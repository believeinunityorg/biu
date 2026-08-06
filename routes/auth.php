<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CareAlliance\CareAllianceRegisterController;
use App\Http\Controllers\OrganizationRegisterController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\PurchaseOrderController;
use App\Models\User;
use App\Services\SeoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Development staging only — shareable direct link to organization registration (501c3ers.com).
Route::get('/dev/organization', function (Request $request) {
    if (! is_development_site($request)) {
        abort(404);
    }

    return app(OrganizationRegisterController::class)->create($request);
})->name('dev.register.organization');

Route::middleware('guest')->group(function () {
    Route::get('/register', function () {
        return Inertia::render('frontend/register', [
            'seo' => SeoService::forPage('register'),
        ]);
    })->name('register');

    Route::get('/register/user', function (Request $request) {
        $positions = \App\Models\SupporterPosition::where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        $seo = SeoService::forPage('register_user');
        $referralCode = null;
        $lockOrganization = null;

        if ($request->has('ref')) {
            $referrer = User::where('referral_code', $request->ref)->first();
            if (! $referrer) {
                return redirect()->route('register')->with('error', 'Invalid referral code');
            }

            $referralCode = $referrer->referral_code;
            $lockOrg = app(\App\Services\SupporterPrimaryOrganizationService::class)
                ->registeredOrganizationForReferrer($referrer);

            if ($lockOrg !== null) {
                $lockOrganization = [
                    'id' => $lockOrg->id,
                    'name' => $lockOrg->name,
                ];
            }
        }

        $giftInviteEmail = null;
        $giftInviteToken = null;
        if ($request->filled('gift_invite')) {
            $invite = \App\Models\BelievePointGiftInvite::query()
                ->where('token', (string) $request->query('gift_invite'))
                ->where('status', \App\Models\BelievePointGiftInvite::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->first();
            if ($invite) {
                $giftInviteToken = $invite->token;
                $giftInviteEmail = $invite->recipient_email;
            }
        }
        if ($giftInviteEmail === null && $request->filled('email')) {
            $candidate = strtolower(trim((string) $request->query('email')));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $giftInviteEmail = $candidate;
            }
        }

        return Inertia::render('frontend/register/user', [
            'seo' => $seo,
            'referralCode' => $referralCode ?? '',
            'positions' => $positions,
            'lockOrganization' => $lockOrganization,
            'giftInviteEmail' => $giftInviteEmail,
            'giftInviteToken' => $giftInviteToken,
        ]);
    })->name('register.user');

    Route::get('/register/organization', [OrganizationRegisterController::class, 'create'])->name('register.organization');

    Route::post('/register/organization', [OrganizationRegisterController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register.organization.store');

    Route::post('/register/organization/lookup-ein', [OrganizationRegisterController::class, 'lookupEIN'])
        ->middleware('throttle:10,1')
        ->name('register.organization.lookup-ein');

    Route::get('/register/organization/officers-for-ein', [OrganizationRegisterController::class, 'officersForEin'])
        ->middleware('throttle:20,1')
        ->name('register.organization.officers-for-ein');

    Route::get('/register/care-alliance', [CareAllianceRegisterController::class, 'create'])->name('register.care-alliance');
    Route::post('/register/care-alliance', [CareAllianceRegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.care-alliance.store');

    // Route::get('register', [RegisteredUserController::class, 'create'])
    //     ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');

    // Route::get('login-old', [AuthenticatedSessionController::class, 'create'])
    //     ->name('login-old');

    Route::get('/login', function (Request $request) {
        if (is_development_site($request)) {
            return app(DevLoginController::class)->create($request);
        }

        return Inertia::render('frontend/login', [
            'seo' => SeoService::forPage('login'),
            'status' => $request->session()->get('status'),
            'canResetPassword' => Route::has('password.request'),
        ]);
    })->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/purchase-order', [PurchaseOrderController::class, 'purchaseOrder'])->name('purchase.order');
    Route::get('/purchase-success', [PurchaseOrderController::class, 'purchaseSuccess'])->name('purchase.success');
    Route::get('/order-success', [PurchaseOrderController::class, 'orderSuccess'])->name('order.success');
    Route::get('/order-cancel', [PurchaseOrderController::class, 'purchaseCancel'])->name('purchase.cancel');

    // Profile photo routes
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])->name('profile.photo.store');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy'])->name('profile.photo.destroy');
    Route::get('/profile/photo', [ProfilePhotoController::class, 'show'])->name('profile.photo.show');

    Route::post('/profile/cover', [ProfilePhotoController::class, 'updateCover'])->middleware('role:organization|care_alliance')->name('profile.cover');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout.main');
});
