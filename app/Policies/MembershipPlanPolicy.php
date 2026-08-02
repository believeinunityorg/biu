<?php

namespace App\Policies;

use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipAccountService;

class MembershipPlanPolicy
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function update(User $user, MembershipPlan $plan): bool
    {
        $account = $plan->account;

        return $account !== null && $this->membershipAccountService->canManage($user, $account);
    }
}
