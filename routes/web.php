<?php

use App\Http\Controllers\AboutPageController;
use App\Http\Controllers\Admin\AdminChallengeHubController;
use App\Http\Controllers\Admin\AdminKybVerificationController;
use App\Http\Controllers\Admin\AdminKycVerificationController;
use App\Http\Controllers\Admin\BarterAuditController;
use App\Http\Controllers\Admin\BiuFeeSettingsController;
use App\Http\Controllers\Admin\ComplianceApplicationController as AdminComplianceApplicationController;
use App\Http\Controllers\Admin\ContactPageController;
use App\Http\Controllers\Admin\ContactSubmissionController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\EmailPackageController;
use App\Http\Controllers\Admin\ExemptionCertificateController;
use App\Http\Controllers\Admin\FeesController;
use App\Http\Controllers\Admin\Form1023ApplicationController as AdminForm1023ApplicationController;
use App\Http\Controllers\Admin\FractionalAssetController;
use App\Http\Controllers\Admin\FractionalOfferingController;
use App\Http\Controllers\Admin\FractionalOrderController;
use App\Http\Controllers\Admin\FundraiseLeadController;
use App\Http\Controllers\Admin\IrsBoardMemberController;
use App\Http\Controllers\Admin\KioskManagementController;
use App\Http\Controllers\Admin\KioskProviderController;
use App\Http\Controllers\Admin\KioskServiceRequestsController;
use App\Http\Controllers\Admin\KioskSubcategoryController;
use App\Http\Controllers\Admin\LivestockController;
use App\Http\Controllers\Admin\ManualPaymentVerificationController;
use App\Http\Controllers\Admin\MerchantHubCategoryController;
use App\Http\Controllers\Admin\PhazeWebhookManagementController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PreGeneratedTagController;
use App\Http\Controllers\Admin\PrimaryActionCategoryController;
use App\Http\Controllers\Admin\ProcessingFeeSettingsController;
use App\Http\Controllers\Admin\PromotionalBannerController;
use App\Http\Controllers\Admin\PushNotificationLogController;
use App\Http\Controllers\Admin\PushNotificationsController;
use App\Http\Controllers\Admin\RewardPointController;
use App\Http\Controllers\Admin\SeoController as AdminSeoController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceSellerController;
use App\Http\Controllers\Admin\TransactionLedgerController;
use App\Http\Controllers\Admin\WalletFeeController;
use App\Http\Controllers\Admin\WalletPlanController;
use App\Http\Controllers\AdminAboutPageController;
use App\Http\Controllers\AiCampaignController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AiMediaStudioController;
use App\Http\Controllers\Api\PushNotificationOpenController;
use App\Http\Controllers\Barter\BarterNetworkController;
use App\Http\Controllers\BelievePointController;
use App\Http\Controllers\BoardMemberController;
use App\Http\Controllers\BridgeWalletController;
use App\Http\Controllers\BridgeWebhookController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CareAlliance\CareAllianceCampaignManageController;
use App\Http\Controllers\CareAlliance\CareAllianceDashboardController;
use App\Http\Controllers\CareAlliance\CareAllianceDonationController;
use App\Http\Controllers\CareAlliance\CareAllianceInvitationController;
use App\Http\Controllers\CareAlliance\CareAllianceJoinRequestReviewController;
use App\Http\Controllers\CareAlliance\CareAllianceOrgInvitationController;
use App\Http\Controllers\CareAlliance\CareAllianceOrgJoinRequestController;
use App\Http\Controllers\CareAlliance\CareAllianceOrgMembershipController;
use App\Http\Controllers\CareAlliance\CareAlliancePublicController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChallengeLevelUpController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatTopicController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\ClassificationCodeController;
use App\Http\Controllers\CommunityVideoEngagementController;
use App\Http\Controllers\CommunityVideosController;
use App\Http\Controllers\ComplianceApplicationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseUnityMeetController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeductibilityCodeController;
use App\Http\Controllers\DonateWidgetEmbedController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\DonationPaymentController;
use App\Http\Controllers\EmailCreditsController;
use App\Http\Controllers\EmailInviteController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\ExcelDataController;
use App\Http\Controllers\ExploreByCauseController;
use App\Http\Controllers\Facebook\AuthController;
use App\Http\Controllers\Facebook\ConfigurationController;
use App\Http\Controllers\Facebook\PostController;
use App\Http\Controllers\FavoriteMenuController;
use App\Http\Controllers\FindCareAlliancesController;
use App\Http\Controllers\FindSupportersController;
use App\Http\Controllers\FollowerController;
use App\Http\Controllers\Form1023ApplicationController;
use App\Http\Controllers\FractionalCertificateController;
use App\Http\Controllers\FractionalOwnershipController;
use App\Http\Controllers\FrontendCourseController;
use App\Http\Controllers\FundMeCampaignController;
use App\Http\Controllers\FundMeController;
use App\Http\Controllers\FundMeDonationController;
use App\Http\Controllers\FundraiseController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\GovernanceComplianceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpactScoreController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\Internal\DeployRunnerAllowController;
use App\Http\Controllers\InvestController;
use App\Http\Controllers\IrsBmfController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobPositionController;
use App\Http\Controllers\JobPostController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\KioskDashboardController;
use App\Http\Controllers\KioskServiceRequestController;
use App\Http\Controllers\LivestreamRecordingDeclineController;
use App\Http\Controllers\LiveViewController;
use App\Http\Controllers\ManageDataController;
use App\Http\Controllers\ManageDatasetController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\MarketplaceOrganizationProductController;
use App\Http\Controllers\MerchantHubMarketplaceProductController;
use App\Http\Controllers\MerchantHubOfferController;
use App\Http\Controllers\MerchantRedemptionController;
use App\Http\Controllers\MyChatGroupsController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NodeBossController;
use App\Http\Controllers\NodeReferralController;
use App\Http\Controllers\NodeSellController;
use App\Http\Controllers\NodeShareController;
use App\Http\Controllers\NonprofitNewsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NteeCodeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\Organization\LivestreamController;
use App\Http\Controllers\Organization\MarketplaceProductPoolController;
use App\Http\Controllers\Organization\OrganizationKioskProviderController;
use App\Http\Controllers\Organization\OrganizationSupportersController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationPayoutSettingsController;
use App\Http\Controllers\OrganizationPayPalPayoutController;
use App\Http\Controllers\OrganizationStripeConnectController;
use App\Http\Controllers\OwnershipVerificationController;
use App\Http\Controllers\PaymentMethodSettingController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\PhazeWebhookController;
use App\Http\Controllers\PlaidVerificationController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\PositionCategoryController;
use App\Http\Controllers\PrintifyProductController;
use App\Http\Controllers\PrintifyWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProximityLocationController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\PwaInstallController;
use App\Http\Controllers\RaffleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SavedNewsController;
use App\Http\Controllers\ServiceHubController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialMediaController;
use App\Http\Controllers\StatusCodeController;
use App\Http\Controllers\SupporterActivityController;
use App\Http\Controllers\SupporterBelievePointGiftController;
use App\Http\Controllers\SupporterLivestreamController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UnityCallController;
use App\Http\Controllers\UnityLiveController;
use App\Http\Controllers\UnityLiveEngagementController;
use App\Http\Controllers\UnityLoavesController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UsersInterestedTopicsController;
use App\Http\Controllers\VolunteerAvailableSupportersController;
use App\Http\Controllers\VolunteerController;
use App\Http\Controllers\VolunteerSupporterInterestsController;
use App\Http\Controllers\VolunteerTimesheetController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookManagementController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

// ============================================
// MERCHANT DOMAIN ROUTES - MUST BE FIRST
// ============================================
// Load merchant routes only when accessing merchant domain
// This must be before all other routes to take precedence
Route::domain(config('merchant.domain'))->group(function () {
    require __DIR__.'/merchant.php';
});

// ============================================
// LIVESTOCK DOMAIN ROUTES
// ============================================
// Load livestock routes only when accessing livestock domain
Route::domain(config('livestock.domain'))->group(function () {
    require __DIR__.'/livestock.php';
});

Route::post('/internal/deploy/allow-runner-ip', DeployRunnerAllowController::class)
    ->middleware('throttle:10,1')
    ->name('internal.deploy.allow-runner-ip');

// Route::get('/test-broadcast', function () {
//     $message = App\Models\ChatMessage::first();
//     event(new App\Events\MessageSent($message));
//     return "Event fired for message: " . $message;
// });

// ============================================
// MAIN APP ROUTES - Only accessible on main domain (not livestock domain)
// ============================================
// These routes are automatically excluded from livestock domain because
// livestock routes are defined with Route::domain() above.
// Routes without Route::domain() only work on the main domain.
Route::get('/', [HomeController::class, 'index'])->name('home');

// SEO: sitemap and robots
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', function () {
    $url = rtrim(config('app.url'), '/');

    $lines = [
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /dashboard',
        'Disallow: /chat',
        'Disallow: /settings',
        'Disallow: /user-profile',
        'Disallow: /livestreams',
        'Disallow: /checkout',
        'Disallow: /oauth',
        'Disallow: /stripe',
        '',
        "Sitemap: {$url}/sitemap.xml",
    ];

    return response(implode("\n", $lines)."\n", 200, [
        'Content-Type' => 'text/plain',
    ]);
})->name('robots');

// Discover Care Alliances (public — same listing whether or not you’re logged in)
Route::get('/find-care-alliances', [FindCareAlliancesController::class, 'index'])->name('find-care-alliances.index');

// Unity Loaves Network Directory (public)
Route::get('/unity-loaves', [UnityLoavesController::class, 'index'])->name('unity-loaves.index');

// Social Media Feed Routes
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/social-feed', [App\Http\Controllers\PostController::class, 'index'])->name('social-feed.index');
    Route::get('/find-supporters', [FindSupportersController::class, 'index'])->name('find-supporters.index');
    Route::get('/supporters/gift/{recipient}', [SupporterBelievePointGiftController::class, 'showGift'])->name('supporters.gift');
    Route::post('/supporters/gift/{recipient}', [SupporterBelievePointGiftController::class, 'sendGift'])->name('supporters.gift.send');
    Route::get('/supporters/birthday-gift/{celebrant}', [SupporterBelievePointGiftController::class, 'showBirthdayGift'])->name('supporters.birthday-gift');
    Route::post('/supporters/birthday-gift/{celebrant}', [SupporterBelievePointGiftController::class, 'sendBirthdayGift'])->name('supporters.birthday-gift.send');

    // Gift BP: search by name/email, gift existing supporters, or hold + invite unregistered emails
    Route::get('/gift-bp', [\App\Http\Controllers\GiftBelievePointsController::class, 'index'])->name('gift-bp.index');
    Route::get('/gift-bp/search', [\App\Http\Controllers\GiftBelievePointsController::class, 'search'])->name('gift-bp.search');
    Route::post('/gift-bp/send', [\App\Http\Controllers\GiftBelievePointsController::class, 'send'])->name('gift-bp.send');
    Route::post('/gift-bp/gifts/{invite}/claim', [\App\Http\Controllers\GiftBelievePointsController::class, 'claim'])->name('gift-bp.gifts.claim');
    Route::post('/gift-bp/invites/{invite}/cancel', [\App\Http\Controllers\GiftBelievePointsController::class, 'cancel'])->name('gift-bp.invites.cancel');
    Route::post('/gift-bp/invites/{invite}/resend', [\App\Http\Controllers\GiftBelievePointsController::class, 'resend'])->name('gift-bp.invites.resend');
    Route::post('/gift-bp/invites/{invite}/email', [\App\Http\Controllers\GiftBelievePointsController::class, 'updateEmail'])->name('gift-bp.invites.email');
    Route::get('/search', [App\Http\Controllers\PostController::class, 'searchPage'])->name('search.index');
    Route::get('/social-feed/search', [App\Http\Controllers\PostController::class, 'search'])->name('social-feed.search');
    // Toggle favorite organization — supporter accounts only (see User::canFollowOrganizations)
    Route::post('/organizations/{id}/toggle-favorite', [OrganizationController::class, 'toggleFavorite'])->name('organizations.toggle-favorite-search');
    Route::post('/organizations/{id}/toggle-favorite', [OrganizationController::class, 'toggleFavorite'])->name('user.organizations.toggle-favorite');
    Route::post('/organizations/{id}/toggle-favorite', [OrganizationController::class, 'toggleFavorite'])->name('organizations.toggle-favorite');
    Route::post('/organizations/{id}/toggle-notifications', [OrganizationController::class, 'toggleNotifications'])->name('organizations.toggle-notifications');
    // GET fallback: if user hits toggle-favorite with GET (e.g. refresh/back), redirect to organization page
    Route::get('/organizations/{id}/toggle-favorite', function (string $id) {
        return redirect()->route('organizations.show', $id);
    })->name('organizations.toggle-favorite.get');
    Route::post('/posts', [App\Http\Controllers\PostController::class, 'store'])->name('posts.store');
    Route::post('/posts/share-from-video-hub', [App\Http\Controllers\PostController::class, 'shareVideoFromHub'])->name('posts.share-from-video-hub');
    Route::put('/posts/{post}', [App\Http\Controllers\PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [App\Http\Controllers\PostController::class, 'destroy'])->name('posts.destroy');
    Route::post('/posts/{post}/react', [App\Http\Controllers\PostController::class, 'react'])->name('posts.react');
    Route::delete('/posts/{post}/reaction', [App\Http\Controllers\PostController::class, 'removeReaction'])->name('posts.remove-reaction');
    Route::post('/posts/{post}/comment', [App\Http\Controllers\PostController::class, 'comment'])->name('posts.comment');
    Route::get('/posts/{post}/comments', [App\Http\Controllers\PostController::class, 'getComments'])->name('posts.comments');
    Route::post('/posts/{post}/seen', [App\Http\Controllers\PostController::class, 'markAsSeen'])->name('posts.mark-seen');
    Route::delete('/comments/{comment}', [App\Http\Controllers\PostController::class, 'deleteComment'])->name('comments.destroy');
});

Route::permanentRedirect('/level-up', '/challenge-hub');
Route::permanentRedirect('/level-up/{track}', '/challenge-hub/{track}');

Route::middleware(['auth', 'EnsureEmailIsVerified'])->prefix('challenge-hub')->name('challenge-hub.')->group(function () {
    Route::get('/', [ChallengeLevelUpController::class, 'index'])->name('index');
    Route::get('/{track:slug}/play/{challenge?}', [ChallengeLevelUpController::class, 'play'])->name('play');
    Route::get('/{track:slug}', [ChallengeLevelUpController::class, 'challenges'])->name('challenges');
    Route::post('/{track:slug}/next', [ChallengeLevelUpController::class, 'next'])
        ->middleware('throttle:45,1')
        ->name('next');
    Route::post('/{track:slug}/answer', [ChallengeLevelUpController::class, 'answer'])
        ->middleware('throttle:90,1')
        ->name('answer');
    Route::post('/{track:slug}/finish', [ChallengeLevelUpController::class, 'finish'])
        ->middleware('throttle:45,1')
        ->name('finish');
    /** GET refresh fallback for POST-only quiz routes (avoids Method Not Allowed on reload). */
    Route::get('/{track:slug}/next', [ChallengeLevelUpController::class, 'restorePlayFromGet'])->name('next.get');
    Route::get('/{track:slug}/answer', [ChallengeLevelUpController::class, 'restorePlayFromGet'])->name('answer.get');
    Route::get('/{track:slug}/finish', [ChallengeLevelUpController::class, 'restorePlayFromGet'])->name('finish.get');
});

Route::get('pwa-setup', function () {
    return Inertia::render('pwa-setup/page');
})->name('pwa.install');
Route::get('/pwa/install-qr', [PwaInstallController::class, 'installQr'])->name('pwa.install-qr');
Route::get('/pwa/version.json', [\App\Http\Controllers\PwaVersionController::class, 'show'])->name('pwa.version');

Route::get('/about', AboutPageController::class)->name('about');

Route::get('/privacy-policy', function () {
    return Inertia::render('frontend/PrivacyPolicy');
})->name('privacy.policy');

Route::get('/data-deletion', function () {
    return Inertia::render('frontend/DataDeletion');
})->name('data.deletion');

Route::get('/terms-of-service', function () {
    return Inertia::render('frontend/TermsOfService');
})->name('terms.service');

Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('/kiosk', [KioskController::class, 'index'])->name('kiosk.index');
Route::get('/kiosk/services', [KioskController::class, 'services'])->name('kiosk.services');
Route::post('/kiosk/services/geo', [KioskController::class, 'updateServicesGeo'])->name('kiosk.services.geo');
Route::middleware('auth')->group(function () {
    Route::post('/kiosk/service-requests', [KioskServiceRequestController::class, 'store'])->name('kiosk.service-requests.store');
});
Route::patch('/kiosk/service-requests/{serviceRequest}/link', [KioskServiceRequestController::class, 'updateLink'])->name('kiosk.service-requests.update-link');

Route::middleware(['auth', 'EnsureEmailIsVerified'])->prefix('kiosk/dashboard')->name('kiosk.dashboard.')->group(function () {
    Route::get('/', [KioskDashboardController::class, 'index'])->name('index');
    Route::get('/{category_slug}', [KioskDashboardController::class, 'show'])->name('show');
    Route::post('/log-action', [KioskDashboardController::class, 'logAction'])->name('log-action');
    Route::patch('/context', [KioskDashboardController::class, 'updateContext'])->name('update-context');
});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:admin'])->group(function () {
    Route::get('/admin/about', [AdminAboutPageController::class, 'edit'])->name('admin.about.edit');
    Route::put('/admin/about', [AdminAboutPageController::class, 'update'])->name('admin.about.update');
    Route::get('/admin/seo', [AdminSeoController::class, 'index'])->name('admin.seo.index');
    Route::put('/admin/seo', [AdminSeoController::class, 'update'])->name('admin.seo.update');
    Route::get('/admin/processing-fees', [ProcessingFeeSettingsController::class, 'index'])->name('admin.processing-fees.index');
    Route::put('/admin/processing-fees', [ProcessingFeeSettingsController::class, 'update'])->name('admin.processing-fees.update');
    Route::get('/admin/biu-fee', [BiuFeeSettingsController::class, 'index'])->name('admin.biu-fee.index');
    Route::put('/admin/biu-fee', [BiuFeeSettingsController::class, 'update'])->name('admin.biu-fee.update');
    Route::get('/admin/gift-card-revenue', [\App\Http\Controllers\Admin\GiftCardRevenueShareController::class, 'index'])->name('admin.gift-card-revenue.index');
    Route::get('/admin/gift-card-redemptions', [\App\Http\Controllers\Admin\GiftCardRedemptionController::class, 'index'])->name('admin.gift-card-redemptions.index');
    Route::post('/admin/gift-card-redemptions/{giftCard}/retry', [\App\Http\Controllers\Admin\GiftCardRedemptionController::class, 'retry'])->name('admin.gift-card-redemptions.retry');
    Route::post('/admin/gift-card-redemptions/{giftCard}/force-fulfill', [\App\Http\Controllers\Admin\GiftCardRedemptionController::class, 'forceFulfill'])->name('admin.gift-card-redemptions.force-fulfill');
    Route::get('/admin/phaze-balance', [\App\Http\Controllers\Admin\PhazeBalanceController::class, 'index'])->name('admin.phaze-balance.index');
    Route::post('/admin/phaze-balance/top-up', [\App\Http\Controllers\Admin\PhazeBalanceController::class, 'topUp'])->name('admin.phaze-balance.top-up');

    Route::redirect('/admin/challenge-hub', '/admin/challenge-hub/categories', 302)->name('admin.challenge-hub.index');
    Route::get('/admin/challenge-hub/categories', [AdminChallengeHubController::class, 'categoriesIndex'])->name('admin.challenge-hub.categories.index');
    Route::get('/admin/challenge-hub/categories/create', [AdminChallengeHubController::class, 'createCategory'])->name('admin.challenge-hub.categories.create');
    Route::post('/admin/challenge-hub/categories', [AdminChallengeHubController::class, 'storeCategory'])->name('admin.challenge-hub.categories.store');
    Route::get('/admin/challenge-hub/subcategories', [AdminChallengeHubController::class, 'subcategoriesIndex'])->name('admin.challenge-hub.subcategories.index');
    Route::get('/admin/challenge-hub/subcategories/create', [AdminChallengeHubController::class, 'createSubcategory'])->name('admin.challenge-hub.subcategories.create');
    Route::post('/admin/challenge-hub/subcategories', [AdminChallengeHubController::class, 'storeSubcategory'])->name('admin.challenge-hub.subcategories.store');
    Route::get('/admin/challenge-hub/subcategories/{subcategory}/edit', [AdminChallengeHubController::class, 'editSubcategory'])->name('admin.challenge-hub.subcategories.edit');
    Route::put('/admin/challenge-hub/subcategories/{subcategory}', [AdminChallengeHubController::class, 'updateSubcategory'])->name('admin.challenge-hub.subcategories.update');
    Route::delete('/admin/challenge-hub/subcategories/{subcategory}', [AdminChallengeHubController::class, 'destroySubcategory'])->name('admin.challenge-hub.subcategories.destroy');
    Route::delete('/admin/challenge-hub/categories/{category:slug}', [AdminChallengeHubController::class, 'destroyCategory'])->name('admin.challenge-hub.categories.destroy');
    Route::get('/admin/challenge-hub/tracks', [AdminChallengeHubController::class, 'tracksIndex'])->name('admin.challenge-hub.tracks.index');
    Route::get('/admin/challenge-hub/tracks/create', [AdminChallengeHubController::class, 'createTrack'])->name('admin.challenge-hub.tracks.create');
    Route::post('/admin/challenge-hub/tracks', [AdminChallengeHubController::class, 'storeTrack'])->name('admin.challenge-hub.tracks.store');
    Route::get('/admin/challenge-hub/questions', [AdminChallengeHubController::class, 'questionsIndex'])->name('admin.challenge-hub.questions.index');
    // CSV / Excel import temporarily disabled — uncomment routes + controller methods + QuestionsBank UI to restore.
    // Route::post('/admin/challenge-hub/questions/import', [AdminChallengeHubController::class, 'importQuestions'])->name('admin.challenge-hub.questions.import');
    // Route::get('/admin/challenge-hub/questions/import-template', [AdminChallengeHubController::class, 'downloadQuestionImportTemplate'])->name('admin.challenge-hub.questions.import-template');
    Route::get('/admin/challenge-hub/questions/create', [AdminChallengeHubController::class, 'createQuestion'])->name('admin.challenge-hub.questions.create');
    Route::post('/admin/challenge-hub/questions', [AdminChallengeHubController::class, 'storeQuestion'])->name('admin.challenge-hub.questions.store');
    Route::get('/admin/challenge-hub/questions/{question}/edit', [AdminChallengeHubController::class, 'editQuestion'])->name('admin.challenge-hub.questions.edit');
    Route::put('/admin/challenge-hub/questions/{question}', [AdminChallengeHubController::class, 'updateQuestion'])->name('admin.challenge-hub.questions.update');
    Route::delete('/admin/challenge-hub/questions/{question}', [AdminChallengeHubController::class, 'destroyQuestion'])->name('admin.challenge-hub.questions.destroy');
    Route::get('/admin/challenge-hub/tracks/{track:slug}/edit', [AdminChallengeHubController::class, 'editTrack'])->name('admin.challenge-hub.tracks.edit');
    Route::delete('/admin/challenge-hub/tracks/{track:slug}', [AdminChallengeHubController::class, 'destroyTrack'])->name('admin.challenge-hub.tracks.destroy');
    Route::put('/admin/challenge-hub/tracks/{track:slug}', [AdminChallengeHubController::class, 'updateTrack'])->name('admin.challenge-hub.tracks.update');
    Route::post('/admin/challenge-hub/tracks/{track:slug}/generate-cover', [AdminChallengeHubController::class, 'generateTrackCover'])->name('admin.challenge-hub.tracks.generate-cover');
    Route::post('/admin/challenge-hub/tracks/{track:slug}/upload-cover', [AdminChallengeHubController::class, 'uploadTrackCover'])->name('admin.challenge-hub.tracks.upload-cover');
    Route::post('/admin/challenge-hub/tracks/{track:slug}/entries', [AdminChallengeHubController::class, 'storeEntry'])->name('admin.challenge-hub.tracks.entries.store');
    Route::put('/admin/challenge-hub/entries/{entry}', [AdminChallengeHubController::class, 'updateEntry'])->name('admin.challenge-hub.entries.update');
    Route::delete('/admin/challenge-hub/entries/{entry}', [AdminChallengeHubController::class, 'destroyEntry'])->name('admin.challenge-hub.entries.destroy');
    Route::post('/admin/challenge-hub/entries/{entry}/generate-cover', [AdminChallengeHubController::class, 'generateEntryCover'])->name('admin.challenge-hub.entries.generate-cover');
    Route::post('/admin/challenge-hub/entries/{entry}/upload-cover', [AdminChallengeHubController::class, 'uploadEntryCover'])->name('admin.challenge-hub.entries.upload-cover');
    Route::get('/admin/challenge-hub/challenges', [AdminChallengeHubController::class, 'challengesIndex'])->name('admin.challenge-hub.challenges.index');
    Route::get('/admin/challenge-hub/challenges/create', [AdminChallengeHubController::class, 'createChallenge'])->name('admin.challenge-hub.challenges.create');
    Route::post('/admin/challenge-hub/challenges', [AdminChallengeHubController::class, 'storeChallenge'])->name('admin.challenge-hub.challenges.store');
    Route::get('/admin/challenge-hub/challenges/{entry}/edit', [AdminChallengeHubController::class, 'editChallenge'])->name('admin.challenge-hub.challenges.edit');
    Route::get('/admin/challenge-hub/categories/{category:slug}/edit', [AdminChallengeHubController::class, 'editCategory'])->name('admin.challenge-hub.categories.edit');
    Route::put('/admin/challenge-hub/categories/{category:slug}', [AdminChallengeHubController::class, 'updateCategory'])->name('admin.challenge-hub.categories.update');
    Route::post('/admin/challenge-hub/categories/{category:slug}/generate-cover', [AdminChallengeHubController::class, 'generateCategoryCover'])->name('admin.challenge-hub.categories.generate-cover');
    Route::post('/admin/challenge-hub/categories/{category:slug}/upload-cover', [AdminChallengeHubController::class, 'uploadCategoryCover'])->name('admin.challenge-hub.categories.upload-cover');
    Route::redirect('/admin/donation-fees', '/admin/processing-fees', 301);
    Route::put('/admin/donation-fees', [ProcessingFeeSettingsController::class, 'update'])->name('admin.donation-fees.update');
    Route::get('/admin/kiosk', [KioskManagementController::class, 'index'])->name('admin.kiosk.index');
    Route::put('/admin/kiosk/hero', [KioskManagementController::class, 'updateHero'])->name('admin.kiosk.update-hero');
    Route::get('/admin/kiosk/categories/create', [KioskManagementController::class, 'create'])->name('admin.kiosk.categories.create');
    Route::post('/admin/kiosk/categories', [KioskManagementController::class, 'store'])->name('admin.kiosk.categories.store');
    Route::put('/admin/kiosk/categories/{kiosk}', [KioskManagementController::class, 'updateCategory'])->name('admin.kiosk.update-category');
    Route::get('/admin/kiosk/categories/{kiosk}/edit', [KioskManagementController::class, 'edit'])->name('admin.kiosk.categories.edit');
    Route::patch('/admin/kiosk/categories/{kiosk}/toggle-active', [KioskManagementController::class, 'toggleActive'])->name('admin.kiosk.toggle-active');
    Route::delete('/admin/kiosk/categories/{kiosk}', [KioskManagementController::class, 'destroy'])->name('admin.kiosk.destroy');

    Route::get('/admin/kiosk/requests', [KioskServiceRequestsController::class, 'index'])->name('admin.kiosk.requests.index');
    // More specific route first so {id} never competes with the static "edit" segment
    Route::get('/admin/kiosk/requests/{id}/edit', [KioskServiceRequestsController::class, 'edit'])->whereNumber('id')->name('admin.kiosk.requests.edit');
    Route::get('/admin/kiosk/requests/{id}', [KioskServiceRequestsController::class, 'show'])->whereNumber('id')->name('admin.kiosk.requests.show');
    Route::put('/admin/kiosk/requests/{id}', [KioskServiceRequestsController::class, 'update'])->whereNumber('id')->name('admin.kiosk.requests.update');
    Route::patch('/admin/kiosk/requests/{id}/status', [KioskServiceRequestsController::class, 'updateStatus'])->whereNumber('id')->name('admin.kiosk.requests.update-status');
    Route::delete('/admin/kiosk/requests/{id}', [KioskServiceRequestsController::class, 'destroy'])->whereNumber('id')->name('admin.kiosk.requests.destroy');

    Route::get('/admin/kiosk/subcategories', [KioskSubcategoryController::class, 'index'])->name('admin.kiosk.subcategories.index');
    Route::get('/admin/kiosk/subcategories/create', [KioskSubcategoryController::class, 'create'])->name('admin.kiosk.subcategories.create');
    Route::post('/admin/kiosk/subcategories', [KioskSubcategoryController::class, 'store'])->name('admin.kiosk.subcategories.store');
    Route::get('/admin/kiosk/subcategories/{subcategory}/edit', [KioskSubcategoryController::class, 'edit'])->name('admin.kiosk.subcategories.edit');
    Route::put('/admin/kiosk/subcategories/{subcategory}', [KioskSubcategoryController::class, 'update'])->name('admin.kiosk.subcategories.update');
    Route::delete('/admin/kiosk/subcategories/{subcategory}', [KioskSubcategoryController::class, 'destroy'])->name('admin.kiosk.subcategories.destroy');

    Route::get('/admin/kiosk/providers', [KioskProviderController::class, 'index'])->name('admin.kiosk.providers.index');
    Route::get('/admin/kiosk/providers/create', [KioskProviderController::class, 'create'])->name('admin.kiosk.providers.create');
    Route::post('/admin/kiosk/providers', [KioskProviderController::class, 'store'])->name('admin.kiosk.providers.store');
    Route::get('/admin/kiosk/providers/{kioskProvider}/edit', [KioskProviderController::class, 'edit'])->name('admin.kiosk.providers.edit');
    Route::put('/admin/kiosk/providers/{kioskProvider}', [KioskProviderController::class, 'update'])->name('admin.kiosk.providers.update');
    Route::delete('/admin/kiosk/providers/{kioskProvider}', [KioskProviderController::class, 'destroy'])->name('admin.kiosk.providers.destroy');
});

