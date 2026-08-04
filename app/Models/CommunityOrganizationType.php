<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityOrganizationType extends Model
{
    public const SLUG_FAMILY_REUNION = 'family_reunion';

    public const SLUG_OTHER = 'other';

    protected $fillable = [
        'slug',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'community_organization_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isFamilyReunion(): bool
    {
        return $this->slug === self::SLUG_FAMILY_REUNION;
    }

    public function isOther(): bool
    {
        return $this->slug === self::SLUG_OTHER;
    }

    public static function familyReunionId(): ?int
    {
        return static::query()->where('slug', self::SLUG_FAMILY_REUNION)->value('id');
    }

    public static function otherId(): ?int
    {
        return static::query()->where('slug', self::SLUG_OTHER)->value('id');
    }

    /**
     * @return list<array{id: int, slug: string, name: string, label: string}>
     */
    public static function forSelect(): array
    {
        return static::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'slug', 'name'])
            ->map(fn (self $type) => [
                'id' => $type->id,
                'slug' => $type->slug,
                'name' => $type->name,
                'label' => $type->name,
            ])
            ->values()
            ->all();
    }
}
