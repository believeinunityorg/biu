<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FamilyFounder extends Model
{
    protected $fillable = [
        'organization_id',
        'grandfather_name',
        'grandmother_name',
        'grandfather_photo',
        'grandmother_photo',
        'grandfather_birth_year',
        'grandfather_death_year',
        'grandmother_birth_year',
        'grandmother_death_year',
        'grandfather_member_id',
        'grandmother_member_id',
    ];

    protected $casts = [
        'grandfather_birth_year' => 'integer',
        'grandfather_death_year' => 'integer',
        'grandmother_birth_year' => 'integer',
        'grandmother_death_year' => 'integer',
    ];

    protected $appends = [
        'grandfather_photo_url',
        'grandmother_photo_url',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grandfatherMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'grandfather_member_id');
    }

    public function grandmotherMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'grandmother_member_id');
    }

    public function getGrandfatherPhotoUrlAttribute(): ?string
    {
        return $this->photoUrl($this->grandfather_photo);
    }

    public function getGrandmotherPhotoUrlAttribute(): ?string
    {
        return $this->photoUrl($this->grandmother_photo);
    }

    private function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
