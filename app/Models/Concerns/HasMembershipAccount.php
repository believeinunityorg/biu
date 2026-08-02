<?php

namespace App\Models\Concerns;

use App\Enums\MembershipAccountType;
use App\Models\MembershipPlan;
use App\Models\SupporterMembership;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasMembershipAccount
{
    public function membershipPlan(): HasOne
    {
        $accountType = MembershipAccountType::fromModel($this);

        return $this->hasOne(MembershipPlan::class, 'account_id')
            ->where('account_type', $accountType->value);
    }

    public function membershipInvitations(): HasMany
    {
        $accountType = MembershipAccountType::fromModel($this);

        return $this->hasMany(MembershipInvitation::class, 'account_id')
            ->where('account_type', $accountType->value);
    }

    public function memberships(): HasMany
    {
        $accountType = MembershipAccountType::fromModel($this);

        return $this->hasMany(SupporterMembership::class, 'account_id')
            ->where('account_type', $accountType->value);
    }

    public function membershipAccountType(): MembershipAccountType
    {
        return MembershipAccountType::fromModel($this);
    }
}
