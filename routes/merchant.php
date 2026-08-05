<?php

use App\Http\Controllers\Merchant\Auth\MerchantAuthController;
use App\Http\Controllers\Merchant\MerchantBrpCampaignController;
use App\Http\Controllers\Merchant\MerchantBrpWalletController;
use App\Http\Controllers\Merchant\MerchantFeedbackRewardsController;
use App\Http\Controllers\Merchant\MerchantMarketplacePoolApprovalController;
use App\Http\Controllers\Merchant\MerchantMarketplaceProductController;
use App\Http\Controllers\SupporterFeedbackController;
use App\Support\PasswordResetCooldown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ============================================
// MERCHANT PROGRAM ROUTES
// ============================================
// These routes are only accessible on merchant.believeinunity.org domain

// Home/Index Route - Landing Page
Route::get('/', function () {
    return Inertia::render('merchant/Landing');
})->name('merchant.home');

// Auth Routes (Login/Register) - Merchant branded pages
Route::middleware('guest:merchant')->group(function () {
    Route::get('/login', [MerchantAuthController::class, 'showLoginForm'])->name('merchant.login');
    Route::post('login', [MerchantAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('merchant.login.store');

    Route::get('/register', [MerchantAuthController::class, 'create'])->name('merchant.register');
    Route::post('register', [MerchantAuthController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('forgot-password', function (Request $request) {
        return Inertia::render('merchant/Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            'passwordResetCooldownUntil' => $request->session()->get('passwordResetCooldownUntil'),
            'passwordResetThrottleSeconds' => PasswordResetCooldown::seconds('merchants'),
        ]);
    })->name('merchant.password.request');

    Route::post('forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('merchant.password.email');

    Route::get('reset-password/{token}', [\App\Http\Controllers\Auth\NewPasswordController::class, 'create'])
        ->name('merchant.password.reset');

    Route::post('reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('merchant.password.store');
});

// Authenticated Merchant Routes
Route::middleware(['auth:merchant'])->group(function () {
    // Dashboard (no subscription required)
    Route::get('/dashboard', [App\Http\Controllers\Merchant\MerchantDashboardController::class, 'index'])->name('merchant.dashboard');

    // Subscription routes (no subscription required to view/subscribe)
    Route::prefix('subscription')->name('merchant.subscription.')->group(function () {
        Route::get('/', [App\Http\Controllers\Merchant\MerchantSubscriptionController::class, 'index'])->name('index');
        Route::post('/{plan}/subscribe', [App\Http\Controllers\Merchant\MerchantSubscriptionController::class, 'subscribe'])->name('subscribe');
        Route::get('/success', [App\Http\Controllers\Merchant\MerchantSubscriptionController::class, 'success'])->name('success');
        Route::post('/cancel', [App\Http\Controllers\Merchant\MerchantSubscriptionController::class, 'cancel'])->name('cancel');
    });

    // Protected routes (require subscription)
    Route::middleware([\App\Http\Middleware\RequireMerchantSubscription::class])->group(function () {
        // Offers Management
        Route::prefix('offers')->name('offers.')->group(function () {
            Route::get('/', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'index'])->name('index');
            Route::get('/create', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'create'])->name('create');
            Route::post('/', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'store'])->name('store');
            Route::get('/{offer}', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'show'])->name('show');
            Route::get('/{offer}/edit', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'edit'])->name('edit');
            Route::put('/{offer}', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'update'])->name('update');
            Route::delete('/{offer}', [App\Http\Controllers\Merchant\MerchantOfferController::class, 'destroy'])->name('destroy');
        });

        // Redemptions
        Route::prefix('redemptions')->name('redemptions.')->group(function () {
            Route::get('/', [App\Http\Controllers\Merchant\MerchantRedemptionsController::class, 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Merchant\MerchantRedemptionsController::class, 'show'])->name('show');
            Route::get('/qr-code/{code}', [App\Http\Controllers\Merchant\MerchantRedemptionsController::class, 'generateQrCode'])->name('qr-code');
            Route::get('/verify/{code}', [App\Http\Controllers\Merchant\MerchantRedemptionsController::class, 'verify'])->name('verify');
        });

        // Analytics
        Route::get('/analytics', [App\Http\Controllers\Merchant\MerchantAnalyticsController::class, 'index'])->name('merchant.analytics');

        // Settings
        Route::get('/settings', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'index'])->name('merchant.settings');

        Route::prefix('payouts')->name('merchant.payouts.')->group(function () {
            Route::get('/', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'index'])->name('index');
            Route::patch('/preferred-method', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'updatePreferredMethod'])->name('preferred-method');
            Route::post('/paypal/connect', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'connectPayPal'])->name('paypal.connect');
            Route::post('/paypal/disconnect', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'disconnectPayPal'])->name('paypal.disconnect');
            Route::get('/stripe-connect/start', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'startStripeConnect'])->name('stripe-connect.start');
            Route::get('/stripe-connect/return', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'stripeConnectReturn'])->name('stripe-connect.return');
            Route::get('/stripe-connect/refresh', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'stripeConnectRefresh'])->name('stripe-connect.refresh');
            Route::post('/stripe-connect/disconnect', [App\Http\Controllers\Merchant\MerchantPayoutSettingsController::class, 'disconnectStripeConnect'])->name('stripe-connect.disconnect');
        });

        Route::patch('/settings/profile', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'updateProfile'])->name('merchant.settings.profile');
        Route::patch('/settings/business', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'updateBusiness'])->name('merchant.settings.business');

        Route::post('/settings/shipping-addresses', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'storeShippingAddress'])->name('merchant.settings.shipping-addresses.store');
        Route::patch('/settings/shipping-addresses/{shippingAddress}', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'updateShippingAddress'])->name('merchant.settings.shipping-addresses.update');
        Route::delete('/settings/shipping-addresses/{shippingAddress}', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'destroyShippingAddress'])->name('merchant.settings.shipping-addresses.destroy');
        Route::post('/settings/shipping-addresses/{shippingAddress}/default', [App\Http\Controllers\Merchant\MerchantSettingsController::class, 'setDefaultShippingAddress'])->name('merchant.settings.shipping-addresses.default');

        Route::prefix('marketplace-products')->name('marketplace-products.')->group(function () {
            Route::get('/', [MerchantMarketplaceProductController::class, 'index'])->name('index');
            Route::get('/create', [MerchantMarketplaceProductController::class, 'create'])->name('create');
            Route::post('/', [MerchantMarketplaceProductController::class, 'store'])->name('store');
            Route::get('/{marketplace_product}/edit', [MerchantMarketplaceProductController::class, 'edit'])->name('edit');
            Route::put('/{marketplace_product}', [MerchantMarketplaceProductController::class, 'update'])->name('update');
            Route::delete('/{marketplace_product}', [MerchantMarketplaceProductController::class, 'destroy'])->name('destroy');
        });

        /** Nonprofit pool listing requests (manual approval on marketplace products) */
        Route::prefix('marketplace-pool-approvals')->name('marketplace-pool-approvals.')->group(function () {
            Route::get('/', [MerchantMarketplacePoolApprovalController::class, 'index'])->name('index');
            Route::post('/{organization_product}/approve', [MerchantMarketplacePoolApprovalController::class, 'approve'])->name('approve');
            Route::post('/{organization_product}/decline', [MerchantMarketplacePoolApprovalController::class, 'decline'])->name('decline');
        });

        Route::get('/marketplace-orders', [App\Http\Controllers\Merchant\MerchantMarketplaceOrderController::class, 'index'])->name('merchant.marketplace-orders.index');
        Route::get('/marketplace-orders/{order}/shippo/rates', [App\Http\Controllers\Merchant\MerchantMarketplaceOrderController::class, 'getShippoRates'])->name('merchant.marketplace-orders.shippo.rates');
        Route::post('/marketplace-orders/{order}/shippo/purchase-label', [App\Http\Controllers\Merchant\MerchantMarketplaceOrderController::class, 'purchaseShippoLabel'])->name('merchant.marketplace-orders.shippo.purchase-label');
        Route::post('/marketplace-orders/{order}/items/{orderItem}/digital-deliveries', [App\Http\Controllers\DigitalDeliveryController::class, 'uploadOrderItemFiles'])->name('merchant.marketplace-orders.digital-deliveries.upload');
        Route::delete('/marketplace-orders/{order}/items/{orderItem}/digital-deliveries/{delivery}', [App\Http\Controllers\DigitalDeliveryController::class, 'deleteOrderItemDelivery'])->name('merchant.marketplace-orders.digital-deliveries.delete');
        Route::post('/marketplace-products/{marketplaceProduct}/digital-files', [App\Http\Controllers\DigitalDeliveryController::class, 'uploadCatalogForMarketplaceProduct'])->name('merchant.marketplace-products.digital-files.upload');

        // Feedback & Rewards
        Route::prefix('feedback-rewards')->name('feedback-rewards.')->group(function () {
            Route::get('/', [MerchantFeedbackRewardsController::class, 'index'])->name('index');
            Route::get('/create', [MerchantFeedbackRewardsController::class, 'create'])->name('create');
            Route::post('/', [MerchantFeedbackRewardsController::class, 'store'])->name('store');
            Route::get('/{campaign}', [MerchantFeedbackRewardsController::class, 'show'])->name('show');
            Route::get('/{campaign}/edit', [MerchantFeedbackRewardsController::class, 'edit'])->name('edit');
            Route::put('/{campaign}', [MerchantFeedbackRewardsController::class, 'update'])->name('update');
            Route::post('/{campaign}/launch', [MerchantFeedbackRewardsController::class, 'launch'])->name('launch');
            Route::post('/{campaign}/end', [MerchantFeedbackRewardsController::class, 'end'])->name('end');
        });

        // Merchant playbooks (marketing patterns using BRP)
        Route::get('/playbooks', [MerchantBrpCampaignController::class, 'playbooks'])->name('merchant.playbooks');

        // Fund BRP campaigns + listing
        Route::get('/brp-funding', [MerchantBrpCampaignController::class, 'create'])->name('merchant.brp-funding');
        Route::post('/brp-funding/checkout', [MerchantBrpCampaignController::class, 'startCheckout'])->name('merchant.brp-funding.checkout');
        Route::get('/brp-funding/success', [MerchantBrpCampaignController::class, 'fundingSuccess'])->name('merchant.brp-funding.success');
        Route::get('/brp-campaigns', [MerchantBrpCampaignController::class, 'index'])->name('merchant.brp-campaigns.index');

        // BRP Wallet
        Route::prefix('wallet/brp')->name('wallet.brp.')->group(function () {
            Route::get('/', [MerchantBrpWalletController::class, 'index'])->name('index');
            Route::get('/buy', [MerchantBrpWalletController::class, 'buyForm'])->name('buy');
            Route::post('/purchase', [MerchantBrpWalletController::class, 'purchase'])->name('purchase');
            Route::get('/purchase/success', [MerchantBrpWalletController::class, 'purchaseSuccess'])->name('purchase.success');
        });
    });

    // Logout
    Route::post('logout', [MerchantAuthController::class, 'destroy'])
        ->name('merchant.logout');
});

// Public Routes (for supporters to view offers)
Route::prefix('hub')->name('hub.')->group(function () {
    Route::get('/', [App\Http\Controllers\Merchant\HubController::class, 'index'])->name('index');

    // Success route (must be before /offers/{slug} to avoid route conflict)
    Route::get('/offers/stripe/success', [App\Http\Controllers\Merchant\HubController::class, 'success'])->name('offer.success');

    // Checkout route (requires auth - accepts both web and merchant guards)
    Route::middleware(['auth:web,merchant'])->group(function () {
        Route::post('/offers/checkout', [App\Http\Controllers\Merchant\HubController::class, 'checkout'])->name('offer.checkout');
    });

    // Offer detail route (must be last to avoid matching "success" as slug)
    Route::get('/offers/{slug}', [App\Http\Controllers\Merchant\HubOfferController::class, 'show'])->name('offer.show');
});

// Supporter Feedback Routes (require auth)
Route::middleware(['auth:web,merchant'])->group(function () {
    Route::get('/feedback/{uuid}', [SupporterFeedbackController::class, 'show'])->name('feedback.show');
    Route::post('/feedback/{uuid}', [SupporterFeedbackController::class, 'submit'])->name('feedback.submit');
});
