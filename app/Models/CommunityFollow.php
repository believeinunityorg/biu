<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityFollow extends Model
{
    protected $fillable = [
        'community_content_id',
        'user_id',
        'is_muted',
    ];

    protected $casts = [
        'is_muted' => 'boolean',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(CommunityContent::class, 'community_content_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