Route::get('/nonprofit-news', [NonprofitNewsController::class, 'index'])
    ->name('nonprofit.news');
Route::get('/nonprofit-news/saved', [SavedNewsController::class, 'index'])
    ->name('nonprofit.news.saved')
    ->middleware('auth');
Route::post('/nonprofit-news/save/{article}', [SavedNewsController::class, 'toggle'])
    ->name('nonprofit.news.save.toggle')
    ->middleware('auth');

Route::get('/unity-videos', [CommunityVideosController::class, 'index'])->name('unity-videos.index');
Route::get('/unity-videos/feed', [CommunityVideosController::class, 'feed'])->name('unity-videos.feed');
Route::get('/unity-videos/organizations', [CommunityVideosController::class, 'organizations'])->name('unity-videos.organizations');
Route::get('/unity-videos/supporters', [CommunityVideosController::class, 'supporters'])->name('unity-videos.supporters');
Route::get('/unity-videos/channel/{slug}', [CommunityVideosController::class, 'channel'])->name('unity-videos.channel');
Route::get('/unity-videos/upload', [CommunityVideosController::class, 'upload'])->name('unity-videos.upload')->middleware('auth');
Route::post('/unity-videos/import', [CommunityVideosController::class, 'importFromUrl'])->name('unity-videos.import')->middleware('auth');
Route::delete('/unity-videos/import/{communityVideo}', [CommunityVideosController::class, 'destroyImport'])->name('unity-videos.import.destroy')->middleware('auth');
// More specific route first so /watch/yt/{id} is not matched by /watch/{slug}
Route::get('/unity-videos/watch/yt/{id}', [CommunityVideosController::class, 'showYouTube'])->name('unity-videos.show-youtube');
Route::get('/unity-videos/shorts/yt/{id}', [CommunityVideosController::class, 'showShort'])->name('unity-videos.show-short');
Route::get('/unity-videos/watch/{slug}', [CommunityVideosController::class, 'show'])->name('unity-videos.show');

Route::post('/unity-videos/engagement/like', [CommunityVideoEngagementController::class, 'like'])->name('unity-videos.engagement.like')->middleware('auth');
Route::post('/unity-videos/engagement/view', [CommunityVideoEngagementController::class, 'view'])->name('unity-videos.engagement.view')->middleware('auth');
Route::post('/unity-videos/engagement/share', [CommunityVideoEngagementController::class, 'share'])->name('unity-videos.engagement.share');
Route::get('/unity-videos/engagement/comments', [CommunityVideoEngagementController::class, 'comments'])->name('unity-videos.engagement.comments');
Route::post('/unity-videos/engagement/comments', [CommunityVideoEngagementController::class, 'comment'])->name('unity-videos.engagement.comment')->middleware('auth');

Route::get('/unity-live', [UnityLiveController::class, 'index'])->name('unity-live.index');
Route::get('/unity-live/{slug}', [UnityLiveController::class, 'show'])->name('unity-live.show')->where('slug', '[a-zA-Z0-9_-]+');
Route::get('/unity-live/{slug}/stats', [UnityLiveEngagementController::class, 'stats'])->name('unity-live.stats')->where('slug', '[a-zA-Z0-9_-]+');
Route::post('/unity-live/{slug}/viewer/join', [UnityLiveEngagementController::class, 'viewerJoin'])->name('unity-live.viewer.join')->where('slug', '[a-zA-Z0-9_-]+');
Route::post('/unity-live/{slug}/viewer/heartbeat', [UnityLiveEngagementController::class, 'viewerHeartbeat'])->name('unity-live.viewer.heartbeat')->where('slug', '[a-zA-Z0-9_-]+');
Route::post('/unity-live/{slug}/viewer/leave', [UnityLiveEngagementController::class, 'viewerLeave'])->name('unity-live.viewer.leave')->where('slug', '[a-zA-Z0-9_-]+');
Route::get('/unity-live/{slug}/chat', [UnityLiveEngagementController::class, 'chatIndex'])->name('unity-live.chat.index')->where('slug', '[a-zA-Z0-9_-]+');
Route::post('/unity-live/{slug}/chat', [UnityLiveEngagementController::class, 'chatStore'])->name('unity-live.chat.store')->where('slug', '[a-zA-Z0-9_-]+');

// Unity Meet (supporter UI): personal meetings — also available to org / care alliance accounts from dashboard Tools
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:user|organization|organization_pending|care_alliance'])->group(function () {
    Route::get('/livestreams/supporter', [SupporterLivestreamController::class, 'index'])->name('livestreams.supporter.index');
    Route::get('/livestreams/supporter/live', [SupporterLivestreamController::class, 'live'])->name('livestreams.supporter.live');
    Route::get('/livestreams/supporter/settings', [SupporterLivestreamController::class, 'settings'])->name('livestreams.supporter.settings');
    Route::get('/livestreams/supporter/overlay-studio', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterIndex'])->name('livestreams.supporter.overlay-studio');
    Route::patch('/livestreams/supporter/overlay-studio', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterUpdate'])->name('livestreams.supporter.overlay-studio.update');
    Route::post('/livestreams/supporter/overlay-studio/logo', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterUploadLogo'])->name('livestreams.supporter.overlay-studio.upload-logo');
    Route::post('/livestreams/supporter/overlay-studio/sponsor', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterUploadSponsor'])->name('livestreams.supporter.overlay-studio.upload-sponsor');
    Route::post('/livestreams/supporter/overlay-studio/qr', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterUploadQr'])->name('livestreams.supporter.overlay-studio.upload-qr');
    Route::delete('/livestreams/supporter/overlay-studio/asset', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'supporterRemoveAsset'])->name('livestreams.supporter.overlay-studio.remove-asset');
    Route::get('/livestreams/supporter/recordings/search', [SupporterLivestreamController::class, 'recordingsSearch'])->name('livestreams.supporter.recordings.search');
    Route::get('/livestreams/supporter/recordings/download', [SupporterLivestreamController::class, 'recordingDownload'])->name('livestreams.supporter.recordings.download');
    Route::get('/livestreams/supporter/recordings/branded-download', [SupporterLivestreamController::class, 'recordingBrandedDownload'])->name('livestreams.supporter.recordings.branded-download');
    Route::delete('/livestreams/supporter/recordings/file', [SupporterLivestreamController::class, 'recordingDelete'])->name('livestreams.supporter.recordings.file.delete');
    Route::put('/livestreams/supporter/recordings/file', [SupporterLivestreamController::class, 'recordingRename'])->name('livestreams.supporter.recordings.file.rename');
    Route::post('/livestreams/supporter/recordings/youtube', [SupporterLivestreamController::class, 'recordingPublishToYoutube'])->name('livestreams.supporter.recordings.youtube.publish');
    Route::get('/livestreams/supporter/recordings', [SupporterLivestreamController::class, 'recordings'])->name('livestreams.supporter.recordings');
    Route::get('/livestreams/supporter/create', [SupporterLivestreamController::class, 'create'])->name('livestreams.supporter.create');
    Route::post('/livestreams/supporter', [SupporterLivestreamController::class, 'store'])->name('livestreams.supporter.store');
    Route::post('/livestreams/supporter/schedule', [SupporterLivestreamController::class, 'schedule'])->name('livestreams.supporter.schedule');
    Route::get('/livestreams/supporter/ready/{id}', [SupporterLivestreamController::class, 'ready'])->name('livestreams.supporter.ready')->where('id', '[0-9]+');
    Route::get('/unity-meet/host/user/{id}', [SupporterLivestreamController::class, 'ready'])->name('unity-meet.host.user')->where('id', '[0-9]+');
    Route::get('/livestreams/supporter/invite-recipients', [SupporterLivestreamController::class, 'searchInviteRecipients'])->name('livestreams.supporter.invite-recipients');
    Route::get('/livestreams/supporter/join', [SupporterLivestreamController::class, 'joinPage'])->name('livestreams.supporter.join');
    Route::post('/livestreams/supporter/join', [SupporterLivestreamController::class, 'joinWithPasscode'])->name('livestreams.supporter.join.submit');
    Route::get('/livestreams/supporter/{id}/edit', [SupporterLivestreamController::class, 'edit'])->name('livestreams.supporter.edit')->where('id', '[0-9]+');
    Route::put('/livestreams/supporter/{id}', [SupporterLivestreamController::class, 'update'])->name('livestreams.supporter.update')->where('id', '[0-9]+');
    Route::delete('/livestreams/supporter/{id}', [SupporterLivestreamController::class, 'destroy'])->name('livestreams.supporter.destroy')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/start-meeting', [SupporterLivestreamController::class, 'startMeeting'])->name('livestreams.supporter.start-meeting')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/end-meeting', [SupporterLivestreamController::class, 'endMeeting'])->name('livestreams.supporter.end-meeting')->where('id', '[0-9]+');
    Route::get('/livestreams/supporter/{id}/participant-roster', [SupporterLivestreamController::class, 'participantRosterJson'])->name('livestreams.supporter.participant-roster')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/set-live', [SupporterLivestreamController::class, 'setLive'])->name('livestreams.supporter.set-live')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/end-unity-live', [SupporterLivestreamController::class, 'endUnityLive'])->name('livestreams.supporter.end-unity-live')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/end-stream', [SupporterLivestreamController::class, 'endStream'])->name('livestreams.supporter.end-stream')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/abandon-host-session', [SupporterLivestreamController::class, 'abandonHostSession'])->name('livestreams.supporter.abandon-host-session')->where('id', '[0-9]+');
    Route::patch('/livestreams/supporter/{id}/visibility', [SupporterLivestreamController::class, 'updateVisibility'])->name('livestreams.supporter.update-visibility')->where('id', '[0-9]+');
    Route::patch('/livestreams/supporter/{id}/stream-key', [SupporterLivestreamController::class, 'updateStreamKey'])->name('livestreams.supporter.update-stream-key')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/prepare-youtube-live', [SupporterLivestreamController::class, 'prepareYouTubeLive'])->name('livestreams.supporter.prepare-youtube-live')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/queue-stream-relay', [SupporterLivestreamController::class, 'queueStreamRelayJob'])->name('livestreams.supporter.queue-stream-relay')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/go-live-obs-auto', [SupporterLivestreamController::class, 'queueStreamRelayJob'])->name('livestreams.supporter.go-live-obs-auto')->where('id', '[0-9]+');
    Route::delete('/livestreams/supporter/{id}/participants', [SupporterLivestreamController::class, 'removeParticipant'])->name('livestreams.supporter.participants.remove')->where('id', '[0-9]+');
    Route::post('/livestreams/supporter/{id}/participants', [SupporterLivestreamController::class, 'inviteParticipant'])->name('livestreams.supporter.participants.invite')->where('id', '[0-9]+');
    Route::get('/livestreams/supporter/{id}', [SupporterLivestreamController::class, 'show'])->name('livestreams.supporter.show')->where('id', '[0-9]+');
});

// Email credits — supporters + org accounts (Unity Meet invitations, etc.)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:user|organization|organization_pending|care_alliance'])
    ->prefix('email-credits')
    ->name('email-credits.')
    ->group(function () {
        Route::get('/', [EmailCreditsController::class, 'index'])->name('index');
        Route::post('/purchase', [EmailCreditsController::class, 'purchase'])->name('purchase');
        Route::get('/purchase/success', [EmailCreditsController::class, 'purchaseSuccess'])->name('purchase.success');
    });

// VDO.Ninja meeting: guest join by secure token (public)
Route::get('/join/{token}', [LivestreamController::class, 'guestJoinByToken'])->name('livestreams.guest-join-by-token')->where('token', '[a-zA-Z0-9_-]+');
// Viewer page: /live/{slug} — view-only with Mute + Volume (public, when stream is live)
Route::get('/live/{slug}', [LiveViewController::class, 'show'])->name('live.show')->where('slug', '[a-zA-Z0-9_-]+');

Route::get('/jobs', [JobsController::class, 'index'])->name('jobs.index');
Route::get('/volunteer-opportunities', [JobsController::class, 'volunteerOpportunities'])->name('volunteer-opportunities.index');
Route::post('/volunteer-opportunities/volunteer-interests', [JobsController::class, 'saveVolunteerInterestStatement'])
    ->name('volunteer-opportunities.save-interests')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:user']);
Route::post('/volunteer-opportunities/saved-positions', [JobsController::class, 'saveVolunteerPreferredPositions'])
    ->name('volunteer-opportunities.save-positions')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:user']);
Route::get('/jobs/{id}', [JobsController::class, 'show'])->name('jobs.show');
Route::get('/get-job-positions', [JobsController::class, 'getJobPositions'])->name('jobs.positions.by-category');

Route::get('/jobs/{id}/apply', [JobsController::class, 'applyShow'])->name('jobs.apply.show');
Route::post('/jobs/{id}/apply', [JobsController::class, 'applyStore'])->name('jobs.apply.store');

Route::get('/nodeboss', [NodeBossController::class, 'frontendIndex'])->name('nodeboss.index');

Route::get('/nodeboss/{id}/buy', [NodeBossController::class, 'frontendShow'])->name('buy.nodeboss');

Route::get('/donate', [DonationController::class, 'index'])->name('donate');
Route::get('/donate/organization-context', [DonationController::class, 'organizationDonationContext'])
    ->name('donations.organization-context');
Route::redirect('/donation', '/donate', 302);

Route::get('/explore-by-cause', [ExploreByCauseController::class, 'index'])->name('explore-by-cause.index');
Route::post('/explore-by-cause/toggle-interest/{category}', [ExploreByCauseController::class, 'toggleUserInterest'])
    ->middleware(['auth', 'EnsureEmailIsVerified'])
    ->name('explore-by-cause.toggle-interest');

// Public short URL: group chats are served from /chat (auth + topics). Explore-by-cause links here as "Join Group".
Route::redirect('/groups', '/chat', 302)->name('groups');

// Care Alliance — public campaign donation + preview (no auth)
Route::get('/care-alliance/{allianceSlug}/campaigns/{campaign}/donate', [CareAllianceDonationController::class, 'donatePage'])
    ->name('care-alliance.campaigns.donate')
    ->where('campaign', '[a-zA-Z0-9][a-zA-Z0-9-]*');
Route::post('/care-alliance/{allianceSlug}/campaigns/{campaign}/preview', [CareAllianceDonationController::class, 'preview'])
    ->name('care-alliance.campaigns.preview')
    ->where('campaign', '[a-zA-Z0-9][a-zA-Z0-9-]*');

// Care Alliance — public hub (must stay before any conflicting /care-alliance/* catch-alls)
Route::prefix('alliances/{allianceSlug}')
    ->where(['allianceSlug' => '[a-zA-Z0-9][a-zA-Z0-9-]*'])
    ->group(function () {
        Route::get('/', [CareAlliancePublicController::class, 'show'])->name('alliances.show');
        Route::get('/products', [CareAlliancePublicController::class, 'products'])->name('alliances.products');
        Route::get('/jobs', [CareAlliancePublicController::class, 'jobs'])->name('alliances.jobs');
        Route::get('/events', [CareAlliancePublicController::class, 'events'])->name('alliances.events');
        Route::get('/about', [CareAlliancePublicController::class, 'about'])->name('alliances.about');
        Route::get('/contact', [CareAlliancePublicController::class, 'contact'])->name('alliances.contact');
        Route::get('/members', [CareAlliancePublicController::class, 'members'])->name('alliances.members');
        Route::get('/supporters', [CareAlliancePublicController::class, 'supporters'])->name('alliances.supporters');
    });

Route::get('/pricing', [PlansController::class, 'pricing'])->name('pricing');

// Support a Project — public landing: Give (FundMe) or Grow (Invest / Wefunder)
Route::get('/support-a-project', [FundraiseController::class, 'supportAProject'])->name('support-a-project');

// Public branded funnel: explain → qualify form → redirect to Wefunder (lead capture)
Route::get('/fundraise', [FundraiseController::class, 'index'])->name('fundraise');
Route::post('/fundraise', [FundraiseController::class, 'store'])->name('fundraise.store');
// Project applications requested (authenticated supporters can view)
Route::get('/fundraise/applications', [FundraiseController::class, 'projectApplications'])->name('fundraise.applications')
    ->middleware(['auth']);
// Invest redirect: log click then redirect to Wefunder project URL (auth optional so we can track guest clicks too)
Route::get('/invest/redirect/{lead}', [InvestController::class, 'redirect'])->name('invest.redirect')
    ->where('lead', '[0-9]+');
// Org-only: Support Community Projects (Donation vs Investment / Wefunder)
Route::get('/fundraise/community-projects', [FundraiseController::class, 'communityProjects'])->name('fundraise.community-projects')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|organization_pending|care_alliance']);

// Believe FundMe – public listing and campaign pages
Route::get('/believe-fundme', [FundMeController::class, 'index'])->name('fundme.index');
// Thank-you route must come before {slug} to avoid route conflict
Route::get('/believe-fundme/thank-you', [FundMeDonationController::class, 'thankYou'])->name('fundme.thank-you')
    ->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/believe-fundme/donate', [FundMeDonationController::class, 'store'])->name('fundme.donate.store')
    ->middleware(['auth', 'EnsureEmailIsVerified']);
// Campaign detail page - must come after thank-you to avoid matching it
Route::get('/believe-fundme/{slug}', [FundMeController::class, 'show'])->name('fundme.show')->where('slug', '[a-z0-9\-]+');

