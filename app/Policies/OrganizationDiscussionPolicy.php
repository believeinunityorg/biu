<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationDiscussion;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;

class OrganizationDiscussionPolicy
{
    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Determine whether the user can view the organization's discussion list.
     * Item-level visibility (hidden/archived) is enforced in view().
     */
    public function viewAny(?User $user, Organization $organization): bool
    {
        return true;
    }

    /**
     * Staff can always view; everyone else only sees discussions that aren't hidden/archived/deleted.
     */
    public function view(?User $user, OrganizationDiscussion $discussion): bool
    {
        if ($user && $this->permissions->canModerateDiscussions($user, $this->organizationOf($discussion))) {
            return true;
        }

        return $discussion->isVisiblePublicly();
    }

    /**
     * Followers/members may start a discussion (subject to the org's posting settings); staff always may.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->permissions->canCreateDiscussion($user, $organization);
    }

    /**
     * Author may edit their own open thread; staff may edit any thread for moderation.
     */
    public function update(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);

        if ($this->permissions->canModerateDiscussions($user, $organization)) {
            return true;
        }

        return (int) $discussion->created_by === (int) $user->id
            && ! $discussion->is_locked
            && ! $discussion->is_archived;
    }

    /**
     * Author may soft-delete their own thread; staff may delete any thread for moderation.
     */
    public function delete(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);

        if ($this->permissions->canModerateDiscussions($user, $organization)) {
            return true;
        }

        return (int) $discussion->created_by === (int) $user->id;
    }

    public function reply(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);
        $isModerator = $this->permissions->canModerateDiscussions($user, $organization);

        if ($discussion->is_locked || $discussion->is_archived || $discussion->trashed()) {
            return $isModerator;
        }

        if ($discussion->posting_suspended && ! $isModerator) {
            return false;
        }

        return $isModerator || $this->isFollowerOrMember($user, $organization);
    }

    public function react(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);
        $settings = $this->permissions->getSettings($organization);

        if (! ($settings['enable_reactions'] ?? true)) {
            return false;
        }

        return $this->permissions->canModerateDiscussions($user, $organization)
            || $this->isFollowerOrMember($user, $organization);
    }

    public function follow(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);

        return $this->permissions->canModerateDiscussions($user, $organization)
            || $this->isFollowerOrMember($user, $organization);
    }

    public function moderate(User $user, OrganizationDiscussion $discussion): bool
    {
        return $this->permissions->canModerateDiscussions($user, $this->organizationOf($discussion));
    }

    public function report(User $user, OrganizationDiscussion $discussion): bool
    {
        $organization = $this->organizationOf($discussion);
        $settings = $this->permissions->getSettings($organization);

        if (! ($settings['enable_reporting'] ?? true)) {
            return false;
        }

        return $this->permissions->canModerateDiscussions($user, $organization)
            || $this->isFollowerOrMember($user, $organization);
    }

    private function isFollowerOrMember(User $user, Organization $organization): bool
    {
        return $this->permissions->isFollower($user, $organization)
            || $this->permissions->isMember($user, $organization);
    }

    private function organizationOf(OrganizationDiscussion $discussion): Organization
    {
        return $discussion->organization ?? Organization::findOrFail($discussion->organization_id);
    }
}
