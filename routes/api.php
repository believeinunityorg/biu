<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\FavoriteMenuController as ApiFavoriteMenuController;
use App\Http\Controllers\Api\OrganizationLookupController;
use App\Http\Controllers\Api\ShippoWebhookController;
use App\Http\Controllers\Api\StreamingStatusCallbackController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\Api\PushNotificationOpenController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/knowledge-base', [KnowledgeBaseController::class, 'index'])->middleware('throttle:60,1');
Route::post('/knowledge-base/learn', [KnowledgeBaseController::class, 'learn'])->middleware('throttle:10,1');
Route::get('/organizations/lookup', [OrganizationLookupController::class, 'lookup'])->middleware('throttle:30,1');

// Better Stack / uptime probes (DB + Redis when configured) — also keep Laravel /up as liveness
Route::get('/health', HealthController::class)->middleware('throttle:60,1');

// Shippo webhook (no auth; Shippo will call it directly)
Route::post('/webhooks/shippo', [ShippoWebhookController::class, 'handle']);
Route::post('/streaming/status', StreamingStatusCallbackController::class)->name('api.streaming.status');

// Authentication routes
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/auth/resend-verification-code', [AuthController::class, 'resendVerificationCode'])->middleware('throttle:5,1');

// Protected routes (require authentication via Passport)
Route::middleware('auth:api')->group(function () {
    // Auth routes that don't require email verification
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/resend-verification-code', [AuthController::class, 'resendVerificationCode']);

    // Protected routes that require email verification - SECURE GUARD
    Route::middleware('api.email.verified')->group(function () {
        // User
        Route::prefix('user')->group(function () {
            Route::get('/profile', [UserController::class, 'getProfile']);
            Route::put('/profile', [UserController::class, 'updateProfile']);
            Route::post('/profile', [UserController::class, 'updateProfile']); // POST for multipart (profile photo)
            Route::put('/organization-profile', [UserController::class, 'updateOrganizationProfile']);
            Route::put('/password', [UserController::class, 'changePassword']);
            Route::get('/security-prefs', [UserController::class, 'getSecurityPrefs']);
            Route::put('/security-prefs', [UserController::class, 'updateSecurityPrefs']);
            Route::get('/sessions', [UserController::class, 'getSessions']);
            Route::post('/sessions/revoke-others', [UserController::class, 'revokeOtherSessions']);
            Route::delete('/sessions/{id}', [UserController::class, 'revokeSession']);
            Route::get('/balance', [UserController::class, 'getBalance']);
            Route::get('/points', [UserController::class, 'getPoints']);
            Route::get('/profile/posts', [UserController::class, 'getProfilePosts']);
            Route::get('/trending-organizations', [UserController::class, 'getTrendingOrganizations']);
            Route::get('/suggested-people', [UserController::class, 'getSuggestedPeople']);
            Route::get('/suggested-causes', [UserController::class, 'getSuggestedCauses']);

            Route::get('/favorite-menus', [ApiFavoriteMenuController::class, 'index']);
            Route::post('/favorite-menus', [ApiFavoriteMenuController::class, 'store']);
            Route::put('/favorite-menus/reorder', [ApiFavoriteMenuController::class, 'reorder']);
            Route::put('/favorite-menus/bottom-nav', [ApiFavoriteMenuController::class, 'syncBottomNav']);
            Route::delete('/favorite-menus/{menuKey}', [ApiFavoriteMenuController::class, 'destroy']);
            Route::get('/menu-items', [ApiFavoriteMenuController::class, 'menuItems']);
        });

        // Wallet
        Route::prefix('wallet')->group(function () {
            Route::get('/balance', [WalletController::class, 'getBalance']);
            Route::post('/deposit', [WalletController::class, 'deposit']);
            Route::post('/withdraw', [WalletController::class, 'withdraw']);
            Route::post('/send', [WalletController::class, 'send']);
            Route::post('/transfer', [WalletController::class, 'transfer']);
        });

        // Transactions
        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'index']);
            Route::get('/{id}', [TransactionController::class, 'show']);
        });

        // Cards
        Route::prefix('cards')->group(function () {
            Route::get('/', [CardController::class, 'index']);
            Route::get('/{id}', [CardController::class, 'show']);
        });

        // KYC
        Route::prefix('kyc')->group(function () {
            Route::get('/status', [KycController::class, 'status']);
            Route::post('/submit', [KycController::class, 'submit']);
        });

        Route::prefix('crypto')->group(function () {
            Route::get('/', function () {
                return response()->json(['message' => 'Crypto API endpoint']);
            });
        });

        Route::prefix('p2p')->group(function () {
            Route::get('/', function () {
                return response()->json(['message' => 'P2P API endpoint']);
            });
        });

        Route::prefix('nft')->group(function () {
            Route::get('/', function () {
                return response()->json(['message' => 'NFT API endpoint']);
            });
        });

        Route::post('/push-notifications/open', [PushNotificationOpenController::class, 'store']);

        Route::post('/push-token', [\App\Http\Controllers\PushTokenController::class, 'store']);
        Route::delete('/push-token', [\App\Http\Controllers\PushTokenController::class, 'destroy']);
    });
});
