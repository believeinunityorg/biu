<?php

use App\Http\Controllers\NonprofitExemptionCertificateController;
use App\Http\Controllers\PaymentMethodSettingController;
use App\Http\Controllers\Settings\DonateWidgetController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\OrganizationSetupChecklistController;
use App\Http\Controllers\Settings\PayAsYouGoServicesController;
use App\Http\Controllers\Settings\ProfileSettingsEntryController;
use App\Http\Controllers\Settings\ReferralLinkController;
use App\Http\Controllers\Organization\OrganizationPaymentSettingsController;
use App\Http\Controllers\Organization\OrganizationManualPaymentVerificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileSettingsEntryController::class, 'show'])->name('profile.edit');
});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|care_alliance'])->group(function () {

    // Admin-only settings (outside topics.selected middleware)
    Route::middleware('role:admin')->group(function () {
        // Service Hub Settings - Admin Only
        Route::get('/settings/service-hub', [\App\Http\Controllers\Admin\ServiceHubSettingsController::class, 'index'])->name('service-hub-settings.index');
        Route::post('/settings/service-hub/fees', [\App\Http\Controllers\Admin\ServiceHubSettingsController::class, 'updateFees'])->name('service-hub-settings.update-fees');
        Route::post('/settings/service-hub/state-tax/{stateId}', [\App\Http\Controllers\Admin\ServiceHubSettingsController::class, 'updateStateTax'])->name('service-hub-settings.update-state-tax');
        Route::post('/settings/service-hub/state-taxes/bulk', [\App\Http\Controllers\Admin\ServiceHubSettingsController::class, 'bulkUpdateStateTaxes'])->name('service-hub-settings.bulk-update-taxes');
    });

    Route::middleware('topics.selected')->group(function () {
        Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('settings/financial', [ProfileController::class, 'editFinancial'])
            ->name('profile.financial.edit')
            ->middleware('role:care_alliance');
        Route::patch('settings/profile/financial', [ProfileController::class, 'updateFinancial'])
            ->name('profile.financial.update')
            ->middleware('role:care_alliance');
        Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::get('/settings/settings/referral', [ReferralLinkController::class, 'edit'])->name('referral.edit');
        // Payment Methods - Admin Only (applies globally to all users)
        Route::middleware('role:admin')->group(function () {
            Route::get('/settings/payment-methods', [PaymentMethodSettingController::class, 'index'])->name('payment-methods.index');
            Route::post('/settings/payment-methods', [PaymentMethodSettingController::class, 'update'])->name('payment-methods.update');

            // Bridge Settings - Admin Only
            Route::get('/settings/bridge', [\App\Http\Controllers\BridgeSettingsController::class, 'index'])->name('bridge.index');
            Route::post('/settings/bridge', [\App\Http\Controllers\BridgeSettingsController::class, 'update'])->name('bridge.update');
            Route::post('/settings/bridge/enable-cards', [\App\Http\Controllers\BridgeSettingsController::class, 'enableCards'])->name('bridge.enable-cards');

            // Twilio WhatsApp / SMS - Admin Only
            Route::get('/settings/twilio', [\App\Http\Controllers\TwilioSettingsController::class, 'index'])->name('twilio.index');
            Route::post('/settings/twilio', [\App\Http\Controllers\TwilioSettingsController::class, 'update'])->name('twilio.update');
            Route::post('/settings/twilio/test', [\App\Http\Controllers\TwilioSettingsController::class, 'test'])->name('twilio.test');

            // Application Settings - Admin Only
            Route::get('/settings/application', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'index'])->name('application.index');
            Route::post('/settings/application/optimize', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'optimize'])->name('application.optimize');
            Route::post('/settings/application/clear', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'clear'])->name('application.clear');
            Route::post('/settings/application/footer', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'updateFooter'])->name('application.footer.update');
            Route::post('/settings/application/storage-link', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'storageLink'])->name('application.storage-link');
            Route::post('/settings/application/migrate', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'migrate'])->name('application.migrate');
            Route::post('/settings/application/seed', [\App\Http\Controllers\Settings\ApplicationSettingsController::class, 'runSeed'])->name('application.seed');
        });
        Route::get('settings/appearance', function () {
            return Inertia::render('settings/appearance');
        })->name('appearance');

        // Exemption Certificates - For nonprofit organizations
        Route::middleware('role:organization|organization_pending|care_alliance')->group(function () {
            Route::get('settings/setup-checklist', [OrganizationSetupChecklistController::class, 'index'])
                ->name('setup-checklist.index');
            Route::get('settings/setup-checklist/status', [OrganizationSetupChecklistController::class, 'status'])
                ->name('setup-checklist.status');

            Route::get('settings/pay-as-you-go', [PayAsYouGoServicesController::class, 'index'])
                ->name('pay-as-you-go.index');

            Route::get('settings/donate-widget', [DonateWidgetController::class, 'edit'])
                ->name('donate-widget.settings')
                ->middleware('role:organization');

            Route::middleware('role:organization|care_alliance')->group(function () {
                Route::get('settings/donation-payments', [OrganizationPaymentSettingsController::class, 'edit'])
                    ->name('organization.payment-settings.edit');
                Route::post('settings/donation-payments', [OrganizationPaymentSettingsController::class, 'update'])
                    ->name('organization.payment-settings.update');

                Route::get('settings/manual-payments', [OrganizationManualPaymentVerificationController::class, 'index'])
                    ->name('organization.manual-payments.index');
                Route::post('settings/manual-payments/{paymentTransaction}/approve', [OrganizationManualPaymentVerificationController::class, 'approve'])
                    ->name('organization.manual-payments.approve');
                Route::post('settings/manual-payments/{paymentTransaction}/reject', [OrganizationManualPaymentVerificationController::class, 'reject'])
                    ->name('organization.manual-payments.reject');
            });

            Route::get('settings/exemption-certificates', [NonprofitExemptionCertificateController::class, 'index'])->name('exemption-certificates.index');
            Route::post('settings/exemption-certificates', [NonprofitExemptionCertificateController::class, 'store'])->name('exemption-certificates.store');
            Route::put('settings/exemption-certificates/{id}', [NonprofitExemptionCertificateController::class, 'update'])->name('exemption-certificates.update');
            Route::delete('settings/exemption-certificates/{id}', [NonprofitExemptionCertificateController::class, 'destroy'])->name('exemption-certificates.destroy');

            // State Sales Tax - For organizations to view
            Route::get('settings/state-sales-tax', [\App\Http\Controllers\Admin\StateSalesTaxController::class, 'index'])->name('state-sales-tax.index');
            Route::get('settings/state-sales-tax/{stateSalesTax}', [\App\Http\Controllers\Admin\StateSalesTaxController::class, 'show'])->name('state-sales-tax.show');
        });

        Route::get('settings/billing', [\App\Http\Controllers\Settings\BillingController::class, 'index'])->name('billing.index');
        Route::get('settings/saved-payment-methods', [\App\Http\Controllers\UserPaymentMethodController::class, 'index'])->name('settings.saved-payment-methods.index');
    });

});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:user|organization|admin|care_alliance'])->put('settings/password', [PasswordController::class, 'update'])->name('password.update');

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|care_alliance'])->group(function () {
    Route::patch(
        'settings/social-accounts',
        [ProfileController::class, 'updateSocialAccounts']
    )->name('profile.social-accounts.update');

    Route::patch(
        'settings/gift-card-terms',
        [ProfileController::class, 'updateGiftCardTerms']
    )->name('profile.gift-card-terms.update');
});
