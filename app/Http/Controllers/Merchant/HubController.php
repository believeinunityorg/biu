<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MerchantHubCategory;
use App\Models\MerchantHubOffer;
use App\Models\MerchantHubOfferRedemption;
use App\Models\Transaction;
use App\Services\BiuPlatformFeeService;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;

class HubController extends Controller
{
    /**
     * Display a listing of offers for the public hub.
     */
    public function index(Request $request): Response
    {
        $query = MerchantHubOffer::with(['merchant', 'category'])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });

        // Category filter
        if ($request->has('category') && $request->category) {
            $category = MerchantHubCategory::where('slug', $request->category)->first();
            if ($category) {
                $query->where('merchant_hub_category_id', $category->id);
            }
        }

        // Merchant filter
        if ($request->has('merchant') && $request->merchant) {
            $query->whereHas('merchant', function ($q) use ($request) {
                $q->where('slug', $request->merchant);
            });
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('short_description', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhereHas('merchant', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Points filter
        if ($request->has('min_points') && $request->min_points) {
            $query->where('points_required', '>=', $request->min_points);
        }
        if ($request->has('max_points') && $request->max_points) {
            $query->where('points_required', '<=', $request->max_points);
        }

        // Cash required filter
        if ($request->has('has_cash') && $request->has_cash) {
            $query->whereNotNull('cash_required');
        }

        // Sorting
        $sort = $request->get('sort', 'newest');
        switch ($sort) {
            case 'points_asc':
                $query->orderBy('points_required', 'asc');
                break;
            case 'points_desc':
                $query->orderBy('points_required', 'desc');
                break;
            case 'cash_asc':
                $query->orderBy('cash_required', 'asc')->whereNotNull('cash_required');
                break;
            case 'cash_desc':
                $query->orderBy('cash_required', 'desc')->whereNotNull('cash_required');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $perPage = $request->get('per_page', 12);
        $offers = $query->paginate($perPage)->withQueryString();

        // Transform offers for frontend
        $transformedOffers = $offers->through(function ($offer) {
            $imageUrl = $offer->image_url;
            if ($imageUrl && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = asset('storage/'.ltrim($imageUrl, '/'));
            }

            return [
                'id' => (string) $offer->id,
                'slug' => $offer->slug,
                'title' => $offer->title,
                'image' => $imageUrl ?: '/placeholder.jpg',
                'pointsRequired' => $offer->points_required,
                'cashRequired' => $offer->cash_required ? (float) $offer->cash_required : null,
                'currency' => $offer->currency,
                'merchantName' => $offer->merchant->name,
                'merchantSlug' => $offer->merchant->slug,
                'category' => $offer->category->name,
                'categorySlug' => $offer->category->slug,
                'description' => $offer->short_description ?: $offer->description,
            ];
        });

        // Get categories with counts
        $categories = MerchantHubCategory::where('is_active', true)
            ->withCount(['offers' => function ($query) {
                $query->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('starts_at')
                            ->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($q) {
                        $q->whereNull('ends_at')
                            ->orWhere('ends_at', '>=', now());
                    });
            }])
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'offers_count' => $category->offers_count,
                ];
            });

        return Inertia::render('merchant/Hub/Index', [
            'offers' => $transformedOffers,
            'categories' => $categories,
            'filters' => [
                'category' => $request->get('category'),
                'merchant' => $request->get('merchant'),
                'search' => $request->get('search'),
                'min_points' => $request->get('min_points'),
                'max_points' => $request->get('max_points'),
                'has_cash' => $request->get('has_cash'),
                'sort' => $sort,
                'per_page' => (int) $perPage,
            ],
        ]);
    }

    /**
     * Create Stripe checkout session for offer redemption
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:merchant_hub_offers,id',
            'payment_method' => 'nullable|in:points,cash',
        ]);
        $paymentMethod = $request->input('payment_method', 'points');

        // Check both web and merchant guards - get authenticated user from either guard
        $user = Auth::user();
        $merchant = Auth::guard('merchant')->user();

        // Determine which user we're working with
        $userModel = null;
        $isMerchant = false;

        if ($user && $user instanceof \App\Models\User) {
            // Regular user logged in via web guard
            $userModel = $user;
        } elseif ($merchant) {
            // Merchant logged in - find or create linked User account
            $isMerchant = true;
            $linkedUser = \App\Models\User::where('email', $merchant->email)->first();

            if (! $linkedUser) {
                // Automatically create a User account for the merchant
                $linkedUser = \App\Models\User::create([
                    'name' => $merchant->name,
                    'email' => $merchant->email,
                    'password' => bcrypt(Str::random(32)), // Random password since merchant uses different auth
                    'role' => 'user',
                    'believe_points' => 0, // Start with 0 points
                    'email_verified_at' => $merchant->email_verified_at ?? now(),
                ]);
            }

            $userModel = $linkedUser;
        }

        if (! $userModel) {
            return response()->json([
                'success' => false,
                'error' => 'You must be logged in to claim merchant offers.',
            ], 401);
        }

        // Use the User model for all operations
        $user = $userModel;

        DB::beginTransaction();

        try {
            $offer = MerchantHubOffer::with(['merchant', 'category'])->findOrFail($request->offer_id);

            // Validate offer is available
            if (! $offer->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'This offer is no longer available.',
                ], 400);
            }

            $userPoints = $user->purchasedBelievePointsBalance();
            $receiptCode = 'RED-'.strtoupper(Str::random(8));

            // BIU: Pay with Cash (full amount) — Stripe
            if ($paymentMethod === 'cash') {
                $referencePrice = (float) ($offer->reference_price ?? 0);
                if ($referencePrice <= 0 && $offer->points_required > 0 && $offer->discount_percentage > 0) {
                    $referencePrice = ($offer->points_required / 1000) * 100 / (float) $offer->discount_percentage;
                }
                if ($referencePrice <= 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'This offer does not support cash purchase.',
                    ], 400);
                }
                $cashAmount = round($referencePrice, 2); // Full amount when paying with cash (no points)

                $amountCents = (int) round($cashAmount * 100);
                $currency = strtolower($offer->currency ?? 'usd');
                $checkout = $user->checkout([
                    [
                        'price_data' => [
                            'currency' => $currency,
                            'product_data' => [
                                'name' => $offer->title,
                                'description' => 'Pay with cash (full amount) - '.$offer->merchant->name,
                            ],
                            'unit_amount' => $amountCents,
                        ],
                        'quantity' => 1,
                    ],
                ], [
                    'payment_method_types' => ['card'],
                    'success_url' => route('hub.offer.success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('hub.offer.show', $offer->slug),
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'offer_id' => (string) $offer->id,
                        'type' => 'merchant_hub_redemption',
                        'payment_method' => 'cash',
                        'cash_amount' => (string) $cashAmount,
                        'receipt_code' => $receiptCode,
                        'currency' => $currency,
                        'is_merchant' => $isMerchant ? 'true' : 'false',
                    ],
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'url' => $checkout->url,
                ]);
            }

            // BIU: Pay with Unity Points — deduct points, no Stripe
            $pointsToDeduct = 0;
            if ($userPoints >= $offer->points_required && ! $isMerchant) {
                $pointsToDeduct = $offer->points_required;
            } elseif (! $isMerchant && $userPoints < $offer->points_required) {
                return response()->json([
                    'success' => false,
                    'error' => 'You need '.number_format($offer->points_required).' points but you have '.number_format($userPoints).'.',
                ], 400);
            }

            $cashRequired = $offer->cash_required ?? 0;

            $redemption = MerchantHubOfferRedemption::create([
                'merchant_hub_offer_id' => $offer->id,
                'user_id' => $user->id,
                'points_spent' => $pointsToDeduct,
                'cash_spent' => $cashRequired,
                'status' => 'pending',
                'receipt_code' => $receiptCode,
            ]);

            if ($pointsToDeduct > 0) {
                if (! $user->deductBelievePoints($pointsToDeduct)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'error' => 'Failed to deduct points. Please try again.',
                    ], 400);
                }
            }

            // If offer also has cash_required, create Stripe checkout via Cashier; otherwise approve
            if ($cashRequired > 0) {
                $amountCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd((float) $cashRequired, 'card');
                $currency = strtolower($offer->currency ?? 'usd');
                $checkout = $user->checkout([
                    [
                        'price_data' => [
                            'currency' => $currency,
                            'product_data' => [
                                'name' => $offer->title,
                                'description' => 'Merchant Hub Offer - '.$offer->merchant->name,
                            ],
                            'unit_amount' => $amountCents,
                        ],
                        'quantity' => 1,
                    ],
                ], [
                    'payment_method_types' => ['card'],
                    'success_url' => route('hub.offer.success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('hub.offer.show', $offer->slug),
                    'metadata' => [
                        'redemption_id' => (string) $redemption->id,
                        'user_id' => (string) $user->id,
                        'offer_id' => (string) $offer->id,
                        'type' => 'merchant_hub_redemption',
                        'is_merchant' => $isMerchant ? 'true' : 'false',
                    ],
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'url' => $checkout->url,
                ]);
            }

            $redemption->update(['status' => 'approved']);

            Transaction::create([
                'user_id' => $user->id,
                'related_id' => $redemption->id,
                'related_type' => MerchantHubOfferRedemption::class,
                'type' => 'purchase',
                'status' => 'completed',
                'amount' => 0,
                'fee' => 0,
                'currency' => $offer->currency ?? 'USD',
                'payment_method' => 'points',
                'meta' => [
                    'points_spent' => $pointsToDeduct,
                    'offer_id' => $offer->id,
                    'receipt_code' => $receiptCode,
                    'is_merchant_redemption' => $isMerchant,
                ],
                'processed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'url' => route('hub.offer.success', ['code' => $receiptCode]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Offer redemption checkout error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'An error occurred during checkout. Please try again.',
            ], 500);
        }
    }

    /**
     * Handle successful payment from Stripe
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');
        $code = $request->get('code');

        if (! $sessionId && ! $code) {
            return redirect()->route('hub.index')
                ->with('error', 'Invalid session.');
        }

        DB::beginTransaction();

        try {
            if ($sessionId) {
                // Handle Stripe payment success (Laravel Cashier)
                $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

                if ($session->payment_status !== 'paid') {
                    return redirect()->route('hub.index')
                        ->with('error', 'Payment was not completed.');
                }

                $metadata = $session->metadata ?? (object) [];
                $redemptionId = $metadata->redemption_id ?? null;

                if ($redemptionId) {
                    // Points + cash flow: redemption was created before checkout, just approve it
                    $redemption = MerchantHubOfferRedemption::with(['offer', 'user'])->findOrFail($redemptionId);
                    $redemption->update(['status' => 'approved']);
                    Transaction::create([
                        'user_id' => $redemption->user_id,
                        'related_id' => $redemption->id,
                        'related_type' => MerchantHubOfferRedemption::class,
                        'type' => 'purchase',
                        'status' => 'completed',
                        'amount' => $redemption->cash_spent,
                        'fee' => 0,
                        'currency' => $redemption->offer->currency ?? 'USD',
                        'payment_method' => 'stripe',
                        'transaction_id' => $session->payment_intent,
                        'meta' => array_merge([
                            'stripe_session_id' => $sessionId,
                            'points_spent' => $redemption->points_spent,
                            'offer_id' => $redemption->offer->id,
                            'receipt_code' => $redemption->receipt_code,
                        ], BiuPlatformFeeService::merchantHubCatalogLedgerMetaSlice((float) ($redemption->subtotal_amount ?? $redemption->cash_spent))),
                        'processed_at' => now(),
                    ]);
                } else {
                    // Pay with cash only: create redemption after successful payment
                    $offerId = $metadata->offer_id ?? null;
                    $userId = $metadata->user_id ?? null;
                    $cashAmount = isset($metadata->cash_amount) ? (float) $metadata->cash_amount : 0;
                    $receiptCode = $metadata->receipt_code ?? 'RED-'.strtoupper(Str::random(8));
                    $currency = $metadata->currency ?? 'usd';
                    if (! $offerId || ! $userId || $cashAmount <= 0) {
                        return redirect()->route('hub.index')->with('error', 'Invalid payment session.');
                    }
                    $offer = MerchantHubOffer::with('merchant')->findOrFail($offerId);
                    $redemption = MerchantHubOfferRedemption::create([
                        'merchant_hub_offer_id' => $offer->id,
                        'user_id' => $userId,
                        'points_spent' => 0,
                        'cash_spent' => $cashAmount,
                        'status' => 'approved',
                        'receipt_code' => $receiptCode,
                    ]);
                    Transaction::create([
                        'user_id' => $redemption->user_id,
                        'related_id' => $redemption->id,
                        'related_type' => MerchantHubOfferRedemption::class,
                        'type' => 'purchase',
                        'status' => 'completed',
                        'amount' => $redemption->cash_spent,
                        'fee' => 0,
                        'currency' => strtoupper($offer->currency ?? $currency),
                        'payment_method' => 'stripe',
                        'transaction_id' => $session->payment_intent,
                        'meta' => array_merge([
                            'stripe_session_id' => $sessionId,
                            'points_spent' => 0,
                            'offer_id' => $redemption->offer->id,
                            'receipt_code' => $redemption->receipt_code,
                        ], BiuPlatformFeeService::merchantHubCatalogLedgerMetaSlice((float) ($redemption->subtotal_amount ?? $redemption->cash_spent))),
                        'processed_at' => now(),
                    ]);
                }

                DB::commit();

                $qrCodeUrl = route('merchant-hub.redemption.qr-code', ['code' => $redemption->receipt_code]);
                $redemptionData = [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'points_used' => $redemption->points_spent,
                    'cash_paid' => $redemption->cash_spent,
                    'offer' => [
                        'id' => $redemption->offer->id,
                        'title' => $redemption->offer->title,
                        'image' => $redemption->offer->image_url
                            ? (filter_var($redemption->offer->image_url, FILTER_VALIDATE_URL)
                                ? $redemption->offer->image_url
                                : asset('storage/'.ltrim($redemption->offer->image_url, '/')))
                            : '/placeholder.jpg',
                    ],
                    'status' => $redemption->status,
                    'redeemed_at' => $redemption->created_at->toIso8601String(),
                    'qr_code_url' => $qrCodeUrl,
                ];

                return Inertia::render('merchant/RedemptionConfirmed', [
                    'redemption' => $redemptionData,
                ]);
            } else {
                // Handle points-only redemption (no cash)
                $redemption = MerchantHubOfferRedemption::with(['offer', 'user'])
                    ->where('receipt_code', $code)
                    ->firstOrFail();

                DB::commit();

                // Generate QR code URL
                $qrCodeUrl = route('merchant-hub.redemption.qr-code', ['code' => $redemption->receipt_code]);

                $offerImage = $redemption->offer->image_url
                    ? (filter_var($redemption->offer->image_url, FILTER_VALIDATE_URL)
                        ? $redemption->offer->image_url
                        : asset('storage/'.ltrim($redemption->offer->image_url, '/')))
                    : '/placeholder.jpg';

                // Render success page instead of redirecting
                $redemptionData = [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'points_used' => $redemption->points_spent,
                    'cash_paid' => $redemption->cash_spent,
                    'offer' => [
                        'id' => $redemption->offer->id,
                        'title' => $redemption->offer->title,
                        'image' => $offerImage,
                    ],
                    'status' => $redemption->status,
                    'redeemed_at' => $redemption->created_at->toIso8601String(),
                    'qr_code_url' => $qrCodeUrl,
                ];

                return Inertia::render('merchant/RedemptionConfirmed', [
                    'redemption' => $redemptionData,
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Offer redemption success handler error: '.$e->getMessage());

            return redirect()->route('hub.index')
                ->with('error', 'Something went wrong completing your claim. Please contact support.');
        }
    }
}
