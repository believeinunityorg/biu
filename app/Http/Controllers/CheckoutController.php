<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\MarketplaceProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShippingInfo;
use App\Models\OrderSplit;
use App\Models\ShippoShipment;
use App\Models\TempOrder;
use App\Models\Transaction;
use App\Services\BiuPlatformFeeService;
use App\Services\MarketplaceOrderLedgerService;
use App\Services\MarketplaceOrganizationMarkupService;
use App\Services\MarketplacePoolRevenueSplit;
use App\Services\PrintifyService;
use App\Services\ShippoService;
use App\Services\StripeConfigService;
use App\Services\StripeProcessingFeeEstimator;
use App\Services\DigitalDeliveryService;
use App\Services\SupporterActivityService;
use App\Support\DigitalProductDelivery;
use App\Support\MarketplacePickup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class CheckoutController extends Controller
{
    protected $printifyService;

    protected $shippoService;

    public function __construct(PrintifyService $printifyService, ShippoService $shippoService)
    {
        $this->printifyService = $printifyService;
        $this->shippoService = $shippoService;
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Show checkout page - Step 1
     */
    public function show(): Response
    {
        $user = auth()->user();
        $cart = $user->cart()->with([
            'items.product.organization',
            'items.variant',
            'items.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.organization',
        ])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return Inertia::render('Checkout/Empty');
        }

        $cart->loadMissing([
            'items.product.organization',
            'items.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.organization',
        ]);

        $subtotal = $cart->getTotal();
        $feeDetail = BiuPlatformFeeService::marketplaceCartBuyerPlatformFeeDetail($cart);
        $platformFee = (float) $feeDetail['fee_usd'];
        $platformFeePct = (float) $feeDetail['effective_percent'];
        $markupBasis = MarketplaceOrganizationMarkupService::basisFromCart($cart, $this->printifyService);

        return Inertia::render('Checkout/index', [
            'items' => $cart->items->map(function ($item) {
                if ($item->marketplace_product_id) {
                    $mp = $item->marketplaceProduct;

                    return [
                        'id' => $item->id,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'variant_image' => $item->variant_image,
                        'product' => [
                            'name' => $mp?->name ?? 'Product',
                        ],
                        'variant_data' => [
                            'printify_variant_id' => null,
                            'printify_blueprint_id' => null,
                            'printify_print_provider_id' => null,
                            'variant_options' => null,
                        ],
                        'listing_type' => 'merchant_marketplace',
                        'sold_by_organization' => null,
                    ];
                }

                if ($item->organization_product_id) {
                    $mp = $item->organizationProduct?->marketplaceProduct;

                    return [
                        'id' => $item->id,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'variant_image' => $item->variant_image,
                        'product' => [
                            'name' => $mp?->name ?? 'Product',
                        ],
                        'variant_data' => [
                            'printify_variant_id' => null,
                            'printify_blueprint_id' => null,
                            'printify_print_provider_id' => null,
                            'variant_options' => null,
                        ],
                        'listing_type' => 'merchant_pool',
                        'sold_by_organization' => $item->organizationProduct?->organization?->name,
                    ];
                }

                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'variant_image' => $item->variant_image,
                    'product' => [
                        'name' => $item->product->name,
                    ],
                    'variant_data' => [
                        'printify_variant_id' => $item->printify_variant_id,
                        'printify_blueprint_id' => $item->printify_blueprint_id,
                        'printify_print_provider_id' => $item->printify_print_provider_id,
                        'variant_options' => $item->variant_options,
                    ],
                    'listing_type' => 'catalog',
                ];
            }),
            'subtotal' => (float) $subtotal,
            'platform_fee_percentage' => $platformFeePct,
            'platform_fee' => $platformFee,
            'platform_fee_lines' => $feeDetail['lines'],
            // 'donation_percentage' => config('printify.optional_donation_percentage', 10), // Commented out - removed donation for Printify products
            'donation_percentage' => 0, // Set to 0 to disable donation
            // Prefer Stripe publishable key from database (payment_methods), fall back to .env
            'stripePublishableKey' => StripeConfigService::getPublishableKey() ?? config('services.stripe.key') ?? '',
            // Stripe processing fee is always passed through to the buyer on card checkout.
            'customerPaysProcessingFee' => true,
            'pickup_available_at_checkout' => MarketplacePickup::cartAllowsUnifiedPickup($cart),
            'digital_only_checkout' => DigitalProductDelivery::cartIsDigitalOnly($cart),
        ]);
    }

    /**
     * Step 1: Submit shipping info and create Printify order
     */
    public function submitStep1(Request $request): JsonResponse
    {
        $user = auth()->user();
        $cart = $user->cart()->with([
            'items.product.organization',
            'items.variant',
            'items.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.organization',
        ])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['error' => 'Cart is empty'], 400);
        }

        $cart->loadMissing([
            'items.product.organization',
            'items.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.marketplaceProduct.merchant.shippingAddresses',
            'items.organizationProduct.organization',
        ]);

        $digitalOnlyCheckout = DigitalProductDelivery::cartIsDigitalOnly($cart);

        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string|max:20',
            'address' => $digitalOnlyCheckout ? 'nullable|string' : 'required|string',
            'city' => $digitalOnlyCheckout ? 'nullable|string' : 'required|string',
            'state' => 'nullable|string',
            'zip' => $digitalOnlyCheckout ? 'nullable|string' : 'required|string',
            'country' => $digitalOnlyCheckout ? 'nullable|string' : 'required|string',
            'donation_amount' => 'nullable|numeric',
        ]);

        if ($digitalOnlyCheckout) {
            $validated['address'] = $validated['address'] ?? '';
            $validated['city'] = $validated['city'] ?? '';
            $validated['state'] = $validated['state'] ?? '';
            $validated['zip'] = $validated['zip'] ?? '';
            $validated['country'] = $validated['country'] ?? 'US';
        }

        DB::beginTransaction();
        try {
            $subtotal = $cart->getTotal();
            $markupBasis = MarketplaceOrganizationMarkupService::basisFromCart($cart, $this->printifyService);
            $platformFee = BiuPlatformFeeService::marketplaceCartBuyerPlatformFeeUsd($cart);

            // Split full name
            $nameParts = explode(' ', $validated['name']);
            $firstName = $nameParts[0] ?? 'Customer';
            $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

            // Create temp order
            // platform_fee: buyer-facing platform fee charged on the order subtotal.
            $tempOrder = TempOrder::create([
                'user_id' => $user->id,
                'cart_id' => $cart->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'shipping_address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip' => $validated['zip'],
                'country' => $validated['country'],
                'subtotal' => $subtotal,
                'platform_fee' => $platformFee,
                'organization_markup_basis' => $markupBasis,
                // 'donation_amount' => $validated['donation_amount'], // Commented out - removed donation for Printify products
                'donation_amount' => 0, // Set to 0 - donation removed for Printify products
                'total_amount' => $subtotal + $platformFee,
                'status' => 'pending',
            ]);

            // Check if cart has Printify products (catalog products only)
            $hasPrintifyProducts = $cart->items->contains(function ($item) {
                return $item->product_id
                    && $item->product
                    && ! empty($item->product->printify_product_id);
            });

            $printifyOrderId = null;
            $shippingData = [];
            $taxAmount = 0;
            $printifyTaxPortion = 0.0;
            $additionalSalesTaxAdjustment = 0.0;
            $shippoRateObjectId = null;
            $shippoCarrier = null;
            $shippoRateAmount = null;
            $shippoShipmentId = null;

            if ($hasPrintifyProducts) {
                // Create Printify order only if there are Printify products
                $printifyOrderData = $this->preparePrintifyOrder($cart, $tempOrder);

                // Only create order if there are Printify line items
                if (! empty($printifyOrderData['line_items'])) {
                    $printifyOrder = $this->printifyService->createOrder($printifyOrderData);

                    if (! isset($printifyOrder['id'])) {
                        throw new \Exception('Failed to create Printify order');
                    }

                    $printifyOrderId = $printifyOrder['id'];
                    $tempOrder->update([
                        'printify_order_id' => $printifyOrderId,
                    ]);

                    // Calculate shipping from Printify
                    $shippingData = $this->calculateShippingFromPrintify(
                        $printifyOrderId,
                        $validated['country'],
                        $validated['state'],
                        $validated['city'],
                        $validated['zip']
                    );

                    if (isset($shippingData['total_tax'])) {
                        $rawPrintifyTax = ($shippingData['total_tax'] ?? 0) / 100;
                        $split = $this->sumPrintifyRetailAndProductionCost($cart);
                        $adj = $this->adjustPrintifySalesTaxForMarkup($rawPrintifyTax, $split['retail'], $split['cost']);
                        $printifyTaxPortion = $adj['printify_tax'];
                        $additionalSalesTaxAdjustment = $adj['additional_sales_tax_adjustment'];
                        $taxAmount += $adj['total_tax'];
                    }
                } else {
                    // Cart has Printify products but no valid line items (fallback to manual shipping)
                    $defaultShippingCost = config('app.manual_product_shipping_cost', 9.99);
                    $shippingData = [
                        'cost' => $defaultShippingCost,
                        'methods' => [
                            [
                                'id' => 'standard',
                                'name' => 'Standard Shipping',
                                'cost' => $defaultShippingCost,
                                'estimated_days' => '5-10 business days',
                            ],
                        ],
                    ];
                }
            }

            $nonPrintifyShipping = 0.0;
            $nonPrintifyShippingMethods = [];
            if ($this->cartHasNonPrintifyShippableItems($cart)) {
                // Manual org catalog, direct merchant marketplace, or pool listing: Shippo from seller ship-from
                $firstCartItem = $cart->items->first(function ($item) {
                    return $this->isNonPrintifyCartItem($item);
                }) ?? $cart->items->first();
                $firstCartItem?->loadMissing([
                    'marketplaceProduct.merchant.shippingAddresses',
                    'organizationProduct.marketplaceProduct.merchant.shippingAddresses',
                ]);
                $mp = $firstCartItem?->marketplaceProduct ?? $firstCartItem?->organizationProduct?->marketplaceProduct;
                $poolOp = $firstCartItem?->organizationProduct;
                $merchant = $mp?->merchant;
                $manualProduct = $firstCartItem?->product;

                $shippingCost = (float) config('app.manual_product_shipping_cost', 9.99);
                $shippingMethods = [
                    [
                        'id' => 'standard',
                        'name' => 'Standard Shipping',
                        'cost' => $shippingCost,
                        'estimated_days' => '5-10 business days',
                    ],
                ];

                $canQuoteShippo = $this->shippoService->isConfigured()
                    && ! empty($validated['country'])
                    && ! empty($validated['zip']);

                $shipTo = [
                    'name' => trim(($firstName ?? 'Customer').' '.($lastName ?? '')),
                    'street1' => $validated['address'],
                    'city' => $validated['city'],
                    'state' => $validated['state'],
                    'zip' => $validated['zip'],
                    'country' => $this->shippoService->normalizeCountryToIso2((string) ($validated['country'] ?? 'US')),
                    'phone' => $this->shippoService->ensureRecipientPhoneForShippo($validated['phone'] ?? null),
                    'email' => $validated['email'],
                ];

                $defaultParcel = [
                    'length' => '10',
                    'width' => '8',
                    'height' => '4',
                    'distance_unit' => 'in',
                    'weight' => '16',
                    'mass_unit' => 'oz',
                ];

                if ($mp && $merchant && $this->marketplaceProductNeedsShippoRates($mp) && $canQuoteShippo) {
                    $shipFrom = $this->shippoService->shipFromPayloadForMerchant($merchant);
                    try {
                        $ratesResult = $this->shippoService->getRatesForAddresses($shipFrom, $shipTo, $defaultParcel);
                        if (! empty($ratesResult['success']) && ! empty($ratesResult['rates'])) {
                            $shippingMethods = $this->shippoService->ratesToCheckoutMethods($ratesResult['rates']);
                            if (! empty($shippingMethods)) {
                                $first = $shippingMethods[0];
                                $shippingCost = (float) $first['cost'];
                                $shippoRateObjectId = (string) $first['id'];
                                $shippoCarrier = $first['provider'] ?? null;
                                $shippoRateAmount = $shippingCost;
                                $shippoShipmentId = $ratesResult['shipment_id'] ?? null;
                            }
                        }
                    } catch (\Exception $e) {
                        $shippingCost = (float) config('app.manual_product_shipping_cost', 9.99);
                        $shippingMethods = [
                            [
                                'id' => 'standard',
                                'name' => 'Standard Shipping',
                                'cost' => $shippingCost,
                                'estimated_days' => '5-10 business days',
                            ],
                        ];
                        \Log::warning('Shippo rates failed in checkout step1 (merchant pool)', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                } elseif ($manualProduct && DigitalProductDelivery::productIsDigital($manualProduct)) {
                    $shippingCost = 0;
                    $shippingMethods = [
                        [
                            'id' => DigitalProductDelivery::SHIPPING_RATE_ID,
                            'name' => 'Digital delivery',
                            'cost' => 0,
                            'estimated_days' => '—',
                        ],
                    ];
                } elseif ($manualProduct && $canQuoteShippo) {
                    $manualProduct->load(['organization.user', 'user', 'shipFromMerchant.shippingAddresses']);
                    $org = $manualProduct->organization;

                    $shipFrom = $this->shippoService->shipFromForManualProduct($manualProduct, $org);

                    $length = $manualProduct->parcel_length_in !== null ? (float) $manualProduct->parcel_length_in : 10.0;
                    $width = $manualProduct->parcel_width_in !== null ? (float) $manualProduct->parcel_width_in : 8.0;
                    $height = $manualProduct->parcel_height_in !== null ? (float) $manualProduct->parcel_height_in : 4.0;
                    $weight = $manualProduct->parcel_weight_oz !== null ? (float) $manualProduct->parcel_weight_oz : 16.0;
                    if ($weight < 0.1) {
                        $weight = 16.0;
                    }

                    $parcel = [
                        'length' => (string) $length,
                        'width' => (string) $width,
                        'height' => (string) $height,
                        'distance_unit' => 'in',
                        'weight' => (string) $weight,
                        'mass_unit' => 'oz',
                    ];

                    try {
                        $ratesResult = $this->shippoService->getRatesForAddresses($shipFrom, $shipTo, $parcel);
                        if (! empty($ratesResult['success']) && ! empty($ratesResult['rates'])) {
                            $shippingMethods = $this->shippoService->ratesToCheckoutMethods($ratesResult['rates']);
                            if (! empty($shippingMethods)) {
                                $first = $shippingMethods[0];
                                $shippingCost = (float) $first['cost'];
                                $shippoRateObjectId = (string) $first['id'];
                                $shippoCarrier = $first['provider'] ?? null;
                                $shippoRateAmount = $shippingCost;
                                $shippoShipmentId = $ratesResult['shipment_id'] ?? null;
                            }
                        }
                    } catch (\Exception $e) {
                        $shippingCost = (float) config('app.manual_product_shipping_cost', 9.99);
                        $shippingMethods = [
                            [
                                'id' => 'standard',
                                'name' => 'Standard Shipping',
                                'cost' => $shippingCost,
                                'estimated_days' => '5-10 business days',
                            ],
                        ];
                        \Log::warning('Shippo rates failed in checkout step1 (manual products)', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                } elseif ($mp && $this->marketplaceProductIsDigitalDeliveryOnly($mp)) {
                    $shippingCost = 0;
                    $shippingMethods = [
                        [
                            'id' => 'digital',
                            'name' => 'Digital delivery',
                            'cost' => 0,
                            'estimated_days' => '—',
                        ],
                    ];
                } else {
                    $shippingCost = (float) config('app.manual_product_shipping_cost', 9.99);
                    $shippingMethods = [
                        [
                            'id' => 'standard',
                            'name' => 'Standard Shipping',
                            'cost' => $shippingCost,
                            'estimated_days' => '5-10 business days',
                        ],
                    ];
                }

                if (MarketplacePickup::cartAllowsUnifiedPickup($cart)) {
                    $pickupLines = MarketplacePickup::pickupAddressLinesForCart($cart);
                    array_unshift($shippingMethods, [
                        'id' => MarketplacePickup::RATE_ID,
                        'name' => 'Pick up at seller location',
                        'cost' => 0.0,
                        'estimated_days' => '—',
                        'pickup_address' => $pickupLines,
                    ]);
                    $shippingCost = 0.0;
                    $shippoRateObjectId = null;
                    $shippoCarrier = null;
                    $shippoRateAmount = null;
                    $shippoShipmentId = null;
                }

                $nonPrintifyShipping = $shippingCost;
                $nonPrintifyShippingMethods = $shippingMethods;
            } elseif (DigitalProductDelivery::cartHasDigitalItems($cart) && ! $this->cartHasNonPrintifyShippableItems($cart)) {
                $nonPrintifyShipping = 0;
                $nonPrintifyShippingMethods = [
                    [
                        'id' => DigitalProductDelivery::SHIPPING_RATE_ID,
                        'name' => 'Digital delivery',
                        'cost' => 0,
                        'estimated_days' => '—',
                    ],
                ];
            }

            $shippingData = $this->mergeShippingData($shippingData, $nonPrintifyShipping, $nonPrintifyShippingMethods);
            $taxAmount += $this->calculateStateSalesTaxForNonPrintifySubtotal($cart, (string) ($validated['state'] ?? ''));

            // Update temp order with shipping and tax
            $tempOrder->update([
                'shipping_methods' => $shippingData['methods'] ?? [],
                'shipping_cost' => $shippingData['cost'] ?? 0,
                'tax_amount' => $taxAmount,
                'printify_tax_amount' => $printifyTaxPortion,
                'additional_sales_tax_adjustment' => $additionalSalesTaxAdjustment,
                'total_amount' => $subtotal + $platformFee + ($shippingData['cost'] ?? 0) + $taxAmount,
                'shippo_rate_object_id' => $shippoRateObjectId,
                'shippo_carrier' => $shippoCarrier,
                'shippo_rate_amount' => $shippoRateAmount,
                'shippo_shipment_id' => $shippoShipmentId,
                'status' => 'shipping_calculated',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'temp_order_id' => $tempOrder->id,
                'shipping_methods' => $shippingData['methods'] ?? [],
                'shipping_cost' => (float) ($shippingData['cost'] ?? 0),
                'tax_amount' => (float) $taxAmount,
                'printify_tax_amount' => (float) $printifyTaxPortion,
                'additional_sales_tax_adjustment' => (float) $additionalSalesTaxAdjustment,
                'total_amount' => (float) $tempOrder->total_amount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Step 1 submission error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Step 2: Create payment intent
     */
    // public function createPaymentIntent(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'temp_order_id' => 'required|exists:temp_orders,id',
    //         'shipping_method' => 'required|string',
    //     ]);

    //     $user = auth()->user();
    //     $tempOrder = TempOrder::where('user_id', $user->id)
    //         ->findOrFail($validated['temp_order_id']);

    //     $printifyOrder = $this->printifyService->getOrder($tempOrder->printify_order_id);

    //     dd($printifyOrder);

    //     $newTaxAmount = $printifyOrder['total_tax'];

    //     $tempOrder->update([
    //             'tax_amount' => $newTaxAmount,
    //             'total_amount' => $tempOrder->total_amount + $newTaxAmount,
    //         ]);

    //     DB::beginTransaction();
    //     try {
    //         // Create Stripe payment intent
    //         $paymentIntent = PaymentIntent::create([
    //             'amount' => (int) ($tempOrder->total_amount * 100),
    //             'currency' => 'usd',
    //             'description' => 'Marketplace Order - ' . $user->email,
    //             'metadata' => [
    //                 'user_id' => $user->id,
    //                 'temp_order_id' => $tempOrder->id,
    //                 'order_type' => 'marketplace',
    //             ],
    //             'automatic_payment_methods' => [
    //                 'enabled' => true,
    //             ],
    //         ]);

    //         // Update temp order with selected shipping method
    //         $tempOrder->update([
    //             'selected_shipping_method' => $validated['shipping_method'],
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'clientSecret' => $paymentIntent->client_secret,
    //             'temp_order_id' => $tempOrder->id,
    //             'amount' => (float) $tempOrder->total_amount,
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error('Payment intent creation error: ' . $e->getMessage());
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

    /**
     * Step 2: Create payment intent
     */
    // public function createPaymentIntent(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'temp_order_id' => 'required|exists:temp_orders,id',
    //         'shipping_method' => 'required|string',
    //     ]);

    //     $user = auth()->user();
    //     $tempOrder = TempOrder::where('user_id', $user->id)
    //         ->findOrFail($validated['temp_order_id']);

    //     // Always check Printify for latest tax before payment
    //     try {
    //         $printifyOrder = $this->printifyService->getOrder($tempOrder->printify_order_id);

    //         // Convert cents to dollars
    //         $newTaxAmount = (float) (($printifyOrder['total_tax'] ?? 0) / 100);
    //         $newShippingCost = (float) (($printifyOrder['total_shipping'] ?? 0) / 100);

    //         // Calculate new total
    //         $newTotalAmount = $tempOrder->subtotal +
    //             $tempOrder->platform_fee +
    //             $tempOrder->donation_amount +
    //             $newShippingCost +
    //             $newTaxAmount;

    //         // Update temp order with latest values
    //         $tempOrder->update([
    //             'tax_amount' => $newTaxAmount,
    //             'shipping_cost' => $newShippingCost,
    //             'total_amount' => $newTotalAmount,
    //             'selected_shipping_method' => $validated['shipping_method'],
    //         ]);

    //     } catch (\Exception $e) {
    //         \Log::error('Failed to get Printify tax update: ' . $e->getMessage());
    //         // Continue with existing values if Printify fails
    //     }

    //     DB::beginTransaction();
    //     try {
    //         // Create Stripe payment intent with UPDATED amount
    //         $paymentIntent = PaymentIntent::create([
    //             'amount' => (int) ($tempOrder->total_amount * 100),
    //             'currency' => 'usd',
    //             'description' => 'Marketplace Order - ' . $user->email,
    //             'metadata' => [
    //                 'user_id' => $user->id,
    //                 'temp_order_id' => $tempOrder->id,
    //                 'order_type' => 'marketplace',
    //             ],
    //             'automatic_payment_methods' => [
    //                 'enabled' => true,
    //             ],
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'clientSecret' => $paymentIntent->client_secret,
    //             'temp_order_id' => $tempOrder->id,
    //             'amount' => (float) $tempOrder->total_amount,
    //             'tax_amount' => (float) $tempOrder->tax_amount,
    //             'shipping_cost' => (float) $tempOrder->shipping_cost,
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error('Payment intent creation error: ' . $e->getMessage());
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

    public function createPaymentIntent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temp_order_id' => 'required|exists:temp_orders,id',
            'shipping_method' => 'required|string',
        ]);

        $user = auth()->user();
        $tempOrder = TempOrder::where('user_id', $user->id)
            ->findOrFail($validated['temp_order_id']);

        $maxRetries = 5;
        $retryDelay = 2.5; // seconds
        $newTaxAmount = 0;
        $newShippingCost = 0;
        $printifyTaxPortion = 0.0;
        $additionalSalesTaxAdjustment = 0.0;
        $printifyStatus = null;
        $shippoPatch = [];

        $tempOrder->loadMissing([
            'cart.items.product',
            'cart.items.marketplaceProduct',
            'cart.items.organizationProduct.marketplaceProduct',
        ]);

        $methods = $tempOrder->shipping_methods ?? [];
        $selectedId = (string) $validated['shipping_method'];
        $picked = collect($methods)->first(function ($m) use ($selectedId) {
            return isset($m['id']) && (string) $m['id'] === $selectedId;
        });

        if ($picked === null) {
            return response()->json([
                'error' => 'Invalid shipping method. Go back to shipping, refresh checkout, and try again.',
            ], 422);
        }

        // Check if this is a Printify order or manual product
        if ($tempOrder->printify_order_id) {
            // Try to get Printify order with retry mechanism
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $printifyOrder = $this->printifyService->getOrder($tempOrder->printify_order_id);
                    $printifyStatus = $printifyOrder['status'] ?? null;

                    \Log::info('Printify order check attempt', [
                        'attempt' => $attempt,
                        'order_id' => $tempOrder->printify_order_id,
                        'status' => $printifyStatus,
                        'total_tax' => $printifyOrder['total_tax'] ?? 0,
                        'total_shipping' => $printifyOrder['total_shipping'] ?? 0,
                    ]);

                    $printifyShippingCost = (float) (($printifyOrder['total_shipping'] ?? 0) / 100);
                    $nonPrintifyShippingCost = round((float) ($picked['non_printify_cost'] ?? 0), 2);
                    $newShippingCost = round($printifyShippingCost + $nonPrintifyShippingCost, 2);

                    $shippoPatch = [
                        'shippo_rate_object_id' => MarketplacePickup::isShippoPurchasableRateId($selectedId) ? $selectedId : null,
                        'shippo_rate_amount' => $nonPrintifyShippingCost > 0 ? $nonPrintifyShippingCost : null,
                        'shippo_carrier' => $picked['provider'] ?? null,
                    ];

                    $rawPrintifyTax = (float) (($printifyOrder['total_tax'] ?? 0) / 100);
                    $split = $this->sumPrintifyRetailAndProductionCost($tempOrder->cart);
                    $adj = $this->adjustPrintifySalesTaxForMarkup($rawPrintifyTax, $split['retail'], $split['cost']);
                    $printifyTaxPortion = $adj['printify_tax'];
                    $additionalSalesTaxAdjustment = $adj['additional_sales_tax_adjustment'];
                    $stateTaxPortion = $this->calculateStateSalesTaxForNonPrintifySubtotal($tempOrder->cart, (string) ($tempOrder->state ?? ''));
                    $newTaxAmount = round($adj['total_tax'] + $stateTaxPortion, 2);

                    $isTaxCalculated = $newTaxAmount > 0;

                    if ($isTaxCalculated || $attempt === $maxRetries) {
                        break;
                    }

                    // Wait before retrying
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                    }

                } catch (\Exception $e) {
                    \Log::error('Failed to get Printify order on attempt '.$attempt.': '.$e->getMessage());

                    // If this is the last attempt, use fallback
                    if ($attempt === $maxRetries) {
                        $newShippingCost = (float) ($tempOrder->shipping_cost ?? 0);
                        $stateTaxPortion = $this->calculateStateSalesTaxForNonPrintifySubtotal($tempOrder->cart, (string) ($tempOrder->state ?? ''));
                        $printifyTaxPortion = $this->estimatePrintifyTaxFallback($tempOrder);
                        $additionalSalesTaxAdjustment = 0.0;
                        $newTaxAmount = round($printifyTaxPortion + $stateTaxPortion, 2);
                        \Log::warning('Using estimated tax after all retries failed');
                    }
                }
            }
        } else {
            // Manual products: tax from step 1; shipping from the method the buyer selected (Shippo rate id or flat "standard")
            $newTaxAmount = (float) ($tempOrder->tax_amount ?? 0);
            $printifyTaxPortion = (float) ($tempOrder->printify_tax_amount ?? 0);
            $additionalSalesTaxAdjustment = (float) ($tempOrder->additional_sales_tax_adjustment ?? 0);
            $newShippingCost = round((float) ($picked['cost'] ?? 0), 2);

            if (MarketplacePickup::isShippoPurchasableRateId($selectedId)) {
                $shippoPatch = [
                    'shippo_rate_object_id' => $selectedId,
                    'shippo_rate_amount' => $newShippingCost,
                    'shippo_carrier' => $picked['provider'] ?? null,
                ];
            } else {
                $shippoPatch = [
                    'shippo_rate_object_id' => null,
                    'shippo_rate_amount' => null,
                    'shippo_carrier' => null,
                ];
            }
        }

        $platformFee = (float) ($tempOrder->platform_fee ?? 0);

        // Calculate new total with the buyer-facing platform fee included.
        $newTotalAmount = $tempOrder->subtotal +
            $platformFee +
            $newShippingCost +
            $newTaxAmount;

        // Always pass-through Stripe processing fees for card payments when using Stripe.
        $passThrough = StripeProcessingFeeEstimator::applyPassThrough($newTotalAmount, 'card');
        $amountToChargeUsd = $passThrough['gross_usd'];
        $processingFeeAddon = $passThrough['fee_addon_usd'];

        // Log the calculated amounts
        Log::info('Final amounts for payment intent', [
            'temp_order_id' => $tempOrder->id,
            'old_tax' => $tempOrder->tax_amount,
            'new_tax' => $newTaxAmount,
            'old_shipping' => $tempOrder->shipping_cost,
            'new_shipping' => $newShippingCost,
            'old_total' => $tempOrder->total_amount,
            'new_total' => $newTotalAmount,
            'stripe_processing_fee_addon' => $processingFeeAddon,
            'amount_to_charge_usd' => $amountToChargeUsd,
            'printify_status' => $printifyStatus,
        ]);

        // Update temp order with latest values (manual: merge Shippo rate id for label purchase)
        // total_amount = basket (subtotal+platform fee+shipping+tax); Stripe may charge more when customer pays processing fee.
        $tempOrder->update(array_merge([
            'tax_amount' => $newTaxAmount,
            'printify_tax_amount' => $printifyTaxPortion,
            'additional_sales_tax_adjustment' => $additionalSalesTaxAdjustment,
            'shipping_cost' => $newShippingCost,
            'total_amount' => $newTotalAmount,
            'stripe_processing_fee_addon' => $processingFeeAddon > 0 ? $processingFeeAddon : null,
            'selected_shipping_method' => $validated['shipping_method'],
            'printify_status' => $printifyStatus,
        ], $shippoPatch));

        DB::beginTransaction();
        try {
            // Create Stripe payment intent with UPDATED amount
            $paymentIntent = PaymentIntent::create([
                'amount' => (int) round($amountToChargeUsd * 100),
                'currency' => 'usd',
                'description' => 'Marketplace Order - '.$user->email,
                'metadata' => array_filter([
                    'user_id' => (string) $user->id,
                    'temp_order_id' => (string) $tempOrder->id,
                    'order_type' => 'marketplace',
                    'printify_status' => $printifyStatus !== null ? (string) $printifyStatus : null,
                    'tax_calculated' => $newTaxAmount > 0 ? 'yes' : 'no',
                ]),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'clientSecret' => $paymentIntent->client_secret,
                'temp_order_id' => $tempOrder->id,
                'amount' => (float) $amountToChargeUsd,
                'base_amount' => (float) $newTotalAmount,
                'stripe_processing_fee_addon' => (float) $processingFeeAddon,
                'customer_pays_processing_fee' => true,
                'tax_amount' => (float) $tempOrder->tax_amount,
                'printify_tax_amount' => (float) $tempOrder->printify_tax_amount,
                'additional_sales_tax_adjustment' => (float) $tempOrder->additional_sales_tax_adjustment,
                'shipping_cost' => (float) $tempOrder->shipping_cost,
                'donation_amount' => (float) $tempOrder->donation_amount,
                'printify_status' => $printifyStatus,
                'tax_estimated' => $newTaxAmount <= 0, // Flag if tax was estimated
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment intent creation error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm payment and create final order
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temp_order_id' => 'required|exists:temp_orders,id',
            'payment_intent_id' => 'nullable|string',
            'payment_method' => 'nullable|string|in:stripe,believe_points',
        ]);

        $user = auth()->user();
        $tempOrder = TempOrder::where('user_id', $user->id)
            ->with([
                'cart.items.product',
                'cart.items.marketplaceProduct',
                'cart.items.organizationProduct.marketplaceProduct',
                'cart.items.organizationProduct.organization',
            ])
            ->findOrFail($validated['temp_order_id']);

        $paymentMethod = $validated['payment_method'] ?? 'stripe';
        $paymentIntent = null;

        DB::beginTransaction();
        try {
            // Handle Believe Points payment
            if ($paymentMethod === 'believe_points') {
                $pointsRequired = $tempOrder->total_amount; // 1$ = 1 believe point
                $user->refresh(); // Get latest balance

                if ($user->purchasedBelievePointsBalance() < $pointsRequired) {
                    DB::rollBack();

                    $have = $user->purchasedBelievePointsBalance();

                    return response()->json([
                        'error' => "Insufficient purchased Believe Points. You need {$pointsRequired} points but only have {$have} (Gift BP can only be used for gift cards).",
                    ], 400);
                }

                // Deduct points
                if (! $user->deductBelievePoints($pointsRequired)) {
                    DB::rollBack();

                    return response()->json([
                        'error' => 'Failed to deduct Believe Points. Please try again.',
                    ], 500);
                }
            } else {
                // Validate Stripe payment
                if (! isset($validated['payment_intent_id'])) {
                    return response()->json(['error' => 'Payment intent ID is required for Stripe payments'], 400);
                }

                $paymentIntent = PaymentIntent::retrieve($validated['payment_intent_id'], [
                    'expand' => ['latest_charge.balance_transaction'],
                ]);

                if ($paymentIntent->status !== 'succeeded') {
                    return response()->json(['error' => 'Payment not completed'], 400);
                }
            }

            $stripeFeeUsd = 0.0;
            if ($paymentMethod === 'stripe' && $paymentIntent !== null) {
                $charge = $paymentIntent->latest_charge ?? null;
                $bt = is_object($charge) ? ($charge->balance_transaction ?? null) : null;
                if (is_object($bt) && isset($bt->fee)) {
                    $stripeFeeUsd = (float) $bt->fee / 100;
                }
            }

            // COMMENTED OUT: Donation calculation removed for Printify products
            // Calculate total donation amount from temp order
            // $totalDonationAmount = $tempOrder->donation_amount;

            // Calculate total subtotal for donation distribution
            // $totalSubtotal = $tempOrder->cart->items->sum(function ($item) {
            //     return $item->unit_price * $item->quantity;
            // });

            // Group cart items by organization to calculate donation distribution
            // $organizationItems = [];
            // foreach ($tempOrder->cart->items as $cartItem) {
            //     $orgId = $cartItem->product->organization_id;
            //     if (!isset($organizationItems[$orgId])) {
            //         $organizationItems[$orgId] = [
            //             'items' => [],
            //             'subtotal' => 0
            //         ];
            //     }
            //     $organizationItems[$orgId]['items'][] = $cartItem;
            //     $organizationItems[$orgId]['subtotal'] += $cartItem->unit_price * $cartItem->quantity;
            // }

            // Calculate donation percentage for each organization based on their subtotal share
            // $organizationDonations = [];
            // foreach ($organizationItems as $orgId => $orgData) {
            //     // Avoid division by zero
            //     if ($totalSubtotal > 0) {
            //         $orgSubtotalPercentage = ($orgData['subtotal'] / $totalSubtotal) * 100;
            //         $orgDonationAmount = ($totalDonationAmount * $orgSubtotalPercentage) / 100;
            //     } else {
            //         $orgSubtotalPercentage = 0;
            //         $orgDonationAmount = 0;
            //     }

            //     $organizationDonations[$orgId] = [
            //         'donation_amount' => $orgDonationAmount,
            //         'subtotal_percentage' => $orgSubtotalPercentage,
            //         'item_count' => count($orgData['items']),
            //         'org_subtotal' => $orgData['subtotal'] // Add this for safety
            //     ];
            // }

            // Set empty organization donations array since donation is disabled
            $organizationDonations = [];

            $tempOrder->loadMissing([
                'cart.items.product',
                'cart.items.marketplaceProduct',
                'cart.items.organizationProduct.marketplaceProduct',
                'cart.items.organizationProduct.organization',
            ]);

            $markupBasis = MarketplaceOrganizationMarkupService::basisFromCart($tempOrder->cart, $this->printifyService);
            $platformFee = BiuPlatformFeeService::marketplaceCartBuyerPlatformFeeUsd($tempOrder->cart);
            $tempOrder->update([
                'platform_fee' => $platformFee,
                'organization_markup_basis' => $markupBasis,
            ]);

            $firstItem = $tempOrder->cart->items->first();
            $orderOrganizationId = null;
            if ($firstItem) {
                if ($firstItem->marketplace_product_id) {
                    $orderOrganizationId = null;
                } elseif ($firstItem->organization_product_id) {
                    $orderOrganizationId = $firstItem->organizationProduct?->organization_id;
                } else {
                    $orderOrganizationId = $firstItem->product?->organization_id;
                }
            }

            $chargedUsd = $paymentMethod === 'stripe' && $paymentIntent !== null
                ? round((float) $paymentIntent->amount / 100, 2)
                : (float) $tempOrder->total_amount;
            $basketUsd = round(
                (float) $tempOrder->subtotal + (float) $platformFee + (float) $tempOrder->shipping_cost + (float) $tempOrder->tax_amount,
                2
            );
            $processingAddon = $paymentMethod === 'stripe'
                ? (float) ($tempOrder->stripe_processing_fee_addon ?? max(0.0, round($chargedUsd - $basketUsd, 2)))
                : 0.0;

            // Create final order
            $order = Order::create([
                'user_id' => $user->id,
                'organization_id' => $orderOrganizationId,
                'subtotal' => $tempOrder->subtotal,
                'platform_fee' => $platformFee,
                'organization_markup_basis' => $markupBasis,
                // 'donation_amount' => $tempOrder->donation_amount, // Commented out - removed donation for Printify products
                'donation_amount' => 0, // Set to 0 - donation removed for Printify products
                'tax_amount' => $tempOrder->tax_amount,
                'printify_tax_amount' => $tempOrder->printify_tax_amount ?? 0,
                'additional_sales_tax_adjustment' => $tempOrder->additional_sales_tax_adjustment ?? 0,
                'stripe_fee_amount' => $paymentMethod === 'stripe' ? round($stripeFeeUsd, 2) : null,
                'shipping_cost' => $tempOrder->shipping_cost,
                'stripe_processing_fee_addon' => $paymentMethod === 'stripe' && $processingAddon > 0 ? round($processingAddon, 2) : null,
                'total_amount' => $chargedUsd,
                'status' => 'processing',
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
                'stripe_payment_intent_id' => $paymentMethod === 'stripe' ? (isset($paymentIntent) ? $paymentIntent->id : null) : null,
                'printify_order_id' => $tempOrder->printify_order_id,
            ]);

            // Create shipping info
            OrderShippingInfo::create([
                'order_id' => $order->id,
                'first_name' => $tempOrder->first_name,
                'last_name' => $tempOrder->last_name,
                'email' => $tempOrder->email,
                'phone' => $tempOrder->phone,
                'shipping_address' => $tempOrder->shipping_address,
                'city' => $tempOrder->city,
                'state' => $tempOrder->state,
                'zip' => $tempOrder->zip,
                'country' => $tempOrder->country,
                'shipping_method' => $tempOrder->selected_shipping_method,
            ]);

            // Create order items from cart; pool lines use organization_products + revenue split
            $splitMerchantCents = 0;
            $splitOrgCents = 0;
            $splitBiuCents = 0;

            foreach ($tempOrder->cart->items as $cartItem) {
                $donationPerItem = 0;
                $lineSubtotal = (float) $cartItem->unit_price * (int) $cartItem->quantity;

                if ($cartItem->marketplace_product_id) {
                    $mp = $cartItem->marketplaceProduct;

                    if (! $mp) {
                        throw new \Exception('Invalid merchant product in cart.');
                    }

                    $lineCents = (int) round($lineSubtotal * 100);
                    $pctM = (float) ($mp->pct_merchant ?? 0);
                    $pctN = (float) ($mp->pct_nonprofit ?? 0);
                    $useNonprofitSplit = $mp->nonprofit_marketplace_enabled
                        && abs($pctM + $pctN) > 0.01;
                    if ($useNonprofitSplit) {
                        $allocated = MarketplacePoolRevenueSplit::allocateLineCents($lineCents, $pctM, $pctN);
                        $mCents = $allocated['merchant_cents'];
                        $nCents = $allocated['nonprofit_cents'];
                        $bCents = 0;
                    } else {
                        $mCents = $lineCents;
                        $nCents = 0;
                        $bCents = 0;
                    }

                    $splitMerchantCents += $mCents;
                    $splitOrgCents += $nCents;
                    $splitBiuCents += $bCents;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => null,
                        'organization_product_id' => null,
                        'marketplace_product_id' => $mp->id,
                        'organization_id' => null,
                        'printify_product_id' => null,
                        'printify_variant_id' => null,
                        'printify_blueprint_id' => null,
                        'printify_print_provider_id' => null,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'subtotal' => $lineSubtotal,
                        'per_organization_donation_amount' => $donationPerItem,
                        'variant_data' => $cartItem->variant_options,
                        'primary_image' => $cartItem->variant_image,
                        'product_details' => [
                            'marketplace_product_id' => $mp->id,
                            'merchant_id' => $mp->merchant_id,
                            'name' => $mp->name,
                        ],
                    ]);

                    if ($mp->inventory_quantity !== null) {
                        $mp->decrement('inventory_quantity', (int) $cartItem->quantity);
                    }
                } elseif ($cartItem->organization_product_id) {
                    $op = $cartItem->organizationProduct;
                    $mp = $op?->marketplaceProduct;

                    if (! $op || ! $mp) {
                        throw new \Exception('Invalid merchant pool listing in cart.');
                    }

                    $lineCents = (int) round($lineSubtotal * 100);
                    $pctM = (float) ($mp->pct_merchant ?? 0);
                    $pctN = (float) ($mp->pct_nonprofit ?? 0);
                    $useNonprofitSplit = $mp->nonprofit_marketplace_enabled
                        && abs($pctM + $pctN) > 0.01;
                    if ($useNonprofitSplit) {
                        $allocated = MarketplacePoolRevenueSplit::allocateLineCents($lineCents, $pctM, $pctN);
                        $mCents = $allocated['merchant_cents'];
                        $nCents = $allocated['nonprofit_cents'];
                        $bCents = 0;
                    } else {
                        // Merchant Hub direct sale (no nonprofit split): pay merchant 100% of line.
                        $mCents = $lineCents;
                        $nCents = 0;
                        $bCents = 0;
                    }

                    $splitMerchantCents += $mCents;
                    $splitOrgCents += $nCents;
                    $splitBiuCents += $bCents;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => null,
                        'organization_product_id' => $op->id,
                        'marketplace_product_id' => null,
                        'organization_id' => $op->organization_id,
                        'printify_product_id' => null,
                        'printify_variant_id' => null,
                        'printify_blueprint_id' => null,
                        'printify_print_provider_id' => null,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'subtotal' => $lineSubtotal,
                        'per_organization_donation_amount' => $donationPerItem,
                        'variant_data' => $cartItem->variant_options,
                        'primary_image' => $cartItem->variant_image,
                        'product_details' => [
                            'marketplace_product_id' => $mp->id,
                            'merchant_id' => $mp->merchant_id,
                            'name' => $mp->name,
                            'organization_product_id' => $op->id,
                        ],
                    ]);

                    if ($mp->inventory_quantity !== null) {
                        $mp->decrement('inventory_quantity', (int) $cartItem->quantity);
                    }
                } else {
                    $product = $cartItem->product;
                    if (! $product) {
                        throw new \Exception('Invalid catalog product in cart.');
                    }

                    $mp = null;
                    if ($product->marketplace_product_id) {
                        $mp = MarketplaceProduct::query()->find($product->marketplace_product_id);
                        if (! $mp) {
                            throw new \Exception('Linked merchant product not found for catalog item.');
                        }
                        if ($product->organization_id && ! $mp->nonprofit_marketplace_enabled) {
                            throw new \Exception(
                                'This nonprofit listing is no longer available: the merchant has disabled nonprofit resale for the source product. Remove it from your cart.'
                            );
                        }
                    }

                    if ($mp) {
                        $lineCents = (int) round($lineSubtotal * 100);
                        $pctM = (float) ($mp->pct_merchant ?? 0);
                        $pctN = (float) ($mp->pct_nonprofit ?? 0);
                        $useNonprofitSplit = $mp->nonprofit_marketplace_enabled
                            && abs($pctM + $pctN) > 0.01;
                        if ($useNonprofitSplit) {
                            $allocated = MarketplacePoolRevenueSplit::allocateLineCents($lineCents, $pctM, $pctN);
                            $mCents = $allocated['merchant_cents'];
                            $nCents = $allocated['nonprofit_cents'];
                            $bCents = 0;
                        } else {
                            $mCents = $lineCents;
                            $nCents = 0;
                            $bCents = 0;
                        }
                        $splitMerchantCents += $mCents;
                        $splitOrgCents += $nCents;
                        $splitBiuCents += $bCents;
                    }

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'organization_product_id' => null,
                        'marketplace_product_id' => $mp?->id,
                        'organization_id' => $product->organization_id,
                        'printify_product_id' => $product->printify_product_id,
                        'printify_variant_id' => $cartItem->printify_variant_id,
                        'printify_blueprint_id' => $cartItem->printify_blueprint_id,
                        'printify_print_provider_id' => $cartItem->printify_print_provider_id,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'subtotal' => $lineSubtotal,
                        'per_organization_donation_amount' => $donationPerItem,
                        'variant_data' => $cartItem->variant_options,
                        'primary_image' => $cartItem->variant_image,
                        'product_details' => $mp ? [
                            'merchant_hub_org_catalog' => true,
                            'marketplace_product_id' => $mp->id,
                            'merchant_id' => $mp->merchant_id,
                            'name' => $product->name,
                        ] : null,
                    ]);

                    $product->update([
                        'quantity_ordered' => $product->quantity_ordered + $cartItem->quantity,
                        'quantity_available' => $product->quantity_available - $cartItem->quantity,
                    ]);
                }
            }

            if ($splitMerchantCents + $splitOrgCents + $splitBiuCents > 0) {
                OrderSplit::create([
                    'order_id' => $order->id,
                    'merchant_amount' => number_format($splitMerchantCents / 100, 2, '.', ''),
                    'organization_amount' => number_format($splitOrgCents / 100, 2, '.', ''),
                    'biu_amount' => number_format($splitBiuCents / 100, 2, '.', ''),
                ]);
            }

            // COMMENTED OUT: Donation logging removed for Printify products
            // Log donation distribution for debugging
            // \Log::info('Donation distribution completed', [
            //     'order_id' => $order->id,
            //     'total_donation' => $totalDonationAmount,
            //     'total_subtotal' => $totalSubtotal,
            //     'organization_count' => count($organizationDonations),
            //     'organization_donations' => $organizationDonations
            // ]);

            $ledgerMeta = MarketplaceOrderLedgerService::transactionMeta(
                $order->fresh(['orderSplit']),
                $stripeFeeUsd
            );
            $ledgerMeta['stripe_fee_amount'] = round($stripeFeeUsd, 2);
            if ($paymentMethod === 'believe_points') {
                $ledgerMeta['believe_points_used'] = $pointsRequired;
            }
            if (! empty($tempOrder->printify_order_id)) {
                $ledgerMeta['printify_order_id'] = $tempOrder->printify_order_id;
            }

            if (! Transaction::query()
                ->where('related_type', Order::class)
                ->where('related_id', $order->id)
                ->where('type', 'purchase')
                ->exists()) {
                Transaction::create([
                    'user_id' => $user->id,
                    'related_id' => $order->id,
                    'related_type' => Order::class,
                    'type' => 'purchase',
                    'status' => Transaction::STATUS_COMPLETED,
                    'amount' => $order->total_amount,
                    'fee' => $paymentMethod === 'stripe' ? round($stripeFeeUsd, 2) : 0,
                    'currency' => 'USD',
                    'payment_method' => $paymentMethod === 'stripe' ? 'stripe' : 'believe_points',
                    'transaction_id' => $paymentMethod === 'stripe' && $paymentIntent !== null
                        ? $paymentIntent->id
                        : 'mp_order_'.$order->id.'_points',
                    'meta' => $ledgerMeta,
                    'processed_at' => now(),
                ]);
            }

            // Clear cart
            $tempOrder->cart->items()->delete();

            // Update temp order status
            $tempOrder->update(['status' => 'payment_completed']);

            DB::commit();

            try {
                $order->load('items');
                app(SupporterActivityService::class)->recordPurchasesForOrder($order);
            } catch (\Throwable $e) {
                \Log::warning('Supporter activity (purchase) failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                \App\Services\ParticipationActivityService::complete(
                    $user,
                    \App\Support\BrpParticipationModule::MARKETPLACE_PURCHASE,
                    $order->id,
                    'Participation reward for marketplace purchase',
                    ['order_id' => $order->id, 'total_amount' => (float) $order->total_amount],
                    null,
                    'catalog_order',
                );
            } catch (\Throwable $e) {
                \Log::warning('Marketplace participation BRP failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(DigitalDeliveryService::class)->provisionCatalogFilesForOrder($order);
            } catch (\Throwable $e) {
                \Log::warning('Digital catalog provisioning failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Post-commit: for manual-only orders, auto-create Shippo label/tracking
            // (Printify orders are handled via Printify webhooks + OrderController UI.)
            try {
                if ($this->shippoService->isConfigured() && empty($order->printify_order_id) && MarketplacePickup::isShippoPurchasableRateId($tempOrder->shippo_rate_object_id)) {
                    $purchase = $this->shippoService->purchaseLabel((string) $tempOrder->shippo_rate_object_id);

                    if (($purchase['success'] ?? false) === true) {
                        // Load relations for parcel snapshot.
                        $order->load(['items.product', 'shippingInfo']);
                        $parcel = $this->shippoService->getParcelSnapshot($order);
                        $shippingInfo = $order->shippingInfo;
                        $shipToName = trim(($shippingInfo?->first_name ?? '').' '.($shippingInfo?->last_name ?? ''));

                        $order->update([
                            'shippo_shipment_id' => $tempOrder->shippo_shipment_id,
                            'shippo_transaction_id' => $purchase['transaction_id'] ?? null,
                            'tracking_number' => $purchase['tracking_number'] ?? null,
                            'tracking_url' => $purchase['tracking_url'] ?? null,
                            'label_url' => $purchase['label_url'] ?? null,
                            'carrier' => $purchase['carrier'] ?? null,
                            'shipping_status' => 'label_created',
                            'shipped_at' => now(),
                        ]);

                        ShippoShipment::updateOrCreate(
                            ['order_id' => $order->id, 'product_type' => 'manual'],
                            [
                                'shippo_shipment_id' => $tempOrder->shippo_shipment_id,
                                'selected_rate_object_id' => (string) $tempOrder->shippo_rate_object_id,
                                'shippo_transaction_id' => $purchase['transaction_id'] ?? null,
                                'tracking_number' => $purchase['tracking_number'] ?? null,
                                'label_url' => $purchase['label_url'] ?? null,
                                'carrier' => $purchase['carrier'] ?? null,
                                'ship_to_name' => $shipToName ?: null,
                                'ship_to_street1' => (string) ($shippingInfo?->shipping_address ?? ''),
                                'ship_to_city' => $shippingInfo?->city ?: null,
                                'ship_to_state' => $shippingInfo?->state ?: null,
                                'ship_to_zip' => $shippingInfo?->zip ?: null,
                                'ship_to_country' => $shippingInfo?->country ?: null,
                                'parcel_weight_oz' => $parcel['weight'] ?? null,
                                'parcel_length_in' => $parcel['length'] ?? null,
                                'parcel_width_in' => $parcel['width'] ?? null,
                                'parcel_height_in' => $parcel['height'] ?? null,
                                'status' => 'label_created',
                            ]
                        );
                    }
                }
            } catch (\Exception $e) {
                // Shipping label failure should not break the purchase completion.
                \Log::error('Auto Shippo label failed (checkout confirmPayment)', [
                    'order_id' => $order->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'orderId' => $order->id,
                'redirect' => route('user.profile.orders'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Payment confirmation error: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json(['error' => 'Failed to process payment: '.$e->getMessage()], 500);
        }
    }

    /**
     * Prepare Printify order data - only includes Printify products
     */
    private function preparePrintifyOrder(Cart $cart, TempOrder $tempOrder): array
    {
        $lineItems = [];

        foreach ($cart->items as $item) {
            // Only include Printify catalog products (skip pool listings and manual products)
            if (
                $item->product_id
                && $item->product
                && ! empty($item->product->printify_product_id)
                && ! empty($item->printify_variant_id)
            ) {
                $lineItems[] = [
                    'product_id' => $item->product->printify_product_id,
                    'variant_id' => (int) $item->printify_variant_id,
                    'quantity' => $item->quantity,
                    'external_id' => 'temp-'.$tempOrder->id.'-item-'.$item->id,
                ];
            }
        }

        return [
            'external_id' => 'ORDER-'.$tempOrder->id.'-'.uniqid(),
            'label' => 'ORDER-LABEL'.$tempOrder->id,
            'line_items' => $lineItems,
            'address_to' => [
                'first_name' => $tempOrder->first_name,
                'last_name' => $tempOrder->last_name,
                'email' => $tempOrder->email,
                'phone' => $tempOrder->phone,
                'country' => $tempOrder->country,
                'region' => $tempOrder->state,
                'city' => $tempOrder->city,
                'address1' => $tempOrder->shipping_address,
                'zip' => $tempOrder->zip,
            ],
            'send_shipping_notification' => false,
        ];
    }

    /**
     * Calculate shipping from Printify
     */
    public function calculateShippingFromPrintify(
        string $printifyOrderId,
        string $country,
        string $state,
        string $city,
        string $zip
    ): array {
        try {
            // Get Printify order details
            $printifyOrder = $this->printifyService->getOrder($printifyOrderId);

            // Printify bills shipping on the order as total_shipping (cents). shipping_method.cost is often 0
            // until finalized — always align UI + temp_order with total_shipping so checkout matches the summary.
            $defaultCost = round((float) (($printifyOrder['total_shipping'] ?? 0) / 100), 2);

            $methodName = 'Standard Shipping';
            if (isset($printifyOrder['shipping_method']) && is_array($printifyOrder['shipping_method'])) {
                $sm = $printifyOrder['shipping_method'];
                if (! empty($sm['title'])) {
                    $methodName = (string) $sm['title'];
                } elseif (! empty($sm['name'])) {
                    $methodName = (string) $sm['name'];
                }
                $lineCost = round((float) (($sm['cost'] ?? 0) / 100), 2);
                if ($defaultCost <= 0 && $lineCost > 0) {
                    $defaultCost = $lineCost;
                }
            }

            $methods = [
                [
                    'id' => 'standard',
                    'name' => $methodName,
                    'cost' => $defaultCost,
                    // UI appends "business days"; keep range only here
                    'estimated_days' => '10-30',
                ],
            ];

            \Log::info('Printify order in checkout step1 submit', [
                'printify_order_id' => $printifyOrderId,
                'total_shipping_cents' => $printifyOrder['total_shipping'] ?? null,
                'shipping_usd' => $defaultCost,
            ]);

            return [
                'cost' => $defaultCost,
                'total_tax' => $printifyOrder['total_tax'] ?? null,
                'methods' => $methods,
            ];

        } catch (\Exception $e) {
            \Log::error('Shipping calculation error: '.$e->getMessage());

            return [
                'cost' => 9.99,
                'methods' => [
                    [
                        'id' => 'standard',
                        'name' => 'Standard Shipping',
                        'cost' => 9.99,
                        'estimated_days' => '5-10',
                    ],
                ],
            ];
        }
    }

    /**
     * Update tax amount when Step2 page loads
     */
    public function updateTax(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temp_order_id' => 'required|exists:temp_orders,id',
        ]);

        $user = auth()->user();
        $tempOrder = TempOrder::where('user_id', $user->id)
            ->findOrFail($validated['temp_order_id']);

        try {
            if ($tempOrder->printify_order_id) {
                $printifyOrder = $this->printifyService->getOrder($tempOrder->printify_order_id);
                $printifyShippingCost = (float) (($printifyOrder['total_shipping'] ?? 0) / 100);
                $shippingCost = round($printifyShippingCost + (float) ($tempOrder->shippo_rate_amount ?? 0), 2);
                $rawPrintifyTax = (float) (($printifyOrder['total_tax'] ?? 0) / 100);
                $tempOrder->load(['cart.items.product']);
                $split = $this->sumPrintifyRetailAndProductionCost($tempOrder->cart);
                $adj = $this->adjustPrintifySalesTaxForMarkup($rawPrintifyTax, $split['retail'], $split['cost']);
                $printifyTaxPortion = $adj['printify_tax'];
                $additionalSalesTaxAdjustment = $adj['additional_sales_tax_adjustment'];
                $stateTaxPortion = $this->calculateStateSalesTaxForNonPrintifySubtotal($tempOrder->cart, (string) ($tempOrder->state ?? ''));
                $newTaxAmount = round($adj['total_tax'] + $stateTaxPortion, 2);

                \Log::info('Tax amount updated on Step2 load', [
                    'temp_order_id' => $tempOrder->id,
                    'old_tax_amount' => $tempOrder->tax_amount,
                    'new_tax_amount' => $newTaxAmount,
                    'old_shipping_cost' => $tempOrder->shipping_cost,
                    'new_shipping_cost' => $shippingCost,
                    'printify_order_id' => $tempOrder->printify_order_id,
                ]);
            } else {
                $shippingCost = (float) ($tempOrder->shipping_cost ?? 0);
                $newTaxAmount = $tempOrder->tax_amount ?? 0;
                $printifyTaxPortion = (float) ($tempOrder->printify_tax_amount ?? 0);
                $additionalSalesTaxAdjustment = (float) ($tempOrder->additional_sales_tax_adjustment ?? 0);

                \Log::info('Tax amount for manual product (Step2 load)', [
                    'temp_order_id' => $tempOrder->id,
                    'tax_amount' => $newTaxAmount,
                    'shipping_cost' => $shippingCost,
                ]);
            }

            $oldTotal = $tempOrder->total_amount;

            $tempOrder->update([
                'tax_amount' => $newTaxAmount,
                'printify_tax_amount' => $printifyTaxPortion,
                'additional_sales_tax_adjustment' => $additionalSalesTaxAdjustment,
                'shipping_cost' => $shippingCost,
                'total_amount' => $tempOrder->subtotal +
                    (float) ($tempOrder->platform_fee ?? 0) +
                    $shippingCost +
                    $newTaxAmount,
            ]);

            // Check if total amount changed significantly
            $amountChanged = abs($oldTotal - $tempOrder->total_amount) > 0.01;

            return response()->json([
                'success' => true,
                'tax_amount' => (float) $newTaxAmount,
                'printify_tax_amount' => (float) $printifyTaxPortion,
                'additional_sales_tax_adjustment' => (float) $additionalSalesTaxAdjustment,
                'shipping_cost' => (float) $shippingCost,
                'total_amount' => (float) $tempOrder->total_amount,
                'amount_changed' => $amountChanged,
                'old_total' => (float) $oldTotal,
                'new_total' => (float) $tempOrder->total_amount,
                'difference' => (float) ($tempOrder->total_amount - $oldTotal),
            ]);

        } catch (\Exception $e) {
            \Log::error('Tax update error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to update tax amount',
            ], 500);
        }
    }

    /**
     * Merchant Hub / pool marketplace rows: types that ship from the merchant (matches org create flow: physical + service).
     */
    private function marketplaceProductNeedsShippoRates(?MarketplaceProduct $mp): bool
    {
        if (! $mp) {
            return false;
        }

        return in_array((string) $mp->product_type, ['physical', 'service', 'media'], true);
    }

    /**
     * Only true digital goods skip Shippo; service/media are still merchant-fulfilled physical-style shipments.
     */
    private function marketplaceProductIsDigitalDeliveryOnly(?MarketplaceProduct $mp): bool
    {
        return $mp && (string) $mp->product_type === 'digital';
    }

    /**
     * Sum retail (cart) and Printify production cost for catalog Printify line items only.
     *
     * @return array{retail: float, cost: float}
     */
    private function sumPrintifyRetailAndProductionCost(?Cart $cart): array
    {
        $retail = 0.0;
        $cost = 0.0;

        if (! $cart || $cart->items->isEmpty()) {
            return ['retail' => 0.0, 'cost' => 0.0];
        }

        $productCache = [];

        foreach ($cart->items as $item) {
            if (
                ! $item->product_id
                || ! $item->product
                || empty($item->product->printify_product_id)
                || empty($item->printify_variant_id)
            ) {
                continue;
            }

            $pid = (string) $item->product->printify_product_id;
            if (! isset($productCache[$pid])) {
                try {
                    $productCache[$pid] = $this->printifyService->getProduct($pid);
                } catch (\Exception $e) {
                    \Log::warning('sumPrintifyRetailAndProductionCost: getProduct failed', [
                        'printify_product_id' => $pid,
                        'error' => $e->getMessage(),
                    ]);
                    $productCache[$pid] = [];
                }
            }

            $details = $productCache[$pid];
            $variantId = (int) $item->printify_variant_id;
            $costCents = 0;
            foreach ($details['variants'] ?? [] as $v) {
                if ((int) ($v['id'] ?? 0) === $variantId) {
                    $costCents = (int) ($v['cost'] ?? 0);
                    break;
                }
            }

            $qty = (int) $item->quantity;
            $retail += (float) $item->unit_price * $qty;
            $cost += ($costCents / 100) * $qty;
        }

        return [
            'retail' => round($retail, 2),
            'cost' => round($cost, 2),
        ];
    }

    /**
     * Printify remits tax on production; retail includes org markup. Scale total tax so the effective
     * rate applies to full retail: T_retail ≈ T_printify × (R / C).
     */
    private function adjustPrintifySalesTaxForMarkup(float $printifyTaxDollars, float $retailSubtotal, float $productionCostSubtotal): array
    {
        $printifyTaxDollars = round($printifyTaxDollars, 2);

        if ($productionCostSubtotal <= 0 || $printifyTaxDollars <= 0 || $retailSubtotal <= $productionCostSubtotal) {
            return [
                'printify_tax' => $printifyTaxDollars,
                'additional_sales_tax_adjustment' => 0.0,
                'total_tax' => $printifyTaxDollars,
            ];
        }

        $ratio = $retailSubtotal / $productionCostSubtotal;
        $totalRetailAligned = round($printifyTaxDollars * $ratio, 2);
        $additional = max(0.0, round($totalRetailAligned - $printifyTaxDollars, 2));

        return [
            'printify_tax' => $printifyTaxDollars,
            'additional_sales_tax_adjustment' => $additional,
            'total_tax' => round($printifyTaxDollars + $additional, 2),
        ];
    }

    /**
     * Sum only non-Printify retail subtotal for state sales tax.
     */
    private function nonPrintifySubtotal(?Cart $cart): float
    {
        if (! $cart || $cart->items->isEmpty()) {
            return 0.0;
        }

        $subtotal = 0.0;
        foreach ($cart->items as $item) {
            if (! $this->isNonPrintifyCartItem($item)) {
                continue;
            }
            $subtotal += (float) $item->unit_price * (int) $item->quantity;
        }

        return round($subtotal, 2);
    }

    private function calculateStateSalesTaxForNonPrintifySubtotal(?Cart $cart, string $state): float
    {
        $state = strtoupper(trim($state));
        if ($state === '') {
            return 0.0;
        }

        $subtotal = $this->nonPrintifySubtotal($cart);
        if ($subtotal <= 0) {
            return 0.0;
        }

        $stateTax = \App\Models\StateSalesTax::where('state_code', $state)->first();
        if (! $stateTax) {
            return 0.0;
        }

        $rate = (float) $stateTax->base_sales_tax_rate;

        return round(($subtotal * $rate) / 100, 2);
    }

    private function isNonPrintifyCartItem($item): bool
    {
        if ($item->marketplace_product_id || $item->organization_product_id) {
            return true;
        }

        return ! ($item->product_id
            && $item->product
            && ! empty($item->product->printify_product_id));
    }

    private function cartHasNonPrintifyShippableItems(?Cart $cart): bool
    {
        if (! $cart || $cart->items->isEmpty()) {
            return false;
        }

        return $cart->items->contains(function ($item) {
            if (! $this->isNonPrintifyCartItem($item)) {
                return false;
            }

            return DigitalProductDelivery::cartItemNeedsShipping($item);
        });
    }

    /**
     * Merge Printify shipping with non-Printify shipping methods.
     *
     * @param  array{cost?: float, methods?: array<int, array<string, mixed>>}  $printifyShipping
     * @param  array<int, array<string, mixed>>  $nonPrintifyMethods
     * @return array{cost: float, methods: array<int, array<string, mixed>>}
     */
    private function mergeShippingData(array $printifyShipping, float $nonPrintifyCost, array $nonPrintifyMethods): array
    {
        $printifyCost = round((float) ($printifyShipping['cost'] ?? 0), 2);
        if ($nonPrintifyMethods === []) {
            return [
                'cost' => $printifyCost,
                'methods' => $printifyShipping['methods'] ?? [],
            ];
        }

        $methods = array_map(function ($method) use ($printifyCost) {
            $methodCost = round((float) ($method['cost'] ?? 0), 2);
            $method['non_printify_cost'] = $methodCost;
            $method['cost'] = round($methodCost + $printifyCost, 2);

            return $method;
        }, $nonPrintifyMethods);

        return [
            'cost' => round($printifyCost + $nonPrintifyCost, 2),
            'methods' => $methods,
        ];
    }

    private function estimatePrintifyTaxFallback(TempOrder $tempOrder): float
    {
        $tempOrder->loadMissing(['cart.items.product']);
        $printifySubtotal = round((float) $tempOrder->subtotal - $this->nonPrintifySubtotal($tempOrder->cart), 2);
        if ($printifySubtotal <= 0) {
            return 0.0;
        }

        $state = strtoupper((string) ($tempOrder->state ?? ''));
        if ($state === '') {
            return 0.0;
        }

        $stateTax = \App\Models\StateSalesTax::where('state_code', $state)->first();
        if (! $stateTax) {
            return 0.0;
        }

        $rate = (float) $stateTax->base_sales_tax_rate;

        return round(($printifySubtotal * $rate) / 100, 2);
    }
}
