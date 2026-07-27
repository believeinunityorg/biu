<?php

namespace App\Services;

use App\Data\OrganizationReadinessModules;
use App\Data\OrganizationSetupChecklistItems;
use App\Models\AiChatConversation;
use App\Models\AiVideo;
use App\Models\Campaign;
use App\Models\FacebookAccount;
use App\Models\Newsletter;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLivestream;
use App\Support\LivestreamOverlayConfig;
use App\Support\PayAsYouGoServices;
use App\Support\UserEmailCredits;

class OrganizationSetupChecklistService
{
    public function __construct(
        private readonly OrganizationOnboardingService $onboardingService,
    ) {}

    /**
     * @return array{
     *     percent: int,
     *     completed: int,
     *     total: int,
     *     remaining: int,
     *     modules: list<array<string, mixed>>,
     *     sections: list<array<string, mixed>>
     * }|null
     */
    public function forOrganization(?Organization $organization, ?User $user): ?array
    {
        if (! $organization || ! $user) {
            return null;
        }

        $organization->loadMissing(['emailConnections', 'livestreams', 'bridgeIntegration']);

        $statusById = $this->resolveStatuses($organization, $user);
        $completedAtById = $this->resolveCompletedAt($organization, $user);

        $items = collect(OrganizationSetupChecklistItems::all())
            ->map(function (array $def) use ($statusById, $completedAtById) {
                $status = $statusById[$def['id']] ?? 'not_started';
                $href = $this->itemHref($def);

                return [
                    'id' => $def['id'],
                    'module' => $def['module'],
                    'section' => $def['section'],
                    'label' => $def['label'],
                    'description' => $def['description'],
                    'route' => $def['route'],
                    'anchor' => $def['anchor'],
                    'weight' => $def['weight'],
                    'href' => $href,
                    'route_label' => $this->actionLabel($status, $def['route_label']),
                    'status' => $status,
                    'completed_at' => $completedAtById[$def['id']] ?? null,
                ];
            })
            ->values()
            ->all();

        $modules = collect(OrganizationReadinessModules::all())
            ->map(function (array $moduleDef) use ($items) {
                $moduleItems = collect($items)->where('module', $moduleDef['id'])->values();
                $stats = $this->moduleStats($moduleItems->all());

                $firstIncomplete = collect($moduleItems)
                    ->first(fn (array $item) => $item['status'] !== 'completed');

                return array_merge($moduleDef, $stats, [
                    'items' => $moduleItems->all(),
                    'first_incomplete' => $firstIncomplete,
                    'next_module' => $this->nextModuleId($moduleDef['id']),
                    'prev_module' => $this->prevModuleId($moduleDef['id']),
                ]);
            })
            ->values()
            ->all();

        $sections = collect($items)
            ->groupBy('section')
            ->map(function ($sectionItems, $sectionId) {
                $stats = $this->moduleStats($sectionItems->values()->all());

                return [
                    'id' => (string) $sectionId,
                    'label' => $sectionId === OrganizationSetupChecklistItems::SECTION_SYSTEM ? 'System' : 'Tools',
                    'completed' => $stats['completed'],
                    'total' => $stats['total'],
                    'percent' => $stats['percent'],
                    'items' => $sectionItems->values()->all(),
                ];
            })
            ->values()
            ->sortBy(fn (array $s) => $s['id'] === OrganizationSetupChecklistItems::SECTION_SYSTEM ? 0 : 1)
            ->values()
            ->all();

        $overall = $this->moduleStats($items);

        return [
            'percent' => $overall['percent'],
            'completed' => $overall['completed'],
            'total' => $overall['total'],
            'remaining' => max(0, $overall['total'] - $overall['completed']),
            'modules' => $modules,
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     completed: int,
     *     total: int,
     *     percent: int,
     *     status_color: 'green'|'yellow'|'red',
     *     weighted_completed: float,
     *     weighted_total: float
     * }
     */
    private function moduleStats(array $items): array
    {
        $total = count($items);
        $completed = collect($items)->where('status', 'completed')->count();

        $weightedTotal = collect($items)->sum(fn (array $item) => (int) ($item['weight'] ?? 1));
        $weightedCompleted = collect($items)
            ->where('status', 'completed')
            ->sum(fn (array $item) => (int) ($item['weight'] ?? 1));

        $percent = $weightedTotal > 0
            ? (int) round(($weightedCompleted / $weightedTotal) * 100)
            : 100;

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
            'status_color' => $this->statusColor($percent),
            'weighted_completed' => $weightedCompleted,
            'weighted_total' => $weightedTotal,
        ];
    }

