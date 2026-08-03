<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPollVote extends Model
{
    protected $fillable = [
        'group_poll_id',
        'group_poll_option_id',
        'user_id',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(GroupPoll::class, 'group_poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(GroupPollOption::class, 'group_poll_option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
