<?php

namespace App\Models;

use App\Enums\FamilyBranchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyBranch extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'head_member_id',
        'admin_user_id',
        'admin_name',
        'admin_email',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'status' => FamilyBranchStatus::class,
        'sort_order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function headMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'head_member_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'branch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FamilyBranchStatus::Active->value);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
