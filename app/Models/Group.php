<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Models\Concerns\HasMembershipAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Group extends Model
{
    use HasMembershipAccount;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_image',
        'category',
        'parent_type',
        'parent_id',
        'creator_user_id',
        'memberships_enabled',
        'status',
        'is_featured',
        'is_pinned',
        'is_hidden_on_parent',
    ];

    protected $casts = [
        'memberships_enabled' => 'boolean',
        'is_featured' => 'boolean',
        'is_pinned' => 'boolean',
        'is_hidden_on_parent' => 'boolean',
        'parent_type' => MembershipAccountType::class,
    ];

    public function parent(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'parent_type', 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GroupInvite::class);
    }

    public function communityContents(): MorphMany
    {
        return $this->morphMany(CommunityContent::class, 'parent');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }

    public static function forAuthUser(User $user): ?self
    {
        return static::query()
            ->where('creator_user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Resolve whether the user can manage this group (creator or parent account manager).
     */
    public function isManagedBy(User $user): bool
    {
        if ((int) $this->creator_user_id === (int) $user->id) {
            return true;
        }

        $parent = $this->parent;
        if ($parent instanceof Organization) {
            $org = Organization::forAuthUser($user);

            return $org !== null && (int) $org->id === (int) $parent->id;
        }

        if ($parent instanceof CareAlliance) {
            return (int) $parent->creator_user_id === (int) $user->id;
        }

        return false;
    }
}
