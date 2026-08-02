<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityContentVersion extends Model
{
    protected $fillable = [
        'community_content_id',
        'edited_by',
        'title',
        'body',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(CommunityContent::class, 'community_content_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
