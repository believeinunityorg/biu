<?php

namespace App\Models;

use App\Enums\FamilyMemberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class FamilyMember extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'branch_id',
        'full_name',
        'email',
        'photo',
        'birth_year',
        'death_year',
        'father_id',
        'mother_id',
        'spouse_id',
        'generation',
        'status',
        'is_founding_grandfather',
        'is_founding_grandmother',
        'merged_into_id',
        'claimed_at',
    ];

    protected $casts = [
        'status' => FamilyMemberStatus::class,
        'is_founding_grandfather' => 'boolean',
        'is_founding_grandmother' => 'boolean',
        'birth_year' => 'integer',
        'death_year' => 'integer',
        'generation' => 'integer',
        'claimed_at' => 'datetime',
    ];

    protected $appends = [
        'photo_url',
        'is_claimed',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(FamilyBranch::class, 'branch_id');
    }

    public function father(): BelongsTo
    {
        return $this->belongsTo(self::class, 'father_id');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mother_id');
    }

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'spouse_id');
    }

    public function childrenAsFather(): HasMany
    {
        return $this->hasMany(self::class, 'father_id');
    }

    public function childrenAsMother(): HasMany
    {
        return $this->hasMany(self::class, 'mother_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function relationshipAudits(): HasMany
    {
        return $this->hasMany(FamilyRelationshipAudit::class);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo) {
            return null;
        }

        return str_starts_with($this->photo, 'http')
            ? $this->photo
            : Storage::disk('public')->url($this->photo);
    }

    public function getIsClaimedAttribute(): bool
    {
        return $this->user_id !== null && $this->status !== FamilyMemberStatus::Unclaimed;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            FamilyMemberStatus::Merged->value,
            FamilyMemberStatus::Archived->value,
        ]);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
