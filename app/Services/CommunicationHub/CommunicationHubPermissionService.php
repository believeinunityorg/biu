<?php

namespace App\Services\CommunicationHub;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\GroupEligibilityService;

class CommunicationHubPermissionService
{
    public function __construct(
        private readonly GroupEligibilityService $eligibility,
    ) {}

    /**
     * Organization's communication hub settings merged with platform defaults.
     *
     * @return array<string, mixed>
     */
    public function getSettings(Organization $organization): array
    {
        $defaults = (array) config('communication_hub.default_settings', []);
        $custom = (array) ($organization->communication_settings ?? []);

        return array_merge($defaults, $custom);
    }

    /**
     * Org owner, platform admin, or active board staff can manage announcements.
     */
    public function canManageAnnouncements(User $user, Organization $organization): bool
    {
        if (Organization::forAuthUser($user)?->id === $organization->id) {
            return true;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isBoardStaff($user, $organization);
    }

    /**
     * Same authority level as announcement management for now (admin/moderator).
     */
    public function canModerateDiscussions(User $user, Organization $organization): bool
    {
        return $this->canManageAnnouncements($user, $organization);
    }

    public function canCreateDiscussion(User $user, Organization $organization): bool
    {
        if ($this->canModerateDiscussions($user, $organization)) {
            return true;
        }

        $settings = $this->getSettings($organization);

        if (($settings['allow_followers_to_post'] ?? true) && $this->isFollower($user, $organization)) {
            return true;
        }

        if (($settings['allow_members_to_post'] ?? true) && $this->isMember($user, $organization)) {
            return true;
        }

        return false;
    }

    public function canCommentOnAnnouncements(User $user, Organization $organization): bool
    {
        $settings = $this->getSettings($organization);

        if (! ($settings['enable_comments'] ?? true)) {
            return false;
        }

        if ($this->canManageAnnouncements($user, $organization)) {
            return true;
        }

        return $this->isFollower($user, $organization) || $this->isMember($user, $organization);
    }

    public function isFollower(User $user, Organization $organization): bool
    {
        return UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->exists();
    }

    public function isMember(User $user, Organization $organization): bool
    {
        return $this->eligibility->isParentMember($user, $organization);
    }

    /**
     * Active board member for this organization (defensively checks the relation exists
     * in case the BoardMember model/relation is renamed or removed in the future).
     */
    private function isBoardStaff(User $user, Organization $organization): bool
    {
        if (! method_exists($organization, 'boardMembers')) {
            return false;
        }

        return $organization->boardMembers()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