/* marketplace */
Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/marketplace/pool/{organization_product}', [MarketplaceOrganizationProductController::class, 'show'])
    ->name('marketplace.pool.show');
Route::get('/product/{product}', [ProductController::class, 'show'])->name('product.show');
Route::post('/product/{product}/bid', [ProductController::class, 'placeBid'])->name('product.bid')->middleware(['auth', 'EnsureEmailIsVerified']);
// Note: Public route for products moved after resource routes to avoid conflict with /products/create

/* Service Hub - Fiverr-like service marketplace */
Route::get('/service-hub', [ServiceHubController::class, 'index'])->name('service-hub.index');
Route::get('/service-hub/create', [ServiceHubController::class, 'create'])->name('service-hub.create')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub', [ServiceHubController::class, 'store'])->name('service-hub.store')->middleware(['auth', 'EnsureEmailIsVerified']);

// Seller Profile Routes
Route::get('/service-hub/seller-dashboard', [ServiceHubController::class, 'sellerDashboard'])->name('service-hub.seller-dashboard')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/seller-profile/create', [ServiceHubController::class, 'sellerProfileCreate'])->name('service-hub.seller-profile.create')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/seller-profile', [ServiceHubController::class, 'sellerProfileStore'])->name('service-hub.seller-profile.store')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/seller-profile/edit', [ServiceHubController::class, 'sellerProfileEdit'])->name('service-hub.seller-profile.edit')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/seller-profile/update', [ServiceHubController::class, 'sellerProfileUpdate'])->name('service-hub.seller-profile.update')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/{slug}/edit', [ServiceHubController::class, 'edit'])->name('service-hub.edit')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::put('/service-hub/{slug}', [ServiceHubController::class, 'update'])->name('service-hub.update')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::delete('/service-hub/services/{gig}', [ServiceHubController::class, 'destroyService'])
    ->name('service-hub.services.destroy')
    ->middleware(['auth', 'EnsureEmailIsVerified']);

Route::post('/service-hub/{slug}/create-offer', [ServiceHubController::class, 'createCustomOffer'])->name('service-hub.create-offer')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/offers/{offerId}/accept', [ServiceHubController::class, 'acceptCustomOffer'])->name('service-hub.accept-offer')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/offers/{offerId}/reject', [ServiceHubController::class, 'rejectCustomOffer'])->name('service-hub.reject-offer')->middleware(['auth', 'EnsureEmailIsVerified']);
// Keep old URL for backward compatibility
Route::get('/service-hub/order', [ServiceHubController::class, 'order'])->middleware(['auth', 'EnsureEmailIsVerified']);
// Create order route
Route::get('/service-hub/create-order', [ServiceHubController::class, 'order'])->name('service-hub.order')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/create-order', [ServiceHubController::class, 'orderStore'])->name('service-hub.order.store')->middleware(['auth', 'EnsureEmailIsVerified']);
// Separate route for Stripe checkout session creation
Route::post('/service-hub/checkout/create-session', [ServiceHubController::class, 'createCheckoutSession'])->name('service-hub.checkout.create-session')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/calculate-fees', [ServiceHubController::class, 'calculateFees'])->name('service-hub.calculate-fees')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/order/success', [ServiceHubController::class, 'orderSuccess'])->name('service-hub.order.success')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/seller/{id}', [ServiceHubController::class, 'sellerProfile'])->name('service-hub.seller.profile');
Route::get('/service-hub/seller/{id}/reviews', [ServiceHubController::class, 'sellerReviews'])->name('service-hub.seller.reviews');
Route::get('/service-hub/my-orders', [ServiceHubController::class, 'myOrders'])->name('service-hub.my-orders')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/seller-orders', [ServiceHubController::class, 'sellerOrders'])->name('service-hub.seller-orders')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/orders/{orderId}', [ServiceHubController::class, 'orderDetail'])->name('service-hub.order.detail')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/deliver', [ServiceHubController::class, 'deliverOrder'])->name('service-hub.order.deliver')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/accept-delivery', [ServiceHubController::class, 'acceptDelivery'])->name('service-hub.order.accept-delivery')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/complete', [ServiceHubController::class, 'completeOrder'])->name('service-hub.order.complete')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/approve', [ServiceHubController::class, 'approveOrder'])->name('service-hub.order.approve')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/reject', [ServiceHubController::class, 'rejectOrder'])->name('service-hub.order.reject')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/{slug}/favorite', [ServiceHubController::class, 'toggleFavorite'])->name('service-hub.favorite')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/{slug}/reviews', [ServiceHubController::class, 'reviewsStore'])->name('service-hub.reviews.store')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::post('/service-hub/orders/{orderId}/seller-review', [ServiceHubController::class, 'sellerReviewStore'])->name('service-hub.order.seller-review')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/test-email', [ServiceHubController::class, 'testEmailNotifications'])->name('service-hub.test-email')->middleware(['auth', 'EnsureEmailIsVerified']);
Route::get('/service-hub/{slug}/reviews', [ServiceHubController::class, 'reviews'])->name('service-hub.reviews');
Route::get('/service-hub/{slug}', [ServiceHubController::class, 'show'])->name('service-hub.show');

// Cancel order route
Route::post('/service-hub/orders/{orderId}/cancel', [ServiceHubController::class, 'cancelOrder'])
    ->name('service-hub.order.cancel')
    ->middleware(['auth', 'EnsureEmailIsVerified']);

// Resubmit delivery route
Route::post('/service-hub/orders/{orderId}/resubmit', [ServiceHubController::class, 'resubmitDelivery'])
    ->name('service-hub.order.resubmit')
    ->middleware(['auth', 'EnsureEmailIsVerified']);

// Get order status info route
Route::get('/service-hub/orders/{orderId}/status-info', [ServiceHubController::class, 'getOrderStatusInfo'])
    ->name('service-hub.order.status-info')
    ->middleware(['auth', 'EnsureEmailIsVerified']);

// Admin seller management routes
Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/service-sellers', [ServiceSellerController::class, 'index'])->name('admin.service-sellers.index');
    Route::get('/service-sellers/{id}', [ServiceSellerController::class, 'show'])->name('admin.service-sellers.show');
    Route::post('/service-sellers/{id}/suspend', [ServiceSellerController::class, 'suspend'])->name('admin.service-sellers.suspend');
    Route::post('/service-sellers/{id}/unsuspend', [ServiceSellerController::class, 'unsuspend'])->name('admin.service-sellers.unsuspend');
});

// Service Chat Routes
Route::prefix('service-hub')->middleware(['auth', 'EnsureEmailIsVerified'])->name('service-hub.chat.')->group(function () {
    Route::post('/{slug}/chat', [ServiceHubController::class, 'createOrGetServiceChat'])->name('create-or-get');
    Route::get('/chat/{chatId}/messagesget', [ServiceHubController::class, 'getServiceChatMessages'])->name('messages');
    Route::post('/chat/{chatId}/messages', [ServiceHubController::class, 'sendServiceChatMessage'])->name('send-message');
    Route::get('/chats', [ServiceHubController::class, 'getServiceChats'])->name('list');
    Route::get('/chats/unreadcountget', [ServiceHubController::class, 'getUnreadCount'])->name('unread-count');
    Route::get('/chats/list', [ServiceHubController::class, 'chats'])->name('chats');
});
Route::get('/service-hub/chat/{chatId}', [ServiceHubController::class, 'serviceChat'])->name('service-hub.chat.show')->middleware(['auth', 'EnsureEmailIsVerified']);

// Cart routes (protected)
// Believe Points Routes (organization_pending cannot access until onboarding complete)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|user|care_alliance'])->prefix('believe-points')->name('believe-points.')->group(function () {
    Route::get('/', [BelievePointController::class, 'index'])->name('index');
    Route::post('/purchase', [BelievePointController::class, 'purchase'])->name('purchase');
    Route::get('/manual-confirm/{purchase}', [BelievePointController::class, 'manualConfirm'])->name('manual.confirm');
    Route::post('/manual-confirm/{purchase}', [BelievePointController::class, 'manualConfirmSubmit'])->name('manual.confirm.submit');
    Route::get('/paypal/capture/{purchase}', [BelievePointController::class, 'paypalCapture'])->name('paypal.capture');
    Route::get('/complete-saved-payment/{purchase}', [BelievePointController::class, 'completeSavedPayment'])->name('complete-saved-payment');
    Route::get('/success', [BelievePointController::class, 'success'])->name('success');
    Route::get('/cancel', [BelievePointController::class, 'cancel'])->name('cancel');
    Route::get('/refunds', [BelievePointController::class, 'refunds'])->name('refunds');
    Route::post('/refunds/{purchaseId}', [BelievePointController::class, 'refund'])->name('refund');
    Route::post('/auto-replenish/settings', [BelievePointController::class, 'updateAutoReplenishSettings'])->name('auto-replenish.settings');
    Route::get('/auto-replenish/setup', [BelievePointController::class, 'autoReplenishSetupPayment'])->name('auto-replenish.setup');
    Route::get('/auto-replenish/setup-success', [BelievePointController::class, 'autoReplenishSetupSuccess'])->name('auto-replenish.setup-success');
    Route::post('/auto-replenish/remove-payment', [BelievePointController::class, 'autoReplenishRemovePaymentMethod'])->name('auto-replenish.remove-payment');
    Route::post('/transfer-to-wallet', [BelievePointController::class, 'transferToWallet'])->name('transfer-to-wallet');
});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|user|care_alliance'])->group(function () {
    Route::get('/brp-campaigns', [\App\Http\Controllers\BrpCampaignBrowserController::class, 'index'])
        ->name('frontend.brp-campaigns.index');
    Route::get('/feedback-campaigns', [\App\Http\Controllers\FeedbackCampaignBrowserController::class, 'index'])
        ->name('feedback-campaigns.index');
    Route::get('/feedback/{uuid}', [\App\Http\Controllers\SupporterFeedbackController::class, 'show'])
        ->name('feedback-campaigns.show');
    Route::post('/feedback/{uuid}', [\App\Http\Controllers\SupporterFeedbackController::class, 'submit'])
        ->name('feedback-campaigns.submit');

    // Dashboard Unity Loaves
    Route::prefix('dashboard/unity-loaves')->name('dashboard.unity-loaves.')->group(function () {
        Route::get('/', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'store'])->name('store');
        Route::get('/{unityLoaf}/edit', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'edit'])->name('edit');
        Route::put('/{unityLoaf}', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'update'])->name('update');
        Route::delete('/{unityLoaf}', [\App\Http\Controllers\DashboardUnityLoavesController::class, 'destroy'])->name('destroy');
    });
});

// Merchant Hub Routes (Public - for viewing offers)
Route::prefix('merchant-hub')->name('merchant-hub.')->group(function () {
    Route::get('/', [MerchantHubOfferController::class, 'index'])->name('index');
    // SEO-friendly referral: /merchant-hub/offers/8/ref/ABC123 — stores ref in session, redirects to offer
    Route::get('/offers/{id}/ref/{refCode}', [MerchantRedemptionController::class, 'offerRefRedirect'])->name('offer.show.ref');
    Route::get('/offers/{id}', [MerchantHubOfferController::class, 'show'])->name('offer.show');
    Route::get('/products/{marketplace_product}', [MerchantHubMarketplaceProductController::class, 'show'])->name('product.show');
});

// Merchant Hub Redemption Routes (Requires auth)
Route::middleware(['auth', 'EnsureEmailIsVerified'])->prefix('merchant-hub')->name('merchant-hub.')->group(function () {
    Route::get('/my-purchases', [MerchantRedemptionController::class, 'myPurchases'])->name('my-purchases');
    Route::get('/offers/{id}/checkout', [MerchantRedemptionController::class, 'checkoutShow'])->name('offer.checkout');
    Route::post('/checkout/rates', [MerchantRedemptionController::class, 'checkoutRates'])->name('checkout.rates');
    Route::post('/checkout', [MerchantRedemptionController::class, 'checkoutStore'])->name('checkout.store');
    Route::post('/redeem', [MerchantRedemptionController::class, 'redeem'])->name('redeem');
    Route::get('/redemption/stripe-success', [MerchantRedemptionController::class, 'stripeSuccess'])->name('redemption.stripe-success');
    Route::get('/redemption/confirmed/{code?}', [MerchantRedemptionController::class, 'confirmed'])->name('redemption.confirmed');
    Route::get('/redemption/verify/{code}', [MerchantRedemptionController::class, 'verify'])->name('redemption.verify');
});

// Merchant QR verification route (requires merchant auth)
Route::middleware(['auth:merchant'])->prefix('merchant-hub')->name('merchant-hub.')->group(function () {
    Route::post('/redemption/verify-from-qr', [MerchantRedemptionController::class, 'verifyFromQr'])->name('redemption.verify-from-qr');
});

// Merchant verification route (requires merchant auth for approval)
Route::middleware(['auth:merchant'])->prefix('merchant-hub')->name('merchant-hub.')->group(function () {
    Route::post('/redemption/{code}/mark-used', [MerchantRedemptionController::class, 'markAsUsed'])->name('redemption.mark-used');
    Route::post('/redemption/{code}/cancel', [MerchantRedemptionController::class, 'cancelRedemption'])->name('redemption.cancel');
});

// Public QR code route (no auth required - code in URL is sufficient security)
Route::get('/merchant-hub/redemption/qr-code/{code}', [MerchantRedemptionController::class, 'generateQrCode'])->name('merchant-hub.redemption.qr-code');

// Public verification page route (for merchants scanning QR codes)
Route::get('/merchant-hub/redemption/verify/{code}', [MerchantRedemptionController::class, 'verifyPage'])->name('merchant-hub.redemption.verify-page');

// Merchant Program Routes
Route::middleware(['auth', 'EnsureEmailIsVerified'])->prefix('merchant')->name('merchant.')->group(function () {
    Route::get('/welcome', function () {
        return Inertia::render('merchant/Welcome');
    })->name('welcome');

    Route::get('/hub', function () {
        return Inertia::render('merchant/Hub');
    })->name('hub');

    Route::get('/earn-points', function () {
        return Inertia::render('merchant/EarnPoints');
    })->name('earn-points');

    Route::get('/offers/{id}', function ($id) {
        return Inertia::render('merchant/OfferDetail', [
            'offer' => null, // Replace with actual offer data from backend
        ]);
    })->name('offers.show');

    // Redemption routes
    Route::post('/redeem', [MerchantRedemptionController::class, 'redeem'])->name('redeem');
    Route::get('/redemption-confirmed/{code?}', [MerchantRedemptionController::class, 'confirmed'])->name('redemption.confirmed');
    Route::get('/redemption/qr-code/{code}', [MerchantRedemptionController::class, 'generateQrCode'])->name('redemption.qr-code');
    Route::get('/redemption/verify/{code}', [MerchantRedemptionController::class, 'verify'])->name('redemption.verify');

    Route::get('/qr-code', function () {
        return Inertia::render('merchant/QRCode');
    })->name('qr-code');
});

Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::get('/cart/data', [CartController::class, 'getCartDataApi'])->name('cart.data');
    Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
    Route::put('/cart/items/{cartItem}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::post('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');

    // Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');

    // Route::post('/checkout/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('checkout.payment-intent');
    // Route::post('/checkout/confirm', [CheckoutController::class, 'confirmPayment'])->name('checkout.confirm');
    // Route::post('/checkout/{order}/submit-printify', [CheckoutController::class, 'submitToPrintify'])->name('checkout.submit-printify');

    // // Step 1 - Shipping
    // Route::get('/checkout/step1', [CheckoutController::class, 'showStep1'])->name('checkout.step1');
    // Route::post('/checkout/validate-step1', [CheckoutController::class, 'validateStep1'])->name('checkout.validate-step1');

    // // Step 2 - Payment
    // Route::get('/checkout/step2', [CheckoutController::class, 'showStep2'])->name('checkout.step2');
    // Route::post('/checkout/update-donation', [CheckoutController::class, 'updateDonation'])->name('checkout.update-donation');
    // Route::post('/checkout/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('checkout.payment-intent');
    // Route::post('/checkout/confirm', [CheckoutController::class, 'confirmPayment'])->name('checkout.confirm');

    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout/shipping-calculation', [CheckoutController::class, 'calculateShipping'])->name('checkout.shipping');
    Route::post('/checkout/update-tax', [CheckoutController::class, 'updateTax'])->name('checkout.update-tax');

    Route::post('/checkout/step1', [CheckoutController::class, 'submitStep1'])->name('checkout.step1');
    Route::post('/checkout/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('checkout.payment-intent');
    Route::post('/checkout/confirm', [CheckoutController::class, 'confirmPayment'])->name('checkout.confirm');
});

/* fractional ownership */
Route::get('/fractional', [FractionalOwnershipController::class, 'index'])->name('fractional.index');
Route::get('/fractional/{offering}', [FractionalOwnershipController::class, 'show'])->name('fractional.show');
Route::post('/fractional/{offering}/purchase', [FractionalOwnershipController::class, 'purchase'])->middleware('auth')->name('fractional.purchase');
Route::get('/fractional/purchase/success', [FractionalOwnershipController::class, 'purchaseSuccess'])->middleware('auth')->name('fractional.purchase.success');
Route::get('/fractional/purchase/cancel', [FractionalOwnershipController::class, 'purchaseCancel'])->middleware('auth')->name('fractional.purchase.cancel');
Route::get('/fractional/certificate/{order}', [FractionalCertificateController::class, 'show'])->middleware('auth')->name('fractional.certificate.show');
Route::get('/fractional/certificate/{order}/download', [FractionalCertificateController::class, 'download'])->middleware('auth')->name('fractional.certificate.download');
Route::get('/fractional/certificate/{order}/download-pdf', [FractionalCertificateController::class, 'downloadPdf'])->middleware('auth')->name('fractional.certificate.download-pdf');

/* events */
Route::get('/all-events', [EventController::class, 'alleventsPage'])->name('alleventsPage');
Route::get('/events/{id}/view', [EventController::class, 'viewEvent'])->name('viewEvent');

// User public routes
Route::get('/users/{slug}', [UserProfileController::class, 'show'])->name('users.show');
Route::get('/users/{slug}/posts', [UserProfileController::class, 'posts'])->name('users.posts');
Route::get('/users/{slug}/about', [UserProfileController::class, 'about'])->name('users.about');
Route::get('/users/{slug}/activity', [UserProfileController::class, 'activity'])->name('users.activity');
Route::get('/users/{slug}/following', [UserProfileController::class, 'following'])->name('users.following');
Route::get('/users/{slug}/groups', [UserProfileController::class, 'groups'])->name('users.groups');

// User follow routes (requires auth)
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::post('/users/{id}/toggle-follow', [UserProfileController::class, 'toggleFollow'])->name('users.toggle-follow');
});

// Organization routes
Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations');
Route::get('/organizations/{slug}', [OrganizationController::class, 'show'])->name('organizations.show');
Route::get('/organizations/{slug}/products', [OrganizationController::class, 'products'])->name('organizations.products');
Route::get('/organizations/{slug}/jobs', [OrganizationController::class, 'jobs'])->name('organizations.jobs');
Route::get('/organizations/{slug}/events', [OrganizationController::class, 'events'])->name('organizations.events');
Route::get('/organizations/{slug}/social-media', [OrganizationController::class, 'socialMedia'])->name('organizations.social-media');
Route::get('/organizations/{slug}/about', [OrganizationController::class, 'about'])->name('organizations.about');
Route::get('/organizations/{slug}/supporters', [OrganizationController::class, 'supporters'])->name('organizations.supporters');
Route::get('/organizations/{slug}/impact', [OrganizationController::class, 'impact'])->name('organizations.impact');
Route::get('/organizations/{slug}/details', [OrganizationController::class, 'details'])->name('organizations.details');
Route::get('/organizations/{slug}/contact', [OrganizationController::class, 'contact'])->name('organizations.contact');