    /**
     * @return 'green'|'yellow'|'red'
     */
    private function statusColor(int $percent): string
    {
        if ($percent >= 100) {
            return 'green';
        }

        if ($percent > 0) {
            return 'yellow';
        }

        return 'red';
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function itemHref(array $def): string
    {
        $url = route($def['route']);

        if (filled($def['anchor'] ?? null)) {
            return $url.'#'.($def['anchor']);
        }

        return $url;
    }

    private function nextModuleId(string $moduleId): ?string
    {
        $ids = OrganizationReadinessModules::ids();
        $index = array_search($moduleId, $ids, true);

        if ($index === false || $index >= count($ids) - 1) {
            return null;
        }

        return $ids[$index + 1];
    }

    private function prevModuleId(string $moduleId): ?string
    {
        $ids = OrganizationReadinessModules::ids();
        $index = array_search($moduleId, $ids, true);

        if ($index === false || $index <= 0) {
            return null;
        }

        return $ids[$index - 1];
    }

    /**
     * @return array<string, 'completed'|'in_progress'|'not_started'>
     */
    private function resolveStatuses(Organization $organization, User $user): array
    {
        $integrationCount = $this->connectedIntegrationCount($organization);

        $stripeStarted = filled($organization->stripe_connect_account_id);
        $stripeReady = StripeConnectOrganizationService::organizationCanAcceptDirectDonations($organization);

        $paypalStarted = filled($organization->paypal_payout_email);
        $paypalReady = (bool) $organization->paypal_payouts_enabled;

        $payoutPreferenceSet = filled($organization->preferred_payout_method);
        $payoutReady = $organization->isPayoutReady();

        $youtubePartial = filled($organization->youtube_channel_url ?? null)
            && ! filled($organization->youtube_access_token ?? null);

        $dropboxConnected = filled($organization->dropbox_refresh_token ?? null);

        $facebookConnected = FacebookAccount::query()
            ->where('organization_id', $organization->id)
            ->where('is_connected', true)
            ->exists();
        $facebookPartial = ! $facebookConnected && FacebookAccount::query()
            ->where('organization_id', $organization->id)
            ->exists();

        $emailConnectionCount = $organization->emailConnections()->where('is_active', true)->count();
        $emailInvitesSent = (int) ($user->emails_used ?? 0) > 0;

        $onboardingComplete = $this->onboardingService->isComplete($organization);
        $onboardingPartial = ! $onboardingComplete
            && ($this->onboardingService->profileCompletionForOrganization($organization)['completed'] ?? 0) > 0;

        $bridge = BridgeVerificationService::payloadForOrganization($organization);
        $ownershipVerified = $user->ownership_verified_at !== null;

        $verificationComplete = $onboardingComplete || $bridge['is_verified'] || $ownershipVerified;
        $verificationPartial = ! $verificationComplete && ($onboardingPartial || $bridge['initialized']);

        $payg = PayAsYouGoServices::forUser($user);
        $paygPurchased = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['email_purchase', 'sms_purchase', 'credit_purchase'])
            ->where('status', 'completed')
            ->exists();
        $paygUsed = (int) ($user->emails_used ?? 0) > 0
            || (int) ($user->sms_used ?? 0) > 0
            || (int) ($user->ai_tokens_used ?? 0) > 0;
        $paygHasBalance = $payg['email']['balance'] > 0
            || $payg['sms']['balance'] > 0
            || $payg['ai']['balance'] > 0;

        $overlayConfigured = $this->overlayStudioConfigured($organization);
        $livestreamCount = $organization->livestreams()->count();
        $livestreamStarted = $organization->livestreams()->whereNotNull('started_at')->exists();
        $livestreamScheduled = $organization->livestreams()->whereNotNull('scheduled_at')->whereNull('started_at')->exists();

        $unityMeetCount = UserLivestream::query()->where('user_id', $user->id)->count();

        $aiChatUsed = AiChatConversation::query()->where('user_id', $user->id)->exists()
            || (int) ($user->ai_tokens_used ?? 0) > 0;

        $aiStudioUsed = AiVideo::query()->where('organization_id', $organization->id)->exists()
            || (float) ($user->ai_media_studio_credits ?? 0) > 0;

        $engagementStarted = Newsletter::query()->where('organization_id', $organization->id)->exists();
        $campaignStarted = Campaign::query()->where('organization_id', $organization->id)->exists();

        $profileComplete = $this->profileInformationComplete($organization, $user);
        $profilePartial = ! $profileComplete && $this->profileInformationPartial($organization, $user);

        $boardComplete = $this->onboardingService->boardMembersAreComplete($organization);
        $boardPartial = ! $boardComplete
            && $organization->boardMembers()->where('is_active', true)->exists();

        $giftCardApproved = (bool) $organization->gift_card_terms_approved;

        $marketplaceListing = OrganizationProduct::query()
            ->where('organization_id', $organization->id)
            ->first();
        $marketplaceComplete = $marketplaceListing !== null
            && in_array((string) ($marketplaceListing->status ?? ''), ['active', 'approved'], true);
        $marketplacePartial = $marketplaceListing !== null && ! $marketplaceComplete;

        return [
            'profile_information' => $this->status($profileComplete, $profilePartial),
            'team_members' => $this->status($boardComplete, $boardPartial),
            'email_invites' => $this->status($emailConnectionCount > 0, $emailInvitesSent && $emailConnectionCount === 0),

            'organization_verification' => $this->status($verificationComplete, $verificationPartial),
            'payout_settings' => $this->status($payoutReady, $payoutPreferenceSet && ! $payoutReady),
            'gift_card_agreement' => $this->status($giftCardApproved, false),
            'marketplace_agreement' => $this->status($marketplaceComplete, $marketplacePartial),

            'integrations' => $this->status($integrationCount >= 2, $integrationCount === 1),
            'social_media' => $this->status($facebookConnected, $facebookPartial),
            'youtube' => $this->status(filled($organization->youtube_access_token ?? null), $youtubePartial),
            'stripe_payouts' => $this->status($stripeReady, $stripeStarted && ! $stripeReady),
            'paypal_payouts' => $this->status($paypalReady, $paypalStarted && ! $paypalReady),
            'dropbox' => $this->status($dropboxConnected, false),

            'ai_chat' => $this->status($aiChatUsed, false),
            'pay_as_you_go' => $this->status($paygPurchased, ! $paygPurchased && ($paygUsed || $paygHasBalance)),
            'overlay_studio' => $this->status($overlayConfigured, false),
            'livestream' => $this->status($livestreamCount > 0, false),
            'unity_live' => $this->status($livestreamStarted, $livestreamScheduled),
            'unity_meet' => $this->status($unityMeetCount > 0, false),
            'ai_video_studio' => $this->status($aiStudioUsed, false),
            'engagement' => $this->status($engagementStarted, false),
            'auto_drip_campaign' => $this->status($campaignStarted, false),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function resolveCompletedAt(Organization $organization, User $user): array
    {
        $timestamps = [];

        if ((bool) $organization->gift_card_terms_approved) {
            $timestamps['gift_card_agreement'] = $organization->gift_card_terms_approved_at?->toIso8601String();
        }

        if ($organization->onboarding_completed_at) {
            $timestamps['organization_verification'] = $organization->onboarding_completed_at->toIso8601String();
        }

        if ($user->ownership_verified_at) {
            $timestamps['organization_verification'] = $user->ownership_verified_at->toIso8601String();
        }

        return $timestamps;
    }

    private function profileInformationComplete(Organization $organization, User $user): bool
    {
        $hasLogo = filled($user->image);
        $hasMission = filled(trim((string) ($organization->mission ?? '')))
            || filled(trim((string) ($organization->description ?? '')));
        $hasName = filled(trim((string) ($organization->name ?? '')));

        return $hasLogo && $hasMission && $hasName;
    }

    private function profileInformationPartial(Organization $organization, User $user): bool
    {
        return filled($user->image)
            || filled(trim((string) ($organization->mission ?? '')))
            || filled(trim((string) ($organization->description ?? '')));
    }

    private function connectedIntegrationCount(Organization $organization): int
    {
        $count = 0;

        if (filled($organization->stripe_connect_account_id)) {
            $count++;
        }
        if ((bool) $organization->paypal_payouts_enabled) {
            $count++;
        }
        if (filled($organization->dropbox_refresh_token ?? null)) {
            $count++;
        }
        if (filled($organization->youtube_access_token ?? null)) {
            $count++;
        }
        if (FacebookAccount::query()->where('organization_id', $organization->id)->where('is_connected', true)->exists()) {
            $count++;
        }
        if ($organization->emailConnections()->where('is_active', true)->exists()) {
            $count++;
        }

        return $count;
    }

    private function overlayStudioConfigured(Organization $organization): bool
    {
        $raw = $organization->livestream_overlay_settings;
        if (! is_array($raw) || $raw === []) {
            return false;
        }

        $config = LivestreamOverlayConfig::merge($raw);
        $defaults = LivestreamOverlayConfig::defaults();

        $fields = [
            'speaker_name',
            'banner_message',
            'banner_cta',
            'donation_message',
            'donation_cta',
            'sponsor_label',
            'scrolling_message',
            'qr_label',
        ];

        foreach ($fields as $field) {
            if (trim((string) ($config[$field] ?? '')) !== trim((string) ($defaults[$field] ?? ''))
                && trim((string) ($config[$field] ?? '')) !== '') {
                return true;
            }
        }

        if (! empty($config['logo_path']) && empty($config['logo_from_profile'])) {
            return true;
        }

        if (! empty($config['sponsor_image_path']) || ! empty($config['qr_code_path'])) {
            return true;
        }

        if (($config['accent_color'] ?? '') !== ($defaults['accent_color'] ?? '')) {
            return true;
        }

        return false;
    }

    /**
     * @return 'completed'|'in_progress'|'not_started'
     */
    private function status(bool $completed, bool $inProgress): string
    {
        if ($completed) {
            return 'completed';
        }

        if ($inProgress) {
            return 'in_progress';
        }

        return 'not_started';
    }

    private function actionLabel(string $status, string $defaultLabel): string
    {
        return match ($status) {
            'completed' => 'View',
            'in_progress' => 'Continue',
            default => $defaultLabel === 'View' ? 'Start' : $defaultLabel,
        };
    }
}
