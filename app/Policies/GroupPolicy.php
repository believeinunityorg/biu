<?php

namespace App\Policies;

use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\GroupEligibilityService;

class GroupPolicy
{
    public function __construct(
        private readonly GroupEligibilityService $eligibility,
    ) {}

    public function view(?User $user, Group $group): bool
    {
        return $group->status === 'active' || ($user && $this->isMember($user, $group));
    }

    public function create(User $user): bool
    {
        return true; // parent-specific check in controller
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isAdmin($user, $group) || $group->isManagedBy($user);
    }

    public function manageMembers(User $user, Group $group): bool
    {
        return $this->isAdmin($user, $group);
    }

    public function moderate(User $user, Group $group): bool
    {
        $role = $this->role($user, $group);

        return ($role?->canModerate() ?? false) || $group->isManagedBy($user) || $user->hasRole('admin');
    }

    public function join(User $user, Group $group): bool
    {
        if ($this->isMember($user, $group)) {
            return false;
        }

        return $this->eligibility->canJoinGroup($user, $group);
    }

    public function leave(User $user, Group $group): bool
    {
        $member = $this->membership($user, $group);
        if (! $member) {
            return false;
        }

        // Last admin cannot leave without transferring
        if ($member->role === GroupMemberRole::Admin) {
            $adminCount = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('role', GroupMemberRole::Admin)
                ->count();

            return $adminCount > 1;
        }

        return true;
    }

    public function featureOnParent(User $user, Group $group): bool
    {
        return $group->isManagedBy($user) || $user->hasRole('admin');
    }

    private function membership(User $user, Group $group): ?GroupMember
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function role(User $user, Group $group): ?GroupMemberRole
    {
        return $this->membership($user, $group)?->role;
    }

    private function isMember(User $user, Group $group): bool
    {
        return $this->membership($user, $group) !== null;
    }

    private function isAdmin(User $user, Group $group): bool
    {
        return $this->role($user, $group) === GroupMemberRole::Admin
            || (int) $group->creator_user_id === (int) $user->id;
    }
}