// Unity Meet — public guest join (Connection Hub, invitations; same handler as legacy path)
Route::get('/unity-meet/join/{roomName}', [LivestreamController::class, 'guestJoin'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->name('unity-meet.join');

// Public livestream guest join (no auth) — legacy alias; registered first so /livestreams/join/{roomName} is not matched by /livestreams/{id}
Route::get('/livestreams/join/{roomName}', [LivestreamController::class, 'guestJoin'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->name('livestreams.guest-join');

Route::post('/livestreams/join/{roomName}/presence/join', [\App\Http\Controllers\LivestreamMeetingPresenceController::class, 'join'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->middleware('throttle:120,1')
    ->name('livestreams.presence.join');

Route::post('/livestreams/join/{roomName}/presence/heartbeat', [\App\Http\Controllers\LivestreamMeetingPresenceController::class, 'heartbeat'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->middleware('throttle:240,1')
    ->name('livestreams.presence.heartbeat');

Route::post('/livestreams/join/{roomName}/presence/leave', [\App\Http\Controllers\LivestreamMeetingPresenceController::class, 'leave'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->middleware('throttle:120,1')
    ->name('livestreams.presence.leave');

// Hidden participant canvas mixer (MVP). Public, room-name addressed like guest join.
// Opened in a browser; WHEP-subscribes the 6 seat paths, composites a 3x2 grid,
// and WHIP-publishes the combined stream to the path the worker already pulls.
Route::get('/livestreams/canvas/{roomName}', [\App\Http\Controllers\LivestreamCanvasController::class, 'show'])
    ->where('roomName', '[a-zA-Z0-9_-]+')
    ->name('livestreams.canvas');

Route::post('/livestreams/recording-decline', [LivestreamRecordingDeclineController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('livestreams.recording-decline.store');

// API route for inviting unregistered organizations (requires auth)
Route::middleware(['auth', 'web'])->post('/api/organizations/invite', [OrganizationController::class, 'inviteOrganization'])->name('api.organizations.invite');
Route::get('/organizations/{slug}/enrollments', [OrganizationController::class, 'enrollments'])->name('organizations.enrollments');
Route::post('/organizations/{id}/generate-mission', [OrganizationController::class, 'generateMission'])->name('organizations.generate-mission'); // id is ExcelData ID

// API route for dynamic city loading
Route::get('/api/cities-by-state', [OrganizationController::class, 'getCitiesByState']);

// Giving dashboard (donation history) - available to both supporters and organizations
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    Route::get('/profile/donations', [UserProfileController::class, 'donations'])->name('profile.donations');

    Route::prefix('me/favorite-menus')->name('favorite-menus.')->group(function () {
        Route::post('/', [FavoriteMenuController::class, 'syncQuick'])->name('sync');
        Route::put('/reorder', [FavoriteMenuController::class, 'syncQuick'])->name('reorder');
        Route::put('/bottom-nav', [FavoriteMenuController::class, 'syncBottomNav'])->name('bottom-nav');
        Route::post('/{menuKey}/toggle', [FavoriteMenuController::class, 'toggle'])->name('toggle');
        Route::post('/onboarding', [FavoriteMenuController::class, 'completeOnboarding'])->name('onboarding');
        Route::post('/onboarding/skip', [FavoriteMenuController::class, 'skipOnboarding'])->name('onboarding.skip');
    });
});

// BIU AI Media Studio — nonprofits + supporters (OpenAI → fal.ai → Dropbox; work runs on the queue worker).
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:ai.media.read', 'role:user|organization|organization_pending|care_alliance|admin'])
    ->prefix('ai-media-studio')
    ->name('ai-media-studio.')
    ->group(function () {
        Route::get('/', [AiMediaStudioController::class, 'index'])->name('index');
        Route::get('/create', [AiMediaStudioController::class, 'create'])->middleware('permission:ai.media.create')->name('create');
        Route::post('/', [AiMediaStudioController::class, 'store'])->middleware('permission:ai.media.create')->name('store');
        Route::get('/{ai_video}/download', [AiMediaStudioController::class, 'download'])->name('download');
        Route::post('/{ai_video}/apply-watermark', [AiMediaStudioController::class, 'applyWatermark'])
            ->middleware('permission:ai.media.create')
            ->name('apply-watermark');
        Route::get('/{ai_video}', [AiMediaStudioController::class, 'show'])->name('show');
    });

// Credit purchases — wallet credits + AI Media Studio packs (supporters + organizations).
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:user|organization|organization_pending|care_alliance|admin'])
    ->group(function () {
        Route::get('/credits/purchase', [CreditPurchaseController::class, 'index'])->name('credits.purchase');
        Route::post('/credits/checkout', [CreditPurchaseController::class, 'checkout'])->name('credits.checkout');
        Route::post('/credits/pay-believe-points', [CreditPurchaseController::class, 'payWithBelievePoints'])->name('credits.pay-believe-points');
        Route::get('/credits/success', [CreditPurchaseController::class, 'success'])->name('credits.success');
        Route::get('/credits/cancel', [CreditPurchaseController::class, 'cancel'])->name('credits.cancel');
    });

// Profile routes
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:user'])->name('user.')->group(function () {
    Route::get('/profile', [UserProfileController::class, 'index'])->name('profile.index');
    Route::get('/profile/edit', [UserProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile/update', [UserProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/primary-organization/change', [UserProfileController::class, 'changePrimaryOrganization'])->name('profile.primary-organization.change');
    Route::post('/profile/timezone', [UserProfileController::class, 'updateTimezone'])->name('profile.timezone');

    Route::get('/profile/change-password', [UserProfileController::class, 'changePasswordForm'])->name('profile.change-password');

    Route::get('/profile/following', [UserProfileController::class, 'favorites'])->name('profile.favorites');
    Route::delete('/profile/following/{id}', [UserProfileController::class, 'removeFavorite'])->name('profile.favorites.remove');
    Route::get('/profile/project-applications', [UserProfileController::class, 'profileProjectApplications'])->name('profile.project-applications');
    Route::get('/profile/project-applications/{lead}', [UserProfileController::class, 'profileProjectApplicationShow'])->name('profile.project-applications.show')->where('lead', '[0-9]+');

    Route::get('/profile/orders', [UserProfileController::class, 'orders'])->name('profile.orders');
    Route::get('/profile/orders/{order}', [UserProfileController::class, 'orderDetails'])->name('profile.order-details');
    Route::get('/profile/bids', [UserProfileController::class, 'bids'])->name('profile.bids');
    Route::get('/profile/bid-wins', [UserProfileController::class, 'bidWins'])->name('profile.bid-wins');
    Route::get('/profile/job-applications', [UserProfileController::class, 'jobApplications'])->name('profile.job-applications');
    Route::get('/profile/job-applications/{id}', [UserProfileController::class, 'showJobApplication'])->name('profile.job-applications.show');
    Route::get('/profile/job-applications/{id}/timesheets', [UserProfileController::class, 'getJobApplicationTimesheets'])->name('profile.job-applications.timesheets');
    Route::post('/profile/job-applications/{id}/request-completion', [UserProfileController::class, 'requestJobCompletion'])->name('profile.job-applications.request-completion');
    Route::get('/profile/reward-points-ledger', [UserProfileController::class, 'rewardPointsLedger'])->name('profile.reward-points-ledger');
    Route::get('/profile/redemptions', [UserProfileController::class, 'redemptions'])->name('profile.redemptions');
    Route::get('/profile/transactions', [TransactionController::class, 'index'])->name('profile.transactions');
    Route::get('/profile/billing', [UserProfileController::class, 'billing'])->name('profile.billing');
    Route::get('/profile/payment-methods', [\App\Http\Controllers\UserPaymentMethodController::class, 'index'])->name('profile.payment-methods.index');
    Route::get('/profile/timesheet', [UserProfileController::class, 'timesheet'])->name('profile.timesheet');
    Route::get('/profile/impact-score', [ImpactScoreController::class, 'show'])->name('profile.impact-score');
    Route::get('/api/impact-score', [ImpactScoreController::class, 'index'])->name('api.impact-score');
    Route::get('/profile/fractional-ownership', [FractionalOwnershipController::class, 'myPurchases'])->name('profile.fractional-ownership');
    Route::get('nodeboss/shares', [NodeShareController::class, 'index'])->name('nodeboss.sahres');

    Route::post('/organizations/{orgId}/save-positions-follow', [OrganizationController::class, 'savePositionsAndFollow'])
        ->name('organizations.save-positions-and-follow');

    Route::get('/user/positions/for-selection', [OrganizationController::class, 'getPositionsForSelection'])
        ->name('positions.get-for-selection');

    Route::get('/profile/topics/select', [UsersInterestedTopicsController::class, 'userSelect'])
        ->name('topics.select');

    Route::get('/profile/integrations', [UserProfileController::class, 'integrations'])->name('profile.integrations');
});

Route::post('/user/topics/store', [UsersInterestedTopicsController::class, 'store'])
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:user|organization|organization_pending|care_alliance']);

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:user'])->get('/profile-old', function () {
    return Inertia::render('frontend/profile');
});

Route::resource('/chat-group-topics', ChatTopicController::class)->only(['index', 'store', 'update', 'destroy'])->middleware([
    'index' => 'permission:communication.read',
    'store' => 'permission:communication.create',
    'update' => 'permission:communication.update',
    'destroy' => 'permission:communication.delete',
]);

Route::get('group-topics/select', [UsersInterestedTopicsController::class, 'orgSelect'])->middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|organization_pending|care_alliance'])
    ->name('auth.topics.select');

Route::get('/my-chat-groups', [MyChatGroupsController::class, 'index'])
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:user|organization|organization_pending|care_alliance|admin'])
    ->name('my-chat-groups.index');

Route::prefix('chat')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->name('chat.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::get('/rooms/{chatRoom}/messages', [ChatController::class, 'getMessages'])->name('messages');
    Route::post('/rooms/{chatRoom}/messages', [ChatController::class, 'sendMessage'])->name('send-message');
    Route::delete('/messages/{message}', [ChatController::class, 'deleteMessage'])->name('delete-message');
    Route::post('/rooms', [ChatController::class, 'createRoom'])->name('create-room');
    Route::post('/direct-chat', [ChatController::class, 'createDirectChat'])->name('create-direct-chat'); // Corrected route name
    Route::post('/rooms/{chatRoom}/join', [ChatController::class, 'joinRoom'])->name('join-room');
    Route::post('/rooms/{chatRoom}/leave', [ChatController::class, 'leaveRoom'])->name('leave-room');
    Route::post('/rooms/{chatRoom}/typing', [ChatController::class, 'setTypingStatus'])->name('typing'); // Renamed function
    Route::post('/rooms/{chatRoom}/mark-as-read', [ChatController::class, 'markRoomAsRead'])->name('mark-as-read'); // Renamed function
    Route::post('/rooms/{chatRoom}/members', [ChatController::class, 'addMembers'])->name('add-members');
    Route::get('/topics', [ChatController::class, 'getTopics'])->name('get-topics');

    Route::get('/user/topics', [DashboardController::class, 'getUserTopic']);
    Route::delete('/user/topics/{topic}', [DashboardController::class, 'destroyUserTopic']);
});

Route::prefix('unity-calls')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->name('unity-calls.')->group(function () {
    Route::get('/incoming', [UnityCallController::class, 'incoming'])->name('incoming');
    Route::get('/active', [UnityCallController::class, 'active'])->name('active');
    Route::get('/chat-rooms', [UnityCallController::class, 'chatRooms'])->name('chat-rooms');
    Route::post('/', [UnityCallController::class, 'store'])->name('store');
    Route::post('/{call}/accept', [UnityCallController::class, 'accept'])->name('accept')->where('call', '[0-9]+');
    Route::post('/{call}/decline', [UnityCallController::class, 'decline'])->name('decline')->where('call', '[0-9]+');
    Route::post('/{call}/cancel', [UnityCallController::class, 'cancel'])->name('cancel')->where('call', '[0-9]+');
    Route::post('/{call}/end', [UnityCallController::class, 'end'])->name('end')->where('call', '[0-9]+');
    Route::post('/{call}/expire-ringing', [UnityCallController::class, 'expireRinging'])->name('expire-ringing')->where('call', '[0-9]+');
    Route::post('/{call}/incoming-delivered', [UnityCallController::class, 'markIncomingDelivered'])->name('incoming-delivered')->where('call', '[0-9]+');
    Route::post('/{call}/signal', [UnityCallController::class, 'signal'])->name('signal')->where('call', '[0-9]+');
    Route::get('/{call}/pending-signals', [UnityCallController::class, 'pendingSignals'])->name('pending-signals')->where('call', '[0-9]+');
});

Route::get('/unity-calls/{call}', function (Request $request, int $call) {
    $query = $request->getQueryString();

    return redirect()->to('/unity-call/'.$call.($query ? '?'.$query : ''));
})
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])
    ->where('call', '[0-9]+');

Route::get('/unity-call/{call}', [UnityCallController::class, 'show'])
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])
    ->name('unity-call.show')
    ->where('call', '[0-9]+');

Route::get('/unity-calls/{call}/decline-signed/{user}', [UnityCallController::class, 'declineSigned'])
    ->middleware(['auth', 'EnsureEmailIsVerified', 'signed'])
    ->name('unity-calls.decline-signed')
    ->where(['call' => '[0-9]+', 'user' => '[0-9]+']);

Route::get('/unity-calls/{call}/accept-signed/{user}', [UnityCallController::class, 'acceptSigned'])
    ->middleware(['auth', 'EnsureEmailIsVerified', 'signed'])
    ->name('unity-calls.accept-signed')
    ->where(['call' => '[0-9]+', 'user' => '[0-9]+']);

// Wallet Routes
Route::prefix('wallet')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'care_alliance.wallet'])->name('wallet.')->group(function () {
    Route::post('/connect', [WalletController::class, 'connect'])->name('connect');
    Route::get('/balance', [WalletController::class, 'getBalance'])
        ->middleware('throttle:wallet-balance')
        ->name('balance');
    Route::get('/status', [WalletController::class, 'status'])->name('status');
    Route::get('/activity', [WalletController::class, 'getActivity'])->name('activity');
    Route::get('/activity/all', [WalletController::class, 'getAllActivity'])->name('activity.all');
    Route::get('/search-recipients', [WalletController::class, 'searchRecipients'])->name('search-recipients');
    Route::post('/send', [WalletController::class, 'send'])->name('send');
    Route::post('/deposit', [WalletController::class, 'deposit'])->name('deposit');
    Route::post('/disconnect', [WalletController::class, 'disconnect'])->name('disconnect');

    // Bridge Wallet Routes
    Route::post('/bridge/initialize', [BridgeWalletController::class, 'initializeBridge'])->name('bridge.initialize');
    Route::post('/bridge/create-wallet', [BridgeWalletController::class, 'createWalletAfterKYC'])->name('bridge.create-wallet');
    Route::get('/bridge/status', [BridgeWalletController::class, 'checkBridgeStatus'])->name('bridge.status');
    Route::get('/bridge/balance', [BridgeWalletController::class, 'getBridgeBalance'])->name('bridge.balance');
    Route::post('/bridge/kyc-link', [BridgeWalletController::class, 'createKYCLink'])->name('bridge.kyc-link');
    Route::post('/bridge/kyb-link', [BridgeWalletController::class, 'createKYCLink'])->name('bridge.kyb-link');
    Route::post('/bridge/deposit', [BridgeWalletController::class, 'deposit'])->name('bridge.deposit');
    Route::post('/bridge/send', [BridgeWalletController::class, 'send'])->name('bridge.send');

    // Custom KYC Routes
    Route::get('/bridge/tos-link', [BridgeWalletController::class, 'getTosLink'])->name('bridge.tos-link');
    Route::get('/bridge/tos-status', [BridgeWalletController::class, 'checkTosStatus'])->name('bridge.tos-status');
    Route::post('/bridge/sync-tos-status', [BridgeWalletController::class, 'syncTosStatus'])->name('bridge.sync-tos-status');
    Route::post('/bridge/create-customer-kyc', [BridgeWalletController::class, 'createCustomerWithKyc'])->name('bridge.create-customer-kyc');
    Route::post('/bridge/control-person-kyc-link', [BridgeWalletController::class, 'getControlPersonKycLink'])->name('bridge.control-person-kyc-link');
    Route::get('/bridge/business-details', [BridgeWalletController::class, 'getBusinessDetails'])->name('bridge.business-details');

    // Bridge Virtual Account & External Account Routes (for USD top-up)
    Route::post('/bridge/virtual-account', [BridgeWalletController::class, 'createVirtualAccountForWallet'])->name('bridge.virtual-account.create');
    Route::get('/bridge/virtual-accounts', [BridgeWalletController::class, 'getVirtualAccounts'])->name('bridge.virtual-accounts');
    Route::post('/bridge/external-account', [BridgeWalletController::class, 'createExternalAccount'])->name('bridge.external-account.create');
    Route::delete('/bridge/external-account/{externalAccountId}', [BridgeWalletController::class, 'deleteExternalAccount'])->name('bridge.external-account.delete');
    Route::get('/bridge/external-accounts', [BridgeWalletController::class, 'getExternalAccounts'])->name('bridge.external-accounts');
    Route::post('/bridge/transfer-from-external', [BridgeWalletController::class, 'createTransferFromExternalAccount'])->name('bridge.transfer-from-external');
    Route::post('/bridge/transfer-to-external', [BridgeWalletController::class, 'createTransferToExternalAccount'])->name('bridge.transfer-to-external');
    Route::get('/bridge/deposit-instructions', [BridgeWalletController::class, 'getDepositInstructions'])->name('bridge.deposit-instructions');
    Route::get('/bridge/deposit-qr-code', [BridgeWalletController::class, 'getDepositQrCode'])->name('bridge.deposit-qr-code');

    // Card Account Routes
    Route::get('/bridge/card-account', [BridgeWalletController::class, 'getCardAccount'])->name('bridge.card-account.get');
    Route::post('/bridge/card-account', [BridgeWalletController::class, 'createCardAccount'])->name('bridge.card-account.create');

    // Liquidation Address Routes (for crypto deposits)
    Route::post('/bridge/liquidation-address', [BridgeWalletController::class, 'createLiquidationAddress'])->name('bridge.liquidation-address.create');
    Route::get('/bridge/liquidation-addresses', [BridgeWalletController::class, 'getLiquidationAddresses'])->name('bridge.liquidation-addresses');
    Route::get('/bridge/liquidation-address-qr-code', [BridgeWalletController::class, 'getLiquidationAddressQrCode'])->name('bridge.liquidation-address-qr-code');

    // Bridge Webhook Routes
    Route::get('/bridge/webhooks/{webhookId}/events', [BridgeWalletController::class, 'getWebhookEvents'])->name('bridge.webhooks.events');
    Route::get('/bridge/webhooks/{webhookId}/events/{eventId}', [BridgeWalletController::class, 'getWebhookEvent'])->name('bridge.webhooks.event');

    // User Rewards Routes
    Route::get('/rewards/balance', [WalletController::class, 'getRewardBalance'])->name('rewards.balance');
    Route::get('/rewards/history', [WalletController::class, 'getRewardTransactionHistory'])->name('rewards.history');
    Route::post('/rewards/credit-hours', [WalletController::class, 'creditVolunteerHours'])->name('rewards.credit-hours');

    // Token Balance Route
    Route::get('/tokens/balance', [WalletController::class, 'getTokenBalance'])->name('tokens.balance');
});

// AI Chat (Public) - Needs to be outside auth middleware
// Route::get('/ai-chat/context', [AiChatController::class, 'getContext'])->name('ai-chat.context');

// KYC/KYB Callback Routes (after verification completion - no auth required for redirect)
Route::get('/wallet/kyc-callback', [BridgeWalletController::class, 'kycCallback'])->name('bridge.kyc-callback');
Route::get('/wallet/kyb-callback', [BridgeWalletController::class, 'kybCallback'])->name('bridge.kyb-callback');
// TOS callback - GET doesn't require auth (Bridge redirect), POST requires auth (from frontend)
Route::get('/wallet/tos-callback', [BridgeWalletController::class, 'tosCallback'])->name('bridge.tos-callback');
Route::post('/wallet/tos-callback', [BridgeWalletController::class, 'tosCallback'])->middleware('auth')->name('bridge.tos-callback.post');

// Raffle Payment Routes (must come before admin routes to avoid conflicts)
// Stop impersonation route (must be accessible to any authenticated user, including impersonated users)
Route::post('/users/stop-impersonate', [RolePermissionController::class, 'stopImpersonate'])->middleware(['auth'])->name('users.stop-impersonate');

Route::middleware(['web', 'auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/raffles/success', [RaffleController::class, 'success'])->name('raffles.success');
    Route::get('/raffles/cancel', [RaffleController::class, 'cancel'])->name('raffles.cancel');
});

// Route::prefix('excel-data')->name('excel-data.')->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])->group(function () {
//     Route::get('/', [ExcelDataController::class, 'index'])->name('index');
//     Route::get('/import', [ExcelDataController::class, 'import'])->name('import');
//     Route::post('/import', [ExcelDataController::class, 'importStore'])->name('import.store');
//     Route::post('/upload', [ExcelDataController::class, 'upload'])->name('upload');
// });

Route::prefix('admin/compliance')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected', 'permission:compliance.review'])
    ->name('admin.compliance.')
    ->group(function () {
        Route::get('/', [AdminComplianceApplicationController::class, 'index'])->name('index');
        Route::get('/{application}', [AdminComplianceApplicationController::class, 'show'])->name('show');
        Route::patch('/{application}', [AdminComplianceApplicationController::class, 'update'])->name('update');
        Route::delete('/{application}', [AdminComplianceApplicationController::class, 'destroy'])->name('destroy');
    });

Route::prefix('admin/form1023')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.form1023.')
    ->group(function () {
        Route::get('/', [AdminForm1023ApplicationController::class, 'index'])->name('index');
        Route::get('/{application}', [AdminForm1023ApplicationController::class, 'show'])->name('show');
        Route::patch('/{application}', [AdminForm1023ApplicationController::class, 'update'])->name('update');
        Route::patch('/{application}/amount', [AdminForm1023ApplicationController::class, 'updateAmount'])->name('update-amount');
        Route::post('/{application}/reject-document', [AdminForm1023ApplicationController::class, 'rejectDocument'])->name('reject-document');
        Route::delete('/{application}', [AdminForm1023ApplicationController::class, 'destroy'])->name('destroy');
    });

Route::prefix('admin/fees')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.fees.')
    ->group(function () {
        Route::get('/', [FeesController::class, 'index'])->name('index');
        Route::put('/', [FeesController::class, 'update'])->name('update');
    });

Route::prefix('admin/transactions')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.transactions.')
    ->group(function () {
        Route::get('/ledger', [TransactionLedgerController::class, 'index'])->name('ledger');
        Route::get('/ledger/export', [TransactionLedgerController::class, 'exportFlatFile'])->name('ledger.export');
        Route::get('/{transaction}', [TransactionLedgerController::class, 'show'])->name('show');
        Route::delete('/{transaction}', [TransactionLedgerController::class, 'destroy'])->name('destroy');
    });

Route::prefix('admin/reward-points')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.reward-points.')
    ->group(function () {
        Route::get('/', [RewardPointController::class, 'index'])->name('index');
        Route::put('/', [RewardPointController::class, 'update'])->name('update');
    });

// Admin Believe Points Management
Route::prefix('admin/payments/manual')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.payments.manual.')
    ->group(function () {
        Route::get('/', [ManualPaymentVerificationController::class, 'index'])->name('index');
        Route::post('/{paymentTransaction}/approve', [ManualPaymentVerificationController::class, 'approve'])->name('approve');
        Route::post('/{paymentTransaction}/reject', [ManualPaymentVerificationController::class, 'reject'])->name('reject');
    });

Route::prefix('admin/believe-points')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.believe-points.')
    ->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\BelievePointController::class, 'index'])->name('index');
        Route::match(['put', 'post'], '/', [App\Http\Controllers\Admin\BelievePointController::class, 'update'])->name('update');
    });

// Fractional Ownership (Admin-only - Full CRUD)
Route::prefix('admin/fractional')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.fractional.')
    ->group(function () {
        // Assets routes
        Route::get('/assets', [FractionalAssetController::class, 'index'])->name('assets.index');
        Route::get('/assets/create', [FractionalAssetController::class, 'create'])->name('assets.create');
        Route::post('/assets', [FractionalAssetController::class, 'store'])->name('assets.store');
        Route::get('/assets/{asset}/edit', [FractionalAssetController::class, 'edit'])->name('assets.edit');
        Route::put('/assets/{asset}', [FractionalAssetController::class, 'update'])->name('assets.update');
        Route::delete('/assets/{asset}', [FractionalAssetController::class, 'destroy'])->name('assets.destroy');

        // Offerings routes
        Route::get('/offerings', [FractionalOfferingController::class, 'index'])->name('offerings.index');
        Route::get('/offerings/create', [FractionalOfferingController::class, 'create'])->name('offerings.create');
        Route::post('/offerings', [FractionalOfferingController::class, 'store'])->name('offerings.store');
        Route::get('/offerings/{offering}', [FractionalOfferingController::class, 'show'])->name('offerings.show');
        Route::get('/offerings/{offering}/edit', [FractionalOfferingController::class, 'edit'])->name('offerings.edit');
        Route::put('/offerings/{offering}', [FractionalOfferingController::class, 'update'])->name('offerings.update');
        Route::delete('/offerings/{offering}', [FractionalOfferingController::class, 'destroy'])->name('offerings.destroy');

        // Orders routes
        Route::get('/orders', [FractionalOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [FractionalOrderController::class, 'show'])->name('orders.show');
    });

Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/notifications/api', [NotificationController::class, 'apiIndex'])->name('notifications.api');
    Route::get('/notifications', [NotificationController::class, 'inbox'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/clear-all', [NotificationController::class, 'clearAll']);

    Route::get('/notifications/content/{content_item}', [NotificationController::class, 'show'])->name('notifications.content.show');
});

// Push notification routes
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::post('/push-token', [PushTokenController::class, 'store']);
    Route::delete('/push-token', [PushTokenController::class, 'destroy']);
    Route::post('/api/push-notifications/open', [PushNotificationOpenController::class, 'store'])
        ->name('push-notifications.open');
});

