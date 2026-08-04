<?php

namespace App\Policies;

use App\Enums\OrganizationAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;

class OrganizationAnnouncementPolicy
{
    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Determine whether the user can view the organization's announcement list.
     * Item-level visibility (draft/scheduled/hidden vs. published) is enforced in view().
     */
    public function viewAny(?User $user, Organization $organization): bool
    {
        return true;
    }

    /**
     * Staff can always view; everyone else only sees published announcements within their window.
     */
    public function view(?User $user, OrganizationAnnouncement $announcement): bool
    {
        $organization = $this->organizationOf($announcement);

        if ($user && $this->permissions->canManageAnnouncements($user, $organization)) {
            return true;
        }

        if ($announcement->status !== OrganizationAnnouncementStatus::Published) {
            return false;
        }

        if ($announcement->published_at && $announcement->published_at->isFuture()) {
            return false;
        }

        if ($announcement->expires_at && $announcement->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->permissions->canManageAnnouncements($user, $organization);
    }

    public function update(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function delete(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function publish(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function schedule(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function archive(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function restore(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function hide(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function pin(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    public function moderateComments(User $user, OrganizationAnnouncement $announcement): bool
    {
        return $this->permissions->canManageAnnouncements($user, $this->organizationOf($announcement));
    }

    private function organizationOf(OrganizationAnnouncement $announcement): Organization
    {
        return $announcement->organization ?? Organization::findOrFail($announcement->organization_id);
    }
}
