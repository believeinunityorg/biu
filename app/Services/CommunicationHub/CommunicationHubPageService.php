<?php

namespace App\Services\CommunicationHub;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationDiscussionCategory;
use App\Models\User;

/**
 * Builds the shared prop payload for the Communication Hub dashboard and the public
 * organization "Community" tab, so both surfaces stay in sync.
 */
class CommunicationHubPageService
{
    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
        private readonly OrganizationAnnouncementService $announcementService,
        private readonly OrganizationDiscussionService $discussionService,
        private readonly CommunicationHubPresenter $presenter,
    ) {}

    /**
     * @param  array<string, mixed>  $options  activeTab, announcementsLimit, discussionsLimit, managed (bool), community (bool)
     * @return array<string, mixed>
     */
    public function hubProps(Organization $organization, ?User $user, array $options = []): array
    {
        $this->discussionService->ensureDefaultCategories($organization);
        OrganizationAnnouncement::promoteScheduledForOrganization($organization->id);

        $isManager = $user !== null && $this->permissions->canManageAnnouncements($user, $organization);
        $canModerateDiscussions = $user !== null && $this->permissions->canModerateDiscussions($user, $organization);
        $canViewAnnouncements = $this->permissions->canViewAnnouncements($user, $organization);
        $canViewDiscussions = $this->permissions->canViewDiscussions($user, $organization);
        $canCreateDiscussion = $user !== null && $this->permissions->canCreateDiscussion($user, $organization);

        $announcementsLimit = max(1, min(20, (int) ($options['announcementsLimit'] ?? 5)));
        $discussionsLimit = max(1, min(20, (int) ($options['discussionsLimit'] ?? 5)));

        $announcementsPage = null;
        if ($canViewAnnouncements) {
            $announcementsPage = $isManager
                ? $this->announcementService->listForOrganization($organization, [
                    'per_page' => $announcementsLimit,
                    'lightweight' => true,
                ])
                : $this->announcementService->listPublic($organization, [
                    'per_page' => $announcementsLimit,
                    'lightweight' => true,
                ]);
        }

        $discussionsPage = null;
        if ($canViewDiscussions) {
            $discussionsPage = $this->discussionService->listForOrganization($organization, [
                'per_page' => $discussionsLimit,
                'tab' => 'all',
                'lightweight' => true,
                'public_only' => ! $canModerateDiscussions,
                'user_id' => $user?->id,
            ]);
        }

        $categories = $canViewDiscussions
            ? OrganizationDiscussionCategory::query()
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'organization_id', 'name', 'slug', 'color', 'sort_order', 'is_active'])
            : collect();

        $settings = $this->permissions->getSettings($organization);

        $organization->loadMissing('user:id,slug');

        $community = (bool) ($options['community'] ?? ! $isManager);

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->user?->slug,
            ],
            'announcements' => $announcementsPage
                ? $announcementsPage->getCollection()
                    ->map(fn (OrganizationAnnouncement $announcement) => $this->presenter->announcementToArray($announcement))
                    ->values()
                    ->all()
                : [],
            'announcementsTotal' => $announcementsPage?->total() ?? 0,
            'discussions' => $discussionsPage
                ? $discussionsPage->getCollection()
                    ->map(fn ($discussion) => $this->presenter->discussionToArray($discussion))
                    ->values()
                    ->all()
                : [],
            'discussionsTotal' => $discussionsPage?->total() ?? 0,
            'categories' => $categories
                ->map(fn (OrganizationDiscussionCategory $category) => $this->presenter->categoryToArray($category))
                ->values()
                ->all(),
            'settings' => $this->presenter->settingsToArray($settings, $organization),
            'permissions' => [
                'can_view_announcements' => $canViewAnnouncements,
                'can_view_discussions' => $canViewDiscussions,
                'can_manage_announcements' => $isManager,
                'can_create_announcement' => $isManager,
                'can_moderate_discussions' => $canModerateDiscussions,
                'can_create_discussion' => $canCreateDiscussion,
                'can_manage_settings' => $isManager,
            ],
            'hubContext' => [
                'mode' => $community ? 'community' : 'manage',
                'org_slug' => $organization->user?->slug,
            ],
            'reportReasons' => config('communication_hub.report_reasons', []),
            'announcementCategories' => config('communication_hub.announcement_categories', []),
            'activeTab' => in_array($options['activeTab'] ?? 'announcements', ['announcements', 'discussions', 'settings'], true)
                ? $options['activeTab']
                : 'announcements',
            'visibilityAudiences' => config('communication_hub.visibility_audiences', []),
        ];
    }
}
