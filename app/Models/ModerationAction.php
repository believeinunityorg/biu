<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModerationAction extends Model
{
    protected $fillable = [
        'target_type',
        'target_id',
        'actor_id',
        'action',
        'reason',
        'original_snapshot',
        'status_before',
        'status_after',
    ];

    protected $casts = [
        'original_snapshot' => 'array',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
