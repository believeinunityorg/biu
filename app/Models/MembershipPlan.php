<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Enums\MembershipJoinMethod;
use App\Enums\MembershipPlanStatus;
use App\Enums\MembershipType;
use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MembershipPlan extends Model
{
    protected $fillable = [
        'account_id',
        'account_type',
        'membership_name',
        'membership_type',
        'membership_fee',
        'billing_frequency',
        'join_method',
        'status',
    ];

    protected $casts = [
        'membership_fee' => 'decimal:2',
        'membership_type' => MembershipType::class,
        'join_method' => MembershipJoinMethod::class,
        'status' => MembershipPlanStatus::class,
        'account_type' => MembershipAccountType::class,
    ];

    public function account(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'account_type', 'account_id');
    }

    public function supporterMemberships(): HasMany
    {
        return $this->hasMany(SupporterMembership::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettingsPayload(): array
    {
        return [
            'id' => $this->id,
            'membership_name' => $this->membership_name,
            'membership_type' => $this->membership_type?->value,
            'membership_fee' => $this->membership_fee !== null ? (string) $this->membership_fee : null,
            'billing_frequency' => $this->billing_frequency,
            'join_method' => $this->join_method?->value,
            'status' => $this->status?->value,
        ];
    }
}
