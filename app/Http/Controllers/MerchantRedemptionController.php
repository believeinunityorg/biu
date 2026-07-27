<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\MerchantHubOffer;
use App\Models\MerchantHubOfferRedemption;
use App\Models\MerchantHubReferralReward;
use App\Models\Order;
use App\Models\StateSalesTax;
use App\Models\Transaction;
use App\Services\BiuPlatformFeeService;
use App\Services\ShippoService;
use App\Services\StripeProcessingFeeEstimator;
use App\Services\ParticipationActivityService;
use App\Services\ParticipationBrpAwardService;
use App\Support\BrpParticipationModule;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class MerchantRedemptionController extends Controller
{
    public function __construct(private readonly ShippoService $shippoService) {}

    /**
     * Ensure redemption has a share_token (for post-purchase share link).
     * Same person + same product = same link: reuse existing token if they already have one for this offer.
     */
    private function ensureShareToken(MerchantHubOfferRedemption $redemption): void
    {
        if (! empty($redemption->share_token)) {
            return;
        }
        $existing = MerchantHubOfferRedemption::where('user_id', $redemption->user_id)
            ->where('merchant_hub_offer_id', $redemption->merchant_hub_offer_id)
            ->whereNotNull('share_token')
            ->where('id', '!=', $redemption->id)
            ->first();
        $token = $existing ? $existing->share_token : strtoupper(Str::random(12));
        $redemption->update(['share_token' => $token]);
    }

    /**
     * If visitor came via referral link, credit referrer 500 points once per referred purchase.
     * Sharer gets points only one time for each separate purchase made through their link.
     * Sharer must NEVER get points when they are the purchaser (same person buying = no reward).
     */
    private function awardReferralIfApplicable(int $newRedemptionId, int $buyerUserId): void
    {
        $refToken = session('merchant_hub_ref');
        if (empty($refToken)) {
            return;
        }
        $referrerRedemption = MerchantHubOfferRedemption::where('share_token', $refToken)
            ->whereIn('status', ['approved', 'fulfilled'])
            ->first();
        // Same person (sharer) purchasing through their own link = no points for anyone
        if (! $referrerRedemption || (int) $referrerRedemption->user_id === (int) $buyerUserId) {
            session()->forget('merchant_hub_ref');

            return;
        }
        // Buyer already purchased this same product before = referrer gets no points (first-time purchase only)
        $newRedemption = MerchantHubOfferRedemption::find($newRedemptionId);
        if ($newRedemption && MerchantHubOfferRedemption::where('user_id', $buyerUserId)
            ->where('merchant_hub_offer_id', $newRedemption->merchant_hub_offer_id)
            ->where('id', '!=', $newRedemptionId)
            ->whereIn('status', ['approved', 'fulfilled'])
            ->exists()) {
            session()->forget('merchant_hub_ref');

            return;
        }
        $referrer = $referrerRedemption->user;
        if (! $referrer) {
            session()->forget('merchant_hub_ref');

            return;
        }
        // One reward per referred purchase: create only if not already awarded for this redemption
        $reward = MerchantHubReferralReward::firstOrCreate(
            ['referral_redemption_id' => $newRedemptionId],
            [
                'referrer_user_id' => $referrer->id,
                'points_awarded' => ParticipationBrpAwardService::previewAmount($referrer, BrpParticipationModule::MERCHANT_REFERRAL),
            ]
        );
        if ($reward->wasRecentlyCreated) {
            $pointsAwarded = ParticipationBrpAwardService::previewAmount($referrer, BrpParticipationModule::MERCHANT_REFERRAL);
            ParticipationActivityService::complete(
                $referrer,
                BrpParticipationModule::MERCHANT_REFERRAL,
                $newRedemptionId,
                'Merchant referral reward: someone purchased via your share link',
                ['referral_redemption_id' => $newRedemptionId],
                'merchant_hub_referral',
                'merchant_hub_redemption',
            );
            Transaction::create([
                'user_id' => $referrer->id,
                'related_id' => $reward->id,
                'related_type' => MerchantHubReferralReward::class,
                'type' => 'referral_reward',
                'status' => 'completed',
                'amount' => 0,
                'fee' => 0,
                'currency' => 'USD',
                'payment_method' => 'referral',
                'meta' => [
                    'points_awarded' => $pointsAwarded,
                    'referral_redemption_id' => $newRedemptionId,
                    'description' => 'Merchant referral reward: someone purchased via your share link',
                ],
                'processed_at' => now(),
            ]);
        }
        session()->forget('merchant_hub_ref');
    }

    private function awardMarketplacePurchaseBrp(MerchantHubOfferRedemption $redemption): void
    {
        $buyer = $redemption->user;
        if ($buyer === null) {
            return;
        }

        ParticipationActivityService::complete(
            $buyer,
            BrpParticipationModule::MARKETPLACE_PURCHASE,
            $redemption->id,
            'Participation reward for merchant hub purchase',
            [
                'redemption_id' => $redemption->id,
                'offer_id' => $redemption->merchant_hub_offer_id,
            ],
            null,
            'merchant_hub_redemption',
        );
    }

    /**
     * SEO-friendly referral link: /merchant-hub/offers/{id}/ref/{refCode}
     * Returns 200 with product image, title, description for social preview (og/twitter).
     * Stores ref in session for non-own links and redirects users to offer page.
     */
    public function offerRefRedirect(Request $request, $id, $refCode)
    {
        $offer = MerchantHubOffer::with('merchant')->find($id);
        if (! $offer) {
            return redirect()->route('merchant-hub.index');
        }
        $redemption = MerchantHubOfferRedemption::where('share_token', $refCode)
            ->whereIn('status', ['approved', 'fulfilled'])
            ->first();
        if ($redemption) {
            $isOwnLink = Auth::check() && (int) $redemption->user_id === (int) Auth::id();
            if (! $isOwnLink) {
                session(['merchant_hub_ref' => $refCode]);
            }
        }
        $imageUrl = $offer->image_url;
        if ($imageUrl && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $imageUrl = asset('storage/'.ltrim($imageUrl, '/'));
        }
        if (empty($imageUrl) || $imageUrl === '/storage/') {
            $imageUrl = asset('placeholder.jpg');
        }
        $shareUrl = url()->current();
        $redirectUrl = route('merchant-hub.offer.show', $id);
        $title = $offer->title.' - '.($offer->merchant->name ?? config('app.name'));
        $description = $offer->short_description ?: \Illuminate\Support\Str::limit(strip_tags($offer->description ?? ''), 160);
        if (empty($description)) {
            $description = 'Check out this offer on '.config('app.name');
        }

        return response()->view('merchant-hub.share-ref', [
            'title' => $title,
            'description' => $description,
            'imageUrl' => $imageUrl,
            'shareUrl' => $shareUrl,
            'redirectUrl' => $redirectUrl,
        ], 200)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * My Purchases: list all merchant-hub redemptions for the current user.
     */
    public function myPurchases(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login', ['redirect' => route('merchant-hub.my-purchases')]);
        }

        $tab = $request->get('tab', 'offers');
        if (! in_array($tab, ['offers', 'products'], true)) {
            $tab = 'offers';
        }

        $offerCount = MerchantHubOfferRedemption::query()->where('user_id', $user->id)->count();
        $productOrderCount = Order::query()
            ->where('user_id', $user->id)
            ->whereHas('items', function ($q) {
                $q->whereNotNull('marketplace_product_id');
            })
            ->count();

        $offers = null;
        $productOrders = null;

        if ($tab === 'offers') {
            $redemptions = MerchantHubOfferRedemption::with(['offer.merchant'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(12)
                ->withQueryString();
            $offers = $redemptions->through(function ($r) {
                $imageUrl = $r->offer->image_url ?? null;
                if ($imageUrl && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $imageUrl = asset('storage/'.ltrim($imageUrl, '/'));
                }

                return [
                    'id' => (string) $r->id,
                    'receipt_code' => $r->receipt_code,
                    'offer_title' => $r->offer->title ?? 'N/A',
                    'offer_image' => $imageUrl ?: '/placeholder.jpg',
                    'merchant_name' => $r->offer->merchant->name ?? 'N/A',
                    'points_used' => (int) $r->points_spent,
                    'cash_paid' => $r->cash_spent ? (float) $r->cash_spent : null,
                    'subtotal_amount' => $r->subtotal_amount ? (float) $r->subtotal_amount : null,
                    'shipping_cost' => $r->shipping_cost ? (float) $r->shipping_cost : 0,
                    'tax_amount' => $r->tax_amount ? (float) $r->tax_amount : 0,
                    'platform_fee_amount' => $r->platform_fee_amount ? (float) $r->platform_fee_amount : 0,
                    'total_amount' => $r->total_amount ? (float) $r->total_amount : (float) ($r->cash_spent ?? 0),
                    'stripe_processing_fee_addon' => $r->stripe_processing_fee_addon !== null
                        ? (float) $r->stripe_processing_fee_addon
                        : null,
                    'shipping_status' => $r->shipping_status,
                    'tracking_number' => $r->tracking_number,
                    'tracking_url' => $r->tracking_url,
                    'label_url' => $r->label_url,
                    'status' => $r->status,
                    'purchased_at' => $r->created_at->toIso8601String(),
                    'confirmed_url' => route('merchant-hub.redemption.confirmed', $r->receipt_code),
                ];
            });
        } else {
            $productOrders = Order::query()
                ->where('user_id', $user->id)
                ->whereHas('items', function ($q) {
                    $q->whereNotNull('marketplace_product_id');
                })
                ->with(['items.marketplaceProduct'])
                ->orderByDesc('created_at')
                ->paginate(12)
                ->withQueryString();
            $productOrders->getCollection()->transform(function (Order $order) {
                $first = $order->items->whereNotNull('marketplace_product_id')->first();
                $mp = $first?->marketplaceProduct;
                $img = $first?->primary_image;
                if ($img === null && $mp && is_array($mp->images) && count($mp->images) > 0) {
                    $path = $mp->images[0];
                    $img = filter_var($path, FILTER_VALIDATE_URL) ? $path : asset('storage/'.ltrim((string) $path, '/'));
                }

                return [
                    'id' => $order->id,
                    'order_number' => 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                    'total_amount' => (float) $order->total_amount,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'purchased_at' => $order->created_at->toIso8601String(),
                    'primary_image' => $img ?? '/placeholder.jpg',
                    'summary' => $mp?->name ?? 'Merchant product',
                    'detail_url' => route('user.profile.order-details', $order->id),
                ];
            });
        }

        return Inertia::render('frontend/merchant-hub/MyPurchases', [
            'activeTab' => $tab,
            'offerCount' => $offerCount,
            'productOrderCount' => $productOrderCount,
            'offers' => $offers,
            'productOrders' => $productOrders,
        ]);
    }

    /**
     * Show checkout (shipping address) page before pay with cash.
     */
    public function checkoutShow(Request $request, $id)
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login', ['redirect' => route('merchant-hub.offer.checkout', $id)]);
        }
        $offer = MerchantHubOffer::with(['merchant', 'category'])->where('id', $id)->where('status', 'active')->firstOrFail();
        if (! $offer->isAvailable()) {
            return redirect()->route('merchant-hub.offer.show', $id)->with('error', 'This offer is no longer available.');
        }
        $merchantModel = $this->resolveMerchantAddressOwner($offer);
        $pickupAddress = ($offer->pickup_available ?? false) && $merchantModel
            ? MarketplacePickup::pickupAddressForMerchantHubOffer($offer, $merchantModel)
            : null;
        $referencePrice = (float) ($offer->reference_price ?? 0);
        if ($referencePrice <= 0 && $offer->points_required > 0 && $offer->discount_percentage > 0) {
            $referencePrice = ($offer->points_required / 1000) * 100 / (float) $offer->discount_percentage;
        }
        $communityCashPrice = $referencePrice > 0 ? round($referencePrice, 2) : 0; // Full amount when paying with cash (no points)
        $platformFee = BiuPlatformFeeService::merchantHubCatalogPlatformFeeFromAmount((float) $communityCashPrice);
        $imageUrl = $offer->image_url;
        if ($imageUrl && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $imageUrl = asset('storage/'.ltrim($imageUrl, '/'));
        }

        return Inertia::render('frontend/merchant-hub/CheckoutShipping', [
            'offer' => [
                'id' => (string) $offer->id,
                'title' => $offer->title,
                'image' => $imageUrl ?: '/placeholder.jpg',
                'merchantName' => $offer->merchant->name,
                'amount' => $communityCashPrice,
                'platformFee' => $platformFee,
                'pointsRequired' => (int) ($offer->points_required ?? 0),
                'userPoints' => (int) ($user->reward_points ?? 0),
                'currency' => $offer->currency ?? 'USD',
                'pickupAvailable' => (bool) ($offer->pickup_available ?? false),
                'pickupAddress' => $pickupAddress,
            ],
            'defaultPaymentMethod' => in_array($request->string('payment_method')->toString(), ['points', 'cash', 'believe_points'], true)
                ? $request->string('payment_method')->toString()
                : 'cash',
        ]);
    }

    /**
     * Quote Shippo rates from merchant address to buyer shipping address.
     */
    public function checkoutRates(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:merchant_hub_offers,id',
            'shipping_name' => 'required|string|max:255',
            'shipping_line1' => 'required|string|max:255',
            'shipping_line2' => 'nullable|string|max:255',
            'shipping_city' => 'required|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'required|string|max:20',
            'shipping_country' => 'required|string|size:2',
            'payment_method' => 'required|in:points,cash,believe_points',
        ]);
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }
        $offer = MerchantHubOffer::with('merchant')->findOrFail($request->offer_id);
        if (! $offer->isAvailable()) {
            return response()->json(['error' => 'This offer is no longer available.'], 422);
        }
        $referencePrice = (float) ($offer->reference_price ?? 0);
        if ($referencePrice <= 0 && $offer->points_required > 0 && $offer->discount_percentage > 0) {
            $referencePrice = ($offer->points_required / 1000) * 100 / (float) $offer->discount_percentage;
        }
        if ($referencePrice <= 0) {
            return response()->json(['error' => 'This offer does not support purchase.'], 422);
        }

        $subtotalAmount = round($referencePrice, 2); // Full amount when paying with cash (no points)
        $platformFeeAmount = BiuPlatformFeeService::merchantHubCatalogPlatformFeeFromAmount((float) $subtotalAmount);

        if (! $this->shippoService->isConfigured()) {
            return response()->json(['error' => 'Shippo is not configured.'], 503);
        }

        $merchantModel = $this->resolveMerchantAddressOwner($offer);
        if (! $merchantModel) {
            return response()->json(['error' => 'Merchant shipping address is not configured.'], 422);
        }

        $shipFrom = $this->shippoService->shipFromPayloadForMerchant($merchantModel);
        $shipTo = [
            'name' => (string) $request->shipping_name,
            'street1' => (string) $request->shipping_line1,
            'street2' => (string) ($request->shipping_line2 ?? ''),
            'city' => (string) $request->shipping_city,
            'state' => (string) ($request->shipping_state ?? ''),
            'zip' => (string) $request->shipping_postal_code,
            'country' => $this->shippoService->normalizeCountryToIso2((string) $request->shipping_country),
            'phone' => $this->shippoService->ensureRecipientPhoneForShippo($user->contact_number ?? null),
            'email' => (string) $user->email,
        ];
        $parcel = [
            'length' => '10',
            'width' => '8',
            'height' => '4',
            'distance_unit' => 'in',
            'weight' => '16',
            'mass_unit' => 'oz',
        ];
        $ratesResult = $this->shippoService->getRatesForAddresses($shipFrom, $shipTo, $parcel);
        if (empty($ratesResult['success']) || empty($ratesResult['rates'])) {
            return response()->json(['error' => $ratesResult['error'] ?? 'No shipping rates found.'], 422);
        }

        $methods = $this->shippoService->ratesToCheckoutMethods($ratesResult['rates']);
        if ($methods === []) {
            return response()->json(['error' => 'No shipping methods available for this address.'], 422);
        }

        $taxAmount = $this->calculateStateSalesTaxForAmount(
            (float) $subtotalAmount,
            (string) ($request->shipping_state ?? '')
        );

        $paymentMethod = (string) $request->payment_method;
        $enrichMethod = function (array $m) use ($subtotalAmount, $platformFeeAmount, $taxAmount, $paymentMethod) {
            $shipping = round((float) ($m['cost'] ?? 0), 2);
            $m['cost'] = $shipping;
            $basket = round($subtotalAmount + $platformFeeAmount + $taxAmount + $shipping, 2);
            $m['total_amount'] = $basket;
            if ($paymentMethod === 'cash') {
                $pt = StripeProcessingFeeEstimator::applyPassThrough($basket, 'card');
                $m['stripe_processing_fee_addon'] = $pt['fee_addon_usd'];
                $m['charged_total'] = $pt['gross_usd'];
            } else {
                $m['stripe_processing_fee_addon'] = 0.0;
                $m['charged_total'] = $basket;
            }

            return $m;
        };
        $methods = array_map($enrichMethod, $methods);

        if (($offer->pickup_available ?? false) && $merchantModel) {
            $pickupLines = MarketplacePickup::pickupAddressForMerchantHubOffer($offer, $merchantModel);
            if ($pickupLines) {
                array_unshift($methods, $enrichMethod([
                    'id' => MarketplacePickup::RATE_ID,
                    'name' => 'Pick up at merchant (no shipping)',
                    'cost' => 0.0,
                    'estimated_days' => '—',
                    'provider' => 'pickup',
                    'pickup_address' => $pickupLines,
                ]));
            }
        }

        return response()->json([
            'success' => true,
            'shipment_id' => $ratesResult['shipment_id'] ?? null,
            'shipping_methods' => $methods,
            'subtotal_amount' => $subtotalAmount,
            'platform_fee_amount' => $platformFeeAmount,
            'tax_amount' => $taxAmount,
            'points_required' => (int) ($offer->points_required ?? 0),
            'user_points' => (int) ($user->reward_points ?? 0),
            'payment_method' => $paymentMethod,
            'stripe_processing_fee_applied' => $paymentMethod === 'cash',
        ]);
    }

    /**
     * Submit shipping + shipping method and continue to payment/confirmation.
     */
    public function checkoutStore(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:merchant_hub_offers,id',
            'payment_method' => 'required|in:points,cash,believe_points',
            'shipping_name' => 'required|string|max:255',
            'shipping_line1' => 'required|string|max:255',
            'shipping_line2' => 'nullable|string|max:255',
            'shipping_city' => 'required|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'required|string|max:20',
            'shipping_country' => 'required|string|size:2',
            'shippo_shipment_id' => 'nullable|string|max:120',
            'shippo_rate_object_id' => 'required|string|max:120',
        ]);
        $user = Auth::user();
        if (! $user) {
            return back()->withErrors(['error' => 'You must be logged in.']);
        }

        $offer = MerchantHubOffer::with('merchant')->findOrFail($request->offer_id);
        if (! $offer->isAvailable()) {
            return back()->withErrors(['error' => 'This offer is no longer available.']);
        }

        $isPickup = (string) $request->shippo_rate_object_id === MarketplacePickup::RATE_ID;

        if ($isPickup) {
            if (! ($offer->pickup_available ?? false)) {
                return back()->withErrors(['error' => 'Pickup is not available for this offer.']);
            }
            $selectedRate = [
                'id' => MarketplacePickup::RATE_ID,
                'cost' => 0.0,
                'provider' => 'pickup',
            ];
        } else {
            if (! $this->shippoService->isConfigured()) {
                return back()->withErrors(['error' => 'Shipping is not configured.']);
            }
            if (empty($request->shippo_shipment_id)) {
                return back()->withErrors(['error' => 'Please get shipping options first.']);
            }

            $rates = $this->shippoService->retrieveShipmentRates((string) $request->shippo_shipment_id);
            if (empty($rates['success']) || empty($rates['rates'])) {
                return back()->withErrors(['error' => $rates['error'] ?? 'Could not refresh shipping rates. Please try again.']);
            }
            $methods = $this->shippoService->ratesToCheckoutMethods($rates['rates']);
            $selectedRate = collect($methods)->first(function ($m) use ($request) {
                return (string) ($m['id'] ?? '') === (string) $request->shippo_rate_object_id;
            });
            if (! $selectedRate) {
                return back()->withErrors(['error' => 'Selected shipping method is no longer available. Please refresh rates.']);
            }
        }

        $referencePrice = (float) ($offer->reference_price ?? 0);
        if ($referencePrice <= 0 && $offer->points_required > 0 && $offer->discount_percentage > 0) {
            $referencePrice = ($offer->points_required / 1000) * 100 / (float) $offer->discount_percentage;
        }
        if ($referencePrice <= 0) {
            return back()->withErrors(['error' => 'This offer does not support purchase.']);
        }

        $subtotalAmount = round($referencePrice, 2);
        $platformFeeAmount = BiuPlatformFeeService::merchantHubCatalogPlatformFeeFromAmount((float) $subtotalAmount);
        $shippingCost = round((float) ($selectedRate['cost'] ?? 0), 2);
        $taxAmount = $this->calculateStateSalesTaxForAmount(
            (float) $subtotalAmount,
            (string) ($request->shipping_state ?? '')
        );
        $totalAmount = round($subtotalAmount + $platformFeeAmount + $shippingCost + $taxAmount, 2);
        $receiptCode = 'RED-'.strtoupper(Str::random(8));
        $paymentMethod = (string) $request->payment_method;
        $cardPassThrough = $paymentMethod === 'cash'
            ? StripeProcessingFeeEstimator::applyPassThrough($totalAmount, 'card')
            : ['gross_usd' => $totalAmount, 'fee_addon_usd' => 0.0];
        $chargedGrossUsd = round((float) $cardPassThrough['gross_usd'], 2);
        $stripeProcessingFeeAddon = round(max(0.0, (float) $cardPassThrough['fee_addon_usd']), 2);

        DB::beginTransaction();
        try {
            $pointsRequired = (int) ($offer->points_required ?? 0);

            if ($paymentMethod === 'points') {
                $user->refresh();
                $userPoints = (int) ($user->reward_points ?? 0);
                if ($userPoints < $pointsRequired) {
                    DB::rollBack();

                    return back()->withErrors(['error' => 'You do not have enough reward points for this offer.']);
                }
            } elseif ($paymentMethod === 'believe_points') {
                $user->refresh();
                $bpRequired = round($totalAmount, 2);
                $userBeliefPoints = $user->purchasedBelievePointsBalance();
                if ($userBeliefPoints < $bpRequired) {
                    DB::rollBack();

                    return back()->withErrors([
                        'error' => 'Insufficient purchased Believe Points. You need '.number_format($bpRequired, 2).' BP but only have '.number_format($userBeliefPoints, 2).' BP (Gift BP can only be used for gift cards).',
                    ]);
                }
            }

            $redemption = MerchantHubOfferRedemption::create([
                'merchant_hub_offer_id' => $offer->id,
                'user_id' => $user->id,
                'points_spent' => $paymentMethod === 'points' ? $pointsRequired : 0,
                'cash_spent' => in_array($paymentMethod, ['cash', 'believe_points'], true) ? $chargedGrossUsd : 0,
                'subtotal_amount' => $subtotalAmount,
                'platform_fee_amount' => $platformFeeAmount,
                'shipping_cost' => $shippingCost,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'stripe_processing_fee_addon' => $paymentMethod === 'cash' && $stripeProcessingFeeAddon > 0
                    ? $stripeProcessingFeeAddon
                    : null,
                'shippo_shipment_id' => $isPickup ? null : ($request->filled('shippo_shipment_id') ? (string) $request->shippo_shipment_id : null),
                'shippo_rate_object_id' => $isPickup ? null : (string) $request->shippo_rate_object_id,
                'carrier' => $isPickup ? 'local_pickup' : ($selectedRate['provider'] ?? null),
                'status' => 'pending',
                'receipt_code' => $receiptCode,
                'shipping_name' => $request->shipping_name,
                'shipping_line1' => $request->shipping_line1,
                'shipping_line2' => $request->shipping_line2,
                'shipping_city' => $request->shipping_city,
                'shipping_state' => $request->shipping_state,
                'shipping_postal_code' => $request->shipping_postal_code,
                'shipping_country' => strtoupper((string) $request->shipping_country),
            ]);

            if ($paymentMethod === 'points') {
                $user->decrement('reward_points', $pointsRequired);
                \App\Models\RewardPointLedger::createDebit(
                    $user->id,
                    'merchant_hub_redemption',
                    $redemption->id,
                    $pointsRequired,
                    "Merchant hub offer claimed: {$offer->title}",
                    [
                        'offer_id' => $offer->id,
                        'offer_title' => $offer->title,
                        'merchant_name' => $offer->merchant->name,
                        'receipt_code' => $receiptCode,
                        'redemption_id' => $redemption->id,
                    ]
                );

                $redemption->update(['status' => 'approved']);
                $this->ensureShareToken($redemption);
                $this->attemptShippoLabelPurchaseForRedemption($redemption);

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
                        'points_spent' => $pointsRequired,
                        'offer_id' => $offer->id,
                        'receipt_code' => $receiptCode,
                        'shipping_cost' => $shippingCost,
                        'tax_amount' => $taxAmount,
                        'platform_fee_amount' => $platformFeeAmount,
                        'total_amount' => $totalAmount,
                        'shippo_shipment_id' => $redemption->shippo_shipment_id,
                        'shippo_rate_object_id' => $redemption->shippo_rate_object_id,
                    ],
                    'processed_at' => now(),
                ]);

                DB::commit();

                return redirect()->route('merchant-hub.redemption.confirmed', $redemption->receipt_code);
            }

            if ($paymentMethod === 'believe_points') {
                $bpRequired = round($totalAmount, 2);
                if (! $user->deductBelievePoints($bpRequired)) {
                    DB::rollBack();

                    return back()->withErrors(['error' => 'Failed to deduct Believe Points. Please try again.']);
                }

                $redemption->update(['status' => 'approved']);
                $this->ensureShareToken($redemption);
                $this->attemptShippoLabelPurchaseForRedemption($redemption);

                Transaction::create([
                    'user_id' => $user->id,
                    'related_id' => $redemption->id,
                    'related_type' => MerchantHubOfferRedemption::class,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'amount' => $bpRequired,
                    'fee' => 0,
                    'currency' => $offer->currency ?? 'USD',
                    'payment_method' => 'believe_points',
                    'meta' => [
                        'believe_points_used' => $bpRequired,
                        'offer_id' => $offer->id,
                        'receipt_code' => $receiptCode,
                        'shipping_cost' => $shippingCost,
                        'tax_amount' => $taxAmount,
                        'platform_fee_amount' => $platformFeeAmount,
                        'total_amount' => $totalAmount,
                        'shippo_shipment_id' => $redemption->shippo_shipment_id,
                        'shippo_rate_object_id' => $redemption->shippo_rate_object_id,
                    ],
                    'processed_at' => now(),
                ]);

                DB::commit();

                return redirect()->route('merchant-hub.redemption.confirmed', $redemption->receipt_code);
            }

            if (! config('cashier.secret')) {
                DB::rollBack();

                return back()->withErrors(['error' => 'Stripe is not configured.']);
            }

            $amountCents = (int) round($chargedGrossUsd * 100);
            if ($amountCents < 50) {
                DB::rollBack();

                return back()->withErrors(['error' => 'Minimum charge amount is 0.50. This offer cannot be purchased with cash.']);
            }

            $currency = strtolower($offer->currency ?? 'usd');
            $merchantName = $offer->merchant ? $offer->merchant->name : 'Merchant';
            $checkout = $user->checkout([
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $offer->title,
                            'description' => 'Pay with cash (full amount) - '.$merchantName,
                        ],
                        'unit_amount' => $amountCents,
                    ],
                    'quantity' => 1,
                ],
            ], [
                'success_url' => route('merchant-hub.redemption.stripe-success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('merchant-hub.offer.checkout', $offer->id).'?payment_method=cash',
                'payment_method_types' => ['card'],
                'automatic_tax' => ['enabled' => false],
                'metadata' => array_filter([
                    'redemption_id' => (string) $redemption->id,
                    'user_id' => (string) $user->id,
                    'offer_id' => (string) $offer->id,
                    'type' => 'merchant_hub_redemption',
                    'payment_method' => 'cash',
                    'cash_amount' => (string) $chargedGrossUsd,
                    'basket_total' => (string) $totalAmount,
                    'stripe_processing_fee_addon' => (string) $stripeProcessingFeeAddon,
                    'subtotal_amount' => (string) $subtotalAmount,
                    'platform_fee_amount' => (string) $platformFeeAmount,
                    'shipping_cost' => (string) $shippingCost,
                    'tax_amount' => (string) $taxAmount,
                    'shippo_shipment_id' => (string) $request->shippo_shipment_id,
                    'shippo_rate_object_id' => (string) $request->shippo_rate_object_id,
                    'receipt_code' => $receiptCode,
                    'currency' => $currency,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);

            DB::commit();

            return Inertia::location($checkout->url);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Merchant hub checkout error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return back()->withErrors(['error' => 'Could not start checkout. Please try again.']);
        }
    }

    /**
     * Process redemption request (points or pay with cash).
     */
    public function redeem(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:merchant_hub_offers,id',
            'payment_method' => 'nullable|in:points,cash',
        ]);

        $user = Auth::user();
        $paymentMethod = $request->input('payment_method', 'points');

        if (! $user) {
            return back()->withErrors(['error' => 'You must be logged in to claim merchant offers.']);
        }

        DB::beginTransaction();

        try {
            $offer = MerchantHubOffer::with(['merchant', 'category'])->findOrFail($request->offer_id);
            $merchantId = $offer->merchant_hub_merchant_id;

            if (! $offer->isAvailable()) {
                return back()->withErrors(['error' => 'This offer is no longer available.']);
            }

            $user->refresh();
            $userPoints = (float) ($user->reward_points ?? 0);

            // Pay with cash: no points required, create redemption and Stripe session
            if ($paymentMethod === 'cash') {
                $referencePrice = (float) ($offer->reference_price ?? 0);
                if ($referencePrice <= 0 && $offer->points_required > 0 && $offer->discount_percentage > 0) {
                    $referencePrice = ($offer->points_required / 1000) * 100 / (float) $offer->discount_percentage;
                }
                if ($referencePrice <= 0) {
                    DB::rollBack();

                    return back()->withErrors(['error' => 'This offer does not support cash purchase.']);
                }
                $cashAmount = round($referencePrice, 2); // Full amount when paying with cash (no points)

                $receiptCode = 'RED-'.strtoupper(Str::random(8));

                $amountCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd($cashAmount, 'card');
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
                    'success_url' => route('merchant-hub.redemption.stripe-success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('merchant-hub.offer.show', $offer->id),
                    'payment_method_types' => ['card'],
                    // Merchant hub offer checkout uses app-side state tax; disable Stripe automatic tax.
                    'automatic_tax' => ['enabled' => false],
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'offer_id' => (string) $offer->id,
                        'type' => 'merchant_hub_redemption',
                        'payment_method' => 'cash',
                        'cash_amount' => (string) $cashAmount,
                        'receipt_code' => $receiptCode,
                        'currency' => $currency,
                    ],
                ]);

                DB::commit();

                return Inertia::location($checkout->url);
            }

            // Pay with points
            if ($userPoints < $offer->points_required) {
                DB::rollBack();

                return back()->withErrors([
                    'error' => 'You do not have enough points. You need '.number_format($offer->points_required).' points, but you only have '.number_format($userPoints).'. Use "Pay with cash" to pay the full amount instead.',
                ]);
            }

            if ($offer->is_standard_discount && $offer->points_required != 100) {
                DB::rollBack();

                return back()->withErrors(['error' => 'Standard 10% discount offers require exactly 100 points.']);
            }

            $receiptCode = 'RED-'.strtoupper(Str::random(8));
            $cashRequired = 0;
            if (! $offer->is_standard_discount && $offer->cash_required) {
                $cashRequired = (float) $offer->cash_required;
            }
            $status = $cashRequired > 0 ? 'pending' : 'approved';

            $redemption = MerchantHubOfferRedemption::create([
                'merchant_hub_offer_id' => $offer->id,
                'user_id' => $user->id,
                'points_spent' => $offer->points_required,
                'cash_spent' => $cashRequired,
                'status' => $status,
                'receipt_code' => $receiptCode,
            ]);
            $this->ensureShareToken($redemption);

            $user->decrement('reward_points', $offer->points_required);
            \App\Models\RewardPointLedger::createDebit(
                $user->id,
                'merchant_hub_redemption',
                $redemption->id,
                (int) $offer->points_required,
                "Merchant hub offer claimed: {$offer->title}",
                [
                    'offer_id' => $offer->id,
                    'offer_title' => $offer->title,
                    'merchant_name' => $offer->merchant->name,
                    'receipt_code' => $receiptCode,
                    'redemption_id' => $redemption->id,
                ]
            );

            if ($cashRequired > 0) {
                DB::commit();

                return redirect()->route('merchant-hub.offer.show', $offer->id)
                    ->with('error', 'Cash payment processing not yet implemented. Please contact support.');
            }

            DB::commit();
            $this->awardReferralIfApplicable((int) $redemption->id, (int) $user->id);
            $this->awardMarketplacePurchaseBrp($redemption->fresh(['user']));
            $shareLink = $redemption->share_token
                ? url()->route('merchant-hub.offer.show.ref', ['id' => $offer->id, 'refCode' => $redemption->share_token])
                : null;

            return redirect()->route('merchant-hub.offer.show', $offer->id)
                ->with('redemption_success', [
                    'id' => $redemption->id,
                    'code' => $receiptCode,
                    'offer' => [
                        'id' => $offer->id,
                        'title' => $offer->title,
                        'merchant_name' => $offer->merchant->name,
                    ],
                    'points_spent' => $offer->points_required,
                    'cash_spent' => $cashRequired,
                    'status' => $status,
                    'redeemed_at' => $redemption->created_at->toIso8601String(),
                    'qr_code_url' => route('merchant-hub.redemption.qr-code', ['code' => $receiptCode]),
                    'share_link' => $shareLink,
                    'share_token' => $redemption->share_token,
                ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Redemption failed: '.$e->getMessage());

            return back()->withErrors(['error' => 'Something went wrong completing your claim. Please try again.']);
        }
    }

    /**
     * Handle Stripe checkout success for main app merchant-hub (pay with cash).
     * Uses Laravel Cashier to retrieve the session.
     */
    public function stripeSuccess(Request $request)
    {
        $sessionId = $request->get('session_id');
        if (! $sessionId) {
            return redirect()->route('merchant-hub.index')->with('error', 'Invalid session.');
        }

        DB::beginTransaction();
        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
            if ($session->payment_status !== 'paid') {
                return redirect()->route('merchant-hub.index')->with('error', 'Payment was not completed.');
            }
            $metadata = $session->metadata ?? (object) [];
            $redemptionId = $metadata->redemption_id ?? null;

            if ($redemptionId) {
                // Shipping flow: redemption was created with address before Stripe
                $redemption = MerchantHubOfferRedemption::with(['offer'])->findOrFail($redemptionId);
                $redemption->update(['status' => 'approved']);
                $this->ensureShareToken($redemption);
                $this->attemptShippoLabelPurchaseForRedemption($redemption);

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
                    'transaction_id' => $session->payment_intent ?? null,
                    'meta' => array_merge([
                        'stripe_session_id' => $sessionId,
                        'points_spent' => 0,
                        'offer_id' => $redemption->offer->id,
                        'receipt_code' => $redemption->receipt_code,
                    ], BiuPlatformFeeService::merchantHubCatalogLedgerMetaSlice((float) ($redemption->subtotal_amount ?? $redemption->cash_spent))),
                    'processed_at' => now(),
                ]);
            } else {
                // Legacy: create redemption from metadata (no shipping)
                $offerId = $metadata->offer_id ?? null;
                $userId = $metadata->user_id ?? null;
                $cashAmount = isset($metadata->cash_amount) ? (float) $metadata->cash_amount : 0;
                $receiptCode = $metadata->receipt_code ?? 'RED-'.strtoupper(Str::random(8));
                $currency = $metadata->currency ?? 'usd';
                if (! $offerId || ! $userId || $cashAmount <= 0) {
                    return redirect()->route('merchant-hub.index')->with('error', 'Invalid payment session.');
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
                $this->ensureShareToken($redemption);
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
                    'transaction_id' => $session->payment_intent ?? null,
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
            $this->awardReferralIfApplicable((int) $redemption->id, (int) $redemption->user_id);
            $this->awardMarketplacePurchaseBrp($redemption->fresh(['user']));

            return redirect()->route('merchant-hub.redemption.confirmed', $redemption->receipt_code);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Merchant hub Stripe success error: '.$e->getMessage());

            return redirect()->route('merchant-hub.index')->with('error', 'An error occurred. Please contact support.');
        }
    }

    /**
     * Show redemption confirmation page
     */
    public function confirmed(Request $request, $code = null)
    {
        $redemptionData = $request->session()->get('redemption');

        if (! $redemptionData && $code) {
            $redemption = MerchantHubOfferRedemption::with(['offer', 'user'])
                ->where('receipt_code', $code)
                ->firstOrFail();
            $this->ensureShareToken($redemption);
            $offer = $redemption->offer;
            $shareLink = $redemption->share_token
                ? url()->route('merchant-hub.offer.show.ref', ['id' => $offer->id, 'refCode' => $redemption->share_token])
                : null;
            $redemptionData = [
                'id' => $redemption->id,
                'code' => $redemption->receipt_code,
                'offer' => [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'image' => $offer->image_url ?: '/placeholder.jpg',
                ],
                'points_spent' => $redemption->points_spent,
                'cash_spent' => $redemption->cash_spent,
                'status' => $redemption->status,
                'redeemed_at' => $redemption->created_at->toIso8601String(),
                'qr_code_url' => route('merchant-hub.redemption.qr-code', ['code' => $redemption->receipt_code]),
                'share_token' => $redemption->share_token,
                'share_link' => $shareLink,
            ];
        }

        if (! $redemptionData) {
            return redirect()->route('merchant-hub.index')
                ->with('error', 'Redemption not found.');
        }

        return Inertia::render('frontend/merchant-hub/RedemptionConfirmed', [
            'redemption' => $redemptionData,
        ]);
    }

    /**
     * Generate QR code for redemption
     */
    public function generateQrCode(Request $request, $code)
    {
        try {
            Log::info('QR Code generation requested', [
                'code' => $code,
                'user_id' => Auth::id(),
                'authenticated' => Auth::check(),
                'ip' => $request->ip(),
            ]);

            // Verify the redemption exists (no auth required for QR code generation)
            $redemption = MerchantHubOfferRedemption::where('receipt_code', $code)->first();

            if (! $redemption) {
                Log::warning('QR Code generation attempted for non-existent redemption code: '.$code);
                // Still generate a QR code with error message
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Redemption not found: '.$code);

                return response($errorQr, 200, [
                    'Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ]);
            }

            // Create JSON data for QR code
            $qrData = json_encode([
                'type' => 'redemption',
                'code' => $redemption->receipt_code,
                'redemption_id' => $redemption->id,
                'offer_id' => $redemption->merchant_hub_offer_id,
                'user_id' => $redemption->user_id,
                'points_spent' => $redemption->points_spent,
                'created_at' => $redemption->created_at->toIso8601String(),
            ]);

            Log::info('QR Code JSON data generated', ['code' => $code, 'data' => $qrData]);

            // Generate QR code as SVG with JSON data
            $qrCode = QrCode::format('svg')
                ->size(300)
                ->margin(2)
                ->errorCorrection('M')
                ->generate($qrData);

            Log::info('QR Code generated successfully', ['code' => $code]);

            return response($qrCode, 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Exception $e) {
            Log::error('QR Code generation failed: '.$e->getMessage());

            // Return error QR code as SVG
            try {
                $errorQr = QrCode::format('svg')
                    ->size(300)
                    ->margin(2)
                    ->generate('Error: Unable to generate QR code');

                return response($errorQr, 200, [
                    'Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            } catch (\Exception $e2) {
                return response('QR Code generation failed', 500);
            }
        }
    }

    private function calculateStateSalesTaxForAmount(float $amountUsd, string $state): float
    {
        $state = strtoupper(trim($state));
        if ($state === '' || $amountUsd <= 0) {
            return 0.0;
        }

        $stateTax = StateSalesTax::where('state_code', $state)->first();
        if (! $stateTax) {
            return 0.0;
        }

        $rate = (float) $stateTax->base_sales_tax_rate;

        return round(($amountUsd * $rate) / 100, 2);
    }

    private function resolveMerchantAddressOwner(MerchantHubOffer $offer): ?Merchant
    {
        return Merchant::where('business_name', $offer->merchant->name)
            ->orWhere('name', $offer->merchant->name)
            ->with('shippingAddresses')
            ->first();
    }

    private function attemptShippoLabelPurchaseForRedemption(MerchantHubOfferRedemption $redemption): void
    {
        try {
            if (
                ! $this->shippoService->isConfigured()
                || ! MarketplacePickup::isShippoPurchasableRateId($redemption->shippo_rate_object_id)
                || ! empty($redemption->tracking_number)
            ) {
                return;
            }

            $purchase = $this->shippoService->purchaseLabel((string) $redemption->shippo_rate_object_id);
            if (($purchase['success'] ?? false) !== true) {
                return;
            }

            $redemption->update([
                'shippo_transaction_id' => $purchase['transaction_id'] ?? null,
                'tracking_number' => $purchase['tracking_number'] ?? null,
                'tracking_url' => $purchase['tracking_url'] ?? null,
                'label_url' => $purchase['label_url'] ?? null,
                'carrier' => $purchase['carrier'] ?? $redemption->carrier,
                'shipping_status' => 'label_created',
                'shipped_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Shippo label purchase failed for merchant hub offer', [
                'redemption_id' => $redemption->id,
                'offer_id' => $redemption->merchant_hub_offer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Show verification page for merchant (when scanning QR code)
     */
    public function verifyPage(Request $request, $code)
    {
        $redemption = MerchantHubOfferRedemption::with(['offer.merchant', 'user', 'eligibleItem'])
            ->where('receipt_code', $code)
            ->first();

        if (! $redemption) {
            return Inertia::render('merchant/RedemptionVerify', [
                'code' => $code,
                'error' => 'Redemption code not found.',
            ]);
        }

        // Check if already used
        if ($redemption->isUsed()) {
            return Inertia::render('merchant/RedemptionVerify', [
                'code' => $code,
                'redemption' => [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'user_name' => $redemption->user->name,
                    'user_email' => $redemption->user->email,
                    'points_spent' => $redemption->points_spent,
                    'cash_spent' => $redemption->cash_spent,
                    'status' => $redemption->status,
                    'discount_percentage' => $redemption->offer->discount_percentage ?? 10,
                    'discount_cap' => $redemption->offer->discount_cap,
                    'eligible_items' => [],
                    'redeemed_at' => $redemption->created_at->toIso8601String(),
                ],
                'error' => 'This redemption has already been used.',
            ]);
        }

        // Check if expired (optional - e.g., 30 days)
        if ($redemption->created_at->addDays(30)->isPast()) {
            return Inertia::render('merchant/RedemptionVerify', [
                'code' => $code,
                'error' => 'This redemption has expired. Redemptions are valid for 30 days.',
            ]);
        }

        // If merchant is authenticated, verify merchant matches
        $merchant = null;
        if (Auth::guard('merchant')->check()) {
            $merchant = Auth::guard('merchant')->user();
            $merchantHubMerchant = $this->getOrCreateMerchantHubMerchant($merchant);

            if ($redemption->offer->merchant_hub_merchant_id !== $merchantHubMerchant->id) {
                return Inertia::render('merchant/RedemptionVerify', [
                    'code' => $code,
                    'error' => 'This redemption is not for your merchant.',
                ]);
            }
        }

        // Get eligible items for this merchant
        $eligibleItems = $redemption->offer->merchant->eligibleItems()
            ->where('is_active', true)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'price' => $item->price,
                    'discount_cap' => $item->discount_cap,
                    'has_reached_limit' => $item->hasReachedLimit(),
                ];
            });

        return Inertia::render('merchant/RedemptionVerify', [
            'code' => $code,
            'redemption' => [
                'id' => $redemption->id,
                'code' => $redemption->receipt_code,
                'user_name' => $redemption->user->name,
                'user_email' => $redemption->user->email,
                'points_spent' => $redemption->points_spent,
                'cash_spent' => $redemption->cash_spent,
                'status' => $redemption->status,
                'discount_percentage' => $redemption->offer->discount_percentage ?? 10,
                'discount_cap' => $redemption->offer->discount_cap,
                'eligible_items' => $eligibleItems,
                'redeemed_at' => $redemption->created_at->toIso8601String(),
            ],
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'name' => $merchant->business_name ?? $merchant->name,
            ] : null,
        ]);
    }

    /**
     * Verify redemption code (for merchant scanning) - JSON API
     */
    public function verify(Request $request, $code)
    {
        $redemption = MerchantHubOfferRedemption::with(['offer.merchant', 'user', 'eligibleItem'])
            ->where('receipt_code', $code)
            ->first();

        if (! $redemption) {
            return response()->json([
                'error' => 'Redemption code not found.',
            ], 404);
        }

        // Check if already used
        if ($redemption->isUsed()) {
            return response()->json([
                'error' => 'This redemption has already been used.',
                'used_at' => $redemption->used_at->toIso8601String(),
            ], 400);
        }

        // Check if expired (optional - e.g., 30 days)
        if ($redemption->created_at->addDays(30)->isPast()) {
            return response()->json([
                'error' => 'This redemption has expired. Redemptions are valid for 30 days.',
            ], 400);
        }

        // If merchant is authenticated, verify merchant matches
        if (Auth::guard('merchant')->check()) {
            $merchant = Auth::guard('merchant')->user();
            $merchantHubMerchant = $this->getOrCreateMerchantHubMerchant($merchant);

            if ($redemption->offer->merchant_hub_merchant_id !== $merchantHubMerchant->id) {
                return response()->json([
                    'error' => 'This redemption is not for your merchant.',
                ], 403);
            }
        }

        // Get eligible items for this merchant
        $eligibleItems = $redemption->offer->merchant->eligibleItems()
            ->where('is_active', true)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'price' => $item->price,
                    'discount_cap' => $item->discount_cap,
                    'has_reached_limit' => $item->hasReachedLimit(),
                ];
            });

        return response()->json([
            'success' => true,
            'redemption' => [
                'id' => $redemption->id,
                'code' => $redemption->receipt_code,
                'user_name' => $redemption->user->name,
                'user_email' => $redemption->user->email,
                'points_spent' => $redemption->points_spent,
                'cash_spent' => $redemption->cash_spent,
                'status' => $redemption->status,
                'discount_percentage' => $redemption->offer->discount_percentage ?? 10,
                'discount_cap' => $redemption->offer->discount_cap,
                'eligible_items' => $eligibleItems,
                'redeemed_at' => $redemption->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Verify redemption from QR code JSON data
     */
    public function verifyFromQr(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:redemption',
            'code' => 'required|string',
            'redemption_id' => 'nullable|integer|exists:merchant_hub_offer_redemptions,id',
        ]);

        // Require merchant authentication
        $merchant = Auth::guard('merchant')->user();
        if (! $merchant) {
            return response()->json([
                'error' => 'Merchant authentication required.',
            ], 401);
        }

        $merchantHubMerchant = $this->getOrCreateMerchantHubMerchant($merchant);

        $code = $request->code;
        $redemption = MerchantHubOfferRedemption::with(['offer.merchant', 'user', 'eligibleItem'])
            ->where('receipt_code', $code)
            ->first();

        if (! $redemption) {
            return response()->json([
                'error' => 'Redemption code not found.',
            ], 404);
        }

        // Verify merchant owns this redemption
        if ($redemption->offer->merchant_hub_merchant_id !== $merchantHubMerchant->id) {
            return response()->json([
                'error' => 'This redemption is not for your merchant. You can only scan QR codes for your own offers.',
                'redemption' => [
                    'code' => $redemption->receipt_code,
                    'offer' => [
                        'title' => $redemption->offer->title,
                        'merchant_name' => $redemption->offer->merchant->name ?? null,
                    ],
                ],
            ], 403);
        }

        // If redemption_id is provided, verify it matches
        if ($request->filled('redemption_id') && $redemption->id != $request->redemption_id) {
            return response()->json([
                'error' => 'Redemption ID mismatch.',
            ], 400);
        }

        // Calculate pricing breakdown (for both used and unused redemptions)
        $discountPercentage = $redemption->offer->discount_percentage ?? 10.0;
        $pricingBreakdownForUsed = null;

        if ($redemption->offer->cash_required && $redemption->offer->cash_required > 0) {
            $regularPrice = (float) $redemption->offer->cash_required;
            $discountAmount = ($regularPrice * $discountPercentage) / 100;
            $discountPrice = $regularPrice - $discountAmount;

            $pricingBreakdownForUsed = [
                'regularPrice' => round($regularPrice, 2),
                'discountPercentage' => round($discountPercentage, 2),
                'discountAmount' => round($discountAmount, 2),
                'discountPrice' => round($discountPrice, 2),
            ];
        }

        // Check if already used
        if ($redemption->isUsed()) {
            return response()->json([
                'error' => 'This redemption has already been used.',
                'redemption' => [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'user_name' => $redemption->user->name,
                    'user_email' => $redemption->user->email,
                    'points_spent' => $redemption->points_spent,
                    'cash_spent' => (float) $redemption->cash_spent,
                    'status' => $redemption->status,
                    'used_at' => $redemption->used_at?->toIso8601String(),
                    'discount_percentage' => $redemption->offer->discount_percentage ?? 10,
                    'discount_cap' => (float) $redemption->offer->discount_cap,
                    'pricingBreakdown' => $pricingBreakdownForUsed,
                    'offer' => [
                        'title' => $redemption->offer->title,
                        'merchant_name' => $redemption->offer->merchant->name,
                    ],
                ],
            ], 400);
        }

        // Check if expired (optional - e.g., 30 days)
        if ($redemption->created_at->addDays(30)->isPast()) {
            return response()->json([
                'error' => 'This redemption has expired. Redemptions are valid for 30 days.',
            ], 400);
        }

        // Get eligible items for this merchant
        $eligibleItems = $redemption->offer->merchant->eligibleItems()
            ->where('is_active', true)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'price' => (float) $item->price,
                    'discount_cap' => (float) $item->discount_cap,
                    'has_reached_limit' => $item->hasReachedLimit(),
                ];
            });

        // Calculate pricing breakdown if applicable (reuse the calculation from above)
        $pricingBreakdown = $pricingBreakdownForUsed;

        return response()->json([
            'success' => true,
            'redemption' => [
                'id' => $redemption->id,
                'code' => $redemption->receipt_code,
                'user_name' => $redemption->user->name,
                'user_email' => $redemption->user->email,
                'points_spent' => $redemption->points_spent,
                'cash_spent' => (float) $redemption->cash_spent,
                'status' => $redemption->status,
                'used_at' => $redemption->used_at?->toIso8601String(),
                'discount_percentage' => $redemption->offer->discount_percentage ?? 10,
                'discount_cap' => (float) $redemption->offer->discount_cap,
                'pricingBreakdown' => $pricingBreakdown,
                'eligible_items' => $eligibleItems,
                'redeemed_at' => $redemption->created_at->toIso8601String(),
                'offer' => [
                    'title' => $redemption->offer->title,
                    'merchant_name' => $redemption->offer->merchant->name,
                ],
            ],
        ]);
    }

    /**
     * Cancel redemption (for merchant)
     */
    public function cancelRedemption(Request $request, $code)
    {
        $merchant = Auth::guard('merchant')->user();
        if (! $merchant) {
            return response()->json([
                'error' => 'Merchant authentication required.',
            ], 401);
        }

        $merchantHubMerchant = $this->getOrCreateMerchantHubMerchant($merchant);

        $redemption = MerchantHubOfferRedemption::with(['offer', 'user'])
            ->where('receipt_code', $code)
            ->firstOrFail();

        // Verify merchant owns this redemption
        if ($redemption->offer->merchant_hub_merchant_id !== $merchantHubMerchant->id) {
            return response()->json([
                'error' => 'This redemption is not for your merchant.',
            ], 403);
        }

        // Check if already canceled
        if ($redemption->status === 'canceled') {
            return response()->json([
                'error' => 'This redemption has already been canceled.',
            ], 400);
        }

        // Check if already fulfilled (can't cancel fulfilled redemptions)
        if ($redemption->status === 'fulfilled') {
            return response()->json([
                'error' => 'Cannot cancel a fulfilled redemption.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $redemption->status;
            $pointsToRefund = $redemption->points_spent;

            // Update status to canceled
            $redemption->update(['status' => 'canceled']);

            // Refund points if they were spent
            if ($pointsToRefund > 0) {
                $user = $redemption->user;
                $user->increment('reward_points', $pointsToRefund);

                // Create credit ledger entry for refund
                \App\Models\RewardPointLedger::createCredit(
                    $user->id,
                    'merchant_hub_redemption_refund',
                    $redemption->id,
                    $pointsToRefund,
                    "Refund for canceled redemption: {$redemption->offer->title}",
                    [
                        'redemption_id' => $redemption->id,
                        'offer_id' => $redemption->merchant_hub_offer_id,
                        'offer_title' => $redemption->offer->title,
                        'merchant_name' => $redemption->offer->merchant->name,
                        'receipt_code' => $redemption->receipt_code,
                        'original_points_spent' => $pointsToRefund,
                    ]
                );

                Log::info('Redemption canceled by merchant and points refunded', [
                    'redemption_id' => $redemption->id,
                    'user_id' => $user->id,
                    'merchant_id' => $merchant->id,
                    'points_refunded' => $pointsToRefund,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Redemption canceled successfully. Points have been refunded to the user.',
                'redemption' => [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'status' => $redemption->status,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel redemption: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to cancel redemption. Please try again.',
            ], 500);
        }
    }

    /**
     * Mark redemption as used (for merchant after applying discount)
     */
    public function markAsUsed(Request $request, $code)
    {
        $request->validate([
            'eligible_item_id' => 'nullable|exists:merchant_hub_eligible_items,id',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $merchant = Auth::guard('merchant')->user();
        if (! $merchant) {
            return response()->json([
                'error' => 'Merchant authentication required.',
            ], 401);
        }

        $merchantHubMerchant = $this->getOrCreateMerchantHubMerchant($merchant);

        $redemption = MerchantHubOfferRedemption::with(['offer'])
            ->where('receipt_code', $code)
            ->firstOrFail();

        // Verify merchant matches
        if ($redemption->offer->merchant_hub_merchant_id !== $merchantHubMerchant->id) {
            return response()->json([
                'error' => 'This redemption is not for your merchant.',
            ], 403);
        }

        // Check if already used
        if ($redemption->isUsed()) {
            return response()->json([
                'error' => 'This redemption has already been used.',
            ], 400);
        }

        // Check if eligible item is valid (if provided)
        if ($request->filled('eligible_item_id')) {
            $eligibleItem = $merchantHubMerchant->eligibleItems()
                ->where('id', $request->eligible_item_id)
                ->where('is_active', true)
                ->first();

            if (! $eligibleItem) {
                return response()->json([
                    'error' => 'Invalid eligible item selected.',
                ], 400);
            }

            // Check quantity limit
            if ($eligibleItem->hasReachedLimit()) {
                return response()->json([
                    'error' => 'This item has reached its redemption limit.',
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            // Mark as used
            $redemption->update([
                'status' => 'fulfilled',
                'used_at' => now(),
                'verified_by_merchant_id' => $merchant->id,
                'eligible_item_id' => $request->eligible_item_id,
                'discount_amount' => $request->discount_amount,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Redemption marked as used successfully.',
                'redemption' => [
                    'id' => $redemption->id,
                    'code' => $redemption->receipt_code,
                    'used_at' => $redemption->used_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark redemption as used: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to mark redemption as used. Please try again.',
            ], 500);
        }
    }

    /**
     * Get or create MerchantHubMerchant for the authenticated merchant.
     */
    private function getOrCreateMerchantHubMerchant($merchant)
    {
        $name = $merchant->business_name ?? $merchant->name;
        $slug = \Illuminate\Support\Str::slug($name);

        // Try to find by name or slug
        $merchantHubMerchant = \App\Models\MerchantHubMerchant::where('name', $name)
            ->orWhere('slug', $slug)
            ->first();

        if (! $merchantHubMerchant) {
            // Ensure slug is unique
            $originalSlug = $slug;
            $counter = 1;
            while (\App\Models\MerchantHubMerchant::where('slug', $slug)->exists()) {
                $slug = $originalSlug.'-'.$counter;
                $counter++;
            }

            // Create new MerchantHubMerchant
            $merchantHubMerchant = \App\Models\MerchantHubMerchant::create([
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
            ]);
        }

        return $merchantHubMerchant;
    }
}
