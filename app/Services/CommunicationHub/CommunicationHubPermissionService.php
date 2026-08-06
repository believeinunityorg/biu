<?php

namespace App\Services\CommunicationHub;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\GroupEligibilityService;

class CommunicationHubPermissionService
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_MEMBERS = 'members';

    public const VISIBILITY_STAFF = 'staff';

    /** @var list<string> */
    public const VISIBILITY_AUDIENCES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_FOLLOWERS,
        self::VISIBILITY_MEMBERS,
        self::VISIBILITY_STAFF,
    ];

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

        $merged = array_merge($defaults, $custom);

        $merged['announcement_visibility'] = $this->normalizeVisibility(
            $merged['announcement_visibility'] ?? self::VISIBILITY_PUBLIC
        );
        $merged['discussion_visibility'] = $this->normalizeVisibility(
            $merged['discussion_visibility'] ?? self::VISIBILITY_PUBLIC
        );

        return $merged;
    }

    public function normalizeVisibility(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : self::VISIBILITY_PUBLIC;

        return in_array($value, self::VISIBILITY_AUDIENCES, true)
            ? $value
            : self::VISIBILITY_PUBLIC;
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

    /**
     * Whether the viewer may see the organization's announcements board.
     */
    public function canViewAnnouncements(?User $user, Organization $organization): bool
    {
        $settings = $this->getSettings($organization);

        return $this->meetsVisibilityAudience(
            $user,
            $organization,
            (string) $settings['announcement_visibility']
        );
    }

    /**
     * Whether the viewer may see the organization's discussion board.
     */
    public function canViewDiscussions(?User $user, Organization $organization): bool
    {
        $settings = $this->getSettings($organization);

        return $this->meetsVisibilityAudience(
            $user,
            $organization,
            (string) $settings['discussion_visibility']
        );
    }

    /**
     * Audience hierarchy: public ⊂ followers ⊂ members ⊂ staff.
     */
    public function meetsVisibilityAudience(?User $user, Organization $organization, string $audience): bool
    {
        $audience = $this->normalizeVisibility($audience);

        if ($audience === self::VISIBILITY_PUBLIC) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($this->canManageAnnouncements($user, $organization)) {
            return true;
        }

        return match ($audience) {
            self::VISIBILITY_FOLLOWERS => $this->isFollower($user, $organization) || $this->isMember($user, $organization),
            self::VISIBILITY_MEMBERS => $this->isMember($user, $organization),
            self::VISIBILITY_STAFF => false,
            default => false,
        };
    }

    public function canCreateDiscussion(User $user, Organization $organization): bool
    {
        if (! $this->canViewDiscussions($user, $organization)) {
            return false;
        }

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
        if (! $this->canViewAnnouncements($user, $organization)) {
            return false;
        }

        $settings = $this->getSettings($organization);

        if (! ($settings['enable_comments'] ?? true)) {
            return false;
        }

        if ($this->canManageAnnouncements($user, $organization)) {
            return true;
        }

        return $this->isFollower($user, $organization) || $this->isMember($user, $organization);
    }

    public function canReplyToDiscussions(User $user, Organization $organization): bool
    {
        if (! $this->canViewDiscussions($user, $organization)) {
            return false;
        }

        if ($this->canModerateDiscussions($user, $organization)) {
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
