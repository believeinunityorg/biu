<?php

namespace App\Services;

use App\Enums\MembershipAccountType;
use App\Enums\MembershipPlanStatus;
use App\Enums\MembershipType;
use App\Enums\SupporterMembershipStatus;
use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\SupporterMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MembershipAccountService
{
    /**
     * @return array{account: Model, account_type: MembershipAccountType}
     */
    public function resolveForUser(User $user, ?string $accountType = null, ?int $accountId = null): array
    {
        if ($accountType !== null && $accountId !== null) {
            $type = MembershipAccountType::from($accountType);
            $account = $type->modelClass()::query()->findOrFail($accountId);
            $this->authorizeManagement($user, $account);

            return ['account' => $account, 'account_type' => $type];
        }

        if ($user->hasRole('care_alliance')) {
            $alliance = CareAlliance::query()->where('creator_user_id', $user->id)->first();
            if ($alliance) {
                return ['account' => $alliance, 'account_type' => MembershipAccountType::UnityImpactAlliance];
            }
        }

        $organization = Organization::forAuthUser($user);
        if ($organization) {
            return ['account' => $organization, 'account_type' => MembershipAccountType::Organization];
        }

        throw new InvalidArgumentException('No membership account found for this user.');
    }

    public function authorizeManagement(User $user, Model $account): void
    {
        if (! $this->canManage($user, $account)) {
            abort(403);
        }
    }

    public function canManage(User $user, Model $account): bool
    {
        if ($account instanceof Organization) {
            $org = Organization::forAuthUser($user);

            return $org !== null && (int) $org->id === (int) $account->id;
        }

        if ($account instanceof CareAlliance) {
            return (int) $account->creator_user_id === (int) $user->id;
        }

        if ($account instanceof Group) {
            return $account->isManagedBy($user);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateSettings(Model $account, array $validated): MembershipPlan
    {
        $accountType = MembershipAccountType::fromModel($account);

        return DB::transaction(function () use ($account, $accountType, $validated) {
            $account->memberships_enabled = (bool) ($validated['memberships_enabled'] ?? false);
            $account->save();

            if (! $account->memberships_enabled) {
                return $account->membershipPlan()->first() ?? new MembershipPlan([
                    'account_id' => $account->id,
                    'account_type' => $accountType->value,
                ]);
            }

            $plan = MembershipPlan::query()->firstOrNew([
                'account_type' => $accountType->value,
                'account_id' => $account->id,
            ]);

            $plan->fill([
                'membership_name' => $validated['membership_name'],
                'membership_type' => $validated['membership_type'],
                'membership_fee' => ($validated['membership_type'] ?? null) === MembershipType::Paid->value
                    ? $validated['membership_fee']
                    : null,
                'billing_frequency' => $validated['billing_frequency'] ?? null,
                'join_method' => $validated['join_method'],
                'status' => MembershipPlanStatus::Active->value,
            ]);
            $plan->save();

            return $plan;
        });
    }

    public function createSupporterRequest(User $supporter, Model $account): SupporterMembership
    {
        if (! $supporter->canFollowOrganizations()) {
            abort(403, 'Only supporter accounts can request membership.');
        }

        if (! (bool) $account->memberships_enabled) {
            abort(422, 'Memberships are not enabled for this account.');
        }

        $plan = $account->membershipPlan;
        if ($plan === null || $plan->status !== MembershipPlanStatus::Active) {
            abort(422, 'No active membership plan is configured.');
        }

        $accountType = MembershipAccountType::fromModel($account);

        $existing = SupporterMembership::query()
            ->where('supporter_id', $supporter->id)
            ->where('account_type', $accountType->value)
            ->where('account_id', $account->id)
            ->whereIn('status', [
                SupporterMembershipStatus::Pending->value,
                SupporterMembershipStatus::Verified->value,
            ])
            ->first();

        if ($existing) {
            abort(422, 'You already have an active or pending membership with this account.');
        }

        return SupporterMembership::query()->create([
            'supporter_id' => $supporter->id,
            'membership_plan_id' => $plan->id,
            'account_id' => $account->id,
            'account_type' => $accountType->value,
            'status' => SupporterMembershipStatus::Pending->value,
            'requested_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(Model $account): array
    {
        $plan = $account->membershipPlan;

        return [
            'memberships_enabled' => (bool) $account->memberships_enabled,
            'account_type' => MembershipAccountType::fromModel($account)->value,
            'account_id' => $account->id,
            'plan' => $plan?->toSettingsPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function managementPayload(Model $account): array
    {
        $accountType = MembershipAccountType::fromModel($account);
        $pending = $account->memberships()
            ->where('status', SupporterMembershipStatus::Pending->value)
            ->count();
        $verified = $account->memberships()
            ->where('status', SupporterMembershipStatus::Verified->value)
            ->count();

        $accountName = match (true) {
            $account instanceof Organization => $account->name,
            $account instanceof CareAlliance => $account->name,
            $account instanceof Group => $account->name,
            default => 'Account',
        };

        return [
            'account' => [
                'id' => $account->id,
                'name' => $accountName,
                'type' => $accountType->value,
                'memberships_enabled' => (bool) $account->memberships_enabled,
            ],
            'counts' => [
                'pending' => $pending,
                'verified' => $verified,
            ],
            'plan' => $account->membershipPlan?->toSettingsPayload(),
        ];
    }
}
