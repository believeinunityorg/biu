<?php

namespace App\Models;

use App\Enums\GroupMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'role',
        'joined_at',
        'posting_suspended_until',
    ];

    protected $casts = [
        'role' => GroupMemberRole::class,
        'joined_at' => 'datetime',
        'posting_suspended_until' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPostingSuspended(): bool
    {
        return $this->posting_suspended_until !== null && $this->posting_suspended_until->isFuture();
    }
}
