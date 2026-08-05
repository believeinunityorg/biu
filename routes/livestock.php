<?php

use App\Http\Controllers\Livestock\MarketplaceController as LivestockMarketplaceController;
use App\Http\Controllers\Livestock\SellerController as LivestockSellerController;
use App\Http\Controllers\Livestock\AnimalController as LivestockAnimalController;
use App\Http\Controllers\Livestock\BreedingController as LivestockBreedingController;
use App\Http\Controllers\Livestock\HealthRecordController as LivestockHealthRecordController;
use App\Http\Controllers\Livestock\PayoutController as LivestockPayoutController;
use App\Http\Controllers\Livestock\ListingController as LivestockListingController;
use App\Http\Controllers\Livestock\BuyerController as LivestockBuyerController;
use App\Http\Controllers\Admin\LivestockController as AdminLivestockController;
use App\Http\Controllers\Livestock\Auth\LivestockAuthenticatedSessionController;
use App\Http\Controllers\Livestock\Auth\LivestockRegisteredUserController;
use App\Http\Controllers\Livestock\Auth\LivestockPasswordResetLinkController;
use App\Http\Controllers\Livestock\Auth\LivestockNewPasswordController;
use App\Http\Controllers\Livestock\Auth\LivestockEmailVerificationPromptController;
use App\Http\Controllers\Livestock\Auth\LivestockVerifyEmailController;
use App\Http\Controllers\Livestock\Auth\LivestockEmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

// ============================================
// LIVESTOCK MANAGEMENT SYSTEM ROUTES
// ============================================
// These routes are only accessible on bidalivestock.test domain

// Public Marketplace Routes (namespaced so Ziggy/main app keep `marketplace.index` for BIU nonprofit marketplace)
Route::prefix('marketplace')->name('livestock.marketplace.')->group(function () {
    Route::get('/', [LivestockMarketplaceController::class, 'index'])->name('index');
    Route::get('/{id}', [LivestockMarketplaceController::class, 'show'])->name('show');
    
    // Authenticated Purchase
    Route::middleware(['auth:livestock', 'EnsureEmailIsVerified'])->group(function () {
        Route::post('/{id}/checkout', [LivestockMarketplaceController::class, 'checkout'])->name('checkout');
        Route::get('/purchase/success', [LivestockMarketplaceController::class, 'purchaseSuccess'])->name('purchase.success');
        Route::get('/purchase/cancel', [LivestockMarketplaceController::class, 'purchaseCancel'])->name('purchase.cancel');
    });
});

// Home/Index Route - Landing Page
Route::get('/', function () {
    return Inertia::render('Livestock/Landing');
})->name('livestock.home');

