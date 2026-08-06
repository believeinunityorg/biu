<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OrganizationDiscussion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'category_id',
        'title',
        'slug',
        'body',
        'attachments',
        'created_by',
        'updated_by',
        'is_pinned',
        'is_locked',
        'is_hidden',
        'is_archived',
        'posting_suspended',
        'replies_count',
        'views_count',
        'reactions_count',
        'last_activity_at',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'is_hidden' => 'boolean',
        'is_archived' => 'boolean',
        'posting_suspended' => 'boolean',
        'last_activity_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $appends = [
        'excerpt',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Include soft-deleted discussions so moderators can reach them (e.g. to restore) via slug.
     * Controllers are responsible for rejecting trashed records where restoration isn't the intent.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public static function uniqueSlugFromTitle(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'discussion';
        $slug = $base;
        $counter = 2;

        while (
            static::query()
                ->withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OrganizationDiscussionCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(OrganizationDiscussionReply::class, 'discussion_id');
    }

    public function follows(): HasMany
    {
        return $this->hasMany(OrganizationDiscussionFollow::class, 'discussion_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(OrganizationDiscussionView::class, 'discussion_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(OrganizationDiscussionReaction::class, 'reactable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(OrganizationDiscussionReport::class, 'reportable');
    }

    public function getExcerptAttribute(): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->body)) ?? '');

        return Str::limit($plain, 140);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeVisibleToPublic(Builder $query): Builder
    {
        return $query
            ->where('is_hidden', false)
            ->where('is_archived', false)
            ->whereNotNull('approved_at');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    public function isVisiblePublicly(): bool
    {
        return ! $this->is_hidden
            && ! $this->is_archived
            && ! $this->trashed()
            && $this->approved_at !== null;
    }
}
