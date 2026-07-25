<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientPhazeBalanceException;
use App\Models\GiftCard;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\BiuPlatformFeeService;
use App\Services\GiftCardGiftedPointsPolicy;
use App\Services\GiftCardRedemptionService;
use App\Services\GiftCardRevenueShareService;
use App\Services\GiftCardService;
use App\Services\PhazeBalanceService;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\Stripe;

class GiftCardController extends Controller
{
    protected $giftCardService;

    protected PhazeBalanceService $phazeBalanceService;

    protected GiftCardRedemptionService $giftCardRedemptionService;

    public function __construct(
        GiftCardService $giftCardService,
        PhazeBalanceService $phazeBalanceService,
        GiftCardRedemptionService $giftCardRedemptionService,
    ) {
        $this->giftCardService = $giftCardService;
        $this->phazeBalanceService = $phazeBalanceService;
        $this->giftCardRedemptionService = $giftCardRedemptionService;
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Display gift card brands from Phaze API (marketplace style)
     * - Shows brands directly from Phaze API
     * - Filter by country
     * - Search functionality
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $search = $request->input('search', '');
        $countryFilter = $request->input('country', 'USA'); // Default to USA
        $perPage = $request->input('per_page', 12);
        $currentPage = $request->input('page', 1);

        // Available countries in Phaze API
        $availableCountries = [
            'USA' => 'United States',
            'Canada' => 'Canada',
            'UK' => 'United Kingdom',
            'France' => 'France',
            'India' => 'India',
            'Italy' => 'Italy',
            'Japan' => 'Japan',
        ];

        // Fetch one page from Phaze API (Phaze uses ?currentPage= — already paginated server-side).
        $pagePayload = $this->giftCardService->getGiftBrandsPagePayload($countryFilter, (int) $currentPage);
        $brands = $pagePayload['brands'] ?? [];

        // Ensure brands is an array
        if (! is_array($brands)) {
            Log::warning('GiftCardController: getGiftBrandsPagePayload returned non-array brands', [
                'country' => $countryFilter,
                'currentPage' => $currentPage,
                'type' => gettype($brands),
            ]);
            $brands = [];
        }

        // Log if no brands found for debugging
        if (empty($brands)) {
            Log::info('GiftCardController: No brands found', [
                'country' => $countryFilter,
                'currentPage' => $currentPage,
                'search' => $search,
            ]);
        }

        // Apply search filter if provided (filters the current Phaze page only)
        if ($search && ! empty($brands)) {
            $searchLower = strtolower($search);
            $brands = array_filter($brands, function ($brand) use ($searchLower) {
                $brandName = strtolower($brand['productName'] ?? '');

                return strpos($brandName, $searchLower) !== false;
            });
            // Re-index array after filter
            $brands = array_values($brands);
        }

        // Do not slice again by $currentPage/$perPage — that duplicated Phaze pagination and emptied page 2+.
        $paginatedBrands = array_values($brands);

        $apiTotal = $pagePayload['total'] ?? null;
        $apiLastPage = $pagePayload['last_page'] ?? null;
        $apiPerPage = $pagePayload['per_page'] ?? null;

        $n = count($paginatedBrands);
        $step = max(1, $apiPerPage ?? $n ?: (int) $perPage);

        if ($apiLastPage !== null) {
            $lastPage = max(1, (int) $apiLastPage);
        } elseif ($n === 0 && (int) $currentPage > 1) {
            $lastPage = max(1, (int) $currentPage - 1);
        } else {
            // Unknown Phaze totals: allow "next" while this page has rows; stop when user hits an empty page
            $lastPage = $n > 0 ? (int) $currentPage + 1 : max(1, (int) $currentPage);
        }

        if ($apiTotal !== null) {
            $total = (int) $apiTotal;
        } elseif ($n > 0) {
            $total = (((int) $currentPage - 1) * $step) + $n;
        } else {
            $total = 0;
        }

        // Ensure all brands have productName for display (already handled in service, but double-check)
        $paginatedBrands = array_map(function ($brand) {
            // Check if productName is missing, empty, or looks like a productId
            $currentName = $brand['productName'] ?? '';

            // If name is missing, empty, numeric, or starts with "Gift Card #", try to find a better name
            if (empty($currentName) ||
                is_numeric($currentName) ||
                strpos($currentName, 'Gift Card #') === 0 ||
                preg_match('/^[0-9]+/', $currentName)) {

                // Check other possible field names
                $brandName = null;
                $possibleFields = ['name', 'brandName', 'title', 'brand', 'displayName', 'productTitle'];

                foreach ($possibleFields as $field) {
                    if (isset($brand[$field]) &&
                        ! empty($brand[$field]) &&
                        ! is_numeric($brand[$field]) &&
                        ! preg_match('/^[0-9]+/', $brand[$field])) {
                        $brandName = trim($brand[$field]);
                        break;
                    }
                }

                if ($brandName && strlen($brandName) > 0) {
                    $brand['productName'] = $brandName;
                } else {
                    // Use generic name instead of productId
                    $brand['productName'] = 'Gift Card';
                }
            }

            return $this->withGiftedEligibility($brand);
        }, $paginatedBrands);

        $from = $n > 0 ? $total - $n + 1 : 0;
        $to = $n > 0 ? $total : 0;
        if ($apiTotal !== null && $to > $apiTotal) {
            $to = $apiTotal;
        }

        $giftCards = [
            'data' => $paginatedBrands,
            'current_page' => (int) $currentPage,
            'last_page' => $lastPage,
            'per_page' => (int) $perPage,
            'total' => $total,
            'from' => $from,
            'to' => $to,
            'links' => $this->generatePaginationLinks($currentPage, $lastPage, $request),
        ];

        return Inertia::render('GiftCards/Index', [
            'giftCards' => $giftCards,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ] : null,
            'filters' => [
                'search' => $search,
                'country' => $countryFilter,
                'per_page' => $perPage,
            ],
            'availableCountries' => $availableCountries,
        ]);
    }

    /**
     * Generate pagination links
     */
    private function generatePaginationLinks($currentPage, $lastPage, $request): array
    {
        $links = [];
        $queryParams = $request->except('page');

        // Previous link
        if ($currentPage > 1) {
            $links[] = [
                'url' => route('gift-cards.index', array_merge($queryParams, ['page' => $currentPage - 1])),
                'label' => '&laquo; Previous',
                'active' => false,
            ];
        } else {
            $links[] = [
                'url' => null,
                'label' => '&laquo; Previous',
                'active' => false,
            ];
        }

        // Page number links
        for ($i = 1; $i <= $lastPage; $i++) {
            if ($i == 1 || $i == $lastPage || ($i >= $currentPage - 2 && $i <= $currentPage + 2)) {
                $links[] = [
                    'url' => route('gift-cards.index', array_merge($queryParams, ['page' => $i])),
                    'label' => (string) $i,
                    'active' => $i == $currentPage,
                ];
            } elseif ($i == $currentPage - 3 || $i == $currentPage + 3) {
                $links[] = [
                    'url' => null,
                    'label' => '...',
                    'active' => false,
                ];
            }
        }

        // Next link
        if ($currentPage < $lastPage) {
            $links[] = [
                'url' => route('gift-cards.index', array_merge($queryParams, ['page' => $currentPage + 1])),
                'label' => 'Next &raquo;',
                'active' => false,
            ];
        } else {
            $links[] = [
                'url' => null,
                'label' => 'Next &raquo;',
                'active' => false,
            ];
        }

        return $links;
    }

    /**
     * Show gift card creation form (for organizations)
     */
    public function create(Request $request)
    {
        $user = Auth::user();

        // Only organizations can create gift cards
        if (! $user) {
            abort(403, 'You must be logged in to create gift cards.');
        }

        // Check role - only organization and admin can create gift cards
        if (! in_array($user->role, ['organization', 'organization_pending', 'admin'])) {
            abort(403, 'Only organizations can create gift cards.');
        }

        $organization = $user->organization;

        // Get selected country from request (default to USA)
        $selectedCountry = $request->input('country', 'USA');

        // Get brands for selected country from Phaze API
        $brands = $this->giftCardService->getGiftBrands($selectedCountry);

        // Check if API key is configured
        $apiKeyConfigured = ! empty(config('services.phaze.api_key'));
        $apiError = null;

        if (! $apiKeyConfigured) {
            $apiError = 'Phaze API key is not configured. Please set PHAZE_API_KEY in your .env file.';
        } elseif (empty($brands)) {
            // Check recent logs for API errors
            $apiError = 'Unable to fetch brands. Please check your Phaze API credentials and ensure the API key is valid.';
        }

        // Available countries in Phaze API
        $availableCountries = [
            'USA' => 'United States',
            'Canada' => 'Canada',
            'UK' => 'United Kingdom',
            'France' => 'France',
            'India' => 'India',
            'Italy' => 'Italy',
            'Japan' => 'Japan',
        ];

        return Inertia::render('GiftCards/Create', [
            'brands' => $brands,
            'selectedCountry' => $selectedCountry,
            'availableCountries' => $availableCountries,
            'apiError' => $apiError,
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
            ] : null,
        ]);
    }

    /**
     * Store a new gift card (organization creates)
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Only organizations can create gift cards
        if (! $user || ! in_array($user->role, ['organization', 'organization_pending', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only organizations can create gift cards.',
            ], 403);
        }

        // Role check is already done by route middleware

        $validated = $request->validate([
            'brand' => 'required|string',
            'brand_name' => 'required|string',
            'product_id' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'country' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'expires_at' => 'nullable|date|after:today',
        ]);

        // Get brand data from Phaze API if product_id is provided
        $brandData = null;
        if (! empty($validated['product_id'])) {
            // Fetch brand details from Phaze API to store in meta
            // Convert country name to country code for API (e.g., "United States" -> "USA")
            $countryName = $validated['country'] ?? 'United States';
            $countryCode = $this->getCountryCode($countryName);
            $brands = $this->giftCardService->getGiftBrands($countryCode);
            $selectedBrand = collect($brands)->firstWhere('productId', (int) $validated['product_id']);

            if ($selectedBrand) {
                $brandData = [
                    'productId' => $selectedBrand['productId'] ?? null,
                    'productImage' => $selectedBrand['productImage'] ?? null,
                    'denominations' => $selectedBrand['denominations'] ?? [],
                    'valueRestrictions' => $selectedBrand['valueRestrictions'] ?? [],
                    'productDescription' => $selectedBrand['productDescription'] ?? null,
                    'termsAndConditions' => $selectedBrand['termsAndConditions'] ?? null,
                    'howToUse' => $selectedBrand['howToUse'] ?? null,
                    'expiryAndValidity' => $selectedBrand['expiryAndValidity'] ?? null,
                    'discount' => $selectedBrand['discount'] ?? 0,
                ];
            }
        }

        try {
            // Get organization
            $organization = $user->organization;

            if (! $organization) {
                return back()->withErrors([
                    'organization' => 'Organization not found.',
                ]);
            }

            // Generate unique card number (16 digits)
            $cardNumber = $this->generateCardNumber();

            // Set expiration date (default to 1 year from now if not provided)
            $expiresAt = $validated['expires_at']
                ? \Carbon\Carbon::parse($validated['expires_at'])
                : now()->addYear();

            // Store in local database (organization creates the card listing)
            // Note: user_id should be null when creating - it will be set when purchased
            $giftCard = GiftCard::create([
                'user_id' => null, // Will be set when a user purchases this card
                'organization_id' => $organization->id,
                'card_number' => $cardNumber,
                'amount' => $validated['amount'],
                'brand' => $validated['brand'],
                'brand_name' => $validated['brand_name'],
                'country' => $validated['country'] ?? null,
                'currency' => $validated['currency'] ?? 'USD',
                'status' => 'active',
                'expires_at' => $expiresAt,
                'meta' => $brandData, // Store brand data (image, denominations, etc.)
            ]);

            return redirect()->route('gift-cards.created')->with('success', 'Gift card created successfully!');

        } catch (\Exception $e) {
            Log::error('Error creating gift card: '.$e->getMessage(), [
                'user_id' => $user->id,
                'error' => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'error' => 'An error occurred while creating the gift card. Please try again.',
            ]);
        }
    }

    /**
     * Show purchase page for a specific brand (for users)
     * Note: This method is deprecated - users now purchase directly from the marketplace
     */
    public function showPurchase(Request $request, string $brand)
    {
        // Redirect to index page since we now use organization-created gift cards
        return redirect()->route('gift-cards.index');
    }

    /**
     * Process gift card purchase (for users)
     * Creates a new gift card purchase from Phaze API brand
     */
    public function purchase(Request $request)
    {
        $user = Auth::user();
        $isInertiaRequest = $request->header('X-Inertia');

        // Only users can purchase gift cards (guests need to login)
        if (! $user || $user->role !== 'user') {
            if ($isInertiaRequest) {
                return back()->withErrors([
                    'auth' => 'Please login as a user to purchase gift cards.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Please login as a user to purchase gift cards.',
            ], 403);
        }

        $validated = $request->validate([
            'productId' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'organization_id' => 'required|exists:organizations,id',
            'country' => 'required|string',
            'brand_name' => 'required|string',
            'currency' => 'nullable|string|size:3',
            'payment_method' => 'nullable|string|in:believe_points',
        ]);

        try {
            DB::beginTransaction();

            // Phaze `/brands/country/{code}` expects codes like USA, Canada — normalize display names (e.g. United States).
            $countryForPhaze = $this->getCountryCode($validated['country']);

            // Fetch brand details from Phaze API to validate amount restrictions
            $brands = $this->giftCardService->getGiftBrands($countryForPhaze, 1);
            $selectedBrand = null;
            foreach ($brands as $brand) {
                if (isset($brand['productId']) && (int) $brand['productId'] === (int) $validated['productId']) {
                    $selectedBrand = $brand;
                    break;
                }
            }

            if (! $selectedBrand) {
                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'productId' => 'Brand not found.',
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Brand not found.',
                ], 404);
            }

            // Validate amount against brand restrictions
            $valueRestrictions = $selectedBrand['valueRestrictions'] ?? [];
            $minVal = $valueRestrictions['minVal'] ?? 0.01;
            $maxVal = $valueRestrictions['maxVal'] ?? null;

            // If maxVal is 0 or null, treat as no maximum limit
            $hasMaxLimit = $maxVal !== null && $maxVal > 0;

            // Validate amount
            if ($validated['amount'] < $minVal) {
                $errorMessage = 'Amount must be at least '.number_format($minVal, 2).' '.($validated['currency'] ?? 'USD');
                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'amount' => $errorMessage,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 422);
            }

            if ($hasMaxLimit && $validated['amount'] > $maxVal) {
                $errorMessage = 'Amount must be between '.number_format($minVal, 2).' and '.number_format($maxVal, 2).' '.($validated['currency'] ?? 'USD');
                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'amount' => $errorMessage,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 422);
            }

            $purchaseAmount = $validated['amount'];
            $currency = $validated['currency'] ?? 'USD';
            $paymentMethod = $validated['payment_method'] ?? 'believe_points';

            if ($paymentMethod !== 'believe_points') {
                DB::rollBack();

                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'payment_method' => 'Gift cards can only be purchased using Believe Points.',
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Gift cards can only be purchased using Believe Points.',
                ], 422);
            }

            // Get organization
            $organization = \App\Models\Organization::findOrFail($validated['organization_id']);

            // Check if organization has approved gift card terms
            if (! $organization->gift_card_terms_approved) {
                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'organization_id' => 'This organization has not approved the gift card program terms yet. Gift cards cannot be purchased for this organization until they approve the terms in their settings.',
                        'error' => 'Organization approval required: This organization needs to approve the gift card program terms before purchases can be made.',
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'This organization has not approved the gift card program terms yet. Please contact the organization.',
                ], 403);
            }

            $platformFee = BiuPlatformFeeService::getGiftCardPlatformFeeUsd();
            $pointsRequired = BiuPlatformFeeService::giftCardTotalChargedUsd((float) $purchaseAmount);
            $user->refresh();

            if ($user->currentBelievePoints() < $pointsRequired) {
                DB::rollBack();
                $have = $user->currentBelievePoints();
                $message = $platformFee > 0
                    ? "Insufficient Believe Points. You need {$pointsRequired} Available BP (gift card {$purchaseAmount} + platform fee {$platformFee}) but only have {$have}."
                    : "Insufficient Believe Points. You need {$pointsRequired} Available BP but only have {$have}.";

                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'payment_method' => $message,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 400);
            }

            try {
                $giftCard = $this->giftCardRedemptionService->submit(
                    $user,
                    $validated,
                    $selectedBrand,
                    $purchaseAmount,
                    $currency,
                );
            } catch (\RuntimeException $e) {
                DB::rollBack();

                if ($isInertiaRequest) {
                    return back()->withErrors([
                        'payment_method' => $e->getMessage(),
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }

            DB::commit();

            $this->giftCardRedemptionService->scheduleFulfillmentJob($giftCard);

            if ($isInertiaRequest) {
                return redirect()->route('gift-cards.success', ['gift_card_id' => $giftCard->id])
                    ->with('success', 'Gift card redemption submitted successfully.');
            }

            return response()->json([
                'success' => true,
                'message' => 'Gift card redemption submitted successfully.',
                'redirect' => route('gift-cards.success', ['gift_card_id' => $giftCard->id]),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            // Re-throw validation exceptions so Inertia can handle them properly
            throw $e;
        } catch (ApiErrorException $e) {
            DB::rollBack();
            Log::error('Stripe API error (gift card purchase)', [
                'message' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);

            $isInertiaRequest = $request->header('X-Inertia');
            $userMessage = config('app.debug')
                ? $e->getMessage()
                : 'We could not start secure checkout. Please try again in a moment, or contact support if this continues.';

            if ($isInertiaRequest) {
                return back()->withErrors([
                    'error' => $userMessage,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $userMessage,
            ], 502);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing gift card purchase: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            report($e);

            $isInertiaRequest = $request->header('X-Inertia');
            if ($isInertiaRequest) {
                return back()->withErrors([
                    'error' => 'An error occurred while processing your purchase. Please try again.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your purchase.',
            ], 500);
        }
    }

    /**
     * Generate a unique 16-digit card number
     */
    private function generateCardNumber(): string
    {
        return GiftCard::generateUniqueCardNumber();
    }

    /**
     * Convert country name to country code for Phaze API
     */
    private function getCountryCode(string $countryName): string
    {
        $countryMap = [
            'United States' => 'USA',
            'USA' => 'USA',
            'Canada' => 'Canada',
            'United Kingdom' => 'UK',
            'UK' => 'UK',
            'France' => 'France',
            'India' => 'India',
            'Italy' => 'Italy',
            'Japan' => 'Japan',
        ];

        return $countryMap[$countryName] ?? 'USA';
    }

    /**
     * Handle successful payment
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');
        $paymentMethod = $request->get('payment_method', 'stripe');

        // Handle Believe Points payment (no Stripe session)
        if (! $sessionId) {
            $user = auth()->user();
            $giftCardId = $request->get('gift_card_id');

            // Try to get gift card by ID first (if passed in redirect)
            if ($giftCardId && $user) {
                $giftCard = GiftCard::where('id', $giftCardId)
                    ->where('user_id', $user->id)
                    ->where('payment_method', 'believe_points')
                    ->first();

                if ($giftCard) {
                    $giftCard->load(['user', 'organization']);

                    // Get Phaze purchase details (same as show method)
                    $phazePurchaseData = null;
                    $phazeDisbursementData = null;

                    // Try to get purchase details from stored purchase ID in meta
                    $phazePurchaseId = $giftCard->meta['phaze_purchase']['id'] ??
                                       $giftCard->meta['phaze_purchase_id'] ??
                                       $giftCard->external_id ??
                                       null;

                    if ($phazePurchaseId) {
                        $phazePurchaseData = $this->giftCardService->getPurchaseDetails($phazePurchaseId);
                    }

                    // If purchase data not available, use stored data from meta
                    if (! $phazePurchaseData && isset($giftCard->meta['phaze_purchase'])) {
                        $phazePurchaseData = $giftCard->meta['phaze_purchase'];
                    }

                    // Also check for phaze_initial_response as fallback
                    if (! $phazePurchaseData && isset($giftCard->meta['phaze_initial_response'])) {
                        $phazePurchaseData = $giftCard->meta['phaze_initial_response'];
                    }

                    // If we still don't have purchase data but have external_id, try to construct basic info
                    if (! $phazePurchaseData && $giftCard->external_id) {
                        $phazePurchaseData = [
                            'id' => $giftCard->external_id,
                            'status' => $giftCard->meta['phaze_status'] ?? 'pending',
                        ];
                    }

                    // Get disbursement status if available
                    if ($giftCard->phaze_disbursement_id) {
                        $phazeDisbursementData = $this->giftCardService->getDisbursementStatus($giftCard->phaze_disbursement_id);
                    }

                    return Inertia::render('GiftCards/Success', [
                        'giftCard' => $giftCard,
                        'sessionId' => null,
                        'paymentMethod' => 'believe_points',
                        'phazePurchaseData' => $phazePurchaseData,
                        'phazeDisbursementData' => $phazeDisbursementData,
                        'pendingFulfillment' => $giftCard->isPendingFulfillment(),
                        'scheduledFulfillmentAt' => $giftCard->scheduled_fulfillment_at?->toIso8601String(),
                        'user' => [
                            'name' => $giftCard->user->name ?? $user->name,
                            'email' => $giftCard->user->email ?? $user->email,
                        ],
                    ]);
                }
            }

            // Fallback: Get the most recent gift card purchase for the current user (Believe Points payment)
            if ($user) {
                $giftCard = GiftCard::where('user_id', $user->id)
                    ->where('payment_method', 'believe_points')
                    ->whereNull('stripe_session_id') // Only Believe Points purchases
                    ->whereNotNull('purchased_at') // Only purchased cards
                    ->orderBy('purchased_at', 'desc')
                    ->first();

                if ($giftCard && $giftCard->purchased_at && $giftCard->purchased_at->isAfter(now()->subMinutes(10))) {
                    // Gift card was purchased within last 10 minutes (recent purchase)
                    $giftCard->load(['user', 'organization']);

                    // Get Phaze purchase details (same as show method)
                    $phazePurchaseData = null;
                    $phazeDisbursementData = null;

                    // Try to get purchase details from stored purchase ID in meta
                    $phazePurchaseId = $giftCard->meta['phaze_purchase']['id'] ??
                                       $giftCard->meta['phaze_purchase_id'] ??
                                       $giftCard->external_id ??
                                       null;

                    if ($phazePurchaseId) {
                        $phazePurchaseData = $this->giftCardService->getPurchaseDetails($phazePurchaseId);
                    }

                    // If purchase data not available, use stored data from meta
                    if (! $phazePurchaseData && isset($giftCard->meta['phaze_purchase'])) {
                        $phazePurchaseData = $giftCard->meta['phaze_purchase'];
                    }

                    // Also check for phaze_initial_response as fallback
                    if (! $phazePurchaseData && isset($giftCard->meta['phaze_initial_response'])) {
                        $phazePurchaseData = $giftCard->meta['phaze_initial_response'];
                    }

                    // If we still don't have purchase data but have external_id, try to construct basic info
                    if (! $phazePurchaseData && $giftCard->external_id) {
                        $phazePurchaseData = [
                            'id' => $giftCard->external_id,
                            'status' => $giftCard->meta['phaze_status'] ?? 'pending',
                        ];
                    }

                    // Get disbursement status if available
                    if ($giftCard->phaze_disbursement_id) {
                        $phazeDisbursementData = $this->giftCardService->getDisbursementStatus($giftCard->phaze_disbursement_id);
                    }

                    return Inertia::render('GiftCards/Success', [
                        'giftCard' => $giftCard,
                        'sessionId' => null,
                        'paymentMethod' => 'believe_points',
                        'phazePurchaseData' => $phazePurchaseData,
                        'phazeDisbursementData' => $phazeDisbursementData,
                        'pendingFulfillment' => $giftCard->isPendingFulfillment(),
                        'scheduledFulfillmentAt' => $giftCard->scheduled_fulfillment_at?->toIso8601String(),
                        'user' => [
                            'name' => $giftCard->user->name ?? $user->name,
                            'email' => $giftCard->user->email ?? $user->email,
                        ],
                    ]);
                }
            }

            // If no session_id and no recent Believe Points purchase found, show error
            return redirect()->route('gift-cards.index')->withErrors([
                'message' => 'Invalid payment session. Please try purchasing again.',
            ]);
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            // Check payment status
            if ($session->payment_status === 'paid') {
                DB::beginTransaction();

                // Get metadata from session
                $metadata = $session->metadata;
                $userId = $metadata->user_id ?? null;
                $organizationId = $metadata->organization_id ?? null;
                $productId = $metadata->product_id ?? null;
                $purchaseAmount = isset($metadata->purchase_amount) ? (float) $metadata->purchase_amount : 0;
                $brandName = $metadata->brand_name ?? 'Gift Card';
                $country = $metadata->country ?? 'USA';
                $currency = $metadata->currency ?? 'USD';

                if (! $userId || ! $organizationId || ! $productId) {
                    DB::rollBack();

                    return redirect()->route('gift-cards.index')->withErrors([
                        'message' => 'Invalid payment metadata.',
                    ]);
                }

                // Fetch brand details from Phaze API to store in meta (cached)
                $brands = $this->giftCardService->getGiftBrands($country, 1);
                $selectedBrand = null;
                foreach ($brands as $brand) {
                    if (isset($brand['productId']) && (int) $brand['productId'] === (int) $productId) {
                        $selectedBrand = $brand;
                        // Ensure productName exists
                        if (! isset($selectedBrand['productName']) || empty($selectedBrand['productName'])) {
                            $selectedBrand['productName'] = $brandName;
                        }
                        break;
                    }
                }

                // Generate unique card number
                $cardNumber = $this->generateCardNumber();

                // Ensure brand name is set
                $finalBrandName = $selectedBrand['productName'] ?? $brandName;
                if (empty($finalBrandName)) {
                    $finalBrandName = 'Gift Card #'.($productId ?? 'Unknown');
                }

                // Create gift card record
                $giftCard = GiftCard::create([
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'card_number' => $cardNumber,
                    'amount' => $purchaseAmount,
                    'brand' => $finalBrandName,
                    'brand_name' => $finalBrandName,
                    'country' => $country,
                    'currency' => $currency,
                    'status' => 'active',
                    'payment_method' => 'stripe',
                    'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    'stripe_session_id' => $sessionId,
                    'purchased_at' => now(),
                    'expires_at' => now()->addYear(), // Default 1 year expiration
                    'meta' => $selectedBrand ? [
                        'productId' => $selectedBrand['productId'] ?? null,
                        'productImage' => $selectedBrand['productImage'] ?? null,
                        'denominations' => $selectedBrand['denominations'] ?? [],
                        'valueRestrictions' => $selectedBrand['valueRestrictions'] ?? [],
                        'productDescription' => $selectedBrand['productDescription'] ?? null,
                        'termsAndConditions' => $selectedBrand['termsAndConditions'] ?? null,
                        'howToUse' => $selectedBrand['howToUse'] ?? null,
                        'expiryAndValidity' => $selectedBrand['expiryAndValidity'] ?? null,
                        'discount' => $selectedBrand['discount'] ?? 0,
                    ] : null,
                ]);

                // Reload gift card to get relationships
                $giftCard->load(['user', 'organization']);

                // Generate unique Version 4 UUID order ID for Phaze API
                // Phaze requires orderId to be a Version 4 UUID to prevent duplicate orders
                $orderId = \Illuminate\Support\Str::uuid()->toString();

                try {
                    $this->phazeBalanceService->assertCanAfford($purchaseAmount);
                } catch (InsufficientPhazeBalanceException $e) {
                    Log::error('Insufficient Phaze balance after Stripe payment succeeded', [
                        'gift_card_id' => $giftCard->id,
                        'purchase_amount' => $purchaseAmount,
                        'available' => $e->available,
                        'stripe_payment_intent' => $session->payment_intent,
                    ]);

                    $giftCard->update([
                        'status' => 'failed',
                        'meta' => array_merge($giftCard->meta ?? [], [
                            'phaze_balance_insufficient' => true,
                            'failed_at' => now()->toIso8601String(),
                        ]),
                    ]);

                    if ($session->payment_intent) {
                        try {
                            Refund::create([
                                'payment_intent' => $session->payment_intent,
                                'amount' => (int) ($purchaseAmount * 100),
                                'reason' => 'requested_by_customer',
                                'metadata' => [
                                    'gift_card_id' => $giftCard->id,
                                    'reason' => 'phaze_balance_insufficient',
                                ],
                            ]);
                        } catch (\Exception $refundException) {
                            Log::error('Failed to refund Stripe payment after Phaze balance check failed', [
                                'gift_card_id' => $giftCard->id,
                                'error' => $refundException->getMessage(),
                            ]);
                        }
                    }

                    DB::commit();

                    return redirect()->route('gift-cards.index')->withErrors([
                        'message' => 'Gift card purchases are temporarily unavailable due to provider funding limits. Your payment has been refunded.',
                    ]);
                }

                // Purchase gift card via Phaze API (after Stripe payment success)
                $phazePurchaseData = [
                    'productId' => (int) $productId,
                    'amount' => $purchaseAmount,
                    'currency' => $currency,
                    'orderId' => $orderId,
                    'externalUserId' => (string) $userId, // Required by Phaze API
                ];

                $phazePurchaseResult = $this->giftCardService->purchaseGiftCard($phazePurchaseData);

                Log::info('Phaze purchase result', ['result' => $phazePurchaseResult]);

                if ($this->giftCardService->isPurchaseSuccessful($phazePurchaseResult)) {
                    $revenueSplit = GiftCardRevenueShareService::calculateFromPhazeResponse($phazePurchaseResult, $purchaseAmount);
                    $commissionPercentage = $revenueSplit['commission_percentage'];
                    $totalCommission = $revenueSplit['total_commission'];
                    $platformCommission = $revenueSplit['platform_commission'];
                    $nonprofitCommission = $revenueSplit['nonprofit_commission'];
                    $merchantRevenue = $revenueSplit['merchant_revenue'];

                    if ($totalCommission !== null && $totalCommission > 0) {
                        Log::info('Gift card commission calculated', [
                            'gift_card_id' => $giftCard->id,
                            'purchase_amount' => $purchaseAmount,
                            'provider_commission' => $totalCommission,
                            'biu_revenue_share' => $platformCommission,
                            'organization_revenue' => $nonprofitCommission,
                            'phaze_response_keys' => array_keys($phazePurchaseResult),
                        ]);
                    } else {
                        Log::warning('Gift card commission is zero or not found', [
                            'gift_card_id' => $giftCard->id,
                            'purchase_amount' => $purchaseAmount,
                            'phaze_response' => $phazePurchaseResult,
                        ]);
                    }

                    $existingMeta = $giftCard->meta ?? [];
                    $updateData = [
                        'external_id' => $phazePurchaseResult['id'] ?? null,
                        'voucher' => $phazePurchaseResult['voucher'] ?? null,
                        'phaze_disbursement_id' => $phazePurchaseResult['id'] ?? null,
                        'commission_percentage' => $commissionPercentage,
                        'total_commission' => $totalCommission,
                        'platform_commission' => $platformCommission,
                        'nonprofit_commission' => $nonprofitCommission,
                        'merchant_revenue' => $merchantRevenue,
                        'meta' => array_merge($existingMeta, [
                            'phaze_purchase' => $phazePurchaseResult,
                            'orderId' => $orderId,
                            'phaze_purchase_id' => $phazePurchaseResult['id'] ?? null,
                            'phaze_status' => $phazePurchaseResult['status'] ?? 'pending',
                            'phaze_initial_response' => $phazePurchaseResult,
                            'commission_calculation' => $revenueSplit['commission_calculation'],
                        ]),
                    ];

                    // Update card_number if provided
                    if (isset($phazePurchaseResult['cardNumber']) && ! empty($phazePurchaseResult['cardNumber'])) {
                        $updateData['card_number'] = $phazePurchaseResult['cardNumber'];
                    }

                    $giftCard->update($updateData);

                    // If Phaze returns voucher/card details, update them
                    if (isset($phazePurchaseResult['voucher'])) {
                        $giftCard->update(['voucher' => $phazePurchaseResult['voucher']]);
                    }

                    $this->recordPhazeBalanceDeduction($giftCard, (float) $purchaseAmount, $orderId);
                } else {
                    // Phaze purchase failed - need to refund Stripe payment
                    $phazeError = 'Purchase API call failed';
                    if ($phazePurchaseResult && isset($phazePurchaseResult['error'])) {
                        $phazeError = $phazePurchaseResult['error'];
                    } elseif ($phazePurchaseResult && isset($phazePurchaseResult['httpStatusCode'])) {
                        $phazeError = $phazePurchaseResult['error'] ?? 'Purchase failed with HTTP '.$phazePurchaseResult['httpStatusCode'];
                    }

                    Log::error('Phaze purchase API call failed, but Stripe payment succeeded - Processing refund', [
                        'gift_card_id' => $giftCard->id,
                        'product_id' => $productId,
                        'stripe_payment_intent' => $session->payment_intent,
                        'purchase_amount' => $purchaseAmount,
                        'phaze_error' => $phazeError,
                    ]);

                    // Mark gift card as failed first
                    $giftCard->update([
                        'status' => 'failed',
                        'meta' => array_merge($giftCard->meta ?? [], [
                            'phaze_purchase_failed' => true,
                            'phaze_error' => $phazeError,
                            'failed_at' => now()->toIso8601String(),
                        ]),
                    ]);

                    // Process Stripe refund
                    try {
                        if ($session->payment_intent) {
                            $refund = Refund::create([
                                'payment_intent' => $session->payment_intent,
                                'amount' => (int) ($purchaseAmount * 100), // Convert to cents
                                'reason' => 'requested_by_customer',
                                'metadata' => [
                                    'gift_card_id' => $giftCard->id,
                                    'reason' => 'phaze_purchase_failed',
                                    'phaze_error' => $phazeError,
                                ],
                            ]);

                            // Update gift card with refund info
                            $giftCard->update([
                                'meta' => array_merge($giftCard->meta ?? [], [
                                    'refund_processed' => true,
                                    'stripe_refund_id' => $refund->id,
                                    'refund_status' => $refund->status,
                                    'refund_amount' => $refund->amount / 100,
                                    'refund_reason' => 'Phaze purchase failed after Stripe payment succeeded',
                                    'refunded_at' => now()->toIso8601String(),
                                ]),
                            ]);

                            // Create refund transaction record
                            if ($giftCard->user) {
                                Transaction::create([
                                    'user_id' => $giftCard->user->id,
                                    'type' => 'refund',
                                    'status' => $refund->status === 'succeeded' ? 'completed' : 'pending',
                                    'amount' => $refund->amount / 100,
                                    'currency' => $currency,
                                    'payment_method' => 'stripe',
                                    'transaction_id' => $refund->id,
                                    'related_id' => $giftCard->id,
                                    'related_type' => GiftCard::class,
                                    'meta' => [
                                        'gift_card_id' => $giftCard->id,
                                        'original_payment_intent' => $session->payment_intent,
                                        'stripe_refund_id' => $refund->id,
                                        'refund_reason' => 'Phaze purchase failed',
                                        'phaze_error' => $phazeError,
                                        'original_purchase_amount' => $purchaseAmount,
                                    ],
                                    'processed_at' => $refund->status === 'succeeded' ? now() : null,
                                ]);
                            }

                            Log::info('Stripe refund processed for failed Phaze purchase', [
                                'gift_card_id' => $giftCard->id,
                                'refund_id' => $refund->id,
                                'refund_status' => $refund->status,
                                'refund_amount' => $refund->amount / 100,
                            ]);
                        } else {
                            Log::warning('Cannot process refund - no payment intent found', [
                                'gift_card_id' => $giftCard->id,
                                'session_id' => $sessionId,
                            ]);

                            // Mark refund as attempted but failed
                            $giftCard->update([
                                'meta' => array_merge($giftCard->meta ?? [], [
                                    'refund_attempted' => true,
                                    'refund_error' => 'No payment intent found',
                                ]),
                            ]);
                        }
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        Log::error('Failed to process Stripe refund for failed Phaze purchase', [
                            'gift_card_id' => $giftCard->id,
                            'error' => $e->getMessage(),
                            'stripe_error' => $e->getStripeCode(),
                        ]);

                        // Update gift card with refund error
                        $giftCard->update([
                            'meta' => array_merge($giftCard->meta ?? [], [
                                'refund_attempted' => true,
                                'refund_error' => $e->getMessage(),
                                'stripe_error_code' => $e->getStripeCode(),
                            ]),
                        ]);
                    }
                }

                // Record purchase transaction if user exists
                // Transaction status will be updated based on Phaze purchase result
                if ($giftCard->user) {
                    $transactionStatus = $phazePurchaseResult ? 'completed' : 'failed';
                    $transactionMeta = array_merge([
                        'gift_card_id' => $giftCard->id,
                        'brand' => $brandName,
                        'phaze_order_id' => $orderId,
                    ], GiftCardRevenueShareService::ledgerMetaSlice(
                        (float) $purchaseAmount,
                        isset($totalCommission) && $totalCommission !== null ? (float) $totalCommission : null,
                        isset($platformCommission) && $platformCommission !== null ? (float) $platformCommission : null,
                        isset($nonprofitCommission) && $nonprofitCommission !== null ? (float) $nonprofitCommission : null,
                        isset($merchantRevenue) ? (float) $merchantRevenue : 0.0
                    ));

                    if ($phazePurchaseResult) {
                        $transactionMeta['phaze_purchase_id'] = $phazePurchaseResult['id'] ?? null;
                        $transactionMeta['phaze_status'] = $phazePurchaseResult['status'] ?? 'pending';
                    } else {
                        // Get phaze error from gift card meta if available
                        $phazeErrorFromMeta = $giftCard->fresh()->meta['phaze_error'] ?? 'Purchase API call failed';
                        $transactionMeta['phaze_purchase_failed'] = true;
                        $transactionMeta['phaze_error'] = $phazeErrorFromMeta;
                        $transactionMeta['failed_at'] = now()->toIso8601String();
                    }

                    $giftCard->user->recordTransaction([
                        'type' => 'purchase',
                        'amount' => $purchaseAmount,
                        'status' => $transactionStatus,
                        'payment_method' => 'stripe',
                        'related_id' => $giftCard->id,
                        'related_type' => GiftCard::class,
                        'transaction_id' => $session->payment_intent ?? $session->id,
                        'meta' => $transactionMeta,
                        'processed_at' => $phazePurchaseResult ? now() : null,
                    ]);
                }

                DB::commit();

                // Reload gift card to get latest status
                $giftCard = $giftCard->fresh()->load(['user', 'organization']);

                // Check if Phaze purchase failed - if so, redirect with error
                if ($giftCard->status === 'failed' || ($giftCard->meta['phaze_purchase_failed'] ?? false)) {
                    $refundInfo = $giftCard->meta['refund_processed'] ?? false
                        ? 'Your payment has been refunded automatically.'
                        : 'We are processing your refund. Please contact support if you have any questions.';

                    return redirect()->route('gift-cards.index')->withErrors([
                        'message' => 'Gift card purchase could not be completed. '.$refundInfo.' Error: '.($giftCard->meta['phaze_error'] ?? 'Purchase failed'),
                    ]);
                }

                // Send email with PDF receipt only if purchase was successful
                $recipientEmail = $giftCard->user ? $giftCard->user->email : $session->customer_email;
                if ($recipientEmail && $giftCard->status !== 'failed') {
                    try {
                        \Illuminate\Support\Facades\Mail::to($recipientEmail)->send(
                            new \App\Mail\GiftCardPurchaseReceipt($giftCard, $session)
                        );
                        $giftCard->update(['is_sent' => true]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send gift card receipt email: '.$e->getMessage());
                    }
                }

                return Inertia::render('GiftCards/Success', [
                    'giftCard' => $giftCard,
                    'sessionId' => $sessionId,
                    'paymentMethod' => 'stripe',
                    'user' => $giftCard->user ? [
                        'name' => $giftCard->user->name,
                        'email' => $giftCard->user->email,
                    ] : null,
                ]);
            } else {
                return redirect()->route('gift-cards.index')->withErrors([
                    'message' => 'Payment was not completed.',
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing gift card success: '.$e->getMessage());

            return redirect()->route('gift-cards.index')->withErrors([
                'message' => 'An error occurred while processing your payment.',
            ]);
        }
    }

    /**
     * Display user's gift cards
     */
    /**
     * Show user's purchased gift cards only
     * Only shows cards that the user has purchased (purchased_at is not null)
     */
    public function myCards(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Only show purchased gift cards (purchased_at is not null)
        $giftCards = GiftCard::where('user_id', $user->id)
            ->whereNotNull('purchased_at') // Only purchased cards
            ->orderBy('purchased_at', 'desc') // Order by purchase date
            ->paginate(12);

        return Inertia::render('GiftCards/MyCards', [
            'giftCards' => $giftCards,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Show gift card brand details from Phaze API for purchase
     * Accepts productId and country to fetch brand details
     */
    public function show(Request $request, $giftCard = null)
    {
        $user = Auth::user();
        $productId = $request->input('productId');
        $country = $request->input('country', 'USA'); // Default to USA

        // If productId is provided, fetch brand details from Phaze API
        if ($productId) {
            // Fetch brands from Phaze API (cached)
            $brands = $this->giftCardService->getGiftBrands($country, 1);

            // Find the specific brand by productId
            $brand = null;
            foreach ($brands as $b) {
                if (isset($b['productId']) && (string) $b['productId'] === (string) $productId) {
                    $brand = $b;
                    break;
                }
            }

            if (! $brand) {
                return redirect()->route('gift-cards.index')->withErrors([
                    'message' => 'Brand not found.',
                ]);
            }

            // Ensure brand has productName
            if (! isset($brand['productName']) || empty($brand['productName'])) {
                $brand['productName'] = 'Gift Card #'.($brand['productId'] ?? 'Unknown');
            }

            $brand = $this->withGiftedEligibility($brand);

            // All approved nonprofits eligible for beneficiary selection (same idea as /donate).
            $organizations = Organization::query()
                ->where('registration_status', 'approved')
                ->excludingCareAllianceHubs()
                ->orderBy('name')
                ->get(['id', 'name', 'gift_card_terms_approved'])
                ->map(fn (Organization $o) => [
                    'id' => (int) $o->id,
                    'name' => $o->name,
                    'gift_card_terms_approved' => (bool) $o->gift_card_terms_approved,
                ])
                ->values()
                ->all();

            // Orgs this user has bought gift cards for — latest purchase first, with lifetime total.
            $giftCardPurchaseOrganizations = [];
            if ($user && $user->role === 'user') {
                $aggregates = GiftCard::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('purchased_at')
                    ->whereNotNull('organization_id')
                    ->selectRaw('organization_id')
                    ->selectRaw('SUM(amount) as purchased_total')
                    ->selectRaw('MAX(purchased_at) as last_purchased_at')
                    ->groupBy('organization_id')
                    ->orderByDesc('last_purchased_at')
                    ->get();

                $orgById = Organization::query()
                    ->whereIn('id', $aggregates->pluck('organization_id'))
                    ->get(['id', 'name', 'gift_card_terms_approved'])
                    ->keyBy('id');

                foreach ($aggregates as $row) {
                    $o = $orgById->get($row->organization_id);
                    if ($o === null) {
                        continue;
                    }
                    $giftCardPurchaseOrganizations[] = [
                        'id' => (int) $o->id,
                        'name' => $o->name,
                        'gift_card_terms_approved' => (bool) $o->gift_card_terms_approved,
                        'purchased_total' => (float) $row->purchased_total,
                    ];
                }
            }

            return Inertia::render('GiftCards/PurchaseDetails', [
                'brand' => $brand,
                'country' => $country,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ] : null,
                'organizations' => $organizations,
                'giftCardPurchaseOrganizations' => $giftCardPurchaseOrganizations,
                'platformFeeUsd' => BiuPlatformFeeService::getGiftCardPlatformFeeUsd(),
            ]);
        }

        // If giftCard is provided (route parameter), show purchased gift card
        // Handle both route model binding (GiftCard instance) and string ID
        $giftCardModel = null;
        if ($giftCard instanceof GiftCard) {
            $giftCardModel = $giftCard;
        } elseif (is_numeric($giftCard)) {
            // If it's a numeric ID, try to find the gift card
            $giftCardModel = GiftCard::find($giftCard);
        } elseif (is_string($giftCard)) {
            // If it's a string, try to find by ID
            $giftCardModel = GiftCard::find((int) $giftCard);
        }

        if ($giftCardModel) {
            // Only allow viewing if user owns this gift card OR if organization owns it
            if (! $user) {
                return redirect()->route('login');
            }

            // Check if user purchased this card
            $userOwnsCard = $giftCardModel->user_id === $user->id && $giftCardModel->purchased_at;

            // Check if organization owns this card (for organization role)
            $organizationOwnsCard = false;
            if (in_array($user->role, ['organization', 'organization_pending', 'admin']) && $giftCardModel->organization_id && $giftCardModel->purchased_at) {
                if ($user->role === 'admin') {
                    // Admin can view any organization's gift cards
                    $organizationOwnsCard = true;
                } else {
                    // Organization can only view cards purchased for their organization
                    $organization = $user->organization;
                    if ($organization && $giftCardModel->organization_id === $organization->id) {
                        $organizationOwnsCard = true;
                    }
                }
            }

            if ($userOwnsCard || $organizationOwnsCard) {
                // Get Phaze purchase details
                $phazePurchaseData = null;
                $phazeDisbursementData = null;

                // Try to get purchase details from stored purchase ID in meta
                $phazePurchaseId = $giftCardModel->meta['phaze_purchase']['id'] ??
                                   $giftCardModel->meta['phaze_purchase_id'] ??
                                   $giftCardModel->external_id ??
                                   null;

                // First, try to get stored data from meta (most reliable)
                // This ensures we always show purchase information even if API is temporarily unavailable
                if (isset($giftCardModel->meta['phaze_purchase']) && is_array($giftCardModel->meta['phaze_purchase'])) {
                    $phazePurchaseData = $giftCardModel->meta['phaze_purchase'];
                }

                // Also check for phaze_initial_response as fallback
                if (! $phazePurchaseData && isset($giftCardModel->meta['phaze_initial_response']) && is_array($giftCardModel->meta['phaze_initial_response'])) {
                    $phazePurchaseData = $giftCardModel->meta['phaze_initial_response'];
                }

                // Try to refresh from API if we have a purchase ID (optional - for latest status)
                // But don't overwrite if API call fails - gracefully handle errors
                if ($phazePurchaseId && $phazePurchaseData) {
                    try {
                        $apiPurchaseData = $this->giftCardService->getPurchaseDetails($phazePurchaseId);
                        // Only use API data if it's valid, not an error, and has more information than stored data
                        if ($apiPurchaseData &&
                            is_array($apiPurchaseData) &&
                            ! empty($apiPurchaseData) &&
                            ! isset($apiPurchaseData['error']) &&
                            ! isset($apiPurchaseData['httpStatusCode']) &&
                            ! isset($apiPurchaseData['message'])) {
                            // Merge API data with stored data (API data takes precedence for status updates)
                            $phazePurchaseData = array_merge($phazePurchaseData, $apiPurchaseData);
                        } elseif (isset($apiPurchaseData['error']) || isset($apiPurchaseData['message'])) {
                            // Log API error but don't break - use stored data
                            Log::warning('Phaze API error when refreshing purchase details', [
                                'purchase_id' => $phazePurchaseId,
                                'error' => $apiPurchaseData['error'] ?? $apiPurchaseData['message'] ?? 'Unknown error',
                                'http_status' => $apiPurchaseData['httpStatusCode'] ?? null,
                            ]);
                            // Continue with stored data - don't overwrite
                        }
                    } catch (\Exception $e) {
                        // Log exception but don't break - use stored data
                        Log::warning('Exception when refreshing Phaze purchase details from API', [
                            'purchase_id' => $phazePurchaseId,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue with stored data - don't overwrite
                    }
                }

                // If we still don't have purchase data but have external_id, try to construct basic info
                // Also merge any additional data from meta
                if (! $phazePurchaseData && $giftCardModel->external_id) {
                    $phazePurchaseData = [
                        'id' => $giftCardModel->external_id,
                        'status' => $giftCardModel->meta['phaze_status'] ?? 'pending',
                    ];

                    // Add orderId from meta if available
                    if (isset($giftCardModel->meta['orderId'])) {
                        $phazePurchaseData['orderId'] = $giftCardModel->meta['orderId'];
                        $phazePurchaseData['orderID'] = $giftCardModel->meta['orderId']; // Also add orderID for compatibility
                    }

                    // Add productId from meta if available
                    if (isset($giftCardModel->meta['productId'])) {
                        $phazePurchaseData['productId'] = $giftCardModel->meta['productId'];
                    }

                    // Add voucher and card_number from gift card if available
                    if ($giftCardModel->voucher) {
                        $phazePurchaseData['voucher'] = $giftCardModel->voucher;
                    }
                    if ($giftCardModel->card_number) {
                        $phazePurchaseData['cardNumber'] = $giftCardModel->card_number;
                        $phazePurchaseData['card_number'] = $giftCardModel->card_number;
                    }

                    // Add amount and currency
                    $phazePurchaseData['denomination'] = $giftCardModel->amount;
                    $phazePurchaseData['baseCurrency'] = $giftCardModel->currency;
                    $phazePurchaseData['currency'] = $giftCardModel->currency;

                    // Add timestamps if available in meta
                    if (isset($giftCardModel->meta['phaze_purchase']['createdAt'])) {
                        $phazePurchaseData['createdAt'] = $giftCardModel->meta['phaze_purchase']['createdAt'];
                    } elseif ($giftCardModel->purchased_at) {
                        $phazePurchaseData['createdAt'] = $giftCardModel->purchased_at->toIso8601String();
                    }

                    if (isset($giftCardModel->meta['phaze_purchase']['updatedAt'])) {
                        $phazePurchaseData['updatedAt'] = $giftCardModel->meta['phaze_purchase']['updatedAt'];
                    }

                    // Add externalUserId if available
                    if (isset($giftCardModel->meta['phaze_purchase']['externalUserId'])) {
                        $phazePurchaseData['externalUserId'] = $giftCardModel->meta['phaze_purchase']['externalUserId'];
                    } elseif ($giftCardModel->user_id) {
                        $phazePurchaseData['externalUserId'] = (string) $giftCardModel->user_id;
                    }
                }

                // Ensure orderID/orderId compatibility for frontend
                if ($phazePurchaseData && is_array($phazePurchaseData)) {
                    // Ensure both orderId and orderID are set for compatibility
                    if (isset($phazePurchaseData['orderId']) && ! isset($phazePurchaseData['orderID'])) {
                        $phazePurchaseData['orderID'] = $phazePurchaseData['orderId'];
                    }
                    if (isset($phazePurchaseData['orderID']) && ! isset($phazePurchaseData['orderId'])) {
                        $phazePurchaseData['orderId'] = $phazePurchaseData['orderID'];
                    }

                    // Also ensure cardNumber/card_number compatibility
                    if (isset($phazePurchaseData['cardNumber']) && ! isset($phazePurchaseData['card_number'])) {
                        $phazePurchaseData['card_number'] = $phazePurchaseData['cardNumber'];
                    }
                    if (isset($phazePurchaseData['card_number']) && ! isset($phazePurchaseData['cardNumber'])) {
                        $phazePurchaseData['cardNumber'] = $phazePurchaseData['card_number'];
                    }

                    // Ensure baseCurrency/currency compatibility
                    if (isset($phazePurchaseData['baseCurrency']) && ! isset($phazePurchaseData['currency'])) {
                        $phazePurchaseData['currency'] = $phazePurchaseData['baseCurrency'];
                    }
                    if (isset($phazePurchaseData['currency']) && ! isset($phazePurchaseData['baseCurrency'])) {
                        $phazePurchaseData['baseCurrency'] = $phazePurchaseData['currency'];
                    }
                }

                // Get disbursement status if available
                if ($giftCardModel->phaze_disbursement_id) {
                    $phazeDisbursementData = $this->giftCardService->getDisbursementStatus($giftCardModel->phaze_disbursement_id);
                }

                $giftCardModel->load(['user', 'organization']);

                // Prepare gift card data
                $giftCardData = [
                    'id' => $giftCardModel->id,
                    'voucher' => $giftCardModel->voucher,
                    'card_number' => $giftCardModel->card_number,
                    'amount' => $giftCardModel->amount,
                    'commission_percentage' => $giftCardModel->commission_percentage,
                    'total_commission' => $giftCardModel->total_commission,
                    'platform_fee' => $giftCardModel->platform_fee !== null ? (float) $giftCardModel->platform_fee : null,
                    'platform_fee_biu_share' => $giftCardModel->platform_fee_biu_share !== null ? (float) $giftCardModel->platform_fee_biu_share : null,
                    'platform_fee_org_share' => $giftCardModel->platform_fee_org_share !== null ? (float) $giftCardModel->platform_fee_org_share : null,
                    'platform_commission' => $giftCardModel->platform_commission,
                    'nonprofit_commission' => $giftCardModel->nonprofit_commission,
                    'brand' => $giftCardModel->brand,
                    'brand_name' => $giftCardModel->brand_name,
                    'country' => $giftCardModel->country,
                    'currency' => $giftCardModel->currency,
                    'status' => $giftCardModel->status,
                    'payment_method' => $giftCardModel->payment_method,
                    'purchased_at' => $giftCardModel->purchased_at?->toISOString(),
                    'requested_at' => $giftCardModel->requested_at?->toISOString(),
                    'scheduled_fulfillment_at' => $giftCardModel->scheduled_fulfillment_at?->toISOString(),
                    'fulfilled_at' => $giftCardModel->fulfilled_at?->toISOString(),
                    'failure_reason' => $giftCardModel->failure_reason,
                    'expires_at' => $giftCardModel->expires_at?->toISOString(),
                    'created_at' => $giftCardModel->created_at->toISOString(),
                    'meta' => $giftCardModel->meta,
                    'fulfillment_audit' => is_array($giftCardModel->meta['fulfillment_audit'] ?? null)
                        ? $giftCardModel->meta['fulfillment_audit']
                        : [],
                    'organization' => $giftCardModel->organization ? [
                        'id' => $giftCardModel->organization->id,
                        'name' => $giftCardModel->organization->name,
                    ] : null,
                ];

                // Render different views based on user role
                if ($organizationOwnsCard && ! $userOwnsCard) {
                    // Organization viewing (not the purchaser) - use organization layout
                    return Inertia::render('GiftCards/OrganizationShow', [
                        'giftCard' => $giftCardData,
                        'phazePurchaseData' => $phazePurchaseData,
                        'phazeDisbursementData' => $phazeDisbursementData,
                        'user' => $giftCardModel->user ? [
                            'name' => $giftCardModel->user->name,
                            'email' => $giftCardModel->user->email,
                        ] : null,
                        'organization' => $giftCardModel->organization ? [
                            'id' => $giftCardModel->organization->id,
                            'name' => $giftCardModel->organization->name,
                        ] : null,
                    ]);
                } else {
                    // User viewing (purchaser) - use user profile layout
                    return Inertia::render('GiftCards/Show', [
                        'giftCard' => $giftCardData,
                        'phazePurchaseData' => $phazePurchaseData,
                        'phazeDisbursementData' => $phazeDisbursementData,
                        'user' => $giftCardModel->user ? [
                            'name' => $giftCardModel->user->name,
                            'email' => $giftCardModel->user->email,
                        ] : null,
                        'sessionId' => $giftCardModel->stripe_session_id ?? $giftCardModel->stripe_payment_intent_id ?? null,
                    ]);
                }
            } else {
                // User doesn't own this card or card not purchased
                // Redirect based on user role
                if (in_array($user->role, ['organization', 'organization_pending', 'admin'])) {
                    return redirect()->route('gift-cards.created')->withErrors([
                        'message' => 'You can only view gift cards purchased for your organization.',
                    ]);
                } else {
                    return redirect()->route('gift-cards.my-cards')->withErrors([
                        'message' => 'You can only view gift cards that you have purchased.',
                    ]);
                }
            }
        }

        return redirect()->route('gift-cards.index')->withErrors([
            'message' => 'Gift card not found.',
        ]);
    }

    /**
     * Get gift card brands (API endpoint)
     * Note: This endpoint is deprecated - we now use organization-created gift cards
     */
    public function getBrands(Request $request)
    {
        // Return empty array since we no longer fetch brands from external API
        return response()->json([
            'success' => true,
            'brands' => [],
        ]);
    }

    /**
     * List all gift cards purchased for organization (for admin/organization)
     * Organizations can only view purchased cards for their organization
     */
    public function createdCards(Request $request)
    {
        $user = Auth::user();

        // Only organizations and admins can view purchased cards
        if (! $user || ! in_array($user->role, ['organization', 'organization_pending', 'admin'])) {
            abort(403, 'Only organizations can view purchased gift cards.');
        }

        $query = GiftCard::with(['user', 'organization'])
            ->whereNotNull('organization_id')
            ->whereNotNull('purchased_at') // Only show purchased cards
            ->orderBy('purchased_at', 'desc');

        // If user is organization (not admin), filter by their organization
        if ($user->role !== 'admin') {
            $organization = $user->organization;
            if ($organization) {
                $query->where('organization_id', $organization->id);
            } else {
                $query->whereRaw('1 = 0'); // No results if no organization
            }
        }
        // Admin sees all purchased cards (no filter)

        $earningsBase = (clone $query)->where('status', '!=', 'failed');
        $earningsSummary = [
            'platform_fee' => (float) (clone $earningsBase)->sum('platform_fee'),
            'platform_fee_biu_share' => (float) (clone $earningsBase)->sum('platform_fee_biu_share'),
            'platform_fee_org_share' => (float) (clone $earningsBase)->sum('platform_fee_org_share'),
            'platform_commission' => (float) (clone $earningsBase)->sum('platform_commission'),
            'nonprofit_commission' => (float) (clone $earningsBase)->sum('nonprofit_commission'),
        ];
        $earningsSummary['biu_total'] = round(
            $earningsSummary['platform_fee_biu_share'] + $earningsSummary['platform_commission'],
            2
        );
        $earningsSummary['organization_total'] = round(
            $earningsSummary['platform_fee_org_share'] + $earningsSummary['nonprofit_commission'],
            2
        );

        $giftCards = $query->paginate(15);

        return Inertia::render('GiftCards/CreatedCards', [
            'giftCards' => $giftCards,
            'earningsSummary' => $earningsSummary,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'gift_card_terms_approved' => $user->organization->gift_card_terms_approved,
                'gift_card_terms_approved_at' => $user->organization->gift_card_terms_approved_at?->toIso8601String(),
            ] : null,
            'isAdmin' => $user->role === 'admin',
        ]);
    }

    /**
     * Delete a gift card (only by organization owner or admin)
     * Can only delete if card is not purchased yet
     */
    public function destroy(Request $request, GiftCard $giftCard)
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthorized');
        }

        // Check if user has permission
        $isAdmin = $user->role === 'admin';
        $isOwner = $giftCard->organization_id && $user->organization && $giftCard->organization_id === $user->organization->id;

        if (! $isAdmin && ! $isOwner) {
            abort(403, 'You do not have permission to delete this gift card.');
        }

        // Prevent deletion of purchased cards
        if ($giftCard->purchased_at) {
            return redirect()->back()->withErrors(['error' => 'Cannot delete a gift card that has been purchased.']);
        }

        $giftCard->delete();

        return redirect()->route('gift-cards.created')
            ->with('success', 'Gift card deleted successfully.');
    }

    /**
     * Toggle gift card status (active/inactive)
     * Only organization owner or admin can toggle
     * Can only toggle if card is not purchased yet
     */
    public function toggleStatus(Request $request, GiftCard $giftCard)
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthorized');
        }

        // Check if user has permission
        $isAdmin = $user->role === 'admin';
        $isOwner = $giftCard->organization_id && $user->organization && $giftCard->organization_id === $user->organization->id;

        if (! $isAdmin && ! $isOwner) {
            abort(403, 'You do not have permission to modify this gift card.');
        }

        // Prevent status change of purchased cards
        if ($giftCard->purchased_at) {
            return redirect()->back()->withErrors(['error' => 'Cannot change status of a gift card that has been purchased.']);
        }

        // Toggle status
        $newStatus = $giftCard->status === 'active' ? 'inactive' : 'active';
        $giftCard->status = $newStatus;
        $giftCard->save();

        $statusLabel = $newStatus === 'active' ? 'activated' : 'deactivated';

        return redirect()->back()
            ->with('success', "Gift card {$statusLabel} successfully.");
    }

    /**
     * Download gift card receipt as PDF
     */
    public function downloadPdf(Request $request, GiftCard $giftCard)
    {
        $user = Auth::user();

        // Check if user has permission to download this gift card receipt
        if (! $user) {
            abort(401, 'Unauthorized');
        }

        // User can only download their own gift cards
        // Organization can download cards purchased for their organization
        $canDownload = false;

        if ($giftCard->user_id === $user->id) {
            // User owns this card
            $canDownload = true;
        } elseif (in_array($user->role, ['organization', 'organization_pending', 'admin'])) {
            // Organization or admin can download if it's for their organization
            if ($user->role === 'admin') {
                $canDownload = true;
            } elseif ($giftCard->organization_id && $user->organization && $giftCard->organization_id === $user->organization->id) {
                $canDownload = true;
            }
        }

        if (! $canDownload) {
            abort(403, 'You do not have permission to download this gift card receipt.');
        }

        // Only allow download for purchased cards
        if (! $giftCard->purchased_at) {
            abort(404, 'Gift card receipt is not available for unpurchased cards.');
        }

        try {
            // Check if dompdf is available
            if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                return redirect()->back()->withErrors([
                    'error' => 'PDF generation is not available. Please contact support.',
                ]);
            }

            // Load relationships
            $giftCard->load(['user', 'organization']);

            // Get Stripe session for additional details
            $session = null;
            if ($giftCard->stripe_session_id) {
                try {
                    $session = Cashier::stripe()->checkout->sessions->retrieve($giftCard->stripe_session_id);
                } catch (\Exception $e) {
                    Log::warning('Could not retrieve Stripe session for PDF', [
                        'gift_card_id' => $giftCard->id,
                        'session_id' => $giftCard->stripe_session_id,
                    ]);
                }
            }

            // Generate PDF using the blade template
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('emails.gift-card-receipt-pdf', [
                'giftCard' => $giftCard,
                'session' => $session,
            ]);

            // Set PDF options
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption('enable-local-file-access', true);

            // Generate filename
            $date = $giftCard->purchased_at->format('Y-m-d');
            $filename = 'Gift-Card-Receipt-'.$giftCard->id.'-'.$date.'.pdf';

            // Download PDF
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Failed to generate gift card PDF receipt: '.$e->getMessage(), [
                'gift_card_id' => $giftCard->id,
                'user_id' => $user->id,
                'error' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->withErrors([
                'error' => 'Failed to generate PDF receipt. Please try again or contact support.',
            ]);
        }
    }

    /**
     * Lookup Phaze transaction by order ID (for testing/debugging)
     * GET /gift-cards/transaction/lookup/{orderId}
     */
    public function lookupTransaction(Request $request, string $orderId)
    {
        $user = Auth::user();

        // Only allow authenticated users (admin or organization for testing)
        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            return redirect()->route('login');
        }

        try {
            $giftCardService = new \App\Services\GiftCardService;
            $transactionData = $giftCardService->lookupTransactionByOrderId($orderId);

            if (! $transactionData) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Transaction not found or lookup failed',
                    ], 404);
                }

                return redirect()->back()->withErrors([
                    'error' => 'Transaction not found or lookup failed',
                ]);
            }

            // If JSON request (API/Postman)
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'data' => $transactionData,
                    'order_id' => $orderId,
                ]);
            }

            // If web request, return Inertia view
            return Inertia::render('GiftCards/TransactionLookup', [
                'transaction' => $transactionData,
                'orderId' => $orderId,
            ]);

        } catch (\Exception $e) {
            Log::error('Error looking up transaction: '.$e->getMessage(), [
                'order_id' => $orderId,
                'user_id' => $user->id ?? null,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error looking up transaction: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()->withErrors([
                'error' => 'Error looking up transaction: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse|null
     */
    private function rejectIfInsufficientPhazeBalance(float $purchaseAmount, bool $isInertiaRequest)
    {
        try {
            $this->phazeBalanceService->assertCanAfford($purchaseAmount);
        } catch (InsufficientPhazeBalanceException $e) {
            Log::warning('Gift card purchase blocked due to insufficient Phaze balance', [
                'required' => $e->required,
                'available' => $e->available,
            ]);

            $message = $e->userMessage();

            if ($isInertiaRequest) {
                return back()->withErrors([
                    'payment_method' => $message,
                    'error' => $message,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 503);
        }

        return null;
    }

    private function recordPhazeBalanceDeduction(GiftCard $giftCard, float $purchaseAmount, ?string $orderId): void
    {
        try {
            $this->phazeBalanceService->deductForPurchase($purchaseAmount, $giftCard, $orderId);
        } catch (InsufficientPhazeBalanceException $e) {
            Log::critical('Phaze purchase succeeded but balance ledger recording failed', [
                'gift_card_id' => $giftCard->id,
                'required' => $e->required,
                'available' => $e->available,
                'order_id' => $orderId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    private function withGiftedEligibility(array $brand): array
    {
        $name = $brand['productName'] ?? '';
        $brand['allowedForGiftedPoints'] = GiftCardGiftedPointsPolicy::isAllowedForGiftedRedemption($name);

        return $brand;
    }
}
