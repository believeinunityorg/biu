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

    /**
     * Parents the user can create a community group under (orgs/alliances they follow or belong to).
     *
     * @return list<array{type: string, id: int, name: string, label: string}>
     */
    public function creatableParentsFor(User $user): array
    {
        $items = [];

        $ownOrg = Organization::forAuthUser($user);
        if ($ownOrg) {
            $items[$this->parentKey(MembershipAccountType::Organization->value, $ownOrg->id)] = [
                'type' => MembershipAccountType::Organization->value,
                'id' => (int) $ownOrg->id,
                'name' => (string) $ownOrg->name,
                'label' => 'Organization',
            ];
        }

        $favoriteOrgIds = UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $membershipOrgIds = SupporterMembership::query()
            ->where('supporter_id', $user->id)
            ->where('account_type', MembershipAccountType::Organization->value)
            ->where('status', SupporterMembershipStatus::Verified)
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orgIds = array_values(array_unique(array_filter([...$favoriteOrgIds, ...$membershipOrgIds])));
        if ($orgIds !== []) {
            Organization::query()
                ->whereIn('id', $orgIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->each(function (Organization $organization) use (&$items) {
                    $items[$this->parentKey(MembershipAccountType::Organization->value, $organization->id)] = [
                        'type' => MembershipAccountType::Organization->value,
                        'id' => (int) $organization->id,
                        'name' => (string) $organization->name,
                        'label' => 'Organization',
                    ];
                });
        }

        CareAlliance::query()
            ->where('creator_user_id', $user->id)
            ->where('status', 'active')
            ->get(['id', 'name'])
            ->each(function (CareAlliance $alliance) use (&$items) {
                $items[$this->parentKey(MembershipAccountType::UnityImpactAlliance->value, $alliance->id)] = [
                    'type' => MembershipAccountType::UnityImpactAlliance->value,
                    'id' => (int) $alliance->id,
                    'name' => (string) $alliance->name,
                    'label' => 'Unity Impact Alliance',
                ];
            });

        $favoriteAllianceIds = UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->whereNotNull('care_alliance_id')
            ->pluck('care_alliance_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $membershipAllianceIds = SupporterMembership::query()
            ->where('supporter_id', $user->id)
            ->where('account_type', MembershipAccountType::UnityImpactAlliance->value)
            ->where('status', SupporterMembershipStatus::Verified)
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allianceIdsViaOrg = [];
        if ($ownOrg) {
            $allianceIdsViaOrg = CareAllianceMembership::query()
                ->where('organization_id', $ownOrg->id)
                ->where('status', 'active')
                ->pluck('care_alliance_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $allianceIds = array_values(array_unique(array_filter([
            ...$favoriteAllianceIds,
            ...$membershipAllianceIds,
            ...$allianceIdsViaOrg,
        ])));

        if ($allianceIds !== []) {
            CareAlliance::query()
                ->whereIn('id', $allianceIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->each(function (CareAlliance $alliance) use (&$items) {
                    $items[$this->parentKey(MembershipAccountType::UnityImpactAlliance->value, $alliance->id)] = [
                        'type' => MembershipAccountType::UnityImpactAlliance->value,
                        'id' => (int) $alliance->id,
                        'name' => (string) $alliance->name,
                        'label' => 'Unity Impact Alliance',
                    ];
                });
        }

        usort($items, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return array_values($items);
    }

    private function parentKey(string $type, int $id): string
    {
        return $type.':'.$id;
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

    public function isParentFollower(User $user, Model $parent): bool
    {
        if ($parent instanceof Organization) {
            return UserFavoriteOrganization::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $parent->id)
                ->exists();
        }

        if ($parent instanceof CareAlliance) {
            return UserFavoriteOrganization::query()
                ->where('user_id', $user->id)
                ->where('care_alliance_id', $parent->id)
                ->exists();
        }

        return false;
    }

    public function isParentMember(User $user, Model $parent): bool
    {
        if ($parent instanceof Organization) {
            if (Organization::forAuthUser($user)?->id === $parent->id) {
                return true;
            }

            return $this->hasActiveSupporterMembership($user, MembershipAccountType::Organization, $parent->id);
        }

        if ($parent instanceof CareAlliance) {
            if ((int) $parent->creator_user_id === (int) $user->id) {
                return true;
            }

            $userOrg = Organization::forAuthUser($user);
            if ($userOrg) {
                $orgInAlliance = CareAllianceMembership::query()
                    ->where('care_alliance_id', $parent->id)
                    ->where('organization_id', $userOrg->id)
                    ->where('status', 'active')
                    ->exists();
                if ($orgInAlliance) {
                    return true;
                }
            }

            return $this->hasActiveSupporterMembership($user, MembershipAccountType::UnityImpactAlliance, $parent->id);
        }

        return false;
    }

    /**
     * Whether the user meets this group's join_policy eligibility (not invite_only gate).
     */
    public function canJoinGroup(User $user, Group $group): bool
    {
        if ($group->status !== 'active') {
            return false;
        }

        $policy = $group->join_policy ?? 'anyone';
        if ($policy === 'invite_only') {
            return false;
        }

        $parent = $group->relationLoaded('parent') ? $group->parent : $group->parent()->first();
        if (! ($parent instanceof Organization || $parent instanceof CareAlliance)) {
            // anyone / approval with no parent host
            return in_array($policy, ['anyone', 'approval'], true);
        }

        $isFollower = $this->isParentFollower($user, $parent);
        $isMember = $this->isParentMember($user, $parent);

        return match ($policy) {
            'anyone', 'approval' => true,
            'followers' => $isFollower,
            'members' => $isMember,
            'followers_and_members' => $isFollower || $isMember,
            default => false,
        };
    }

    /**
     * Human-readable reason the Join button is blocked (null when not blocked by eligibility).
     */
    public function joinEligibilityBlockedReason(User $user, Group $group): ?string
    {
        $policy = $group->join_policy ?? 'anyone';
        $parent = $group->relationLoaded('parent') ? $group->parent : $group->parent()->first();
        $parentName = $parent?->name ?? 'the host';
        $hostWord = $parent instanceof CareAlliance ? 'Unity Impact Alliance' : 'organization';

        return match ($policy) {
            'invite_only' => 'This group is invitation only. Ask a group admin to invite you.',
            'followers' => $this->canJoinGroup($user, $group)
                ? null
                : "Only people who follow {$parentName} can join this group.",
            'members' => $this->canJoinGroup($user, $group)
                ? null
                : "Only {$hostWord} members of {$parentName} can join this group.",
            'followers_and_members' => $this->canJoinGroup($user, $group)
                ? null
                : "Only followers or members of {$parentName} can join this group.",
            default => null,
        };
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
