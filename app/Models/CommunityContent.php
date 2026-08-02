<?php

namespace App\Models;

use App\Enums\CommunityContentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommunityContent extends Model
{
    protected $fillable = [
        'parent_type',
        'parent_id',
        'type',
        'title',
        'slug',
        'body',
        'cover_image',
        'attachment_path',
        'attachment_name',
        'author_id',
        'category',
        'is_pinned',
        'is_locked',
        'comments_enabled',
        'published_at',
        'scheduled_at',
        'expires_at',
        'archived_at',
        'visibility_status',
    ];

    protected $casts = [
        'type' => CommunityContentType::class,
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'comments_enabled' => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'expires_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (ctype_digit((string) $value)) {
            return $this->whereKey($value)->firstOrFail();
        }

        return $this->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public static function uniqueSlugFromTitle(string $title, ?int $ignoreId = null): string
    {
        $base = \Illuminate\Support\Str::slug($title);
        if ($base === '') {
            $base = 'post';
        }

        $slug = $base;
        $counter = 2;
        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
    public function parent(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommunityReply::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommunityContentVersion::class);
    }

    public function follows(): HasMany
    {
        return $this->hasMany(CommunityFollow::class);
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(CommunityReaction::class, 'reactable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->visibility_status !== 'visible') {
            return false;
        }
        if ($this->archived_at !== null) {
            return false;
        }
        if ($this->published_at === null || $this->published_at->isFuture()) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->scheduled_at !== null && $this->scheduled_at->isFuture()) {
            return false;
        }

        return true;
    }

    public function scopeVisiblePublic($query)
    {
        return $query
            ->where('visibility_status', 'visible')
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
