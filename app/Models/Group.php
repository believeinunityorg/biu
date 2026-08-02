<?php

namespace App\Models;

use App\Enums\MembershipAccountType;
use App\Models\Concerns\HasMembershipAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Group extends Model
{
    use HasMembershipAccount;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_type',
        'parent_id',
        'creator_user_id',
        'memberships_enabled',
        'status',
    ];

    protected $casts = [
        'memberships_enabled' => 'boolean',
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