Route::middleware(['auth', 'EnsureEmailIsVerified'])->prefix('account/payment-methods')->name('account.payment-methods.')->group(function () {
    Route::post('/setup', [\App\Http\Controllers\UserPaymentMethodController::class, 'setup'])->name('setup');
    Route::get('/setup-success', [\App\Http\Controllers\UserPaymentMethodController::class, 'setupSuccess'])->name('setup-success');
    Route::post('/{paymentMethodId}/default', [\App\Http\Controllers\UserPaymentMethodController::class, 'setDefault'])->name('default');
    Route::delete('/{paymentMethodId}', [\App\Http\Controllers\UserPaymentMethodController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth', 'web', 'throttle:120,1'])->post('/api/user/proximity-location', [ProximityLocationController::class, 'store'])->name('api.user.proximity-location');

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|care_alliance', 'topics.selected'])->group(function () {
    Route::get('/content', [ContentItemController::class, 'index'])->name('content.items.index');
    Route::get('/content/create', [ContentItemController::class, 'create'])->name('content.items.create');
    Route::post('/content', [ContentItemController::class, 'store'])->name('content.items.store');
    Route::get('/content/{content_item}/edit', [ContentItemController::class, 'edit'])->name('content.items.edit');
    Route::put('/content/{content_item}', [ContentItemController::class, 'update'])->name('content.items.update');
    Route::delete('/content/{content_item}', [ContentItemController::class, 'destroy'])->name('content.items.destroy');

    // Campaigns
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

    Route::get('/campaigns/ai/create', [AiCampaignController::class, 'create'])->name('campaigns.ai-create');
    Route::post('/campaigns/ai', [AiCampaignController::class, 'store'])->name('campaigns.ai-store');

    // Believe FundMe – organization campaigns
    Route::get('/fundme', [FundMeCampaignController::class, 'index'])->name('fundme.campaigns.index');
    Route::get('/fundme/create', [FundMeCampaignController::class, 'create'])->name('fundme.campaigns.create');
    Route::post('/fundme', [FundMeCampaignController::class, 'store'])->name('fundme.campaigns.store');
    Route::get('/fundme/{fundme_campaign}/edit', [FundMeCampaignController::class, 'edit'])->name('fundme.campaigns.edit');
    Route::put('/fundme/{fundme_campaign}', [FundMeCampaignController::class, 'update'])->name('fundme.campaigns.update');
    Route::post('/fundme/{fundme_campaign}/submit', [FundMeCampaignController::class, 'submit'])->name('fundme.campaigns.submit');
    Route::delete('/fundme/{fundme_campaign}', [FundMeCampaignController::class, 'destroy'])->name('fundme.campaigns.destroy');

    // AI Chat
    // AI Chat (Authenticated)
    Route::get('/ai-chat', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::post('/ai-chat/send', [AiChatController::class, 'sendMessage'])->name('ai-chat.send');
    Route::get('/ai-chat/conversations', [AiChatController::class, 'getConversations'])->name('ai-chat.conversations');
    Route::get('/ai-chat/conversations/{id}', [AiChatController::class, 'getConversation'])->name('ai-chat.conversation');
    Route::put('/ai-chat/conversations/{id}', [AiChatController::class, 'updateConversation'])->name('ai-chat.update-conversation');
    Route::delete('/ai-chat/conversations/{id}', [AiChatController::class, 'deleteConversation'])->name('ai-chat.delete-conversation');

    // Organization routes follwers
    Route::prefix('organization')->group(function () {
        Route::get('/followers', [FollowerController::class, 'index'])
            ->name('organization.followers.index');

        Route::get('/supporters', [OrganizationSupportersController::class, 'index'])
            ->name('organization.supporters.index');

        Route::get('/supporters/{supporter}', [OrganizationSupportersController::class, 'show'])
            ->whereNumber('supporter')
            ->name('organization.supporters.show');

        Route::get('/supporters/{supporter}/contact', [OrganizationSupportersController::class, 'contact'])
            ->whereNumber('supporter')
            ->name('organization.supporters.contact');

        Route::get('/supporters/export/{format}', [OrganizationSupportersController::class, 'export'])
            ->where('format', 'csv|xlsx|pdf')
            ->name('organization.supporters.export');

        Route::post(
            '/followers/{follower}/toggle-notifications',
            [FollowerController::class, 'toggleNotifications']
        )
            ->name('organization.followers.toggle-notifications');

        Route::delete(
            '/followers/{follower}',
            [FollowerController::class, 'destroy']
        )
            ->name('organization.followers.destroy');

        // Bulk actions
        Route::post(
            '/followers/bulk-toggle-notifications',
            [FollowerController::class, 'bulkToggleNotifications']
        )
            ->name('organization.followers.bulk-toggle-notifications');

        Route::post(
            '/followers/bulk-destroy',
            [FollowerController::class, 'bulkDestroy']
        )
            ->name('organization.followers.bulk-destroy');

        // Kiosk directory listings (org-scoped rows in `kiosk_providers`)
        Route::get('/kiosk-providers', [OrganizationKioskProviderController::class, 'index'])
            ->name('organization.kiosk-providers.index');
        Route::get('/kiosk-providers/create', [OrganizationKioskProviderController::class, 'create'])
            ->name('organization.kiosk-providers.create');
        Route::post('/kiosk-providers', [OrganizationKioskProviderController::class, 'store'])
            ->name('organization.kiosk-providers.store');
        Route::get('/kiosk-providers/{kioskProvider}/edit', [OrganizationKioskProviderController::class, 'edit'])
            ->name('organization.kiosk-providers.edit');
        Route::put('/kiosk-providers/{kioskProvider}', [OrganizationKioskProviderController::class, 'update'])
            ->name('organization.kiosk-providers.update');
        Route::get('/kiosk-providers/{kioskProvider}', [OrganizationKioskProviderController::class, 'show'])
            ->name('organization.kiosk-providers.show');
        Route::delete('/kiosk-providers/{kioskProvider}', [OrganizationKioskProviderController::class, 'destroy'])
            ->name('organization.kiosk-providers.destroy');
    });

    Route::prefix('marketplace/product-pool')->name('marketplace.product-pool.')->group(function () {
        Route::get('/', [MarketplaceProductPoolController::class, 'index'])->name('index');
        Route::post('/adopt', [MarketplaceProductPoolController::class, 'store'])->name('adopt');
        Route::patch('/listing/{organization_product}', [MarketplaceProductPoolController::class, 'updateListing'])->name('listing.update');
    });

    // Organization Feedback & Rewards
    Route::prefix('organization/feedback-rewards')
        ->name('org.feedback-rewards.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'store'])->name('store');
            Route::get('/{campaign}', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'show'])->name('show');
            Route::get('/{campaign}/edit', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'edit'])->name('edit');
            Route::put('/{campaign}', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'update'])->name('update');
            Route::post('/{campaign}/launch', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'launch'])->name('launch');
            Route::post('/{campaign}/end', [\App\Http\Controllers\Organization\OrganizationFeedbackRewardsController::class, 'end'])->name('end');
        });

    // Organization Project Management (Kanban)
    Route::prefix('organization/projects')
        ->name('org.projects.')
        ->group(function () {
            $boards = \App\Http\Controllers\Organization\OrganizationProjectBoardController::class;
            $lists = \App\Http\Controllers\Organization\OrganizationProjectListController::class;
            $cards = \App\Http\Controllers\Organization\OrganizationProjectCardController::class;
            $extras = \App\Http\Controllers\Organization\OrganizationProjectCardExtrasController::class;

            Route::get('/', [$boards, 'index'])->middleware('permission:project.read')->name('index');
            Route::post('/', [$boards, 'store'])->middleware('permission:project.create')->name('store');
            Route::get('/{board}', [$boards, 'show'])->middleware('permission:project.read')->name('show');
            Route::put('/{board}', [$boards, 'update'])->middleware('permission:project.update')->name('update');
            Route::post('/{board}/star', [$boards, 'toggleStar'])->middleware('permission:project.update')->name('star');
            Route::post('/{board}/archive', [$boards, 'archive'])->middleware('permission:project.delete')->name('archive');
            Route::post('/{board}/restore', [$boards, 'restore'])->middleware('permission:project.update')->name('restore');
            Route::delete('/{board}', [$boards, 'destroy'])->middleware('permission:project.delete')->name('destroy');

            Route::post('/{board}/lists', [$lists, 'store'])->middleware('permission:project.create')->name('lists.store');
            Route::put('/{board}/lists/reorder', [$lists, 'reorder'])->middleware('permission:project.update')->name('lists.reorder');
            Route::put('/{board}/lists/{list}', [$lists, 'update'])->middleware('permission:project.update')->name('lists.update');
            Route::post('/{board}/lists/{list}/archive', [$lists, 'archive'])->middleware('permission:project.delete')->name('lists.archive');

            Route::post('/{board}/cards', [$cards, 'store'])->middleware('permission:project.create')->name('cards.store');
            Route::get('/{board}/cards/{card}', [$cards, 'show'])->middleware('permission:project.read')->name('cards.show');
            Route::put('/{board}/cards/{card}', [$cards, 'update'])->middleware('permission:project.update')->name('cards.update');
            Route::post('/{board}/cards/{card}/move', [$cards, 'move'])->middleware('permission:project.update')->name('cards.move');
            Route::post('/{board}/cards/{card}/archive', [$cards, 'archive'])->middleware('permission:project.delete')->name('cards.archive');
            Route::post('/{board}/cards/{card}/restore', [$cards, 'restore'])->middleware('permission:project.update')->name('cards.restore');
            Route::delete('/{board}/cards/{card}', [$cards, 'destroy'])->middleware('permission:project.delete')->name('cards.destroy');

            Route::post('/{board}/labels', [$extras, 'storeLabel'])->middleware('permission:project.create')->name('labels.store');
            Route::put('/{board}/labels/{label}', [$extras, 'updateLabel'])->middleware('permission:project.update')->name('labels.update');
            Route::delete('/{board}/labels/{label}', [$extras, 'destroyLabel'])->middleware('permission:project.delete')->name('labels.destroy');

            Route::post('/{board}/cards/{card}/labels', [$extras, 'syncCardLabels'])->middleware('permission:project.update')->name('cards.labels.sync');
            Route::post('/{board}/cards/{card}/members', [$extras, 'syncCardMembers'])->middleware('permission:project.update')->name('cards.members.sync');

            Route::post('/{board}/cards/{card}/checklists', [$extras, 'storeChecklist'])->middleware('permission:project.create')->name('checklists.store');
            Route::put('/{board}/cards/{card}/checklists/{checklist}', [$extras, 'updateChecklist'])->middleware('permission:project.update')->name('checklists.update');
            Route::delete('/{board}/cards/{card}/checklists/{checklist}', [$extras, 'destroyChecklist'])->middleware('permission:project.delete')->name('checklists.destroy');
            Route::post('/{board}/cards/{card}/checklists/{checklist}/items', [$extras, 'storeChecklistItem'])->middleware('permission:project.create')->name('checklist-items.store');
            Route::put('/{board}/cards/{card}/checklists/{checklist}/items/{item}', [$extras, 'updateChecklistItem'])->middleware('permission:project.update')->name('checklist-items.update');
            Route::delete('/{board}/cards/{card}/checklists/{checklist}/items/{item}', [$extras, 'destroyChecklistItem'])->middleware('permission:project.delete')->name('checklist-items.destroy');

            Route::post('/{board}/cards/{card}/comments', [$extras, 'storeComment'])->middleware('permission:project.create')->name('comments.store');
            Route::put('/{board}/cards/{card}/comments/{comment}', [$extras, 'updateComment'])->middleware('permission:project.create')->name('comments.update');
            Route::post('/{board}/cards/{card}/comments/{comment}/like', [$extras, 'toggleCommentLike'])->middleware('permission:project.read')->name('comments.like');
            Route::delete('/{board}/cards/{card}/comments/{comment}', [$extras, 'destroyComment'])->middleware('permission:project.create')->name('comments.destroy');

            Route::post('/{board}/cards/{card}/attachments', [$extras, 'storeAttachment'])->middleware('permission:project.create')->name('attachments.store');
            Route::delete('/{board}/cards/{card}/attachments/{attachment}', [$extras, 'destroyAttachment'])->middleware('permission:project.delete')->name('attachments.destroy');
        });

    // Organization BRP Wallet routes removed — organizations use the existing Believe Points (BP) wallet

    // old Facebook Integration Routes
    // Route::prefix('facebook')->group(function () {
    //     // Connection Management
    //     Route::get('/connect', [AuthController::class, 'connect'])->name('facebook.connect');
    //     Route::get('/callback', [AuthController::class, 'callback'])->name('facebook.callback');
    //     Route::get('/configure', [ConfigurationController::class, 'index'])
    //         ->name('facebook.configure');
    //     Route::post('/{id}/disconnect', [AuthController::class, 'disconnect'])->name('facebook.disconnect');
    //     Route::post('/{id}/refresh', [AuthController::class, 'refresh'])->name('facebook.refresh');
    //     Route::post('/{id}/set-default', [AuthController::class, 'setDefault'])->name('facebook.set-default');

    //     // Posts Management
    //     Route::prefix('/posts')->group(function () {
    //         Route::get('/', [PostController::class, 'index'])->name('facebook.posts.index');
    //         Route::get('/create', [PostController::class, 'create'])->name('facebook.posts.create');
    //         Route::post('/', [PostController::class, 'store'])->name('facebook.posts.store');
    //         Route::post('/{id}/publish', [PostController::class, 'publish'])->name('facebook.posts.publish');
    //         Route::delete('/{id}', [PostController::class, 'destroy'])->name('facebook.posts.destroy');
    //         Route::get('/{id}/analytics', [PostController::class, 'analytics'])->name('facebook.posts.analytics');
    //     });
    // });

    // Facebook Integration Routes
    Route::prefix('facebook')->group(function () {
        Route::get('/connect', [AuthController::class, 'connect'])->name('facebook.connect');
        Route::get('/oauth/redirect', [AuthController::class, 'redirectToFacebook'])->name('facebook.redirect');
        Route::get('/callback', [AuthController::class, 'callback'])->name('facebook.callback');
        Route::get('/select-pages', [AuthController::class, 'selectPages'])->name('facebook.select-pages');
        Route::post('/select-pages', [AuthController::class, 'storeSelectedPages'])->name('facebook.select-pages.store');
        Route::get('/{id}/content', [AuthController::class, 'pageContent'])->name('facebook.content');
        Route::post('/{id}/disconnect', [AuthController::class, 'disconnect'])->name('facebook.disconnect');
        Route::post('/{id}/refresh', [AuthController::class, 'refresh'])->name('facebook.refresh');
        Route::post('/{id}/set-default', [AuthController::class, 'setDefault'])->name('facebook.set-default');

        Route::prefix('/posts')->group(function () {
            Route::get('/', [PostController::class, 'index'])->name('facebook.posts.index');
            Route::get('/create', [PostController::class, 'create'])->name('facebook.posts.create');
            Route::post('/', [PostController::class, 'store'])->name('facebook.posts.store');
            Route::post('/{id}/publish', [PostController::class, 'publish'])->name('facebook.posts.publish');
            Route::delete('/{id}', [PostController::class, 'destroy'])->name('facebook.posts.destroy');
        });
    });
});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|organization_pending|care_alliance', 'topics.selected'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/project-applications', [FundraiseController::class, 'dashboardProjectApplications'])->name('dashboard.project-applications');
    Route::put('dashboard/project-applications/{lead}', [FundraiseController::class, 'updateProjectApplication'])->name('dashboard.project-applications.update')->where('lead', '[0-9]+');

    // Organization Livestreams
    Route::prefix('livestreams')->name('organization.livestreams.')->group(function () {
        Route::get('/', [LivestreamController::class, 'index'])->name('index');
        Route::get('/overlay-studio', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationIndex'])->name('overlay-studio');
        Route::patch('/overlay-studio', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationUpdate'])->name('overlay-studio.update');
        Route::post('/overlay-studio/logo', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationUploadLogo'])->name('overlay-studio.upload-logo');
        Route::post('/overlay-studio/sponsor', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationUploadSponsor'])->name('overlay-studio.upload-sponsor');
        Route::post('/overlay-studio/qr', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationUploadQr'])->name('overlay-studio.upload-qr');
        Route::delete('/overlay-studio/asset', [\App\Http\Controllers\LivestreamOverlayStudioController::class, 'organizationRemoveAsset'])->name('overlay-studio.remove-asset');
        Route::get('/recordings/branded-download', [SupporterLivestreamController::class, 'recordingBrandedDownload'])->name('recordings.branded-download');
        Route::get('/create', [LivestreamController::class, 'create'])->name('create');
        Route::post('/', [LivestreamController::class, 'store'])->name('store');
        Route::get('/{id}/ready', [LivestreamController::class, 'ready'])->name('ready');
        Route::get('/{id}', [LivestreamController::class, 'show'])->name('show');
        Route::post('/{id}/start-meeting', [LivestreamController::class, 'startMeeting'])->name('start-meeting');
        Route::post('/{id}/generate-invite', [LivestreamController::class, 'generateInviteToken'])->name('generate-invite');
        Route::post('/{id}/go-live', [LivestreamController::class, 'goLive'])->name('go-live');
        Route::post('/{id}/set-live', [LivestreamController::class, 'setLive'])->name('set-live');
        Route::post('/{id}/queue-stream-relay', [LivestreamController::class, 'queueStreamRelayJob'])->name('queue-stream-relay');
        Route::post('/{id}/go-live-obs-auto', [LivestreamController::class, 'queueStreamRelayJob'])->name('go-live-obs-auto');
        Route::post('/{id}/go-live-browser', [LivestreamController::class, 'goLiveBrowser'])->name('go-live-browser');
        Route::post('/{id}/end-stream', [LivestreamController::class, 'endStream'])->name('end-stream');
        Route::post('/{id}/abandon-host-session', [LivestreamController::class, 'abandonHostSession'])->name('abandon-host-session');
        Route::patch('/{id}/status', [LivestreamController::class, 'updateStatus'])->name('update-status');
        Route::patch('/{id}/stream-key', [LivestreamController::class, 'updateStreamKey'])->name('update-stream-key');
        Route::post('/{id}/prepare-youtube-live', [LivestreamController::class, 'prepareYouTubeLive'])->name('prepare-youtube-live');
        Route::patch('/{id}/visibility', [LivestreamController::class, 'updateVisibility'])->name('update-visibility');
        Route::delete('/{id}', [LivestreamController::class, 'destroy'])->name('destroy');
    });

    // Unity Meet host entry (organization accounts) — Meeting Ready page
    Route::get('unity-meet/host/organization/{id}', [LivestreamController::class, 'ready'])
        ->name('unity-meet.host.organization')
        ->where('id', '[0-9]+');

    // Nonprofit Barter Network (NNBN) – EIN + KYB + Board + Bridge + Admin approved only
    Route::middleware('barter.access')->prefix('barter')->name('barter.')->group(function () {
        Route::get('/', [BarterNetworkController::class, 'index'])->name('index');
        Route::get('/marketplace', [BarterNetworkController::class, 'marketplace'])->name('marketplace');
        Route::get('/my-listings', [BarterNetworkController::class, 'myListings'])->name('my-listings');
        Route::get('/listings/{listing}', [BarterNetworkController::class, 'showListing'])->name('listings.show');
        Route::post('/listings', [BarterNetworkController::class, 'storeListing'])->name('listings.store');
        Route::put('/listings/{listing}', [BarterNetworkController::class, 'updateListing'])->name('listings.update');
        Route::post('/request-trade', [BarterNetworkController::class, 'requestTrade'])->name('request-trade');
        Route::get('/incoming-requests', [BarterNetworkController::class, 'incomingRequests'])->name('incoming-requests');
        Route::post('/transactions/{transaction}/accept', [BarterNetworkController::class, 'acceptRequest'])->name('transactions.accept');
        Route::post('/transactions/{transaction}/reject', [BarterNetworkController::class, 'rejectRequest'])->name('transactions.reject');
        Route::get('/active-trades', [BarterNetworkController::class, 'activeTrades'])->name('active-trades');
        Route::get('/trade-history', [BarterNetworkController::class, 'tradeHistory'])->name('trade-history');
        Route::get('/points-wallet', [BarterNetworkController::class, 'pointsWallet'])->name('points-wallet');
        Route::get('/reputation', [BarterNetworkController::class, 'reputation'])->name('reputation');
    });

    Route::middleware('permission:dashboard.read')->group(function () {
        Route::get('/dashboard/compliance/apply', [ComplianceApplicationController::class, 'show'])->name('compliance.apply.show');
        Route::post('/dashboard/compliance/apply', [ComplianceApplicationController::class, 'store'])->name('compliance.apply.store');
        Route::get('/dashboard/compliance/apply/{application}/success', [ComplianceApplicationController::class, 'success'])->name('compliance.apply.success');
        Route::get('/dashboard/compliance/apply/{application}/cancel', [ComplianceApplicationController::class, 'cancel'])->name('compliance.apply.cancel');
    });

    // Form 1023 Application Routes - Only for organization users (not admins)
    Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|organization_pending|care_alliance', 'topics.selected'])->group(function () {
        Route::get('/dashboard/form1023/apply', [Form1023ApplicationController::class, 'show'])->name('form1023.apply.show');
        Route::post('/dashboard/form1023/apply', [Form1023ApplicationController::class, 'store'])->name('form1023.apply.store');
        Route::put('/dashboard/form1023/apply/{application}', [Form1023ApplicationController::class, 'update'])->name('form1023.apply.update');
        Route::post('/dashboard/form1023/apply/draft', [Form1023ApplicationController::class, 'saveAsDraft'])->name('form1023.apply.draft');
        Route::get('/dashboard/form1023/apply/{application}/view', [Form1023ApplicationController::class, 'view'])->name('form1023.apply.view');
        Route::post('/dashboard/form1023/apply/{application}/pay', [Form1023ApplicationController::class, 'initiatePayment'])->name('form1023.apply.pay');
        Route::get('/dashboard/form1023/apply/{application}/success', [Form1023ApplicationController::class, 'success'])->name('form1023.apply.success');
        Route::get('/dashboard/form1023/apply/{application}/cancel', [Form1023ApplicationController::class, 'cancel'])->name('form1023.apply.cancel');
    });

    Route::middleware('role:organization|organization_pending|care_alliance')->group(function () {
        Route::prefix('governance/onboarding')->name('governance.onboarding.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Organization\OrganizationOnboardingController::class, 'index'])->name('index');
            Route::post('/upload', [\App\Http\Controllers\Organization\OrganizationOnboardingController::class, 'upload'])->name('upload');
            Route::delete('/document', [\App\Http\Controllers\Organization\OrganizationOnboardingController::class, 'destroyDocument'])->name('document.destroy');
            Route::post('/authorized-signer', [\App\Http\Controllers\Organization\OrganizationOnboardingController::class, 'storeAuthorizedSigner'])->name('authorized-signer');
        });
    });

    Route::middleware('role:organization|organization_pending|care_alliance')->group(function () {
        Route::get('board-members/filing/pdf', [BoardMemberController::class, 'filingPdf'])
            ->name('board-members.filing-pdf');

        Route::resource('board-members', BoardMemberController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->shallow();

        Route::post('board-members/{boardMember}/status', [BoardMemberController::class, 'updateStatus'])
            ->name('board-members.status');
    });

    Route::middleware('role:organization|care_alliance')->group(function () {
        Route::get('/compliance', [GovernanceComplianceController::class, 'index'])->name('governance.compliance');

        Route::prefix('governance/storage')->name('governance.storage.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'index'])->name('index');
            Route::post('/provision', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'provision'])->name('provision');
            Route::post('/upload', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'upload'])->name('upload');
            Route::get('/download', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'download'])->name('download');
            Route::delete('/file', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'delete'])->name('delete');
            Route::put('/file', [\App\Http\Controllers\Organization\OrganizationStorageController::class, 'rename'])->name('rename');
        });

    });

    // Chunked Upload Routes
    Route::prefix('upload')->group(function () {
        Route::get('/', [ChunkedUploadController::class, 'index'])->name('upload.index');
        Route::post('/init', [ChunkedUploadController::class, 'initializeUpload'])->name('upload.init');
        Route::post('/chunk', [ChunkedUploadController::class, 'uploadChunk'])->name('upload.chunk');
        Route::get('/progress/{uploadId}', [ChunkedUploadController::class, 'getProgress'])->name('upload.progress');
        Route::delete('/cancel/{uploadId}', [ChunkedUploadController::class, 'cancelUpload'])->name('upload.cancel');
        Route::post('/cleanup', [ChunkedUploadController::class, 'cleanupExpiredSessions'])->name('upload.cleanup');
    });

    // Main upload routes
    Route::get('/chunked-upload', [ChunkedUploadController::class, 'index'])->name('chunked-upload');
    Route::post('/upload-chunk', [ChunkedUploadController::class, 'uploadChunk'])->name('upload-chunk');
    Route::get('/file-status/{fileId}', [ChunkedUploadController::class, 'getProgress'])->name('file-status');

    // Route::get('manage', [ManageDataController::class, "index"])->name('manage-data.index');
    // Route::get('manage/{id}', [ManageDataController::class, "detailsDataset"])->name('manage-dataset.index');

    // Manage Data Routes
    Route::get('/manage-data', [ManageDataController::class, 'index'])->name('manage-data');
    Route::delete('/manage-data/{id}', [ManageDataController::class, 'destroy'])->name('manage-data.destroy');

    // Manage Dataset Routes
    Route::get('/manage-dataset/{fileId}', [ManageDatasetController::class, 'show'])->name('manage-dataset.show');
    Route::get('/manage-dataset/{fileId}/download', [ManageDatasetController::class, 'downloadAll'])->name('manage-dataset.download');
    Route::post('/manage-dataset/{fileId}/bulk-delete', [ManageDatasetController::class, 'bulkDelete'])->name('manage-dataset.bulk-delete');
    Route::post('/manage-dataset/{fileId}/bulk-download', [ManageDatasetController::class, 'bulkDownload'])->name('manage-dataset.bulk-download');
    Route::post('/manage-dataset/{fileId}/rows/{rowId}/note', [ManageDatasetController::class, 'saveNote'])->name('manage-dataset.save-note');

    // Classification Codes Routes
    Route::resource('classification-codes', ClassificationCodeController::class)->except(['show'])->middleware([
        'index' => 'permission:classification.code.read',
        'create' => 'permission:classification.code.create',
        'store' => 'permission:classification.code.create',
        'edit' => 'permission:classification.code.edit',
        'update' => 'permission:classification.code.update',
        'destroy' => 'permission:classification.code.delete',
    ]);

    // NTEE Codes Routes
    Route::resource('ntee-codes', NteeCodeController::class)->except(['show'])->middleware([
        'index' => 'permission:ntee.code.read',
        'create' => 'permission:ntee.code.create',
        'store' => 'permission:ntee.code.create',
        'edit' => 'permission:ntee.code.edit',
        'update' => 'permission:ntee.code.update',
        'destroy' => 'permission:ntee.code.delete',
    ]);

    // Status Codes Routes
    Route::resource('status-codes', StatusCodeController::class)->except(['show'])->middleware([
        'index' => 'permission:status.code.read',
        'create' => 'permission:status.code.create',
        'store' => 'permission:status.code.create',
        'edit' => 'permission:status.code.edit',
        'update' => 'permission:status.code.update',
        'destroy' => 'permission:status.code.delete',
    ]);

    // Deductibility Codes Routes
    Route::resource('deductibility-codes', DeductibilityCodeController::class)->except(['show'])->middleware([
        'index' => 'permission:deductibility.code.read',
        'create' => 'permission:deductibility.code.create',
        'store' => 'permission:deductibility.code.create',
        'edit' => 'permission:deductibility.code.edit',
        'update' => 'permission:deductibility.code.update',
        'destroy' => 'permission:deductibility.code.delete',
    ]);

    /* Product Routes */
    Route::resource('products', ProductController::class)->except(['show'])->middleware([
        'index' => 'permission:product.read',
        'create' => 'permission:product.create',
        'store' => 'permission:product.create',
        'edit' => 'permission:product.edit',
        'update' => 'permission:product.update',
        'destroy' => 'permission:product.delete',
    ]);

    // Seller / admin: view and manage bids for a product
    Route::get('/products/{product}/bids', [ProductController::class, 'bidsIndex'])
        ->name('products.bids.index')
        ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:product.read']);
    Route::post('/products/{product}/bids/{bid}/cancel', [ProductController::class, 'cancelBid'])
        ->name('products.bids.cancel')
        ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:product.update']);
    Route::post('/products/{product}/close-bidding', [ProductController::class, 'closeBidding'])
        ->name('products.bids.close')
        ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:product.update']);
    Route::post('/products/{product}/bids/{bid}/pick-winner', [ProductController::class, 'pickWinner'])
        ->name('products.bids.pick-winner')
        ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:product.update']);

    // Admin/Organization show route for managing their products (must come after public route)
    // This route will only be used when user is authenticated and has permission
    Route::get('/products/{id}/manage', [ProductController::class, 'show'])->name('products.show.manage')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'permission:product.read']);
});

