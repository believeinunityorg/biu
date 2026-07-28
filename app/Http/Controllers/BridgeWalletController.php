<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use App\Models\BridgeIntegration;
use App\Models\BridgeWallet;
use App\Models\BridgeKycKybSubmission;
use App\Models\ControlPerson;
use App\Models\VerificationDocument;
use App\Models\AdminSetting;
use App\Models\WalletFee;
use App\Models\Transaction;
use App\Models\LiquidationAddress;
use App\Services\BridgeService;
use App\Services\BridgeVirtualAccountDepositService;
use App\Services\BridgeWalletNotifier;
use App\Services\BridgeWalletReadService;
use App\Services\WalletTransactionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class BridgeWalletController extends Controller
{
    protected BridgeService $bridgeService;

    protected WalletTransactionNotifier $walletTransactionNotifier;

    protected BridgeWalletNotifier $bridgeWalletNotifier;

    public function __construct(
        BridgeService $bridgeService,
        WalletTransactionNotifier $walletTransactionNotifier,
        BridgeWalletNotifier $bridgeWalletNotifier,
    ) {
        $this->bridgeService = $bridgeService;
        $this->walletTransactionNotifier = $walletTransactionNotifier;
        $this->bridgeWalletNotifier = $bridgeWalletNotifier;
    }

    public function initializeBridge(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = Organization::forAuthUser($user);
                if (!$organization) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
            } else {
                $entity = $user;
                $entityType = User::class;
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            // Link existing Bridge customer by email (e.g. after local DB cleanup)
            $linked = $this->linkBridgeCustomerByEmail($entity, $entityType, $user, $isOrgUser, $integration);
            if ($linked) {
                $integration = $linked->loadMissing('primaryWallet');
            }

            // Re-sync from Bridge when customer exists locally
            if ($integration && $integration->bridge_customer_id) {
                $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);
                $integration->refresh();
            }

            // If fully initialized, return existing data
            if ($integration && $integration->bridge_customer_id && $integration->bridge_wallet_id) {
                return response()->json([
                    'success' => true,
                    'message' => 'Bridge already initialized',
                    'data' => [
                        'customer_id' => $integration->bridge_customer_id,
                        'wallet_id' => $integration->bridge_wallet_id,
                        'requires_verification' => false,
                    ],
                ]);
            }

            // Customer on Bridge may already be approved — skip verification UI when synced
            if ($integration && $integration->bridge_customer_id && $this->integrationVerificationApproved($integration, $isOrgUser)) {
                $verificationRedirectUri = url($isOrgUser ? '/wallet/kyb-callback' : '/wallet/kyc-callback');
                $freshLink = $this->refreshHostedVerificationLinkForIntegration($integration, $isOrgUser, $verificationRedirectUri);
                $kycWidgetUrl = $freshLink
                    ? $this->bridgeService->convertKycLinkToWidgetUrl($freshLink, $request->getSchemeAndHttpHost())
                    : null;

                return response()->json([
                    'success' => true,
                    'message' => empty($integration->bridge_wallet_id)
                        ? 'Account verified — create your wallet to continue'
                        : 'Bridge already initialized',
                    'data' => [
                        'customer_id' => $integration->bridge_customer_id,
                        'kyc_link_id' => $integration->kyc_link_id,
                        'tos_link' => $integration->tos_link_url,
                        'kyc_link' => $freshLink ?? $integration->kyc_link_url,
                        'kyc_widget_url' => $kycWidgetUrl,
                        'kyc_status' => $integration->kyc_status,
                        'kyb_status' => $integration->kyb_status,
                        'tos_status' => $integration->tos_status,
                        'requires_verification' => false,
                    ],
                ]);
            }

            // If KYC link exists but verification may already be complete on Bridge
            if ($integration && ($integration->kyc_link_id || $integration->bridge_customer_id)) {
                if ($integration->bridge_customer_id) {
                    $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);
                    $integration->refresh();

                    if ($this->bridgeService->isCardsEnabledOnDeveloperAccount()) {
                        $this->refreshCardsEndorsementKycLink($integration, $isOrgUser);
                        $integration->refresh();
                    }
                }

                if (! $integration->bridge_customer_id) {
                    // Stale customer cleared during sync — create a fresh Bridge KYC link below.
                } else {
                    $verificationRedirectUri = url($isOrgUser ? '/wallet/kyb-callback' : '/wallet/kyc-callback');
                    $freshLink = $this->refreshHostedVerificationLinkForIntegration($integration, $isOrgUser, $verificationRedirectUri);
                    $kycWidgetUrl = $freshLink
                        ? $this->bridgeService->convertKycLinkToWidgetUrl($freshLink, $request->getSchemeAndHttpHost())
                        : null;

                    $requiresVerification = ! $this->integrationVerificationApproved($integration, $isOrgUser);

                    return response()->json([
                        'success' => true,
                        'message' => $requiresVerification ? 'Please complete verification' : 'Wallet connected successfully',
                        'data' => [
                            'customer_id' => $integration->bridge_customer_id,
                            'kyc_link_id' => $integration->kyc_link_id,
                            'tos_link' => $integration->tos_link_url,
                            'kyc_link' => $freshLink ?? $integration->kyc_link_url,
                            'kyc_widget_url' => $kycWidgetUrl,
                            'kyc_status' => $integration->kyc_status,
                            'kyb_status' => $integration->kyb_status,
                            'tos_status' => $integration->tos_status,
                            'requires_verification' => $requiresVerification,
                        ],
                    ]);
                }
            }

            DB::beginTransaction();

            try {
                // Get required fields
                $email = trim($entity->email ?? $user->email ?? '');
                $fullName = $isOrgUser
                    ? trim($organization->name ?? $user->name ?? '')
                    : trim($user->name ?? '');

                if (empty($email)) {
                    throw new \Exception('Email is required');
                }

                if (empty($fullName)) {
                    throw new \Exception('Name is required');
                }

                // Cards endorsement when Bridge Cards is enabled on the developer account; otherwise base wallet KYC only.
                $verificationRedirectUri = url($isOrgUser ? '/wallet/kyb-callback' : '/wallet/kyc-callback');
                $connectEndorsements = $this->bridgeService->resolveConnectWalletEndorsements();
                $kycLinkData = [
                    'full_name' => $fullName,
                    'email' => $email,
                    'type' => $isOrgUser ? 'business' : 'individual',
                    'endorsements' => $connectEndorsements,
                    'redirect_uri' => $verificationRedirectUri,
                ];

                $profilePhone = $entity->contact_number ?? $entity->phone ?? null;
                if (is_string($profilePhone) && trim($profilePhone) !== '') {
                    $kycLinkData['phone'] = trim($profilePhone);
                }

                Log::info('Creating Bridge KYC Link', [
                    'is_org_user' => $isOrgUser,
                    'endorsements' => $connectEndorsements,
                    'cards_enabled_on_developer' => $this->bridgeService->isCardsEnabledOnDeveloperAccount(),
                    'kyc_link_data' => $kycLinkData,
                ]);

                $kycLinkResult = $this->bridgeService->createKYCLink($kycLinkData);

                if (! $kycLinkResult['success']
                    && $connectEndorsements === ['cards']
                    && $this->bridgeService->isCardsEndorsementNotAllowedError($kycLinkResult)) {
                    Log::warning('Bridge rejected cards endorsement on connect — falling back to base', [
                        'email' => $email,
                        'error' => $kycLinkResult['error'] ?? null,
                    ]);

                    $kycLinkData['endorsements'] = ['base'];
                    $kycLinkResult = $this->bridgeService->createKYCLink($kycLinkData);
                }

                if (!$kycLinkResult['success']) {
                    throw new \Exception($kycLinkResult['error'] ?? 'Failed to create KYC Link');
                }

                $response = $kycLinkResult['data'];
                
                // Log if we're using an existing link
                if (isset($kycLinkResult['is_existing']) && $kycLinkResult['is_existing']) {
                    Log::info('Using existing Bridge KYC Link', [
                        'kyc_link_id' => $response['id'] ?? null,
                        'customer_id' => $response['customer_id'] ?? null,
                        'email' => $response['email'] ?? null,
                    ]);
                }

                $customerId = $response['customer_id'] ?? null;
                $kycLinkId = $response['id'] ?? null;

                // Reuse existing row when Bridge returns an existing customer (avoids duplicate bridge_customer_id).
                $integration = BridgeIntegration::resolveForEntity(
                    $entity->id,
                    $entityType,
                    $customerId,
                    $kycLinkId,
                );

                $integration->bridge_customer_id = $customerId;
                $integration->kyc_link_id = $kycLinkId;
                $integration->tos_link_url = $response['tos_link'] ?? null;
                // Bridge KYC link response uses kyc_status for both individual and business flows
                $responseKycStatus = $response['kyc_status'] ?? 'not_started';
                $integration->kyc_status = $responseKycStatus;
                if ($isOrgUser) {
                    $integration->kyb_status = $response['kyb_status'] ?? $responseKycStatus;
                }

                if ($customerId && is_string($profilePhone ?? null) && trim($profilePhone) !== '') {
                    $this->bridgeService->remediateCardsEndorsementBlockers($customerId, trim($profilePhone));
                }

                // Prefer GET ?endorsement=cards; never persist base KYC link as cards link when base is approved.
                $this->refreshCardsEndorsementKycLink($integration, $isOrgUser, $verificationRedirectUri);
                if (! $isOrgUser && empty($integration->kyc_link_url) && ! empty($response['kyc_link'])) {
                    $integration->kyc_link_url = $response['kyc_link'];
                }
                if ($isOrgUser && empty($integration->kyb_link_url) && ! empty($response['kyc_link'])) {
                    $integration->kyb_link_url = $response['kyc_link'];
                }
                $integration->tos_status = $response['tos_status'] ?? 'pending'; // ToS uses 'pending' or 'approved'
                $integration->bridge_metadata = [
                    'kyc_link_response' => $response,
                    'type' => $isOrgUser ? 'business' : 'individual',
                ];
                $integration->save();

                DB::commit();

                // KYC link payload may be stale — pull full customer status from Bridge
                $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);
                $integration->refresh();

                if (empty($integration->kyc_link_url) && empty($integration->kyb_link_url)) {
                    $this->refreshCardsEndorsementKycLink($integration, $isOrgUser);
                    $integration->refresh();
                }

                $requiresVerification = ! $this->integrationVerificationApproved($integration, $isOrgUser);

                // Convert verification links to widget URLs for iframe embedding
                $kycWidgetUrl = null;
                $kybWidgetUrl = null;
                if ($integration->kyc_link_url) {
                    $kycWidgetUrl = $this->bridgeService->convertKycLinkToWidgetUrl($integration->kyc_link_url);
                }
                if ($integration->kyb_link_url) {
                    $kybWidgetUrl = $this->bridgeService->convertKycLinkToWidgetUrl($integration->kyb_link_url);
                }

                return response()->json([
                    'success' => true,
                    'message' => $requiresVerification ? 'Please complete verification' : 'Wallet connected successfully',
                    'data' => [
                        'customer_id' => $integration->bridge_customer_id,
                        'kyc_link_id' => $integration->kyc_link_id,
                        'tos_link' => $integration->tos_link_url,
                        'kyc_link' => $integration->kyc_link_url,
                        'kyb_link' => $integration->kyb_link_url,
                        'kyc_widget_url' => $kycWidgetUrl,
                        'kyb_widget_url' => $kybWidgetUrl,
                        'kyc_status' => $integration->kyc_status,
                        'kyb_status' => $integration->kyb_status,
                        'tos_status' => $integration->tos_status,
                        'requires_verification' => $requiresVerification,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Bridge initialization error', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize Bridge: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create wallet after KYC is approved
     */
    public function createWalletAfterKYC(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? Organization::forAuthUser($user) : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            if ($isOrgUser && ! $entity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not found.',
                ], 404);
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please initialize Bridge first.',
                ], 404);
            }

            // Refresh from Bridge so local kyc/kyb match endorsements (SEPA/Active can
            // approve wallet creation even when US base is region-blocked).
            $customer = $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);
            $integration->refresh();

            if (! $this->integrationVerificationApproved($integration, $isOrgUser, $customer)) {
                return response()->json([
                    'success' => false,
                    'message' => $isOrgUser
                        ? 'KYB must be approved before creating wallet.'
                        : 'KYC must be approved before creating wallet.',
                    'kyc_status' => $integration->kyc_status,
                    'kyb_status' => $integration->kyb_status,
                ], 403);
            }

            // Check if we're in sandbox mode
            $isSandbox = $this->bridgeService->isSandbox();

            // Check if wallet/virtual account already exists
            if ($isSandbox) {
                // In sandbox, first check Bridge API for existing virtual accounts
                // IMPORTANT: Each customer must have their own virtual account
                $customerId = $integration->bridge_customer_id;
                
                Log::info('Checking for existing virtual accounts for customer', [
                    'customer_id' => $customerId,
                    'integration_id' => $integration->id,
                    'integratable_id' => $integration->integratable_id,
                    'integratable_type' => $integration->integratable_type,
                ]);
                
                $virtualAccountsResult = $this->bridgeService->getVirtualAccounts($customerId);
                
                Log::info('Virtual accounts retrieval result', [
                    'customer_id' => $customerId,
                    'success' => $virtualAccountsResult['success'] ?? false,
                    'has_data' => !empty($virtualAccountsResult['data']),
                    'data_structure' => isset($virtualAccountsResult['data']) ? gettype($virtualAccountsResult['data']) : 'null',
                    'data_keys' => isset($virtualAccountsResult['data']) && is_array($virtualAccountsResult['data']) ? array_keys($virtualAccountsResult['data']) : [],
                    'full_response' => $virtualAccountsResult,
                ]);
                
                if ($virtualAccountsResult['success'] && !empty($virtualAccountsResult['data'])) {
                    // Handle Bridge API response structure: { count: X, data: [...], pagination_token: ... }
                    $responseData = $virtualAccountsResult['data'];
                    $virtualAccounts = [];
                    
                    if (isset($responseData['data']) && is_array($responseData['data'])) {
                        // Response has 'data' key containing array
                        $virtualAccounts = $responseData['data'];
                        Log::info('Extracted virtual accounts from response.data', [
                            'count' => count($virtualAccounts),
                            'first_item_keys' => !empty($virtualAccounts) && is_array($virtualAccounts[0]) ? array_keys($virtualAccounts[0]) : [],
                        ]);
                    } elseif (is_array($responseData) && isset($responseData[0])) {
                        // Response is directly an array
                        $virtualAccounts = $responseData;
                        Log::info('Using response as direct array', [
                            'count' => count($virtualAccounts),
                        ]);
                    } else {
                        Log::warning('Unexpected response structure for virtual accounts', [
                            'response_data' => $responseData,
                            'response_data_type' => gettype($responseData),
                        ]);
                    }
                    
                    // Ensure we have valid virtual accounts array
                    if (empty($virtualAccounts) || !is_array($virtualAccounts)) {
                        Log::warning('No valid virtual accounts found in response', [
                            'virtual_accounts' => $virtualAccounts,
                            'virtual_accounts_type' => gettype($virtualAccounts),
                            'response_data' => $responseData,
                        ]);
                    }
                    
                    // Filter only activated virtual accounts from Bridge
                    $activatedVirtualAccounts = array_filter($virtualAccounts, function ($va) {
                        return isset($va['status']) && strtolower($va['status']) === 'activated';
                    });
                    
                    // Re-index array after filtering
                    $activatedVirtualAccounts = array_values($activatedVirtualAccounts);
                    
                    Log::info('Filtered activated virtual accounts', [
                        'total_virtual_accounts' => count($virtualAccounts),
                        'activated_count' => count($activatedVirtualAccounts),
                        'activated_statuses' => array_map(function ($va) {
                            return $va['status'] ?? 'no_status';
                        }, $virtualAccounts),
                    ]);
                    
                    // If activated virtual accounts exist in Bridge, use the first one
                    // IMPORTANT: Verify this virtual account belongs to this customer
                    if (!empty($activatedVirtualAccounts) && is_array($activatedVirtualAccounts)) {
                        $existingVirtualAccount = $activatedVirtualAccounts[0]; // Use first activated virtual account
                        $virtualAccountId = $existingVirtualAccount['id'] ?? null;
                        $virtualAccountCustomerId = $existingVirtualAccount['customer_id'] ?? null;
                        
                        // CRITICAL: Verify virtual account belongs to this customer
                        if ($virtualAccountCustomerId && $virtualAccountCustomerId !== $customerId) {
                            Log::error('VIRTUAL ACCOUNT CUSTOMER MISMATCH - Using wrong customer virtual account!', [
                                'expected_customer_id' => $customerId,
                                'virtual_account_customer_id' => $virtualAccountCustomerId,
                                'virtual_account_id' => $virtualAccountId,
                                'integration_id' => $integration->id,
                            ]);
                            // Don't use this virtual account - create a new one instead
                            $existingVirtualAccount = null;
                            $virtualAccountId = null;
                        } else {
                            Log::info('Using activated virtual account for customer', [
                                'customer_id' => $customerId,
                                'virtual_account_id' => $virtualAccountId,
                                'virtual_account_customer_id' => $virtualAccountCustomerId,
                                'status' => $existingVirtualAccount['status'] ?? null,
                                'has_destination' => isset($existingVirtualAccount['destination']),
                                'destination_keys' => isset($existingVirtualAccount['destination']) && is_array($existingVirtualAccount['destination']) 
                                    ? array_keys($existingVirtualAccount['destination']) 
                                    : [],
                                'full_virtual_account' => $existingVirtualAccount,
                            ]);
                        }
                        
                        if ($virtualAccountId && $existingVirtualAccount) {
                            // Check if we already have this in our database
                            // IMPORTANT: Also verify it belongs to this integration/customer
                            $existingWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                                ->where('bridge_customer_id', $customerId)
                                ->where('virtual_account_id', $virtualAccountId)
                                ->first();
                            
                            Log::info('Checking existing wallet in database', [
                                'customer_id' => $customerId,
                                'integration_id' => $integration->id,
                                'virtual_account_id' => $virtualAccountId,
                                'found_existing_wallet' => $existingWallet !== null,
                                'existing_wallet_customer_id' => $existingWallet ? $existingWallet->bridge_customer_id : null,
                            ]);
                            
                            if (!$existingWallet) {
                                // Store in bridge_wallets table if not already stored
                                // Extract destination details from Bridge response
                                $destination = $existingVirtualAccount['destination'] ?? [];
                                $destinationAddress = $destination['address'] ?? null;
                                $destinationChain = $destination['payment_rail'] ?? 'ethereum';
                                $destinationCurrency = $destination['currency'] ?? 'usdc';
                                
                                // Validate that we have valid virtual account data before saving
                                if (empty($existingVirtualAccount) || !is_array($existingVirtualAccount)) {
                                    Log::error('Cannot save empty or invalid virtual account data', [
                                        'virtual_account_id' => $virtualAccountId,
                                        'existing_virtual_account' => $existingVirtualAccount,
                                        'existing_virtual_account_type' => gettype($existingVirtualAccount),
                                    ]);
                                    throw new \Exception('Invalid virtual account data received from Bridge API');
                                }
                                
                                Log::info('Creating BridgeWallet record', [
                                    'virtual_account_id' => $virtualAccountId,
                                    'destination_address' => $destinationAddress,
                                    'destination_chain' => $destinationChain,
                                    'destination_currency' => $destinationCurrency,
                                    'virtual_account_details_type' => gettype($existingVirtualAccount),
                                    'virtual_account_details_empty' => empty($existingVirtualAccount),
                                    'virtual_account_details_keys' => is_array($existingVirtualAccount) ? array_keys($existingVirtualAccount) : [],
                                    'virtual_account_details_count' => is_array($existingVirtualAccount) ? count($existingVirtualAccount) : 0,
                                ]);
                                
                                $existingWallet = BridgeWallet::create([
                                    'bridge_integration_id' => $integration->id,
                                    'bridge_customer_id' => $integration->bridge_customer_id,
                                    'bridge_wallet_id' => null,
                                    'wallet_address' => $destinationAddress,
                                    'chain' => $destinationChain,
                                    'status' => 'active',
                                    'balance' => 0,
                                    'currency' => strtoupper($destinationCurrency), // Convert usdc to USDC
                                    'virtual_account_id' => $virtualAccountId,
                                    'virtual_account_details' => $existingVirtualAccount, // Store full virtual account data
                                    'is_primary' => true,
                                    'last_balance_sync' => now(),
                                ]);
                                
                                // Refresh to get the actual saved data
                                $existingWallet->refresh();
                                
                                Log::info('BridgeWallet created successfully', [
                                    'wallet_id' => $existingWallet->id,
                                    'virtual_account_id' => $existingWallet->virtual_account_id,
                                    'virtual_account_details_saved' => !empty($existingWallet->virtual_account_details),
                                    'virtual_account_details_type' => gettype($existingWallet->virtual_account_details),
                                    'virtual_account_details_is_array' => is_array($existingWallet->virtual_account_details),
                                    'virtual_account_details_count' => is_array($existingWallet->virtual_account_details) ? count($existingWallet->virtual_account_details) : 0,
                                    'virtual_account_details_keys' => is_array($existingWallet->virtual_account_details) ? array_keys($existingWallet->virtual_account_details) : [],
                                ]);
                                
                                // Mark as primary and update others
                                BridgeWallet::where('bridge_integration_id', $integration->id)
                                    ->where('id', '!=', $existingWallet->id)
                                    ->update(['is_primary' => false]);
                            }
                            
                            // Return existing virtual account from Bridge
                            return response()->json([
                                'success' => true,
                                'message' => 'Virtual account already exists',
                                'data' => [
                                    'wallet_id' => $existingWallet->id,
                                    'virtual_account' => $existingVirtualAccount,
                                    'virtual_account_id' => $virtualAccountId,
                                    'address' => $existingWallet->wallet_address,
                                    'is_sandbox' => true,
                                ],
                            ]);
                        }
                    }
                }
                
                // Also check local database as fallback, but verify status is activated in Bridge
                $existingWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                    ->whereNotNull('virtual_account_id')
                    ->where('is_primary', true)
                    ->where('status', 'active')
                    ->first();

                if ($existingWallet && $existingWallet->virtual_account_id) {
                    // Verify the virtual account is still activated in Bridge
                    $verifyResult = $this->bridgeService->getVirtualAccount(
                        $integration->bridge_customer_id,
                        $existingWallet->virtual_account_id
                    );
                    
                    // Only return if status is activated in Bridge
                    if (
                        $verifyResult['success'] && isset($verifyResult['data']['status']) &&
                        strtolower($verifyResult['data']['status']) === 'activated'
                    ) {
                        // Update local record with latest Bridge data
                        $existingWallet->virtual_account_details = $verifyResult['data'];
                        $existingWallet->save();
                        
                        return response()->json([
                            'success' => true,
                            'message' => 'Virtual account already exists',
                            'data' => [
                                'wallet_id' => $existingWallet->id,
                                'virtual_account' => $verifyResult['data'],
                                'virtual_account_id' => $existingWallet->virtual_account_id,
                                'address' => $existingWallet->wallet_address,
                                'is_sandbox' => true,
                            ],
                        ]);
                    }
                }
            } else {
                // In production, first check Bridge API for existing wallets
                $walletsResult = $this->bridgeService->getWallets($integration->bridge_customer_id);
                
                if ($walletsResult['success'] && !empty($walletsResult['data'])) {
                    $wallets = is_array($walletsResult['data']) && isset($walletsResult['data']['data'])
                        ? $walletsResult['data']['data']
                        : (is_array($walletsResult['data']) ? $walletsResult['data'] : []);
                    
                    // If wallets exist in Bridge, use the first one
                    if (!empty($wallets) && is_array($wallets)) {
                        $existingBridgeWallet = $wallets[0]; // Use first wallet
                        $bridgeWalletId = $existingBridgeWallet['id'] ?? null;
                        
                        if ($bridgeWalletId) {
                            // Check if we already have this in our database
                            $existingWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                                ->where('bridge_wallet_id', $bridgeWalletId)
                                ->first();
                            
                            if (!$existingWallet) {
                                // Store in bridge_wallets table if not already stored
                                $existingWallet = BridgeWallet::create([
                                    'bridge_integration_id' => $integration->id,
                                    'bridge_customer_id' => $integration->bridge_customer_id,
                                    'bridge_wallet_id' => $bridgeWalletId,
                                    'wallet_address' => $existingBridgeWallet['address'] ?? null,
                                    'chain' => $existingBridgeWallet['chain'] ?? 'solana',
                                    'status' => 'active',
                                    'balance' => 0,
                                    'currency' => 'USD',
                                    'is_primary' => true,
                                    'wallet_metadata' => $existingBridgeWallet,
                                    'last_balance_sync' => now(),
                                ]);
                                
                                // Mark as primary and update others
                                BridgeWallet::where('bridge_integration_id', $integration->id)
                                    ->where('id', '!=', $existingWallet->id)
                                    ->update(['is_primary' => false]);
                                
                                // Update integration for backward compatibility
                                $integration->bridge_wallet_id = $bridgeWalletId;
                                $integration->wallet_address = $existingBridgeWallet['address'] ?? null;
                                $integration->wallet_chain = $existingBridgeWallet['chain'] ?? 'solana';
                                $integration->save();
                            }
                            
                            // Return existing wallet from Bridge
                            return response()->json([
                                'success' => true,
                                'message' => 'Wallet already exists',
                                'data' => [
                                    'wallet_id' => $existingWallet->id,
                                    'bridge_wallet_id' => $bridgeWalletId,
                                    'address' => $existingWallet->wallet_address,
                                    'chain' => $existingWallet->chain,
                                ],
                            ]);
                        }
                    }
                }
                
                // Also check local database as fallback
                $existingWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                    ->whereNotNull('bridge_wallet_id')
                    ->where('is_primary', true)
                    ->first();

                if ($existingWallet && $existingWallet->bridge_wallet_id) {
                    // Wallet already exists locally, return it
                    return response()->json([
                        'success' => true,
                        'message' => 'Wallet already exists',
                        'data' => [
                            'wallet_id' => $existingWallet->id,
                            'bridge_wallet_id' => $existingWallet->bridge_wallet_id,
                            'address' => $existingWallet->wallet_address,
                            'chain' => $existingWallet->chain,
                        ],
                    ]);
                }

                // Also check integration's bridge_wallet_id for backward compatibility
            if ($integration->bridge_wallet_id) {
                return response()->json([
                    'success' => true,
                    'message' => 'Wallet already exists',
                        'data' => [
                            'wallet_id' => $integration->bridge_wallet_id,
                            'bridge_wallet_id' => $integration->bridge_wallet_id,
                            'address' => $integration->wallet_address,
                        ],
                ]);
            }
            }

            // No existing wallet/virtual account found, create new one
            // In sandbox mode, create virtual account instead of wallet
            if ($isSandbox) {
                // Create virtual account for sandbox mode
                // IMPORTANT: Each customer gets their own unique virtual account
                $customerId = $integration->bridge_customer_id;
                
                Log::info('Creating new virtual account for customer', [
                    'customer_id' => $customerId,
                    'integration_id' => $integration->id,
                    'integratable_id' => $integration->integratable_id,
                    'integratable_type' => $integration->integratable_type,
                ]);
                
                $sourceCurrency = $this->bridgeService->resolvePreferredVirtualAccountSourceCurrency(
                    null,
                    $customerId,
                );

                $virtualAccountResult = $this->bridgeService->createVirtualAccount(
                    $customerId, // CRITICAL: Use customer-specific ID
                    ['currency' => $sourceCurrency],
                    [
                        'payment_rail' => 'ethereum',
                        'currency' => 'usdc',
                        'address' => $this->bridgeService->generateEthereumAddress(), // Generate unique address per customer
                    ]
                );
                
                Log::info('Virtual account creation API call made', [
                    'customer_id' => $customerId,
                    'api_success' => $virtualAccountResult['success'] ?? false,
                    'virtual_account_id' => $virtualAccountResult['data']['id'] ?? null,
                    'virtual_account_customer_id' => $virtualAccountResult['data']['customer_id'] ?? null,
                ]);

                if (!$virtualAccountResult['success']) {
                    Log::error('Failed to create virtual account', [
                        'error' => $virtualAccountResult['error'] ?? 'Unknown error',
                        'full_result' => $virtualAccountResult,
                    ]);
                    throw new \Exception($virtualAccountResult['error'] ?? 'Failed to create virtual account');
                }

                $virtualAccountData = $virtualAccountResult['data'];
                
                // Validate that we have valid virtual account data
                if (empty($virtualAccountData) || !is_array($virtualAccountData)) {
                    Log::error('Invalid virtual account data received from Bridge API', [
                        'customer_id' => $customerId,
                        'virtual_account_result' => $virtualAccountResult,
                        'virtual_account_data' => $virtualAccountData,
                        'virtual_account_data_type' => gettype($virtualAccountData),
                    ]);
                    throw new \Exception('Invalid virtual account data received from Bridge API');
                }
                
                // CRITICAL: Verify the virtual account belongs to the correct customer
                $virtualAccountCustomerId = $virtualAccountData['customer_id'] ?? null;
                if ($virtualAccountCustomerId && $virtualAccountCustomerId !== $customerId) {
                    Log::error('VIRTUAL ACCOUNT CUSTOMER MISMATCH - Bridge returned wrong customer!', [
                        'expected_customer_id' => $customerId,
                        'virtual_account_customer_id' => $virtualAccountCustomerId,
                        'virtual_account_id' => $virtualAccountData['id'] ?? null,
                        'integration_id' => $integration->id,
                    ]);
                    throw new \Exception("Virtual account customer mismatch: expected {$customerId}, got {$virtualAccountCustomerId}");
                }
                
                Log::info('Virtual account created successfully for customer', [
                    'customer_id' => $customerId,
                    'virtual_account_id' => $virtualAccountData['id'] ?? null,
                    'virtual_account_customer_id' => $virtualAccountCustomerId,
                    'has_destination' => isset($virtualAccountData['destination']),
                    'virtual_account_data_type' => gettype($virtualAccountData),
                    'virtual_account_data_keys' => is_array($virtualAccountData) ? array_keys($virtualAccountData) : [],
                    'virtual_account_data_empty' => empty($virtualAccountData),
                    'virtual_account_data_count' => is_array($virtualAccountData) ? count($virtualAccountData) : 0,
                    'full_virtual_account_data' => $virtualAccountData,
                ]);

                // Extract destination details from Bridge response
                $destination = $virtualAccountData['destination'] ?? [];
                $destinationAddress = $destination['address'] ?? null;
                $destinationChain = $destination['payment_rail'] ?? 'ethereum';
                $destinationCurrency = $destination['currency'] ?? 'usdc';
                
                Log::info('Extracted destination details', [
                    'destination_address' => $destinationAddress,
                    'destination_chain' => $destinationChain,
                    'destination_currency' => $destinationCurrency,
                    'destination_keys' => array_keys($destination),
                ]);

                // Create BridgeWallet record for virtual account in sandbox mode
                // Use virtual_account_id as unique identifier to prevent duplicates
                // IMPORTANT: Store with correct customer_id to ensure customer-specific virtual accounts
                $walletData = [
                    'bridge_integration_id' => $integration->id,
                    'bridge_customer_id' => $customerId, // Use the verified customer ID
                    'bridge_wallet_id' => null, // No wallet ID in sandbox, only virtual account
                    'wallet_address' => $destinationAddress,
                    'chain' => $destinationChain,
                    'status' => 'active',
                    'balance' => 0,
                    'currency' => strtoupper($destinationCurrency), // Convert usdc to USDC
                    'virtual_account_details' => $virtualAccountData, // Store full virtual account data
                    'is_primary' => true,
                    'last_balance_sync' => now(),
                ];
                
                Log::info('Creating/updating BridgeWallet with data', [
                    'virtual_account_id' => $virtualAccountData['id'] ?? null,
                    'virtual_account_details_type' => gettype($virtualAccountData),
                    'virtual_account_details_empty' => empty($virtualAccountData),
                    'wallet_data_keys' => array_keys($walletData),
                ]);
                
                $wallet = BridgeWallet::updateOrCreate(
                    [
                        'bridge_integration_id' => $integration->id,
                        'virtual_account_id' => $virtualAccountData['id'] ?? null,
                    ],
                    $walletData
                );
                
                // Refresh to get the actual saved data
                $wallet->refresh();
                
                Log::info('BridgeWallet saved', [
                    'wallet_id' => $wallet->id,
                    'virtual_account_id' => $wallet->virtual_account_id,
                    'virtual_account_details_saved' => !empty($wallet->virtual_account_details),
                    'virtual_account_details_type' => gettype($wallet->virtual_account_details),
                    'virtual_account_details_is_array' => is_array($wallet->virtual_account_details),
                    'virtual_account_details_count' => is_array($wallet->virtual_account_details) ? count($wallet->virtual_account_details) : 0,
                    'virtual_account_details_keys' => is_array($wallet->virtual_account_details) ? array_keys($wallet->virtual_account_details) : [],
                    'raw_virtual_account_details' => $wallet->getRawOriginal('virtual_account_details') ?? 'null',
                ]);

                // Mark as primary and update others
                if ($wallet->is_primary) {
                    BridgeWallet::where('bridge_integration_id', $integration->id)
                        ->where('id', '!=', $wallet->id)
                        ->update(['is_primary' => false]);
                }

                // Also store in integration metadata for backward compatibility
                $integrationMetadata = $integration->bridge_metadata ?? [];
                if (!is_array($integrationMetadata)) {
                    $integrationMetadata = is_string($integrationMetadata) ? json_decode($integrationMetadata, true) : [];
                }
                $integrationMetadata['virtual_account'] = $virtualAccountData;
                $integration->bridge_metadata = $integrationMetadata;
                $integration->save();

                Log::info('Virtual account created in sandbox mode and saved to bridge_wallets', [
                    'integration_id' => $integration->id,
                    'wallet_id' => $wallet->id,
                    'virtual_account_id' => $virtualAccountData['id'] ?? null,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Virtual account created successfully',
                    'data' => [
                        'wallet_id' => $wallet->id,
                        'virtual_account' => $virtualAccountData,
                        'virtual_account_id' => $virtualAccountData['id'] ?? null,
                        'address' => $wallet->wallet_address,
                        'is_sandbox' => true,
                    ],
                ]);
            }

            // Production mode: Create wallet with chain parameter
            $chain = $request->input('chain', 'solana');
            $walletResult = $this->bridgeService->createWallet(
                $integration->bridge_customer_id,
                $chain
            );

            if (!$walletResult['success']) {
                throw new \Exception($walletResult['error'] ?? 'Failed to create wallet');
            }

            $walletData = $walletResult['data'];

            // Update integration for backward compatibility
            $integration->bridge_wallet_id = $walletData['id'] ?? null;
            $integration->wallet_address = $walletData['address'] ?? null;
            $integration->wallet_chain = $chain;
            $integration->save();

            // Create BridgeWallet record
            $wallet = BridgeWallet::updateOrCreate(
                [
                    'bridge_integration_id' => $integration->id,
                    'bridge_wallet_id' => $walletData['id'] ?? null,
                ],
                [
                    'bridge_customer_id' => $integration->bridge_customer_id,
                    'wallet_address' => $walletData['address'] ?? null,
                    'chain' => $chain,
                    'status' => 'active',
                    'balance' => 0,
                    'currency' => 'USD',
                    'is_primary' => true,
                    'wallet_metadata' => $walletData,
                    'last_balance_sync' => now(),
                ]
            );

            // If this is the first wallet, mark it as primary and update others
            if ($wallet->is_primary) {
                BridgeWallet::where('bridge_integration_id', $integration->id)
                    ->where('id', '!=', $wallet->id)
                    ->update(['is_primary' => false]);
            }

            // Automatically create virtual account for the wallet (USD ACH or EUR SEPA IBAN)
            $virtualAccountData = null;
            try {
                $sourceCurrency = $this->bridgeService->resolvePreferredVirtualAccountSourceCurrency(
                    null,
                    $integration->bridge_customer_id,
                );

                if ($chain === 'solana' || $chain === 'ethereum' || $chain === 'usd' || $chain === 'USD') {
                    if ($chain === 'usd' || $chain === 'USD') {
                        $vaChain = 'solana';
                        $virtualAccountResult = $this->bridgeService->createVirtualAccountForWallet(
                            $integration->bridge_customer_id,
                            $walletData['id'] ?? null,
                            $sourceCurrency,
                            $vaChain,
                        );
                    } else {
                        $virtualAccountResult = $this->bridgeService->createVirtualAccountForChainWallet(
                            $integration->bridge_customer_id,
                            $walletData['id'] ?? null,
                            $chain,
                            null,
                            $sourceCurrency,
                        );
                    }

                    if ($virtualAccountResult['success'] && isset($virtualAccountResult['data'])) {
                        $virtualAccountData = $virtualAccountResult['data'];
                        
                        // Update wallet record with virtual account info
                        $wallet->virtual_account_id = $virtualAccountData['id'] ?? null;
                        $wallet->virtual_account_details = $virtualAccountData;
                        $wallet->save();

                        Log::info('Virtual account created automatically', [
                            'wallet_id' => $wallet->id,
                            'virtual_account_id' => $virtualAccountData['id'] ?? null,
                            'source_currency' => $sourceCurrency,
                        ]);
                    } else {
                        Log::warning('Failed to create virtual account automatically', [
                            'wallet_id' => $wallet->id,
                            'source_currency' => $sourceCurrency,
                            'error' => $virtualAccountResult['error'] ?? 'Unknown error',
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Don't fail wallet creation if virtual account creation fails
                Log::warning('Error creating virtual account automatically', [
                    'wallet_id' => $wallet->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Wallet created successfully', [
                'integration_id' => $integration->id,
                'wallet_id' => $wallet->id,
                'bridge_wallet_id' => $walletData['id'] ?? null,
                'address' => $walletData['address'] ?? null,
                'chain' => $chain,
                'virtual_account_created' => $virtualAccountData !== null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Wallet and virtual account created successfully',
                'data' => [
                    'wallet_id' => $wallet->id,
                    'bridge_wallet_id' => $integration->bridge_wallet_id,
                    'address' => $integration->wallet_address,
                    'chain' => $chain,
                    'virtual_account' => $virtualAccountData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Wallet creation error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create wallet: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Check if Bridge wallet is initialized (without fetching balance)
     */
    public function checkBridgeStatus(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = Organization::forAuthUser($user);
                if (!$organization) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
            } else {
                $entity = $user;
                $entityType = User::class;
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            // If no integration or no customer ID, Bridge is not initialized
            // But return organization data for pre-filling the form
            $organizationData = null;
            if ($isOrgUser && $organization) {
                $organizationData = [
                    'business_name' => $organization->name,
                    'email' => $organization->email ?? $user->email,
                    'ein' => $organization->ein,
                    'phone' => $organization->phone,
                    'website' => $organization->website,
                    'street_line_1' => $organization->street,
                    'city' => $organization->city,
                    'state' => $organization->state,
                    'postal_code' => $organization->zip,
                    'country' => 'USA',
                ];
            }
            
            // Status check is read-only — do not auto-link Bridge customers here.
            // Linking/sync happens only when the user clicks Connect (initializeBridge).
            if (! $integration || ! $integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'initialized' => false,
                    'message' => 'Bridge wallet not initialized. Click Connect Wallet to link your account.',
                    'organization_data' => $organizationData, // Include organization data for pre-filling
                ], 200); // Return 200 so frontend can handle it gracefully
            }

            // Customer exists locally from a prior Connect — refresh from Bridge API
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $customer = $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);
            $integration->refresh();

            $needsVerification = ! $this->integrationVerificationApproved($integration, $isOrgUser, $customer);

            // Check TOS status from Bridge API via customer endorsements
            $tosStatusFromBridge = $integration->tos_status ?? 'pending';
            $tosAcceptedFromBridge = false;
            
            // If tos_status is empty, check metadata for signed_agreement_id as fallback
            if (empty($tosStatusFromBridge) || $tosStatusFromBridge === '') {
                $metadata = $integration->bridge_metadata ?? [];
                if (is_string($metadata)) {
                    $metadata = json_decode($metadata, true) ?? [];
                }
                if (!empty($metadata['signed_agreement_id'])) {
                    // If we have a signed_agreement_id, TOS must be accepted
                    $tosStatusFromBridge = 'approved';
                    $tosAcceptedFromBridge = true;
                    // Update the integration to fix empty status
                    $integration->tos_status = BridgeIntegration::normalizeTosStatus('approved');
                    $integration->save();
                }
            }

            try {
                if ($customer === null && ! empty($integration->bridge_customer_id)) {
                $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
                    $customer = ($customerResult['success'] ?? false) ? ($customerResult['data'] ?? null) : null;
                }

                if (is_array($customer)) {
                    $endorsements = $customer['endorsements'] ?? [];
                    
                    // Also check has_accepted_terms_of_service from Bridge
                    $hasAcceptedTos = $customer['has_accepted_terms_of_service'] ?? false;
                    if ($hasAcceptedTos) {
                        $tosAcceptedFromBridge = true;
                        $tosStatusFromBridge = 'accepted';
                    }

                    // Check if terms_of_service is in "complete" requirements
                    if (!$tosAcceptedFromBridge) {
                        foreach ($endorsements as $endorsement) {
                            $complete = $endorsement['requirements']['complete'] ?? [];
                            if (in_array('terms_of_service_v1', $complete) || in_array('terms_of_service_v2', $complete)) {
                                $tosAcceptedFromBridge = true;
                                $tosStatusFromBridge = 'accepted';
                                break;
                            }
                        }
                    }

                    // If not found in complete, check missing requirements
                    if (!$tosAcceptedFromBridge) {
                        foreach ($endorsements as $endorsement) {
                            $missing = $endorsement['requirements']['missing'] ?? [];
                            if (in_array('terms_of_service_v1', $missing) || in_array('terms_of_service_v2', $missing)) {
                                $tosStatusFromBridge = 'pending';
                                break;
                            }
                        }
                    }

                    // Update local database if Bridge status differs or is empty
                    if ($tosAcceptedFromBridge && ! BridgeIntegration::isTosAccepted($integration->tos_status)) {
                        $integration->tos_status = BridgeIntegration::normalizeTosStatus('approved');
                        $integration->save();
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to check TOS status from Bridge', [
                    'error' => $e->getMessage(),
                    'customer_id' => $integration->bridge_customer_id,
                ]);
                // Continue with local status if Bridge check fails
            }

            // Convert KYC/KYB links to widget URLs for iframe embedding
            $kycWidgetUrl = null;
            $kybWidgetUrl = null;
            if ($integration->kyc_link_url) {
                $kycWidgetUrl = $this->bridgeService->convertKycLinkToWidgetUrl($integration->kyc_link_url);
            }
            if ($integration->kyb_link_url) {
                $kybWidgetUrl = $this->bridgeService->convertKycLinkToWidgetUrl($integration->kyb_link_url);
            }

            // KYC/KYB — Bridge Persona modal only (no custom forms or admin approval)
            $kybStep = $isOrgUser ? 'bridge_hosted' : null;
            $kybUseHostedFlow = $isOrgUser;
            $directBridgeSubmission = true;
            $controlPersonKycLink = null;
            $controlPersonKycIframeUrl = null;
                    $hasBusinessDocuments = false;
            $submission = null;
            $requestedFields = null;
            $refillMessage = null;
            $controlPersonData = null;

            if ($isOrgUser && $integration->kyb_link_url) {
                $controlPersonKycLink = $integration->kyb_link_url;
                $controlPersonKycIframeUrl = $this->bridgeService->convertKycLinkToWidgetUrl($controlPersonKycLink);
            }

            // Get wallet address from primary wallet if exists, otherwise from integration
            $walletAddress = $integration->wallet_address;
            $primaryWallet = $integration->primaryWallet;
            
            // Check if we're in sandbox mode
            $isSandbox = $this->bridgeService->isSandbox();
            
            if ($primaryWallet) {
                // Priority 1: Get address from primary wallet
                if ($primaryWallet->wallet_address) {
                    $walletAddress = $primaryWallet->wallet_address;
                } elseif ($isSandbox && $primaryWallet->virtual_account_id) {
                    // In sandbox mode, if we have virtual account but no wallet_address, extract from virtual_account_details
                    $virtualAccountDetails = $primaryWallet->virtual_account_details;
                    if (is_string($virtualAccountDetails)) {
                        $virtualAccountDetails = json_decode($virtualAccountDetails, true) ?? [];
                    }
                    if (is_array($virtualAccountDetails) && isset($virtualAccountDetails['destination']['address'])) {
                        $walletAddress = $virtualAccountDetails['destination']['address'];
                        // Update the wallet record with the address for future use
                        $primaryWallet->wallet_address = $walletAddress;
                        $primaryWallet->save();
                    }
                }
            }
            
            // Fallback: try to get from BridgeWallet table if still no address
            if (!$walletAddress) {
                $wallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                    ->where('is_primary', true)
                    ->first();
                if ($wallet) {
                    if ($wallet->wallet_address) {
                        $walletAddress = $wallet->wallet_address;
                    } elseif ($isSandbox && $wallet->virtual_account_id && $wallet->virtual_account_details) {
                        // Extract address from virtual account details
                        $virtualAccountDetails = $wallet->virtual_account_details;
                        if (is_string($virtualAccountDetails)) {
                            $virtualAccountDetails = json_decode($virtualAccountDetails, true) ?? [];
                        }
                        if (is_array($virtualAccountDetails) && isset($virtualAccountDetails['destination']['address'])) {
                            $walletAddress = $virtualAccountDetails['destination']['address'];
                            // Update the wallet record with the address
                            $wallet->wallet_address = $walletAddress;
                            $wallet->save();
                        }
                    }
                }
            }
            
            // Determine has_wallet based on mode
            $hasWallet = false;
            if ($isSandbox) {
                // In sandbox: check for virtual account
                $hasWallet = ($primaryWallet && !empty($primaryWallet->virtual_account_id)) || 
                            (!empty($walletAddress)); // If we have an address, we have a virtual account
            } else {
                // In production: check for actual wallet
                $hasWallet = !empty($integration->bridge_wallet_id) || 
                            ($primaryWallet && !empty($primaryWallet->bridge_wallet_id));
            }

            if (! $hasWallet && ! empty($integration->bridge_customer_id)) {
                $resolvedWallet = $this->bridgeService->resolveCustomerBridgeWallet($integration);
                $hasWallet = $resolvedWallet !== null;
                if ($hasWallet && empty($walletAddress) && ! empty($resolvedWallet['wallet_id'])) {
                    $walletResult = $this->bridgeService->getWallet(
                        $integration->bridge_customer_id,
                        $resolvedWallet['wallet_id'],
                    );
                    $walletData = is_array($walletResult['data'] ?? null) ? $walletResult['data'] : [];
                    $walletAddress = (string) ($walletData['address'] ?? $walletAddress);
                }
            }

            return response()->json([
                'success' => true,
                'initialized' => true,
                'customer_id' => $integration->bridge_customer_id,
                'wallet_id' => $integration->bridge_wallet_id, // May be null if KYC not approved yet
                'wallet_address' => $walletAddress, // Wallet address for display (from wallet or virtual account)
                'has_wallet' => $hasWallet, // Whether wallet/virtual account exists
                'is_sandbox' => $isSandbox, // Indicate if we're in sandbox mode
                'bank_withdrawal_available' => ! $isSandbox && ! empty(
                    $integration->bridge_wallet_id
                    ?? ($primaryWallet ? $primaryWallet->bridge_wallet_id : null)
                ),
                'kyc_status' => $integration->kyc_status,
                'kyb_status' => $integration->kyb_status,
                'tos_status' => $tosStatusFromBridge,
                'tos_accepted' => $tosAcceptedFromBridge,
                'tos_link' => $integration->tos_link_url,
                'kyc_link' => $integration->kyc_link_url,
                'kyb_link' => $integration->kyb_link_url,
                'kyc_widget_url' => $kycWidgetUrl,
                'kyb_widget_url' => $kybWidgetUrl,
                'requires_verification' => $needsVerification,
                'bridge_account_verified' => ! $needsVerification,
                'verification_type' => $isOrgUser ? 'kyb' : 'kyc',
                // Bridge endorsements: base = US ACH/wire; SEPA may be granted when base is region-blocked.
                'endorsements' => is_array($customer)
                    ? $this->bridgeService->getEndorsementsSummary($customer)
                    : null,
                'organization_data' => $organizationData, // Always include organization data for pre-filling
                'kyb_step' => $isOrgUser ? $kybStep : null, // Current step for KYB multi-step flow
                'kyb_use_hosted_flow' => $isOrgUser ? $kybUseHostedFlow : null,
                'direct_bridge_submission' => $isOrgUser ? $directBridgeSubmission : null,
                'has_control_person' => false,
                'control_person_kyc_link' => $isOrgUser ? $controlPersonKycLink : null, // KYC link for control person
                'control_person_kyc_iframe_url' => $isOrgUser ? $controlPersonKycIframeUrl : null, // Iframe URL for control person KYC
                'kyb_submission_status' => $isOrgUser && isset($submission) ? $submission->submission_status : null, // Submission status for checking approval
                'business_documents_submitted' => $isOrgUser ? $hasBusinessDocuments : false, // Whether business documents have been submitted
                'document_statuses' => $isOrgUser && isset($submission) ? $this->getDocumentStatuses($submission) : null, // Document approval/rejection statuses from VerificationDocument table
                'requested_fields' => $requestedFields, // Fields that admin requested to be re-filled
                'refill_message' => $refillMessage, // Message from admin about what needs to be re-filled
                'control_person_data' => $controlPersonData, // Control person data for pre-filling form (ID Type, ID Number, etc.)
            ]);
        } catch (\Exception $e) {
            Log::error('Bridge status check error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'initialized' => false,
                'message' => 'Failed to check Bridge status',
            ], 500);
        }
    }

    /**
     * Get Bridge balance
     */
    public function getBridgeBalance(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = Organization::forAuthUser($user);
                if (!$organization) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
            } else {
                $entity = $user;
                $entityType = User::class;
            }

            $integration = BridgeIntegration::with(['primaryWallet', 'wallets'])
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (! $integration || ! $integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge wallet not initialized. Please initialize first.',
                ], 404);
            }

            $snapshot = app(BridgeWalletReadService::class)->getWalletSnapshot($integration);

            if ($snapshot === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch wallet balance from Bridge.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'balance' => $snapshot['balance'],
                'currency' => $snapshot['currency'] ?? 'USD',
                'source' => 'bridge_wallet',
            ]);
        } catch (\Exception $e) {
            Log::error('Bridge balance error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to fetch balance'], 500);
        }
    }

    /**
     * Create KYC link for user
     */
    public function createKYCLink(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = $user->organization;
                if (!$organization) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
                $linkType = 'kyb';
            } else {
                $entity = $user;
                $entityType = User::class;
                $linkType = 'kyc';
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge customer not initialized. Please initialize first.',
                ], 404);
            }

            $iframeOrigin = $request->getSchemeAndHttpHost();
            // Explicit empty redirect_url = iframe/modal embed (postMessage only; no breakout window).
            // Omitted redirect_url keeps the legacy callback default for other clients.
            if ($request->exists('redirect_url')) {
                $redirectRaw = trim((string) $request->input('redirect_url'));
                $redirectUri = $redirectRaw !== '' ? $redirectRaw : null;
            } else {
                $redirectUri = url($linkType === 'kyb' ? '/wallet/kyb-callback' : '/wallet/kyc-callback');
            }

            // Individual + business (KYB): cards endorsement KYC link
            $kycLinkId = $linkType === 'kyb'
                ? ($integration->kyb_link_id ?? $integration->kyc_link_id)
                : $integration->kyc_link_id;

            $preferredPhone = $this->resolveIntegratablePhoneForCards($integration);

            $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
            if ($this->bridgeService->isCustomerInaccessibleError($customerResult)) {
                $this->bridgeService->clearStaleCustomerReference(
                    $integration,
                    (string) $integration->bridge_customer_id,
                    'kyc_link_customer_inaccessible',
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Your wallet verification session expired. Please start Connect Wallet again.',
                    'error_code' => 'bridge_customer_stale',
                    'requires_wallet_restart' => true,
                ], 409);
            }

            $baseApproved = $this->bridgeService->getBaseEndorsementInfo($customerResult['data'] ?? [])['approved'] ?? false;
            $effectiveKycLinkId = $baseApproved ? null : $kycLinkId;

            $requestedEndorsement = strtolower((string) $request->input('endorsement', 'base'));

            if ($requestedEndorsement === 'cards') {
                $linkResult = $this->bridgeService->resolveCardsEndorsementKycLink(
                    $integration->bridge_customer_id,
                    $redirectUri,
                    $effectiveKycLinkId,
                    $preferredPhone,
                    true,
                );
            } else {
                $linkResult = $this->bridgeService->resolveBaseKycLink(
                    $integration->bridge_customer_id,
                    $redirectUri,
                    $kycLinkId,
                );
            }

            if ($linkResult['cards_already_approved'] ?? false) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cards verification is already complete.',
                    'data' => [
                        'cards_already_approved' => true,
                        'endorsement' => 'cards',
                    ],
                ]);
            }

            if ($requestedEndorsement === 'cards' && ($linkResult['phone_required'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add a valid US phone number in E.164 format (e.g. +12025550100) to your profile before cards verification.',
                    'error_code' => 'cards_endorsement_phone_required',
                    'endorsement' => 'cards',
                    'cards_endorsement_issues' => $linkResult['cards_endorsement_issues'] ?? ['phone_number_invalid_format'],
                ], 400);
            }

            if (! ($linkResult['success'] ?? false)) {
                $isCards = $requestedEndorsement === 'cards';
                if (($linkResult['error_code'] ?? '') === 'bridge_customer_stale') {
                    $this->bridgeService->clearStaleCustomerReference(
                        $integration,
                        (string) $integration->bridge_customer_id,
                        'kyc_link_customer_inaccessible',
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Your wallet verification session expired. Please start Connect Wallet again.',
                        'error_code' => 'bridge_customer_stale',
                        'requires_wallet_restart' => true,
                    ], 409);
                }

                Log::error($isCards ? 'Cards endorsement KYC/KYB link failed' : 'Base KYC/KYB link failed', [
                    'integration_id' => $integration->id,
                    'customer_id' => $integration->bridge_customer_id,
                    'link_type' => $linkType,
                    'endorsement' => $requestedEndorsement,
                    'error' => $linkResult['error'] ?? null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $isCards
                        ? 'Cards verification link is temporarily unavailable from Bridge. Please try again in a moment.'
                        : 'Identity verification link is temporarily unavailable from Bridge. Please try again in a moment.',
                    'error_code' => $isCards ? 'cards_endorsement_kyc_link_failed' : 'kyc_link_failed',
                    'endorsement' => $requestedEndorsement,
                    'bridge_error' => $linkResult['error'] ?? null,
                ], 502);
            }

            $linkUrlFromBridge = $linkResult['url'] ?? null;
            $linkResult = [
                'success' => true,
                'data' => array_merge($linkResult['data'] ?? [], [
                    'url' => $linkUrlFromBridge,
                    'kyc_link' => $linkUrlFromBridge,
                ]),
            ];

            if (!$linkResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $linkResult['error'] ?? 'Failed to create KYC/KYB link',
                ], 500);
            }

            $linkId = $linkResult['data']['id'] ?? null;
            $linkUrl = $linkResult['data']['url'] ?? $linkResult['data']['link_url'] ?? $linkResult['data']['kyc_link'] ?? null;

            // Convert to widget URL for iframe embedding
            $widgetUrl = null;
            if ($linkUrl) {
                $widgetUrl = $this->bridgeService->convertKycLinkToWidgetUrl($linkUrl, $iframeOrigin);
            }

            if ($linkUrl && ! $widgetUrl) {
                Log::warning('KYC/KYB link created but widget URL conversion failed', [
                    'link_type' => $linkType,
                    'integration_id' => $integration->id,
                    'link_url' => $linkUrl,
                ]);
            }

            if ($linkType === 'kyb') {
                $integration->kyb_link_id = $linkId;
                $integration->kyb_link_url = $linkUrl;
                $metadata = $integration->bridge_metadata ?? [];
                if (! is_array($metadata)) {
                    $metadata = is_string($metadata) ? (json_decode($metadata, true) ?? []) : [];
                }
                $metadata['control_person_kyc_link'] = $linkUrl;
                $integration->bridge_metadata = $metadata;
                if ($integration->kyb_status !== 'approved') {
                $integration->kyb_status = 'not_started';
                }
            } else {
                $integration->kyc_link_id = $linkId;
                $integration->kyc_link_url = $linkUrl;
                if ($requestedEndorsement !== 'cards' && $integration->kyc_status !== 'approved') {
                // Bridge KYC statuses: not_started, incomplete, under_review, awaiting_questionnaire, approved, rejected, paused, offboarded
                $integration->kyc_status = 'not_started';
                }
            }
            $integration->save();

            return response()->json([
                'success' => true,
                'message' => ucfirst($linkType) . ' link created successfully',
                'data' => [
                    'link_id' => $linkId,
                    'link_url' => $linkUrl,
                    'widget_url' => $widgetUrl, // Widget URL for iframe
                    'endorsement' => $requestedEndorsement === 'cards' ? 'cards' : 'base',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('KYC/KYB link creation error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to create link'], 500);
        }
    }

    /**
     * Get Terms of Service link for custom KYC
     */
    public function getTosLink(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = $user->organization;
                if (!$organization) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
                $email = $organization->email ?? $user->email;
            } else {
                $entity = $user;
                $entityType = User::class;
                $email = $user->email;
            }

            if (empty($email)) {
                return response()->json(['success' => false, 'message' => 'Email is required.'], 400);
            }

            // Get or create integration
            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration) {
                $integration = new BridgeIntegration();
                $integration->integratable_id = $entity->id;
                $integration->integratable_type = $entityType;
            }

            // Check if refresh is requested - if so, always fetch a new TOS link
            $refresh = $request->query('refresh') === '1' || $request->query('refresh') === 'true';
            
            // Check if TOS is already accepted
            if (BridgeIntegration::isTosAccepted($integration->tos_status) && !$refresh) {
                // TOS already accepted, return existing TOS link if available, or return success
                // But only if refresh is not requested
                return response()->json([
                    'success' => true,
                    'data' => [
                        'tos_url' => $integration->tos_link_url,
                        'tos_link_id' => $integration->bridge_metadata['tos_link_id'] ?? null,
                        'already_accepted' => true,
                        'tos_status' => BridgeIntegration::normalizeTosStatus($integration->tos_status),
                    ],
                ]);
            }

            // Always get a fresh TOS link (especially if refresh is requested)
            // This ensures we use the latest redirect_uri from .env
            // Get redirect URI for callback - use BRIDGE_REDIRECT_URI from environment
            // IMPORTANT: For local development, browsers block redirects from public domains (Bridge) to localhost
            // Set BRIDGE_REDIRECT_URI in .env to a public URL (e.g., ngrok) for local development
            // Example: BRIDGE_REDIRECT_URI=https://your-ngrok-url.ngrok.io
            
            // ============================================
            // ALWAYS USE APP_URL as redirect_uri (user requirement)
            // ============================================
            $appUrl = env('APP_URL') ?? config('app.url');
            
            Log::info('Bridge TOS: Using APP_URL as redirect_uri', [
                'app_url' => $appUrl,
                'customer_id' => $integration->bridge_customer_id,
                'entity_id' => $entity->id,
                'entity_type' => $entityType,
            ]);
            
            // Always construct redirect_uri from APP_URL
            $redirectUri = rtrim($appUrl, '/') . '/wallet/tos-callback';
            
            // Add customer_id to redirect URI so we can identify the user in callback
            // This helps when session is not available
            if ($integration->bridge_customer_id) {
                $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
                $redirectUri .= $separator . 'customer_id=' . urlencode($integration->bridge_customer_id);
                Log::info('Bridge TOS: Added customer_id to redirect_uri', [
                    'redirect_uri' => $redirectUri,
                    'customer_id' => $integration->bridge_customer_id,
                ]);
            }

            Log::info('Bridge TOS: Calling Bridge API getTosLink', [
                'email' => $email,
                'redirect_uri' => $redirectUri,
            ]);
            
            $result = $this->bridgeService->getTosLink($email, $redirectUri);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to get TOS link',
                ], 500);
            }

            // Save TOS link to database
            $tosUrl = $result['data']['url'] ?? null;
            $tosLinkId = $result['data']['id'] ?? null;

            if ($tosUrl) {
                // Always update the TOS link URL to ensure it has the latest redirect_uri
                $integration->tos_link_url = $tosUrl;
                
                // Extract redirect_uri from the TOS URL to log it
                $urlParts = parse_url($tosUrl);
                $redirectUriFromUrl = null;
                if (isset($urlParts['query'])) {
                    parse_str($urlParts['query'], $queryParams);
                    $redirectUriFromUrl = $queryParams['redirect_uri'] ?? null;
                }
                
                Log::info('Bridge TOS: Saved fresh TOS link to database', [
                    'tos_url' => $tosUrl,
                    'redirect_uri_from_url' => $redirectUriFromUrl ? urldecode($redirectUriFromUrl) : 'not found in URL',
                    'redirect_uri_used' => $redirectUri ?? 'null',
                ]);

                // Extract customer_id from TOS URL if present (Bridge includes it in the URL)
                $metadata = $integration->bridge_metadata ?? [];
                if ($tosLinkId) {
                    $metadata['tos_link_id'] = $tosLinkId;
                }

                // Parse TOS URL to extract customer_id
                $urlParts = parse_url($tosUrl);
                if (isset($urlParts['query'])) {
                    parse_str($urlParts['query'], $queryParams);
                    if (isset($queryParams['customer_id'])) {
                        $metadata['tos_customer_id'] = $queryParams['customer_id'];
                        // Also update bridge_customer_id if not set
                        if (!$integration->bridge_customer_id) {
                            $integration->bridge_customer_id = $queryParams['customer_id'];
                        }
                    }
                }

                $integration->bridge_metadata = $metadata;
                $integration->save();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tos_url' => $tosUrl,
                    'tos_link_id' => $tosLinkId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('TOS link error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to get TOS link'], 500);
        }
    }

    /**
     * Handle TOS callback (redirect from Bridge)
     */
    public function tosCallback(Request $request)
    {
        try {
            $signedAgreementId = $request->input('signed_agreement_id') ?? $request->query('signed_agreement_id');

            if (!$signedAgreementId) {
                if ($request->expectsJson() || $request->isMethod('POST')) {
                    return response()->json(['success' => false, 'message' => 'TOS acceptance failed'], 400);
                }
                if ($request->isMethod('GET')) {
                    return response()->view('bridge.tos-callback', [
                        'signedAgreementId' => '',
                        'success' => false,
                        'error' => 'TOS acceptance failed. No signed_agreement_id received.',
                    ]);
                }
                return redirect('/wallet')->with('error', 'TOS acceptance failed');
            }

            $user = Auth::user();
            $integration = null;
            $entity = null;
            $entityType = null;

            if ($user) {
                $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

                if ($isOrgUser) {
                    $organization = $user->organization;
                    if ($organization) {
                        $entity = $organization;
                        $entityType = Organization::class;
                    }
                } else {
                    $entity = $user;
                    $entityType = User::class;
                }

                if ($entity) {
                    $integration = BridgeIntegration::where('integratable_id', $entity->id)
                        ->where('integratable_type', $entityType)
                        ->first();
                }
            }

            // Fallback: Find by customer_id (but ONLY if it exists in Bridge)
            $customerId = $request->input('customer_id') ?? $request->query('customer_id');
            if (!$integration && $customerId) {
                // FIRST verify the customer actually exists in Bridge before using it
                // Note: 404 is expected if customer_id is wrong/old, so we handle it gracefully
                $customerExistsInBridge = false;
                try {
                    $verifyResult = $this->bridgeService->getCustomer($customerId);
                    $customerExistsInBridge = $verifyResult['success'];

                    // If customer doesn't exist (404), this is expected - don't log as error
                    if (!$customerExistsInBridge && isset($verifyResult['status']) && $verifyResult['status'] === 404) {
                        Log::info('TOS callback: Customer ID from request does not exist in Bridge (expected if ID is outdated)', [
                            'customer_id' => $customerId,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('TOS callback: Exception while verifying customer exists in Bridge', [
                        'customer_id' => $customerId,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                // Only use customer_id from request if it actually exists in Bridge
                if ($customerExistsInBridge) {
                    $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();
                    
                    // If still not found, try to get customer from Bridge and match by email
                    if (!$integration) {
                        try {
                            $customerResult = $this->bridgeService->getCustomer($customerId);
                            if ($customerResult['success'] && isset($customerResult['data']['email'])) {
                                $customerEmail = $customerResult['data']['email'];
                                
                                // Try to find integration by matching email with organization or user
                                // First try organization
                                $organization = \App\Models\Organization::where('email', $customerEmail)->first();
                                if ($organization) {
                                    $integration = BridgeIntegration::where('integratable_id', $organization->id)
                                        ->where('integratable_type', Organization::class)
                                        ->first();
                                    
                                    // If integration exists but doesn't have customer_id, update it
                                    if ($integration && !$integration->bridge_customer_id) {
                                        $integration->bridge_customer_id = $customerId;
                                        $integration->save();
                                    }
                                }
                                
                                // If still not found, try user
                                if (!$integration) {
                                    $userByEmail = \App\Models\User::where('email', $customerEmail)->first();
                                    if ($userByEmail) {
                                        $integration = BridgeIntegration::where('integratable_id', $userByEmail->id)
                                            ->where('integratable_type', \App\Models\User::class)
                                            ->first();
                                        
                                        // If integration exists but doesn't have customer_id, update it
                                        if ($integration && !$integration->bridge_customer_id) {
                                            $integration->bridge_customer_id = $customerId;
                                            $integration->save();
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning('TOS callback: Failed to fetch customer from Bridge', [
                                'customer_id' => $customerId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } else {
                    // Customer ID from request doesn't exist in Bridge - try to find correct customer
                    Log::warning('TOS callback: Customer ID from request does not exist in Bridge, searching all customers', [
                        'customer_id' => $customerId,
                        'signed_agreement_id' => $signedAgreementId,
                    ]);
                    
                    // Try to find the correct customer by searching all customers and matching by email
                    // This handles cases where the customer_id in the request is wrong/old
                    try {
                        $allCustomersResult = $this->bridgeService->getCustomers();
                        
                        if ($allCustomersResult['success'] && isset($allCustomersResult['data'])) {
                            $allCustomers = $allCustomersResult['data'];
                            
                            // Handle both array and object responses
                            if (isset($allCustomers['data']) && is_array($allCustomers['data'])) {
                                $allCustomers = $allCustomers['data'];
                            } elseif (!is_array($allCustomers)) {
                                $allCustomers = [$allCustomers];
                            }
                            
                            // Try to find customer by matching with existing integrations
                            foreach ($allCustomers as $bridgeCustomer) {
                                $bridgeCustomerId = $bridgeCustomer['id'] ?? null;
                                $bridgeCustomerEmail = $bridgeCustomer['email'] ?? null;
                                
                                if (!$bridgeCustomerId) {
                                    continue;
                                }
                                
                                // Check if this customer_id matches any existing integration
                                $matchingIntegration = BridgeIntegration::where('bridge_customer_id', $bridgeCustomerId)->first();
                                
                                if ($matchingIntegration) {
                                    // Found integration with this customer_id
                                    $integration = $matchingIntegration;
                                    $customerId = $bridgeCustomerId;
                                    Log::info('TOS callback: Found integration by searching all Bridge customers', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'original_customer_id' => $customerId,
                                    ]);
                                    break;
                                }
                                
                                // If no direct match, try matching by email
                                if ($bridgeCustomerEmail && !$integration) {
                                    // Try organization first
                                    $organization = \App\Models\Organization::where('email', $bridgeCustomerEmail)->first();
                                    if ($organization) {
                                        $potentialIntegration = BridgeIntegration::where('integratable_id', $organization->id)
                                            ->where('integratable_type', Organization::class)
                                            ->first();
                                        
                                        if ($potentialIntegration) {
                                            // Found integration by email - update customer_id
                                            $integration = $potentialIntegration;
                                            $integration->bridge_customer_id = $bridgeCustomerId;
                                            $integration->save();
                                            $customerId = $bridgeCustomerId;
                                            Log::info('TOS callback: Found integration by email, updated customer_id', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'email' => $bridgeCustomerEmail,
                                            ]);
                                            break;
                                        }
                                    }
                                    
                                    // Try user if not found
                                    if (!$integration) {
                                        $userByEmail = \App\Models\User::where('email', $bridgeCustomerEmail)->first();
                                        if ($userByEmail) {
                                            $potentialIntegration = BridgeIntegration::where('integratable_id', $userByEmail->id)
                                                ->where('integratable_type', \App\Models\User::class)
                                                ->first();
                                            
                                            if ($potentialIntegration) {
                                                // Found integration by email - update customer_id
                                                $integration = $potentialIntegration;
                                                $integration->bridge_customer_id = $bridgeCustomerId;
                                                $integration->save();
                                                $customerId = $bridgeCustomerId;
                                                Log::info('TOS callback: Found integration by email, updated customer_id', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'email' => $bridgeCustomerEmail,
                                                ]);
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('TOS callback: Failed to search all customers when customer_id not found', [
                            'error' => $e->getMessage(),
                            'original_customer_id' => $customerId,
                        ]);
                    }
                    
                    // If still not found, clear customer_id
                    if (!$integration) {
                        $customerId = null;
                    }
                }
            }

            // Fallback: Find by signed_agreement_id in metadata
            if (!$integration && $signedAgreementId) {
                // Try JSON contains first
                $integration = BridgeIntegration::whereJsonContains('bridge_metadata->signed_agreement_id', $signedAgreementId)->first();
                
                // Alternative: Search in metadata as string (for older records)
                if (!$integration) {
                    $integrations = BridgeIntegration::whereNotNull('bridge_metadata')->get();
                    foreach ($integrations as $int) {
                        $metadata = $int->bridge_metadata ?? [];
                        if (isset($metadata['signed_agreement_id']) && $metadata['signed_agreement_id'] === $signedAgreementId) {
                            $integration = $int;
                            break;
                        }
                    }
                }
                
                // If still not found, try to get customer_id from Bridge by searching for customer with this signed_agreement_id
                if (!$integration && $signedAgreementId) {
                    Log::info('TOS callback: Searching Bridge for customer with signed_agreement_id', [
                        'signed_agreement_id' => $signedAgreementId,
                    ]);
                    
                    try {
                        // List all customers from Bridge and find the one with matching signed_agreement_id
                        $customersResult = $this->bridgeService->getCustomers();
                        
                        if ($customersResult['success'] && isset($customersResult['data'])) {
                            $customers = $customersResult['data'];
                            
                            // Handle both array and object responses
                            if (isset($customers['data']) && is_array($customers['data'])) {
                                $customers = $customers['data'];
                            } elseif (!is_array($customers)) {
                                $customers = [$customers];
                            }
                            
                            foreach ($customers as $customer) {
                                $customerSignedAgreementId = $customer['signed_agreement_id'] ?? null;
                                
                                if ($customerSignedAgreementId === $signedAgreementId) {
                                    $foundCustomerId = $customer['id'] ?? null;
                                    
                                    if ($foundCustomerId) {
                                        Log::info('TOS callback: Found customer in Bridge by signed_agreement_id', [
                                            'customer_id' => $foundCustomerId,
                                            'signed_agreement_id' => $signedAgreementId,
                                        ]);
                                        
                                        // Now find integration by customer_id
                                        $integration = BridgeIntegration::where('bridge_customer_id', $foundCustomerId)->first();
                                        
                                        // If integration found but doesn't have customer_id, update it
                                        if ($integration && !$integration->bridge_customer_id) {
                                            $integration->bridge_customer_id = $foundCustomerId;
                                            $integration->save();
                                        }
                                        
                                        // If still no integration, try to find by email
                                        if (!$integration && isset($customer['email'])) {
                                            $customerEmail = $customer['email'];
                                            
                                            // Try organization first
                                            $organization = \App\Models\Organization::where('email', $customerEmail)->first();
                                            if ($organization) {
                                                $integration = BridgeIntegration::where('integratable_id', $organization->id)
                                                    ->where('integratable_type', Organization::class)
                                                    ->first();
                                                
                                                if ($integration) {
                                                    $integration->bridge_customer_id = $foundCustomerId;
                                                    $integration->save();
                                                }
                                            }
                                            
                                            // Try user if not found
                                            if (!$integration) {
                                                $userByEmail = \App\Models\User::where('email', $customerEmail)->first();
                                                if ($userByEmail) {
                                                    $integration = BridgeIntegration::where('integratable_id', $userByEmail->id)
                                                        ->where('integratable_type', \App\Models\User::class)
                                                        ->first();
                                                    
                                                    if ($integration) {
                                                        $integration->bridge_customer_id = $foundCustomerId;
                                                        $integration->save();
                                                    }
                                                }
                                            }
                                        }
                                        
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('TOS callback: Failed to search Bridge for customer by signed_agreement_id', [
                            'signed_agreement_id' => $signedAgreementId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            
            // Last resort: If we have customer_id (and it exists in Bridge), 
            // try to find any integration that might be related (check all integrations)
            // NOTE: $customerId is only set if it exists in Bridge (from previous check)
            if (!$integration && $customerId) {
                // Check if there's only one integration - might be the one we need
                $integrationCount = BridgeIntegration::count();
                if ($integrationCount === 1) {
                    $integration = BridgeIntegration::first();
                    // Only update if missing or if existing one doesn't exist in Bridge
                    if ($integration) {
                        $shouldUpdate = false;
                        $oldCustomerId = $integration->bridge_customer_id;
                        
                        if (!$oldCustomerId) {
                            // No customer_id, safe to update
                            $shouldUpdate = true;
                        } else {
                            // Verify existing customer_id still exists in Bridge
                            try {
                                $oldCustomerResult = $this->bridgeService->getCustomer($oldCustomerId);
                                if (!$oldCustomerResult['success']) {
                                    // Old customer doesn't exist, safe to update
                                    $shouldUpdate = true;
                                    Log::info('TOS callback: Existing customer_id no longer exists in Bridge, updating', [
                                        'old_customer_id' => $oldCustomerId,
                                        'new_customer_id' => $customerId,
                                    ]);
                                } else {
                                    // Old customer exists, don't overwrite
                                    Log::info('TOS callback: Existing customer_id still valid, not overwriting', [
                                        'existing_customer_id' => $oldCustomerId,
                                        'requested_customer_id' => $customerId,
                                    ]);
                                }
                            } catch (\Exception $e) {
                                // Error checking, be conservative and don't update
                                Log::warning('TOS callback: Error verifying existing customer_id', [
                                    'old_customer_id' => $oldCustomerId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                        
                        if ($shouldUpdate) {
                            Log::info('TOS callback: Using single integration and updating customer_id', [
                                'integration_id' => $integration->id,
                                'old_customer_id' => $oldCustomerId,
                                'new_customer_id' => $customerId,
                            ]);
                            $integration->bridge_customer_id = $customerId;
                            $integration->save();
                        }
                    }
                } else {
                    // If multiple integrations, try to find the most recent one without a customer_id
                    $integration = BridgeIntegration::whereNull('bridge_customer_id')
                        ->orWhere('bridge_customer_id', '')
                        ->latest()
                        ->first();
                    
                    if ($integration) {
                        Log::info('TOS callback: Found integration without customer_id and updating it', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                        ]);
                        $integration->bridge_customer_id = $customerId;
                        $integration->save();
                    }
                }
            }
            
            // Final fallback: If we still don't have integration but have signed_agreement_id,
            // and there's only one integration, use it (likely the correct one)
            if (!$integration && $signedAgreementId) {
                $integrationCount = BridgeIntegration::count();
                if ($integrationCount === 1) {
                    $integration = BridgeIntegration::first();
                    Log::info('TOS callback: Using single integration as final fallback', [
                        'integration_id' => $integration->id,
                        'signed_agreement_id' => $signedAgreementId,
                        'existing_customer_id' => $integration->bridge_customer_id,
                    ]);
                    
                    // If integration doesn't have customer_id, try to get it from Bridge using signed_agreement_id
                    if (!$integration->bridge_customer_id) {
                        try {
                            $customersResult = $this->bridgeService->getCustomers();
                            
                            if ($customersResult['success'] && isset($customersResult['data'])) {
                                $customers = $customersResult['data'];
                                
                                // Handle both array and object responses
                                if (isset($customers['data']) && is_array($customers['data'])) {
                                    $customers = $customers['data'];
                                } elseif (!is_array($customers)) {
                                    $customers = [$customers];
                                }
                                
                                foreach ($customers as $customer) {
                                    $customerSignedAgreementId = $customer['signed_agreement_id'] ?? null;
                                    
                                    if ($customerSignedAgreementId === $signedAgreementId) {
                                        $foundCustomerId = $customer['id'] ?? null;
                                        
                                        if ($foundCustomerId) {
                                            $integration->bridge_customer_id = $foundCustomerId;
                                            $integration->save();
                                            
                                            Log::info('TOS callback: Updated single integration with customer_id from Bridge', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $foundCustomerId,
                                            ]);
                                            break;
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning('TOS callback: Failed to get customer_id from Bridge for single integration', [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            if (! $integration && $user && $entity && $entityType) {
                $isOrgUserForLink = $user->hasRole(['organization', 'organization_pending']);
                $linked = $this->linkBridgeCustomerByEmail($entity, $entityType, $user, $isOrgUserForLink, null);
                if ($linked) {
                    $integration = $linked;
                    Log::info('TOS callback: Linked integration by email before accepting ToS', [
                        'integration_id' => $integration->id,
                        'bridge_customer_id' => $integration->bridge_customer_id,
                    ]);
                }
            }

            if (!$integration) {
                // Enhanced logging to help debug
                $allIntegrations = BridgeIntegration::whereNotNull('bridge_customer_id')->get(['id', 'bridge_customer_id', 'integratable_id', 'integratable_type']);
                $customerIds = $allIntegrations->pluck('bridge_customer_id')->toArray();
                
                Log::warning('TOS callback: Cannot find integration', [
                    'signed_agreement_id' => $signedAgreementId,
                    'user_id' => $user->id ?? null,
                    'customer_id' => $customerId,
                    'customer_id_in_request' => $request->input('customer_id') ?? $request->query('customer_id'),
                    'total_integrations' => BridgeIntegration::count(),
                    'integrations_with_customer_id' => count($customerIds),
                    'existing_customer_ids' => $customerIds,
                    'searched_customer_id_exists' => in_array($customerId, $customerIds),
                ]);

                if ($request->isMethod('GET')) {
                    return response()->view('bridge.tos-callback', [
                        'signedAgreementId' => $signedAgreementId,
                        'success' => false,
                        'error' => 'Cannot identify user. Please try accepting TOS again.',
                    ]);
                }
                return response()->json(['success' => false, 'message' => 'Integration not found'], 404);
            }

            // ============================================
            // CRITICAL: Verify customer exists in Bridge before updating
            // ============================================
            $customerIdToUpdate = $integration->bridge_customer_id;
            $customerExists = false;
            
            // If we have a customer_id, verify it exists in Bridge
            if ($customerIdToUpdate) {
                try {
                    $verifyResult = $this->bridgeService->getCustomer($customerIdToUpdate);
                    $customerExists = $verifyResult['success'];
                    
                    if (!$customerExists) {
                        Log::warning('TOS callback: Customer ID in integration does not exist in Bridge', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerIdToUpdate,
                            'signed_agreement_id' => $signedAgreementId,
                        ]);
                        
                        // Try to find the correct customer by signed_agreement_id
                        if ($signedAgreementId) {
                            try {
                                $customersResult = $this->bridgeService->getCustomers();
                                
                                if ($customersResult['success'] && isset($customersResult['data'])) {
                                    $customers = $customersResult['data'];
                                    
                                    // Handle both array and object responses
                                    if (isset($customers['data']) && is_array($customers['data'])) {
                                        $customers = $customers['data'];
                                    } elseif (!is_array($customers)) {
                                        $customers = [$customers];
                                    }
                                    
                                    foreach ($customers as $customer) {
                                        $customerSignedAgreementId = $customer['signed_agreement_id'] ?? null;
                                        
                                        if ($customerSignedAgreementId === $signedAgreementId) {
                                            $foundCustomerId = $customer['id'] ?? null;
                                            
                                            if ($foundCustomerId) {
                                                Log::info('TOS callback: Found correct customer by signed_agreement_id, updating integration', [
                                                    'integration_id' => $integration->id,
                                                    'old_customer_id' => $customerIdToUpdate,
                                                    'new_customer_id' => $foundCustomerId,
                                                    'signed_agreement_id' => $signedAgreementId,
                                                ]);
                                                
                                                // Update integration with correct customer_id
                                                $integration->bridge_customer_id = $foundCustomerId;
                                                $integration->save();
                                                
                                                $customerIdToUpdate = $foundCustomerId;
                                                $customerExists = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::error('TOS callback: Failed to search for customer by signed_agreement_id', [
                                    'error' => $e->getMessage(),
                                    'signed_agreement_id' => $signedAgreementId,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('TOS callback: Failed to verify customer exists in Bridge', [
                        'customer_id' => $customerIdToUpdate,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // No customer_id in integration, try to find by signed_agreement_id
                if ($signedAgreementId) {
                    try {
                        $customersResult = $this->bridgeService->getCustomers();
                        
                        if ($customersResult['success'] && isset($customersResult['data'])) {
                            $customers = $customersResult['data'];
                            
                            // Handle both array and object responses
                            if (isset($customers['data']) && is_array($customers['data'])) {
                                $customers = $customers['data'];
                            } elseif (!is_array($customers)) {
                                $customers = [$customers];
                            }
                            
                            foreach ($customers as $customer) {
                                $customerSignedAgreementId = $customer['signed_agreement_id'] ?? null;
                                
                                if ($customerSignedAgreementId === $signedAgreementId) {
                                    $foundCustomerId = $customer['id'] ?? null;
                                    
                                    if ($foundCustomerId) {
                                        Log::info('TOS callback: Found customer by signed_agreement_id for integration without customer_id', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $foundCustomerId,
                                            'signed_agreement_id' => $signedAgreementId,
                                        ]);
                                        
                                        // Update integration with customer_id
                                        $integration->bridge_customer_id = $foundCustomerId;
                                        $integration->save();
                                        
                                        $customerIdToUpdate = $foundCustomerId;
                                        $customerExists = true;
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('TOS callback: Failed to find customer by signed_agreement_id', [
                            'error' => $e->getMessage(),
                            'signed_agreement_id' => $signedAgreementId,
                        ]);
                    }
                }
            }
            
            // If we still don't have a valid customer, return error
            if (! $customerIdToUpdate || ! $customerExists) {
                Log::error('TOS callback: Cannot find valid customer in Bridge', [
                    'integration_id' => $integration->id,
                    'signed_agreement_id' => $signedAgreementId,
                    'customer_id_in_integration' => $integration->bridge_customer_id,
                ]);

                if ($request->isMethod('GET')) {
                    return response()->view('bridge.tos-callback', [
                        'signedAgreementId' => $signedAgreementId,
                        'success' => false,
                        'error' => 'Cannot find customer in Bridge. Please contact support.',
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot find customer in Bridge',
                ], 404);
            }

            // ============================================
            // Update Bridge customer with signed_agreement_id (skip if ToS already accepted)
            // ============================================
            $customerResult = $this->bridgeService->getCustomer($customerIdToUpdate);
            $alreadyAcceptedOnBridge = $customerResult['success']
                && ($customerResult['data']['has_accepted_terms_of_service'] ?? false);

            if (! $alreadyAcceptedOnBridge) {
                $updateResult = $this->bridgeService->updateCustomer(
                    $customerIdToUpdate,
                    [
                        'signed_agreement_id' => $signedAgreementId,
                    ]
                );

                if (! $updateResult['success']) {
                    $recheck = $this->bridgeService->getCustomer($customerIdToUpdate);
                    $alreadyAcceptedOnBridge = $recheck['success']
                        && ($recheck['data']['has_accepted_terms_of_service'] ?? false);

                    if ($alreadyAcceptedOnBridge) {
                        $customerResult = $recheck;
                        Log::info('TOS callback: updateCustomer failed but Bridge already shows ToS accepted', [
                            'customer_id' => $customerIdToUpdate,
                            'signed_agreement_id' => $signedAgreementId,
                        ]);
                    } else {
                        Log::error('TOS callback: Failed to update Bridge customer', [
                            'customer_id' => $customerIdToUpdate,
                            'signed_agreement_id' => $signedAgreementId,
                            'error' => $updateResult['error'] ?? 'Unknown error',
                        ]);

                        if ($request->isMethod('GET')) {
                            return response()->view('bridge.tos-callback', [
                                'signedAgreementId' => $signedAgreementId,
                                'success' => false,
                                'error' => 'Failed to update Bridge: ' . ($updateResult['error'] ?? 'Unknown error'),
                            ]);
                        }

                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to update Bridge customer',
                        ], 500);
                    }
                } else {
                    Log::info('TOS callback: Bridge customer updated successfully', [
                        'customer_id' => $customerIdToUpdate,
                        'signed_agreement_id' => $signedAgreementId,
                    ]);
                    $customerResult = $this->bridgeService->getCustomer($customerIdToUpdate);
                }
            } else {
                Log::info('TOS callback: Skipping updateCustomer — ToS already accepted on Bridge', [
                    'customer_id' => $customerIdToUpdate,
                    'signed_agreement_id' => $signedAgreementId,
                ]);
            }

            Log::info('TOS callback: Using customer data from Bridge API', [
                'customer_id' => $customerIdToUpdate,
                'signed_agreement_id' => $signedAgreementId,
            ]);
            
            $tosStatus = 'approved'; // Default
            $hasAcceptedTos = false;
            
            if (!$customerResult['success']) {
                Log::error('TOS callback: Failed to fetch customer from Bridge after TOS acceptance', [
                    'customer_id' => $customerIdToUpdate,
                    'error' => $customerResult['error'] ?? 'Unknown error',
                ]);
                // Still update with signed_agreement_id but mark as warning
                $tosStatus = 'approved';
            } else {
                // Get FRESH TOS status from Bridge response
                $customer = $customerResult['data'];
                $hasAcceptedTos = $customer['has_accepted_terms_of_service'] ?? false;
                $endorsements = $customer['endorsements'] ?? [];
                
                // Check endorsements for TOS status
                $tosAcceptedFromBridge = false;
                $tosStatusFromBridge = 'pending';
                
                if (! empty($endorsements)) {
                    foreach ($endorsements as $endorsement) {
                        $complete = $endorsement['requirements']['complete'] ?? $endorsement['complete'] ?? [];
                        $missing = $endorsement['requirements']['missing'] ?? $endorsement['missing'] ?? [];

                        foreach ($complete as $item) {
                            if (is_string($item) && str_contains($item, 'terms_of_service')) {
                                $tosAcceptedFromBridge = true;
                                $tosStatusFromBridge = 'accepted';
                                break 2;
                            }
                        }

                        if (! $tosAcceptedFromBridge && is_array($missing)) {
                            $missingItems = $missing['all_of'] ?? $missing;
                            if (is_array($missingItems)) {
                                foreach ($missingItems as $item) {
                                    if (is_string($item) && str_contains($item, 'terms_of_service')) {
                                        $tosStatusFromBridge = 'pending';
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Use Bridge's has_accepted_terms_of_service as primary source
                if ($hasAcceptedTos) {
                    $tosAcceptedFromBridge = true;
                    $tosStatusFromBridge = 'accepted';
                }
                
                Log::info('TOS callback: FRESH TOS status from Bridge API', [
                    'customer_id' => $integration->bridge_customer_id,
                    'has_accepted_terms_of_service' => $hasAcceptedTos,
                    'tos_accepted_from_endorsements' => $tosAcceptedFromBridge,
                    'tos_status_from_bridge' => $tosStatusFromBridge,
                    'endorsements' => $endorsements,
                    'signed_agreement_id_from_bridge' => $customer['signed_agreement_id'] ?? null,
                ]);
                
                // Update local database with FRESH status from Bridge
                $tosStatus = $tosAcceptedFromBridge
                    ? BridgeIntegration::normalizeTosStatus('approved')
                    : BridgeIntegration::normalizeTosStatus($tosStatusFromBridge);
            }

            // Ensure tos_status is set - if we have a signed_agreement_id, TOS must be accepted
            if ($signedAgreementId && empty($tosStatus)) {
                $tosStatus = BridgeIntegration::normalizeTosStatus('approved');
            }
            
            // Final fallback: if tosStatus is still empty or invalid, default to approved since we have signed_agreement_id
            if (empty($tosStatus) || ! BridgeIntegration::isTosAccepted($tosStatus)) {
                if ($signedAgreementId) {
                    $tosStatus = BridgeIntegration::normalizeTosStatus('approved');
                } else {
                    $tosStatus = BridgeIntegration::normalizeTosStatus($tosStatus);
                }
            }

            // Update local database
            // Store signed_agreement_id in bridge_metadata (JSON column), not as a direct column
            $metadata = $integration->bridge_metadata ?? [];
            if (!is_array($metadata)) {
                // Ensure metadata is an array (in case it's stored as JSON string)
                $metadata = is_string($metadata) ? json_decode($metadata, true) : [];
            }
            $metadata['signed_agreement_id'] = $signedAgreementId;
            $metadata['tos_accepted_at'] = now()->toIso8601String();
            
            // Store FRESH customer data from Bridge response
            if (isset($customerResult['data'])) {
                $metadata['tos_callback_customer_data'] = $customerResult['data'];
                $metadata['tos_callback_fetched_at'] = now()->toIso8601String();
            }
            
            $integration->bridge_metadata = $metadata;
            
            // Use update() to ensure proper JSON encoding - ALWAYS set tos_status to approved when signed
            $finalTosStatus = BridgeIntegration::normalizeTosStatus(
                $signedAgreementId ? 'approved' : $tosStatus
            );
            
            $integration->update([
                'tos_status' => $finalTosStatus,
                'bridge_metadata' => $metadata,
            ]);

            if ($customerResult['success'] && ! empty($customerResult['data'])) {
                $isOrgUserForSync = $integration->integratable_type === Organization::class;
                $this->applyBridgeCustomerSnapshot(
                    $integration,
                    $customerResult['data'],
                    $isOrgUserForSync,
                );
                $integration->save();
                $integration->refresh();
            }
            
            Log::info('TOS callback: Setting tos_status', [
                'integration_id' => $integration->id,
                'signed_agreement_id' => $signedAgreementId,
                'tos_status_before' => $integration->getOriginal('tos_status'),
                'tos_status_after' => $finalTosStatus,
                'has_accepted_tos' => $hasAcceptedTos ?? false,
            ]);

            // Refresh to ensure we have the latest data
            $integration->refresh();

            // Verify the save worked
            $savedMetadata = $integration->bridge_metadata ?? [];
            if (!is_array($savedMetadata)) {
                $savedMetadata = is_string($savedMetadata) ? json_decode($savedMetadata, true) : [];
            }
            
            Log::info('TOS callback: Successfully updated', [
                'integration_id' => $integration->id,
                'tos_status' => $integration->tos_status,
                'signed_agreement_id' => $signedAgreementId,
                'bridge_metadata' => $savedMetadata,
                'metadata_has_signed_agreement_id' => isset($savedMetadata['signed_agreement_id']),
                'saved_signed_agreement_id' => $savedMetadata['signed_agreement_id'] ?? 'NOT FOUND',
            ]);

            if ($request->isMethod('POST')) {
                return response()->json([
                    'success' => true,
                    'signed_agreement_id' => $signedAgreementId,
                    'tos_status' => $integration->tos_status ?? BridgeIntegration::normalizeTosStatus('approved'),
                ]);
            }

            return response()->view('bridge.tos-callback', [
                'signedAgreementId' => $signedAgreementId,
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('TOS callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->isMethod('GET')) {
                return response()->view('bridge.tos-callback', [
                    'signedAgreementId' => $request->input('signed_agreement_id') ?? '',
                    'success' => false,
                    'error' => 'Error processing TOS acceptance.',
                ]);
            }
            return response()->json(['success' => false, 'message' => 'Error'], 500);
        }
    }

    /**
     * Check TOS status from Bridge API via customer endorsements
     */
    public function checkTosStatus(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = $user->organization;
                if (!$organization) {
                    return response()->json([
                        'success' => false,
                        'tos_accepted' => false,
                        'message' => 'Organization not found.',
                    ], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
            } else {
                $entity = $user;
                $entityType = User::class;
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'tos_accepted' => false,
                    'message' => 'Customer not created yet',
                ], 200);
            }

            // Get customer from Bridge
            $result = $this->bridgeService->getCustomer($integration->bridge_customer_id);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get customer from Bridge',
                    'error' => $result['error'] ?? 'Unknown error',
                ], 500);
            }

            $customer = $result['data'];
            $endorsements = $customer['endorsements'] ?? [];

            // Check if terms_of_service is in "complete" requirements
            $tosAccepted = false;
            $tosStatus = 'pending';

            // Also check Bridge's has_accepted_terms_of_service flag
            $hasAcceptedFromBridge = $customer['has_accepted_terms_of_service'] ?? false;

            foreach ($endorsements as $endorsement) {
                $complete = $endorsement['requirements']['complete'] ?? [];
                $missing = $endorsement['requirements']['missing'] ?? [];

                // Check if TOS is in complete requirements
                if (in_array('terms_of_service_v1', $complete) || in_array('terms_of_service_v2', $complete)) {
                    $tosAccepted = true;
                    $tosStatus = 'accepted';
                    break;
                }

                // Check if TOS is in missing requirements (nested structure)
                // Bridge returns missing as: { "all_of": ["terms_of_service_v1", ...] }
                if (isset($missing['all_of']) && is_array($missing['all_of'])) {
                    foreach ($missing['all_of'] as $requirement) {
                        if (is_string($requirement) && (strpos($requirement, 'terms_of_service') !== false)) {
                            // TOS is still missing
                            $tosStatus = 'pending';
                            break 2; // Break out of both loops
                        }
                    }
                } elseif (is_array($missing)) {
                    // Handle flat array structure
                    if (in_array('terms_of_service_v1', $missing) || in_array('terms_of_service_v2', $missing)) {
                        $tosStatus = 'pending';
                        break;
                    }
                }
            }

            // Use Bridge's has_accepted_terms_of_service as the source of truth
            if ($hasAcceptedFromBridge) {
                $tosAccepted = true;
                $tosStatus = 'accepted';
            }

            // Update local database to match Bridge's status
            if ($integration->tos_status !== $tosStatus) {
                Log::info('Syncing TOS status from Bridge', [
                    'integration_id' => $integration->id,
                    'old_status' => $integration->tos_status,
                    'new_status' => $tosStatus,
                    'bridge_has_accepted' => $hasAcceptedFromBridge,
                ]);

                $integration->tos_status = $tosStatus;
                $metadata = $integration->bridge_metadata ?? [];
                $metadata['tos_synced_at'] = now()->toIso8601String();
                $metadata['bridge_has_accepted_terms'] = $hasAcceptedFromBridge;
                $integration->bridge_metadata = $metadata;
                $integration->save();
            }

            return response()->json([
                'success' => true,
                'tos_accepted' => $tosAccepted,
                'tos_status' => $tosStatus,
                'bridge_has_accepted_terms_of_service' => $hasAcceptedFromBridge,
                'local_status' => $integration->tos_status,
                'synced' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Check TOS status error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to check TOS status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync TOS status from Bridge API (force sync)
     */
    public function syncTosStatus(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = $user->organization;
                if (!$organization) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Organization not found.',
                    ], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
            } else {
                $entity = $user;
                $entityType = User::class;
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not created yet',
                ], 404);
            }

            // Get customer from Bridge
            $result = $this->bridgeService->getCustomer($integration->bridge_customer_id);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get customer from Bridge',
                    'error' => $result['error'] ?? 'Unknown error',
                ], 500);
            }

            $customer = $result['data'];
            $endorsements = $customer['endorsements'] ?? [];
            $hasAcceptedFromBridge = $customer['has_accepted_terms_of_service'] ?? false;

            // Check endorsements for TOS status
            $tosAccepted = false;
            $tosStatus = 'pending';

            foreach ($endorsements as $endorsement) {
                $complete = $endorsement['requirements']['complete'] ?? [];

                // Check if TOS is in complete requirements
                if (in_array('terms_of_service_v1', $complete) || in_array('terms_of_service_v2', $complete)) {
                    $tosAccepted = true;
                    $tosStatus = 'accepted';
                    break;
                }
            }

            // Use Bridge's has_accepted_terms_of_service as the source of truth
            if ($hasAcceptedFromBridge) {
                $tosAccepted = true;
                $tosStatus = 'accepted';
            } else {
                // If not accepted, check if it's in missing
                foreach ($endorsements as $endorsement) {
                    $missing = $endorsement['requirements']['missing'] ?? [];

                    // Check nested missing structure
                    if (isset($missing['all_of']) && is_array($missing['all_of'])) {
                        foreach ($missing['all_of'] as $requirement) {
                            if (is_string($requirement) && (strpos($requirement, 'terms_of_service') !== false)) {
                                $tosStatus = 'pending';
                                break 2;
                            }
                        }
                    }
                }
            }

            // Update local database to match Bridge's status
            $oldStatus = $integration->tos_status;
            $integration->tos_status = $tosStatus;
            $metadata = $integration->bridge_metadata ?? [];
            $metadata['tos_synced_at'] = now()->toIso8601String();
            $metadata['bridge_has_accepted_terms'] = $hasAcceptedFromBridge;
            $integration->bridge_metadata = $metadata;
            $integration->save();

            Log::info('TOS status synced from Bridge', [
                'integration_id' => $integration->id,
                'old_status' => $oldStatus,
                'new_status' => $tosStatus,
                'bridge_has_accepted' => $hasAcceptedFromBridge,
            ]);

            return response()->json([
                'success' => true,
                'tos_accepted' => $tosAccepted,
                'tos_status' => $tosStatus,
                'bridge_has_accepted_terms_of_service' => $hasAcceptedFromBridge,
                'local_status' => $integration->tos_status,
                'synced' => true,
                'message' => $oldStatus !== $tosStatus
                    ? "TOS status updated from '{$oldStatus}' to '{$tosStatus}'"
                    : "TOS status is already in sync: '{$tosStatus}'",
            ]);
        } catch (\Exception $e) {
            Log::error('Sync TOS status error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync TOS status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Custom KYC/KYB submission is disabled — verification uses the Bridge Persona modal in the wallet.
     */
    public function createCustomerWithKyc(Request $request)
    {
                    return response()->json([
                        'success' => false,
            'message' => 'Verification is completed only through the Bridge window in your wallet. Custom forms are not supported.',
            'error_code' => 'persona_modal_only',
                ], 422);
    }

    public function getControlPersonKycLink(Request $request)
    {
        try {
            $validated = $request->validate([
                'control_person_email' => 'required|email',
            ]);

            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if (!$isOrgUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint is only available for business accounts.',
                ], 403);
            }

            $organization = $user->organization;
            if (!$organization) {
                return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
            }

            $integration = BridgeIntegration::where('integratable_id', $organization->id)
                ->where('integratable_type', Organization::class)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Business customer not found. Please complete Step 1: Control Person first.',
                ], 404);
            }

            // Get control person info from metadata
            $metadata = is_array($integration->bridge_metadata) 
                ? $integration->bridge_metadata 
                : json_decode($integration->bridge_metadata ?? '{}', true);
            
            $controlPersonEmail = $validated['control_person_email'];
            $controlPersonFirstName = $metadata['control_person_first_name'] ?? '';
            $controlPersonLastName = $metadata['control_person_last_name'] ?? '';
            $fullName = trim($controlPersonFirstName . ' ' . $controlPersonLastName);

            $redirectUri = $request->input('redirect_url');

            $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
            $baseApproved = $this->bridgeService->getBaseEndorsementInfo($customerResult['data'] ?? [])['approved'] ?? false;
            $kycLinkId = $integration->kyb_link_id ?? $integration->kyc_link_id;
            $effectiveKycLinkId = $baseApproved ? null : $kycLinkId;

            $linkResult = $this->bridgeService->resolveCardsEndorsementKycLink(
                $integration->bridge_customer_id,
                is_string($redirectUri) && $redirectUri !== '' ? $redirectUri : null,
                $effectiveKycLinkId,
                $this->resolveIntegratablePhoneForCards($integration),
            );

            if ($linkResult['cards_already_approved'] ?? false) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cards verification is already complete.',
                    'data' => ['cards_already_approved' => true],
                ]);
            }

            if ($linkResult['phone_required'] ?? false) {
                    return response()->json([
                        'success' => false,
                    'message' => 'A valid US phone number is required for cards verification.',
                    'error_code' => 'cards_endorsement_phone_required',
                ], 400);
            }

            if (! ($linkResult['success'] ?? false) || empty($linkResult['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cards verification link is temporarily unavailable from Bridge. Please try again in a moment.',
                    'error_code' => 'cards_endorsement_kyc_link_failed',
                    'endorsement' => 'cards',
                    'bridge_error' => $linkResult['error'] ?? null,
                ], 502);
            }

            $kycLink = $linkResult['url'];

            $metadata['control_person_kyc_link'] = $kycLink;
            $integration->kyb_link_url = $kycLink;
            $linkData = $linkResult['data'] ?? [];
            if (! empty($linkData['id'])) {
                $integration->kyb_link_id = $linkData['id'];
            }
            $integration->bridge_metadata = $metadata;
            $integration->save();

            Log::info('Control person cards endorsement KYC link resolved via GET ?endorsement=cards', [
                'customer_id' => $integration->bridge_customer_id,
                        'email' => $controlPersonEmail,
                    ]);

            // Convert to iframe URL
            $iframeUrl = null;
            if ($kycLink) {
                $iframeUrl = $this->bridgeService->convertKycLinkToWidgetUrl($kycLink);
            }

            if (!$kycLink) {
                return response()->json([
                    'success' => false,
                    'message' => 'KYC link not found in response',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'KYC link created successfully',
                'data' => [
                    'kyc_link' => $kycLink,
                    'iframe_kyc_link' => $iframeUrl,
                    'kyc_iframe_url' => $iframeUrl, // Alternative key for compatibility
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Get Control Person KYC link error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get KYC link: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deposit money using Bridge
     */
    public function deposit(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
            ]);

            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            if ($isOrgUser) {
                $organization = $user->organization;
                if (!$organization || !$organization->user) {
                    return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
                }
                $entity = $organization;
                $entityType = Organization::class;
                $orgUser = $organization->user;
            } else {
                $entity = $user;
                $entityType = User::class;
                $orgUser = $user;
            }

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_wallet_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge wallet not initialized. Please initialize first.',
                ], 404);
            }

            // Check KYC/KYB status
            if (!$integration->canTransact()) {
                $requiredType = $isOrgUser ? 'KYB' : 'KYC';
                return response()->json([
                    'success' => false,
                    'message' => "Please complete {$requiredType} verification before making deposits.",
                    'requires_verification' => true,
                    'verification_type' => strtolower($requiredType),
                ], 403);
            }

            $amount = (float) $validated['amount'];

            // Deposits are credited by Bridge (virtual account / wallet history) — never the local ledger.
            $bridgeRead = app(BridgeWalletReadService::class);
            $bridgeBalance = round((float) ($bridgeRead->getBalance($integration) ?? 0), 2);

                return response()->json([
                'success' => false,
                'message' => 'Wallet balance updates when Bridge processes your bank deposit. Use deposit instructions to add funds.',
                'use_deposit_instructions' => true,
                    'data' => [
                    'balance' => $bridgeBalance,
                        'amount' => $amount,
                    'source' => 'bridge',
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Bridge deposit error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to process deposit'], 500);
        }
    }

    /**
     * Send money using Bridge
     */
    public function send(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'recipient_id' => 'required|string',
            ]);

            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            // Determine sender entity (user or organization)
            if ($isOrgUser) {
            $organization = Organization::forAuthUser($user);
            if (!$organization) {
                return response()->json(['success' => false, 'message' => 'Organization not found.'], 404);
            }
                $senderEntity = $organization;
                $senderEntityType = Organization::class;
                $senderUser = $organization->user ?? $user;
            } else {
                $senderEntity = $user;
                $senderEntityType = User::class;
                $senderUser = $user;
            }

            // Get sender Bridge integration
            $senderIntegration = BridgeIntegration::resolveForAuthUser($user);

            if (!$senderIntegration || $senderIntegration->integratable_id !== $senderEntity->id
                || $senderIntegration->integratable_type !== $senderEntityType) {
            $senderIntegration = BridgeIntegration::where('integratable_id', $senderEntity->id)
                ->where('integratable_type', $senderEntityType)
                ->first();
            }

            if (!$senderIntegration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge wallet not initialized. Please initialize first.',
                ], 404);
            }

            $isSandbox = $this->bridgeService->isSandbox();
            $primaryWallet = BridgeWallet::where('bridge_integration_id', $senderIntegration->id)
                ->where('is_primary', true)
                ->first();

            // Resolve custodial Bridge wallets (production) or sandbox VA fallback
            $senderWallet = $this->bridgeService->resolveCustomerBridgeWallet($senderIntegration);
            $senderWalletAddress = null;
            $actualBridgeWalletId = $senderWallet['wallet_id'] ?? null;

            if ($isSandbox && $senderWallet === null) {
                if (empty($actualBridgeWalletId) && $primaryWallet && $primaryWallet->virtual_account_id) {
                    $actualBridgeWalletId = $primaryWallet->virtual_account_id;
                }
                if ($primaryWallet?->wallet_address) {
                    $senderWalletAddress = $primaryWallet->wallet_address;
                } elseif ($primaryWallet?->virtual_account_details) {
                    $vaDetails = is_string($primaryWallet->virtual_account_details)
                        ? json_decode($primaryWallet->virtual_account_details, true)
                        : $primaryWallet->virtual_account_details;
                    $senderWalletAddress = $vaDetails['destination']['address'] ?? null;
                }
            }

            $hasWallet = $senderWallet !== null || ($isSandbox && ! empty($actualBridgeWalletId));

            if (! $hasWallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge wallet not initialized. Please create wallet first.',
                ], 404);
            }

            if (!$senderIntegration->canTransact()) {
                $verificationType = $isOrgUser ? 'kyb' : 'kyc';
                return response()->json([
                    'success' => false,
                    'message' => $isOrgUser
                        ? 'Please complete KYB verification before sending money.'
                        : 'Please complete KYC verification before sending money.',
                    'requires_verification' => true,
                    'verification_type' => $verificationType,
                ], 403);
            }

            try {
                $walletSnapshot = app(BridgeWalletReadService::class)->getWalletSnapshot($senderIntegration);
                if ($walletSnapshot !== null) {
                    $senderWallet = $this->bridgeService->resolveCustomerBridgeWallet($senderIntegration);
                    $actualBridgeWalletId = $senderWallet['wallet_id'] ?? $actualBridgeWalletId;
                }
            } catch (\Throwable $refreshError) {
                Log::warning('Bridge send preflight wallet refresh failed', [
                    'integration_id' => $senderIntegration->id,
                    'error' => $refreshError->getMessage(),
                ]);
            }

            $amount = (float) $validated['amount'];
            $bridgeRead = app(BridgeWalletReadService::class);
            $walletSnapshot = $bridgeRead->getWalletSnapshot($senderIntegration);
            $senderBalance = $walletSnapshot['balance']
                ?? (float) ($senderWallet['balance'] ?? 0);

            // Calculate fee
            $fee = 0;
            $feeRecord = WalletFee::getActiveFee('send');
            if ($feeRecord) {
                $fee = $feeRecord->calculateFee($amount);
            }

            $totalAmount = $amount + $fee;

            if ($senderBalance < $totalAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance. Available: $' . number_format($senderBalance, 2),
                ], 400);
            }

            $bridgeSpendable = $senderWallet !== null
                ? (float) ($senderWallet['balance'] ?? 0)
                : null;

            if ($bridgeSpendable !== null && $amount > $bridgeSpendable) {
                $message = $senderBalance > $bridgeSpendable
                    ? 'Only $' . number_format($bridgeSpendable, 2) . ' is available to send right now. Part of your balance may still be settling in Bridge.'
                    : 'Insufficient funds in your wallet. Available: $' . number_format($bridgeSpendable, 2);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'bridge_wallet_balance' => $bridgeSpendable,
                ], 400);
            }

            // Parse recipient
            $recipientId = $validated['recipient_id'];
            $recipientType = null;
            $recipientDbId = null;

            if (strpos($recipientId, 'user_') === 0) {
                $recipientType = 'user';
                $recipientDbId = (int) str_replace('user_', '', $recipientId);
                $recipient = User::find($recipientDbId);
            } elseif (strpos($recipientId, 'org_') === 0) {
                $recipientType = 'organization';
                $recipientDbId = (int) str_replace('org_', '', $recipientId);
                $recipient = Organization::find($recipientDbId);
            } else {
                return response()->json(['success' => false, 'message' => 'Invalid recipient ID format.'], 400);
            }

            if (!$recipient) {
                return response()->json(['success' => false, 'message' => 'Recipient not found.'], 404);
            }

            $recipientUser = $recipientType === 'user' ? $recipient : $recipient->user;
            if (!$recipientUser) {
                return response()->json(['success' => false, 'message' => 'Recipient user not found.'], 404);
            }

                $recipientIntegration = BridgeIntegration::where('integratable_id', $recipient->id)
                ->where('integratable_type', $recipientType === 'user' ? User::class : Organization::class)
                    ->first();

            // Recipient must have a Bridge wallet to receive funds on-chain
            if (! $recipientIntegration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recipient does not have a wallet set up yet.',
                ], 400);
            }

            $recipientWallet = $this->bridgeService->resolveCustomerBridgeWallet($recipientIntegration);
                $recipientPrimaryWallet = BridgeWallet::where('bridge_integration_id', $recipientIntegration->id)
                    ->where('is_primary', true)
                    ->first();
            $recipientBridgeWalletId = $recipientWallet['wallet_id'] ?? null;
            $recipientWalletAddress = null;

            if ($isSandbox && $recipientWallet === null) {
                if (empty($recipientBridgeWalletId) && $recipientPrimaryWallet?->virtual_account_id) {
                        $recipientBridgeWalletId = $recipientPrimaryWallet->virtual_account_id;
                }
                $recipientWalletAddress = $recipientPrimaryWallet?->wallet_address;
                if (! $recipientWalletAddress && $recipientPrimaryWallet?->virtual_account_details) {
                            $vaDetails = is_string($recipientPrimaryWallet->virtual_account_details)
                                ? json_decode($recipientPrimaryWallet->virtual_account_details, true)
                                : $recipientPrimaryWallet->virtual_account_details;
                    $recipientWalletAddress = $vaDetails['destination']['address'] ?? null;
                }
            }

            $canCreateBridgeTransfer = $senderWallet !== null && $recipientWallet !== null;
            if ($isSandbox && ! $canCreateBridgeTransfer) {
                $canCreateBridgeTransfer = ! empty($actualBridgeWalletId)
                    && (! empty($recipientBridgeWalletId) || ! empty($recipientWalletAddress));
            }

            if (! $canCreateBridgeTransfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Both sender and recipient need active wallets to transfer money.',
                ], 400);
            }

                        $transferResult = $this->bridgeService->createWalletToWalletTransfer(
                            $senderIntegration->bridge_customer_id,
                $actualBridgeWalletId ?? '',
                            $recipientIntegration->bridge_customer_id,
                $recipientBridgeWalletId ?? '',
                            $amount,
                            'USD',
                $senderWalletAddress,
                $recipientWalletAddress
            );

            if (! ($transferResult['success'] ?? false)) {
                $errorMessage = $this->humanizeBridgeTransferError(
                    $transferResult,
                    $bridgeSpendable,
                    $amount,
                );
                Log::warning('Bridge wallet-to-wallet transfer failed', [
                                'sender_customer_id' => $senderIntegration->bridge_customer_id,
                                'recipient_customer_id' => $recipientIntegration->bridge_customer_id,
                                'amount' => $amount,
                                'response' => $transferResult,
                            ]);

                $statusCode = ! empty($transferResult['initiation_required']) ? 422 : 502;

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'initiation_required' => (bool) ($transferResult['initiation_required'] ?? false),
                ], $statusCode);
            }

            $bridgeTransferId = $transferResult['data']['id']
                ?? $transferResult['data']['transfer_id']
                ?? null;

            if (! $bridgeTransferId) {
                Log::error('Bridge transfer created but missing transfer id', [
                    'response' => $transferResult,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Bridge transfer was created but could not be tracked. Please try again.',
                ], 502);
            }

            Log::info('Bridge transfer created for send money', [
                'transfer_id' => $bridgeTransferId,
                'sender_customer_id' => $senderIntegration->bridge_customer_id,
                'recipient_customer_id' => $recipientIntegration->bridge_customer_id,
                    'amount' => $amount,
            ]);

            $senderDisplayName = $senderEntity instanceof Organization
                ? (string) $senderEntity->name
                : (string) $senderUser->name;

            $this->bridgeWalletNotifier->notifyOutgoingTransferCreated(
                $senderIntegration,
                $recipientIntegration,
                is_array($transferResult['data'] ?? null) ? $transferResult['data'] : ['id' => $bridgeTransferId],
                $amount,
                $senderDisplayName,
            );

            $updatedSnapshot = $bridgeRead->getWalletSnapshot($senderIntegration);
            $senderBalanceAfter = $updatedSnapshot['balance'] ?? max(0, $senderBalance - $amount);

                return response()->json([
                    'success' => true,
                'message' => 'Transfer submitted. Funds will arrive when Bridge confirms the transfer.',
                    'data' => [
                    'sender_balance' => $senderBalanceAfter,
                        'amount' => $amount,
                        'fee' => $fee,
                    'bridge_transfer_id' => $bridgeTransferId,
                    'status' => 'pending',
                    'source' => 'bridge',
                    ],
                ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
                throw $e;
        } catch (\Throwable $e) {
            Log::error('Bridge send error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            $message = 'Failed to process transfer. Please try again.';
            $safeError = trim($e->getMessage());
            if ($safeError !== '') {
                $message = 'Failed to process transfer: '.$safeError;
            }

            return response()->json(['success' => false, 'message' => $message], 500);
        }
    }

    /**
     * Handle KYC callback after verification
     * This is called when Bridge redirects user back after KYC completion
     */
    public function kycCallback(Request $request)
    {
        try {
            $linkId = $request->input('link_id') ?? $request->input('id') ?? $request->input('kyc_link_id');
            $status = $request->input('status') ?? $request->input('kyc_status');

            Log::info('KYC Callback received', [
                'link_id' => $linkId,
                'status' => $status,
                'all_params' => $request->all(),
            ]);

            if ($linkId) {
                $integration = BridgeIntegration::where('kyc_link_id', $linkId)->first();
                if ($integration) {
                    $integration->kyc_status = $this->normalizeStatus($status);
                    $integration->save();

                    Log::info('KYC status updated from callback', [
                        'integration_id' => $integration->id,
                        'status' => $integration->kyc_status,
                    ]);
                }
            }

            // Redirect to billing page or dashboard
            if (Auth::check()) {
                return redirect('/settings/billing')->with('success', 'KYC verification status updated!');
            } else {
                return redirect('/login')->with('info', 'Please login to view your verification status');
            }
        } catch (\Exception $e) {
            Log::error('KYC callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (Auth::check()) {
                return redirect('/settings/billing')->with('error', 'Error processing KYC callback');
            } else {
                return redirect('/login');
            }
        }
    }

    /**
     * Handle KYB callback after verification
     * This is called when Bridge redirects user back after KYB completion
     */
    public function kybCallback(Request $request)
    {
        try {
            $linkId = $request->input('link_id') ?? $request->input('id') ?? $request->input('kyb_link_id');
            $status = $request->input('status') ?? $request->input('kyb_status');

            Log::info('KYB Callback received', [
                'link_id' => $linkId,
                'status' => $status,
                'all_params' => $request->all(),
            ]);

            if ($linkId) {
                $integration = BridgeIntegration::where('kyb_link_id', $linkId)->first();
                if ($integration) {
                    $integration->kyb_status = $this->normalizeStatus($status);
                    $integration->save();

                    Log::info('KYB status updated from callback', [
                        'integration_id' => $integration->id,
                        'status' => $integration->kyb_status,
                    ]);
                }
            }

            // Redirect to billing page or dashboard
            if (Auth::check()) {
                return redirect('/settings/billing')->with('success', 'KYB verification status updated!');
            } else {
                return redirect('/login')->with('info', 'Please login to view your verification status');
            }
        } catch (\Exception $e) {
            Log::error('KYB callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (Auth::check()) {
                return redirect('/settings/billing')->with('error', 'Error processing KYB callback');
            } else {
                return redirect('/login');
            }
        }
    }

    /**
     * Get webhook events for a specific webhook
     */
    public function getWebhookEvents(Request $request, string $webhookId)
    {
        try {
            $result = $this->bridgeService->getWebhookEvents($webhookId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to fetch webhook events',
                ], $result['status'] ?? 500);
            }

            return response()->json([
                'success' => true,
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('Get webhook events error', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch webhook events',
            ], 500);
        }
    }

    /**
     * Get a specific webhook event by ID
     */
    public function getWebhookEvent(Request $request, string $webhookId, string $eventId)
    {
        try {
            $result = $this->bridgeService->getWebhookEvent($webhookId, $eventId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to fetch webhook event',
                ], $result['status'] ?? 500);
            }

            return response()->json([
                'success' => true,
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('Get webhook event error', [
                'webhook_id' => $webhookId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch webhook event',
            ], 500);
        }
    }

    /**
     * Link an existing Bridge customer to the local entity using email lookup.
     */
    private function linkBridgeCustomerByEmail(
        object $entity,
        string $entityType,
        User $user,
        bool $isOrgUser,
        ?BridgeIntegration $integration = null,
    ): ?BridgeIntegration {
        $emails = $this->bridgeLookupEmailsForEntity($entity, $user);
        if ($emails === []) {
            return null;
        }

        try {
            $customer = $this->findBridgeCustomerForEmails($emails, $isOrgUser);
            if ($customer === null || empty($customer['id'])) {
                Log::warning('No Bridge customer matched local emails', [
                    'integratable_id' => $entity->id,
                    'integratable_type' => $entityType,
                    'emails' => $emails,
                ]);

                return null;
            }

            $customerId = (string) $customer['id'];
            $matchedEmail = strtolower(trim((string) ($customer['email'] ?? $emails[0])));

            $verifyResult = $this->bridgeService->getCustomer($customerId);
            if (! ($verifyResult['success'] ?? false)) {
                if ($this->bridgeService->isCustomerInaccessibleError($verifyResult)) {
                    Log::warning('Bridge email lookup matched a customer that is not accessible via API', [
                        'customer_id' => $customerId,
                        'emails' => $emails,
                    ]);

                    return null;
                }
            } else {
                $customer = $verifyResult['data'];
            }

            $integration = BridgeIntegration::resolveForEntity(
                $entity->id,
                $entityType,
                $customerId,
                $integration?->kyc_link_id,
            );

            $integration->bridge_customer_id = $customerId;
            $metadata = $integration->bridge_metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = is_string($metadata) ? (json_decode($metadata, true) ?? []) : [];
            }
            $metadata['type'] = $isOrgUser ? 'business' : 'individual';
            $metadata['linked_by_email'] = $matchedEmail;
            $metadata['linked_at'] = now()->toIso8601String();
            $metadata['bridge_list_customer'] = $customer;
            $integration->bridge_metadata = $metadata;

            // Apply list/search payload first — works even if GET /customers/{id} fails.
            $this->applyBridgeCustomerSnapshot($integration, $customer, $isOrgUser);
            $integration->save();

            Log::info('Linked existing Bridge customer by email', [
                'integratable_id' => $entity->id,
                'integratable_type' => $entityType,
                'customer_id' => $customerId,
                'email' => $matchedEmail,
                'customer_status' => $customer['status'] ?? null,
                'has_accepted_terms_of_service' => $customer['has_accepted_terms_of_service'] ?? null,
                'kyb_status' => $integration->kyb_status,
                'kyc_status' => $integration->kyc_status,
                'tos_status' => $integration->tos_status,
            ]);

            $this->syncIntegrationFromBridgeApi($integration, $isOrgUser);

            $this->refreshCardsEndorsementKycLink($integration, $isOrgUser);

            return $integration->fresh();
        } catch (\Exception $e) {
            Log::warning('Failed to link Bridge customer by email', [
                'emails' => $emails,
                'integratable_id' => $entity->id,
                'integratable_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function bridgeLookupEmailsForEntity(object $entity, User $user): array
    {
        $candidates = [
            (string) ($entity->email ?? ''),
            (string) ($user->email ?? ''),
        ];

        if (isset($entity->platform_email)) {
            $candidates[] = (string) $entity->platform_email;
        }

        $emails = [];
        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim($candidate));
            if ($normalized !== '' && ! in_array($normalized, $emails, true)) {
                $emails[] = $normalized;
            }
        }

        return $emails;
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, mixed>|null
     */
    private function findBridgeCustomerForEmails(array $emails, bool $isOrgUser): ?array
    {
        foreach ($emails as $email) {
            $result = $this->bridgeService->getCustomersByEmail($email);
            if ($result['success']) {
                $customers = $this->normalizeBridgeCustomerList($result['data'] ?? []);
                $customer = $this->pickBestBridgeCustomer($customers, $isOrgUser, $email);
                if ($customer !== null) {
                    return $customer;
                }
            }
        }

        $allResult = $this->bridgeService->getCustomers();
        if (! $allResult['success']) {
            return null;
        }

        $allCustomers = $this->normalizeBridgeCustomerList($allResult['data'] ?? []);
        foreach ($emails as $email) {
            $customer = $this->pickBestBridgeCustomer($allCustomers, $isOrgUser, $email);
            if ($customer !== null) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * Map a Bridge customer payload (list or detail) into bridge_integrations status columns.
     *
     * @param  array<string, mixed>  $customer
     */
    private function applyBridgeCustomerSnapshot(
        BridgeIntegration $integration,
        array $customer,
        bool $isOrgUser,
    ): void {
        $customerStatus = strtolower((string) ($customer['status'] ?? ''));
        $hasApprovedEndorsements = false;

        foreach ($customer['endorsements'] ?? [] as $endorsement) {
            if (strtolower((string) ($endorsement['status'] ?? '')) === 'approved') {
                $hasApprovedEndorsements = true;
                break;
            }
        }

        $hasAcceptedTos = (bool) ($customer['has_accepted_terms_of_service'] ?? false);
        if (! $hasAcceptedTos) {
            foreach ($customer['endorsements'] ?? [] as $endorsement) {
                foreach ($endorsement['requirements']['complete'] ?? [] as $item) {
                    if (is_string($item) && str_contains($item, 'terms_of_service')) {
                        $hasAcceptedTos = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasAcceptedTos) {
            $integration->tos_status = BridgeIntegration::normalizeTosStatus('approved');
        }

        if ($isOrgUser) {
            $bridgeKybStatus = strtolower((string) ($customer['kyb_status'] ?? ''));
            if ($bridgeKybStatus === 'approved' || ($customerStatus === 'active' && $hasApprovedEndorsements)) {
                $integration->kyb_status = 'approved';
            } elseif ($bridgeKybStatus !== '') {
                $integration->kyb_status = $bridgeKybStatus;
            }

            $bridgeKycStatus = strtolower((string) ($customer['kyc_status'] ?? ''));
            if (
                in_array($bridgeKycStatus, ['approved', 'verified'], true)
                || $integration->kyb_status === 'approved'
                || ($customerStatus === 'active' && $hasApprovedEndorsements)
            ) {
                $integration->kyc_status = 'approved';
            } elseif ($bridgeKycStatus !== '') {
                $integration->kyc_status = $bridgeKycStatus;
            }
        } else {
            // Individuals: wallet KYC is approved when base OR another fiat endorsement (e.g. SEPA)
            // is approved, or the Bridge customer is Active. Do NOT copy a region-blocked base
            // status (incomplete/rejected) over an otherwise wallet-ready customer.
            // @see https://apidocs.bridge.xyz/platform/customers/customers/endorsements
            $endorsements = $this->bridgeService->getEndorsementsSummary($customer);
            $baseInfo = $this->bridgeService->getBaseEndorsementInfo($customer);
            $bridgeKycStatus = strtolower((string) ($customer['kyc_status'] ?? ''));
            $walletReady = ($endorsements['custodial_wallet_ok'] ?? false)
                || ($baseInfo['approved'] ?? false)
                || ($endorsements['sepa_approved'] ?? false)
                || in_array($bridgeKycStatus, ['approved', 'verified'], true)
                || ($customerStatus === 'active' && $hasApprovedEndorsements);

            if ($walletReady) {
                $integration->kyc_status = 'approved';
            } elseif ($baseInfo['approved'] ?? false) {
                $integration->kyc_status = 'approved';
            } elseif (in_array($bridgeKycStatus, ['approved', 'verified', 'under_review', 'incomplete', 'not_started', 'rejected', 'paused', 'offboarded'], true)) {
                // Prefer Bridge top-level kyc_status over a region-blocked base endorsement status.
                $integration->kyc_status = $bridgeKycStatus === 'verified' ? 'approved' : $bridgeKycStatus;
            } elseif (($baseInfo['exists'] ?? false) && ($baseInfo['status'] ?? '') !== '') {
                $baseStatus = strtolower((string) ($baseInfo['status'] ?? ''));
                // Region-blocked base stays incomplete — never treat as terminal rejection for wallet UX.
                if (($endorsements['region_blocks_us_ach'] ?? false) && $customerStatus === 'active') {
                    $integration->kyc_status = 'approved';
                } else {
                    $integration->kyc_status = $baseStatus === 'approved' ? 'approved' : $baseStatus;
                }
            } elseif (
                in_array($customerStatus, ['not_started', 'incomplete'], true)
                && $integration->kyc_status === 'approved'
            ) {
                $integration->kyc_status = $customerStatus;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeBridgeCustomerList(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return array_values($payload['data']);
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            return array_values($payload);
        }

        if (isset($payload['id'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array<string, mixed>|null
     */
    private function pickBestBridgeCustomer(array $customers, bool $isOrgUser, string $email): ?array
    {
        $expectedType = $isOrgUser ? 'business' : 'individual';
        $email = strtolower($email);

        $matches = array_values(array_filter($customers, function (array $customer) use ($expectedType, $email): bool {
            $customerEmail = strtolower(trim((string) ($customer['email'] ?? '')));
            $customerType = strtolower((string) ($customer['type'] ?? ''));

            return $customerEmail === $email && ($customerType === $expectedType || $customerType === '');
        }));

        if ($matches === []) {
            $matches = array_values(array_filter($customers, function (array $customer) use ($email): bool {
                return strtolower(trim((string) ($customer['email'] ?? ''))) === $email;
            }));
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $a, array $b): int {
            $score = fn (array $customer): int => (
                (strtolower((string) ($customer['status'] ?? '')) === 'active' ? 100 : 0)
                + $this->bridgeCustomerEndorsementScore($customer)
            );

            return $score($b) <=> $score($a);
        });

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function bridgeCustomerEndorsementScore(array $customer): int
    {
        $score = 0;

        foreach ($customer['endorsements'] ?? [] as $endorsement) {
            if (strtolower((string) ($endorsement['status'] ?? '')) === 'approved') {
                $score += 10;
            }
        }

        if (! empty($customer['has_accepted_terms_of_service'])) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Whether local integration reflects an approved Bridge account (KYC for individuals, KYB+KYC for orgs).
     */
    private function integrationVerificationApproved(BridgeIntegration $integration, bool $isOrgUser, ?array $customer = null): bool
    {
        if (empty($integration->bridge_customer_id)) {
            return false;
        }

        if ($customer === null) {
            $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
            $customer = ($customerResult['success'] ?? false) ? ($customerResult['data'] ?? []) : [];
        }

        $baseInfo = $this->bridgeService->getBaseEndorsementInfo($customer);
        $endorsements = $this->bridgeService->getEndorsementsSummary($customer);
        $customerActive = strtolower((string) ($customer['status'] ?? '')) === 'active';
        // Custodial wallet OK when customer is Active or any fiat endorsement (base/SEPA/…) is approved.
        // US "base" may be region-rejected (e.g. Bangladesh) while SEPA + wallets remain available.
        $walletEndorsementOk = ($endorsements['custodial_wallet_ok'] ?? false)
            || ($baseInfo['approved'] ?? false)
            || $customerActive;

        // Wallet access: any approved transacting endorsement or Active customer — cards is card-issuance only.
        if ($isOrgUser) {
            $kybApproved = $integration->kyb_status === 'approved'
                || strtolower((string) ($customer['kyb_status'] ?? '')) === 'approved';
            $kycApproved = $integration->kyc_status === 'approved'
                || strtolower((string) ($customer['kyc_status'] ?? '')) === 'approved';

            if ($walletEndorsementOk) {
                return $kybApproved || $kycApproved || $customerActive;
            }

            return ($kybApproved && $kycApproved) || $customerActive;
        }

        if ($walletEndorsementOk) {
            return true;
        }

        $kycApproved = $integration->kyc_status === 'approved'
            || strtolower((string) ($customer['kyc_status'] ?? '')) === 'approved';

        return $kycApproved || $customerActive;
    }

    /**
     * Fetch a fresh hosted Persona URL via GET /customers/{id}/kyc_link (Bridge docs: do not reuse stored URLs).
     *
     * @see https://apidocs.bridge.xyz/api-reference/customers/retrieve-a-hosted-kyc-link-for-an-existing-customer
     */
    private function refreshHostedVerificationLinkForIntegration(
        BridgeIntegration $integration,
        bool $isOrgUser,
        ?string $redirectUri = null,
    ): ?string {
        if (empty($integration->bridge_customer_id)) {
            return null;
        }

        $endorsement = $this->bridgeService->isCardsEnabledOnDeveloperAccount() ? 'cards' : null;
        $result = $this->bridgeService->refreshHostedVerificationLink(
            (string) $integration->bridge_customer_id,
            $endorsement,
            $redirectUri,
        );

        if (($result['error_code'] ?? '') === 'bridge_customer_stale'
            || $this->bridgeService->isCustomerInaccessibleError($result)) {
            $this->bridgeService->clearStaleCustomerReference(
                $integration,
                (string) $integration->bridge_customer_id,
                'hosted_kyc_link_inaccessible',
            );

            return null;
        }

        if (! ($result['success'] ?? false) || empty($result['url'])) {
            return null;
        }

        $url = (string) $result['url'];
        if ($isOrgUser) {
            $integration->kyb_link_url = $url;
        } else {
            $integration->kyc_link_url = $url;
        }
        $integration->save();

        return $url;
    }

    /**
     * Resolve and persist GET ?endorsement=cards URL for individual KYC or business KYB.
     */
    private function refreshCardsEndorsementKycLink(
        BridgeIntegration $integration,
        bool $isOrgUser,
        ?string $redirectUri = null,
    ): void {
        if (empty($integration->bridge_customer_id)) {
            return;
        }

        $kycLinkId = $isOrgUser
            ? ($integration->kyb_link_id ?? $integration->kyc_link_id)
            : $integration->kyc_link_id;

        $integration->loadMissing('integratable');
        $preferredPhone = $this->resolveIntegratablePhoneForCards($integration);

        $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
        if ($this->bridgeService->isCustomerInaccessibleError($customerResult)) {
            $this->bridgeService->clearStaleCustomerReference(
                $integration,
                (string) $integration->bridge_customer_id,
                'cards_kyc_link_customer_inaccessible',
            );

            return;
        }

        $baseApproved = $this->bridgeService->getBaseEndorsementInfo($customerResult['data'] ?? [])['approved'] ?? false;
        $effectiveKycLinkId = $baseApproved ? null : $kycLinkId;

        $result = $this->bridgeService->resolveCardsEndorsementKycLink(
            $integration->bridge_customer_id,
            $redirectUri,
            $effectiveKycLinkId,
            $preferredPhone,
            false,
        );

        if ($result['cards_already_approved'] ?? false) {
            return;
        }

        if (! ($result['success'] ?? false) || empty($result['url'])) {
            Log::warning('Could not refresh cards endorsement KYC/KYB link', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'is_org_user' => $isOrgUser,
                'error' => $result['error'] ?? null,
                'source' => $result['source'] ?? null,
            ]);

            return;
        }

        $linkData = $result['data'] ?? [];
        $linkId = $linkData['id'] ?? null;

        if ($isOrgUser) {
            $integration->kyb_link_url = $result['url'];
            if ($linkId) {
                $integration->kyb_link_id = $linkId;
            }

            $metadata = $integration->bridge_metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = is_string($metadata) ? (json_decode($metadata, true) ?? []) : [];
            }
            $metadata['control_person_kyc_link'] = $result['url'];
            $integration->bridge_metadata = $metadata;
        } else {
            $integration->kyc_link_url = $result['url'];
            if ($linkId) {
                $integration->kyc_link_id = $linkId;
            }
        }

        $integration->save();

        Log::info('Stored cards endorsement KYC/KYB link from GET ?endorsement=cards', [
            'integration_id' => $integration->id,
            'customer_id' => $integration->bridge_customer_id,
            'is_org_user' => $isOrgUser,
            'source' => $result['source'] ?? 'customer_kyc_link_cards',
        ]);
    }

    /**
     * Pull customer KYC/KYB/TOS/wallet state from Bridge into bridge_integrations.
     * Used after database cleanup when Bridge still has the customer.
     */
    private function syncIntegrationFromBridgeApi(BridgeIntegration $integration, bool $isOrgUser): ?array
    {
        if (empty($integration->bridge_customer_id)) {
            return null;
        }

        try {
            $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);
            if (! $customerResult['success'] || empty($customerResult['data'])) {
                if ($this->bridgeService->isCustomerInaccessibleError($customerResult)) {
                    $this->bridgeService->clearStaleCustomerReference(
                        $integration,
                        (string) $integration->bridge_customer_id,
                        'sync_customer_inaccessible',
                    );
                }

                return null;
            }

            $customer = $customerResult['data'];
            $before = [
                'kyb_status' => $integration->kyb_status,
                'kyc_status' => $integration->kyc_status,
                'tos_status' => $integration->tos_status,
            ];

            $this->applyBridgeCustomerSnapshot($integration, $customer, $isOrgUser);

            $statusChanged = $before['kyb_status'] !== $integration->kyb_status
                || $before['kyc_status'] !== $integration->kyc_status
                || $before['tos_status'] !== $integration->tos_status;

            $metadata = $integration->bridge_metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = is_string($metadata) ? (json_decode($metadata, true) ?? []) : [];
            }
            $metadata['bridge_sync_at'] = now()->toIso8601String();
            $metadata['bridge_customer_status'] = $customer['status'] ?? null;
            if (! empty($customer['endorsements'])) {
                $metadata['endorsements'] = $customer['endorsements'];
            }
            $integration->bridge_metadata = $metadata;
            $statusChanged = true;

            if ($statusChanged) {
                $integration->save();
            }

            $isApproved = $this->integrationVerificationApproved($integration, $isOrgUser, $customer);
            $primaryWallet = $integration->primaryWallet;
            $hasWallet = ! empty($integration->bridge_wallet_id)
                || ($primaryWallet !== null && ! empty($primaryWallet->bridge_wallet_id));

            if ($isApproved && ! $hasWallet) {
                Log::info('Bridge sync: approved customer missing local wallet — restoring from Bridge', [
                    'integration_id' => $integration->id,
                    'customer_id' => $integration->bridge_customer_id,
                ]);

                $webhookController = app(BridgeWebhookController::class);
                $webhookController->createWalletVirtualAccountAndCardAccount(
                    $integration->fresh(),
                    $integration->bridge_customer_id
                );
            }

            return $customer;
        } catch (\Exception $e) {
            Log::warning('Failed to sync Bridge customer into local integration', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $transferResult
     */
    private function humanizeBridgeTransferError(array $transferResult, ?float $bridgeSpendable, float $amount): string
    {
        $raw = trim((string) ($transferResult['error'] ?? ''));
        $lower = strtolower($raw);

        if (
            str_contains($lower, 'higher than the balance')
            || str_contains($lower, 'insufficient')
            || ($transferResult['error_code'] ?? null) === 'INSUFFICIENT_BRIDGE_WALLET_BALANCE'
        ) {
            if ($bridgeSpendable !== null) {
                return 'Only $' . number_format($bridgeSpendable, 2) . ' is available in your Bridge wallet right now. You tried to send $' . number_format($amount, 2) . '.';
            }

            return 'Insufficient funds in your Bridge wallet. Some deposits may still be settling.';
        }

        if ($raw !== '') {
            return $raw;
        }

        return 'Bridge transfer could not be created. Please try again.';
    }

    /**
     * Normalize status value
     */
    private function normalizeStatus(?string $status): string
    {
        if (!$status) {
            return 'pending';
        }

        $status = strtolower($status);
        $statusMap = [
            'approved' => 'approved',
            'approve' => 'approved',
            'verified' => 'approved',
            'complete' => 'approved',
            'completed' => 'approved',
            'pending' => 'pending',
            'in_progress' => 'pending',
            'processing' => 'pending',
            'rejected' => 'rejected',
            'reject' => 'rejected',
            'denied' => 'rejected',
            'failed' => 'rejected',
            'not_started' => 'not_started',
            'notstarted' => 'not_started',
            'none' => 'not_started',
        ];

        return $statusMap[$status] ?? 'pending';
    }

    /**
     * Get business details from Bridge
     */
    public function getBusinessDetails(Request $request)
    {
        try {
            $user = Auth::user();
            $organization = $user->organization;

            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not found',
                ], 404);
            }

            $integration = BridgeIntegration::where('integratable_id', $organization->id)
                ->where('integratable_type', Organization::class)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge customer not found',
                ], 404);
            }

            // Get customer details
            $customerResult = $this->bridgeService->getCustomer($integration->bridge_customer_id);

            if (!$customerResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $customerResult['error'] ?? 'Failed to get customer',
                ], 500);
            }

            // Get associated persons (beneficial owners)
            $associatedPersonsResult = $this->bridgeService->getAssociatedPersons($integration->bridge_customer_id);

            $customer = $customerResult['data'];

            return response()->json([
                'success' => true,
                'data' => [
                    // Basic Info
                    'id' => $customer['id'] ?? null,
                    'type' => $customer['type'] ?? null,
                    'email' => $customer['email'] ?? null,
                    'business_legal_name' => $customer['business_legal_name'] ?? null,
                    'business_name' => $customer['business_name'] ?? $customer['first_name'] ?? null,
                    'business_type' => $customer['business_type'] ?? null,
                    'business_industry' => $customer['business_industry'] ?? null,
                    'business_description' => $customer['business_description'] ?? null,
                    'primary_website' => $customer['primary_website'] ?? $customer['website'] ?? null,
                    
                    // Status
                    'kyc_status' => $customer['kyc_status'] ?? null,
                    'kyb_status' => $customer['kyb_status'] ?? null,
                    'status' => $customer['status'] ?? null,
                    
                    // Address
                    'registered_address' => $customer['registered_address'] ?? $customer['business_address'] ?? null,
                    
                    // Endorsements (ToS status, requirements)
                    'endorsements' => $customer['endorsements'] ?? [],
                    
                    // Requirements
                    'requirements_due' => $customer['requirements_due'] ?? [],
                    
                    // Associated Persons (Beneficial Owners)
                    'associated_persons' => $associatedPersonsResult['data'] ?? ($customer['associated_persons'] ?? []),
                    'ultimate_beneficial_owners' => $customer['ultimate_beneficial_owners'] ?? [],
                    
                    // Timestamps
                    'created_at' => $customer['created_at'] ?? null,
                    'updated_at' => $customer['updated_at'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get business details error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get business details',
            ], 500);
        }
    }

    /**
     * Convert file path to base64 data URL for Bridge API
     * 
     * @param string|null $filePath Storage path relative to storage/app/public
     * @return string|null Base64 data URL or null if file doesn't exist
     */
    protected function filePathToBase64(?string $filePath): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        try {
            $fullPath = storage_path('app/public/' . $filePath);
            if (!file_exists($fullPath)) {
                Log::warning('Image file not found for base64 conversion', ['path' => $fullPath]);
                return null;
            }

            $imageData = file_get_contents($fullPath);
            if ($imageData === false) {
                Log::error('Failed to read image file', ['path' => $fullPath]);
                return null;
            }

            $mimeType = $this->getMimeTypeFromPath($filePath);
            return "data:{$mimeType};base64," . base64_encode($imageData);
        } catch (\Exception $e) {
            Log::error('Failed to convert file to base64', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Save base64 image to storage and return the storage path
     * 
     * @param string $base64Data Base64 encoded image data (without data:image/... prefix)
     * @param string $directory Directory within storage/app/public
     * @param string $filename Optional filename, will generate if not provided
     * @return string|null Storage path relative to storage/app/public, or null on failure
     */
    protected function saveBase64Image(string $base64Data, string $directory = 'bridge/documents', ?string $filename = null): ?string
    {
        try {
            if (empty($base64Data)) {
                return null;
            }

            // Detect file type from base64 data URL
            $fileExtension = 'jpg'; // default
            $mimeType = null;
            
            // Check if it's a data URL with MIME type
            if (preg_match('/^data:([^;]+);base64,/', $base64Data, $matches)) {
                $mimeType = $matches[1];
                
                // Map MIME types to file extensions
                $mimeToExtension = [
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'application/pdf' => 'pdf',
                    'image/pdf' => 'pdf', // Some systems use this
                ];
                
                if (isset($mimeToExtension[$mimeType])) {
                    $fileExtension = $mimeToExtension[$mimeType];
                } elseif (strpos($mimeType, 'image/') === 0) {
                    // Generic image type, try to extract from MIME
                    $parts = explode('/', $mimeType);
                    if (count($parts) > 1) {
                        $fileExtension = $parts[1];
                    }
                } elseif (strpos($mimeType, 'pdf') !== false) {
                    $fileExtension = 'pdf';
                }
            }
            
            // Remove data URL prefix if present
            $base64Data = preg_replace('/^data:[^;]+;base64,/', '', $base64Data);
            
            // Decode base64
            $imageData = base64_decode($base64Data, true);
            if ($imageData === false) {
                Log::error('Failed to decode base64 image data');
                return null;
            }

            // Generate filename if not provided, using detected extension
            if (!$filename) {
                $filename = uniqid('doc_', true) . '.' . $fileExtension;
            } else {
                // If filename provided but doesn't have extension, add detected one
                if (!preg_match('/\.(jpg|jpeg|png|gif|webp|pdf)$/i', $filename)) {
                    $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $fileExtension;
                }
            }

            // Ensure directory exists
            $fullPath = $directory . '/' . $filename;
            Storage::disk('public')->makeDirectory($directory);

            // Save file
            Storage::disk('public')->put($fullPath, $imageData);

            Log::info('Base64 file saved to storage', [
                'path' => $fullPath,
                'mime_type' => $mimeType,
                'extension' => $fileExtension,
                'size' => strlen($imageData),
            ]);

            return $fullPath;
        } catch (\Exception $e) {
            Log::error('Failed to save base64 image', [
                'error' => $e->getMessage(),
                'directory' => $directory,
            ]);
            return null;
        }
    }

    /**
     * Get MIME type from a stored file path (by extension).
     */
    protected function getMimeTypeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Save KYC/KYB submission data to database and upload documents
     * 
     * @param BridgeIntegration $integration
     * @param array $validated Validated form data
     * @param array $customerData Data sent to Bridge API
     * @param array $responseData Response from Bridge API
     * @param bool $isOrgUser Whether this is an organization user
     */
    protected function saveKycKybSubmission(
        BridgeIntegration $integration,
        array $validated,
        array $customerData,
        array $responseData,
        bool $isOrgUser,
        bool $sentToBridge = true
    ): void {
        try {
            // Upload ID images to storage (only if not already saved)
            $idFrontImagePath = null;
            $idBackImagePath = null;

            // Check if images are already saved (from direct mode submission)
            if ($isOrgUser) {
                // Business (KYB) - check if paths are already provided
                if (!empty($validated['control_person']['id_front_image_path'])) {
                    $idFrontImagePath = $validated['control_person']['id_front_image_path'];
                } elseif (!empty($validated['control_person']['id_front_image'])) {
                    // Save from base64 if path not provided
                    $fileIdentifier = $integration->bridge_customer_id ?? ('integration_' . $integration->id);
                    $idFrontImagePath = $this->saveBase64Image(
                        $validated['control_person']['id_front_image'],
                        'bridge/kyb/documents',
                        'id_front_' . $fileIdentifier . '_' . time()
                    );
                }
                
                if (!empty($validated['control_person']['id_back_image_path'])) {
                    $idBackImagePath = $validated['control_person']['id_back_image_path'];
                } elseif (!empty($validated['control_person']['id_back_image'])) {
                    // Save from base64 if path not provided
                    $fileIdentifier = $integration->bridge_customer_id ?? ('integration_' . $integration->id);
                    $idBackImagePath = $this->saveBase64Image(
                        $validated['control_person']['id_back_image'],
                        'bridge/kyb/documents',
                        'id_back_' . $fileIdentifier . '_' . time()
                    );
                }
            } else {
                // Individual (KYC) - check if paths are already provided
                if (!empty($validated['id_front_image_path'])) {
                    $idFrontImagePath = $validated['id_front_image_path'];
                } elseif (!empty($validated['id_front_image'])) {
                    // Save from base64 if path not provided
                    $fileIdentifier = $integration->bridge_customer_id ?? ('integration_' . $integration->id);
                    $idFrontImagePath = $this->saveBase64Image(
                        $validated['id_front_image'],
                        'bridge/kyc/documents',
                        'id_front_' . $fileIdentifier . '_' . time()
                    );
                }
                
                if (!empty($validated['id_back_image_path'])) {
                    $idBackImagePath = $validated['id_back_image_path'];
                } elseif (!empty($validated['id_back_image'])) {
                    // Save from base64 if path not provided
                    $fileIdentifier = $integration->bridge_customer_id ?? ('integration_' . $integration->id);
                    $idBackImagePath = $this->saveBase64Image(
                        $validated['id_back_image'],
                        'bridge/kyc/documents',
                        'id_back_' . $fileIdentifier . '_' . time()
                    );
                }
            }

            // Prepare submission data (simplified - only essential fields)
            // Bridge KYC/KYB statuses: not_started, incomplete, under_review, awaiting_questionnaire, awaiting_ubo, approved, rejected, paused, offboarded
            $submissionData = [
                'bridge_integration_id' => $integration->id,
                'type' => $isOrgUser ? 'kyb' : 'kyc',
                'submission_status' => $sentToBridge ? 'under_review' : 'not_started',
                'ein' => $validated['ein'] ?? null,
                'bridge_customer_id' => $integration->bridge_customer_id,
                'bridge_response' => $sentToBridge ? $responseData : null,
                'submission_data' => [], // Store only metadata, not business/control person data
            ];

            // Add business-specific metadata to submission_data (not as columns)
            if ($isOrgUser) {
                // Store Standard KYB Requirements in submission_data
                $submissionData['submission_data'] = [
                    'entity_type' => $validated['entity_type'] ?? null,
                    'dao_status' => $validated['dao_status'] ?? false,
                    'business_description' => $validated['business_description'] ?? null,
                    'primary_website' => $validated['primary_website'] ?? null,
                    'business_industry' => $validated['business_industry'] ?? null,
                    'source_of_funds' => $validated['source_of_funds'] ?? null,
                    'annual_revenue' => $validated['annual_revenue'] ?? null,
                    'transaction_volume' => $validated['transaction_volume'] ?? null,
                    'account_purpose' => $validated['account_purpose'] ?? null,
                    'high_risk_activities' => $validated['high_risk_activities'] ?? null,
                    'high_risk_geographies' => $validated['high_risk_geographies'] ?? null,
                    'physical_address' => $validated['physical_address'] ?? null,
                ];
            } else {
                // Individual (KYC) - store in submission_data
                // Remove base64 image data from identifying_information before storing
                $identifyingInfo = $customerData['identifying_information'] ?? [];
                $identifyingInfoWithoutImages = [];
                if (is_array($identifyingInfo)) {
                    foreach ($identifyingInfo as $info) {
                        $infoCopy = $info;
                        // Remove base64 image data
                        unset($infoCopy['image_front']);
                        unset($infoCopy['image_back']);
                        $identifyingInfoWithoutImages[] = $infoCopy;
                    }
                }

                $submissionData['submission_data'] = [
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name' => $validated['last_name'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'birth_date' => $validated['birth_date'] ?? null,
                    'signed_agreement_id' => $customerData['signed_agreement_id'] ?? null, // Required for Terms of Service acceptance
                    'residential_address' => $customerData['residential_address'] ?? null,
                    'identifying_information' => $identifyingInfoWithoutImages, // Store without base64 images
                ];
            }

            // Find existing submission for control person step (KYB)
            $existingSubmission = null;
            if ($isOrgUser) {
                $existingSubmission = BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                    ->where('type', 'kyb')
                    ->latest()
                    ->first();
            }

            if ($existingSubmission) {
                // Update existing submission
                $existingSubmissionData = $existingSubmission->submission_data ?? [];
                if (is_string($existingSubmissionData)) {
                    $existingSubmissionData = json_decode($existingSubmissionData, true) ?? [];
                }
                
                // Merge submission_data
                if (isset($submissionData['submission_data']) && is_array($submissionData['submission_data'])) {
                    $submissionData['submission_data'] = array_merge($existingSubmissionData, $submissionData['submission_data']);
                } else {
                    $submissionData['submission_data'] = $existingSubmissionData;
                }
                
                // Clear requested_fields and refill_message after successful submission
                unset($submissionData['submission_data']['requested_fields']);
                unset($submissionData['submission_data']['refill_message']);
                unset($submissionData['submission_data']['refill_requested_at']);
                unset($submissionData['submission_data']['refill_requested_by']);
                
                // Update existing submission (only essential fields)
                $existingSubmission->fill([
                    'submission_status' => $submissionData['submission_status'] ?? $existingSubmission->submission_status,
                    'ein' => $submissionData['ein'] ?? $existingSubmission->ein,
                    'bridge_customer_id' => $submissionData['bridge_customer_id'] ?? $existingSubmission->bridge_customer_id,
                    'bridge_response' => $submissionData['bridge_response'] ?? $existingSubmission->bridge_response,
                    'submission_data' => $submissionData['submission_data'],
                ]);
                $existingSubmission->save();
                
                // Save or update ID documents in separate table (preserve approved documents)
                if ($idFrontImagePath) {
                    VerificationDocument::updateOrCreate(
                        [
                            'bridge_kyc_kyb_submission_id' => $existingSubmission->id,
                            'document_type' => 'id_front',
                        ],
                        [
                            'file_path' => $idFrontImagePath,
                            'status' => 'pending', // Reset to pending when re-uploaded
                            'rejection_reason' => null,
                            'rejected_at' => null,
                            'rejected_by' => null,
                        ]
                    );
                }
                
                if ($idBackImagePath) {
                    VerificationDocument::updateOrCreate(
                        [
                            'bridge_kyc_kyb_submission_id' => $existingSubmission->id,
                            'document_type' => 'id_back',
                        ],
                        [
                            'file_path' => $idBackImagePath,
                            'status' => 'pending', // Reset to pending when re-uploaded
                            'rejection_reason' => null,
                            'rejected_at' => null,
                            'rejected_by' => null,
                        ]
                    );
                }
                
                // Save or update control person to control_persons table
                if ($isOrgUser && !empty($validated['control_person'])) {
                    $controlPersonData = $validated['control_person'];
                    \App\Models\ControlPerson::updateOrCreate(
                        [
                            'bridge_kyc_kyb_submission_id' => $existingSubmission->id,
                        ],
                        [
                            'first_name' => $controlPersonData['first_name'] ?? null,
                            'last_name' => $controlPersonData['last_name'] ?? null,
                            'email' => $controlPersonData['email'] ?? null,
                            'birth_date' => $controlPersonData['birth_date'] ?? null,
                            'ssn' => $controlPersonData['ssn'] ?? null,
                            'title' => $controlPersonData['title'] ?? null,
                            'ownership_percentage' => $controlPersonData['ownership_percentage'] ?? null,
                            'street_line_1' => $controlPersonData['street_line_1'] ?? null,
                            'city' => $controlPersonData['city'] ?? null,
                            'state' => $controlPersonData['state'] ?? null,
                            'postal_code' => $controlPersonData['postal_code'] ?? null,
                            'country' => $controlPersonData['country'] ?? 'USA',
                            'id_type' => $controlPersonData['id_type'] ?? null,
                            'id_number' => $controlPersonData['id_number'] ?? null,
                            'bridge_associated_person_id' => $controlPersonData['bridge_associated_person_id'] ?? null,
                        ]
                    );
                    
                    // Save ID images to verification_documents (linked to control person via submission)
                    if ($idFrontImagePath) {
                        VerificationDocument::updateOrCreate(
                            [
                                'bridge_kyc_kyb_submission_id' => $existingSubmission->id,
                                'document_type' => 'id_front',
                            ],
                            [
                                'file_path' => $idFrontImagePath,
                                'status' => 'pending',
                            ]
                        );
                    }
                    if ($idBackImagePath) {
                        VerificationDocument::updateOrCreate(
                            [
                                'bridge_kyc_kyb_submission_id' => $existingSubmission->id,
                                'document_type' => 'id_back',
                            ],
                            [
                                'file_path' => $idBackImagePath,
                                'status' => 'pending',
                            ]
                        );
                    }
                }
            } else {
                // Create new submission record (simplified)
                $submission = BridgeKycKybSubmission::create($submissionData);
                
                // Save ID documents in separate table
                if ($idFrontImagePath) {
                    VerificationDocument::create([
                        'bridge_kyc_kyb_submission_id' => $submission->id,
                        'document_type' => 'id_front',
                        'file_path' => $idFrontImagePath,
                        'status' => 'pending',
                    ]);
                }
                
                if ($idBackImagePath) {
                    VerificationDocument::create([
                        'bridge_kyc_kyb_submission_id' => $submission->id,
                        'document_type' => 'id_back',
                        'file_path' => $idBackImagePath,
                        'status' => 'pending',
                    ]);
                }
                
                // Save control person to control_persons table (for KYB)
                if ($isOrgUser && !empty($validated['control_person'])) {
                    $controlPersonData = $validated['control_person'];
                    $controlPerson = \App\Models\ControlPerson::create([
                        'bridge_kyc_kyb_submission_id' => $submission->id,
                        'first_name' => $controlPersonData['first_name'] ?? null,
                        'last_name' => $controlPersonData['last_name'] ?? null,
                        'email' => $controlPersonData['email'] ?? null,
                        'birth_date' => $controlPersonData['birth_date'] ?? null,
                        'ssn' => $controlPersonData['ssn'] ?? null,
                        'title' => $controlPersonData['title'] ?? null,
                        'ownership_percentage' => $controlPersonData['ownership_percentage'] ?? null,
                        'street_line_1' => $controlPersonData['street_line_1'] ?? null,
                        'city' => $controlPersonData['city'] ?? null,
                        'state' => $controlPersonData['state'] ?? null,
                        'postal_code' => $controlPersonData['postal_code'] ?? null,
                        'country' => $controlPersonData['country'] ?? 'USA',
                        'id_type' => $controlPersonData['id_type'] ?? null,
                        'id_number' => $controlPersonData['id_number'] ?? null,
                        'bridge_associated_person_id' => $controlPersonData['bridge_associated_person_id'] ?? null,
                    ]);
                    
                    // ID images are already saved above to verification_documents
                }
            }

            Log::info('KYC/KYB submission saved to database', [
                'bridge_integration_id' => $integration->id,
                'type' => $isOrgUser ? 'kyb' : 'kyc',
                'customer_id' => $integration->bridge_customer_id,
                'id_front_image_path' => $idFrontImagePath,
                'id_back_image_path' => $idBackImagePath,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the entire request
            Log::error('Failed to save KYC/KYB submission to database', [
                'error' => $e->getMessage(),
                'bridge_integration_id' => $integration->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Get document statuses from VerificationDocument table
     * 
     * @param BridgeKycKybSubmission $submission
     * @return array
     */
    protected function getDocumentStatuses(BridgeKycKybSubmission $submission): array
    {
        $statuses = [];
        $rejectionReasons = [];
        
        // Get all verification documents for this submission
        $documents = $submission->verificationDocuments()->get();
        
        foreach ($documents as $doc) {
            $statuses[$doc->document_type] = $doc->status;
            
            if ($doc->rejection_reason) {
                $rejectionReasons[$doc->document_type . '_rejection_reason'] = $doc->rejection_reason;
            }
            if ($doc->rejected_at) {
                $statuses[$doc->document_type . '_rejected_at'] = $doc->rejected_at->toIso8601String();
            }
            if ($doc->rejected_by) {
                $statuses[$doc->document_type . '_rejected_by'] = $doc->rejected_by;
            }
            if ($doc->approval_notes) {
                $statuses[$doc->document_type . '_approval_notes'] = $doc->approval_notes;
            }
            if ($doc->approved_at) {
                $statuses[$doc->document_type . '_approved_at'] = $doc->approved_at->toIso8601String();
            }
            if ($doc->approved_by) {
                $statuses[$doc->document_type . '_approved_by'] = $doc->approved_by;
            }
        }
        
        // Merge rejection reasons into statuses array
        return array_merge($statuses, $rejectionReasons);
    }

    /**
     * Create virtual account for USD deposits to Bridge wallet
     * This provides bank account details for manual transfers
     */
    public function createVirtualAccountForWallet(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found. Please initialize Bridge first.',
                ], 404);
            }

            $customerId = $integration->bridge_customer_id;
            $isSandbox = $this->bridgeService->isSandbox();
            $sourceCurrency = $this->bridgeService->resolvePreferredVirtualAccountSourceCurrency(
                null,
                $customerId,
            );

            $wallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                    ->where('is_primary', true)
                    ->first();

            if (! $wallet && $integration->bridge_wallet_id) {
                $wallet = BridgeWallet::create([
                    'bridge_integration_id' => $integration->id,
                    'bridge_customer_id' => $customerId,
                    'bridge_wallet_id' => $integration->bridge_wallet_id,
                    'wallet_address' => $integration->wallet_address,
                    'chain' => $integration->wallet_chain ?? 'solana',
                    'status' => 'active',
                    'balance' => 0,
                    'currency' => 'USD',
                    'is_primary' => true,
                ]);
            }

            if (! $wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Complete verification and create a Bridge wallet first.',
                ], 404);
            }

            $bridgeWalletId = $this->bridgeService->resolveBridgeWalletIdForIntegration($integration, $wallet);

            // Reuse any matching virtual account already on Bridge (USD ACH or EUR IBAN)
            $existingVirtualAccount = $this->bridgeService->resolveVirtualAccountFromBridge(
                $customerId,
                $bridgeWalletId,
                $sourceCurrency,
            );

            if ($existingVirtualAccount) {
                $existingInstructions = is_array($existingVirtualAccount['source_deposit_instructions'] ?? null)
                    ? $existingVirtualAccount['source_deposit_instructions']
                    : [];
                $existingSource = is_array($existingVirtualAccount['source'] ?? null)
                    ? $existingVirtualAccount['source']
                    : [];
                $existingCurrency = strtolower((string) (
                    $existingInstructions['currency']
                    ?? $existingSource['currency']
                    ?? ''
                ));

                // Reuse only when currency matches (don't attach a USD ACH VA for a SEPA/EUR customer).
                if ($existingCurrency === '' || $existingCurrency === $sourceCurrency) {
                    $this->bridgeService->attachVirtualAccountToWallet($wallet, $existingVirtualAccount);

                    return response()->json([
                        'success' => true,
                        'message' => $sourceCurrency === 'eur'
                            ? 'SEPA deposit account (EUR IBAN) is ready'
                            : 'Deposit bank account is ready',
                        'data' => $existingVirtualAccount,
                    ]);
                }

                Log::info('Existing Bridge virtual account currency mismatch — creating preferred rail', [
                    'customer_id' => $customerId,
                    'existing_currency' => $existingCurrency,
                    'preferred_currency' => $sourceCurrency,
                ]);
            }

            if (! $isSandbox) {
                $ensureWallet = $this->bridgeService->ensureProductionBridgeWallet($integration, $wallet);
                if (! ($ensureWallet['success'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'message' => $ensureWallet['error'] ?? 'Failed to prepare Bridge wallet for deposits.',
                    ], 422);
                }

                $bridgeWalletId = $ensureWallet['bridge_wallet_id'] ?? $bridgeWalletId;
                $wallet = $ensureWallet['wallet'] ?? $wallet;

                if (! $bridgeWalletId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bridge wallet is required before creating a deposit bank account.',
                    ], 422);
                }

                $walletChain = strtolower((string) ($wallet->chain ?? $integration->wallet_chain ?? 'solana'));
                if (in_array($walletChain, ['usd', 'fiat', 'eur'], true)) {
                    $walletChain = $this->bridgeService->resolveWalletChain($customerId, $bridgeWalletId);
                }

                    $result = $this->bridgeService->createVirtualAccountForWallet(
                    $customerId,
                    $bridgeWalletId,
                    $sourceCurrency,
                    $walletChain,
                );
            } else {
                    $result = $this->bridgeService->createVirtualAccount(
                    $customerId,
                        ['currency' => $sourceCurrency],
                        [
                        'payment_rail' => 'ethereum',
                            'currency' => 'usdc',
                        'address' => $this->bridgeService->generateEthereumAddress(),
                    ],
                );
            }

            if (! ($result['success'] ?? false)) {
                // Race: webhook may have created the account — sync once more
                $synced = $this->bridgeService->resolveVirtualAccountFromBridge($customerId, $bridgeWalletId);
                if ($synced) {
                    $this->bridgeService->attachVirtualAccountToWallet($wallet, $synced);

                    return response()->json([
                        'success' => true,
                        'message' => 'Deposit bank account is ready',
                        'data' => $synced,
                    ]);
                }

                $error = $result['error'] ?? 'Failed to create virtual account';
                Log::error('Virtual account creation failed', [
                    'customer_id' => $customerId,
                    'bridge_wallet_id' => $bridgeWalletId,
                    'is_sandbox' => $isSandbox,
                    'error' => $error,
                    'response' => $result['response'] ?? null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create deposit bank account: ' . $error,
                ], 422);
            }

            $virtualAccountData = $result['data'] ?? [];
            $this->bridgeService->attachVirtualAccountToWallet($wallet, $virtualAccountData);

            return response()->json([
                'success' => true,
                'message' => 'Deposit bank account created successfully',
                'data' => $virtualAccountData,
            ]);
        } catch (\Exception $e) {
            Log::error('Virtual account creation error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create virtual account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get virtual accounts for the current user/organization
     */
    public function getVirtualAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            $result = $this->bridgeService->getVirtualAccounts($integration->bridge_customer_id);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to get virtual accounts');
            }

            return response()->json([
                'success' => true,
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('Get virtual accounts error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get virtual accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create transfer from external account to Bridge wallet (for USD top-up)
     */
    public function createTransferFromExternalAccount(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            $validated = $request->validate([
                'external_account_id' => 'required|string',
                'wallet_id' => 'required|string',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|in:USD,usd',
            ]);

            $result = $this->bridgeService->createTransferFromExternalAccount(
                $integration->bridge_customer_id,
                $validated['external_account_id'],
                $validated['wallet_id'],
                (float) $validated['amount'],
                $validated['currency'] ?? 'USD'
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to create transfer');
            }

            return response()->json([
                'success' => true,
                'message' => 'Transfer initiated successfully',
                'data' => $result['data'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Transfer from external account error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create transfer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create transfer from Bridge wallet to external account (withdrawal/payout)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createTransferToExternalAccount(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            if ($this->bridgeService->isSandbox()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank withdrawals are not available in sandbox mode. Bridge offramps require a production Bridge wallet (bridge_wallet_id), not a sandbox virtual account.',
                    'error_code' => 'SANDBOX_WITHDRAWAL_UNAVAILABLE',
                ], 422);
            }

            $primaryWallet = $integration->primaryWallet;
            $bridgeWalletId = $integration->bridge_wallet_id
                ?? ($primaryWallet ? $primaryWallet->bridge_wallet_id : null);

            if (empty($bridgeWalletId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge wallet not found. Complete verification and create a Bridge wallet before withdrawing to a bank account.',
                    'error_code' => 'BRIDGE_WALLET_REQUIRED',
                ], 404);
            }

            $validated = $request->validate([
                'external_account_id' => 'required|string',
                'wallet_id' => 'nullable|string',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|in:USD,usd',
                'payment_rail' => 'nullable|string|in:ach,wire,ach_same_day',
                'ach_reference' => 'nullable|string|max:10',
                'wire_message' => 'nullable|string|max:140',
            ]);

            $addressFix = $this->bridgeService->ensureExternalAccountAddressValid(
                $integration->bridge_customer_id,
                $validated['external_account_id'],
            );

            if (! ($addressFix['success'] ?? false)) {
                $status = (int) ($addressFix['status'] ?? 422);

                return response()->json([
                    'success' => false,
                    'message' => $addressFix['message'] ?? 'Bank account address is invalid. Please update the address on your linked bank account (street line 1 must be 3–35 characters).',
                    'error_code' => 'EXTERNAL_ACCOUNT_ADDRESS_INVALID',
                    'violations' => $addressFix['violations'] ?? [],
                ], $status >= 400 && $status < 600 ? $status : 422);
            }

            $result = $this->bridgeService->createTransferToExternalAccount(
                $integration->bridge_customer_id,
                $bridgeWalletId,
                $validated['external_account_id'],
                (float) $validated['amount'],
                $validated['currency'] ?? 'USD',
                $validated['payment_rail'] ?? 'ach',
                $validated['ach_reference'] ?? null,
                $validated['wire_message'] ?? null,
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to create withdrawal');
            }

            $transferId = $result['data']['id'] ?? $result['data']['transfer_id'] ?? null;

            Log::info('Bridge wallet withdrawal transfer created', [
                'user_id' => $user->id,
                'bridge_transfer_id' => $transferId,
                'external_account_id' => $validated['external_account_id'],
                'amount' => (float) $validated['amount'],
                'payment_rail' => $validated['payment_rail'] ?? 'ach',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal initiated successfully',
                'data' => $result['data'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Withdrawal to external account error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create withdrawal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get external accounts (bank accounts) for the current user/organization
     */
    public function getExternalAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            $result = $this->bridgeService->getExternalAccounts($integration->bridge_customer_id);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to get external accounts');
            }

            // Bridge API returns: { success: true, data: { count: X, data: [...] } }
            // makeRequest wraps the Bridge response in ['success' => true, 'data' => $body]
            // So $result['data'] is the Bridge API response body: { count: X, data: [...] }
            $bridgeResponse = $result['data'] ?? [];

            // Log the raw response for debugging
            Log::info('Bridge external accounts raw response', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'bridge_response_structure' => [
                    'is_array' => is_array($bridgeResponse),
                    'has_data_key' => isset($bridgeResponse['data']),
                    'has_count_key' => isset($bridgeResponse['count']),
                    'keys' => is_array($bridgeResponse) ? array_keys($bridgeResponse) : [],
                ],
                'bridge_response' => $bridgeResponse,
            ]);

            // Extract the actual accounts array from the nested structure
            // Bridge returns: { count: 1, data: [...] }
            $accounts = $bridgeResponse['data'] ?? [];

            // If accounts is not an array, try to get it from the root level
            if (!is_array($accounts) && is_array($bridgeResponse) && isset($bridgeResponse[0])) {
                // Sometimes Bridge might return the array directly
                $accounts = $bridgeResponse;
            }

            // Ensure accounts is an array
            if (!is_array($accounts)) {
                Log::warning('External accounts is not an array', [
                    'integration_id' => $integration->id,
                    'accounts_type' => gettype($accounts),
                    'accounts_value' => $accounts,
                ]);
                $accounts = [];
            }

            Log::info('Bridge external accounts processed', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'accounts_count' => count($accounts),
                'accounts' => $accounts,
            ]);

            // Map Bridge API response to frontend format
            $mappedAccounts = [];
            foreach ($accounts as $account) {
                if (!is_array($account)) {
                    continue;
                }

                // Get last_4 from nested account object or top level
                $last4 = (string)($account['account']['last_4'] ?? $account['last_4'] ?? '');
                // The frontend uses slice(-4), so we need at least 4 characters
                // Since last_4 is already the last 4 digits, we can pad it or use it directly
                // For display purposes, we'll use it as-is since it's already the last 4
                $accountNumber = $last4 ?: '0000';

                $mappedAccount = [
                    'id' => $account['id'] ?? '',
                    'account_number' => $accountNumber,
                    'routing_number' => (string)($account['account']['routing_number'] ?? ''),
                    'account_type' => $account['account']['checking_or_savings'] ?? 'checking',
                    'bank_name' => (string)($account['bank_name'] ?? ''),
                    // Bridge returns account_name or account_owner_name, prioritize account_name
                    'account_holder_name' => $account['account_name'] ?? $account['account_owner_name'] ?? $account['account_holder_name'] ?? '',
                    'status' => ($account['active'] ?? false) ? 'verified' : 'pending',
                ];

                $mappedAccounts[] = $mappedAccount;
            }

            Log::info('Bridge external accounts mapped', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'mapped_count' => count($mappedAccounts),
                'mapped_accounts' => $mappedAccounts,
            ]);

            return response()->json([
                'success' => true,
                'data' => $mappedAccounts,
            ]);
        } catch (\Exception $e) {
            Log::error('Get external accounts error', [
                'integration_id' => $integration->id ?? null,
                'customer_id' => $integration->bridge_customer_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get external accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create external account (link bank account)
     */
    public function createExternalAccount(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            $validated = $request->validate([
                'account_data' => 'required|array',
                'account_data.routing_number' => 'required|string',
                'account_data.account_number' => 'required|string',
                'account_data.account_type' => 'required|string|in:checking,savings',
                'account_data.account_holder_name' => 'required|string',
                'account_data.bank_name' => 'required|string',
                'account_data.first_name' => 'required|string',
                'account_data.last_name' => 'required|string',
                'account_data.street_line_1' => 'required|string|min:3|max:35',
                'account_data.street_line_2' => 'nullable|string|max:35',
                'account_data.city' => 'required|string',
                'account_data.state' => 'required|string',
                'account_data.postal_code' => 'required|string',
                'account_data.country' => 'required|string',
            ]);

            // Format data according to Bridge API requirements - all data from user input
            $bridgeAccountData = [
                'currency' => 'usd',
                'account_type' => 'us',
                'bank_name' => trim($validated['account_data']['bank_name']),
                'account_name' => trim($validated['account_data']['account_holder_name']),
                'first_name' => trim($validated['account_data']['first_name']),
                'last_name' => trim($validated['account_data']['last_name']),
                'account_owner_type' => $isOrgUser ? 'business' : 'individual',
                'account_owner_name' => trim($validated['account_data']['account_holder_name']),
                'account' => [
                    'routing_number' => trim($validated['account_data']['routing_number']),
                    'account_number' => trim($validated['account_data']['account_number']),
                    'checking_or_savings' => $validated['account_data']['account_type'],
                ],
                'address' => $this->bridgeService->sanitizeExternalAccountAddress([
                    'street_line_1' => trim($validated['account_data']['street_line_1']),
                    'street_line_2' => trim((string) ($validated['account_data']['street_line_2'] ?? '')),
                    'country' => trim($validated['account_data']['country']),
                    'state' => trim($validated['account_data']['state']),
                    'city' => trim($validated['account_data']['city']),
                    'postal_code' => trim($validated['account_data']['postal_code']),
                ]),
            ];

            // Generate idempotency key
            $idempotencyKey = \Illuminate\Support\Str::uuid()->toString();

            Log::info('Creating external account via Bridge API', [
                'customer_id' => $integration->bridge_customer_id,
                'account_owner_type' => $bridgeAccountData['account_owner_type'],
            ]);

            $result = $this->bridgeService->createExternalAccount(
                $integration->bridge_customer_id,
                $bridgeAccountData,
                $idempotencyKey
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to create external account');
            }

            return response()->json([
                'success' => true,
                'message' => 'External account linked successfully',
                'data' => $result['data'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Create external account error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create external account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove (unlink) an external bank account.
     */
    public function deleteExternalAccount(Request $request, string $externalAccountId)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            $integration = BridgeIntegration::with('primaryWallet')
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (! $integration || ! $integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found.',
                ], 404);
            }

            if ($externalAccountId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank account ID is required.',
                ], 422);
            }

            $result = $this->bridgeService->deleteExternalAccount(
                $integration->bridge_customer_id,
                $externalAccountId,
            );

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? $result['message'] ?? 'Failed to remove bank account.',
                ], (int) ($result['status'] ?? 422));
            }

            return response()->json([
                'success' => true,
                'message' => 'Bank account removed successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete external account error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove bank account: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get deposit instructions (bank details) for virtual account
     */
    public function getDepositInstructions(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            Log::info('Fetching deposit instructions', [
                'user_id' => $user->id,
                'is_org_user' => $isOrgUser,
                'entity_id' => $entity->id,
                'entity_type' => $entityType,
                'entity_name' => $isOrgUser ? ($entity->name ?? 'N/A') : ($entity->name ?? 'N/A'),
            ]);

            // Explicitly query with both integratable_id and integratable_type to ensure we get the correct integration
            $integration = BridgeIntegration::with(['primaryWallet' => function ($query) {
                $query->where('is_primary', true);
            }])
                ->where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                Log::warning('Bridge integration not found for deposit instructions', [
                    'entity_id' => $entity->id,
                    'entity_type' => $entityType,
                    'user_id' => $user->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found. Please initialize Bridge first.',
                ], 404);
            }

            Log::info('Found Bridge integration', [
                'integration_id' => $integration->id,
                'customer_id' => $integration->bridge_customer_id,
                'entity_id' => $entity->id,
            ]);

            // Get primary wallet - explicitly query to ensure it belongs to this integration
            $primaryWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                ->where('is_primary', true)
                ->first();
            
            if (! $primaryWallet && $integration->bridge_wallet_id) {
                $primaryWallet = BridgeWallet::create([
                    'bridge_integration_id' => $integration->id,
                    'bridge_customer_id' => $integration->bridge_customer_id,
                    'bridge_wallet_id' => $integration->bridge_wallet_id,
                    'wallet_address' => $integration->wallet_address,
                    'chain' => $integration->wallet_chain ?? 'solana',
                    'status' => 'active',
                    'balance' => 0,
                    'currency' => 'USD',
                    'is_primary' => true,
                ]);
            }

            if (!$primaryWallet) {
                Log::warning('No primary wallet found for deposit instructions', [
                    'integration_id' => $integration->id,
                    'customer_id' => $integration->bridge_customer_id,
                    'entity_id' => $entity->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Please create a wallet/virtual account first.',
                ], 404);
            }

            $bridgeWalletId = $this->bridgeService->resolveBridgeWalletIdForIntegration($integration, $primaryWallet);
            $sourceCurrency = $this->bridgeService->resolvePreferredVirtualAccountSourceCurrency(
                null,
                $integration->bridge_customer_id,
            );

            // Always sync from Bridge first — prefer EUR IBAN when SEPA is the available rail
            $syncedVirtualAccount = $this->bridgeService->resolveVirtualAccountFromBridge(
                $integration->bridge_customer_id,
                $bridgeWalletId,
                $sourceCurrency,
            );

            if ($syncedVirtualAccount) {
                $this->bridgeService->attachVirtualAccountToWallet($primaryWallet, $syncedVirtualAccount);
            }

            Log::info('Found primary wallet', [
                'wallet_id' => $primaryWallet->id,
                'integration_id' => $primaryWallet->bridge_integration_id,
                'customer_id' => $primaryWallet->bridge_customer_id,
                'virtual_account_id' => $primaryWallet->virtual_account_id,
                'has_virtual_account_details' => !empty($primaryWallet->virtual_account_details),
            ]);

            // Verify wallet belongs to correct integration and customer
            if ($primaryWallet->bridge_integration_id !== $integration->id) {
                Log::error('Wallet integration mismatch', [
                    'wallet_integration_id' => $primaryWallet->bridge_integration_id,
                    'expected_integration_id' => $integration->id,
                    'wallet_id' => $primaryWallet->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet does not belong to this organization.',
                ], 403);
            }

            if ($primaryWallet->bridge_customer_id !== $integration->bridge_customer_id) {
                Log::error('Wallet customer mismatch', [
                    'wallet_customer_id' => $primaryWallet->bridge_customer_id,
                    'expected_customer_id' => $integration->bridge_customer_id,
                    'wallet_id' => $primaryWallet->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet does not belong to this customer.',
                ], 403);
            }

            // Check if we have virtual account details stored (may have been synced above)
            $virtualAccountDetails = $primaryWallet->fresh()->virtual_account_details;
            
            // Parse JSON string if needed
            if (is_string($virtualAccountDetails)) {
                $virtualAccountDetails = json_decode($virtualAccountDetails, true) ?? [];
            }
            
            if (!$virtualAccountDetails || !is_array($virtualAccountDetails) || empty($virtualAccountDetails)) {
                // Try to fetch from Bridge API if we have virtual_account_id
                if ($primaryWallet->virtual_account_id) {
                    Log::info('Fetching virtual account details from Bridge API', [
                        'customer_id' => $integration->bridge_customer_id,
                        'virtual_account_id' => $primaryWallet->virtual_account_id,
                        'wallet_id' => $primaryWallet->id,
                    ]);
                    
                    $virtualAccountResult = $this->bridgeService->getVirtualAccount(
                        $integration->bridge_customer_id,
                        $primaryWallet->virtual_account_id
                    );
                    
                    if ($virtualAccountResult['success'] && isset($virtualAccountResult['data'])) {
                        $virtualAccountDetails = $virtualAccountResult['data'];
                        
                        // Verify the virtual account belongs to the correct customer
                        $virtualAccountCustomerId = $virtualAccountDetails['customer_id'] ?? null;
                        if ($virtualAccountCustomerId && $virtualAccountCustomerId !== $integration->bridge_customer_id) {
                            Log::error('Virtual account customer mismatch from Bridge API', [
                                'virtual_account_customer_id' => $virtualAccountCustomerId,
                                'expected_customer_id' => $integration->bridge_customer_id,
                                'virtual_account_id' => $primaryWallet->virtual_account_id,
                            ]);
                            return response()->json([
                                'success' => false,
                                'message' => 'Virtual account does not belong to this customer.',
                            ], 403);
                        }
                        
                        // Update stored details
                        $primaryWallet->virtual_account_details = $virtualAccountDetails;
                        $primaryWallet->save();
                        
                        Log::info('Virtual account details fetched and saved', [
                            'wallet_id' => $primaryWallet->id,
                            'virtual_account_id' => $primaryWallet->virtual_account_id,
                            'has_deposit_instructions' => isset($virtualAccountDetails['source_deposit_instructions']),
                        ]);
                    } else {
                        Log::error('Failed to fetch virtual account from Bridge', [
                            'customer_id' => $integration->bridge_customer_id,
                            'virtual_account_id' => $primaryWallet->virtual_account_id,
                            'error' => $virtualAccountResult['error'] ?? 'Unknown error',
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to fetch virtual account details from Bridge.',
                        ], 404);
                    }
                } else {
                    Log::warning('No virtual account ID found after Bridge sync', [
                        'wallet_id' => $primaryWallet->id,
                        'integration_id' => $integration->id,
                        'customer_id' => $integration->bridge_customer_id,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'No deposit bank account yet. Tap Create Deposit Account to set one up.',
                        'needs_virtual_account' => true,
                    ], 404);
                }
            }

            // Extract deposit instructions (normalize EUR IBAN → UI fields)
            $depositInstructions = $virtualAccountDetails['source_deposit_instructions'] ?? null;
            
            if (!$depositInstructions) {
                Log::warning('Deposit instructions not found in virtual account details', [
                    'wallet_id' => $primaryWallet->id,
                    'virtual_account_id' => $primaryWallet->virtual_account_id,
                    'virtual_account_details_keys' => is_array($virtualAccountDetails) ? array_keys($virtualAccountDetails) : [],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Deposit instructions not available for this virtual account.',
                ], 404);
            }

            $depositInstructions = $this->bridgeService->normalizeDepositInstructionsForUi(
                is_array($depositInstructions) ? $depositInstructions : [],
            );

            Log::info('Deposit instructions retrieved successfully', [
                'wallet_id' => $primaryWallet->id,
                'virtual_account_id' => $primaryWallet->virtual_account_id,
                'customer_id' => $integration->bridge_customer_id,
                'entity_id' => $entity->id,
                'currency' => $depositInstructions['currency'] ?? null,
                'has_payment_rails' => isset($depositInstructions['payment_rails']),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'deposit_instructions' => $depositInstructions,
                    'virtual_account_id' => $primaryWallet->virtual_account_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get deposit instructions error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get deposit instructions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate QR code for deposit instructions (receive money)
     * 
     * Creates a QR code containing bank account details for easy sharing
     * Works for both sandbox and live environments
     */
    public function getDepositQrCode(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                // Return error QR code image instead of JSON (using SVG format - no imagick required)
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Bridge integration not found');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Get primary wallet
            $primaryWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                ->where('is_primary', true)
                ->first();
            
            if (!$primaryWallet || !$primaryWallet->virtual_account_id) {
                // Return error QR code image instead of JSON (using SVG format - no imagick required)
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: No virtual account found');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Get virtual account details
            $virtualAccountDetails = $primaryWallet->virtual_account_details;
            
            if (is_string($virtualAccountDetails)) {
                $virtualAccountDetails = json_decode($virtualAccountDetails, true) ?? [];
            }
            
            if (!$virtualAccountDetails || !is_array($virtualAccountDetails) || empty($virtualAccountDetails)) {
                // Fetch from Bridge API if not stored
                if ($primaryWallet->virtual_account_id) {
                    $virtualAccountResult = $this->bridgeService->getVirtualAccount(
                        $integration->bridge_customer_id,
                        $primaryWallet->virtual_account_id
                    );
                    
                    if ($virtualAccountResult['success'] && isset($virtualAccountResult['data'])) {
                        $virtualAccountDetails = $virtualAccountResult['data'];
                        $primaryWallet->virtual_account_details = $virtualAccountDetails;
                        $primaryWallet->save();
                    } else {
                        // Return error QR code image instead of JSON (using SVG format - no imagick required)
                        $errorQr = QrCode::format('svg')
                            ->size(300)
                            ->margin(2)
                            ->generate('Error: Failed to fetch virtual account details');
                        return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
                    }
                } else {
                    // Return error QR code image instead of JSON (using SVG format - no imagick required)
                    $errorQr = QrCode::format('svg')
                        ->size(300)
                        ->margin(2)
                        ->generate('Error: No virtual account found');
                    return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
                }
            }

            // Extract deposit instructions
            $depositInstructions = $virtualAccountDetails['source_deposit_instructions'] ?? null;
            
            if (!$depositInstructions) {
                // Return error QR code image instead of JSON (using SVG format - no imagick required)
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Deposit instructions not available');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Build QR code data
            // Format: Bank account details in a structured format
            // For ACH/Wire transfers, we'll create a JSON structure with bank details
            $qrData = [
                'type' => 'bank_transfer',
                'bank_name' => $depositInstructions['bank_name'] ?? '',
                'routing_number' => $depositInstructions['bank_routing_number'] ?? '',
                'account_number' => $depositInstructions['bank_account_number'] ?? '',
                'account_holder' => $depositInstructions['bank_beneficiary_name'] ?? '',
                'currency' => $depositInstructions['currency'] ?? 'USD',
                'payment_rail' => $depositInstructions['payment_rail'] ?? ($depositInstructions['payment_rails'][0] ?? 'ach_push'),
            ];

            // Also create a human-readable string format for compatibility
            $qrString = json_encode($qrData);

            // Generate QR code as SVG (no imagick required)
            $qrCode = QrCode::format('svg')
                ->size(300)
                ->margin(2)
                ->errorCorrection('M')
                ->generate($qrString);

            return response($qrCode, 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);
        } catch (\Exception $e) {
            Log::error('Generate deposit QR code error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
            
            // Return a simple error QR code or empty response (using SVG format - no imagick required)
            try {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Unable to generate QR code');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            } catch (\Exception $e2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate QR code: ' . $e->getMessage(),
                ], 500);
            }
        }
    }

    /**
     * Resolve where Bridge should forward crypto deposits (Bridge wallet or sandbox virtual account).
     *
     * @return array{address: string, payment_rail: string, currency: string}|null
     */
    private function resolveLiquidationDestination(
        BridgeIntegration $integration,
        ?BridgeWallet $primaryWallet,
        bool $isSandbox,
    ): ?array {
        if ($isSandbox) {
            $virtualAccountDetails = $primaryWallet?->virtual_account_details;

            if (is_string($virtualAccountDetails)) {
                $virtualAccountDetails = json_decode($virtualAccountDetails, true) ?? [];
            }

            if (is_array($virtualAccountDetails) && ! empty($virtualAccountDetails['destination']['address'])) {
                return [
                    'address' => $virtualAccountDetails['destination']['address'],
                    'payment_rail' => $virtualAccountDetails['destination']['payment_rail'] ?? 'ethereum',
                    'currency' => $virtualAccountDetails['destination']['currency'] ?? 'usdc',
                ];
            }

            if ($primaryWallet?->virtual_account_id) {
                $virtualAccountResult = $this->bridgeService->getVirtualAccount(
                    $integration->bridge_customer_id,
                    $primaryWallet->virtual_account_id,
                );

                if (($virtualAccountResult['success'] ?? false) && isset($virtualAccountResult['data']['destination']['address'])) {
                    $primaryWallet->virtual_account_details = $virtualAccountResult['data'];
                    $primaryWallet->save();

                    return [
                        'address' => $virtualAccountResult['data']['destination']['address'],
                        'payment_rail' => $virtualAccountResult['data']['destination']['payment_rail'] ?? 'ethereum',
                        'currency' => $virtualAccountResult['data']['destination']['currency'] ?? 'usdc',
                    ];
                }
            }

            $virtualAccountsResult = $this->bridgeService->getVirtualAccounts($integration->bridge_customer_id);
            if ($virtualAccountsResult['success'] ?? false) {
                $responseData = $virtualAccountsResult['data'] ?? [];
                $virtualAccounts = is_array($responseData['data'] ?? null)
                    ? $responseData['data']
                    : (is_array($responseData) ? $responseData : []);

                foreach ($virtualAccounts as $virtualAccount) {
                    $address = $virtualAccount['destination']['address'] ?? null;
                    if ($address) {
                        return [
                            'address' => $address,
                            'payment_rail' => $virtualAccount['destination']['payment_rail'] ?? 'ethereum',
                            'currency' => $virtualAccount['destination']['currency'] ?? 'usdc',
                        ];
                    }
                }
            }

            return null;
        }

        if (! $primaryWallet || empty($primaryWallet->wallet_address)) {
            return null;
        }

        return [
            'address' => $primaryWallet->wallet_address,
            'payment_rail' => $primaryWallet->chain ?? 'solana',
            'currency' => 'usdc',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLiquidationAddressPayload(LiquidationAddress $address): array
    {
        return [
            'id' => $address->bridge_liquidation_address_id ?? (string) $address->id,
            'chain' => $address->chain,
            'currency' => $address->currency,
            'address' => $address->address,
            'destination_payment_rail' => $address->destination_payment_rail,
            'destination_currency' => $address->destination_currency,
            'destination_address' => $address->destination_address,
            'return_address' => $address->return_address,
            'state' => $address->state ?? 'active',
        ];
    }

    /**
     * Pull liquidation addresses from Bridge and persist locally.
     *
     * @return list<array<string, mixed>>
     */
    private function syncLiquidationAddressesFromBridge(BridgeIntegration $integration): array
    {
        $result = $this->bridgeService->getLiquidationAddresses($integration->bridge_customer_id);
        if (! ($result['success'] ?? false)) {
            return [];
        }

        $data = $result['data'] ?? [];
        $bridgeAddresses = is_array($data['data'] ?? null)
            ? $data['data']
            : (is_array($data) ? $data : []);

        $formatted = [];

        foreach ($bridgeAddresses as $bridgeAddress) {
            if (! is_array($bridgeAddress) || empty($bridgeAddress['address'])) {
                continue;
            }

            $stored = LiquidationAddress::updateOrCreate(
                [
                    'bridge_integration_id' => $integration->id,
                    'chain' => $bridgeAddress['chain'] ?? null,
                    'currency' => $bridgeAddress['currency'] ?? null,
                ],
                [
                    'bridge_customer_id' => $integration->bridge_customer_id,
                    'bridge_liquidation_address_id' => $bridgeAddress['id'] ?? null,
                    'address' => $bridgeAddress['address'],
                    'destination_payment_rail' => $bridgeAddress['destination_payment_rail'] ?? null,
                    'destination_currency' => $bridgeAddress['destination_currency'] ?? null,
                    'destination_address' => $bridgeAddress['destination_address'] ?? null,
                    'return_address' => $bridgeAddress['return_address'] ?? null,
                    'state' => $bridgeAddress['state'] ?? 'active',
                    'liquidation_metadata' => $bridgeAddress,
                    'last_sync_at' => now(),
                ],
            );

            $formatted[] = $this->formatLiquidationAddressPayload($stored);
        }

        return $formatted;
    }

    private function findStoredLiquidationAddress(
        BridgeIntegration $integration,
        string $chain,
        string $currency,
    ): ?LiquidationAddress {
        return LiquidationAddress::where('bridge_integration_id', $integration->id)
            ->where('chain', $chain)
            ->where('currency', $currency)
            ->whereNotNull('address')
            ->first();
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, already_exists?: bool}
     */
    private function resolveExistingLiquidationAddressResponse(
        BridgeIntegration $integration,
        string $chain,
        string $currency,
    ): array {
        $existing = $this->findStoredLiquidationAddress($integration, $chain, $currency);
        if ($existing) {
            return [
                'success' => true,
                'already_exists' => true,
                'data' => $this->formatLiquidationAddressPayload($existing),
            ];
        }

        $this->syncLiquidationAddressesFromBridge($integration);
        $existing = $this->findStoredLiquidationAddress($integration, $chain, $currency);

        if ($existing) {
            return [
                'success' => true,
                'already_exists' => true,
                'data' => $this->formatLiquidationAddressPayload($existing),
            ];
        }

        return ['success' => false];
    }

    private function bridgeLiquidationAddressAlreadyExists(?string $errorMessage, ?array $response = null): bool
    {
        $haystack = strtolower(trim((string) $errorMessage));

        if (str_contains($haystack, 'already exists')) {
            return true;
        }

        $responseMessage = strtolower((string) ($response['message'] ?? ''));
        if (str_contains($responseMessage, 'already exists')) {
            return true;
        }

        return ($response['code'] ?? null) === 'duplicate_record';
    }

    /**
     * Create a liquidation address for crypto deposits
     */
    public function createLiquidationAddress(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found',
                ], 404);
            }

            // Check if we're in sandbox mode
            $isSandbox = $this->bridgeService->isSandbox();

            // Get primary wallet (for production) or virtual account (for sandbox)
            $primaryWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                ->where('is_primary', true)
                ->first();

            $destination = $this->resolveLiquidationDestination($integration, $primaryWallet, $isSandbox);

            if (! $destination) {
                    return response()->json([
                        'success' => false,
                    'message' => $isSandbox
                        ? 'No virtual account found. Open Deposit and create a deposit account first, then try crypto again.'
                        : 'No Bridge wallet found. Create your wallet first.',
                    ], 404);
                }

            $destinationAddress = $destination['address'];
            $destinationPaymentRail = $destination['payment_rail'];
            $destinationCurrency = $destination['currency'];

            // Validate request — destination_* is resolved server-side from wallet / virtual account
            $validated = $request->validate([
                'chain' => 'required|string|in:solana,ethereum,base,polygon',
                'currency' => 'required|string|in:usdc,usdt',
                'destination_payment_rail' => 'sometimes|string',
                'destination_currency' => 'sometimes|string|in:usdc,usdt',
            ]);

            // Validate chain/currency combinations
            // USDT is not supported on Solana (per Bridge.xyz API)
            if ($validated['chain'] === 'solana' && $validated['currency'] === 'usdt') {
                return response()->json([
                    'success' => false,
                    'message' => 'USDT is not supported on Solana. Please use USDC for Solana.',
                ], 400);
            }

            // Override destination currency and payment rail based on environment
            // In sandbox, use the virtual account's destination settings (Ethereum + USDC)
            // In production, use the validated values (can be Solana/Ethereum + usdc)
            if ($isSandbox) {
                // Sandbox: Always use Ethereum payment rail and USDC currency (per Bridge.xyz docs)
                // Also, in sandbox, only Ethereum chain is supported
                if ($validated['chain'] !== 'ethereum') {
                    return response()->json([
                        'success' => false,
                        'message' => 'In sandbox mode, only Ethereum chain is supported for liquidation addresses.',
                    ], 400);
                }
                if ($validated['currency'] !== 'usdc') {
                    return response()->json([
                        'success' => false,
                        'message' => 'In sandbox mode, only USDC currency is supported for liquidation addresses.',
                    ], 400);
                }
                $destinationPaymentRail = 'ethereum';
                $destinationCurrency = 'usdc';
            } else {
                $destinationPaymentRail = $validated['destination_payment_rail'] ?? $destinationPaymentRail;
                $destinationCurrency = $validated['destination_currency'] ?? $destinationCurrency;
            }

            // Prepare liquidation address data
            $liquidationData = [
                'chain' => $validated['chain'],
                'currency' => $validated['currency'],
                'destination_payment_rail' => $destinationPaymentRail,
                'destination_currency' => $destinationCurrency,
                'destination_address' => $destinationAddress,
            ];

            // Return existing address when already provisioned (local DB or Bridge)
            $existingResult = $this->resolveExistingLiquidationAddressResponse(
                $integration,
                $validated['chain'],
                $validated['currency'],
            );
            if ($existingResult['success'] ?? false) {
                return response()->json($existingResult);
            }

            // Create liquidation address via Bridge API
            $result = $this->bridgeService->createLiquidationAddress(
                $integration->bridge_customer_id,
                $liquidationData
            );

            if (! ($result['success'] ?? false)) {
                if ($this->bridgeLiquidationAddressAlreadyExists($result['error'] ?? null, $result['response'] ?? null)) {
                    $existingResult = $this->resolveExistingLiquidationAddressResponse(
                        $integration,
                        $validated['chain'],
                        $validated['currency'],
                    );

                    if ($existingResult['success'] ?? false) {
                        Log::info('Reusing existing Bridge liquidation address after duplicate create', [
                            'integration_id' => $integration->id,
                            'chain' => $validated['chain'],
                            'currency' => $validated['currency'],
                        ]);

                        return response()->json($existingResult);
                    }
                }

                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to create liquidation address',
                ], 500);
            }

            // Store liquidation address in database
            if (isset($result['data'])) {
                $bridgeLiquidationData = $result['data'];
                
                $liquidationAddress = LiquidationAddress::updateOrCreate(
                    [
                        'bridge_integration_id' => $integration->id,
                        'chain' => $validated['chain'],
                        'currency' => $validated['currency'],
                    ],
                    [
                        'bridge_customer_id' => $integration->bridge_customer_id,
                        'bridge_liquidation_address_id' => $bridgeLiquidationData['id'] ?? null,
                        'address' => $bridgeLiquidationData['address'] ?? null,
                        'destination_payment_rail' => $destinationPaymentRail,
                        'destination_currency' => $destinationCurrency,
                        'destination_address' => $destinationAddress,
                        'return_address' => $bridgeLiquidationData['return_address'] ?? null,
                        'state' => $bridgeLiquidationData['state'] ?? 'active',
                        'liquidation_metadata' => $bridgeLiquidationData,
                        'last_sync_at' => now(),
                    ]
                );

                return response()->json([
                    'success' => true,
                    'data' => $bridgeLiquidationData,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('Create liquidation address error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create liquidation address: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get card account for the user/organization
     */
    public function getCardAccount(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found',
                    'has_card_account' => false,
                ], 404);
            }

            $customerId = $integration->bridge_customer_id;

            $virtualCard = $this->bridgeService->getVirtualCard($integration, $customerId);
            if ($virtualCard['success'] ?? false) {
                return response()->json([
                    'success' => true,
                    'has_card_account' => (bool) ($virtualCard['has_card'] ?? false),
                    'data' => $virtualCard['data'] ?? null,
                ]);
            }

            // Legacy Bridge card_accounts list fallback
            $cardAccountsResult = $this->bridgeService->getCardAccounts($customerId);

            if (!$cardAccountsResult['success']) {
                return response()->json([
                    'success' => false,
                    'has_card_account' => false,
                    'message' => 'Failed to check card account status',
                ], 200);
            }

            // Extract card accounts array
            $cardAccounts = is_array($cardAccountsResult['data']) && isset($cardAccountsResult['data']['data'])
                ? $cardAccountsResult['data']['data']
                : (is_array($cardAccountsResult['data']) ? $cardAccountsResult['data'] : []);

            if (empty($cardAccounts) || !is_array($cardAccounts)) {
                return response()->json([
                    'success' => true,
                    'has_card_account' => false,
                    'data' => null,
                ]);
            }

            // Return first card account
            return response()->json([
                'success' => true,
                'has_card_account' => true,
                'data' => $cardAccounts[0],
            ]);
        } catch (\Exception $e) {
            Log::error('Get card account error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'has_card_account' => false,
                'message' => 'Failed to get card account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a card account for the user/organization
     */
    public function createCardAccount(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found',
                ], 404);
            }

            $customerId = $integration->bridge_customer_id;

            $cardsEnableResult = $this->bridgeService->ensureCardsProductEnabled();
            if (! ($cardsEnableResult['success'] ?? false)
                && empty($cardsEnableResult['skipped'])
                && empty($cardsEnableResult['already_enabled'])) {
                $helpUrl = ($cardsEnableResult['production'] ?? false)
                    ? 'https://apidocs.bridge.xyz/platform/cards/overview/stripe-issuing'
                    : 'https://apidocs.bridge.xyz/platform/cards/sandbox/sandbox';

                return response()->json([
                    'success' => false,
                    'message' => $cardsEnableResult['error'] ?? 'Bridge cards are not ready for issuance.',
                    'error_code' => 'cards_enable_failed',
                    'help_url' => $cardsEnableResult['help_url'] ?? $helpUrl,
                ], 400);
            }

            $existingVirtualCard = $this->bridgeService->getVirtualCard($integration, $customerId);
            if (($existingVirtualCard['success'] ?? false) && ($existingVirtualCard['has_card'] ?? false) && ! empty($existingVirtualCard['data'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'Card already issued',
                    'data' => $existingVirtualCard['data'],
                ]);
            }

            // Legacy Bridge card_accounts API — sandbox only; production uses Stripe Issuing directly.
            $cardAccountsResult = ['success' => false];
            $useLegacyCardAccounts = $this->bridgeService->isSandbox();

            if ($useLegacyCardAccounts) {
                $cardAccountsResult = $this->bridgeService->getCardAccounts($customerId);

                if (! $cardAccountsResult['success']) {
                    if ($this->bridgeService->shouldBypassLegacyCardAccountsGate($cardAccountsResult)) {
                        $useLegacyCardAccounts = false;
                    } elseif ($this->bridgeService->isCardsProductNotEnabledError($cardAccountsResult)) {
                        Log::info('Cards product not enabled - attempting to enable automatically', [
                            'user_id' => Auth::id(),
                            'customer_id' => $customerId,
                            'environment' => 'sandbox',
                        ]);

                        $enableResult = $this->bridgeService->enableCardsProduct();

                        if ($enableResult['success'] ?? false) {
                            $cardAccountsResult = $this->bridgeService->getCardAccounts($customerId);

                            if (! $cardAccountsResult['success']
                                && $this->bridgeService->shouldBypassLegacyCardAccountsGate($cardAccountsResult)) {
                                $useLegacyCardAccounts = false;
                            } elseif (! $cardAccountsResult['success']) {
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Cards product enabled but card account retrieval still failed. Please try again.',
                                    'error_code' => 'cards_enabled_but_failed',
                                ], 500);
                            }
                        } else {
                            return response()->json([
                                'success' => false,
                                'message' => $enableResult['error'] ?? 'Failed to enable Bridge cards product. Link your Cashier Stripe account under Settings → Bridge Wallet.',
                                'error_code' => 'cards_enable_failed',
                                'help_url' => 'https://apidocs.bridge.xyz/platform/cards/sandbox/sandbox',
                            ], 500);
                        }
                    } else {
                        Log::info('Skipping legacy card_accounts gate — proceeding with Stripe Issuing', [
                            'customer_id' => $customerId,
                            'error' => $cardAccountsResult['error'] ?? null,
                        ]);
                        $useLegacyCardAccounts = false;
                    }
                }
            }

            if ($useLegacyCardAccounts && ($cardAccountsResult['success'] ?? false) && ! empty($cardAccountsResult['data'])) {
                $cardAccounts = is_array($cardAccountsResult['data']) && isset($cardAccountsResult['data']['data'])
                    ? $cardAccountsResult['data']['data']
                    : (is_array($cardAccountsResult['data']) ? $cardAccountsResult['data'] : []);

                if (! empty($cardAccounts) && is_array($cardAccounts)) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Card account already exists',
                        'data' => $cardAccounts[0],
                    ]);
                }
            }

            // Check if customer has date of birth (required for card accounts)
            // This follows the same logic as BridgeWebhookController::createWalletVirtualAccountAndCardAccount
            $customerResult = $this->bridgeService->getCustomer($customerId);
            $hasDateOfBirth = false;
            $hasRequiredFields = false;

            if ($customerResult['success'] && isset($customerResult['data'])) {
                $customerData = $customerResult['data'];

                $cardsEndorsementBlock = $this->resolveCardsEndorsementBlockResponse(
                    $customerId,
                    $integration,
                    $customerData
                );
                if ($cardsEndorsementBlock) {
                    return $cardsEndorsementBlock;
                }

                // For business customers, check associated persons (control person)
                if ($customerData['type'] === 'business') {
                    // Check if associated person (control person) has all required fields
                    // Bridge requires: first_name, last_name, and birth_date for card account creation
                    $associatedPersonsResult = $this->bridgeService->getAssociatedPersons($customerId);
                    $hasAssociatedPersonWithDob = false;

                    if ($associatedPersonsResult['success'] && !empty($associatedPersonsResult['data'])) {
                        foreach ($associatedPersonsResult['data'] as $associatedPerson) {
                            // Check if associated person has all required fields for card account
                            $hasBirthDate = !empty($associatedPerson['birth_date'] ?? null);
                            $hasFirstName = !empty($associatedPerson['first_name'] ?? null);
                            $hasLastName = !empty($associatedPerson['last_name'] ?? null);

                            if ($hasBirthDate && $hasFirstName && $hasLastName) {
                                $hasAssociatedPersonWithDob = true;
                                $hasRequiredFields = true;
                                $hasDateOfBirth = true;
                                break;
                            } elseif ($hasBirthDate) {
                                // Has birth_date but missing first_name or last_name
                                $hasAssociatedPersonWithDob = true;
                                $hasDateOfBirth = true; // We have birth_date, but missing other fields
                            }
                        }
                    }

                    // If no associated person with all required fields, try to get from database and update Bridge
                    if (!$hasRequiredFields) {
                        // Get control person from database
                        $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                            ->where('bridge_customer_id', $customerId)
                            ->first();

                        $birthDate = null;
                        $associatedPersonId = null;
                        $controlPerson = null;

                        if ($submission) {
                            // First try to get control person from control_persons table
                            $controlPerson = \App\Models\ControlPerson::where('bridge_kyc_kyb_submission_id', $submission->id)
                                ->whereNotNull('bridge_associated_person_id')
                                ->first();

                            if ($controlPerson) {
                                $associatedPersonId = $controlPerson->bridge_associated_person_id;

                                // Get birth_date if available - format same as individual customer
                                if ($controlPerson->birth_date) {
                                    // Format birth_date as YYYY-MM-DD string (same as individual customer)
                                    if ($controlPerson->birth_date instanceof \DateTime || $controlPerson->birth_date instanceof \Carbon\Carbon) {
                                        $birthDate = $controlPerson->birth_date->format('Y-m-d');
                                    } elseif (is_string($controlPerson->birth_date)) {
                                        try {
                                            $birthDateObj = new \DateTime($controlPerson->birth_date);
                                            $birthDate = $birthDateObj->format('Y-m-d');
                                        } catch (\Exception $e) {
                                            Log::warning('Failed to parse birth_date from control_person for card account', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'birth_date_string' => $controlPerson->birth_date,
                                                'error' => $e->getMessage(),
                                            ]);
                                            $birthDate = null;
                                        }
                                    } else {
                                        $birthDate = null;
                                    }
                                } else {
                                    // Fallback: Try to get birth_date from submission_data
                                    if ($submission->submission_data) {
                                        $submissionData = is_array($submission->submission_data)
                                            ? $submission->submission_data
                                            : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);

                                        // Check for control_person data in submission_data
                                        $controlPersonData = $submissionData['control_person'] ?? null;
                                        if ($controlPersonData && isset($controlPersonData['birth_date'])) {
                                            $birthDateRaw = $controlPersonData['birth_date'];

                                            // Format birth_date same as individual customer
                                            if (is_string($birthDateRaw)) {
                                                try {
                                                    $birthDateObj = new \DateTime($birthDateRaw);
                                                    $birthDate = $birthDateObj->format('Y-m-d');
                                                } catch (\Exception $e) {
                                                    Log::warning('Failed to parse birth_date from submission_data for control person', [
                                                        'integration_id' => $integration->id,
                                                        'customer_id' => $customerId,
                                                        'birth_date_string' => $birthDateRaw,
                                                        'error' => $e->getMessage(),
                                                    ]);
                                                    $birthDate = null;
                                                }
                                            } elseif ($birthDateRaw instanceof \DateTime || $birthDateRaw instanceof \Carbon\Carbon) {
                                                $birthDate = $birthDateRaw->format('Y-m-d');
                                            } else {
                                                $birthDate = null;
                                            }
                                        }
                                    }
                                }

                                // If we have associated_person_id and control person, update Bridge with all required fields
                                if ($associatedPersonId && $controlPerson) {
                                    // Prepare update data with all required fields for card account creation
                                    $updateData = [];

                                    // Add birth_date if available (required)
                                    if ($birthDate) {
                                        $updateData['birth_date'] = $birthDate;
                                    }

                                    // Add first_name if available (required for card accounts)
                                    if ($controlPerson->first_name) {
                                        $updateData['first_name'] = $controlPerson->first_name;
                                    }

                                    // Add last_name if available (required for card accounts)
                                    if ($controlPerson->last_name) {
                                        $updateData['last_name'] = $controlPerson->last_name;
                                    }

                                    // Only update if we have at least birth_date (minimum requirement)
                                    if (!empty($updateData) && isset($updateData['birth_date'])) {
                                        // Ensure birth_date is in YYYY-MM-DD format (same as individual customer)
                                        $formattedBirthDate = $updateData['birth_date'];
                                        if (is_string($formattedBirthDate)) {
                                            try {
                                                // Validate and ensure it's in YYYY-MM-DD format
                                                $dateObj = new \DateTime($formattedBirthDate);
                                                $formattedBirthDate = $dateObj->format('Y-m-d');
                                            } catch (\Exception $e) {
                                                Log::error('Invalid birth_date format for associated person update', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'associated_person_id' => $associatedPersonId,
                                                    'birth_date_raw' => $updateData['birth_date'],
                                                    'error' => $e->getMessage(),
                                                ]);
                                                $formattedBirthDate = null;
                                            }
                                        }
                                        
                                        if ($formattedBirthDate) {
                                            $updateData['birth_date'] = $formattedBirthDate;
                                            
                                            // Log before update
                                            Log::info('Updating associated person with birth_date for card account creation', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'associated_person_id' => $associatedPersonId,
                                                'update_data' => $updateData,
                                                'birth_date_format' => $formattedBirthDate,
                                                'birth_date_type' => gettype($formattedBirthDate),
                                            ]);
                                            
                                            // Update associated person in Bridge with all available required fields
                                            $updateResult = $this->bridgeService->updateAssociatedPerson(
                                                $customerId,
                                                $associatedPersonId,
                                                $updateData
                                            );

                                            if ($updateResult['success']) {
                                                $hasAssociatedPersonWithDob = true;

                                                // Check if we now have all required fields
                                                if (isset($updateData['first_name']) && isset($updateData['last_name'])) {
                                                    $hasRequiredFields = true;
                                                    $hasDateOfBirth = true;
                                                } else {
                                                    $hasDateOfBirth = true; // We have birth_date, but missing other fields
                                                }

                                                Log::info('Successfully updated associated person with required fields for card account', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'associated_person_id' => $associatedPersonId,
                                                    'birth_date' => $updateData['birth_date'],
                                                    'first_name' => $updateData['first_name'] ?? null,
                                                    'last_name' => $updateData['last_name'] ?? null,
                                                    'has_all_required_fields' => $hasRequiredFields,
                                                    'bridge_response' => $updateResult['data'] ?? null,
                                                ]);
                                                
                                                // Verify the update by fetching associated persons again
                                                $verifyResult = $this->bridgeService->getAssociatedPersons($customerId);
                                                if ($verifyResult['success'] && !empty($verifyResult['data'])) {
                                                    foreach ($verifyResult['data'] as $person) {
                                                        if (($person['id'] ?? null) === $associatedPersonId) {
                                                            Log::info('Verified associated person birth_date after update', [
                                                                'integration_id' => $integration->id,
                                                                'customer_id' => $customerId,
                                                                'associated_person_id' => $associatedPersonId,
                                                                'birth_date_in_bridge' => $person['birth_date'] ?? 'NOT SET',
                                                                'expected_birth_date' => $formattedBirthDate,
                                                            ]);
                                                            break;
                                                        }
                                                    }
                                                }
                                            } else {
                                                Log::error('Failed to update associated person with required fields', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'associated_person_id' => $associatedPersonId,
                                                    'update_data' => $updateData,
                                                    'error' => $updateResult['error'] ?? 'Unknown error',
                                                    'status_code' => $updateResult['status'] ?? null,
                                                    'bridge_response' => $updateResult['response'] ?? null,
                                                ]);
                                            }
                                        } else {
                                            Log::error('Cannot update associated person - invalid birth_date format', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'associated_person_id' => $associatedPersonId,
                                                'birth_date_raw' => $updateData['birth_date'] ?? null,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Final check: Ensure we have all required fields before creating card account
                    if (!$hasRequiredFields) {
                        $message = 'The control person must have first name, last name, and date of birth on file to create a card account. Please complete the KYC verification process first.';

                        return response()->json([
                            'success' => false,
                            'message' => $message,
                            'error_code' => 'missing_required_fields',
                            'requires_kyc' => true,
                        ], 400);
                    }
                } else {
                    // Individual: Bridge stores DOB on endorsements after hosted KYC, not always on birth_date.
                    $hasDateOfBirth = $this->bridgeService->customerHasDateOfBirth($customerData);

                    if (! $hasDateOfBirth) {
                        $birthDate = $this->resolveIndividualBirthDateForCardAccount($integration, $customerId, $user);

                        if ($birthDate) {
                            $updateResult = $this->bridgeService->updateCustomer($customerId, [
                                'birth_date' => $birthDate,
                            ]);

                            if ($updateResult['success']) {
                                $hasDateOfBirth = true;
                                Log::info('Updated individual customer with birth_date before card account creation', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'birth_date' => $birthDate,
                                ]);
                            }
                        }
                    }

                    if (! $hasDateOfBirth) {
                        return $this->buildCardsEndorsementKycRequiredResponse(
                            $customerId,
                            $integration,
                            'Please confirm your identity information—including date of birth—to issue your card.'
                        );
                    }
                }
            } else {
                    if ($isOrgUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The control person must have a date of birth on file to create a card account. Please complete the KYC verification process first.',
                        'error_code' => 'missing_date_of_birth',
                        'requires_kyc' => true,
                    ], 400);
                }

                return $this->buildCardsEndorsementKycRequiredResponse(
                    $customerId,
                    $integration,
                    'Please confirm your identity information—including date of birth—to issue your card.'
                );
            }

            return $this->issueVirtualCardResponse($integration, $customerId, $isOrgUser);

        } catch (\Exception $e) {
            Log::error('Create card account error', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create card account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get liquidation addresses for the user
     */
    public function getLiquidationAddresses(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bridge integration not found',
                ], 404);
            }

            // Always sync from Bridge so existing customer addresses are reused locally
            $this->syncLiquidationAddressesFromBridge($integration);

            $liquidationAddresses = LiquidationAddress::where('bridge_integration_id', $integration->id)
                ->whereNotNull('address')
                ->get();

            $formattedAddresses = $liquidationAddresses->map(
                fn (LiquidationAddress $address) => $this->formatLiquidationAddressPayload($address),
            );

            return response()->json([
                'success' => true,
                'data' => $formattedAddresses->values()->toArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Get liquidation addresses error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch liquidation addresses: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate QR code for a liquidation address
     */
    public function getLiquidationAddressQrCode(Request $request)
    {
        try {
            $user = Auth::user();
            $isOrgUser = $user->hasRole(['organization', 'organization_pending']);

            $entity = $isOrgUser ? $user->organization : $user;
            $entityType = $isOrgUser ? Organization::class : User::class;

            // Get integration
            $integration = BridgeIntegration::where('integratable_id', $entity->id)
                ->where('integratable_type', $entityType)
                ->first();

            if (!$integration || !$integration->bridge_customer_id) {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Bridge integration not found');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Get liquidation address ID from request
            $liquidationAddressId = $request->query('id');
            if (!$liquidationAddressId) {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Liquidation address ID required');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Try to get liquidation address from database first
            $liquidationAddress = LiquidationAddress::where('bridge_integration_id', $integration->id)
                ->where(function ($query) use ($liquidationAddressId) {
                    $query->where('bridge_liquidation_address_id', $liquidationAddressId)
                          ->orWhere('id', $liquidationAddressId);
                })
                ->first();

            // If not in database, fetch from Bridge API
            if (!$liquidationAddress) {
                $result = $this->bridgeService->getLiquidationAddresses($integration->bridge_customer_id);

                if (!$result['success']) {
                    $errorQr = QrCode::format('svg')
                        ->size(300)
                        ->margin(2)
                        ->generate('Error: Failed to fetch liquidation addresses');
                    return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
                }

                // Parse Bridge API response
                $liquidationAddresses = [];
                if (isset($result['data'])) {
                    $data = $result['data'];
                    $liquidationAddresses = is_array($data) && isset($data['data']) 
                        ? $data['data'] 
                        : (is_array($data) ? $data : []);
                }

                // Find the specific liquidation address
                $bridgeAddress = collect($liquidationAddresses)->firstWhere('id', $liquidationAddressId);

                if ($bridgeAddress) {
                    // Store in database for future use
                    $liquidationAddress = LiquidationAddress::updateOrCreate(
                        [
                            'bridge_integration_id' => $integration->id,
                            'bridge_liquidation_address_id' => $bridgeAddress['id'] ?? null,
                        ],
                        [
                            'bridge_customer_id' => $integration->bridge_customer_id,
                            'chain' => $bridgeAddress['chain'] ?? null,
                            'currency' => $bridgeAddress['currency'] ?? null,
                            'address' => $bridgeAddress['address'] ?? null,
                            'destination_payment_rail' => $bridgeAddress['destination_payment_rail'] ?? null,
                            'destination_currency' => $bridgeAddress['destination_currency'] ?? null,
                            'destination_address' => $bridgeAddress['destination_address'] ?? null,
                            'return_address' => $bridgeAddress['return_address'] ?? null,
                            'state' => $bridgeAddress['state'] ?? 'active',
                            'liquidation_metadata' => $bridgeAddress,
                            'last_sync_at' => now(),
                        ]
                    );
                }
            }

            if (!$liquidationAddress || !$liquidationAddress->address) {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Liquidation address not found');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            }

            // Generate QR code for the liquidation address
            $qrCode = QrCode::format('svg')
                ->size(300)
                ->margin(2)
                ->errorCorrection('M')
                ->generate($liquidationAddress->address);

            return response($qrCode, 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);
        } catch (\Exception $e) {
            Log::error('Generate liquidation address QR code error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            try {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Unable to generate QR code');
                return response($errorQr, 200, ['Content-Type' => 'image/svg+xml']);
            } catch (\Exception $e2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate QR code: ' . $e->getMessage(),
                ], 500);
            }
        }
    }

    /**
     * Block card issuance until Bridge cards endorsement is approved.
     *
     * @see https://apidocs.bridge.xyz/platform/cards/overview/kyc
     */
    /**
     * Issue a card via Stripe Issuing (Bridge sandbox integration) and map API response.
     */
    private function issueVirtualCardResponse(
        BridgeIntegration $integration,
        string $customerId,
        bool $isOrgUser,
    ): \Illuminate\Http\JsonResponse {
        $preferredPhone = $this->resolveIntegratablePhoneForCards($integration);
        $autoResult = $this->bridgeService->attemptCardsEndorsementAutoResolution($customerId, $preferredPhone);

        if ($autoResult['cards_already_approved'] ?? false) {
            Log::info('Cards endorsement approved after auto-resolution — issuing card', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
            ]);
        }

        $issueResult = $this->bridgeService->issueVirtualCard($integration, $customerId, $isOrgUser);

        if ($issueResult['success'] ?? false) {
            return response()->json([
                'success' => true,
                'message' => $issueResult['message'] ?? 'Card issued successfully',
                'data' => $issueResult['data'],
            ]);
        }

        $errorCode = $issueResult['error_code'] ?? '';

        if ($errorCode === 'cards_endorsement_required') {
            return $this->buildCardsEndorsementKycRequiredResponse(
                $customerId,
                $integration,
                $issueResult['message'] ?? 'Complete cards verification to issue your card.'
            );
        }

        return response()->json([
            'success' => false,
            'message' => $issueResult['message'] ?? 'Failed to issue card',
            'error_code' => $errorCode ?: 'card_issue_failed',
            'help_url' => $issueResult['help_url'] ?? null,
            'cards_endorsement_issues' => $issueResult['cards_endorsement_issues'] ?? null,
            'stripe_account_id' => $issueResult['stripe_account_id'] ?? null,
            'stripe_issuing_enabled' => $issueResult['stripe_issuing_enabled'] ?? null,
        ], $issueResult['http_status'] ?? 500);
    }

    private function resolveCardsEndorsementBlockResponse(
        string $customerId,
        BridgeIntegration $integration,
        array $customerData
    ): ?\Illuminate\Http\JsonResponse {
        if (! $this->bridgeService->customerNeedsCardsEndorsementFlow($customerData)) {
            return null;
        }

        $endorsementInfo = $this->bridgeService->getCardsEndorsementInfo($customerData);
        $issues = $endorsementInfo['endorsement']['requirements']['issues'] ?? [];
        $issues = is_array($issues) ? array_values(array_filter($issues, 'is_string')) : [];
        $status = strtolower((string) ($endorsementInfo['status'] ?? ''));

        if ($endorsementInfo['exists'] && ! $endorsementInfo['approved']) {
            if ($status === 'incomplete' || $issues !== []) {
                $message = $issues !== []
                    ? 'Complete cards verification to resolve: '.implode(', ', array_map(
                        fn (string $issue) => str_replace('_', ' ', $issue),
                        $issues,
                    ))
                    : 'Complete cards verification to issue your card.';

                return $this->buildCardsEndorsementKycRequiredResponse($customerId, $integration, $message);
            }

            if (in_array($status, ['pending', 'under_review'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The cards endorsement is pending approval. Card account will be created once approved. Please check your Bridge dashboard for endorsement status.',
                    'error_code' => 'cards_endorsement_not_approved',
                    'endorsement_status' => $endorsementInfo['status'],
                    'requires_approval' => true,
                ], 400);
            }
        }

        return $this->buildCardsEndorsementKycRequiredResponse(
            $customerId,
            $integration,
            'You must complete the cards endorsement verification. Please confirm your KYC information to proceed with card account creation.'
        );
    }

    private function buildCardsEndorsementKycRequiredResponse(
        string $customerId,
        BridgeIntegration $integration,
        string $message
    ): \Illuminate\Http\JsonResponse {
        Log::info('Getting KYC link for cards endorsement', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
            'environment' => $this->bridgeService->isSandbox() ? 'sandbox' : 'production',
        ]);

        $preferredPhone = $this->resolveIntegratablePhoneForCards($integration);

        $autoResult = $this->bridgeService->attemptCardsEndorsementAutoResolution($customerId, $preferredPhone);
        if ($autoResult['cards_already_approved'] ?? false) {
            $isOrgUser = $integration->integratable_type === Organization::class;

            return $this->issueVirtualCardResponse($integration, $customerId, $isOrgUser);
        }

        if ($this->bridgeService->cardsEndorsementBlockedByPhoneOnly($customerId)) {
            return response()->json([
                'success' => false,
                'message' => 'Cards verification needs a valid US phone number on your profile (E.164 format, e.g. +12025550100). Update your phone in account settings, then try again.',
                'error_code' => 'cards_endorsement_phone_required',
                'cards_endorsement_issues' => ['phone_number_invalid_format'],
            ], 400);
        }

        $redirectUri = url('/wallet/kyc-callback');

        $customerResult = $this->bridgeService->getCustomer($customerId);
        $baseApproved = $this->bridgeService->getBaseEndorsementInfo($customerResult['data'] ?? [])['approved'] ?? false;
        $kycLinkId = $baseApproved ? null : $integration->kyc_link_id;

        $kycLinkResult = $this->bridgeService->getCardsEndorsementKycLink(
            $customerId,
            $redirectUri,
            $kycLinkId,
            $preferredPhone,
        );

        if ($kycLinkResult['cards_already_approved'] ?? false) {
            $isOrgUser = $integration->integratable_type === Organization::class;

            return $this->issueVirtualCardResponse($integration, $customerId, $isOrgUser);
        }

        if ($kycLinkResult['phone_required'] ?? false) {
            return response()->json([
                'success' => false,
                'message' => 'Cards verification needs a valid US phone number on your profile (E.164 format, e.g. +12025550100). Update your phone in account settings, then try again.',
                'error_code' => 'cards_endorsement_phone_required',
                'cards_endorsement_issues' => $kycLinkResult['cards_endorsement_issues'] ?? ['phone_number_invalid_format'],
            ], 400);
        }

        if (! ($kycLinkResult['success'] ?? false) && ! $baseApproved && ! empty($integration->kyc_link_url)) {
            Log::warning('Using stored integration KYC link as cards endorsement fallback (base KYC not yet approved)', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'error' => $kycLinkResult['error'] ?? null,
            ]);

            $kycLinkResult = [
                'success' => true,
                'url' => $integration->kyc_link_url,
                'source' => 'integration_stored_kyc_link',
            ];
        }

        if ($kycLinkResult['success'] && ! empty($kycLinkResult['url'])) {
            Log::info('Cards endorsement KYC link retrieved successfully', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'kyc_link_url' => $kycLinkResult['url'],
                'source' => $kycLinkResult['source'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => 'cards_endorsement_kyc_required',
                'kyc_link_url' => $kycLinkResult['url'],
                'requires_kyc_confirmation' => true,
                'cards_endorsement_issues' => $kycLinkResult['cards_endorsement_issues'] ?? null,
            ], 400);
        }

        $issues = $kycLinkResult['cards_endorsement_issues']
            ?? $this->bridgeService->getCardsEndorsementIssues($customerId);

        Log::warning('Failed to get KYC link for cards endorsement', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
            'error' => $kycLinkResult['error'] ?? 'Unknown error',
            'cards_endorsement_issues' => $issues,
        ]);

        $failureMessage = 'Failed to get cards endorsement verification link. Please try again or contact support.';
        if ($issues !== []) {
            $failureMessage = 'Cards verification is blocked: '.implode(', ', array_map(
                fn (string $issue) => str_replace('_', ' ', $issue),
                $issues,
            )).'. Update your profile phone to E.164 format (e.g. +15551234567) and try again.';
        }

        return response()->json([
            'success' => false,
            'message' => $failureMessage,
            'error_code' => 'cards_endorsement_kyc_link_failed',
            'bridge_error' => $kycLinkResult['error'] ?? null,
            'cards_endorsement_issues' => $issues,
        ], 500);
    }

    private function resolveIntegratablePhoneForCards(BridgeIntegration $integration): ?string
    {
        $integration->loadMissing('integratable');

        $entity = $integration->integratable;
        if (! $entity) {
            return null;
        }

        $phone = $entity->contact_number ?? $entity->phone ?? null;

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    private function resolveIndividualBirthDateForCardAccount(
        BridgeIntegration $integration,
        string $customerId,
        User $user
    ): ?string {
        $submission = BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
            ->where('bridge_customer_id', $customerId)
            ->where('type', 'kyc')
            ->first();

        $birthDate = null;

        if ($submission && $submission->submission_data) {
            $submissionData = is_array($submission->submission_data)
                ? $submission->submission_data
                : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);

            $birthDate = $submissionData['birth_date'] ?? null;
        }

        if (! $birthDate && $user->dob) {
            $birthDate = $user->dob instanceof \DateTimeInterface
                ? $user->dob->format('Y-m-d')
                : (string) $user->dob;
        }

        if (! $birthDate) {
            return null;
        }

        if (is_string($birthDate)) {
            try {
                return (new \DateTime($birthDate))->format('Y-m-d');
            } catch (\Exception $e) {
                Log::warning('Failed to parse birth_date for card account sync', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'birth_date_string' => $birthDate,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($birthDate instanceof \DateTimeInterface) {
            return $birthDate->format('Y-m-d');
        }

        return null;
    }
}
