<?php

namespace App\Http\Controllers;

use App\Models\BridgeIntegration;
use App\Models\BridgeWallet;
use App\Models\CardWallet;
use App\Models\LiquidationAddress;
use App\Models\User;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\BridgeService;
use App\Services\BridgeVirtualAccountDepositService;
use App\Services\BridgeWalletNotifier;
use App\Services\BridgeWalletReadService;
use App\Services\WalletTransactionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BridgeWebhookController extends Controller
{
    protected BridgeService $bridgeService;

    protected WalletTransactionNotifier $walletTransactionNotifier;

    protected BridgeVirtualAccountDepositService $virtualAccountDepositService;

    protected BridgeWalletReadService $bridgeWalletReadService;

    protected BridgeWalletNotifier $bridgeWalletNotifier;

    public function __construct(
        BridgeService $bridgeService,
        WalletTransactionNotifier $walletTransactionNotifier,
        BridgeVirtualAccountDepositService $virtualAccountDepositService,
        BridgeWalletReadService $bridgeWalletReadService,
        BridgeWalletNotifier $bridgeWalletNotifier,
    ) {
        $this->bridgeService = $bridgeService;
        $this->walletTransactionNotifier = $walletTransactionNotifier;
        $this->virtualAccountDepositService = $virtualAccountDepositService;
        $this->bridgeWalletReadService = $bridgeWalletReadService;
        $this->bridgeWalletNotifier = $bridgeWalletNotifier;
    }

    /**
     * Handle Bridge webhooks
     */
    public function handle(Request $request)
    {
        // Log webhook received (before verification) - log all headers for debugging
        $allHeaders = $request->headers->all();
        Log::info('Bridge webhook received', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'has_x_webhook_signature' => $request->hasHeader('X-Webhook-Signature'),
            'has_bridgeapi_signature' => $request->hasHeader('BridgeApi-Signature'),
            'has_bridge_signature' => $request->hasHeader('Bridge-Signature'),
            'content_length' => $request->header('Content-Length'),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->header('User-Agent'),
            'ip' => $request->ip(),
            'app_env' => config('app.env'),
            'all_headers' => $allHeaders, // Log all headers to see what Bridge actually sends
        ]);

        // Verify webhook signature
        $signatureValid = $this->verifySignature($request);
        
        if (!$signatureValid) {
            Log::warning('Invalid Bridge webhook signature', [
                'ip' => $request->ip(),
                'app_env' => config('app.env'),
            ]);
            
            // In development, allow webhook to proceed even if signature fails
            if (config('app.env') === 'local' || config('app.env') === 'development') {
                Log::info('Allowing webhook in development environment despite signature failure');
            } else {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        Log::info('Bridge Webhook Received', [
            'event_type' => $payload['event_type'] ?? 'unknown',
            'event_category' => $payload['event_category'] ?? 'unknown',
            'event_id' => $payload['event_id'] ?? 'unknown',
            'event_object_id' => $payload['event_object']['id'] ?? null,
            'event_object_status' => $payload['event_object_status'] ?? null,
            'customer_id' => $payload['event_object']['id'] ?? null,
            'kyb_status' => $payload['event_object']['kyb_status'] ?? null,
            'kyc_status' => $payload['event_object']['kyc_status'] ?? null,
            'has_accepted_terms_of_service' => $payload['event_object']['has_accepted_terms_of_service'] ?? null,
            'signed_agreement_id' => $payload['event_object']['signed_agreement_id'] ?? null,
            'tos_status' => $payload['event_object']['tos_status'] ?? null,
            'full_payload' => json_encode($payload),
        ]);

        try {
            // Bridge webhook structure
            $eventType = $payload['event_type'] ?? null;
            $eventCategory = $payload['event_category'] ?? null;
            $eventObject = $payload['event_object'] ?? [];
            $eventObjectStatus = $payload['event_object_status'] ?? null;
            $eventObjectChanges = $payload['event_object_changes'] ?? [];
            $eventId = $payload['event_id'] ?? null;

            Log::info('Processing Bridge webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'event_category' => $eventCategory,
                'event_object_status' => $eventObjectStatus,
            ]);

            // Handle by event category
            switch ($eventCategory) {
                case 'customer':
                    $this->handleCustomerEvent($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                case 'kyc_link':
                    $this->handleKYCLinkEvent($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                case 'transfer':
                    $this->handleTransferEvent($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                case 'virtual_account.activity':
                    $this->handleVirtualAccountActivity($eventType, $eventObject, $eventObjectStatus);
                    break;

                case 'card_account':
                    $this->handleCardAccountEvent($eventType, $eventObject, $eventObjectStatus);
                    break;

                case 'card_transaction':
                    $this->handleCardTransactionEvent($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                case 'posted_card_account_transaction':
                    $this->handlePostedCardTransaction($eventType, $eventObject);
                    break;

                case 'card_withdrawal':
                    $this->handleCardWithdrawal($eventType, $eventObject, $eventObjectStatus);
                    break;

                case 'liquidation_address.drain':
                    $this->handleLiquidationAddressDrain($eventType, $eventObject, $eventObjectStatus);
                    break;

                case 'static_memo.activity':
                    $this->handleStaticMemoActivity($eventType, $eventObject, $eventObjectStatus);
                    break;

                case 'bridge_wallet.activity':
                    $this->handleBridgeWalletActivity($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                case 'wallet':
                    $this->handleWalletEvent($eventType, $eventObject, $eventObjectStatus, $eventObjectChanges);
                    break;

                default:
                    Log::warning('Unhandled Bridge webhook event category', [
                        'event_category' => $eventCategory,
                        'event_type' => $eventType,
                        'event_object' => $eventObject,
                    ]);
            }

            Log::info('Bridge webhook processed successfully', [
                'event_id' => $eventId ?? 'unknown',
                'event_category' => $eventCategory,
            ]);

            // Return 200 OK immediately to acknowledge receipt
            // Bridge expects a 200 response within a reasonable time
            return response()->json([
                'received' => true,
                'status' => 'success',
                'event_id' => $eventId ?? null,
            ], 200)->header('Content-Type', 'application/json');
        } catch (\Exception $e) {
            Log::error('Bridge webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event_id' => $payload['event_id'] ?? 'unknown',
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Verify webhook signature using RSA public key
     * Bridge signature format: BridgeApi-Signature or X-Webhook-Signature: t=<timestamp>,v0=<base64-encoded-signature>
     * Check multiple possible header names as Bridge documentation may vary
     */
    private function verifySignature(Request $request): bool
    {
        // Try multiple possible header names (Bridge documentation may use different names)
        $signatureHeader = $request->header('BridgeApi-Signature')
            ?? $request->header('X-Webhook-Signature')
            ?? $request->header('Bridge-Signature')
            ?? $request->header('X-Bridge-Signature');

        $payload = $request->getContent();
        
        // Load webhook public key from database (similar to BridgeService)
        $publicKeyPem = null;
        $bridgeConfig = \App\Models\PaymentMethod::getConfig('bridge');
        
        if ($bridgeConfig) {
            // Determine environment - check database first, then fallback to config/env
            $environment = null;
            if ($bridgeConfig->mode_environment) {
                $environment = trim(strtolower($bridgeConfig->mode_environment));
            } else {
                $environment = trim(strtolower(env('BRIDGE_ENVIRONMENT', config('services.bridge.environment', 'sandbox'))));
            }
            
            // Try to get from database based on environment
            if ($environment === 'sandbox' && !empty($bridgeConfig->sandbox_webhook_public_key)) {
                $publicKeyPem = $bridgeConfig->sandbox_webhook_public_key;
            } elseif ($environment === 'live' && !empty($bridgeConfig->live_webhook_public_key)) {
                $publicKeyPem = $bridgeConfig->live_webhook_public_key;
            }
        }
        
        // Fallback to config/env if not found in database
        if (!$publicKeyPem) {
            $publicKeyPem = config('services.bridge.webhook_public_key', env('BRIDGE_WEBHOOK_PUBLIC_KEY', ''));
        }

        if (!$signatureHeader) {
            if (config('app.env') === 'local') {
                Log::info('Bridge webhook received without signature (local environment)');
                return true;
            }
            Log::warning('Missing Bridge webhook signature header');
            return false;
        }

        if (!$publicKeyPem) {
            Log::warning('Missing Bridge webhook public key in config');
            return false;
        }

        // Parse signature header: t=timestamp,v0=signature
        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v0=')) {
                $signature = substr($part, 3);
            }
        }

        if (!$timestamp || !$signature) {
            Log::warning('Invalid Bridge webhook signature format');
            return false;
        }

        // Check timestamp (reject events older than 10 minutes)
        $currentTimeMs = (int)(microtime(true) * 1000);
        $timestampMs = (int)$timestamp;
        
        if (($currentTimeMs - $timestampMs) > 600000) { // 10 minutes in milliseconds
            Log::warning('Bridge webhook timestamp too old', [
                'timestamp' => $timestamp,
                'current_time' => $currentTimeMs,
            ]);
            return false;
        }

        // Create signed payload: timestamp.payload
        $signedPayload = $timestamp . '.' . $payload;

        try {
            // Decode the base64 signature
            $signatureBytes = base64_decode($signature, true);
            if ($signatureBytes === false) {
                Log::warning('Failed to decode Bridge webhook signature');
                return false;
            }

            // Parse the public key
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if ($publicKey === false) {
                $opensslError = openssl_error_string();
                Log::warning('Failed to parse Bridge webhook public key', [
                    'error' => $opensslError,
                    'public_key_preview' => substr($publicKeyPem, 0, 50) . '...',
                    'environment' => $environment ?? 'unknown',
                ]);
                
                // In development/local, allow webhook if public key is not configured
                if (config('app.env') === 'local' || config('app.env') === 'development') {
                    Log::info('Allowing webhook in development environment (public key not configured)');
                    return true;
                }
                return false;
            }

            // Bridge docs: verify RSA-SHA256 over "{timestamp}.{raw_body}"
            $result = openssl_verify($signedPayload, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256);

            if ($result === 1) {
                Log::info('Bridge webhook signature verified successfully');
                return true;
            } elseif ($result === 0) {
                Log::warning('Bridge webhook signature verification failed', [
                    'timestamp' => $timestamp,
                    'environment' => $environment ?? 'unknown',
                    'has_public_key' => !empty($publicKeyPem),
                ]);
                
                // In development/local, allow webhook if signature verification fails (for testing)
                if (config('app.env') === 'local' || config('app.env') === 'development') {
                    Log::info('Allowing webhook in development environment (signature verification failed)');
                    return true;
                }
                return false;
            } else {
                $opensslError = openssl_error_string();
                Log::warning('Bridge webhook signature verification error', [
                    'error' => $opensslError,
                    'environment' => $environment ?? 'unknown',
                ]);
                
                // In development/local, allow webhook on openssl errors (for testing)
                if (config('app.env') === 'local' || config('app.env') === 'development') {
                    Log::info('Allowing webhook in development environment (openssl error)');
                    return true;
                }
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Bridge webhook signature verification exception', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Handle customer events
     * 
     * Example webhook structure:
     * {
     *   "event_type": "customer.updated.status_transitioned",
     *   "event_object_status": "approved",
     *   "event_object": {
     *     "id": "cust_12345",
     *     "type": "business",
     *     "kyb_status": "approved",
     *     ...
     *   },
     *   "event_object_changes": {
     *     "kyb_status": ["under_review", "approved"]
     *   }
     * }
     */
    private function handleCustomerEvent(string $eventType, array $eventObject, ?string $status, array $changes)
    {
        $customerId = $eventObject['id'] ?? null;
        
        if (!$customerId) {
            Log::warning('Bridge customer event missing customer id', ['event_object' => $eventObject]);
            return;
        }

        $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();

        if (!$integration) {
            Log::warning('Bridge customer event: Integration not found', [
                'customer_id' => $customerId,
                'event_type' => $eventType,
                'event_object' => $eventObject,
            ]);
            return;
        }

        Log::info('Processing Bridge customer event', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
            'event_type' => $eventType,
            'current_kyb_status' => $integration->kyb_status,
            'current_kyc_status' => $integration->kyc_status,
            'event_object_kyb_status' => $eventObject['kyb_status'] ?? null,
            'event_object_kyc_status' => $eventObject['kyc_status'] ?? null,
            'event_object_changes' => $changes,
        ]);

        // Handle customer deletion
        // Bridge.xyz webhook format for deletion:
        // - event_type: "customer.deleted" or "customer.updated.status_transitioned" with status "deleted"
        // - event_object_status: "deleted" or "offboarded"
        // - event_object may contain: deleted_at, status: "deleted" or "offboarded"
        $isDeleted = false;
        
        // Check event type (e.g., "customer.deleted", "customer.updated.status_transitioned")
        if (str_contains(strtolower($eventType ?? ''), 'deleted')) {
            $isDeleted = true;
        }
        
        // Check event_object_status
        if (!$isDeleted && $status) {
            $statusLower = strtolower($status);
            if (in_array($statusLower, ['deleted', 'offboarded'])) {
                $isDeleted = true;
            }
        }
        
        // Check event_object fields
        if (!$isDeleted) {
            // Check for deleted_at timestamp
            if (isset($eventObject['deleted_at']) && !empty($eventObject['deleted_at'])) {
                $isDeleted = true;
            }
            
            // Check status field in event_object
            if (isset($eventObject['status'])) {
                $objectStatus = strtolower($eventObject['status']);
                if (in_array($objectStatus, ['deleted', 'offboarded'])) {
                    $isDeleted = true;
                }
            }
            
            // Check kyb_status or kyc_status for "offboarded"
            if (isset($eventObject['kyb_status']) && strtolower($eventObject['kyb_status']) === 'offboarded') {
                $isDeleted = true;
            }
            if (isset($eventObject['kyc_status']) && strtolower($eventObject['kyc_status']) === 'offboarded') {
                $isDeleted = true;
            }
        }

        if ($isDeleted) {
            $this->handleCustomerDeletion($integration, $customerId);
            return;
        }

        DB::transaction(function () use ($integration, $eventType, $eventObject, $status, $changes, $customerId) {
            $statusUpdated = false;
            $customerStatus = strtolower((string) ($eventObject['status'] ?? $status ?? ''));

            if (
                $customerStatus === 'not_started'
                && $integration->integratable_type !== Organization::class
                && $integration->kyc_status === 'approved'
            ) {
                $integration->kyc_status = 'not_started';
                $statusUpdated = true;

                Log::info('Reset stale KYC status for new Bridge customer', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'event_type' => $eventType,
                ]);
            }

            // Handle status transitions from event_object_changes
            if (!empty($changes)) {
                // Check for kyb_status change
                if (isset($changes['kyb_status']) && is_array($changes['kyb_status']) && count($changes['kyb_status']) >= 2) {
                    $newKybStatus = $this->normalizeStatus($changes['kyb_status'][1] ?? $eventObject['kyb_status'] ?? null);
                    if ($newKybStatus) {
                        $integration->kyb_status = $newKybStatus;
                        $statusUpdated = true;

                        // When KYB is approved, also set KYC status to approved (per user requirement)
                        if ($newKybStatus === 'approved' && $integration->kyc_status !== 'approved') {
                            $integration->kyc_status = 'approved';
                            Log::info('Bridge KYC status auto-set to approved when KYB was approved', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                            ]);
                        }
                        
                        Log::info('Bridge KYB status transitioned', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'from' => $changes['kyb_status'][0] ?? null,
                            'to' => $newKybStatus,
                        ]);
                    }
                }

                // Check for kyc_status change
                if (isset($changes['kyc_status']) && is_array($changes['kyc_status']) && count($changes['kyc_status']) >= 2) {
                    $newKycStatus = $this->normalizeStatus($changes['kyc_status'][1] ?? $eventObject['kyc_status'] ?? null);
                    if ($newKycStatus) {
                        $integration->kyc_status = $newKycStatus;
                        $statusUpdated = true;
                        
                        Log::info('Bridge KYC status transitioned', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'from' => $changes['kyc_status'][0] ?? null,
                            'to' => $newKycStatus,
                        ]);
                    }
                }
            }

            // Fallback: Update from event_object if changes not available
            // Also update if status exists in event_object (to ensure we always have latest status)
            // IMPORTANT: Process KYB first, then KYC, so that when KYB is approved, we can set KYC to approved
            // This ensures both are set correctly when Bridge sends both as approved

            // Update KYB status for business customers first
            if (isset($eventObject['kyb_status'])) {
                $newKybStatus = $this->normalizeStatus($eventObject['kyb_status']);
                if ($newKybStatus) {
                    $oldKybStatus = $integration->kyb_status;
                    if ($oldKybStatus !== $newKybStatus) {
                        $integration->kyb_status = $newKybStatus;
                        $statusUpdated = true;

                        // When KYB is approved, also set KYC status to approved (per user requirement)
                        // This ensures both are approved when KYB is approved
                        if ($newKybStatus === 'approved') {
                            $integration->kyc_status = 'approved';
                            Log::info('Bridge KYC status auto-set to approved when KYB was approved', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'previous_kyc_status' => $integration->getOriginal('kyc_status'),
                            ]);
                        }

                        Log::info('Bridge KYB status updated from event_object', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'old_status' => $oldKybStatus,
                            'new_status' => $newKybStatus,
                        ]);
                    }
                }
            }

            // Update KYC status (but don't override if we just set it to approved from KYB)
                if (isset($eventObject['kyc_status'])) {
                $newKycStatus = $this->normalizeStatus($eventObject['kyc_status']);
                if ($newKycStatus) {
                    $oldKycStatus = $integration->kyc_status;
                    // Only update if it's different and not already set to approved by KYB logic
                    if ($oldKycStatus !== $newKycStatus) {
                        $integration->kyc_status = $newKycStatus;
                        $statusUpdated = true;
                        
                        Log::info('Bridge KYC status updated from event_object', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'old_status' => $oldKycStatus,
                            'new_status' => $newKycStatus,
                        ]);
                    }
                }
                }

            // Handle TOS (Terms of Service) status from customer.updated events
            // Bridge sends has_accepted_terms_of_service or signed_agreement_id when TOS is accepted
            $hasAcceptedTos = $eventObject['has_accepted_terms_of_service'] ?? false;
            $signedAgreementId = $eventObject['signed_agreement_id'] ?? null;

            // Check for TOS status in changes
            if (!empty($changes)) {
                if (isset($changes['has_accepted_terms_of_service']) && is_array($changes['has_accepted_terms_of_service']) && count($changes['has_accepted_terms_of_service']) >= 2) {
                    $hasAcceptedTos = $changes['has_accepted_terms_of_service'][1] ?? $hasAcceptedTos;
                }
                if (isset($changes['signed_agreement_id']) && is_array($changes['signed_agreement_id']) && count($changes['signed_agreement_id']) >= 2) {
                    $signedAgreementId = $changes['signed_agreement_id'][1] ?? $signedAgreementId;
                }
            }

            // Check endorsements for TOS status
            $tosAcceptedFromEndorsements = false;
            if (isset($eventObject['endorsements']) && is_array($eventObject['endorsements'])) {
                foreach ($eventObject['endorsements'] as $endorsement) {
                    $complete = $endorsement['complete'] ?? [];
                    if (!empty($complete)) {
                        if (in_array('terms_of_service_v1', $complete) || in_array('terms_of_service_v2', $complete)) {
                            $tosAcceptedFromEndorsements = true;
                            break;
                        }
                    }
                }
            }

            // Update TOS status if TOS was accepted
            if ($hasAcceptedTos || $tosAcceptedFromEndorsements || $signedAgreementId) {
                $oldTosStatus = $integration->tos_status;
                $newTosStatus = BridgeIntegration::normalizeTosStatus('approved');

                if ($oldTosStatus !== $newTosStatus) {
                    $integration->tos_status = $newTosStatus;
                        $statusUpdated = true;
                        
                    Log::info('Bridge TOS status updated from customer.updated webhook', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                        'old_status' => $oldTosStatus,
                        'new_status' => $newTosStatus,
                        'has_accepted_terms_of_service' => $hasAcceptedTos,
                        'tos_accepted_from_endorsements' => $tosAcceptedFromEndorsements,
                        'signed_agreement_id' => $signedAgreementId,
                    ]);
                }

                // Store signed_agreement_id in metadata if provided
                if ($signedAgreementId) {
                    $metadata = $integration->bridge_metadata ?? [];
                    if (!is_array($metadata)) {
                        $metadata = is_string($metadata) ? json_decode($metadata, true) : [];
                    }
                    $metadata['signed_agreement_id'] = $signedAgreementId;
                    $integration->bridge_metadata = $metadata;
                }
            }

            // Update BridgeKycKybSubmission status if exists
            $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                ->where('bridge_customer_id', $customerId)
                ->first();
            
            if ($submission) {
                // Map Bridge customer status to submission status
                $submissionStatus = null;
                if (isset($eventObject['kyb_status'])) {
                    $submissionStatus = $this->normalizeStatus($eventObject['kyb_status']);
                } elseif (isset($eventObject['kyc_status'])) {
                    $submissionStatus = $this->normalizeStatus($eventObject['kyc_status']);
                }
                
                if ($submissionStatus && in_array($submissionStatus, ['approved', 'rejected', 'under_review', 'awaiting_ubo', 'awaiting_questionnaire', 'incomplete'])) {
                    $submission->submission_status = $submissionStatus;
                    $submission->save();
                    
                    Log::info('Bridge KYB/KYC submission status updated from webhook', [
                        'submission_id' => $submission->id,
                        'new_status' => $submissionStatus,
                    ]);
                }
            }

            // Store endorsements if available and process missing requirements
            if (isset($eventObject['endorsements'])) {
                $metadata = $integration->bridge_metadata ?? [];
                if (!is_array($metadata)) {
                    $metadata = is_string($metadata) ? json_decode($metadata, true) : [];
                }
                $metadata['endorsements'] = $eventObject['endorsements'];
                
                // Process missing requirements from endorsements
                $this->processMissingRequirements($integration, $eventObject['endorsements'], $customerId);
                
                $integration->bridge_metadata = $metadata;
            }

            // For business accounts: If customer status is "active" and endorsements are "approved", 
            // set kyb_status to "approved" (Bridge doesn't always send kyb_status directly)
            $isBusiness = $integration->integratable_type === Organization::class;
            if ($isBusiness) {
                $customerStatus = strtolower($eventObject['status'] ?? $status ?? '');
                $hasApprovedEndorsements = false;

                if (isset($eventObject['endorsements']) && is_array($eventObject['endorsements'])) {
                    foreach ($eventObject['endorsements'] as $endorsement) {
                        if (isset($endorsement['status']) && strtolower($endorsement['status']) === 'approved') {
                            $hasApprovedEndorsements = true;
                            break;
                        }
                    }
                }

                // If customer is active and has approved endorsements, KYB is approved
                if ($customerStatus === 'active' && $hasApprovedEndorsements && $integration->kyb_status !== 'approved') {
                    $integration->kyb_status = 'approved';
                    $statusUpdated = true;

                    Log::info('Bridge KYB status set to approved based on active customer status and approved endorsements', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'customer_status' => $customerStatus,
                        'previous_kyb_status' => $integration->getOriginal('kyb_status'),
                    ]);
                }
            }

            // Update metadata
            $metadata = $integration->bridge_metadata ?? [];
            $metadata['customer_update'] = [
                'event_type' => $eventType,
                'status' => $status,
                'changes' => $changes,
                'updated_at' => now()->toIso8601String(),
            ];
            $integration->bridge_metadata = $metadata;

            $integration->save();

            // Refresh integration to ensure we have the latest statuses from database
            $integration->refresh();

            // Auto-create wallet when account is approved
            // For businesses, ONLY kyb_status must be approved (not KYC)
            // For individuals, only kyc_status needs to be approved
            $isApproved = false;
            $isBusiness = $integration->integratable_type === Organization::class;
            
            if ($isBusiness) {
                // For businesses, ONLY KYB must be approved (not KYC)
                $isApproved = $integration->kyb_status === 'approved';

                Log::info('Checking if business account is approved for resource creation', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'kyb_status' => $integration->kyb_status,
                    'kyc_status' => $integration->kyc_status,
                    'is_approved' => $isApproved,
                ]);
            } else {
                // For individuals, check if KYC is approved
                $isApproved = $integration->kyc_status === 'approved';
            }

            if ($isApproved && $integration->bridge_customer_id) {
                $bridgeCustomerStatus = strtolower((string) ($eventObject['status'] ?? ''));

                if ($bridgeCustomerStatus !== 'active') {
                    Log::info('Bridge customer not active yet - skipping resource creation', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'bridge_customer_status' => $bridgeCustomerStatus ?: 'unknown',
                        'event_type' => $eventType,
                    ]);
                } else {
                    Log::info('Account is approved - triggering resource creation', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'is_business' => $isBusiness,
                        'kyb_status' => $integration->kyb_status,
                        'kyc_status' => $integration->kyc_status,
                    ]);

                    // Auto-create wallet, virtual account, card account, and liquidation address when approved
                    $this->createWalletVirtualAccountAndCardAccount($integration, $customerId);
                }
            } else {
                Log::info('Account not approved yet - skipping resource creation', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'is_business' => $isBusiness,
                    'kyb_status' => $integration->kyb_status,
                    'kyc_status' => $integration->kyc_status,
                    'has_customer_id' => !empty($integration->bridge_customer_id),
                ]);
            }

            Log::info('Bridge customer event processed', [
                'integration_id' => $integration->id,
                'event_type' => $eventType,
                'event_object_status' => $status,
                'customer_id' => $customerId,
                'kyb_status' => $integration->kyb_status,
                'kyc_status' => $integration->kyc_status,
            ]);
        });
    }

    /**
     * Handle KYC Link events
     * 
     * Example webhook structure for ToS acceptance:
     * {
     *   "event_type": "kyc_link.updated.status_transitioned",
     *   "event_object_status": "incomplete",
     *   "event_object": {
     *     "id": "3694522e-6bed-4660-a803-f599b50c7691",
     *     "type": "individual",
     *     "email": "user@example.com",
     *     "tos_status": "approved",
     *     "kyc_status": "incomplete",
     *     "customer_id": null
     *   },
     *   "event_object_changes": {
     *     "tos_status": ["pending", "approved"]
     *   }
     * }
     */
    private function handleKYCLinkEvent(string $eventType, array $eventObject, ?string $status, array $changes)
    {
        $linkId = $eventObject['id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $kycStatus = $eventObject['kyc_status'] ?? null;
        $tosStatus = $eventObject['tos_status'] ?? null;

        if (!$linkId) {
            Log::warning('Bridge KYC link event missing link id', ['event_object' => $eventObject]);
            return;
        }

        // Find integration by link_id or customer_id
        $integration = BridgeIntegration::where('kyc_link_id', $linkId)->first();
        
        if (!$integration && $customerId) {
            $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();
        }

        if (
            $integration
            && $customerId
            && $integration->bridge_customer_id
            && $integration->bridge_customer_id !== $customerId
        ) {
            Log::warning('Bridge KYC link event: customer_id mismatch — skipping stale webhook', [
                'integration_id' => $integration->id,
                'integration_customer_id' => $integration->bridge_customer_id,
                'event_customer_id' => $customerId,
                'link_id' => $linkId,
                'event_type' => $eventType,
            ]);

            return;
        }

        if (str_contains(strtolower($eventType), 'deleted')) {
            Log::info('Bridge KYC link deleted — skipping status sync and resource creation', [
                'link_id' => $linkId,
                'customer_id' => $customerId,
                'event_type' => $eventType,
            ]);

            return;
        }

        // Also try to find by email if available (for KYC links created before customer_id is assigned)
        if (!$integration && isset($eventObject['email'])) {
            $email = $eventObject['email'];
            // Try to find via user or organization email
            $user = \App\Models\User::where('email', $email)->first();
            if ($user) {
                $integration = BridgeIntegration::where('integratable_type', \App\Models\User::class)
                    ->where('integratable_id', $user->id)
                    ->first();
            } else {
                $organization = \App\Models\Organization::where('email', $email)->first();
                if ($organization) {
                    $integration = BridgeIntegration::where('integratable_type', \App\Models\Organization::class)
                        ->where('integratable_id', $organization->id)
                        ->first();
                }
            }
        }

        if (!$integration) {
            Log::warning('Bridge KYC link event: Integration not found', [
                'link_id' => $linkId,
                'customer_id' => $customerId,
                'email' => $eventObject['email'] ?? null,
            ]);
            return;
        }

        DB::transaction(function () use ($integration, $eventType, $eventObject, $kycStatus, $tosStatus, $status, $changes, $linkId, $customerId) {
            $statusUpdated = false;

            // Handle ToS status transition from event_object_changes
            if (!empty($changes) && isset($changes['tos_status']) && is_array($changes['tos_status']) && count($changes['tos_status']) >= 2) {
                $newTosStatus = $this->normalizeTosStatus($changes['tos_status'][1] ?? $eventObject['tos_status'] ?? null);
                if ($newTosStatus) {
                    $integration->tos_status = $newTosStatus;
                    $statusUpdated = true;
                    
                    Log::info('Bridge ToS status transitioned via KYC link webhook', [
                        'integration_id' => $integration->id,
                        'link_id' => $linkId,
                        'from' => $changes['tos_status'][0] ?? null,
                        'to' => $newTosStatus,
                    ]);
                }
            } elseif ($tosStatus) {
                // Fallback: Update from event_object if changes not available
                $integration->tos_status = $this->normalizeTosStatus($tosStatus);
                $statusUpdated = true;
            }

            // Handle KYC status transition from event_object_changes
            if (!empty($changes) && isset($changes['kyc_status']) && is_array($changes['kyc_status']) && count($changes['kyc_status']) >= 2) {
                $newKycStatus = $this->normalizeStatus($changes['kyc_status'][1] ?? $eventObject['kyc_status'] ?? null);
                if ($newKycStatus) {
                    $integration->kyc_status = $newKycStatus;
                    $statusUpdated = true;
                    
                    Log::info('Bridge KYC status transitioned via KYC link webhook', [
                        'integration_id' => $integration->id,
                        'link_id' => $linkId,
                        'from' => $changes['kyc_status'][0] ?? null,
                        'to' => $newKycStatus,
                    ]);
                }
            } elseif ($kycStatus) {
                // Fallback: Update from event_object if changes not available
                $oldKycStatus = $integration->kyc_status;
                $newKycStatus = $this->normalizeStatus($kycStatus);
                if ($oldKycStatus !== $newKycStatus) {
                    $integration->kyc_status = $newKycStatus;
                $statusUpdated = true;
                    
                    Log::info('Bridge KYC status updated from event_object (KYC link)', [
                        'integration_id' => $integration->id,
                        'link_id' => $linkId,
                        'old_status' => $oldKycStatus,
                        'new_status' => $newKycStatus,
                    ]);
                }
            }
            
            // Also check for KYB status in event_object (for business customers)
            if (isset($eventObject['kyb_status'])) {
                $newKybStatus = $this->normalizeStatus($eventObject['kyb_status']);
                if ($newKybStatus) {
                    $oldKybStatus = $integration->kyb_status;
                    if ($oldKybStatus !== $newKybStatus) {
                        $integration->kyb_status = $newKybStatus;
                        $statusUpdated = true;
                        
                        Log::info('Bridge KYB status updated from KYC link event_object', [
                            'integration_id' => $integration->id,
                            'link_id' => $linkId,
                            'old_status' => $oldKybStatus,
                            'new_status' => $newKybStatus,
                        ]);
                    }
                }
            }

            // Store customer_id if newly created
            if (isset($eventObject['customer_id']) && !$integration->bridge_customer_id) {
                $integration->bridge_customer_id = $eventObject['customer_id'];
            }

            // Store kyc_link_id if not set
            if ($linkId && !$integration->kyc_link_id) {
                $integration->kyc_link_id = $linkId;
            }

            // Update BridgeKycKybSubmission status if exists and KYC status changed
            if ($statusUpdated && $integration->bridge_customer_id) {
                $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                    ->where('bridge_customer_id', $integration->bridge_customer_id)
                    ->first();
                
                if ($submission && $kycStatus) {
                    $submissionStatus = $this->normalizeStatus($kycStatus);
                    if (in_array($submissionStatus, ['approved', 'rejected', 'under_review', 'awaiting_ubo', 'awaiting_questionnaire', 'incomplete'])) {
                        $submission->submission_status = $submissionStatus;
                        $submission->save();
                        
                        Log::info('Bridge KYB/KYC submission status updated from KYC link webhook', [
                            'submission_id' => $submission->id,
                            'new_status' => $submissionStatus,
                        ]);
                    }
                }
            }

            // Update metadata
            $metadata = $integration->bridge_metadata ?? [];
            $metadata['kyc_link_update'] = [
                'event_type' => $eventType,
                'event_object_status' => $status,
                'kyc_status' => $kycStatus,
                'tos_status' => $tosStatus,
                'changes' => $changes,
                'updated_at' => now()->toIso8601String(),
            ];
            $integration->bridge_metadata = $metadata;

            $integration->save();

            // Auto-create wallet when account is approved
            // For businesses, ONLY kyb_status must be approved (not KYC)
            // For individuals, only kyc_status needs to be approved
            $isApproved = false;
            $isBusiness = $integration->integratable_type === Organization::class;
            
            if ($isBusiness) {
                // For businesses, ONLY KYB must be approved (not KYC)
                $isApproved = $integration->kyb_status === 'approved';
            } else {
                // For individuals, check if KYC is approved
                $isApproved = $integration->kyc_status === 'approved';
            }

            if ($isApproved && $integration->bridge_customer_id) {
                // Auto-create wallet, virtual account, and card account when approved
                $this->createWalletVirtualAccountAndCardAccount($integration, $customerId, $linkId);
            }

            Log::info('Bridge KYC link event processed', [
                'integration_id' => $integration->id,
                'event_type' => $eventType,
                'event_object_status' => $status,
                'link_id' => $linkId,
                'customer_id' => $customerId,
                'kyc_status' => $integration->kyc_status,
                'tos_status' => $integration->tos_status,
            ]);
        });
    }

    /**
     * Handle transfer events
     * 
     * Per Bridge.xyz docs: Transfer events track the state of money transfers.
     * Terminal state for crediting recipients: payment_processed (funds_received is in-flight).
     */
    private function handleTransferEvent(string $eventType, array $eventObject, ?string $status, array $changes)
    {
        $transferId = $eventObject['id'] ?? null;
        $state = $eventObject['state'] ?? $status ?? $eventObject['status'] ?? null;

        if (!$transferId) {
            Log::warning('Bridge transfer event missing transfer id', ['event_object' => $eventObject]);
            return;
        }

        Log::info('Bridge transfer event received', [
            'transfer_id' => $transferId,
            'state' => $state,
            'event_type' => $eventType,
            'event_object_status' => $status,
        ]);

        $customerId = $eventObject['on_behalf_of']
            ?? $eventObject['customer_id']
            ?? null;
        $destination = is_array($eventObject['destination'] ?? null) ? $eventObject['destination'] : [];

        // Always notify Bridge wallet users in real time (sender + recipient).
        $this->bridgeWalletNotifier->notifyTransferWebhook($eventObject, $state, $eventType);

        if (is_string($transferId) && is_string($state)) {
            app(\App\Services\BelievePointsToBridgeWalletService::class)
                ->syncFromBridgeTransfer($transferId, $state);

            \App\Services\BelievePointBridgeReserveSettlementService::recordTransferCredit($eventObject, $state);
        }

        $integration = $this->findIntegrationByCustomerId($customerId);
        $recipientIntegration = $this->bridgeWalletNotifier->resolveIntegrationForWalletEndpoint($destination);

        if ($this->shouldUseBridgeApiOnly($integration) || $this->shouldUseBridgeApiOnly($recipientIntegration)) {
            return;
        }

        // Find transactions by Bridge transfer ID
        $transactions = Transaction::where(function ($query) use ($transferId) {
            $query->whereJsonContains('meta->bridge_transfer_id', $transferId)
                  ->orWhereJsonContains('meta->transfer_id', $transferId);
        })->get();

        if ($transactions->isEmpty()) {
            // Try to find by source/destination wallet IDs from transfer object
            $sourceWalletId = $eventObject['source']['bridge_wallet_id'] ?? null;
            $destinationWalletId = $eventObject['destination']['bridge_wallet_id'] ?? null;
            
            if ($sourceWalletId || $destinationWalletId) {
                // Try to find transactions by wallet IDs
                $transactions = Transaction::where(function ($query) use ($sourceWalletId, $destinationWalletId) {
                    if ($sourceWalletId) {
                        $query->whereJsonContains('meta->bridge_wallet_id', $sourceWalletId);
                    }
                    if ($destinationWalletId) {
                        $query->orWhereJsonContains('meta->bridge_wallet_id', $destinationWalletId);
                    }
                })->where('created_at', '>=', now()->subHours(24)) // Within last 24 hours
                  ->get();
            }

            if ($transactions->isEmpty()) {
                Log::warning('Bridge transfer event: Transactions not found', [
                    'transfer_id' => $transferId,
                    'source_wallet_id' => $sourceWalletId,
                    'destination_wallet_id' => $destinationWalletId,
                ]);
                return;
            }
        }

        $mappedStatus = $this->mapBridgeTransferStateToStatus($state);

        DB::transaction(function () use ($transactions, $mappedStatus, $state, $transferId, $eventObject, $changes, $eventType) {
            foreach ($transactions as $transaction) {
                $oldStatus = $transaction->status;
                $transaction->status = $mappedStatus;

                // Handle completed transfers - add balance to recipient
                if ($mappedStatus === 'completed' && $oldStatus !== 'completed') {
                    $transaction->processed_at = now();

                    $user = $transaction->user;
                    $amount = (float) $transaction->amount;
                    $skipLedger = $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth(
                        $this->resolveUserBridgeIntegration($user)
                    );

                    if ($transaction->type === 'transfer_in') {
                        // Only add balance if not already added (check if processed_at was null)
                        if (! $skipLedger && ! $transaction->getOriginal('processed_at')) {
                            $user->increment('balance', $amount);
                            Log::info('Bridge transfer completed: Balance added to recipient', [
                                'user_id' => $user->id,
                                'amount' => $amount,
                                'transaction_id' => $transaction->id,
                                'transfer_id' => $transferId,
                            ]);
                        }
                    } elseif ($transaction->type === 'transfer_out') {
                        // Mark sender transaction as completed
                        Log::info('Bridge transfer completed: Sender transaction confirmed', [
                            'user_id' => $user->id,
                            'amount' => $amount,
                            'transaction_id' => $transaction->id,
                            'transfer_id' => $transferId,
                        ]);
                    }
                }

                // Handle failed/cancelled transfers - refund sender
                if (in_array($mappedStatus, ['failed', 'cancelled']) && !in_array($oldStatus, ['failed', 'cancelled'])) {
                    if ($transaction->type === 'transfer_out') {
                        $user = $transaction->user;
                        $amount = (float) $transaction->amount;
                        $fee = (float) ($transaction->fee ?? 0);
                        $skipLedger = $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth(
                            $this->resolveUserBridgeIntegration($user)
                        );
                        
                        // Refund amount + fee to sender
                        if (! $skipLedger) {
                        $user->increment('balance', $amount + $fee);
                        }
                        
                        Log::info('Bridge transfer failed/cancelled: Balance refunded to sender', [
                            'user_id' => $user->id,
                            'amount' => $amount,
                            'fee' => $fee,
                            'total_refund' => $amount + $fee,
                            'transaction_id' => $transaction->id,
                            'transfer_id' => $transferId,
                            'state' => $state,
                        ]);
                    } elseif ($transaction->type === 'transfer_in') {
                        // Remove balance from recipient if transfer failed
                        $user = $transaction->user;
                        $amount = (float) $transaction->amount;
                        $skipLedger = $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth(
                            $this->resolveUserBridgeIntegration($user)
                        );
                        
                        // Only deduct if balance was previously added
                        if (! $skipLedger && $transaction->getOriginal('processed_at')) {
                            $user->decrement('balance', $amount);
                            Log::info('Bridge transfer failed: Balance removed from recipient', [
                                'user_id' => $user->id,
                                'amount' => $amount,
                                'transaction_id' => $transaction->id,
                                'transfer_id' => $transferId,
                            ]);
                        }
                    }
                }

                // Update metadata with latest Bridge transfer information
                $meta = $transaction->meta ?? [];
                $meta['bridge_state'] = $state;
                $meta['bridge_status'] = $state; // Alias for compatibility
                $meta['bridge_update_at'] = now()->toIso8601String();
                $meta['bridge_changes'] = $changes;
                $meta['bridge_event_type'] = $eventType;
                
                // Store receipt if available
                if (isset($eventObject['receipt'])) {
                    $meta['bridge_receipt'] = $eventObject['receipt'];
                }

                // Store source and destination info
                if (isset($eventObject['source'])) {
                    $meta['bridge_source'] = $eventObject['source'];
                }
                if (isset($eventObject['destination'])) {
                    $meta['bridge_destination'] = $eventObject['destination'];
                }

                $transaction->meta = $meta;
                $transaction->save();

                if ($mappedStatus === 'completed' && $oldStatus !== 'completed' && $transaction->user) {
                    $this->walletTransactionNotifier->notify($transaction->user, $transaction);
                }
            }

            Log::info('Bridge transfer event processed', [
                'transfer_id' => $transferId,
                'state' => $state,
                'mapped_status' => $mappedStatus,
                'transaction_count' => $transactions->count(),
                'event_type' => $eventType,
                'transaction_ids' => $transactions->pluck('id')->toArray(),
                'transaction_statuses' => $transactions->pluck('status')->unique()->toArray(),
            ]);
        });
    }

    /**
     * Handle virtual account activity events
     * 
     * Per Bridge.xyz docs: Virtual account activity includes deposits and other events
     * Event types: payment_submitted, payment_processed, etc.
     */
    private function handleVirtualAccountActivity(string $eventType, array $eventObject, ?string $eventObjectStatus = null): void
    {
        $activityId = $eventObject['id'] ?? null;
        $virtualAccountId = $eventObject['virtual_account_id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $activityType = $this->virtualAccountDepositService->resolveActivityType($eventObject, $eventObjectStatus);

        Log::info('Bridge virtual account activity', [
            'activity_id' => $activityId,
            'virtual_account_id' => $virtualAccountId,
            'customer_id' => $customerId,
            'activity_type' => $activityType,
            'amount' => $eventObject['amount'] ?? null,
            'event_type' => $eventType,
            'event_object_status' => $eventObjectStatus,
        ]);

        if (! $customerId) {
            Log::warning('Bridge virtual account activity missing customer_id', [
                'activity_id' => $activityId,
                'virtual_account_id' => $virtualAccountId,
            ]);

            return;
        }

        $integration = $this->findIntegrationByCustomerId($customerId);
        if (! $integration) {
            Log::warning('Bridge integration not found for virtual account activity', [
                'customer_id' => $customerId,
                'virtual_account_id' => $virtualAccountId,
            ]);

            return;
        }

        if ($this->shouldUseBridgeApiOnly($integration)) {
            $this->bridgeWalletNotifier->notifyVirtualAccountActivity($integration, $eventObject, $activityType);

            return;
        }

        if (in_array($activityType, ['refunded', 'refund_failed'], true)) {
            $this->reverseVirtualAccountDeposit($integration, $eventObject, $eventType);

            return;
        }

        try {
            $this->virtualAccountDepositService->processVirtualAccountActivity(
                $integration,
                $eventObject,
                $eventType,
                $eventObjectStatus,
            );
        } catch (\Exception $e) {
            Log::error('Exception processing virtual account activity', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'activity_type' => $activityType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle card account events
     * 
     * Per Bridge.xyz docs: Card account events track card account status changes
     * Event types: card_account.created, card_account.updated, card_account.status_transitioned
     */
    private function handleCardAccountEvent(string $eventType, array $eventObject, ?string $status)
    {
        $cardAccountId = $eventObject['id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $cardStatus = $eventObject['status'] ?? $status;

        Log::info('Bridge card account event', [
            'card_account_id' => $cardAccountId,
            'customer_id' => $customerId,
            'status' => $cardStatus,
            'event_type' => $eventType,
        ]);

        // Update card wallet in database if exists
        if ($cardAccountId && $customerId) {
            try {
                $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();
                
                if ($integration) {
                    $cardWallet = CardWallet::where('bridge_integration_id', $integration->id)
                        ->where('bridge_card_account_id', $cardAccountId)
                        ->first();

                    if ($cardWallet) {
                        // Update card wallet with latest data from Bridge
                        $cardWallet->update([
                            'status' => $cardStatus ?? $cardWallet->status,
                            'card_number' => $eventObject['card_number'] ?? $eventObject['last_four'] ?? $cardWallet->card_number,
                            'card_type' => $eventObject['type'] ?? $eventObject['card_type'] ?? $cardWallet->card_type,
                            'card_brand' => $eventObject['brand'] ?? $eventObject['card_brand'] ?? $cardWallet->card_brand,
                            'expiry_month' => $eventObject['expiry_month'] ?? $eventObject['exp_month'] ?? $cardWallet->expiry_month,
                            'expiry_year' => $eventObject['expiry_year'] ?? $eventObject['exp_year'] ?? $cardWallet->expiry_year,
                            'balance' => $eventObject['balance'] ?? $eventObject['available_balance'] ?? $cardWallet->balance,
                            'currency' => $eventObject['currency'] ?? $cardWallet->currency,
                            'card_metadata' => $eventObject, // Store full response
                            'last_balance_sync' => now(),
                        ]);

                        Log::info('Bridge card account updated in database', [
                            'card_wallet_id' => $cardWallet->id,
                            'card_account_id' => $cardAccountId,
                            'status' => $cardStatus,
                        ]);
                    } else {
                        // Card wallet doesn't exist, create it
                        $cardWallet = CardWallet::create([
                            'bridge_integration_id' => $integration->id,
                            'bridge_customer_id' => $customerId,
                            'bridge_card_account_id' => $cardAccountId,
                            'card_number' => $eventObject['card_number'] ?? $eventObject['last_four'] ?? null,
                            'card_type' => $eventObject['type'] ?? $eventObject['card_type'] ?? null,
                            'card_brand' => $eventObject['brand'] ?? $eventObject['card_brand'] ?? null,
                            'expiry_month' => $eventObject['expiry_month'] ?? $eventObject['exp_month'] ?? null,
                            'expiry_year' => $eventObject['expiry_year'] ?? $eventObject['exp_year'] ?? null,
                            'status' => $cardStatus ?? 'active',
                            'balance' => $eventObject['balance'] ?? $eventObject['available_balance'] ?? 0,
                            'currency' => $eventObject['currency'] ?? 'USD',
                            'card_metadata' => $eventObject,
                            'is_primary' => true,
                            'last_balance_sync' => now(),
                        ]);

                        Log::info('Bridge card account created in database from webhook', [
                            'card_wallet_id' => $cardWallet->id,
                            'card_account_id' => $cardAccountId,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception handling card account event', [
                    'card_account_id' => $cardAccountId,
                    'customer_id' => $customerId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Handle card transaction events
     */
    private function handleCardTransactionEvent(string $eventType, array $eventObject, ?string $status, array $changes)
    {
        $transactionId = $eventObject['id'] ?? null;
        $cardAccountId = $eventObject['card_account_id'] ?? null;
        $amount = $eventObject['amount'] ?? null;
        $merchantName = $eventObject['merchant_name'] ?? null;

        Log::info('Bridge card transaction event', [
            'transaction_id' => $transactionId,
            'card_account_id' => $cardAccountId,
            'status' => $status,
            'amount' => $amount,
            'merchant_name' => $merchantName,
            'event_type' => $eventType,
        ]);

        // Implement your card transaction handling logic here
    }

    /**
     * Handle posted card account transaction events
     * 
     * Per Bridge.xyz docs: Posted transactions are finalized card transactions
     */
    private function handlePostedCardTransaction(string $eventType, array $eventObject)
    {
        $transactionId = $eventObject['id'] ?? null;
        $cardAccountId = $eventObject['card_account_id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $amount = $eventObject['amount'] ?? null;
        $status = $eventObject['status'] ?? null;

        Log::info('Bridge posted card transaction event', [
            'transaction_id' => $transactionId,
            'card_account_id' => $cardAccountId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'status' => $status,
            'event_type' => $eventType,
        ]);

        // Update card wallet balance when transaction is posted
        if ($cardAccountId && $customerId) {
            try {
                $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();
                
                if ($integration) {
                    $cardWallet = CardWallet::where('bridge_integration_id', $integration->id)
                        ->where('bridge_card_account_id', $cardAccountId)
                        ->first();

                    if ($cardWallet) {
                        // Update balance from posted transaction
                        if (isset($eventObject['available_balance']) || isset($eventObject['balance'])) {
                            $newBalance = $eventObject['available_balance'] ?? $eventObject['balance'] ?? $cardWallet->balance;
                            $cardWallet->update([
                                'balance' => $newBalance,
                                'last_balance_sync' => now(),
                            ]);
                        }

                        Log::info('Bridge card wallet balance updated from posted transaction', [
                            'card_wallet_id' => $cardWallet->id,
                            'transaction_id' => $transactionId,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception handling posted card transaction', [
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle card withdrawal events
     */
    private function handleCardWithdrawal(string $eventType, array $eventObject, ?string $status)
    {
        Log::info('Bridge card withdrawal event', [
            'event_type' => $eventType,
            'status' => $status,
            'event_object' => $eventObject,
        ]);

        // Implement your card withdrawal handling logic here
    }

    /**
     * Handle liquidation address drain events
     * 
     * Per Bridge.xyz docs: Liquidation address drain events track funds moved from liquidation addresses
     */
    private function handleLiquidationAddressDrain(string $eventType, array $eventObject, ?string $status)
    {
        $drainId = $eventObject['id'] ?? null;
        $liquidationAddressId = $eventObject['liquidation_address_id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $amount = $eventObject['amount'] ?? null;

        Log::info('Bridge liquidation address drain event', [
            'event_type' => $eventType,
            'drain_id' => $drainId,
            'liquidation_address_id' => $liquidationAddressId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'status' => $status,
        ]);

        // Implement liquidation address drain handling if needed
        // This typically involves updating balances or recording transactions
    }

    /**
     * Handle static memo activity events
     * 
     * Per Bridge.xyz docs: Static memo activity tracks deposits to static memo addresses
     */
    private function handleStaticMemoActivity(string $eventType, array $eventObject, ?string $status)
    {
        $activityId = $eventObject['id'] ?? null;
        $staticMemoId = $eventObject['static_memo_id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $amount = $eventObject['amount'] ?? null;
        $type = $eventObject['type'] ?? null;

        Log::info('Bridge static memo activity event', [
            'activity_id' => $activityId,
            'static_memo_id' => $staticMemoId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'type' => $type,
            'status' => $status,
            'event_type' => $eventType,
        ]);

        $integration = $this->findIntegrationByCustomerId($customerId);
        if ($this->shouldUseBridgeApiOnly($integration)) {
            $this->acknowledgeBridgeWalletWebhook('static_memo.activity', $integration, [
                'activity_id' => $activityId,
                'type' => $type,
            ]);

            return;
        }

        // Handle static memo deposits similar to virtual account deposits
        if (in_array($type, ['refund', 'refunded'], true) && $customerId) {
            try {
                if ($integration) {
                    $this->reverseStaticMemoDeposit($integration, $eventObject, $eventType);
                }
            } catch (\Exception $e) {
                Log::error('Exception processing static memo refund', [
                    'customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        if ($type === 'payment_processed' && $customerId && $amount) {
            try {
                if ($integration) {
                    $user = $integration->integratable;
                    if ($user && $integration->integratable_type === Organization::class) {
                        $user = $user->user ?? null;
                    }

                    if ($user) {
                        $depositAmount = (float) $amount;
                        $depositId = $eventObject['deposit_id'] ?? null;

                        $existingQuery = Transaction::where('user_id', $user->id)
                            ->where('type', 'deposit')
                            ->where('status', 'completed');

                        if ($depositId) {
                            $existingQuery->whereJsonContains('meta->deposit_id', $depositId);
                        } else {
                            $existingQuery
                                ->whereJsonContains('meta->static_memo_id', $staticMemoId)
                                ->whereJsonContains('meta->activity_id', $activityId);
                        }

                        $existingTransaction = $existingQuery->first();

                        if (! $existingTransaction && ! $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth($integration)) {
                            $user->increment('balance', $depositAmount);

                            $depositTransaction = $user->recordTransaction([
                                'type' => 'deposit',
                                'amount' => $depositAmount,
                                'status' => 'completed',
                                'payment_method' => 'bridge',
                                'meta' => [
                                    'static_memo_id' => $staticMemoId,
                                    'activity_id' => $activityId,
                                    'deposit_id' => $depositId,
                                    'customer_id' => $customerId,
                                    'bridge_event_type' => $eventType,
                                ],
                                'processed_at' => now(),
                            ]);

                            $this->walletTransactionNotifier->notify($user, $depositTransaction);

                            Log::info('Bridge static memo deposit processed', [
                                'user_id' => $user->id,
                                'amount' => $depositAmount,
                                'static_memo_id' => $staticMemoId,
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception processing static memo deposit', [
                    'customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle bridge_wallet.activity events (wallet transaction history).
     *
     * Event types: bridge_wallet.activity.created | bridge_wallet.activity.updated
     * event_object matches GET /customers/{id}/wallets/{id}/history entries.
     */
    private function handleBridgeWalletActivity(string $eventType, array $eventObject, ?string $status, array $changes): void
    {
        $activityId = $eventObject['id'] ?? null;
        $bridgeWalletId = $eventObject['bridge_wallet_id'] ?? null;
        $activityType = strtolower(trim((string) ($eventObject['type'] ?? '')));
        $amount = $eventObject['amount'] ?? null;
        $availableBalance = $eventObject['available_balance'] ?? null;
        $paymentRoute = is_array($eventObject['payment_route'] ?? null) ? $eventObject['payment_route'] : [];
        $customerId = $paymentRoute['customer_id'] ?? null;

        Log::info('Bridge wallet activity event', [
            'activity_id' => $activityId,
            'bridge_wallet_id' => $bridgeWalletId,
            'activity_type' => $activityType,
            'amount' => $amount,
            'available_balance' => $availableBalance,
            'event_type' => $eventType,
            'payment_route_type' => $paymentRoute['type'] ?? null,
            'customer_id' => $customerId,
        ]);

        \App\Services\BelievePointBridgeReserveSettlementService::recordWalletActivityCredit($eventObject);

        $integration = $this->resolveIntegrationForBridgeWalletActivity($customerId, $bridgeWalletId);
        if ($integration === null) {
            Log::warning('Bridge integration not found for wallet activity', [
                'activity_id' => $activityId,
                'bridge_wallet_id' => $bridgeWalletId,
                'customer_id' => $customerId,
            ]);

            return;
        }

        if ($bridgeWalletId !== null) {
            $this->syncBridgeWalletFromActivity($integration, $bridgeWalletId, $availableBalance, $eventObject);
        }

        if ($this->shouldUseBridgeApiOnly($integration)) {
            $this->bridgeWalletNotifier->notifyWalletActivity($integration, $eventObject);

            return;
        }

        $user = $this->resolveIntegrationWalletUser($integration);
        if ($user === null) {
            return;
        }

        match ($activityType) {
            'deposit', 'direct_deposit' => $this->processBridgeWalletActivityDeposit(
                $integration,
                $user,
                $eventObject,
                $eventType,
                $activityId,
                $paymentRoute,
                $bridgeWalletId,
            ),
            'withdrawal' => $this->completeBridgeWalletWithdrawal($user, $eventObject, $paymentRoute),
            'return', 'undeliverable' => $this->refundBridgeWalletWithdrawal($user, $eventObject, $eventType, $paymentRoute),
            'card_spend', 'card_refund' => Log::info('Bridge wallet card activity recorded (ledger unchanged)', [
                'user_id' => $user->id,
                'activity_id' => $activityId,
                'activity_type' => $activityType,
            ]),
            default => Log::info('Bridge wallet activity ignored', [
                'activity_id' => $activityId,
                'activity_type' => $activityType,
            ]),
        };
    }

    private function resolveIntegrationForBridgeWalletActivity(?string $customerId, ?string $bridgeWalletId): ?BridgeIntegration
    {
        if ($bridgeWalletId) {
            $byWallet = $this->bridgeWalletNotifier->resolveIntegrationForWalletEndpoint([
                'bridge_wallet_id' => $bridgeWalletId,
            ]);
            if ($byWallet !== null) {
                return $byWallet;
            }
        }

        if ($customerId) {
            return $this->findIntegrationByCustomerId($customerId);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventObject
     */
    private function syncBridgeWalletFromActivity(
        BridgeIntegration $integration,
        string $bridgeWalletId,
        mixed $availableBalance,
        array $eventObject,
    ): void {
        try {
            $wallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                ->where('bridge_wallet_id', $bridgeWalletId)
                ->first();

            if ($wallet === null) {
                return;
            }

            $updates = [
                'wallet_metadata' => $eventObject,
                'last_balance_sync' => now(),
            ];

            if ($availableBalance !== null && $availableBalance !== '') {
                $updates['balance'] = (float) $availableBalance;
                $updates['currency'] = strtoupper((string) ($eventObject['currency'] ?? $wallet->currency ?? 'USD'));
            }

            $wallet->update($updates);
        } catch (\Exception $e) {
            Log::error('Failed to sync Bridge wallet from activity', [
                'bridge_wallet_id' => $bridgeWalletId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Credit the local ledger for inbound Bridge wallet funds (deduped against VA/transfer webhooks).
     *
     * @param  array<string, mixed>  $eventObject
     * @param  array<string, mixed>  $paymentRoute
     */
    private function processBridgeWalletActivityDeposit(
        BridgeIntegration $integration,
        User $user,
        array $eventObject,
        string $eventType,
        ?string $activityId,
        array $paymentRoute,
        ?string $bridgeWalletId,
    ): void {
        if ($this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth($integration)) {
            Log::info('Bridge wallet activity deposit skipped: Bridge is source of truth', [
                'integration_id' => $integration->id,
                'activity_id' => $activityId,
            ]);

            return;
        }

        $depositAmount = (float) ($eventObject['amount'] ?? 0);
        if ($depositAmount <= 0 || $activityId === null) {
            return;
        }

        $depositId = $paymentRoute['deposit_id'] ?? null;
        $transferId = $paymentRoute['transfer_id'] ?? null;

        if (Transaction::where('user_id', $user->id)
            ->whereJsonContains('meta->bridge_wallet_activity_id', $activityId)
            ->exists()) {
            Log::info('Bridge wallet activity deposit already processed', ['activity_id' => $activityId]);

            return;
        }

        if ($depositId) {
            $byDeposit = Transaction::where('user_id', $user->id)
                ->where('type', 'deposit')
                ->whereIn('status', ['completed', 'refunded'])
                ->whereJsonContains('meta->deposit_id', $depositId)
                ->exists();
            if ($byDeposit) {
                Log::info('Bridge wallet activity deposit skipped: virtual/static memo deposit already recorded', [
                    'deposit_id' => $depositId,
                    'activity_id' => $activityId,
                ]);

                return;
            }
        }

        if ($transferId) {
            $byTransfer = Transaction::where('user_id', $user->id)
                ->whereIn('type', ['transfer_in', 'deposit'])
                ->whereIn('status', ['completed', 'pending'])
                ->where(function ($query) use ($transferId): void {
                    $query->whereJsonContains('meta->bridge_transfer_id', $transferId)
                        ->orWhereJsonContains('meta->transfer_id', $transferId);
                })
                ->exists();
            if ($byTransfer) {
                Log::info('Bridge wallet activity deposit skipped: transfer already recorded', [
                    'transfer_id' => $transferId,
                    'activity_id' => $activityId,
                ]);

                return;
            }
        }

        try {
            DB::transaction(function () use ($user, $depositAmount, $activityId, $depositId, $transferId, $bridgeWalletId, $paymentRoute, $eventObject, $eventType): void {
                $user->increment('balance', $depositAmount);

                $depositTransaction = $user->recordTransaction([
                    'type' => 'deposit',
                    'amount' => $depositAmount,
                    'status' => 'completed',
                    'payment_method' => 'bridge',
                    'meta' => [
                        'bridge_wallet_activity_id' => $activityId,
                        'bridge_wallet_id' => $bridgeWalletId,
                        'deposit_id' => $depositId,
                        'bridge_transfer_id' => $transferId,
                        'payment_route_type' => $paymentRoute['type'] ?? null,
                        'bridge_wallet_activity_type' => $eventObject['type'] ?? null,
                        'bridge_event_type' => $eventType,
                        'source' => $eventObject['source'] ?? null,
                    ],
                    'processed_at' => now(),
                ]);

                $this->walletTransactionNotifier->notify($user, $depositTransaction);

                Log::info('Bridge wallet activity deposit processed', [
                    'user_id' => $user->id,
                    'activity_id' => $activityId,
                    'amount' => $depositAmount,
                    'deposit_id' => $depositId,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Exception processing bridge wallet activity deposit', [
                'user_id' => $user->id,
                'activity_id' => $activityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark a pending bank withdrawal complete when Bridge wallet activity confirms outflow.
     *
     * @param  array<string, mixed>  $eventObject
     * @param  array<string, mixed>  $paymentRoute
     */
    private function completeBridgeWalletWithdrawal(User $user, array $eventObject, array $paymentRoute): void
    {
        $transferId = $paymentRoute['transfer_id'] ?? null;
        if ($transferId === null) {
            return;
        }

        $transaction = Transaction::where('user_id', $user->id)
            ->where('type', 'withdrawal')
            ->whereJsonContains('meta->bridge_transfer_id', $transferId)
            ->first();

        if ($transaction === null || $transaction->status === 'completed') {
            return;
        }

        $transaction->status = 'completed';
        $transaction->processed_at = now();
        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['bridge_wallet_activity_id'] = $eventObject['id'] ?? null;
        $meta['bridge_wallet_activity_type'] = $eventObject['type'] ?? null;
        $transaction->meta = $meta;
        $transaction->save();

        $this->walletTransactionNotifier->notify($user, $transaction);

        Log::info('Bridge wallet withdrawal marked completed from activity', [
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'transfer_id' => $transferId,
        ]);
    }

    /**
     * Refund a failed/returned bank withdrawal back to the local ledger.
     *
     * @param  array<string, mixed>  $eventObject
     * @param  array<string, mixed>  $paymentRoute
     */
    private function refundBridgeWalletWithdrawal(User $user, array $eventObject, string $eventType, array $paymentRoute): void
    {
        $transferId = $paymentRoute['transfer_id'] ?? null;
        $activityId = $eventObject['id'] ?? null;

        if ($transferId === null) {
            return;
        }

        $transaction = Transaction::where('user_id', $user->id)
            ->where('type', 'withdrawal')
            ->whereJsonContains('meta->bridge_transfer_id', $transferId)
            ->whereIn('status', ['pending', 'completed'])
            ->first();

        if ($transaction === null) {
            return;
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        if (($meta['bridge_wallet_refund_activity_id'] ?? null) === $activityId) {
            return;
        }

        DB::transaction(function () use ($user, $transaction, $eventObject, $eventType, $activityId): void {
            $amount = (float) $transaction->amount;
            $skipLedger = $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth(
                $this->resolveUserBridgeIntegration($user)
            );
            if ($amount > 0 && ! $skipLedger) {
                $user->increment('balance', $amount);
            }

            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta['bridge_wallet_refund_activity_id'] = $activityId;
            $meta['bridge_wallet_refund_type'] = $eventObject['type'] ?? null;
            $meta['bridge_wallet_refund_event_type'] = $eventType;
            $meta['refunded_at'] = now()->toIso8601String();
            $transaction->meta = $meta;
            $transaction->status = 'failed';
            $transaction->save();

            Log::info('Bridge wallet withdrawal refunded after return/undeliverable activity', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'activity_id' => $activityId,
            ]);
        });
    }

    /**
     * Handle legacy wallet resource events (status/address updates).
     *
     * @param  array<string, mixed>  $eventObject
     * @param  array<string, mixed>  $changes
     */
    private function handleWalletEvent(string $eventType, array $eventObject, ?string $status, array $changes)
    {
        $walletId = $eventObject['id'] ?? null;
        $customerId = $eventObject['customer_id'] ?? null;
        $walletStatus = $eventObject['status'] ?? $status;
        $address = $eventObject['address'] ?? null;
        $chain = $eventObject['chain'] ?? null;

        Log::info('Bridge wallet event', [
            'wallet_id' => $walletId,
            'customer_id' => $customerId,
            'status' => $walletStatus,
            'address' => $address,
            'chain' => $chain,
            'event_type' => $eventType,
        ]);

        // Update wallet in database if exists
        if ($walletId && $customerId) {
            try {
                $integration = BridgeIntegration::where('bridge_customer_id', $customerId)->first();
                
                if ($integration) {
                    $wallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                        ->where('bridge_wallet_id', $walletId)
                        ->first();

                    if ($wallet) {
                        $wallet->update([
                            'status' => $walletStatus ?? $wallet->status,
                            'wallet_address' => $address ?? $wallet->wallet_address,
                            'chain' => $chain ?? $wallet->chain,
                            'wallet_metadata' => $eventObject,
                            'last_balance_sync' => now(),
                        ]);

                        Log::info('Bridge wallet updated in database', [
                            'wallet_id' => $wallet->id,
                            'bridge_wallet_id' => $walletId,
                            'status' => $walletStatus,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception handling wallet event', [
                    'wallet_id' => $walletId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Normalize ToS status values to Bridge's documented statuses
     * 
     * Bridge ToS statuses:
     * - pending
     * - approved
     */
    private function normalizeTosStatus(?string $status): string
    {
        if (!$status) {
            return 'pending';
        }

        $status = strtolower(trim($status));

        // Valid Bridge ToS statuses
        $validTosStatuses = [
            'pending',
            'approved',
        ];

        // If status is already a valid Bridge ToS status, return it as-is
        if (in_array($status, $validTosStatuses)) {
            return $status;
        }

        // Legacy status mapping
        $legacyStatusMap = [
            'accepted' => 'approved', // Legacy accepted = approved
        ];

        return $legacyStatusMap[$status] ?? 'pending';
    }

    /**
     * Normalize KYC/KYB status values to Bridge's documented statuses
     * 
     * Bridge KYC/KYB Link statuses:
     * - not_started
     * - incomplete
     * - under_review
     * - awaiting_questionnaire
     * - awaiting_ubo (for businesses)
     * - approved
     * - rejected
     * - paused
     * - offboarded
     * 
     * Bridge Customer statuses:
     * - not_started
     * - active
     * - under_review
     * - rejected
     */
    private function normalizeStatus(?string $status): string
    {
        if (!$status) {
            return 'not_started';
        }

        $status = strtolower(trim($status));

        // Valid Bridge KYC/KYB Link statuses
        $validKycKybStatuses = [
            'not_started',
            'incomplete',
            'under_review',
            'awaiting_questionnaire',
            'awaiting_ubo',
            'approved',
            'rejected',
            'paused',
            'offboarded',
        ];

        // Valid Bridge Customer statuses
        $validCustomerStatuses = [
            'not_started',
            'active',
            'under_review',
            'rejected',
        ];

        // Valid Bridge ToS statuses
        $validTosStatuses = [
            'pending',
            'approved',
        ];

        // If status is already a valid Bridge status, return it as-is
        if (in_array($status, array_merge($validKycKybStatuses, $validCustomerStatuses, $validTosStatuses))) {
            return $status;
        }

        // Legacy status mapping for backward compatibility
        $legacyStatusMap = [
            'active' => 'approved', // Customer active = approved
            'verified' => 'approved', // Legacy verified = approved
            'pending' => 'under_review', // Legacy pending = under_review for KYC/KYB
            'manual_review' => 'under_review',
            'in_review' => 'under_review',
            'submitted' => 'under_review',
        ];

        return $legacyStatusMap[$status] ?? 'not_started';
    }

    /**
     * Map Bridge transfer state to transaction status
     * 
     * Bridge transfer states (per Bridge.xyz API docs):
     * - awaiting_funds: Transfer is waiting for funds to be deposited
     * - payment_submitted: Payment has been submitted but not yet processed
     * - payment_processed: Payment has been processed and completed
     * - funds_received: Funds have been received
     * - in_review: Transfer is under review
     * - completed: Transfer is fully completed
     * - failed: Transfer failed
     * - returned: Transfer was returned
     * - refunded: Transfer was refunded
     * - cancelled/canceled: Transfer was cancelled
     */
    private function mapBridgeTransferStateToStatus(?string $state): string
    {
        if (!$state) {
            Log::warning('Bridge transfer state is null, defaulting to pending');
            return 'pending';
        }

        $state = strtolower(trim($state));

        $stateMap = [
            // Completed states — only terminal Bridge transfer states credit recipients
            'payment_processed' => 'completed',
            'completed' => 'completed',

            // Pending states
            'awaiting_funds' => 'pending',
            'funds_received' => 'pending',
            'payment_submitted' => 'pending',
            'in_review' => 'pending',
            'processing' => 'pending',
            'pending' => 'pending',
            
            // Failed states
            'failed' => 'failed',
            'returned' => 'failed',
            'refunded' => 'failed',
            'error' => 'failed',
            
            // Cancelled states
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled',
        ];

        $mappedStatus = $stateMap[$state] ?? 'pending';
        
        // Log unmapped states for debugging
        if (!isset($stateMap[$state])) {
            Log::warning('Bridge transfer state not mapped, defaulting to pending', [
                'state' => $state,
                'mapped_status' => $mappedStatus,
            ]);
        }

        return $mappedStatus;
    }

    /**
     * Resolve the wallet owner user from a Bridge integration (org → org user).
     */
    private function resolveIntegrationWalletUser(BridgeIntegration $integration): ?User
    {
        $integratable = $integration->integratable;
        if ($integratable === null) {
            return null;
        }

        if ($integration->integratable_type === Organization::class) {
            return $integratable->user ?? null;
        }

        return $integratable instanceof User ? $integratable : null;
    }

    private function resolveUserBridgeIntegration(User $user): ?BridgeIntegration
    {
        return BridgeIntegration::resolveForUser($user);
    }

    private function findIntegrationByCustomerId(?string $customerId): ?BridgeIntegration
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        return BridgeIntegration::where('bridge_customer_id', $customerId)->first();
    }

    /**
     * Bridge-connected wallets: webhooks update KYC/wallet metadata only — never users.balance.
     */
    private function shouldUseBridgeApiOnly(?BridgeIntegration $integration): bool
    {
        return $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth($integration);
    }

    private function acknowledgeBridgeWalletWebhook(string $handler, ?BridgeIntegration $integration, array $context = []): void
    {
        Log::info("{$handler}: acknowledged (Bridge API is wallet source of truth)", array_merge([
            'integration_id' => $integration?->id,
            'customer_id' => $integration?->bridge_customer_id,
        ], $context));
    }

    /**
     * Reverse a completed virtual-account deposit when Bridge sends refunded/refund_failed.
     */
    private function reverseVirtualAccountDeposit(BridgeIntegration $integration, array $eventObject, string $eventType): void
    {
        if ($this->shouldUseBridgeApiOnly($integration)) {
            $this->acknowledgeBridgeWalletWebhook('virtual_account.refund', $integration, [
                'activity_id' => $eventObject['id'] ?? null,
            ]);

            return;
        }

        $user = $this->resolveIntegrationWalletUser($integration);
        if ($user === null) {
            Log::warning('Cannot reverse virtual account deposit: user not found', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        $depositId = $eventObject['deposit_id'] ?? null;
        $virtualAccountId = $eventObject['virtual_account_id'] ?? null;
        $activityId = $eventObject['id'] ?? null;

        $query = Transaction::where('user_id', $user->id)
            ->where('type', 'deposit')
            ->where('status', 'completed');

        if ($depositId) {
            $query->whereJsonContains('meta->deposit_id', $depositId);
        } elseif ($virtualAccountId && $activityId) {
            $query
                ->whereJsonContains('meta->virtual_account_id', $virtualAccountId)
                ->whereJsonContains('meta->activity_id', $activityId);
        } else {
            Log::warning('Cannot reverse virtual account deposit: missing deposit_id', [
                'integration_id' => $integration->id,
                'activity_id' => $activityId,
            ]);

            return;
        }

        $transaction = $query->first();
        if ($transaction === null) {
            Log::info('No completed virtual account deposit found to reverse', [
                'user_id' => $user->id,
                'deposit_id' => $depositId,
                'virtual_account_id' => $virtualAccountId,
            ]);

            return;
        }

        $this->reverseCompletedDeposit($user, $transaction, $eventObject, $eventType);
    }

    /**
     * Reverse a completed static-memo deposit when Bridge sends refund.
     */
    private function reverseStaticMemoDeposit(BridgeIntegration $integration, array $eventObject, string $eventType): void
    {
        if ($this->shouldUseBridgeApiOnly($integration)) {
            $this->acknowledgeBridgeWalletWebhook('static_memo.refund', $integration, [
                'activity_id' => $eventObject['id'] ?? null,
            ]);

            return;
        }

        $user = $this->resolveIntegrationWalletUser($integration);
        if ($user === null) {
            return;
        }

        $depositId = $eventObject['deposit_id'] ?? null;
        $staticMemoId = $eventObject['static_memo_id'] ?? null;
        $activityId = $eventObject['id'] ?? null;

        $query = Transaction::where('user_id', $user->id)
            ->where('type', 'deposit')
            ->where('status', 'completed');

        if ($depositId) {
            $query->whereJsonContains('meta->deposit_id', $depositId);
        } elseif ($staticMemoId && $activityId) {
            $query
                ->whereJsonContains('meta->static_memo_id', $staticMemoId)
                ->whereJsonContains('meta->activity_id', $activityId);
        } else {
            return;
        }

        $transaction = $query->first();
        if ($transaction === null) {
            Log::info('No completed static memo deposit found to reverse', [
                'user_id' => $user->id,
                'deposit_id' => $depositId,
                'static_memo_id' => $staticMemoId,
            ]);

            return;
        }

        $this->reverseCompletedDeposit($user, $transaction, $eventObject, $eventType);
    }

    /**
     * Decrement ledger balance and mark a completed deposit as refunded.
     */
    private function reverseCompletedDeposit(User $user, Transaction $transaction, array $eventObject, string $eventType): void
    {
        if ($transaction->status === 'refunded') {
            return;
        }

        DB::transaction(function () use ($user, $transaction, $eventObject, $eventType): void {
            $amount = (float) $transaction->amount;
            if ($amount <= 0) {
                return;
            }

            $skipLedger = $this->bridgeWalletReadService->usesBridgeWalletAsSourceOfTruth(
                $this->resolveUserBridgeIntegration($user)
            );

            if (! $skipLedger) {
                $user->decrement('balance', min($amount, (float) $user->balance));
            }

            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta['refunded_at'] = now()->toIso8601String();
            $meta['refund_activity_id'] = $eventObject['id'] ?? null;
            $meta['bridge_refund_type'] = $eventObject['type'] ?? null;
            $meta['bridge_refund_event_type'] = $eventType;

            $transaction->meta = $meta;
            $transaction->status = 'refunded';
            $transaction->save();

            Log::info('Bridge deposit reversed after refund event', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'deposit_id' => $eventObject['deposit_id'] ?? null,
            ]);
        });
    }

    /**
     * Create wallet, virtual account, and card account when customer is approved
     * 
     * This follows Bridge.xyz API documentation exactly for both sandbox and live environments:
     * 
     * SANDBOX MODE (per Bridge.xyz docs):
     * - Wallets: NOT available in sandbox (skipped with info log)
     * - Virtual Accounts: Created with dummy data using Ethereum payment rail, USDC currency, and auto-generated address
     * - Card Accounts: Can be created normally (may be auto-created by Bridge)
     * 
     * PRODUCTION MODE:
     * - Wallets: Created on Solana chain (default)
     * - Virtual Accounts: Created linked to wallet (bridge_wallet) or ACH push if no wallet
     * - Card Accounts: Created normally
     * 
     * @param BridgeIntegration $integration The Bridge integration
     * @param string $customerId The Bridge customer ID
     * @param string|null $linkId Optional KYC link ID for logging
     * @return void
     */
    public function createWalletVirtualAccountAndCardAccount(BridgeIntegration $integration, string $customerId, ?string $linkId = null): void
    {
        $customerId = $integration->bridge_customer_id;
        
        if (!$customerId) {
            Log::warning('Cannot create wallet/virtual account/card account: missing customer ID', [
                'integration_id' => $integration->id,
            ]);
            return;
        }
        
        // Refresh integration to ensure we have the latest statuses from database
        $integration->refresh();

        $customerResult = $this->bridgeService->getCustomer($customerId);
        $bridgeCustomerStatus = strtolower((string) ($customerResult['data']['status'] ?? ''));
        if ($bridgeCustomerStatus !== 'active') {
            Log::info('Bridge customer not active on API — skipping resource creation', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'bridge_customer_status' => $bridgeCustomerStatus ?: 'unknown',
            ]);

            return;
        }

        // For business accounts, ONLY kyb_status must be approved (not KYC)
        // For individual accounts, only kyc_status needs to be approved
        $isBusiness = $integration->integratable_type === \App\Models\Organization::class;

        Log::info('Checking if account is approved for resource creation', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
            'is_business' => $isBusiness,
            'kyb_status' => $integration->kyb_status,
            'kyc_status' => $integration->kyc_status,
        ]);
        
        if ($isBusiness) {
            // Business account: ONLY KYB status needs to be approved (not KYC)
            if ($integration->kyb_status !== 'approved') {
                Log::info('Business account KYB not approved yet - waiting for KYB approval', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'kyb_status' => $integration->kyb_status,
                    'kyc_status' => $integration->kyc_status,
                ]);
                return;
            }
        } else {
            // Individual account: check KYC only
            if ($integration->kyc_status !== 'approved') {
                Log::info('Individual account KYC not approved yet', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'kyc_status' => $integration->kyc_status,
                ]);
                return;
            }
        }

        Log::info('Account is approved - proceeding with resource creation', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
            'is_business' => $isBusiness,
            'kyb_status' => $integration->kyb_status,
            'kyc_status' => $integration->kyc_status,
        ]);

        // 1. Create Wallet (only in production, not available in sandbox)
        $existingWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
            ->where('is_primary', true)
            ->first();

        $walletId = null;
        if (!$existingWallet) {
            try {
                // Create primary wallet (default: Solana)
                // Note: Wallets are not available in sandbox mode per Bridge.xyz docs
                $chain = 'solana';
                $walletResult = $this->bridgeService->createWallet($customerId, $chain);

                if ($walletResult['success'] && isset($walletResult['data'])) {
                    $walletData = $walletResult['data'];
                    $walletId = $walletData['id'] ?? null;
                    
                    // Create wallet record in database
                    $wallet = BridgeWallet::create([
                        'bridge_integration_id' => $integration->id,
                        'bridge_customer_id' => $customerId,
                        'bridge_wallet_id' => $walletId,
                        'wallet_address' => $walletData['address'] ?? null,
                        'chain' => $chain,
                        'status' => 'active',
                        'balance' => 0,
                        'currency' => 'USD',
                        'wallet_metadata' => $walletData,
                        'is_primary' => true,
                        'last_balance_sync' => now(),
                    ]);

                    // Update integration with primary wallet info (for backward compatibility)
                    if (!$integration->bridge_wallet_id) {
                        $integration->bridge_wallet_id = $wallet->bridge_wallet_id;
                        // wallet_address and wallet_chain are stored on BridgeWallet, not BridgeIntegration
                        $integration->save();
                    }

                    Log::info('Bridge wallet auto-created on approval', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'link_id' => $linkId,
                        'wallet_id' => $walletId,
                        'chain' => $chain,
                        'address' => $wallet->wallet_address,
                    ]);
                } else {
                    // In sandbox, wallet creation will fail - this is expected per Bridge.xyz docs
                    if ($this->bridgeService->isSandbox()) {
                        Log::info('Wallet creation skipped in sandbox mode (not available per Bridge.xyz docs)', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                        ]);
                    } else {
                        Log::warning('Failed to auto-create Bridge wallet on approval', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'error' => $walletResult['error'] ?? 'Unknown error',
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception while auto-creating Bridge wallet on approval', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            $walletId = $existingWallet->bridge_wallet_id;
            Log::info('Bridge wallet already exists, skipping creation', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'existing_wallet_id' => $walletId,
            ]);
        }

        $primaryWalletRecord = BridgeWallet::where('bridge_integration_id', $integration->id)
            ->where('is_primary', true)
            ->first();
        $walletChain = strtolower((string) ($primaryWalletRecord?->chain ?? 'solana'));
        if (in_array($walletChain, ['usd', 'fiat'], true) && $walletId) {
            $walletChain = $this->bridgeService->resolveWalletChain($customerId, $walletId);
        }

        // 2. Create Virtual Account (works in both sandbox and production)
        // Per Bridge.xyz docs: Virtual accounts in sandbox use dummy data
        try {
            // Check if virtual account already exists
            $virtualAccountsResult = $this->bridgeService->getVirtualAccounts($customerId);
            $hasVirtualAccount = false;
            
            $existingVirtualAccounts = [];
            if ($virtualAccountsResult['success'] && isset($virtualAccountsResult['data']['data'])) {
                $existingVirtualAccounts = $virtualAccountsResult['data']['data'];
                $hasVirtualAccount = count($existingVirtualAccounts) > 0;
            }

            if (!$hasVirtualAccount) {
                $sourceCurrency = $this->bridgeService->resolvePreferredVirtualAccountSourceCurrency(
                    null,
                    $customerId,
                );

                if ($this->bridgeService->isSandbox()) {
                    // Sandbox mode: Create virtual account with dummy data per Bridge.xyz docs
                    $source = ['currency' => $sourceCurrency];
                    $destination = [
                        'payment_rail' => 'ethereum',
                        'currency' => 'usdc',
                        'address' => $this->bridgeService->generateEthereumAddress(),
                    ];
                    
                    $virtualAccountResult = $this->bridgeService->createVirtualAccount($customerId, $source, $destination);
                } elseif ($walletId) {
                    // Production: EUR IBAN when SEPA granted / US blocked; otherwise USD ACH
                    $virtualAccountResult = $this->bridgeService->createVirtualAccountForWallet(
                        $customerId,
                        $walletId,
                        $sourceCurrency,
                        $walletChain,
                    );
                } else {
                    $source = ['currency' => $sourceCurrency];
                    $destination = $sourceCurrency === 'eur'
                        ? [
                            'payment_rail' => 'ethereum',
                            'currency' => 'usdc',
                            'address' => $this->bridgeService->generateEthereumAddress(),
                        ]
                        : [
                            'payment_rail' => 'ach_push',
                            'currency' => 'usd',
                        ];
                    
                    $virtualAccountResult = $this->bridgeService->createVirtualAccount($customerId, $source, $destination);
                }

                if ($virtualAccountResult['success'] && isset($virtualAccountResult['data'])) {
                    $virtualAccountData = $virtualAccountResult['data'];
                    $virtualAccountId = $virtualAccountData['id'] ?? null;
                    
                    // Store virtual account in bridge_wallets table
                    if ($virtualAccountId) {
                        // Check if virtual account already exists in database
                        $existingVirtualAccountWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                            ->where('virtual_account_id', $virtualAccountId)
                            ->first();
                        
                        if (!$existingVirtualAccountWallet) {
                            // Extract address from virtual account data
                            $virtualAccountAddress = $virtualAccountData['destination']['address'] ?? null;
                            $chain = $virtualAccountData['destination']['payment_rail'] ?? ($this->bridgeService->isSandbox() ? 'ethereum' : 'solana');
                            $currency = $virtualAccountData['destination']['currency'] ?? ($this->bridgeService->isSandbox() ? 'usdc' : 'usdc');
                            
                            // Create or update bridge_wallets record for virtual account
                            BridgeWallet::updateOrCreate(
                                [
                                    'bridge_integration_id' => $integration->id,
                                    'virtual_account_id' => $virtualAccountId,
                                ],
                                [
                                    'bridge_customer_id' => $customerId,
                                    'wallet_address' => $virtualAccountAddress,
                                    'chain' => $chain,
                                    'currency' => $currency,
                                    'status' => 'active',
                                    'balance' => 0,
                                    'virtual_account_details' => $virtualAccountData,
                                    'is_primary' => !$existingWallet, // Set as primary if no wallet exists
                                    'last_balance_sync' => now(),
                                ]
                            );
                            
                            Log::info('Bridge virtual account stored in bridge_wallets table', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'virtual_account_id' => $virtualAccountId,
                                'address' => $virtualAccountAddress,
                                'chain' => $chain,
                                'environment' => $this->bridgeService->isSandbox() ? 'sandbox' : 'production',
                            ]);
                        } else {
                            // Update existing virtual account record
                            $existingVirtualAccountWallet->update([
                                'virtual_account_details' => $virtualAccountData,
                                'wallet_address' => $virtualAccountData['destination']['address'] ?? $existingVirtualAccountWallet->wallet_address,
                                'last_balance_sync' => now(),
                            ]);
                            
                            Log::info('Bridge virtual account updated in bridge_wallets table', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'virtual_account_id' => $virtualAccountId,
                            ]);
                        }
                    }
                    
                    Log::info('Bridge virtual account auto-created on approval', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'link_id' => $linkId,
                        'virtual_account_id' => $virtualAccountId,
                        'environment' => $this->bridgeService->isSandbox() ? 'sandbox' : 'production',
                    ]);
                } else {
                    Log::warning('Failed to auto-create Bridge virtual account on approval', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'error' => $virtualAccountResult['error'] ?? 'Unknown error',
                    ]);
                }
            } else {
                // Virtual account already exists in Bridge - store it in database if not already stored
                if (count($existingVirtualAccounts) > 0) {
                    $virtualAccountData = $existingVirtualAccounts[0];
                    $virtualAccountId = $virtualAccountData['id'] ?? null;
                    
                    if ($virtualAccountId) {
                        $existingVirtualAccountWallet = BridgeWallet::where('bridge_integration_id', $integration->id)
                            ->where('virtual_account_id', $virtualAccountId)
                            ->first();
                        
                        if (!$existingVirtualAccountWallet) {
                            // Store existing virtual account in bridge_wallets table
                            $virtualAccountAddress = $virtualAccountData['destination']['address'] ?? null;
                            $chain = $virtualAccountData['destination']['payment_rail'] ?? ($this->bridgeService->isSandbox() ? 'ethereum' : 'solana');
                            $currency = $virtualAccountData['destination']['currency'] ?? ($this->bridgeService->isSandbox() ? 'usdc' : 'usdc');
                            
                            BridgeWallet::updateOrCreate(
                                [
                                    'bridge_integration_id' => $integration->id,
                                    'virtual_account_id' => $virtualAccountId,
                                ],
                                [
                                    'bridge_customer_id' => $customerId,
                                    'wallet_address' => $virtualAccountAddress,
                                    'chain' => $chain,
                                    'currency' => $currency,
                                    'status' => 'active',
                                    'balance' => 0,
                                    'virtual_account_details' => $virtualAccountData,
                                    'is_primary' => !$existingWallet, // Set as primary if no wallet exists
                                    'last_balance_sync' => now(),
                                ]
                            );
                            
                            Log::info('Bridge existing virtual account stored in bridge_wallets table', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'virtual_account_id' => $virtualAccountId,
                                'address' => $virtualAccountAddress,
                            ]);
                        }
                    }
                }
                
                Log::info('Bridge virtual account already exists, skipping creation', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while auto-creating Bridge virtual account on approval', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // 3. Create Card Account (Stripe Issuing in production; legacy card_accounts in sandbox)
        try {
            $cardsReadyResult = $this->bridgeService->ensureCardsProductEnabled();
            $cardsReady = ($cardsReadyResult['success'] ?? false) || ($cardsReadyResult['already_enabled'] ?? false);

            $existingCardAccount = null;
            $cardAccountData = null;

            $cardAccountsResult = ['success' => false];
            $useLegacyCardAccounts = $this->bridgeService->isSandbox();

            if ($useLegacyCardAccounts) {
                $cardAccountsResult = $this->bridgeService->getCardAccounts($customerId);

                if (! $cardAccountsResult['success']) {
                    if ($this->bridgeService->shouldBypassLegacyCardAccountsGate($cardAccountsResult)) {
                        $useLegacyCardAccounts = false;
                    } elseif ($this->bridgeService->isCardsProductNotEnabledError($cardAccountsResult)) {
                        Log::info('Cards product not enabled - attempting to enable automatically', [
                            'integration_id' => $integration->id,
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
                                $cardsReady = false;
                            }
                        } else {
                            $cardsReady = false;
                            Log::warning('Failed to enable cards product automatically', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'error' => $enableResult['error'] ?? 'Unknown error',
                            ]);
                        }
                    } else {
                        Log::info('Legacy card_accounts unavailable — using Stripe Issuing path', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'error' => $cardAccountsResult['error'] ?? null,
                        ]);
                        $useLegacyCardAccounts = false;
                    }
                } elseif (isset($cardAccountsResult['data']['data'])) {
                    $cardAccounts = $cardAccountsResult['data']['data'];
                    if (count($cardAccounts) > 0) {
                        $existingCardAccount = $cardAccounts[0];
                        $cardAccountData = $existingCardAccount;
                    }
                }
            }

            // Check if card wallet already exists in database
            $existingCardWallet = CardWallet::where('bridge_integration_id', $integration->id)
                ->where('is_primary', true)
                ->first();

            // Only proceed with card account creation if cards are ready
            if (! $cardsReady) {
                Log::info('Skipping card account creation - cards not ready', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'error' => $cardsReadyResult['error'] ?? null,
                ]);
            } elseif (! $existingCardAccount) {
                // Card accounts require date of birth - check appropriately for business vs individual accounts
                $shouldCreateCardAccount = true;

                if ($isBusiness) {
                    // Check if we're in sandbox - skip endorsement check entirely for sandbox
                    $isSandbox = $this->bridgeService->isSandbox();
                    
                    if (!$isSandbox) {
                        // Only check for cards endorsement in production/live environment
                        // For business accounts, Bridge requires:
                        // 1. Business customer must have approved "cards" endorsement
                        // 2. Associated person (control person) must have: first_name, last_name, and birth_date
                        
                        // Check if business customer has cards endorsement
                        $customerResult = $this->bridgeService->getCustomer($customerId);
                        $hasCardsEndorsement = false;
                        
                        if ($customerResult['success'] && isset($customerResult['data'])) {
                            $customer = $customerResult['data'];
                            $endorsements = $customer['endorsements'] ?? [];
                            
                            foreach ($endorsements as $endorsement) {
                                $endorsementName = strtolower($endorsement['name'] ?? '');
                                $endorsementStatus = strtolower($endorsement['status'] ?? '');
                                
                                // Check if cards endorsement exists and is approved
                                if ($endorsementName === 'cards' && $endorsementStatus === 'approved') {
                                    $hasCardsEndorsement = true;
                                    break;
                                }
                            }
                        }
                        
                        if (!$hasCardsEndorsement) {
                            // Check if cards endorsement exists but is not approved (pending, under_review, etc.)
                            $cardsEndorsementExists = false;
                            $cardsEndorsementStatus = null;
                            
                            foreach ($endorsements as $endorsement) {
                                $endorsementName = strtolower($endorsement['name'] ?? '');
                                if ($endorsementName === 'cards') {
                                    $cardsEndorsementExists = true;
                                    $cardsEndorsementStatus = strtolower($endorsement['status'] ?? '');
                                    break;
                                }
                            }
                            
                            // If cards endorsement doesn't exist, request it
                            if (!$cardsEndorsementExists) {
                                Log::info('Requesting cards endorsement for business account', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                ]);
                                
                                $endorsementResult = $this->bridgeService->requestEndorsement($customerId, [
                                    'endorsement_type' => 'cards',
                                ]);
                                
                                if ($endorsementResult['success']) {
                                    Log::info('Cards endorsement requested successfully', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'endorsement_data' => $endorsementResult['data'] ?? null,
                                    ]);
                                } else {
                                    Log::warning('Failed to request cards endorsement', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'error' => $endorsementResult['error'] ?? 'Unknown error',
                                    ]);
                                }
                            } else {
                                Log::info('Cards endorsement exists but not approved yet', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'endorsement_status' => $cardsEndorsementStatus,
                                    'note' => 'Cards endorsement is pending approval. Card account will be created once approved.',
                                ]);
                            }
                            
                            $shouldCreateCardAccount = false;
                            Log::info('Skipping card account creation for business account - cards endorsement not approved', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'endorsement_exists' => $cardsEndorsementExists,
                                'endorsement_status' => $cardsEndorsementStatus,
                                'note' => 'Business customer must have approved "cards" endorsement to create card accounts',
                            ]);
                        } else {
                            // Cards endorsement is approved - check associated person fields
                            // Check if associated person (control person) has all required fields
                            // Bridge requires: first_name, last_name, and birth_date for card account creation
                            $associatedPersonsResult = $this->bridgeService->getAssociatedPersons($customerId);
                            $hasAssociatedPersonWithDob = false;
                            $hasRequiredFields = false;

                            if ($associatedPersonsResult['success'] && !empty($associatedPersonsResult['data'])) {
                                foreach ($associatedPersonsResult['data'] as $associatedPerson) {
                                    // Check if associated person has all required fields for card account
                                    $hasBirthDate = !empty($associatedPerson['birth_date'] ?? null);
                                    $hasFirstName = !empty($associatedPerson['first_name'] ?? null);
                                    $hasLastName = !empty($associatedPerson['last_name'] ?? null);
                                    
                                    if ($hasBirthDate && $hasFirstName && $hasLastName) {
                                        $hasAssociatedPersonWithDob = true;
                                        $hasRequiredFields = true;
                                        break;
                                    } elseif ($hasBirthDate) {
                                        // Has birth_date but missing first_name or last_name
                                        $hasAssociatedPersonWithDob = true; // We'll update the missing fields
                                    }
                                }
                            }

                            // If no associated person with all required fields, try to get from database and update Bridge
                            // Also update if we have birth_date but missing first_name or last_name
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
                                    // Get the control person even if birth_date is null, we need first_name and last_name
                                    $controlPerson = \App\Models\ControlPerson::where('bridge_kyc_kyb_submission_id', $submission->id)
                                        ->whereNotNull('bridge_associated_person_id')
                                        ->first();

                                    if ($controlPerson) {
                                        $associatedPersonId = $controlPerson->bridge_associated_person_id;
                                        
                                        // Get birth_date if available
                                        if ($controlPerson->birth_date) {
                                            $birthDate = $controlPerson->birth_date->format('Y-m-d');
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
                                                        }
                                                    } elseif ($birthDateRaw instanceof \DateTime || $birthDateRaw instanceof \Carbon\Carbon) {
                                                        $birthDate = $birthDateRaw->format('Y-m-d');
                                                    }
                                                }
                                            }
                                        }
                                    }

                                    // If we have associated_person_id and control person, update Bridge with all required fields
                                    // For card accounts, Bridge requires: first_name, last_name, and birth_date on associated person
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
                                                }
                                                
                                                Log::info('Updated associated person with required fields for card account', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'associated_person_id' => $associatedPersonId,
                                                    'birth_date' => $updateData['birth_date'] ?? null,
                                                    'first_name' => $updateData['first_name'] ?? null,
                                                    'last_name' => $updateData['last_name'] ?? null,
                                                    'has_all_required_fields' => $hasRequiredFields,
                                                    'source' => 'control_persons_table',
                                                ]);
                                            } else {
                                                Log::warning('Failed to update associated person with required fields', [
                                                    'integration_id' => $integration->id,
                                                    'customer_id' => $customerId,
                                                    'associated_person_id' => $associatedPersonId,
                                                    'update_data' => $updateData,
                                                    'error' => $updateResult['error'] ?? 'Unknown error',
                                                ]);
                                            }
                                        } else {
                                            Log::warning('Cannot update associated person - missing birth_date', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'associated_person_id' => $associatedPersonId,
                                                'has_birth_date' => !empty($birthDate),
                                                'has_first_name' => !empty($controlPerson->first_name),
                                                'has_last_name' => !empty($controlPerson->last_name),
                                            ]);
                                        }
                                    }
                                }
                            }
                            
                            // Final check: Ensure we have all required fields before creating card account
                            if (!$hasRequiredFields) {
                                $shouldCreateCardAccount = false;
                                Log::info('Skipping card account creation for business account - associated person missing required fields', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'has_cards_endorsement' => $hasCardsEndorsement,
                                    'has_associated_person' => $hasAssociatedPersonWithDob,
                                    'has_all_required_fields' => $hasRequiredFields,
                                    'note' => 'Associated person must have first_name, last_name, and birth_date for card account creation',
                                ]);
                            }
                        }
                    } else {
                        // In sandbox, skip endorsement check entirely and proceed with card account creation
                        Log::info('Skipping cards endorsement check in sandbox for instant approval - will attempt direct card account creation', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                        ]);
                        
                        // Check if associated person (control person) has all required fields
                        // Bridge requires: first_name, last_name, and birth_date for card account creation
                        $associatedPersonsResult = $this->bridgeService->getAssociatedPersons($customerId);
                        $hasAssociatedPersonWithDob = false;
                        $hasRequiredFields = false;

                        if ($associatedPersonsResult['success'] && !empty($associatedPersonsResult['data'])) {
                            foreach ($associatedPersonsResult['data'] as $associatedPerson) {
                                // Check if associated person has all required fields for card account
                                $hasBirthDate = !empty($associatedPerson['birth_date'] ?? null);
                                $hasFirstName = !empty($associatedPerson['first_name'] ?? null);
                                $hasLastName = !empty($associatedPerson['last_name'] ?? null);
                                
                                if ($hasBirthDate && $hasFirstName && $hasLastName) {
                                    $hasAssociatedPersonWithDob = true;
                                    $hasRequiredFields = true;
                                    break;
                                } elseif ($hasBirthDate) {
                                    // Has birth_date but missing first_name or last_name
                                    $hasAssociatedPersonWithDob = true; // We'll update the missing fields
                                }
                            }
                        }

                        // If no associated person with all required fields, try to get from database and update Bridge
                        // Also update if we have birth_date but missing first_name or last_name
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
                                // Get the control person even if birth_date is null, we need first_name and last_name
                                $controlPerson = \App\Models\ControlPerson::where('bridge_kyc_kyb_submission_id', $submission->id)
                                    ->whereNotNull('bridge_associated_person_id')
                                    ->first();

                                if ($controlPerson) {
                                    $associatedPersonId = $controlPerson->bridge_associated_person_id;
                                    
                                    // Get birth_date if available
                                    if ($controlPerson->birth_date) {
                                        $birthDate = $controlPerson->birth_date->format('Y-m-d');
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

                                                if (is_string($birthDateRaw)) {
                                                    try {
                                                        $birthDateObj = new \DateTime($birthDateRaw);
                                                        $birthDate = $birthDateObj->format('Y-m-d');
                                                    } catch (\Exception $e) {
                                                        Log::warning('Failed to parse birth_date from submission_data for control person (sandbox)', [
                                                            'integration_id' => $integration->id,
                                                            'customer_id' => $customerId,
                                                            'birth_date_string' => $birthDateRaw,
                                                            'error' => $e->getMessage(),
                                                        ]);
                                                    }
                                                } elseif ($birthDateRaw instanceof \DateTime || $birthDateRaw instanceof \Carbon\Carbon) {
                                                    $birthDate = $birthDateRaw->format('Y-m-d');
                                                }
                                            }
                                        }
                                    }
                                }

                                // If we have associated_person_id and control person, update Bridge with all required fields
                                // For card accounts, Bridge requires: first_name, last_name, and birth_date on associated person
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
                                            }
                                            
                                            Log::info('Updated associated person with required fields for card account (sandbox)', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'associated_person_id' => $associatedPersonId,
                                                'birth_date' => $updateData['birth_date'] ?? null,
                                                'first_name' => $updateData['first_name'] ?? null,
                                                'last_name' => $updateData['last_name'] ?? null,
                                                'has_all_required_fields' => $hasRequiredFields,
                                                'source' => 'control_persons_table',
                                            ]);
                                        } else {
                                            Log::warning('Failed to update associated person with required fields (sandbox)', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                                'associated_person_id' => $associatedPersonId,
                                                'update_data' => $updateData,
                                                'error' => $updateResult['error'] ?? 'Unknown error',
                                            ]);
                                        }
                                    } else {
                                        Log::warning('Cannot update associated person - missing birth_date (sandbox)', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'associated_person_id' => $associatedPersonId,
                                            'has_birth_date' => !empty($birthDate),
                                            'has_first_name' => !empty($controlPerson->first_name),
                                            'has_last_name' => !empty($controlPerson->last_name),
                                        ]);
                                    }
                                }
                            }
                        }
                        
                        // Final check: Ensure we have all required fields before creating card account
                        if (!$hasRequiredFields) {
                            $shouldCreateCardAccount = false;
                            Log::info('Skipping card account creation for business account - associated person missing required fields (sandbox)', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'has_associated_person' => $hasAssociatedPersonWithDob,
                                'has_all_required_fields' => $hasRequiredFields,
                                'note' => 'Associated person must have first_name, last_name, and birth_date for card account creation',
                            ]);
                        }
                    }
                } else {
                    // For individual accounts, check if customer has date of birth
                    $customerResult = $this->bridgeService->getCustomer($customerId);
                    if ($customerResult['success'] && isset($customerResult['data'])) {
                        $customer = $customerResult['data'];
                        $hasDateOfBirth = $this->bridgeService->customerHasDateOfBirth($customer);

                        // If Bridge doesn't have birth_date, try to get it from submission_data and update Bridge
                        if (!$hasDateOfBirth) {
                            // Get submission from bridge_kyc_kyb_submissions table
                            $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                                ->where('bridge_customer_id', $customerId)
                                ->where('type', 'kyc') // Individual customer
                                ->first();

                            $birthDate = null;

                            if ($submission && $submission->submission_data) {
                                $submissionData = is_array($submission->submission_data)
                                    ? $submission->submission_data
                                    : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);

                                // Get birth_date from submission_data
                                $birthDate = $submissionData['birth_date'] ?? null;

                                // If birth_date is a string, try to parse it
                                if ($birthDate && is_string($birthDate)) {
                                    try {
                                        $birthDateObj = new \DateTime($birthDate);
                                        $birthDate = $birthDateObj->format('Y-m-d');
                                    } catch (\Exception $e) {
                                        Log::warning('Failed to parse birth_date from submission_data', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'birth_date_string' => $birthDate,
                                            'error' => $e->getMessage(),
                                        ]);
                                        $birthDate = null;
                                    }
                                } elseif ($birthDate instanceof \DateTime || $birthDate instanceof \Carbon\Carbon) {
                                    $birthDate = $birthDate->format('Y-m-d');
                                }
                            }

                            if ($birthDate) {
                                // Update Bridge customer with birth_date from submission_data
                                $updateResult = $this->bridgeService->updateCustomer($customerId, [
                                    'birth_date' => $birthDate,
                                ]);

                                if ($updateResult['success']) {
                                    $hasDateOfBirth = true;
                                    Log::info('Updated individual customer with birth_date from submission_data', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'submission_id' => $submission->id ?? null,
                                        'birth_date' => $birthDate,
                                    ]);
                                } else {
                                    Log::warning('Failed to update individual customer with birth_date from submission_data', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'submission_id' => $submission->id ?? null,
                                        'birth_date' => $birthDate,
                                        'error' => $updateResult['error'] ?? 'Unknown error',
                                    ]);
                                }
                            }

                            if (! $hasDateOfBirth) {
                                $shouldCreateCardAccount = false;
                                Log::info('Skipping card account creation - individual needs cards endorsement KYC (date of birth not confirmed)', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'submission_id' => $submission->id ?? null,
                                    'has_submission' => $submission ? true : false,
                                    'has_submission_data' => $submission && $submission->submission_data ? true : false,
                                    'has_birth_date_in_submission' => isset($submissionData['birth_date']),
                                    'needs_cards_endorsement' => $this->bridgeService->customerNeedsCardsEndorsementFlow($customer),
                                ]);
                            }
                        }

                        if ($shouldCreateCardAccount && $this->bridgeService->customerNeedsCardsEndorsementFlow($customer)) {
                            $endorsementInfo = $this->bridgeService->getCardsEndorsementInfo($customer);

                            if (! $endorsementInfo['approved']) {
                                if (! $endorsementInfo['exists']) {
                                    Log::info('Requesting cards endorsement for individual account', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                    ]);

                                    $endorsementResult = $this->bridgeService->requestEndorsement($customerId, [
                                        'endorsement_type' => 'cards',
                                    ]);

                                    if ($endorsementResult['success']) {
                                        Log::info('Cards endorsement requested successfully for individual account', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'endorsement_data' => $endorsementResult['data'] ?? null,
                                        ]);
                                    } else {
                                        Log::warning('Failed to request cards endorsement for individual account', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'error' => $endorsementResult['error'] ?? 'Unknown error',
                                        ]);
                                    }
                                } else {
                                    Log::info('Cards endorsement exists but not approved yet for individual account', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'endorsement_status' => $endorsementInfo['status'],
                                    ]);
                                }

                                $shouldCreateCardAccount = false;
                                Log::info('Skipping card account creation for individual account - cards endorsement not approved', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'endorsement_exists' => $endorsementInfo['exists'],
                                    'endorsement_status' => $endorsementInfo['status'],
                                ]);
                            }
                        }
                    } else {
                        // If we can't get customer from Bridge, try to update with submission_data anyway
                        $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                            ->where('bridge_customer_id', $customerId)
                            ->where('type', 'kyc') // Individual customer
                            ->first();

                        $birthDate = null;

                        if ($submission && $submission->submission_data) {
                            $submissionData = is_array($submission->submission_data)
                                ? $submission->submission_data
                                : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);

                            $birthDate = $submissionData['birth_date'] ?? null;

                            // If birth_date is a string, try to parse it
                            if ($birthDate && is_string($birthDate)) {
                                try {
                                    $birthDateObj = new \DateTime($birthDate);
                                    $birthDate = $birthDateObj->format('Y-m-d');
                                } catch (\Exception $e) {
                                    $birthDate = null;
                                }
                            } elseif ($birthDate instanceof \DateTime || $birthDate instanceof \Carbon\Carbon) {
                                $birthDate = $birthDate->format('Y-m-d');
                            }
                        }

                        if ($birthDate) {
                            // Try to update Bridge customer with birth_date from submission_data
                            $updateResult = $this->bridgeService->updateCustomer($customerId, [
                                'birth_date' => $birthDate,
                            ]);

                            if ($updateResult['success']) {
                                $hasDateOfBirth = true;
                                Log::info('Updated individual customer with birth_date from submission_data (customer fetch failed)', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'submission_id' => $submission->id ?? null,
                                    'birth_date' => $birthDate,
                                ]);
                            } else {
                                $shouldCreateCardAccount = false;
                                Log::warning('Failed to update individual customer with birth_date from submission_data and could not fetch customer', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'submission_id' => $submission->id ?? null,
                                    'birth_date' => $birthDate,
                                    'error' => $updateResult['error'] ?? 'Unknown error',
                                ]);
                            }
                        } else {
                            $shouldCreateCardAccount = false;
                            Log::info('Skipping card account creation - cannot get customer from Bridge and no birth_date in submission_data', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'has_submission' => $submission ? true : false,
                            ]);
                        }
                    }
                }

                if ($shouldCreateCardAccount) {
                // Create card account - Bridge.xyz API may auto-create, but we explicitly create it
                // POST /customers/{customerId}/card_accounts
                $cardData = $this->bridgeService->buildCardAccountPayloadForIntegration($integration);
                
                $cardAccountResult = $this->bridgeService->issueVirtualCard($integration, $customerId, $isBusiness);

                if ($cardAccountResult['success'] && isset($cardAccountResult['data'])) {
                    $cardAccountData = $cardAccountResult['data'];
                } else {
                    // Card account creation may fail if not available or already exists
                    // This is acceptable per Bridge.xyz docs
                        $errorMessage = $cardAccountResult['error'] ?? $cardAccountResult['message'] ?? 'Card account may be auto-created';
                        $errorCode = $cardAccountResult['response']['code'] ?? '';

                        // Check if error is about missing date of birth
                        if ($errorCode === 'bad_request' && stripos($errorMessage, 'date of birth') !== false) {
                            // Try to update birth_date and retry
                            if ($isBusiness) {
                                // For business, update associated person (control person) birth_date
                                $associatedPersonsResult = $this->bridgeService->getAssociatedPersons($customerId);
                                
                                if ($associatedPersonsResult['success'] && !empty($associatedPersonsResult['data'])) {
                                    foreach ($associatedPersonsResult['data'] as $associatedPerson) {
                                        $associatedPersonId = $associatedPerson['id'] ?? null;
                                        
                                        if ($associatedPersonId) {
                                            // Get control person from database
                                            $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                                                ->where('bridge_customer_id', $customerId)
                                                ->first();
                                            
                                            $birthDate = null;
                                            $controlPerson = null;
                                            
                                            if ($submission) {
                                                $controlPerson = \App\Models\ControlPerson::where('bridge_kyc_kyb_submission_id', $submission->id)
                                                    ->where('bridge_associated_person_id', $associatedPersonId)
                                                    ->first();
                                                
                                                if ($controlPerson && $controlPerson->birth_date) {
                                                    $birthDate = $controlPerson->birth_date->format('Y-m-d');
                                                } elseif ($submission->submission_data) {
                                                    $submissionData = is_array($submission->submission_data)
                                                        ? $submission->submission_data
                                                        : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);
                                                    
                                                    $controlPersonData = $submissionData['control_person'] ?? null;
                                                    if ($controlPersonData && isset($controlPersonData['birth_date'])) {
                                                        $birthDateRaw = $controlPersonData['birth_date'];
                                                        if (is_string($birthDateRaw)) {
                                                            try {
                                                                $birthDateObj = new \DateTime($birthDateRaw);
                                                                $birthDate = $birthDateObj->format('Y-m-d');
                                                            } catch (\Exception $e) {
                                                                Log::warning('Failed to parse birth_date for retry', [
                                                                    'integration_id' => $integration->id,
                                                                    'customer_id' => $customerId,
                                                                    'error' => $e->getMessage(),
                                                                ]);
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                if ($birthDate && $associatedPersonId) {
                                                    $updateResult = $this->bridgeService->updateAssociatedPerson($customerId, $associatedPersonId, [
                                                        'birth_date' => $birthDate,
                                                    ]);
                                                    
                                                    if ($updateResult['success']) {
                                                        Log::info('Updated associated person birth_date and retrying card account creation', [
                                                            'integration_id' => $integration->id,
                                                            'customer_id' => $customerId,
                                                            'associated_person_id' => $associatedPersonId,
                                                            'birth_date' => $birthDate,
                                                        ]);
                                                        
                                                        // Retry card account creation
                                                        $cardAccountResult = $this->bridgeService->issueVirtualCard($integration, $customerId, $isBusiness);
                                                        
                                                        if ($cardAccountResult['success'] && isset($cardAccountResult['data'])) {
                                                            $cardAccountData = $cardAccountResult['data'];
                                                            Log::info('Card account created successfully after updating birth_date', [
                                                                'integration_id' => $integration->id,
                                                                'customer_id' => $customerId,
                                                            ]);
                                                        }
                                                    }
                                                }
                                            }
                                            break; // Only process first associated person
                                        }
                                    }
                                }
                            } else {
                                // For individual, update customer birth_date
                                $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                                    ->where('bridge_customer_id', $customerId)
                                    ->where('type', 'kyc')
                                    ->first();
                                
                                $birthDate = null;
                                
                                if ($submission && $submission->submission_data) {
                                    $submissionData = is_array($submission->submission_data)
                                        ? $submission->submission_data
                                        : (is_string($submission->submission_data) ? json_decode($submission->submission_data, true) : []);
                                    
                                    $birthDate = $submissionData['birth_date'] ?? null;
                                    
                                    if ($birthDate && is_string($birthDate)) {
                                        try {
                                            $birthDateObj = new \DateTime($birthDate);
                                            $birthDate = $birthDateObj->format('Y-m-d');
                                        } catch (\Exception $e) {
                                            $birthDate = null;
                                        }
                                    }
                                }
                                
                                if ($birthDate) {
                                    $updateResult = $this->bridgeService->updateCustomer($customerId, [
                                        'birth_date' => $birthDate,
                                    ]);
                                    
                                    if ($updateResult['success']) {
                                        Log::info('Updated individual customer birth_date and retrying card account creation', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'birth_date' => $birthDate,
                                        ]);
                                        
                                        // Retry card account creation
                                        $cardAccountResult = $this->bridgeService->issueVirtualCard($integration, $customerId, $isBusiness);
                                        
                                        if ($cardAccountResult['success'] && isset($cardAccountResult['data'])) {
                                            $cardAccountData = $cardAccountResult['data'];
                                            Log::info('Card account created successfully after updating birth_date', [
                                                'integration_id' => $integration->id,
                                                'customer_id' => $customerId,
                                            ]);
                                        }
                                    }
                                }
                            }
                            
                            // If still failed after retry, log and continue
                            if (!isset($cardAccountData)) {
                                Log::warning('Card account creation failed after birth_date update attempt', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'is_business' => $isBusiness,
                                    'error' => $errorMessage,
                                ]);
                            }
                        } elseif ($errorCode === 'not_allowed' || stripos($errorMessage, 'cards product has not been enabled') !== false || stripos($errorMessage, 'cards-sandbox') !== false) {
                            // Try to enable cards product automatically (only in sandbox)
                            if ($this->bridgeService->isSandbox()) {
                                Log::info('Card account creation failed - attempting to enable cards product', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'environment' => 'sandbox',
                                ]);

                                $enableResult = $this->bridgeService->enableCardsProduct();

                                if ($enableResult['success']) {
                                    Log::info('Cards product enabled - retrying card account creation', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                    ]);

                                    // Retry creating card account after enabling
                                    $cardAccountResult = $this->bridgeService->issueVirtualCard($integration, $customerId, $isBusiness);

                                    if ($cardAccountResult['success'] && isset($cardAccountResult['data'])) {
                                        $cardAccountData = $cardAccountResult['data'];
                                    } else {
                                        Log::warning('Card account creation still failed after enabling cards product', [
                                            'integration_id' => $integration->id,
                                            'customer_id' => $customerId,
                                            'error' => $cardAccountResult['error'] ?? 'Unknown error',
                                        ]);
                                    }
                                } else {
                                    Log::warning('Failed to enable cards product during card account creation', [
                                        'integration_id' => $integration->id,
                                        'customer_id' => $customerId,
                                        'error' => $enableResult['error'] ?? 'Unknown error',
                                    ]);
                                }
                            } else {
                                Log::info('Card account creation skipped - cards product not enabled in production', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'environment' => 'production',
                                    'note' => 'Enable cards product in Bridge dashboard to create card accounts',
                                ]);
                            }
                        } elseif (stripos($errorMessage, 'date of birth') !== false) {
                            // Check if error is about missing date of birth
                            Log::info('Card account creation skipped - customer does not have date of birth', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                            ]);
                        } else {
                    Log::info('Card account creation result (may be auto-created by Bridge)', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'success' => $cardAccountResult['success'] ?? false,
                                'message' => $errorMessage,
                    ]);

                            $cardsReady = $this->bridgeService->ensureCardsProductEnabled();
                            $cardsEnabled = ($cardsReady['success'] ?? false)
                                || ($cardsReady['already_enabled'] ?? false)
                                || ($cardsReady['skipped'] ?? false);

                            // Only try to fetch card accounts again if cards are enabled
                            // (avoiding another error if cards product is not enabled)
                            if ($cardsEnabled) {
                    $retryResult = $this->bridgeService->getCardAccounts($customerId);
                    if ($retryResult['success'] && isset($retryResult['data']['data']) && count($retryResult['data']['data']) > 0) {
                        $cardAccountData = $retryResult['data']['data'][0];
                                }
                            }
                        }
                    }
                }
            }

            // Store card account data in card_wallets table if we have data and it doesn't exist
            if ($cardAccountData && !$existingCardWallet) {
                $cardAccountId = $cardAccountData['id'] ?? null;
                
                if ($cardAccountId) {
                    // Extract card information from Bridge response
                    $cardNumber = $cardAccountData['card_number'] ?? $cardAccountData['last_four'] ?? null;
                    $cardType = $cardAccountData['type'] ?? $cardAccountData['card_type'] ?? null;
                    $cardBrand = $cardAccountData['brand'] ?? $cardAccountData['card_brand'] ?? null;
                    $expiryMonth = $cardAccountData['expiry_month'] ?? $cardAccountData['exp_month'] ?? null;
                    $expiryYear = $cardAccountData['expiry_year'] ?? $cardAccountData['exp_year'] ?? null;
                    $status = $cardAccountData['status'] ?? 'active';
                    $balance = $cardAccountData['balance'] ?? $cardAccountData['available_balance'] ?? 0;
                    $currency = $cardAccountData['currency'] ?? 'USD';

                    // Create card wallet record in database
                    $cardWallet = CardWallet::create([
                        'bridge_integration_id' => $integration->id,
                        'bridge_customer_id' => $customerId,
                        'bridge_card_account_id' => $cardAccountId,
                        'card_number' => $cardNumber,
                        'card_type' => $cardType,
                        'card_brand' => $cardBrand,
                        'expiry_month' => $expiryMonth,
                        'expiry_year' => $expiryYear,
                        'status' => $status,
                        'balance' => $balance,
                        'currency' => $currency,
                        'card_metadata' => $cardAccountData, // Store full response for reference
                        'is_primary' => true,
                        'last_balance_sync' => now(),
                    ]);

                    Log::info('Bridge card account stored in card_wallets table', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'link_id' => $linkId,
                        'card_wallet_id' => $cardWallet->id,
                        'card_account_id' => $cardAccountId,
                        'environment' => $this->bridgeService->isSandbox() ? 'sandbox' : 'production',
                    ]);
                }
            } elseif ($existingCardWallet) {
                // Update existing card wallet if we have new data
                if ($cardAccountData) {
                    $cardAccountId = $cardAccountData['id'] ?? null;
                    
                    // Only update if we have the same card account ID or if it's missing
                    if (!$existingCardWallet->bridge_card_account_id || $existingCardWallet->bridge_card_account_id === $cardAccountId) {
                        $existingCardWallet->update([
                            'bridge_card_account_id' => $cardAccountId ?? $existingCardWallet->bridge_card_account_id,
                            'card_number' => $cardAccountData['card_number'] ?? $cardAccountData['last_four'] ?? $existingCardWallet->card_number,
                            'card_type' => $cardAccountData['type'] ?? $cardAccountData['card_type'] ?? $existingCardWallet->card_type,
                            'card_brand' => $cardAccountData['brand'] ?? $cardAccountData['card_brand'] ?? $existingCardWallet->card_brand,
                            'expiry_month' => $cardAccountData['expiry_month'] ?? $cardAccountData['exp_month'] ?? $existingCardWallet->expiry_month,
                            'expiry_year' => $cardAccountData['expiry_year'] ?? $cardAccountData['exp_year'] ?? $existingCardWallet->expiry_year,
                            'status' => $cardAccountData['status'] ?? $existingCardWallet->status,
                            'balance' => $cardAccountData['balance'] ?? $cardAccountData['available_balance'] ?? $existingCardWallet->balance,
                            'currency' => $cardAccountData['currency'] ?? $existingCardWallet->currency,
                            'card_metadata' => $cardAccountData,
                            'last_balance_sync' => now(),
                        ]);

                        Log::info('Bridge card account updated in card_wallets table', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'card_wallet_id' => $existingCardWallet->id,
                            'card_account_id' => $cardAccountId,
                        ]);
                    }
                }

                Log::info('Bridge card account already exists in database, skipping creation', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'card_wallet_id' => $existingCardWallet->id,
                ]);
            }
        } catch (\Exception $e) {
                    Log::error('Exception while auto-creating Bridge card account on approval', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

        // 4. Create Liquidation Addresses for crypto deposits (works in both sandbox and production)
        // Create common liquidation addresses: USDC on Solana and Ethereum
        try {
            // Get primary wallet address (for production) or use virtual account address (for sandbox)
            $destinationAddress = null;
            $destinationPaymentRail = null;
            
            if ($existingWallet && $existingWallet->wallet_address) {
                // Production: Use wallet address
                $destinationAddress = $existingWallet->wallet_address;
                $destinationPaymentRail = $existingWallet->chain ?? 'solana';
            } else {
                // Sandbox: Get virtual account address if available
                $virtualAccountsResult = $this->bridgeService->getVirtualAccounts($customerId);
                if ($virtualAccountsResult['success'] && isset($virtualAccountsResult['data']['data'])) {
                    $virtualAccounts = $virtualAccountsResult['data']['data'];
                    if (count($virtualAccounts) > 0) {
                        $virtualAccount = $virtualAccounts[0];
                        $destinationAddress = $virtualAccount['destination']['address'] ?? null;
                        $destinationPaymentRail = $virtualAccount['destination']['payment_rail'] ?? 'ethereum';
                    }
                }
            }

            if ($destinationAddress) {
                // Check existing liquidation addresses in database first
                $existingAddresses = LiquidationAddress::where('bridge_integration_id', $integration->id)
                    ->get()
                    ->toArray();

                // Determine liquidation address configurations based on environment
                $isSandbox = $this->bridgeService->isSandbox();
                
                if ($isSandbox) {
                    // Sandbox: Only create Ethereum liquidation addresses (per Bridge.xyz docs)
                    // Sandbox uses Ethereum payment rail with USDC currency
                    $liquidationConfigs = [
                        ['chain' => 'ethereum', 'currency' => 'usdc', 'destination_payment_rail' => 'ethereum', 'destination_currency' => 'usdc'],
                    ];
                } else {
                    // Production: Create liquidation addresses for common chains
                    $liquidationConfigs = [
                        ['chain' => 'solana', 'currency' => 'usdc', 'destination_payment_rail' => 'solana', 'destination_currency' => 'usdc'],
                        ['chain' => 'ethereum', 'currency' => 'usdc', 'destination_payment_rail' => 'ethereum', 'destination_currency' => 'usdc'],
                    ];
                }

                foreach ($liquidationConfigs as $config) {
                    // Check if liquidation address already exists for this chain/currency
                    $exists = LiquidationAddress::where('bridge_integration_id', $integration->id)
                        ->where('chain', $config['chain'])
                        ->where('currency', $config['currency'])
                        ->exists();

                    if (!$exists) {
                        try {
                            $liquidationData = [
                                'chain' => $config['chain'],
                                'currency' => $config['currency'],
                                'destination_payment_rail' => $config['destination_payment_rail'],
                                'destination_currency' => $config['destination_currency'],
                                'destination_address' => $destinationAddress,
                            ];

                            $liquidationResult = $this->bridgeService->createLiquidationAddress($customerId, $liquidationData);

                            if ($liquidationResult['success'] && isset($liquidationResult['data'])) {
                                $liquidationData = $liquidationResult['data'];
                                
                                // Store liquidation address in database
                                LiquidationAddress::updateOrCreate(
                                    [
                                        'bridge_integration_id' => $integration->id,
                                        'chain' => $config['chain'],
                                        'currency' => $config['currency'],
                                    ],
                                    [
                                        'bridge_customer_id' => $customerId,
                                        'bridge_liquidation_address_id' => $liquidationData['id'] ?? null,
                                        'address' => $liquidationData['address'] ?? null,
                                        'destination_payment_rail' => $config['destination_payment_rail'],
                                        'destination_currency' => $config['destination_currency'],
                                        'destination_address' => $destinationAddress,
                                        'return_address' => $liquidationData['return_address'] ?? null,
                                        'state' => $liquidationData['state'] ?? 'active',
                                        'liquidation_metadata' => $liquidationData,
                                        'last_sync_at' => now(),
                                    ]
                                );

                                Log::info('Bridge liquidation address auto-created on approval', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'chain' => $config['chain'],
                                    'currency' => $config['currency'],
                                    'liquidation_address_id' => $liquidationData['id'] ?? null,
                                    'liquidation_address' => $liquidationData['address'] ?? null,
                                ]);
                            } else {
                                Log::warning('Failed to auto-create Bridge liquidation address on approval', [
                                    'integration_id' => $integration->id,
                                    'customer_id' => $customerId,
                                    'chain' => $config['chain'],
                                    'currency' => $config['currency'],
                                    'error' => $liquidationResult['error'] ?? 'Unknown error',
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::error('Exception while auto-creating Bridge liquidation address on approval', [
                                'integration_id' => $integration->id,
                                'customer_id' => $customerId,
                                'chain' => $config['chain'],
                                'currency' => $config['currency'],
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    } else {
                        Log::info('Bridge liquidation address already exists, skipping creation', [
                            'integration_id' => $integration->id,
                            'customer_id' => $customerId,
                            'chain' => $config['chain'],
                            'currency' => $config['currency'],
                        ]);
                    }
                }
            } else {
                Log::info('Skipping liquidation address creation: no destination address available', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'has_wallet' => $existingWallet !== null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while auto-creating Bridge liquidation addresses on approval', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle customer deletion - clean up all Bridge-related data
     * 
     * Per Bridge.xyz API documentation, customer deletion webhooks can come in different formats:
     * - event_type: "customer.deleted" or "customer.updated.status_transitioned"
     * - event_object_status: "deleted" or "offboarded"
     * - event_object may contain: deleted_at (timestamp), status: "deleted"/"offboarded"
     * - kyb_status or kyc_status may be "offboarded"
     * 
     * When a Bridge customer is deleted, we clean up:
     * 1. Delete all bridge_wallets (wallets and virtual accounts)
     * 2. Delete all card_wallets
     * 3. Delete all liquidation_addresses
     * 4. Mark bridge_kyc_kyb_submissions as "offboarded" (keep for audit trail)
     * 5. Clear all Bridge-specific fields from bridge_integrations (keep record for re-initialization)
     * 
     * Note: Transactions are kept as they're tied to users/organizations, not Bridge customers directly.
     * Control persons, associated persons, and verification documents are kept for audit trail.
     * 
     * @param BridgeIntegration $integration The Bridge integration
     * @param string $customerId The Bridge customer ID that was deleted
     * @return void
     */
    private function handleCustomerDeletion(BridgeIntegration $integration, string $customerId): void
    {
        Log::info('Processing Bridge customer deletion', [
            'integration_id' => $integration->id,
            'customer_id' => $customerId,
        ]);

        DB::transaction(function () use ($integration, $customerId) {
            try {
                // 1. Delete all bridge_wallets (wallets and virtual accounts)
                $deletedWallets = BridgeWallet::where('bridge_integration_id', $integration->id)->delete();
                Log::info('Deleted bridge wallets for deleted customer', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'deleted_count' => $deletedWallets,
                ]);

                // 2. Delete all card_wallets
                $deletedCardWallets = CardWallet::where('bridge_integration_id', $integration->id)->delete();
                Log::info('Deleted card wallets for deleted customer', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'deleted_count' => $deletedCardWallets,
                ]);

                // 3. Delete all liquidation_addresses
                $deletedLiquidationAddresses = LiquidationAddress::where('bridge_integration_id', $integration->id)->delete();
                Log::info('Deleted liquidation addresses for deleted customer', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'deleted_count' => $deletedLiquidationAddresses,
                ]);

                // 4. Mark bridge_kyc_kyb_submissions as offboarded (keep for audit trail)
                $submissions = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                    ->where('bridge_customer_id', $customerId)
                    ->get();
                
                foreach ($submissions as $submission) {
                    // Update submission status to indicate customer was deleted
                    $submissionData = $submission->submission_data ?? [];
                    if (is_string($submissionData)) {
                        $submissionData = json_decode($submissionData, true) ?? [];
                    }
                    $submissionData['customer_deleted_at'] = now()->toIso8601String();
                    $submissionData['customer_deleted'] = true;
                    $submission->submission_data = $submissionData;
                    $submission->submission_status = 'offboarded'; // Mark as offboarded per Bridge.xyz status
                    $submission->bridge_customer_id = null; // Clear customer ID reference
                    $submission->save();
                }

                Log::info('Marked KYB/KYC submissions as offboarded for deleted customer', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'submission_count' => $submissions->count(),
                ]);
                
                // Note: control_persons and associated_persons are kept (cascade delete would remove them if we deleted submissions)
                // verification_documents are also kept for audit trail

                // 5. Clear bridge_customer_id and all related fields from integration
                // Keep the integration record but clear Bridge-specific data
                // This allows the user/organization to re-initialize if needed
                $integration->bridge_customer_id = null;
                $integration->bridge_wallet_id = null;
                // wallet_address and wallet_chain are stored on BridgeWallet, not BridgeIntegration
                $integration->kyc_status = 'not_started';
                $integration->kyb_status = 'not_started';
                $integration->tos_status = null;
                // tos_accepted is not a column in bridge_integrations table
                $integration->tos_link_url = null;
                $integration->kyc_link_url = null;
                $integration->kyb_link_url = null;
                $integration->kyc_link_id = null; // Clear KYC link ID if exists
                
                // Update metadata to record deletion
                $metadata = $integration->bridge_metadata ?? [];
                if (is_string($metadata)) {
                    $metadata = json_decode($metadata, true) ?? [];
                }
                $metadata['customer_deleted'] = true;
                $metadata['customer_deleted_at'] = now()->toIso8601String();
                $metadata['deleted_customer_id'] = $customerId;
                $integration->bridge_metadata = $metadata;
                
                $integration->save();

                Log::info('Cleared Bridge customer data from integration', [
                    'integration_id' => $integration->id,
                    'deleted_customer_id' => $customerId,
                ]);

                Log::info('Bridge customer deletion processed successfully', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'deleted_wallets' => $deletedWallets,
                    'deleted_card_wallets' => $deletedCardWallets,
                    'deleted_liquidation_addresses' => $deletedLiquidationAddresses,
                    'updated_submissions' => $submissions->count(),
                ]);
            } catch (\Exception $e) {
                Log::error('Exception while processing Bridge customer deletion', [
                    'integration_id' => $integration->id,
                    'customer_id' => $customerId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e; // Re-throw to rollback transaction
            }
        });
    }

    /**
     * Recursively extract string values from nested requirement structures
     * Handles structures like: ["requirement1", {"any_of": ["req2", "req3"]}]
     * 
     * @param mixed $item
     * @return array
     */
    private function extractRequirementStrings($item): array
    {
        $result = [];
        
        if (is_string($item)) {
            $result[] = $item;
        } elseif (is_array($item)) {
            // If it's an associative array with keys like "any_of", "all_of", extract the values
            if (isset($item['any_of']) && is_array($item['any_of'])) {
                foreach ($item['any_of'] as $subItem) {
                    $result = array_merge($result, $this->extractRequirementStrings($subItem));
                }
            } elseif (isset($item['all_of']) && is_array($item['all_of'])) {
                foreach ($item['all_of'] as $subItem) {
                    $result = array_merge($result, $this->extractRequirementStrings($subItem));
                }
            } else {
                // Regular array, process each item
                foreach ($item as $subItem) {
                    $result = array_merge($result, $this->extractRequirementStrings($subItem));
                }
            }
        }
        
        return $result;
    }

    /**
     * Process missing requirements from Bridge endorsements
     * Detects missing documents and data requirements dynamically
     * 
     * @param BridgeIntegration $integration
     * @param array $endorsements
     * @param string $customerId
     */
    private function processMissingRequirements(BridgeIntegration $integration, array $endorsements, string $customerId): void
    {
        if (empty($endorsements) || !is_array($endorsements)) {
            return;
        }

        $allMissingRequirements = [];
        $allIssues = [];
        $endorsementDetails = [];

        foreach ($endorsements as $endorsement) {
            $endorsementName = $endorsement['name'] ?? 'unknown';
            $endorsementStatus = $endorsement['status'] ?? null;
            $requirements = $endorsement['requirements'] ?? [];
            
            // Extract missing requirements
            $missing = $requirements['missing'] ?? [];
            $issues = $requirements['issues'] ?? [];
            
            // Handle nested missing structure: { "all_of": ["requirement1", "requirement2"] }
            $missingList = [];
            if (isset($missing['all_of']) && is_array($missing['all_of'])) {
                // Extract string values from potentially nested structure
                $missingList = $this->extractRequirementStrings($missing['all_of']);
            } elseif (is_array($missing) && !isset($missing['all_of'])) {
                // Handle flat array structure
                $missingList = $this->extractRequirementStrings($missing);
            }
            
            // Handle issues array
            $issuesList = [];
            if (is_array($issues)) {
                if (isset($issues['all_of']) && is_array($issues['all_of'])) {
                    $issuesList = $this->extractRequirementStrings($issues['all_of']);
                } elseif (!isset($issues['all_of'])) {
                    $issuesList = $this->extractRequirementStrings($issues);
                }
            }
            
            if (!empty($missingList) || !empty($issuesList)) {
                $endorsementDetails[] = [
                    'name' => $endorsementName,
                    'status' => $endorsementStatus,
                    'missing' => $missingList,
                    'issues' => $issuesList,
                ];
                
                $allMissingRequirements = array_merge($allMissingRequirements, $missingList);
                $allIssues = array_merge($allIssues, $issuesList);
            }
        }

        // Remove duplicates (now safe since we only have strings)
        $allMissingRequirements = array_unique($allMissingRequirements);
        $allIssues = array_unique($allIssues);

        // Only update if there are missing requirements or issues
        if (!empty($allMissingRequirements) || !empty($allIssues)) {
            $metadata = $integration->bridge_metadata ?? [];
            if (!is_array($metadata)) {
                $metadata = is_string($metadata) ? json_decode($metadata, true) : [];
            }
            
            // Store pending requirements with detailed information
            $metadata['pending_requirements'] = [
                'missing' => array_values($allMissingRequirements),
                'issues' => array_values($allIssues),
                'endorsements' => $endorsementDetails,
                'updated_at' => now()->toIso8601String(),
                'source' => 'bridge_webhook',
            ];
            
            // Categorize requirements into documents vs data fields
            $documentRequirements = [];
            $dataRequirements = [];
            
            // Common document requirements from Bridge
            $documentTypes = [
                'proof_of_address',
                'government_id_verification',
                'government_id_front',
                'government_id_back',
                'business_formation_document',
                'business_ownership_document',
                'determination_letter_501c3',
                'id_front',
                'id_back',
            ];
            
            // Common data field requirements
            $dataFieldTypes = [
                'control_person',
                'ultimate_beneficial_owners',
                'physical_address',
                'business_address',
                'tax_id',
                'ein',
                'ssn',
                'date_of_birth',
                'birth_date',
            ];
            
            foreach ($allMissingRequirements as $requirement) {
                $requirementLower = strtolower($requirement);
                
                // Check if it's a document requirement
                $isDocument = false;
                foreach ($documentTypes as $docType) {
                    if (str_contains($requirementLower, $docType)) {
                        $isDocument = true;
                        $documentRequirements[] = $requirement;
                        break;
                    }
                }
                
                // Check if it's a data field requirement
                if (!$isDocument) {
                    foreach ($dataFieldTypes as $dataType) {
                        if (str_contains($requirementLower, $dataType)) {
                            $dataRequirements[] = $requirement;
                            break;
                        }
                    }
                }
                
                // If not categorized, assume it might be a document (safer default)
                if (!$isDocument && empty($dataRequirements)) {
                    $documentRequirements[] = $requirement;
                }
            }
            
            $metadata['pending_requirements']['categorized'] = [
                'documents' => array_values(array_unique($documentRequirements)),
                'data_fields' => array_values(array_unique($dataRequirements)),
            ];
            
            $integration->bridge_metadata = $metadata;
            
            Log::info('Bridge KYC/KYB missing requirements detected', [
                'integration_id' => $integration->id,
                'customer_id' => $customerId,
                'missing_requirements' => $allMissingRequirements,
                'issues' => $allIssues,
                'document_requirements' => $documentRequirements,
                'data_requirements' => $dataRequirements,
                'endorsement_count' => count($endorsementDetails),
            ]);
            
            // TODO: Notify user/organization about missing requirements
            // This can be done via:
            // 1. Email notification
            // 2. In-app notification
            // 3. Push notification (if implemented)
            // 4. Update submission status to 'needs_more_info' if applicable
            
            // Update submission status if exists
            $submission = \App\Models\BridgeKycKybSubmission::where('bridge_integration_id', $integration->id)
                ->where('bridge_customer_id', $customerId)
                ->latest()
                ->first();
            
            if ($submission && !empty($allMissingRequirements)) {
                // Only update to needs_more_info if status is under_review or incomplete
                if (in_array($submission->submission_status, ['under_review', 'incomplete', 'not_started'])) {
                    $submission->submission_status = 'needs_more_info';
                    
                    // Store missing requirements in submission data
                    $submissionData = $submission->submission_data ?? [];
                    if (is_string($submissionData)) {
                        $submissionData = json_decode($submissionData, true) ?? [];
                    }
                    $submissionData['bridge_missing_requirements'] = [
                        'missing' => array_values($allMissingRequirements),
                        'issues' => array_values($allIssues),
                        'detected_at' => now()->toIso8601String(),
                    ];
                    $submission->submission_data = $submissionData;
                    $submission->save();
                    
                    Log::info('Bridge submission status updated to needs_more_info due to missing requirements', [
                        'submission_id' => $submission->id,
                        'integration_id' => $integration->id,
                        'missing_requirements' => $allMissingRequirements,
                    ]);
                }
            }
        } else {
            // Clear pending requirements if all requirements are met
            $metadata = $integration->bridge_metadata ?? [];
            if (!is_array($metadata)) {
                $metadata = is_string($metadata) ? json_decode($metadata, true) : [];
            }
            
            if (isset($metadata['pending_requirements'])) {
                // Only clear if it was set by a webhook (not by admin request)
                $pendingReqs = $metadata['pending_requirements'] ?? [];
                if (isset($pendingReqs['source']) && $pendingReqs['source'] === 'bridge_webhook') {
                    unset($metadata['pending_requirements']);
                    $integration->bridge_metadata = $metadata;
                    
                    Log::info('Bridge pending requirements cleared - all requirements met', [
                        'integration_id' => $integration->id,
                        'customer_id' => $customerId,
                    ]);
                }
            }
        }
    }
}
