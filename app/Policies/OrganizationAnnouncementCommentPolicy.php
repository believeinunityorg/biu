<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationAnnouncementComment;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;

class OrganizationAnnouncementCommentPolicy
{
    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Followers/members may comment when the announcement and org both allow it; staff always may.
     */
    public function create(User $user, OrganizationAnnouncement $announcement): bool
    {
        $organization = $announcement->organization ?? Organization::findOrFail($announcement->organization_id);

        if (! $announcement->allow_comments) {
            return $this->permissions->canManageAnnouncements($user, $organization);
        }

        return $this->permissions->canCommentOnAnnouncements($user, $organization);
    }

    public function update(User $user, OrganizationAnnouncementComment $comment): bool
    {
        return (int) $comment->user_id === (int) $user->id && ! $comment->is_locked;
    }

    /**
     * Author may soft-delete their own comment; staff may delete any comment for moderation.
     */
    public function delete(User $user, OrganizationAnnouncementComment $comment): bool
    {
        if ($this->permissions->canManageAnnouncements($user, $this->organizationOf($comment))) {
            return true;
        }

        return (int) $comment->user_id === (int) $user->id;
    }

    public function hide(User $user, OrganizationAnnouncementComment $comment): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($comment));
    }

    public function restore(User $user, OrganizationAnnouncementComment $comment): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($comment));
    }

    private function organizationOf(OrganizationAnnouncementComment $comment): Organization
    {
        return $comment->organization ?? Organization::findOrFail($comment->organization_id);
    }
}
