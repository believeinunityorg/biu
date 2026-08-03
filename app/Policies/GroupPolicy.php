<?php

namespace App\Policies;

use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\GroupInvite;
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
        $isActiveMember = $user && $this->isActiveMember($user, $group);
        if ($group->status !== 'active' && ! $isActiveMember) {
            return false;
        }

        if ($group->isHiddenVisibility()) {
            if (! $user) {
                return false;
            }

            if ($isActiveMember || $group->isManagedBy($user) || $user->hasRole('admin')) {
                return true;
            }

            return GroupInvite::query()
                ->where('group_id', $group->id)
                ->where('status', 'pending')
                ->where(function ($q) use ($user) {
                    $q->where('invitee_user_id', $user->id)
                        ->orWhere('invitee_email', $user->email);
                })
                ->exists();
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true; // parent-specific check in controller
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isAdmin($user, $group) || $group->isManagedBy($user);
    }

    public function delete(User $user, Group $group): bool
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
        if ($this->isActiveMember($user, $group) || $this->isPendingMember($user, $group)) {
            return false;
        }

        $policy = $group->join_policy ?? 'anyone';
        if ($policy === 'invite_only') {
            return false;
        }

        return $this->eligibility->canJoinGroup($user, $group);
    }

    public function leave(User $user, Group $group): bool
    {
        $anyMembership = $this->membership($user, $group);
        if ($anyMembership?->isPending()) {
            return true;
        }

        $member = $this->activeMembership($user, $group);
        if (! $member) {
            return false;
        }

        // Last admin cannot leave without transferring
        if ($member->role === GroupMemberRole::Admin) {
            $adminCount = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('membership_status', 'active')
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

    private function activeMembership(User $user, Group $group): ?GroupMember
    {
        $member = $this->membership($user, $group);

        return $member && $member->isActive() ? $member : null;
    }

    private function role(User $user, Group $group): ?GroupMemberRole
    {
        return $this->activeMembership($user, $group)?->role;
    }

    private function isActiveMember(User $user, Group $group): bool
    {
        return $this->activeMembership($user, $group) !== null;
    }

    private function isPendingMember(User $user, Group $group): bool
    {
        $member = $this->membership($user, $group);

        return $member?->isPending() ?? false;
    }

    private function isAdmin(User $user, Group $group): bool
    {
        return $this->role($user, $group) === GroupMemberRole::Admin
            || (int) $group->creator_user_id === (int) $user->id;
    }
}