// Winner pay flow (auth only; controller checks winner)
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/products/{product}/winning-bid/shipping', [ProductController::class, 'winningBidShipping'])
        ->name('products.winning-bid.shipping');
    Route::get('/products/{product}/winning-bid/shipping-rates', [ProductController::class, 'winningBidShippingRatesJson'])
        ->name('products.winning-bid.shipping-rates');
    Route::post('/products/{product}/pay-winning-bid', [ProductController::class, 'createWinningBidCheckout'])
        ->name('products.winning-bid.checkout');
    Route::get('/products/{product}/winning-bid-success', [ProductController::class, 'winningBidPaymentSuccess'])
        ->name('products.winning-bid.success');
});

// Public route for products (plural) - must come AFTER resource routes to avoid conflict with /products/create
// This route is for marketplace viewing (no auth required)
Route::get('/products/{id}', [ProductController::class, 'show'])->name('products.show.public');

// Printify Integration Routes
Route::middleware(['auth', 'topics.selected', 'role:admin|organization|care_alliance'])->group(function () {
    Route::get('/printify/providers', [PrintifyProductController::class, 'getProviders'])->name('printify.providers');
    Route::get('/printify/provider-comparison', [PrintifyProductController::class, 'getProviderComparison'])->name('printify.provider-comparison');
    Route::get('/printify/variants', [PrintifyProductController::class, 'getVariants'])->name('printify.variants');
    Route::get('/printify/shipping', [PrintifyProductController::class, 'getShipping'])->name('printify.shipping');
    Route::post('/printify/preview-variant-costs', [PrintifyProductController::class, 'previewVariantCosts'])
        ->middleware('throttle:12,1')
        ->name('printify.preview-variant-costs');
    // Route::post('/printify/products/sync', [ProductController::class, 'syncFromPrintify'])->name('printify.products.sync');
});

/* Category Routes — global catalog; admin only (not organization dashboard) */
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:admin'])->group(function () {
    Route::resource('categories', CategoryController::class)->except(['show'])->middleware([
        'index' => 'permission:category.read',
        'create' => 'permission:category.create',
        'store' => 'permission:category.create',
        'edit' => 'permission:category.edit',
        'update' => 'permission:category.update',
        'destroy' => 'permission:category.delete',
    ]);
});

/* Raffle Routes */
Route::resource('raffles', RaffleController::class)->middleware([
    'index' => 'permission:raffle.read',
    'create' => 'permission:raffle.create',
    'store' => 'permission:raffle.create',
    'show' => 'permission:raffle.read',
    'edit' => 'permission:raffle.edit',
    'update' => 'permission:raffle.edit',
    'destroy' => 'permission:raffle.delete',
]);

Route::post('raffles/{raffle}/purchase', [RaffleController::class, 'purchaseTickets'])->name('raffles.purchase')->middleware('permission:raffle.purchase');
Route::post('raffles/{raffle}/draw', [RaffleController::class, 'drawWinners'])->name('raffles.draw')->middleware('permission:raffle.draw');

Route::resource('position-categories', PositionCategoryController::class)->except(['show'])->middleware([
    'index' => 'permission:job.position.categories.read',
    'create' => 'permission:job.position.categories.create',
    'store' => 'permission:job.position.categories.create',
    'edit' => 'permission:job.position.categories.edit',
    'update' => 'permission:job.position.categories.update',
    'destroy' => 'permission:job.position.categories.delete',
]);

Route::resource('job-positions', JobPositionController::class)->except(['show'])->middleware([
    'index' => 'permission:job.positions.read',
    'create' => 'permission:job.positions.create',
    'store' => 'permission:job.positions.create',
    'edit' => 'permission:job.positions.edit',
    'update' => 'permission:job.positions.update',
    'destroy' => 'permission:job.positions.delete',
]);

Route::resource('job-posts', JobPostController::class)->middleware([
    'index' => 'permission:job.posts.read',
    'create' => 'permission:job.posts.create',
    'store' => 'permission:job.posts.create',
    'show' => 'permission:job.posts.read',
    'edit' => 'permission:job.posts.edit',
    'update' => 'permission:job.posts.update',
    'destroy' => 'permission:job.posts.delete',
]);

// job applications routes
Route::resource('job-applications', JobApplicationController::class)->middleware([
    'index' => 'permission:job.posts.read',
    'create' => 'permission:job.posts.read',
    'store' => 'permission:job.posts.read',
    'show' => 'permission:job.posts.read',
    'edit' => 'permission:job.posts.read',
    'update' => 'permission:job.posts.read',
    'destroy' => 'permission:job.posts.read',
]);
Route::put('job-applications/{jobApplication}/update-status', [JobApplicationController::class, 'updateStatus'])
    ->name('job-applications.update-status')
    ->middleware(['role:organization|care_alliance', 'permission:job.posts.read']);

// Volunteers Routes
Route::get('explore-by-cause', [ExploreByCauseController::class, 'index'])
    ->name('explore-by-cause.index')
    ->middleware(['auth', 'EnsureEmailIsVerified']);

Route::post('explore-by-cause/toggle-interest/{category}', [ExploreByCauseController::class, 'toggleUserInterest'])
    ->name('explore-by-cause.toggle-interest')
    ->middleware(['auth', 'EnsureEmailIsVerified'])
    ->where('category', '[0-9]+');

Route::get('supporter-activity', [SupporterActivityController::class, 'index'])
    ->name('supporter-activity.index')
    ->middleware(['role:organization|admin|care_alliance', 'permission:dashboard.read']);
Route::get('supporter-activity/supporters/{supporter}', [SupporterActivityController::class, 'show'])
    ->name('supporter-activity.show')
    ->middleware(['role:organization|admin|care_alliance', 'permission:dashboard.read']);

Route::get('volunteers', [VolunteerController::class, 'index'])
    ->name('volunteers.index')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.read']);
Route::get('volunteers/supporter-interests', [VolunteerSupporterInterestsController::class, 'index'])
    ->name('volunteers.supporter-interests.index')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.read']);
Route::get('volunteers/volunteer-interests', [VolunteerAvailableSupportersController::class, 'index'])
    ->name('volunteers.volunteer-interests')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.read']);

// Volunteer Time Sheet Routes (must come before volunteers/{volunteer} to avoid route conflicts)
// IMPORTANT: Specific routes (like fetch-volunteers) must come BEFORE parameterized routes ({timesheet})
Route::get('volunteers/timesheet', [VolunteerTimesheetController::class, 'index'])
    ->name('volunteers.timesheet.index')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.read']);
Route::get('volunteers/timesheet/create', [VolunteerTimesheetController::class, 'create'])
    ->name('volunteers.timesheet.create')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.create']);
Route::get('volunteers/timesheet/fetch-volunteers', [VolunteerTimesheetController::class, 'fetchVolunteers'])
    ->name('volunteers.timesheet.fetch-volunteers')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.create']);
Route::post('volunteers/timesheet', [VolunteerTimesheetController::class, 'store'])
    ->name('volunteers.timesheet.store')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.create']);
Route::get('volunteers/timesheet/{timesheet}', [VolunteerTimesheetController::class, 'show'])
    ->name('volunteers.timesheet.show')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.read']);
Route::get('volunteers/timesheet/{timesheet}/edit', [VolunteerTimesheetController::class, 'edit'])
    ->name('volunteers.timesheet.edit')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.edit']);
Route::put('volunteers/timesheet/{timesheet}', [VolunteerTimesheetController::class, 'update'])
    ->name('volunteers.timesheet.update')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.update']);
Route::put('volunteers/timesheet/{timesheet}/status', [VolunteerTimesheetController::class, 'updateStatus'])
    ->name('volunteers.timesheet.update-status')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.update']);
Route::delete('volunteers/timesheet/{timesheet}', [VolunteerTimesheetController::class, 'destroy'])
    ->name('volunteers.timesheet.destroy')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.timesheet.delete']);

Route::get('volunteers/{volunteer}', [VolunteerController::class, 'show'])
    ->name('volunteers.show')
    ->middleware(['role:organization|care_alliance', 'permission:volunteer.read']);

// Events Routes (create/store: nonprofit roles + event.create — see EnsureCanCreateEvents)
Route::resource('events', EventController::class)->middleware([
    'index' => 'permission:event.read',
    'create' => 'can.create.events',
    'store' => 'can.create.events',
    'show' => 'permission:event.read',
    'edit' => 'permission:event.edit',
    'update' => 'permission:event.update',
    'destroy' => 'permission:event.delete',
]);
Route::get('/events/{event}/update-status', [EventController::class, 'updateStatus'])
    ->name('events.update-status')
    ->middleware('permission:event.update');
Route::get('/api/events/dashboard', [EventController::class, 'dashboard'])
    ->name('events.dashboard')
    ->middleware('permission:event.read');

// role and permission routes
Route::get('/permission-management', [RolePermissionController::class, 'index']);
Route::get('/role-management', [RolePermissionController::class, 'roleManagement']);
Route::get('/user-permission', [RolePermissionController::class, 'userPermission']);

Route::prefix('permissions')->group(function () {
    Route::get('/', [RolePermissionController::class, 'index'])->name('permissions.overview');

    // Role Management
    Route::get('/roles', [RolePermissionController::class, 'roleManagement'])->name('roles.list');
    Route::get('/roles/create', [RolePermissionController::class, 'createRole'])->name('roles.create');
    Route::post('/roles', [RolePermissionController::class, 'storeRole'])->name('roles.store');
    Route::get('/roles/{role}/edit', [RolePermissionController::class, 'editRole'])->name('roles.edit');
    Route::put('/roles/{role}', [RolePermissionController::class, 'updateRole'])->name('roles.update');
    Route::delete('/roles/{role}', [RolePermissionController::class, 'destroyRole'])->name('roles.destroy');
});

// User Management (separate from permissions)
Route::prefix('users')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    Route::get('/', [RolePermissionController::class, 'userPermission'])->name('users.list');
    Route::get('/create', [RolePermissionController::class, 'createUser'])->name('users.create');
    Route::post('/', [RolePermissionController::class, 'storeUser'])->name('users.store');
    Route::get('/{user}/edit', [RolePermissionController::class, 'editUser'])->name('users.edit');
    Route::put('/{user}', [RolePermissionController::class, 'updateUser'])->name('users.update');
    Route::delete('/{user}', [RolePermissionController::class, 'destroyUser'])->name('users.destroy');
    Route::post('/{user}/impersonate', [RolePermissionController::class, 'impersonate'])->name('users.impersonate');
    Route::post('/{user}/reset-password', [RolePermissionController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('/{user}/toggle-login-disable', [RolePermissionController::class, 'toggleLoginDisable'])->name('users.toggle-login-disable');
    Route::post('/{user}/verify-email', [RolePermissionController::class, 'verifyEmail'])->name('users.verify-email');
});
Route::resource('deductibility-codes', DeductibilityCodeController::class)->except(['show'])->middleware([
    'index' => 'permission:deductibility.code.read',
    'create' => 'permission:deductibility.code.create',
    'store' => 'permission:deductibility.code.create',
    'edit' => 'permission:deductibility.code.edit',
    'update' => 'permission:deductibility.code.update',
    'destroy' => 'permission:deductibility.code.delete',
]);

/* orders Routes */
Route::resource('orders', OrderController::class)->middleware([
    'index' => 'permission:ecommerce.read',
    'create' => 'permission:ecommerce.create',
    'store' => 'permission:ecommerce.create',
    'show' => 'permission:ecommerce.read',
    'edit' => 'permission:ecommerce.edit',
    'update' => 'permission:ecommerce.update',
    'destroy' => 'permission:ecommerce.delete',
]);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancelOrder'])
    ->name('orders.cancel')
    ->middleware('permission:ecommerce.update');

// Admin only route to view items by organization
Route::get('/orders/{order}/items-by-organization', [OrderController::class, 'itemsByOrganization'])
    ->name('orders.items-by-organization')
    ->middleware('permission:ecommerce.read');

Route::get('/orders/{order}/shippo/rates', [OrderController::class, 'getShippoRates'])
    ->name('orders.shippo.rates')
    ->middleware('permission:ecommerce.read');
Route::post('/orders/{order}/shippo/purchase-label', [OrderController::class, 'purchaseShippoLabel'])
    ->name('orders.shippo.purchase-label')
    ->middleware('permission:ecommerce.update');

Route::post('/orders/{order}/items/{orderItem}/digital-deliveries', [App\Http\Controllers\DigitalDeliveryController::class, 'uploadOrderItemFiles'])
    ->name('orders.digital-deliveries.upload')
    ->middleware('permission:ecommerce.update');
Route::delete('/orders/{order}/items/{orderItem}/digital-deliveries/{delivery}', [App\Http\Controllers\DigitalDeliveryController::class, 'deleteOrderItemDelivery'])
    ->name('orders.digital-deliveries.delete')
    ->middleware('permission:ecommerce.update');
Route::post('/products/{product}/digital-files', [App\Http\Controllers\DigitalDeliveryController::class, 'uploadCatalogForProduct'])
    ->name('products.digital-files.upload')
    ->middleware('permission:ecommerce.update');
Route::delete('/digital-product-files/{digitalProductFile}', [App\Http\Controllers\DigitalDeliveryController::class, 'deleteCatalogFile'])
    ->name('digital-product-files.delete')
    ->middleware('permission:ecommerce.update');

Route::get('/digital-deliveries/{delivery}/download', [App\Http\Controllers\DigitalDeliveryController::class, 'download'])
    ->name('digital-deliveries.download')
    ->middleware('auth');

/* Order Items Routes */
// Route::resource('order-items', OrderItemController::class)->middleware([
//     'index' => 'permission:ecommerce.read',
//     'show' => 'permission:ecommerce.read',
// ]);

// Purchase Order Routes
Route::get('/purchase-orders', [PurchaseController::class, 'index'])->name('purchase-orders.index')->middleware('permission:ecommerce.read');
Route::get('/purchase-orders/create', [PurchaseController::class, 'create'])->name('purchase-orders.create')->middleware('permission:ecommerce.create');
Route::post('/purchase-orders', [PurchaseController::class, 'store'])->name('purchase-orders.store')->middleware('permission:ecommerce.create');
Route::get('/purchase-orders/{id}/edit', [PurchaseController::class, 'edit'])->name('purchase-orders.edit')->middleware('permission:ecommerce.edit');
Route::put('/purchase-orders/{id}', [PurchaseController::class, 'update'])->name('purchase-orders.update')->middleware('permission:ecommerce.update');
Route::delete('/purchase-orders/{id}', [PurchaseController::class, 'destroy'])->name('purchase-orders.destroy')->middleware('permission:ecommerce.delete');

// Node Boss Routes
Route::get('/node-boss/create', [NodeBossController::class, 'create'])->name('node-boss.create')->middleware('permission:node.referral.create');
Route::post('/node-boss/store', [NodeBossController::class, 'store'])->name('node-boss.store')->middleware('permission:node.referral.create');
Route::get('/node-boss/{id}/edit', [NodeBossController::class, 'edit'])->name('node-boss.edit')->middleware('permission:node.referral.edit');
Route::put('/node-boss/{id}', [NodeBossController::class, 'update'])->name('node-boss.update')->middleware('permission:node.referral.update');
Route::delete('/node-boss/{id}', [NodeBossController::class, 'destroy'])->name('node-boss.destroy')->middleware('permission:node.referral.delete');
Route::get('/node-boss', [NodeBossController::class, 'index'])->name('node-boss.index')->middleware('permission:node.referral.read');
Route::get('/node-boss/{id}', [NodeBossController::class, 'show'])->name('node-boss.show')->middleware('permission:node.referral.read');

// node boss referral
Route::resource('node-referral', NodeReferralController::class)->middleware([
    'index' => 'permission:node.referral.read',
    'create' => 'permission:node.referral.create',
    'store' => 'permission:node.referral.create',
    'show' => 'permission:node.referral.read',
    'edit' => 'permission:node.referral.edit',
    'update' => 'permission:node.referral.update',
    'destroy' => 'permission:node.referral.delete',
]);

// New Withdrawal resource routes
Route::resource('withdrawals', WithdrawalController::class)->middleware([
    'index' => 'permission:withdrawal.read',
    'create' => 'permission:withdrawal.create',
    'store' => 'permission:withdrawal.create',
    'show' => 'permission:withdrawal.read',
    'edit' => 'permission:withdrawal.edit',
    'update' => 'permission:withdrawal.update',
    'destroy' => 'permission:withdrawal.delete',
]);

// Custom routes for withdrawal actions
Route::post('withdrawals/{withdrawal}/accept', [WithdrawalController::class, 'accept'])->name('withdrawals.accept');
Route::post('withdrawals/{withdrawal}/make-payment', [WithdrawalController::class, 'makePayment'])->name('withdrawals.makePayment');

// Route::prefix('settings')->group(function () {
//     Route::get('/payment-methods', [PaymentMethodSettingController::class, 'index'])->name('payment-methods.index');
//     Route::post('/payment-methods', [PaymentMethodSettingController::class, 'update'])->name('payment-methods.update');
// });

// Newsletter Routes (auth required — guests are redirected to login, not 403)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->prefix('newsletter')->name('newsletter.')->group(function () {
    Route::get('/', [NewsletterController::class, 'index'])->name('index');
    Route::get('/templates', [NewsletterController::class, 'templates'])->name('templates');
    Route::get('/templates/create', [NewsletterController::class, 'createTemplate'])->name('templates.create');
    Route::post('/templates', [NewsletterController::class, 'storeTemplate'])->name('templates.store');
    Route::post('/templates/ai-generate', [NewsletterController::class, 'generateTemplateWithAi'])->name('templates.ai-generate');
    Route::get('/templates/{id}', [NewsletterController::class, 'showTemplate'])->name('templates.show');
    Route::get('/templates/{id}/edit', [NewsletterController::class, 'editTemplate'])->name('templates.edit');
    Route::put('/templates/{id}', [NewsletterController::class, 'updateTemplate'])->name('templates.update');
    Route::delete('/templates/{id}', [NewsletterController::class, 'destroyTemplate'])->name('templates.destroy');
    Route::get('/recipients', [NewsletterController::class, 'recipients'])->name('recipients');
    Route::post('/recipients', [NewsletterController::class, 'storeRecipient'])->name('recipients.store');
    Route::post('/recipients/subscribe/{organizationId}', [NewsletterController::class, 'subscribeOrganization'])->name('recipients.subscribe');
    Route::post('/recipients/unsubscribe/{organizationId}', [NewsletterController::class, 'unsubscribeOrganization'])->name('recipients.unsubscribe');
    Route::post('/recipients/test-email', [NewsletterController::class, 'sendTestEmail'])->name('recipients.test-email');
    Route::get('/recipients/export', [NewsletterController::class, 'exportRecipients'])->name('recipients.export');
    Route::post('/recipients/import', [NewsletterController::class, 'importRecipients'])->name('recipients.import');
    Route::post('/recipients/manual/{recipientId}/subscribe', [NewsletterController::class, 'subscribeManualRecipient'])->name('recipients.manual.subscribe');
    Route::post('/recipients/manual/{recipientId}/unsubscribe', [NewsletterController::class, 'unsubscribeManualRecipient'])->name('recipients.manual.unsubscribe');
    Route::patch('/recipients/manual/{recipient}', [NewsletterController::class, 'updateManualRecipient'])->name('recipients.manual.update');
    Route::delete('/recipients/manual/{recipient}', [NewsletterController::class, 'destroyManualRecipient'])->name('recipients.manual.destroy');
    Route::get('/export', [NewsletterController::class, 'export'])->name('export');
    Route::get('/create', [NewsletterController::class, 'create'])->name('create');
    Route::post('/purchase-sms', [NewsletterController::class, 'purchaseSms'])->name('purchase-sms');
    Route::get('/purchase-sms/success', [NewsletterController::class, 'purchaseSmsSuccess'])->name('purchase-sms.success');
    Route::post('/purchase-pro-targeting', [NewsletterController::class, 'purchaseProTargeting'])->name('purchase-pro-targeting');
    Route::get('/purchase-pro-targeting/success', [NewsletterController::class, 'purchaseProTargetingSuccess'])->name('purchase-pro-targeting.success');
    Route::post('/sms-wallet-preferences', [NewsletterController::class, 'updateSmsWalletPreferences'])->name('sms-wallet-preferences');
    Route::get('/sms-auto-recharge/setup', [NewsletterController::class, 'smsAutoRechargeSetupPayment'])->name('sms-auto-recharge.setup');
    Route::get('/sms-auto-recharge/setup-success', [NewsletterController::class, 'smsAutoRechargeSetupSuccess'])->name('sms-auto-recharge.setup-success');
    Route::post('/sms-auto-recharge/remove-payment-method', [NewsletterController::class, 'smsAutoRechargeRemovePaymentMethod'])->name('sms-auto-recharge.remove-payment');
    Route::post('/create/ai-generate', [NewsletterController::class, 'generateNewsletterCreateWithAi'])->name('create.ai-generate');
    Route::get('/create-advanced', [NewsletterController::class, 'createAdvanced'])->name('create-advanced');
    Route::post('/', [NewsletterController::class, 'store'])->name('store');
    Route::get('/{id}', [NewsletterController::class, 'show'])->name('show');
    Route::get('/{id}/edit', [NewsletterController::class, 'edit'])->name('edit');
    Route::put('/{id}', [NewsletterController::class, 'update'])->name('update');
    Route::put('/{id}/schedule', [NewsletterController::class, 'updateSchedule'])->name('update-schedule');
    Route::post('/{id}/pause', [NewsletterController::class, 'pause'])->name('pause');
    Route::post('/{id}/resume', [NewsletterController::class, 'resume'])->name('resume');
    Route::post('/{id}/manual-send', [NewsletterController::class, 'manualSend'])->name('manual-send');
    Route::delete('/{id}', [NewsletterController::class, 'destroy'])->name('destroy');
    Route::post('/{id}/send', [NewsletterController::class, 'send'])->name('send');
});

