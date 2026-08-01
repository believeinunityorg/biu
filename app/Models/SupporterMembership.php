<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Enums\SupporterMembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupporterMembership extends Model
{
    protected $fillable = [
        'supporter_id',
        'membership_plan_id',
        'account_id',
        'account_type',
        'status',
        'requested_at',
        'approved_at',
    ];

    protected $casts = [
        'status' => SupporterMembershipStatus::class,
        'account_type' => MembershipAccountType::class,
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supporter_id');
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function account(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'account_type', 'account_id');
    }
}
