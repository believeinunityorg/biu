<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupPoll extends Model
{
    protected $fillable = [
        'group_id',
        'creator_user_id',
        'question',
        'allow_multiple',
        'closes_at',
        'closed_at',
    ];

    protected $casts = [
        'allow_multiple' => 'boolean',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(GroupPollOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(GroupPollVote::class);
    }

    public function isClosed(): bool
    {
        if ($this->closed_at !== null) {
            return true;
        }

        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }
}
