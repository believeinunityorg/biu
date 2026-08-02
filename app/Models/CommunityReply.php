<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CommunityReply extends Model
{
    protected $fillable = [
        'community_content_id',
        'parent_reply_id',
        'author_id',
        'body',
        'attachment_path',
        'attachment_name',
        'visibility_status',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(CommunityContent::class, 'community_content_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parentReply(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_reply_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_reply_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(CommunityReaction::class, 'reactable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }
}
