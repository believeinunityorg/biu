<?php

namespace App\Http\Middleware;

use App\Models\AdminSetting;
use App\Models\CareAlliance;
use App\Models\LivestockUser;
use App\Models\Merchant;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Services\BridgeVerificationService;
use App\Services\BrpParticipationSettingsService;
use App\Services\ParticipationBrpAwardService;
use App\Services\Payments\BelievePointsRewardService;
use App\Support\BrpParticipationModule;
use App\Services\SeoService;
use App\Services\StripeProcessingFeeEstimator;
use App\Support\AppVersion;
use App\Support\SupporterSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Cashier\Cashier;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        $assetVersion = parent::version($request);

        return $assetVersion !== null
            ? AppVersion::current().'-'.$assetVersion
            : AppVersion::current();
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        // Check if we're on livestock domain and use appropriate guard
        // For development, manually set or check domain
        $isLivestockDomain = false;
        $isMerchantDomain = false;

        if (function_exists('is_livestock_domain')) {
            $isLivestockDomain = is_livestock_domain();
        } else {
            // Fallback for development
            $isLivestockDomain = app()->environment('local') &&
                (request()->has('livestock') ||
                    str_contains(request()->url(), 'livestock'));
        }

        // Check if we're on merchant domain
        $merchantDomain = config('merchant.domain');
        $currentHost = $request->getHost();
        $isMerchantDomain = $currentHost === $merchantDomain || str_contains($currentHost, 'merchant.');

        // Get user based on domain
        if ($isMerchantDomain) {
            $user = $request->user('merchant');
        } elseif ($isLivestockDomain) {
            $user = $request->user('livestock');
        } else {
            $user = $request->user();
        }

        // Only load organization relationship if user is not a LivestockUser or Merchant
        // Load organization manually to avoid ambiguous column error with hasOneThrough
        if ($user && ! $isLivestockDomain && ! $isMerchantDomain && ! ($user instanceof LivestockUser) && ! ($user instanceof Merchant)) {
            // Load organization manually through board_members to avoid relationship query issues
            // This prevents the ambiguous column error when eager loading
            $boardMember = $user->boardMemberships()->first();
            if ($boardMember) {
                $organization = Organization::find($boardMember->organization_id);
                if ($organization) {
                    $user->setRelation('organization', $organization);
                }
            } elseif (! $user->relationLoaded('organization') || ! $user->organization) {
                $ownedOrganization = Organization::forAuthUser($user);
                if ($ownedOrganization) {
                    $user->setRelation('organization', $ownedOrganization);
                }
            }
            $user->load('serviceSellerProfile');
        }
        // Only access roles if user is not a LivestockUser or Merchant (User model has roles via Spatie Permission)
        $role = null;
        if ($user && ! ($user instanceof LivestockUser) && ! ($user instanceof Merchant)) {
            $role = $user->roles?->first();
        }

        // Get all permissions (both role-based and direct user permissions) - only for main app users
        $permissions = [];
        $roles = [];

        if ($user && ! $isLivestockDomain && ! $isMerchantDomain && ! ($user instanceof LivestockUser) && ! ($user instanceof Merchant) && method_exists($user, 'getAllPermissions')) {
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $roles = $user->roles?->pluck('name')->toArray() ?? [];
        }

        // Build user data based on domain
        $userData = null;
        if ($user) {
            if ($isMerchantDomain || ($user instanceof Merchant)) {
                // Real-time check: Get subscription and refresh from Stripe
                $subscription = $user->subscriptions()
                    ->whereIn('stripe_status', ['active', 'trialing', 'canceled'])
                    ->orderBy('created_at', 'desc')
                    ->first();

                $hasActiveSubscription = false;

                if ($subscription) {
                    // Real-time check: Refresh subscription status from Stripe
                    try {
                        if ($subscription->stripe_id) {
                            $stripe = Cashier::stripe();
                            $stripeSubscription = $stripe->subscriptions->retrieve($subscription->stripe_id);

                            // Update local subscription with latest data from Stripe
                            $subscription->stripe_status = $stripeSubscription->status;
                            $subscription->ends_at = $stripeSubscription->cancel_at ?
                                Carbon::createFromTimestamp($stripeSubscription->cancel_at) : null;
                            $subscription->trial_ends_at = $stripeSubscription->trial_end ?
                                Carbon::createFromTimestamp($stripeSubscription->trial_end) : null;

                            // If cancel_at is set, mark as canceled even if status is still 'active'
                            if ($stripeSubscription->cancel_at) {
                                $subscription->stripe_status = 'canceled';
                            }

                            $subscription->save();

                            // Check if subscription is truly active (not canceled)
                            $hasActiveSubscription = in_array($stripeSubscription->status, ['active', 'trialing'])
                                && $stripeSubscription->cancel_at === null;
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Failed to refresh subscription status in HandleInertiaRequests', [
                            'merchant_id' => $user->id,
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);

                        // Fallback: check local status
                        $hasActiveSubscription = in_array($subscription->stripe_status, ['active', 'trialing'])
                            && $subscription->ends_at === null;
                    }
                }

                // Merchant user data
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'business_name' => $user->business_name,
                    'business_description' => $user->business_description,
                    'website' => $user->website,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'city' => $user->city,
                    'state' => $user->state,
                    'zip_code' => $user->zip_code,
                    'country' => $user->country,
                    'status' => $user->status,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'joined' => $user->created_at->format('F Y'),
                    'has_active_subscription' => $hasActiveSubscription,
                    'pending_pool_approval_count' => OrganizationProduct::query()
                        ->where('status', 'pending_merchant_approval')
                        ->whereHas('marketplaceProduct', fn ($q) => $q->where('merchant_id', $user->id))
                        ->count(),
                ];
            } elseif ($isLivestockDomain || ($user instanceof LivestockUser)) {
                // Livestock user data
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $user->profile_image ? '/storage/'.$user->profile_image : null,
                    'bio' => $user->bio,
                    'status' => $user->status,
                    'is_verified' => $user->is_verified,
                    'email_verified_at' => $user->email_verified_at,
                    'joined' => $user->created_at->format('F Y'),
                    'seller_profile' => $user->sellerProfile ? [
                        'farm_name' => $user->sellerProfile->farm_name,
                        'verification_status' => $user->sellerProfile->verification_status,
                        'rejection_reason' => $user->sellerProfile->rejection_reason,
                    ] : null,
                    'buyer_profile' => $user->buyerProfile ? [
                        'farm_name' => $user->buyerProfile->farm_name,
                        'verification_status' => $user->buyerProfile->verification_status,
                        'rejection_reason' => $user->buyerProfile->rejection_reason,
                    ] : null,
                ];
            } else {
                // Main app user data (only for regular User models)
                if (! ($user instanceof LivestockUser) && ! ($user instanceof Merchant)) {
                    $careAllianceHub = CareAlliance::query()
                        ->where('creator_user_id', $user->id)
                        ->where('status', 'active')
                        ->first(['id', 'slug', 'name']);

                    $user->clampAiTokensUsed();
                    $aiTokensIncluded = (int) ($user->ai_tokens_included ?? 0);
                    $aiTokensUsed = (int) ($user->ai_tokens_used ?? 0);
                    if ($aiTokensIncluded > 0) {
                        $aiTokensUsed = min($aiTokensUsed, $aiTokensIncluded);
                    }

                    $userData = [
                        'id' => $user->id,
                        'slug' => $user->slug ?? null,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->contact_number,
                        'role' => $user->role,
                        'can_follow_organizations' => $user->canFollowOrganizations(),
                        'organization_role' => $user->organization_role ?? null,
                        'balance' => $user->balance,
                        'reward_points' => $user->availableRewardPointsBalance(),
                        'available_reward_points' => $user->availableRewardPointsBalance(),
                        'processing_reward_points' => $user->processingRewardPointsBalance(),
                        'reward_points_total' => $user->totalRewardPointsBalance(),
                        'believe_points' => $user->believe_points ?? 0,
                        'processing_believe_points' => $user->processing_believe_points ?? 0,
                        'processing_believe_points_batches' => ((float) ($user->processing_believe_points ?? 0) > 0)
                            ? $user->processingBelievePointsBatches()
                            : [],
                        'processing_believe_points_release_at' => ((float) ($user->processing_believe_points ?? 0) > 0)
                            ? $user->nextProcessingBelievePointsReleaseAt()?->toIso8601String()
                            : null,
                        // Gift BP is only spendable in the Gift Card module. Everywhere else uses purchased.
                        'purchased_believe_points' => $user->purchasedBelievePointsBalance(),
                        'donateable_believe_points' => round(
                            $user->purchasedBelievePointsBalance() + (float) ($user->processing_believe_points ?? 0),
                            2
                        ),
                        'gifted_believe_points' => $user->gifted_believe_points ?? 0,
                        'holding_believe_points' => $user->holding_believe_points ?? 0,
                        'believe_points_total' => $user->totalBelievePointsBalance(),
                        'credits' => $user->credits ?? 0,
                        'ai_media_studio_credits' => round((float) ($user->ai_media_studio_credits ?? 0), 2),
                        'ai_tokens_used' => $aiTokensUsed,
                        'ai_tokens_included' => $aiTokensIncluded,
                        'current_plan_id' => $user->current_plan_id ?? null,
                        'current_plan_details' => $user->current_plan_details ?? null,
                        'image' => $user->role !== 'organization' ? ($user->image ? '/storage/'.$user->image : null) : ($user->organization?->user?->image ? '/storage/'.$user->organization?->user?->image : null),
                        'favorite_organizations_count' => $user->favoriteOrganizations()->count(),
                        'cover_img' => $user->role !== 'organization' ? ($user->cover_img ? '/storage/'.$user->cover_img : null) : ($user->organization?->user?->cover_img ? '/storage/'.$user->organization?->user?->cover_img : null),
                        'dob' => $user->dob,
                        'joined' => $user->created_at->format('F Y'),
                        'email_verified_at' => $user->email_verified_at,
                        'ownership_verified_at' => $user->ownership_verified_at,
                        'referral_link' => $user->referral_code ? url('/register?ref='.$user->referral_code) : null,
                        'push_token' => $user->push_token ?? null,
                        'has_push_device' => $user->hasActivePushDevice(),
                        'timezone' => $user->timezone ?? 'UTC',
                        'care_alliance_wallet_eligible' => $user->careAllianceWalletEligible(),
                        'wallet_header_visible' => $user->walletHeaderVisible(),
                        'care_alliance' => $careAllianceHub ? [
                            'slug' => $careAllianceHub->slug,
                            'name' => $careAllianceHub->name,
                        ] : null,
                        'proximity_notifications_enabled' => $user->proximity_notifications_enabled !== false,
                        'organization' => $user->organization ? [
                            'id' => $user->organization->id,
                            'ein' => $user->organization->ein ?? null,
                            'name' => $user->organization->name,
                            'community_organization_type_id' => $user->organization->community_organization_type_id ?? null,
                            'community_organization_type_slug' => $user->organization->communityOrganizationType?->slug ?? null,
                            'community_organization_type_other' => $user->organization->community_organization_type_other ?? null,
                            'is_family_reunion' => $user->organization->isFamilyReunion(),
                            'registered_user_image' => $user->organization->registered_user_image ? '/storage/'.$user->organization->registered_user_image : null,
                            'contact_title' => $user->organization->contact_title,
                            'website' => $user->organization->website,
                            'wefunder_project_url' => $user->organization->wefunder_project_url ?? null,
                            'youtube_channel_url' => $user->organization->youtube_channel_url ?? null,
                            'description' => $user->organization->description,
                            'mission' => $user->organization->mission,
                            'address' => implode(', ', array_filter([
                                $user->organization->street,
                                $user->organization->city,
                                $user->organization->state,
                                $user->organization->zip,
                            ])),
                            'joined' => $user->created_at->format('F Y'),
                            'gift_card_terms_approved' => $user->organization->gift_card_terms_approved ?? false,
                            'gift_card_terms_approved_at' => $user->organization->gift_card_terms_approved_at ? $user->organization->gift_card_terms_approved_at->toISOString() : null,
                            'public_view_slug' => $user->slug ?? $user->id, // Use user slug for public view
                        ] : null,
                        'service_seller_profile' => $user->serviceSellerProfile ? [
                            'id' => $user->serviceSellerProfile->id,
                            'verification_status' => $user->serviceSellerProfile->verification_status,
                        ] : null,
                    ];
                }
            }
        }

        // Get footer settings
        $footerSettings = AdminSetting::get('footer_settings', null);
        if ($footerSettings && is_array($footerSettings)) {
            // Settings are already decoded from JSON
        } else {
            $footerSettings = null;
        }

        // SEO (from admin SEO settings) for main app only — used for social share previews (Facebook, WhatsApp, etc.)
        $seoSiteName = (! $isLivestockDomain && ! $isMerchantDomain) ? SeoService::getSiteName() : null;
        $seoCanonical = (! $isLivestockDomain && ! $isMerchantDomain) ? $request->url() : null;
        $seoDefaultImage = (! $isLivestockDomain && ! $isMerchantDomain) ? SeoService::getDefaultShareImage() : null;

        // Consume flash messages so they are only sent once (not on every reload/page switch)
        $success = $request->session()->pull('success');
        $error = $request->session()->pull('error');
        $info = $request->session()->pull('info');
        $warning = $request->session()->pull('warning');
        $importErrors = $request->session()->pull('import_errors');
        $kioskServiceRequest = $request->session()->pull('kiosk_service_request');
        $twilioTest = $request->session()->pull('twilio_test');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appEnv' => config('app.env'),
            'unityCallEnabled' => (bool) config('unity_call.enabled'),
            'seoSiteName' => $seoSiteName,
            'seoCanonical' => $seoCanonical,
            'seoDefaultImage' => $seoDefaultImage,
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $userData,
                'permissions' => $permissions,
                'roles' => $roles,
            ],
            'ziggy' => fn (): array => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'csrf_token' => csrf_token(),
            'pwaVersion' => fn (): string => AppVersion::current(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => fn () => array_merge(
                is_array($request->session()->get('flash')) ? $request->session()->get('flash') : [],
                array_filter([
                    'inviteUrl' => $request->session()->get('inviteUrl'),
                    'success' => $success,
                    'error' => $error,
                    'info' => $info,
                    'warning' => $warning,
                    'import_errors' => $importErrors,
                    'kiosk_service_request' => $kioskServiceRequest,
                    'youtube_upload_path' => $request->session()->pull('youtube_upload_path'),
                    'twilio_test' => $twilioTest,
                ])
            ),
            'browser_publish_url' => fn () => $request->session()->pull('browser_publish_url'),
            'success' => fn () => $success,
            'error' => fn () => $error,
            'info' => fn () => $info,
            'warning' => fn () => $warning,
            'kiosk_service_request' => fn () => $kioskServiceRequest,
            'isImpersonating' => $request->session()->has('impersonate_user_id'),
            'originalUserId' => $request->session()->get('impersonate_user_id'),
            'livestockDomain' => config('livestock.domain'),
            'merchantDomain' => config('merchant.domain'),
            'footerSettings' => $footerSettings,
            'processingFeeRates' => StripeProcessingFeeEstimator::ratesForFrontend(),
            'donationRewardPointsAmount' => fn () => ($user instanceof \App\Models\User && ! $isLivestockDomain && ! $isMerchantDomain)
                ? BelievePointsRewardService::donationBrpAmountForUser($user)
                : ParticipationBrpAwardService::previewAmount(null, BrpParticipationModule::DONATION),
            'brpParticipationSettings' => fn () => ($user instanceof \App\Models\User && ! $isLivestockDomain && ! $isMerchantDomain)
                ? BrpParticipationSettingsService::frontendPayload($user)
                : null,
            'supporterSubscription' => fn () => ($user instanceof \App\Models\User && ($user->role ?? null) === 'user')
                ? SupporterSubscriptionService::subscriptionStateForUser($user)
                : null,
            'bridgeVerification' => fn () => $this->sharedBridgeVerification($user, $isLivestockDomain, $isMerchantDomain),
            'savedPaymentMethods' => fn () => $this->sharedSavedPaymentMethods($user, $isLivestockDomain, $isMerchantDomain),
            'paymentMethodsUrl' => fn () => $this->sharedPaymentMethodsUrl($user, $isLivestockDomain, $isMerchantDomain),
            'firebaseWeb' => [
                'apiKey' => config('services.firebase.api_key'),
                'authDomain' => config('services.firebase.auth_domain'),
                'projectId' => config('services.firebase.project_id'),
                'storageBucket' => config('services.firebase.storage_bucket'),
                'messagingSenderId' => config('services.firebase.messaging_sender_id'),
                'appId' => config('services.firebase.app_id'),
                'vapidKey' => config('services.firebase.vapid_key'),
            ],
            'mobileNav' => fn () => ($user instanceof \App\Models\User && ! $isLivestockDomain && ! $isMerchantDomain && app(\App\Services\FavoriteMenuService::class)->mobileNavRoleKey($user) !== null)
                ? app(\App\Services\FavoriteMenuService::class)->payloadForUser($user)
                : null,
        ];
    }

    /**
     * @return array{initialized: bool, kyb_status: string, kyc_status: string, requires_verification: bool, has_wallet: bool, is_verified: bool}|null
     */
    private function sharedBridgeVerification(mixed $user, bool $isLivestockDomain, bool $isMerchantDomain): ?array
    {
        if (! $user instanceof \App\Models\User || $isLivestockDomain || $isMerchantDomain) {
            return null;
        }

        if (! $user->hasNonprofitDashboardRole()) {
            return null;
        }

        $organization = Organization::forAuthUser($user);

        return BridgeVerificationService::payloadForOrganization($organization);
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     brand: string|null,
     *     last4: string|null,
     *     exp_month: int|null,
     *     exp_year: int|null,
     *     bank_name: string|null,
     *     is_default: bool
     * }>
     */
    private function sharedSavedPaymentMethods(mixed $user, bool $isLivestockDomain, bool $isMerchantDomain): array
    {
        if (! $user instanceof \App\Models\User || $isLivestockDomain || $isMerchantDomain) {
            return [];
        }

        return \App\Services\UserStripePaymentMethodService::listForUser($user);
    }

    private function sharedPaymentMethodsUrl(mixed $user, bool $isLivestockDomain, bool $isMerchantDomain): ?string
    {
        if (! $user instanceof \App\Models\User || $isLivestockDomain || $isMerchantDomain) {
            return null;
        }

        return $user->hasNonprofitDashboardRole()
            ? route('settings.saved-payment-methods.index')
            : route('user.profile.payment-methods.index');
    }
}