// Public Newsletter Routes
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

// Public Course Routes
Route::get('/courses', [CourseController::class, 'publicIndex'])->name('course.index');
Route::get('/courses/{course:slug}', [CourseController::class, 'publicShow'])->name('course.show'); // Use slug for public show
// Ownership Verification Routes
// Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
//     // Ownership Verification routes
//     Route::get('/verification/ownership', [OwnershipVerificationController::class, 'show'])->name('verification.ownership');
//     Route::post('/verification/ownership/verify', [OwnershipVerificationController::class, 'verify'])->name('verification.verify');
//     Route::get('/verification/results', [OwnershipVerificationController::class, 'results'])->name('verification.results');
//     Route::post('/verification/ownership/retry', [OwnershipVerificationController::class, 'retry'])->name('verification.retry');
// });
// Enrollment routes (require authentication)
Route::middleware(['auth', 'topics.selected'])->group(function () {
    Route::get('/courses/{course:slug}/enroll', [EnrollmentController::class, 'show'])->name('courses.enroll');
    Route::post('/courses/{course:slug}/enroll', [EnrollmentController::class, 'store'])->name('courses.enroll.store');
    Route::post('/courses/{course:slug}/cancel', [EnrollmentController::class, 'cancel'])->name('courses.cancel');
    Route::post('/courses/{course:slug}/refund', [EnrollmentController::class, 'refund'])->name('courses.refund');
    Route::get('/courses/enrollment/success', [EnrollmentController::class, 'success'])->name('courses.enrollment.success');
    Route::get('/courses/enrollment/cancel/{enrollment}', [EnrollmentController::class, 'cancel'])->name('courses.enrollment.cancel');
    Route::get('/profile/my-enrollments', [EnrollmentController::class, 'myEnrollments'])->name('enrollments.my');
    Route::post('/profile/my-enrollments/notification-preferences', [EnrollmentController::class, 'updateNotificationPreferences'])->name('enrollments.notification-preferences');
    Route::get('/profile/course', [FrontendCourseController::class, 'adminIndex'])->name('profile.course.index');
    Route::get('/profile/course/enrollments', [FrontendCourseController::class, 'allEnrollments'])->name('profile.course.enrollments')->middleware('permission:course.read');
    Route::get('/profile/course/create', [FrontendCourseController::class, 'create'])->name('profile.course.create')->middleware('permission:course.create');
    Route::post('/profile/course/unity-meet/prepare', [CourseUnityMeetController::class, 'prepare'])->name('profile.course.unity-meet.prepare')->middleware('permission:course.create');
    Route::post('/profile/course', [FrontendCourseController::class, 'store'])->name('profile.course.store')->middleware('permission:course.create');
    Route::get('/profile/course/{course:slug}', [FrontendCourseController::class, 'adminShow'])->name('profile.course.show')->middleware('permission:course.read'); // Added this line
    Route::get('/profile/course/{course:slug}/enrollments', [FrontendCourseController::class, 'adminEnrollments'])->name('profile.course.enrollments.show')->middleware('permission:course.read');
    Route::get('/profile/course/{course:slug}/edit', [FrontendCourseController::class, 'edit'])->name('profile.course.edit')->middleware('permission:course.edit');

    // Frontend User Events Routes
    Route::get('/profile/events', [EventController::class, 'userEvents'])->name('profile.events.index')->middleware('permission:event.read');
    Route::get('/profile/events/create', [EventController::class, 'userCreate'])->name('profile.events.create')->middleware('permission:event.create');
    Route::post('/profile/events', [EventController::class, 'userStore'])->name('profile.events.store')->middleware('permission:event.create');
    Route::get('/profile/events/{event}', [EventController::class, 'userShow'])->name('profile.events.show')->middleware('permission:event.read');
    Route::get('/profile/events/{event}/edit', [EventController::class, 'userEdit'])->name('profile.events.edit')->middleware('permission:event.edit');
    Route::put('/profile/events/{event}', [EventController::class, 'userUpdate'])->name('profile.events.update')->middleware('permission:event.update');
    Route::delete('/profile/events/{event}', [EventController::class, 'userDestroy'])->name('profile.events.destroy')->middleware('permission:event.delete');

    // Frontend User Raffle Tickets Routes
    Route::get('/profile/raffle-tickets', [UserProfileController::class, 'raffleTickets'])->name('profile.raffle-tickets.index');

    Route::put('/profile/course/{course:slug}', [FrontendCourseController::class, 'update'])->name('profile.course.update')->middleware('permission:course.update');
    Route::post('/profile/course/{course:slug}/cancel', [FrontendCourseController::class, 'cancel'])->name('profile.course.cancel')->middleware('permission:course.update');
    Route::delete('/profile/course/{course:slug}', [FrontendCourseController::class, 'destroy'])->name('profile.course.destroy')->middleware('permission:course.delete');
});
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    // Admin Course Management Routes
    Route::prefix('admin/courses-events')->name('admin.courses.')->group(function () {
        Route::get('/', [CourseController::class, 'adminIndex'])->name('index')->middleware('permission:course.read');
        Route::get('/create', [CourseController::class, 'create'])->name('create')->middleware('permission:course.create');
        Route::post('/unity-meet/prepare', [CourseUnityMeetController::class, 'prepare'])->name('unity-meet.prepare')->middleware('permission:course.create');
        Route::post('/', [CourseController::class, 'store'])->name('store')->middleware('permission:course.create');
        Route::get('/{course:slug}', [CourseController::class, 'adminShow'])->name('show')->middleware('permission:course.read');
        Route::get('/{course:slug}/enrollments', [CourseController::class, 'adminEnrollments'])->name('enrollments')->middleware('permission:course.read');
        Route::get('/{course:slug}/edit', [CourseController::class, 'edit'])->name('edit')->middleware('permission:course.edit');
        Route::put('/{course:slug}', [CourseController::class, 'update'])->name('update')->middleware('permission:course.update');
        Route::post('/{course:slug}/cancel', [CourseController::class, 'cancel'])->name('cancel')->middleware('permission:course.update');
        Route::delete('/{course:slug}', [CourseController::class, 'destroy'])->name('destroy')->middleware('permission:course.delete');
    });

    // Topic Management Routes (Admin Only)
    Route::resource('topics', TopicController::class)->only(['index', 'store', 'update', 'destroy'])->middleware([
        'index' => 'can.read.topics',
        'store' => 'permission:topic.create',
        'update' => 'permission:topic.update',
        'destroy' => 'permission:topic.delete',
    ]);

    // Event types (admin CRUD; org / Care Alliance read — index matches EnsureCanReadEventTypes + controller)
    Route::resource('event-types', EventTypeController::class)->only(['index', 'store', 'update', 'destroy'])->middleware([
        'index' => 'can.read.event_types',
        'store' => 'permission:event_type.create',
        'update' => 'permission:event_type.update',
        'destroy' => 'permission:event_type.delete',
    ]);
});

// Plaid Verification routes
Route::middleware(['auth', 'topics.selected'])->group(function () {
    Route::get('/verification/ownership', [PlaidVerificationController::class, 'show'])->name('verification.ownership');
    Route::get('/verification/results', [PlaidVerificationController::class, 'results'])->name('verification.results');
    Route::post('/verification/download-certificate', [PlaidVerificationController::class, 'downloadCertificate'])->name('verification.download-certificate');
    Route::post('/verification/retry', [PlaidVerificationController::class, 'retry'])->name('verification.retry');
});

// Plaid API routes
Route::middleware(['auth', 'topics.selected'])->prefix('api/plaid')->group(function () {
    Route::post('/create-link-token', [PlaidVerificationController::class, 'createLinkToken']);
    Route::post('/exchange-token', [PlaidVerificationController::class, 'exchangeToken']);
    Route::post('/verify-ownership', [PlaidVerificationController::class, 'verifyOwnership']);
});

// Plaid webhook (no auth required)
Route::post('/api/plaid/webhook', function () {
    // Handle Plaid webhooks
    return response()->json(['status' => 'success']);
});

// printify webhook
Route::prefix('webhooks')->group(function () {
    Route::post('/printify/orders', [PrintifyWebhookController::class, 'handleOrderWebhook']);
    // Bridge webhook (no auth required - signature verified)
    // Allow HEAD/GET for webhook endpoint verification, POST for actual webhooks
    Route::match(['get', 'head'], '/bridge', function () {
        return response()->json(['status' => 'ok', 'message' => 'Bridge webhook endpoint is active'], 200);
    });
    Route::post('/bridge', [BridgeWebhookController::class, 'handle'])->name('webhooks.bridge');
    // Phaze webhook (no auth required - API key verified in controller)
    Route::post('/phaze', [PhazeWebhookController::class, 'handle'])->name('webhooks.phaze');
});

Route::prefix('admin')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:admin|'])->group(function () {
    // Wallet Fees Management
    Route::prefix('wallet-fees')->name('admin.wallet-fees.')->group(function () {
        Route::get('/', [WalletFeeController::class, 'index'])->name('index');
        Route::put('/{walletFee}', [WalletFeeController::class, 'update'])->name('update');
        Route::post('/{walletFee}/toggle', [WalletFeeController::class, 'toggleActive'])->name('toggle');
    });

    Route::get('/webhooks', [WebhookManagementController::class, 'index'])->name('admin.webhooks.index');
    Route::post('/webhooks/setup-printify', [WebhookManagementController::class, 'setupWebhooks'])->name('admin.webhooks.setup');
    Route::get('/webhooks/printify', [WebhookManagementController::class, 'getWebhooks'])->name('admin.webhooks.get');
    Route::delete('/webhooks/printify/{webhookId}', [WebhookManagementController::class, 'deleteWebhook'])->name('admin.webhooks.delete');

    // FCM device management (admin only)
    Route::get('/push-notifications/devices', [PushNotificationsController::class, 'devices'])->name('admin.push-notifications.devices');
    Route::post('/push-notifications/send-test', [PushNotificationsController::class, 'sendTest'])->name('admin.push-notifications.send-test');
    Route::post('/push-notifications/request-reregister', [PushNotificationsController::class, 'requestReregister'])->name('admin.push-notifications.request-reregister');
    Route::post('/push-notifications/invalidate-token', [PushNotificationsController::class, 'invalidateToken'])->name('admin.push-notifications.invalidate-token');
});

// Push notification logs (platform admin + org-scoped users)
Route::prefix('admin/push-notifications')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])
    ->name('admin.push-notifications.')
    ->group(function () {
        Route::get('/', [PushNotificationLogController::class, 'index'])->name('index');
        Route::get('/export/csv', [PushNotificationLogController::class, 'exportCsv'])->name('export.csv');
        Route::get('/export/excel', [PushNotificationLogController::class, 'exportExcel'])->name('export.excel');
        Route::get('/{pushNotificationLog}', [PushNotificationLogController::class, 'show'])->name('show');
        Route::post('/{pushNotificationLog}/repush', [PushNotificationLogController::class, 'repush'])->name('repush');
        Route::post('/{pushNotificationLog}/recipients/{recipient}/repush', [PushNotificationLogController::class, 'repushRecipient'])->name('recipients.repush');
    });

Route::prefix('admin')->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:admin|'])->group(function () {
    Route::get('/barter', [BarterAuditController::class, 'index'])->name('admin.barter.index');
    Route::get('/barter/{transaction}', [BarterAuditController::class, 'show'])->name('admin.barter.show');

    // KYB Verification Routes
    Route::prefix('kyb-verification')->name('admin.kyb-verification.')->middleware('permission:kyb.verification.read')->group(function () {
        Route::get('/', [AdminKybVerificationController::class, 'index'])->name('index');
        Route::get('/{id}', [AdminKybVerificationController::class, 'show'])->name('show');
        Route::post('/{id}/approve', [AdminKybVerificationController::class, 'approve'])->name('approve')->middleware('permission:kyb.verification.approve');
        Route::post('/{id}/reject', [AdminKybVerificationController::class, 'reject'])->name('reject')->middleware('permission:kyb.verification.reject');
        Route::post('/{id}/document/{documentType}/approve', [AdminKybVerificationController::class, 'approveDocument'])->name('document.approve')->middleware('permission:kyb.verification.approve');
        Route::post('/{id}/document/{documentType}/reject', [AdminKybVerificationController::class, 'rejectDocument'])->name('document.reject')->middleware('permission:kyb.verification.reject');
        Route::post('/{id}/request-refill', [AdminKybVerificationController::class, 'requestRefill'])->name('request-refill')->middleware('permission:kyb.verification.manage');
        Route::post('/{id}/update-documents-to-send', [AdminKybVerificationController::class, 'updateDocumentsToSend'])->name('update-documents-to-send')->middleware('permission:kyb.verification.manage');
    });

    // KYB Settings Route
    Route::post('/settings/direct-bridge-submission', [AdminKybVerificationController::class, 'updateDirectBridgeSetting'])->name('admin.kyb-verification.settings.direct-bridge-submission')->middleware('permission:kyb.verification.manage');

    // KYC Verification Routes
    Route::prefix('kyc-verification')->name('admin.kyc-verification.')->middleware('permission:kyc.verification.read')->group(function () {
        Route::get('/', [AdminKycVerificationController::class, 'index'])->name('index');
        Route::get('/{id}', [AdminKycVerificationController::class, 'show'])->name('show');
        Route::post('/{id}/approve', [AdminKycVerificationController::class, 'approve'])->name('approve')->middleware('permission:kyc.verification.approve');
        Route::post('/{id}/reject', [AdminKycVerificationController::class, 'reject'])->name('reject')->middleware('permission:kyc.verification.reject');
        Route::post('/request/{customerId}', [AdminKycVerificationController::class, 'request'])->name('request')->middleware('permission:kyc.verification.request');
    });
});

// Livestock Management Routes (Admin Only)
Route::prefix('admin/livestock')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:admin', 'permission:admin.livestock.read'])
    ->name('admin.livestock.')
    ->group(function () {
        Route::get('/', [LivestockController::class, 'index'])->name('index');
        Route::get('/sellers', [LivestockController::class, 'sellers'])->name('sellers');
        Route::get('/sellers/{id}', [LivestockController::class, 'showSeller'])->name('sellers.show');
        Route::get('/sellers/{id}/listings', [LivestockController::class, 'sellerListings'])->name('sellers.listings');
        Route::put('/sellers/{id}/verify', [LivestockController::class, 'verifySeller'])->name('sellers.verify')->middleware('permission:admin.livestock.manage');
        Route::put('/sellers/{id}/reject', [LivestockController::class, 'rejectSeller'])->name('sellers.reject')->middleware('permission:admin.livestock.manage');
        Route::delete('/sellers/{id}', [LivestockController::class, 'deleteSeller'])->name('sellers.delete')->middleware('permission:admin.livestock.manage');
        Route::get('/listings', [LivestockController::class, 'fractionalListings'])->name('listings');
        Route::get('/listings/{id}', [LivestockController::class, 'showFractionalListing'])->name('listings.show');
        Route::put('/listings/{id}/link-asset', [LivestockController::class, 'linkAssetToFractionalListing'])->name('listings.link-asset')->middleware('permission:admin.livestock.manage');
        Route::delete('/listings/{id}', [LivestockController::class, 'removeListing'])->name('listings.remove')->middleware('permission:admin.livestock.manage');
        Route::get('/payouts', [LivestockController::class, 'payouts'])->name('payouts');
        Route::put('/payouts/{id}/approve', [LivestockController::class, 'approvePayout'])->name('payouts.approve')->middleware('permission:admin.livestock.manage');

        // Buyers routes
        Route::get('/buyers', [LivestockController::class, 'buyers'])->name('buyers');
        Route::get('/buyers/create', [LivestockController::class, 'createBuyer'])->name('buyers.create');
        Route::post('/buyers', [LivestockController::class, 'storeBuyer'])->name('buyers.store');
        Route::get('/buyers/{id}', [LivestockController::class, 'showBuyer'])->name('buyers.show');
        Route::put('/buyers/{id}/link-asset', [LivestockController::class, 'linkAssetToBuyer'])->name('buyers.link-asset')->middleware('permission:admin.livestock.manage');
        Route::put('/buyers/{id}/verify', [LivestockController::class, 'verifyBuyer'])->name('buyers.verify')->middleware('permission:admin.livestock.manage');
        Route::put('/buyers/{id}/reject', [LivestockController::class, 'rejectBuyer'])->name('buyers.reject')->middleware('permission:admin.livestock.manage');
        Route::delete('/buyers/{id}', [LivestockController::class, 'deleteBuyer'])->name('buyers.delete')->middleware('permission:admin.livestock.manage');

        // Pre-Generated Tags Routes
        Route::get('/pre-generated-tags', [PreGeneratedTagController::class, 'index'])->name('pre-generated-tags.index');
        Route::post('/pre-generated-tags', [PreGeneratedTagController::class, 'store'])->name('pre-generated-tags.store');
        Route::post('/pre-generated-tags/generate', [PreGeneratedTagController::class, 'generate'])->name('pre-generated-tags.generate');
        Route::post('/pre-generated-tags/{id}/assign', [PreGeneratedTagController::class, 'assign'])->name('pre-generated-tags.assign');
        Route::post('/pre-generated-tags/{id}/unassign', [PreGeneratedTagController::class, 'unassign'])->name('pre-generated-tags.unassign');
        Route::delete('/pre-generated-tags/{id}', [PreGeneratedTagController::class, 'destroy'])->name('pre-generated-tags.destroy');
    });

// Country Management Routes (Admin Only)
Route::prefix('admin/countries')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:admin', 'permission:admin.countries.read'])
    ->name('admin.countries.')
    ->group(function () {
        Route::get('/', [CountryController::class, 'index'])->name('index');
        Route::get('/create', [CountryController::class, 'create'])->name('create')->middleware('permission:admin.countries.create');
        Route::post('/', [CountryController::class, 'store'])->name('store')->middleware('permission:admin.countries.create');
        Route::get('/{country}/edit', [CountryController::class, 'edit'])->name('edit')->middleware('permission:admin.countries.update');
        Route::put('/{country}', [CountryController::class, 'update'])->name('update')->middleware('permission:admin.countries.update');
        Route::delete('/{country}', [CountryController::class, 'destroy'])->name('destroy')->middleware('permission:admin.countries.delete');
    });

Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    // NodeShare routes
    Route::resource('node-shares', NodeShareController::class)->middleware([
        'index' => 'permission:node.referral.read',
        'create' => 'permission:node.referral.create',
        'store' => 'permission:node.referral.create',
        'show' => 'permission:node.referral.read',
        'edit' => 'permission:node.referral.edit',
        'update' => 'permission:node.referral.update',
        'destroy' => 'permission:node.referral.delete',
    ]);

    // NodeSell routes
    Route::resource('node-sells', NodeSellController::class)->middleware([
        'index' => 'permission:node.referral.read',
        'create' => 'permission:node.referral.create',
        'store' => 'permission:node.referral.create',
        'show' => 'permission:node.referral.read',
        'edit' => 'permission:node.referral.edit',
        'update' => 'permission:node.referral.update',
        'destroy' => 'permission:node.referral.delete',
    ]);

    // Buy share routes

    Route::get('/node-boss/{nodeBoss}/buy', [NodeSellController::class, 'buy'])->name('node-boss.buy');
    Route::post('/node-share/purchase', [NodeSellController::class, 'store'])->name('node-share.store');

    // Payment success/cancel routes
    Route::get('/node-share/success', [NodeSellController::class, 'success'])->name('node-share.success');
    Route::get('/node-share/cancel', [NodeSellController::class, 'cancel'])->name('node-share.cancel');

    // Certificate routes
    Route::get('/certificate/{nodeSell}', [NodeSellController::class, 'certificate'])->name('certificate.show');
    Route::post('/certificate/{nodeSell}/email', [NodeSellController::class, 'emailCertificate'])->name('certificate.email');
    Route::get('/certificate/{nodeSell}/download', [NodeSellController::class, 'downloadCertificate'])->name('certificate.download');

    // User shares
    Route::get('/my-shares', [NodeSellController::class, 'myShares'])->name('my-shares');

    // comission withdrawls
    Route::post('/withrawl/request', [WithdrawalController::class, 'store'])->name('withdrawl.request');

    // Social Media Management Routes
    Route::prefix('social-media')->name('social-media.')->group(function () {
        Route::get('/', [SocialMediaController::class, 'index'])->name('index');
        Route::post('/accounts', [SocialMediaController::class, 'storeAccount'])->name('accounts.store');
        Route::put('/accounts/{account}', [SocialMediaController::class, 'updateAccount'])->name('accounts.update');
        Route::delete('/accounts/{account}', [SocialMediaController::class, 'deleteAccount'])->name('accounts.delete');
        Route::post('/posts', [SocialMediaController::class, 'storePost'])->name('posts.store');
        Route::put('/posts/{post}', [SocialMediaController::class, 'updatePost'])->name('posts.update');
        Route::delete('/posts/{post}', [SocialMediaController::class, 'deletePost'])->name('posts.delete');
        Route::post('/posts/{post}/publish', [SocialMediaController::class, 'publishPost'])->name('posts.publish');
        Route::get('/accounts/{account}/posts', [SocialMediaController::class, 'getPostsByAccount'])->name('accounts.posts');
        Route::get('/posts/{post}/analytics', [SocialMediaController::class, 'getPostAnalytics'])->name('posts.analytics');
    });
});

