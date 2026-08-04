<?php

namespace App\Models;

use App\Enums\OrganizationAnnouncementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrganizationAnnouncement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'title',
        'slug',
        'message',
        'category',
        'cover_image',
        'attachments',
        'published_at',
        'scheduled_at',
        'expires_at',
        'is_pinned',
        'allow_comments',
        'status',
        'created_by',
        'updated_by',
        'published_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_pinned' => 'boolean',
        'allow_comments' => 'boolean',
        'status' => OrganizationAnnouncementStatus::class,
    ];

    protected $appends = [
        'cover_image_url',
        'excerpt',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Include soft-deleted announcements so moderators can reach them (e.g. to restore) via slug.
     * Controllers are responsible for rejecting trashed records where restoration isn't the intent.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public static function uniqueSlugFromTitle(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'announcement';
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OrganizationAnnouncementComment::class, 'announcement_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrganizationAnnouncementHistory::class, 'announcement_id');
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_image);
    }

    public function getExcerptAttribute(): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->message)) ?? '');

        return Str::limit($plain, 160);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeVisibleToPublic(Builder $query): Builder
    {
        return $query
            ->where('status', OrganizationAnnouncementStatus::Published)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopePinnedFirst(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Promote due scheduled announcements to published.
     */
    public static function promoteScheduledForOrganization(int $organizationId): int
    {
        return static::query()
            ->forOrganization($organizationId)
            ->where('status', OrganizationAnnouncementStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->update([
                'status' => OrganizationAnnouncementStatus::Published->value,
                'published_at' => now(),
            ]);
    }
}
