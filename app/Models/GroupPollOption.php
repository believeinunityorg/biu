<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupPollOption extends Model
{
    protected $fillable = [
        'group_poll_id',
        'label',
        'sort_order',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(GroupPoll::class, 'group_poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(GroupPollVote::class);
    }
}