// Authenticated Routes
Route::middleware(['auth:livestock', 'EnsureEmailIsVerified'])->group(function () {
    // Seller Routes
    Route::prefix('seller')->name('seller.')->group(function () {
        Route::get('/create', [LivestockSellerController::class, 'create'])->name('create');
        Route::post('/create', [LivestockSellerController::class, 'store'])->name('store');
        Route::get('/dashboard', [LivestockSellerController::class, 'dashboard'])->name('dashboard');
        Route::get('/edit', [LivestockSellerController::class, 'edit'])->name('edit');
        Route::put('/update', [LivestockSellerController::class, 'update'])->name('update');
    });
    
    // Buyer Routes
    Route::prefix('buyer')->name('buyer.')->group(function () {
        Route::get('/dashboard', [LivestockBuyerController::class, 'dashboard'])->name('dashboard');
        Route::get('/edit', [LivestockBuyerController::class, 'edit'])->name('edit');
        Route::put('/update', [LivestockBuyerController::class, 'update'])->name('update');
        Route::get('/animals', [LivestockBuyerController::class, 'animals'])->name('animals');
        Route::get('/payouts', [LivestockBuyerController::class, 'payouts'])->name('payouts');
        Route::post('/payouts/{id}/confirm', [LivestockBuyerController::class, 'confirmPayment'])->name('payouts.confirm');
        
        // Pre-Generated Tags Routes (Buyer Accessible)
        Route::prefix('pre-generated-tags')->name('pre-generated-tags.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PreGeneratedTagController::class, 'index'])->name('index');
            Route::post('/generate', [\App\Http\Controllers\Admin\PreGeneratedTagController::class, 'generate'])->name('generate');
            Route::post('/{id}/assign', [\App\Http\Controllers\Admin\PreGeneratedTagController::class, 'assign'])->name('assign');
            Route::post('/{id}/unassign', [\App\Http\Controllers\Admin\PreGeneratedTagController::class, 'unassign'])->name('unassign');
        });
    });

    // Fractional Listing Routes
    Route::prefix('fractional-listings')->name('fractional-listings.')->group(function () {
        Route::get('/create/{animalId}', [\App\Http\Controllers\Livestock\FractionalListingController::class, 'create'])->name('create');
        Route::post('/store', [\App\Http\Controllers\Livestock\FractionalListingController::class, 'store'])->name('store');
        Route::delete('/{id}', [\App\Http\Controllers\Livestock\FractionalListingController::class, 'destroy'])->name('destroy');
    });
    
    // Animal Management Routes
    Route::prefix('animals')->name('animals.')->group(function () {
        Route::get('/', [LivestockAnimalController::class, 'index'])->name('index');
        Route::get('/create', [LivestockAnimalController::class, 'create'])->name('create');
        Route::get('/purchased', [LivestockAnimalController::class, 'purchased'])->name('purchased');
        Route::post('/', [LivestockAnimalController::class, 'store'])->name('store');
        Route::get('/{id}', [LivestockAnimalController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [LivestockAnimalController::class, 'edit'])->name('edit');
        Route::put('/{id}', [LivestockAnimalController::class, 'update'])->name('update');
        Route::delete('/{id}', [LivestockAnimalController::class, 'destroy'])->name('destroy');
        
        // Listing Routes
        Route::get('/{animalId}/listings/create', [LivestockListingController::class, 'create'])->name('listings.create');
        Route::post('/{animalId}/listings', [LivestockListingController::class, 'store'])->name('listings.store');
        Route::delete('/{animalId}/listings/{id}', [LivestockListingController::class, 'destroy'])->name('listings.destroy');
    });
    
    // Breeding Routes
    Route::prefix('breeding')->name('breeding.')->group(function () {
        Route::get('/', [LivestockBreedingController::class, 'index'])->name('index');
        Route::get('/create', [LivestockBreedingController::class, 'create'])->name('create');
        Route::post('/', [LivestockBreedingController::class, 'store'])->name('store');
        Route::get('/{id}', [LivestockBreedingController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [LivestockBreedingController::class, 'edit'])->name('edit');
        Route::put('/{id}', [LivestockBreedingController::class, 'update'])->name('update');
        Route::delete('/{id}', [LivestockBreedingController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/offspring', [LivestockBreedingController::class, 'createOffspring'])->name('offspring');
        Route::post('/{id}/offspring/single', [LivestockBreedingController::class, 'createSingleOffspring'])->name('offspring.single');
        Route::match(['put', 'post'], '/{breedingId}/offspring/{offspringId}', [LivestockBreedingController::class, 'updateOffspring'])->name('offspring.update');
    });
    
    // Health Records Routes
    Route::prefix('animals/{animalId}/health')->name('health.')->group(function () {
        Route::get('/', [LivestockHealthRecordController::class, 'index'])->name('index');
        Route::get('/create', [LivestockHealthRecordController::class, 'create'])->name('create');
        Route::post('/', [LivestockHealthRecordController::class, 'store'])->name('store');
        Route::get('/{id}', [LivestockHealthRecordController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [LivestockHealthRecordController::class, 'edit'])->name('edit');
        Route::put('/{id}', [LivestockHealthRecordController::class, 'update'])->name('update');
        Route::delete('/{id}', [LivestockHealthRecordController::class, 'destroy'])->name('destroy');
    });
    
    // Payout Routes
    Route::prefix('payouts')->name('payouts.')->group(function () {
        Route::get('/', [LivestockPayoutController::class, 'index'])->name('index');
        Route::get('/{id}', [LivestockPayoutController::class, 'show'])->name('show');
    });
});

// Admin Livestock Routes
Route::middleware(['auth:livestock', 'EnsureEmailIsVerified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminLivestockController::class, 'index'])->name('index');
    Route::get('/sellers', [AdminLivestockController::class, 'sellers'])->name('sellers');
    Route::put('/sellers/{id}/verify', [AdminLivestockController::class, 'verifySeller'])->name('sellers.verify');
    Route::put('/sellers/{id}/reject', [AdminLivestockController::class, 'rejectSeller'])->name('sellers.reject');
    Route::get('/listings', [AdminLivestockController::class, 'listings'])->name('listings');
    Route::delete('/listings/{id}', [AdminLivestockController::class, 'removeListing'])->name('listings.remove');
    Route::get('/payouts', [AdminLivestockController::class, 'payouts'])->name('payouts');
    Route::put('/payouts/{id}/approve', [AdminLivestockController::class, 'approvePayout'])->name('payouts.approve');
});

// Auth Routes (Login/Register) - Custom livestock branded pages
Route::middleware('guest')->group(function () {
    Route::get('/login', [LivestockAuthenticatedSessionController::class, 'create'])->name('livestock.login');

    Route::post('login', [LivestockAuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('/register', function () {
        return Inertia::render('Livestock/Auth/Register');
    })->name('livestock.register');

    Route::post('register', [LivestockRegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('forgot-password', function () {
        return Inertia::render('Livestock/Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    })->name('livestock.password.request');

    Route::post('forgot-password', [LivestockPasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('livestock.password.email');

    Route::get('reset-password/{token}', [LivestockNewPasswordController::class, 'create'])
        ->name('livestock.password.reset');

    Route::post('reset-password', [LivestockNewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('livestock.password.store');
});

Route::middleware('auth:livestock')->group(function () {
    Route::get('verify-email', LivestockEmailVerificationPromptController::class)
        ->name('livestock.verification.notice');

    Route::get('verify-email/{id}/{hash}', LivestockVerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('livestock.verification.verify');

    Route::post('email/verification-notification', [LivestockEmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('livestock.verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('livestock.password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::post('logout', [LivestockAuthenticatedSessionController::class, 'destroy'])
        ->name('logout.livestock');
});

// API Routes for livestock domain
Route::prefix('api')->name('api.')->group(function () {
    Route::get('/countries/search', [\App\Http\Controllers\Api\CountryController::class, 'search'])->name('countries.search');
});