// Integrations – Dropbox (organization + supporter)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|admin|organization_pending|user|care_alliance', 'topics.selected'])->prefix('integrations')->name('integrations.')->group(function () {
    Route::get('/dropbox', [IntegrationsController::class, 'dropbox'])->name('dropbox');
    Route::get('/dropbox/search', [IntegrationsController::class, 'searchDropbox'])->name('dropbox.search');
    Route::get('/dropbox/redirect', [IntegrationsController::class, 'redirectToDropbox'])->name('dropbox.redirect');
    Route::get('/dropbox/callback', [IntegrationsController::class, 'dropboxCallback'])->name('dropbox.callback');
    Route::post('/dropbox/disconnect', [IntegrationsController::class, 'disconnectDropbox'])->name('dropbox.disconnect');
    Route::put('/dropbox/folder', [IntegrationsController::class, 'updateDropboxFolder'])->name('dropbox.folder.update');
    Route::post('/dropbox/move-recordings', [IntegrationsController::class, 'moveRecordingsToFolder'])->name('dropbox.move-recordings');
    Route::get('/dropbox/download', [IntegrationsController::class, 'downloadFile'])->name('dropbox.download');
    Route::delete('/dropbox/file', [IntegrationsController::class, 'deleteFile'])->name('dropbox.file.delete');
    Route::put('/dropbox/file', [IntegrationsController::class, 'renameFile'])->name('dropbox.file.rename');

    Route::get('/stripe-connect', [OrganizationStripeConnectController::class, 'show'])->name('stripe-connect');
    Route::get('/stripe-connect/start', [OrganizationStripeConnectController::class, 'start'])->name('stripe-connect.start');
    Route::get('/stripe-connect/callback', [OrganizationStripeConnectController::class, 'oauthCallback'])->name('stripe-connect.callback');
    Route::post('/stripe-connect/disconnect', [OrganizationStripeConnectController::class, 'disconnect'])->name('stripe-connect.disconnect');
    Route::get('/stripe-connect/return', [OrganizationStripeConnectController::class, 'onboardingReturn'])->name('stripe-connect.return');
    Route::get('/stripe-connect/refresh', [OrganizationStripeConnectController::class, 'onboardingRefresh'])->name('stripe-connect.refresh');

    Route::get('/payout-settings', [OrganizationPayoutSettingsController::class, 'show'])->name('payout-settings');
    Route::put('/payout-settings/preferred-method', [OrganizationPayoutSettingsController::class, 'updatePreferredMethod'])->name('payout-settings.preferred-method');

    Route::get('/paypal-payouts', [OrganizationPayPalPayoutController::class, 'show'])->name('paypal-payouts');
    Route::post('/paypal-payouts/connect', [OrganizationPayPalPayoutController::class, 'connect'])->name('paypal-payouts.connect');
    Route::post('/paypal-payouts/disconnect', [OrganizationPayPalPayoutController::class, 'disconnect'])->name('paypal-payouts.disconnect');
});

// YouTube integration: organization + supporter (outside dashboard group so role:user can access)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|user|care_alliance', 'topics.selected'])->prefix('integrations')->name('integrations.')->group(function () {
    Route::get('/youtube/connect', [IntegrationsController::class, 'youtubeConnect'])->name('youtube.connect'); // supporter (normal user) only
    Route::get('/youtube', [IntegrationsController::class, 'youtube'])->name('youtube'); // organization only
    Route::get('/youtube/redirect', [IntegrationsController::class, 'redirectToYouTube'])->name('youtube.redirect');
    Route::get('/youtube/callback', [IntegrationsController::class, 'youtubeCallback'])->name('youtube.callback');
    Route::put('/youtube', [IntegrationsController::class, 'updateYoutube'])->name('youtube.update');
});

Route::post('/paypal/webhook', [PayPalWebhookController::class, 'handle'])->name('paypal.webhook');

// route for donation
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    Route::post('/donate', [DonationController::class, 'store'])->name('donations.store');
    Route::post('/donate/non-cash', [DonationController::class, 'storeNonCash'])->name('donations.non-cash.store');
    Route::get('/donations/success', [DonationController::class, 'success'])->name('donations.success');
    Route::get('/donations/cancel', [DonationController::class, 'cancel'])->name('donations.cancel');
    Route::get('/donations/complete-saved-payment/{donation}', [DonationController::class, 'completeSavedPayment'])->name('donations.complete-saved-payment');
    Route::get('/donations/manual/{donation}/confirm', [DonationPaymentController::class, 'manualConfirm'])->name('donations.manual.confirm');
    Route::post('/donations/manual/{donation}/confirm', [DonationPaymentController::class, 'manualConfirmSubmit'])->name('donations.manual.confirm.submit');
    Route::get('/donations/paypal/{donation}/capture', [DonationPaymentController::class, 'paypalCapture'])->name('donations.paypal.capture');

    Route::post('/care-alliance/{allianceSlug}/campaigns/{campaign}/checkout', [CareAllianceDonationController::class, 'checkout'])
        ->name('care-alliance.campaigns.checkout')
        ->where('campaign', '[a-zA-Z0-9][a-zA-Z0-9-]*');
    Route::get('/care-alliance/donations/success', [CareAllianceDonationController::class, 'success'])
        ->name('care-alliance.donations.success');
});

Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|care_alliance'])->group(function () {
    Route::redirect('/organization/payment-settings', '/settings/donation-payments', 301);
});

// Organization donations route
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|care_alliance', 'topics.selected'])->group(function () {
    Route::get('/donations', [DonationController::class, 'organizationIndex'])->name('donations.index');
});

// Care Alliance — workspace & APIs (same app shell as organizations; requires topics.selected)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|organization_pending|care_alliance', 'topics.selected'])->prefix('care-alliance')->name('care-alliance.')->group(function () {
    Route::get('/dashboard', [CareAllianceDashboardController::class, 'index'])->name('dashboard');
    Route::get('/workspace/overview', [CareAllianceDashboardController::class, 'workspaceOverview'])->name('workspace.overview');
    Route::get('/workspace/members', [CareAllianceDashboardController::class, 'workspaceMembers'])->name('workspace.members');
    Route::get('/workspace/campaigns/{campaign}/edit', [CareAllianceDashboardController::class, 'workspaceCampaignEdit'])->name('workspace.campaigns.edit');
    Route::get('/workspace/campaigns', [CareAllianceDashboardController::class, 'workspaceCampaigns'])->name('workspace.campaigns');
    Route::get('/workspace/settings', [CareAllianceDashboardController::class, 'workspaceSettings'])->name('workspace.settings');
    Route::patch('/settings', [CareAllianceDashboardController::class, 'updateSettings'])->name('settings.update');
    Route::get('/organizations/search', [CareAllianceInvitationController::class, 'searchOrganizations'])->name('organizations.search');
    Route::post('/invitations', [CareAllianceInvitationController::class, 'store'])->name('invitations.store');
    Route::post('/invitations/{invitation}/resend', [CareAllianceInvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('/invitations/{invitation}', [CareAllianceInvitationController::class, 'destroy'])->name('invitations.destroy');
    Route::post('/join-requests/{joinRequest}/approve', [CareAllianceJoinRequestReviewController::class, 'approve'])->name('join-requests.approve');
    Route::post('/join-requests/{joinRequest}/decline', [CareAllianceJoinRequestReviewController::class, 'decline'])->name('join-requests.decline');
    Route::post('/campaigns', [CareAllianceCampaignManageController::class, 'store'])->name('campaigns.store');
    Route::patch('/campaigns/{campaign}', [CareAllianceCampaignManageController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{campaign}', [CareAllianceCampaignManageController::class, 'destroy'])->name('campaigns.destroy');
});

// Care Alliance — nonprofits accept/decline alliance invites (not Care Alliance hub users)
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|organization_pending', 'deny.care_alliance.hub'])->group(function () {
    Route::get('/organization/alliance-membership', [CareAllianceOrgMembershipController::class, 'index'])->name('organization.alliance-membership');
    Route::get('/organization/care-alliances/search', [CareAllianceOrgJoinRequestController::class, 'searchAlliances'])->name('organization.care-alliances.search');
    Route::post('/organization/care-alliance-join-requests', [CareAllianceOrgJoinRequestController::class, 'store'])->name('organization.care-alliance-join-requests.store');
    Route::get('/organization/care-alliance-invitations', [CareAllianceOrgInvitationController::class, 'pending'])->name('organization.care-alliance.invitations');
    Route::post('/organization/care-alliance-invitations/{invitation}/accept', [CareAllianceOrgInvitationController::class, 'accept'])->name('organization.care-alliance.invitations.accept');
    Route::post('/organization/care-alliance-invitations/{invitation}/decline', [CareAllianceOrgInvitationController::class, 'decline'])->name('organization.care-alliance.invitations.decline');
});

// Gift Cards routes
// Public routes (browse brands - everyone can see)
Route::get('/gift-cards', [GiftCardController::class, 'index'])->name('gift-cards.index');
Route::get('/gift-cards/brands', [GiftCardController::class, 'getBrands'])->name('gift-cards.brands');

// Organization routes (view purchased cards) - organization_pending cannot access until onboarding complete
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role:organization|admin|care_alliance'])->group(function () {
    Route::get('/gift-cards/purchased', [GiftCardController::class, 'createdCards'])->name('gift-cards.created');
});

// Authenticated user routes (purchase) - Must come after specific routes
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected', 'role.simple:user'])->group(function () {
    Route::get('/gift-cards/purchase/{brand}', [GiftCardController::class, 'showPurchase'])->name('gift-cards.purchase')->where('brand', '.*');
    Route::post('/gift-cards/purchase', [GiftCardController::class, 'purchase'])->name('gift-cards.purchase.store');
    Route::get('/gift-cards/my-cards', [GiftCardController::class, 'myCards'])->name('gift-cards.my-cards');
    Route::get('/gift-cards/payment/success', [GiftCardController::class, 'success'])->name('gift-cards.success');
});

// Public route for viewing brand details (before purchase) - must come before parameterized route
Route::get('/gift-cards/show', [GiftCardController::class, 'show'])->name('gift-cards.show');
// PDF download route - must come before parameterized route
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/gift-cards/{giftCard}/download-pdf', [GiftCardController::class, 'downloadPdf'])->name('gift-cards.download-pdf');
    // Transaction lookup by order ID (for testing)
    Route::get('/gift-cards/transaction/lookup/{orderId}', [GiftCardController::class, 'lookupTransaction'])->name('gift-cards.transaction.lookup');
});
// This parameterized route must come LAST to avoid catching specific routes - for viewing purchased cards
// Requires authentication to view purchased gift cards
Route::middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/gift-cards/{giftCard}', [GiftCardController::class, 'show'])->name('gift-cards.show.id');
});

// Plans route
Route::middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])->group(function () {
    Route::get('/plans', [PlansController::class, 'index'])->name('plans.index');
    Route::post('/plans/{plan}/subscribe', [PlansController::class, 'subscribe'])->name('plans.subscribe');
    Route::get('/plans/success', [PlansController::class, 'success'])->name('plans.success');
    Route::post('/plans/cancel', [PlansController::class, 'cancel'])->name('plans.cancel');

    // Wallet subscription routes
    Route::get('/wallet/plans', [PlansController::class, 'getWalletPlans'])->name('wallet.plans');
    Route::post('/wallet/subscribe/{walletPlan}', [PlansController::class, 'subscribeWallet'])->name('wallet.subscribe');
    Route::get('/wallet/subscription/success', [PlansController::class, 'walletSubscriptionSuccess'])->name('wallet.subscription.success');
    Route::get('/wallet/subscription/cancel', [PlansController::class, 'walletSubscriptionCancel'])->name('wallet.subscription.cancel');
});

// Admin Phaze Webhook Management
Route::prefix('admin/phaze-webhooks')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.phaze-webhooks.')
    ->group(function () {
        Route::get('/', [PhazeWebhookManagementController::class, 'index'])->name('index');
        Route::post('/', [PhazeWebhookManagementController::class, 'store'])->name('store');
        Route::delete('/{id}', [PhazeWebhookManagementController::class, 'destroy'])->name('destroy');
    });

// Admin Email Packages Management
Route::prefix('admin/email-packages')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.email-packages.')
    ->group(function () {
        Route::get('/', [EmailPackageController::class, 'index'])->name('index');
        Route::get('/create', [EmailPackageController::class, 'create'])->name('create');
        Route::post('/', [EmailPackageController::class, 'store'])->name('store');
        Route::get('/{emailPackage}/edit', [EmailPackageController::class, 'edit'])->name('edit');
        Route::put('/{emailPackage}', [EmailPackageController::class, 'update'])->name('update');
        Route::delete('/{emailPackage}', [EmailPackageController::class, 'destroy'])->name('destroy');
    });

// Admin Service Categories Management
// Admin
Route::prefix('admin/service-categories')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.service-categories.')
    ->group(function () {
        Route::get('/', [ServiceCategoryController::class, 'index'])->name('index');
        Route::get('/create', [ServiceCategoryController::class, 'create'])->name('create');
        Route::post('/', [ServiceCategoryController::class, 'store'])->name('store');
        Route::get('/{serviceCategory}/edit', [ServiceCategoryController::class, 'edit'])->name('edit');
        Route::put('/{serviceCategory}', [ServiceCategoryController::class, 'update'])->name('update');
        Route::delete('/{serviceCategory}', [ServiceCategoryController::class, 'destroy'])->name('destroy');
    });

// Org registration: Category Grid (Primary Action) — lookup table managed by admins
Route::prefix('admin/primary-action-categories')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.primary-action-categories.')
    ->group(function () {
        Route::get('/', [PrimaryActionCategoryController::class, 'index'])->name('index');
        Route::post('/', [PrimaryActionCategoryController::class, 'store'])->name('store');
        Route::put('/{primaryActionCategory}', [PrimaryActionCategoryController::class, 'update'])->name('update');
        Route::delete('/{primaryActionCategory}', [PrimaryActionCategoryController::class, 'destroy'])->name('destroy');
    });

// Admin Merchant Hub Categories Management
Route::prefix('admin/merchant-hub-categories')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.merchant-hub-categories.')
    ->group(function () {
        Route::get('/', [MerchantHubCategoryController::class, 'index'])->name('index');
        Route::get('/create', [MerchantHubCategoryController::class, 'create'])->name('create');
        Route::post('/', [MerchantHubCategoryController::class, 'store'])->name('store');
        Route::get('/{merchantHubCategory}/edit', [MerchantHubCategoryController::class, 'edit'])->name('edit');
        Route::put('/{merchantHubCategory}', [MerchantHubCategoryController::class, 'update'])->name('update');
        Route::delete('/{merchantHubCategory}', [MerchantHubCategoryController::class, 'destroy'])->name('destroy');
    });

// Admin Merchant Hub Management
Route::prefix('admin/merchant-hub')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.merchant-hub.')
    ->group(function () {
        // Dashboard
        Route::get('/', [MerchantHubController::class, 'index'])->name('index');

        // Merchants
        Route::prefix('merchants')->name('merchants.')->group(function () {
            Route::get('/', [MerchantHubController::class, 'merchantsIndex'])->name('index');
            Route::get('/create', [MerchantHubController::class, 'merchantsCreate'])->name('create');
            Route::post('/', [MerchantHubController::class, 'merchantsStore'])->name('store');
            Route::get('/{merchant}/edit', [MerchantHubController::class, 'merchantsEdit'])->name('edit');
            Route::put('/{merchant}', [MerchantHubController::class, 'merchantsUpdate'])->name('update');
            Route::delete('/{merchant}', [MerchantHubController::class, 'merchantsDestroy'])->name('destroy');
        });

        // Offers
        Route::prefix('offers')->name('offers.')->group(function () {
            Route::get('/', [MerchantHubController::class, 'offersIndex'])->name('index');
            Route::get('/create', [MerchantHubController::class, 'offersCreate'])->name('create');
            Route::post('/', [MerchantHubController::class, 'offersStore'])->name('store');
            Route::get('/{offer}/edit', [MerchantHubController::class, 'offersEdit'])->name('edit');
            Route::put('/{offer}', [MerchantHubController::class, 'offersUpdate'])->name('update');
            Route::delete('/{offer}', [MerchantHubController::class, 'offersDestroy'])->name('destroy');
        });

        // Redemptions
        Route::prefix('redemptions')->name('redemptions.')->group(function () {
            Route::get('/', [MerchantHubController::class, 'redemptionsIndex'])->name('index');
            Route::put('/{redemption}/status', [MerchantHubController::class, 'redemptionsUpdateStatus'])->name('update-status');
        });
    });

// Admin Exemption Certificates Management
Route::prefix('admin/exemption-certificates')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.exemption-certificates.')
    ->group(function () {
        Route::get('/', [ExemptionCertificateController::class, 'index'])->name('index');
        Route::get('/{exemptionCertificate}', [ExemptionCertificateController::class, 'show'])->name('show');
        Route::post('/{exemptionCertificate}/approve', [ExemptionCertificateController::class, 'approve'])->name('approve');
        Route::post('/{exemptionCertificate}/reject', [ExemptionCertificateController::class, 'reject'])->name('reject');
    });

// Admin Promotional Banners Management
Route::prefix('admin/promotional-banners')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected', 'permission:promotional.banner.read'])
    ->name('admin.promotional-banners.')
    ->group(function () {
        Route::get('/', [PromotionalBannerController::class, 'index'])->name('index');
        Route::get('/create', [PromotionalBannerController::class, 'create'])->name('create');
        Route::post('/', [PromotionalBannerController::class, 'store'])->name('store');
        Route::patch('/show-on-dashboard', [PromotionalBannerController::class, 'toggleShowOnDashboard'])->name('toggle-dashboard');
        Route::get('/{promotionalBanner}/edit', [PromotionalBannerController::class, 'edit'])->name('edit');
        Route::put('/{promotionalBanner}', [PromotionalBannerController::class, 'update'])->name('update');
        Route::delete('/{promotionalBanner}', [PromotionalBannerController::class, 'destroy'])->name('destroy');
    });

// Admin Contact Page Management
Route::prefix('admin/contact-page')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.contact-page.')
    ->group(function () {
        Route::get('/', [ContactPageController::class, 'index'])->name('index');
        Route::get('/{section}/edit', [ContactPageController::class, 'edit'])->name('edit');
        Route::put('/{section}', [ContactPageController::class, 'update'])->name('update');
        Route::delete('/{contactPageContent}', [ContactPageController::class, 'destroy'])->name('destroy');
    });

// Admin Contact Submissions Management
Route::prefix('admin/contact-submissions')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.contact-submissions.')
    ->group(function () {
        Route::get('/', [ContactSubmissionController::class, 'index'])->name('index');
        Route::get('/{contactSubmission}', [ContactSubmissionController::class, 'show'])->name('show');
        Route::put('/{contactSubmission}/status', [ContactSubmissionController::class, 'updateStatus'])->name('update-status');
        Route::delete('/{contactSubmission}', [ContactSubmissionController::class, 'destroy'])->name('destroy');
    });

// Admin IRS Board Members (System Management)
Route::prefix('admin/irs-members')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.irs-members.')
    ->group(function () {
        Route::get('/', [IrsBoardMemberController::class, 'index'])->name('index');
    });

// Admin Fundraise Leads (qualified leads from /fundraise funnel → Wefunder)
Route::prefix('admin/fundraise-leads')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.fundraise-leads.')
    ->group(function () {
        Route::get('/', [FundraiseLeadController::class, 'index'])->name('index');
    });

// Admin Plans Management
Route::prefix('admin/plans')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'role:admin', 'topics.selected'])
    ->name('admin.plans.')
    ->group(function () {
        Route::get('/', [PlanController::class, 'index'])->name('index');
        Route::get('/subscribers', [PlanController::class, 'subscribers'])->name('subscribers');
        Route::get('/create', [PlanController::class, 'create'])->name('create');
        Route::post('/', [PlanController::class, 'store'])->name('store');
        Route::get('/{plan}', [PlanController::class, 'show'])->name('show');
        Route::get('/{plan}/edit', [PlanController::class, 'edit'])->name('edit');
        Route::put('/{plan}', [PlanController::class, 'update'])->name('update');
        Route::delete('/{plan}', [PlanController::class, 'destroy'])->name('destroy');

        // Plan Features
        Route::post('/{plan}/features', [PlanController::class, 'storeFeature'])->name('features.store');
        Route::put('/{plan}/features/{feature}', [PlanController::class, 'updateFeature'])->name('features.update');
        Route::delete('/{plan}/features/{feature}', [PlanController::class, 'destroyFeature'])->name('features.destroy');
    });

// Admin Wallet Plans Management
Route::prefix('admin/wallet-plans')
    ->middleware(['auth', 'EnsureEmailIsVerified', 'topics.selected'])
    ->name('admin.wallet-plans.')
    ->group(function () {
        Route::get('/', [WalletPlanController::class, 'index'])->middleware('permission:wallet.plan.read')->name('index');
        Route::get('/create', [WalletPlanController::class, 'create'])->middleware('permission:wallet.plan.create')->name('create');
        Route::post('/', [WalletPlanController::class, 'store'])->middleware('permission:wallet.plan.create')->name('store');
        Route::get('/{walletPlan}/edit', [WalletPlanController::class, 'edit'])->middleware('permission:wallet.plan.edit')->name('edit');
        Route::put('/{walletPlan}', [WalletPlanController::class, 'update'])->middleware('permission:wallet.plan.update')->name('update');
        Route::delete('/{walletPlan}', [WalletPlanController::class, 'destroy'])->middleware('permission:wallet.plan.delete')->name('destroy');
    });

// IRS BMF Management Routes
Route::prefix('irs-bmf')->name('irs-bmf.')->middleware(['auth', 'EnsureEmailIsVerified'])->group(function () {
    Route::get('/', [IrsBmfController::class, 'index'])->name('index');
    Route::get('/search', [IrsBmfController::class, 'search'])->name('search');
    Route::get('/{record}', [IrsBmfController::class, 'show'])->name('show');
    Route::post('/import', [IrsBmfController::class, 'triggerImport'])->name('import');
});

// Raffle ticket QR + verify: public so <img src> and phone scans work without raffle.read permission
Route::get('/raffles/tickets/{ticket}/qr-code', [RaffleController::class, 'generateTicketQrCode'])->name('raffles.ticket.qr-code');
Route::get('/raffles/tickets/{ticket}/verify', [RaffleController::class, 'verifyTicket'])->name('raffles.verify-ticket');

// Test QR Code Route
Route::get('/test-qr', function () {
    $qrCode = QrCode::format('png')
        ->size(200)
        ->margin(2)
        ->color(0, 0, 0)
        ->backgroundColor(255, 255, 255)
        ->generate('TEST QR CODE WORKING');

    return response($qrCode, 200, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
});

Route::middleware(['web', 'auth', 'EnsureEmailIsVerified'])->prefix('frontend')->name('frontend.')->group(function () {
    Route::get('/raffles', [RaffleController::class, 'frontendIndex'])->name('raffles.index');
    Route::get('/raffles/{raffle}', [RaffleController::class, 'frontendShow'])->name('raffles.show');
    Route::post('/raffles/{raffle}/purchase', [RaffleController::class, 'purchaseTickets'])->name('raffles.purchase');
});

// Note: Stripe webhooks are handled by Laravel Cashier at /stripe/webhook
// Configure this URL in your Stripe dashboard
// The webhook will process checkout.session.completed events automatically

// Email Invite Routes
Route::middleware(['auth', 'EnsureEmailIsVerified', 'role:organization|care_alliance', 'topics.selected'])->prefix('email-invite')->name('email-invite.')->group(function () {
    Route::get('/', [EmailInviteController::class, 'index'])->name('index');
    Route::match(['get', 'post'], '/connect/gmail', [EmailInviteController::class, 'connectGmail'])->name('connect.gmail');
    Route::match(['get', 'post'], '/connect/outlook', [EmailInviteController::class, 'connectOutlook'])->name('connect.outlook');
    Route::get('/callback', [EmailInviteController::class, 'callback'])->name('callback');
    Route::post('/connections/{connection}/sync', [EmailInviteController::class, 'syncContacts'])->name('sync');
    Route::get('/connections/{connection}/sync-status', [EmailInviteController::class, 'checkSyncStatus'])->name('sync-status');
    Route::get('/contacts', [EmailInviteController::class, 'getContacts'])->name('contacts');
    Route::post('/send-invites', [EmailInviteController::class, 'sendInvites'])->name('send-invites');
    Route::post('/purchase-emails', [EmailInviteController::class, 'purchaseEmails'])->name('purchase-emails');
    Route::get('/purchase/success', [EmailInviteController::class, 'purchaseSuccess'])->name('purchase.success');
    Route::delete('/connections/{connection}', [EmailInviteController::class, 'disconnect'])->name('disconnect');
    Route::delete('/contacts/{contact}', [EmailInviteController::class, 'deleteContact'])->name('contacts.delete');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::get('/embed/donate-widget/{path?}', function () {
    abort(404);
})->where('path', '.*');

Route::get('/{orgSlug}', [DonateWidgetEmbedController::class, 'show'])
    ->middleware('allow.donate.widget.embed')
    ->name('donate-widget.embed');
