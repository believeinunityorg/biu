<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationDiscussionReply extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'discussion_id',
        'parent_id',
        'user_id',
        'body',
        'attachments',
        'is_hidden',
        'reactions_count',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_hidden' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(OrganizationDiscussion::class, 'discussion_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(OrganizationDiscussionReaction::class, 'reactable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(OrganizationDiscussionReport::class, 'reportable');
    }
}
