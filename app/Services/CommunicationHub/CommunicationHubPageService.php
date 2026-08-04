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
     * @param  array<string, mixed>  $options  activeTab, announcementsLimit, discussionsLimit, managed (bool)
     * @return array<string, mixed>
     */
    public function hubProps(Organization $organization, ?User $user, array $options = []): array
    {
        $this->discussionService->ensureDefaultCategories($organization);
        OrganizationAnnouncement::promoteScheduledForOrganization($organization->id);

        $isManager = $user !== null && $this->permissions->canManageAnnouncements($user, $organization);
        $canModerateDiscussions = $user !== null && $this->permissions->canModerateDiscussions($user, $organization);
        $canCreateDiscussion = $user !== null && $this->permissions->canCreateDiscussion($user, $organization);

        $announcementsLimit = max(1, min(20, (int) ($options['announcementsLimit'] ?? 5)));
        $discussionsLimit = max(1, min(20, (int) ($options['discussionsLimit'] ?? 5)));

        // Hub previews only need list cards — skip heavier relation/count work where possible.
        $announcementsPage = $isManager
            ? $this->announcementService->listForOrganization($organization, [
                'per_page' => $announcementsLimit,
                'lightweight' => true,
            ])
            : $this->announcementService->listPublic($organization, [
                'per_page' => $announcementsLimit,
                'lightweight' => true,
            ]);

        $discussionsPage = $this->discussionService->listForOrganization($organization, [
            'per_page' => $discussionsLimit,
            'tab' => 'all',
            'lightweight' => true,
        ]);

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'organization_id', 'name', 'slug', 'color', 'sort_order', 'is_active']);

        $settings = $this->permissions->getSettings($organization);

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'announcements' => $announcementsPage->getCollection()
                ->map(fn (OrganizationAnnouncement $announcement) => $this->presenter->announcementToArray($announcement))
                ->values()
                ->all(),
            'announcementsTotal' => $announcementsPage->total(),
            'discussions' => $discussionsPage->getCollection()
                ->map(fn ($discussion) => $this->presenter->discussionToArray($discussion))
                ->values()
                ->all(),
            'discussionsTotal' => $discussionsPage->total(),
            'categories' => $categories
                ->map(fn (OrganizationDiscussionCategory $category) => $this->presenter->categoryToArray($category))
                ->values()
                ->all(),
            'settings' => $this->presenter->settingsToArray($settings, $organization),
            'permissions' => [
                'can_manage_announcements' => $isManager,
                'can_create_announcement' => $isManager,
                'can_moderate_discussions' => $canModerateDiscussions,
                'can_create_discussion' => $canCreateDiscussion,
                'can_manage_settings' => $isManager,
            ],
            'reportReasons' => config('communication_hub.report_reasons', []),
            'announcementCategories' => config('communication_hub.announcement_categories', []),
            'activeTab' => in_array($options['activeTab'] ?? 'announcements', ['announcements', 'discussions'], true)
                ? $options['activeTab']
                : 'announcements',
        ];
    }
}
