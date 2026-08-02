<?php

namespace App\Services;

use App\Enums\MembershipAccountType;
use App\Enums\SupporterMembershipStatus;
use App\Models\CareAlliance;
use App\Models\CareAllianceMembership;
use App\Models\Group;
use App\Models\Organization;
use App\Models\SupporterMembership;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use Illuminate\Database\Eloquent\Model;

class GroupEligibilityService
{
    /**
     * Follower or member of Organization / Alliance may create a group under that parent.
     */
    public function canCreateGroupUnder(User $user, Model $parent): bool
    {
        if ($parent instanceof Organization) {
            return $this->isOrgFollowerOrMember($user, $parent);
        }

        if ($parent instanceof CareAlliance) {
            return $this->isAllianceFollowerOrMember($user, $parent);
        }

        return false;
    }

    public function isOrgFollowerOrMember(User $user, Organization $organization): bool
    {
        if (Organization::forAuthUser($user)?->id === $organization->id) {
            return true;
        }

        $isFollower = UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->exists();

        if ($isFollower) {
            return true;
        }

        return $this->hasActiveSupporterMembership($user, MembershipAccountType::Organization, $organization->id);
    }

    public function isAllianceFollowerOrMember(User $user, CareAlliance $alliance): bool
    {
        if ((int) $alliance->creator_user_id === (int) $user->id) {
            return true;
        }

        $isFollower = UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->where('care_alliance_id', $alliance->id)
            ->exists();

        if ($isFollower) {
            return true;
        }

        $userOrg = Organization::forAuthUser($user);
        if ($userOrg) {
            $orgInAlliance = CareAllianceMembership::query()
                ->where('care_alliance_id', $alliance->id)
                ->where('organization_id', $userOrg->id)
                ->where('status', 'active')
                ->exists();

            if ($orgInAlliance) {
                return true;
            }
        }

        return $this->hasActiveSupporterMembership($user, MembershipAccountType::UnityImpactAlliance, $alliance->id);
    }

    public function canJoinGroup(User $user, Group $group): bool
    {
        $parent = $group->parent;
        if ($parent instanceof Organization || $parent instanceof CareAlliance) {
            return $this->canCreateGroupUnder($user, $parent);
        }

        // Open join for active groups when parent relation is missing (edge case)
        return $group->status === 'active';
    }

    private function hasActiveSupporterMembership(User $user, MembershipAccountType $type, int $accountId): bool
    {
        return SupporterMembership::query()
            ->where('supporter_id', $user->id)
            ->where('account_type', $type->value)
            ->where('account_id', $accountId)
            ->where('status', SupporterMembershipStatus::Verified)
            ->exists();
    }
}
