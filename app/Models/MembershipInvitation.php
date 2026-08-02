<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Enums\MembershipInvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MembershipInvitation extends Model
{
    protected $fillable = [
        'membership_plan_id',
        'account_id',
        'account_type',
        'email',
        'invited_by_user_id',
        'supporter_id',
        'token',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'account_type' => MembershipAccountType::class,
        'status' => MembershipInvitationStatus::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supporter_id');
    }

    public function account(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'account_type', 'account_id');
    }

    public function supporterMembership(): HasOne
    {
        return $this->hasOne(SupporterMembership::class, 'membership_invitation_id');
    }
}
