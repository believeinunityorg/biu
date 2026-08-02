<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Enums\MembershipPaymentStatus;
use App\Enums\SupporterMembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupporterMembership extends Model
{
    protected $fillable = [
        'supporter_id',
        'membership_plan_id',
        'membership_invitation_id',
        'account_id',
        'account_type',
        'status',
        'payment_status',
        'payment_amount',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
        'requested_at',
        'approved_at',
    ];

    protected $casts = [
        'status' => SupporterMembershipStatus::class,
        'payment_status' => MembershipPaymentStatus::class,
        'payment_amount' => 'decimal:2',
        'account_type' => MembershipAccountType::class,
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supporter_id');
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function membershipInvitation(): BelongsTo
    {
        return $this->belongsTo(MembershipInvitation::class, 'membership_invitation_id');
    }

    public function account(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'account_type', 'account_id');
    }
}
