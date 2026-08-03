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
        'icon_image',
        'category',
        'visibility',
        'join_policy',
        'posting_policy',
        'rules',
        'allow_photos',
        'allow_videos',
        'allow_documents',
        'allow_polls',
        'allow_events',
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
        'allow_photos' => 'boolean',
        'allow_videos' => 'boolean',
        'allow_documents' => 'boolean',
        'allow_polls' => 'boolean',
        'allow_events' => 'boolean',
        'rules' => 'array',
        'parent_type' => MembershipAccountType::class,
    ];

    public function activeMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class)->where('membership_status', 'active');
    }

    public function isPublicVisibility(): bool
    {
        return ($this->visibility ?? 'public') === 'public';
    }

    public function isPrivateVisibility(): bool
    {
        return ($this->visibility ?? 'public') === 'private';
    }

    public function isHiddenVisibility(): bool
    {
        return ($this->visibility ?? 'public') === 'hidden';
    }

    /**
     * @return list<string>
     */
    public function rulesList(): array
    {
        $rules = $this->rules;

        if (! is_array($rules)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($rule) => is_string($rule) ? trim($rule) : '',
            $rules
        )));
    }

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

    public function polls(): HasMany
    {
        return $this->hasMany(GroupPoll::class);
    }

    public function libraryItems(): HasMany
    {
        return $this->hasMany(GroupLibraryItem::class);
    }

    public function groupEvents(): HasMany
    {
        return $this->hasMany(GroupEvent::class);
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
