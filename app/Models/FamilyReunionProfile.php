<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyReunionProfile extends Model
{
    protected $fillable = [
        'organization_id',
        'family_name',
        'family_motto',
        'family_history',
        'reunion_established',
        'reunion_frequency',
        'reunion_location',
        'tree_visibility',
        'allow_members_add_children',
        'allow_members_invite_relatives',
        'require_member_approval',
        'allow_branch_administrators',
    ];

    protected $casts = [
        'reunion_established' => 'date',
        'allow_members_add_children' => 'boolean',
        'allow_members_invite_relatives' => 'boolean',
        'require_member_approval' => 'boolean',
        'allow_branch_administrators' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
