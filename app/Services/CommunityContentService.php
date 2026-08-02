<?php

namespace App\Services;

use App\Enums\CommunityContentType;
use App\Enums\GroupMemberRole;
use App\Enums\MembershipAccountType;
use App\Models\CareAlliance;
use App\Models\CommunityContent;
use App\Models\CommunityContentVersion;
use App\Models\CommunityReply;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CommunityContentService
{
    public function __construct(
        private readonly GroupEligibilityService $eligibility,
    ) {}

    public function canCreateAnnouncement(User $user, Model $parent): bool
    {
        if ($parent instanceof Group) {
            return $this->groupRole($user, $parent)?->canModerate() ?? false;
        }

        if ($parent instanceof Organization) {
            $org = Organization::forAuthUser($user);

            return $org !== null && (int) $org->id === (int) $parent->id;
        }

        if ($parent instanceof CareAlliance) {
            return (int) $parent->creator_user_id === (int) $user->id;
        }

        return false;
    }

    public function canCreateDiscussion(User $user, Model $parent): bool
    {
        if ($parent instanceof Group) {
            $member = $this->membership($user, $parent);
            if (! $member) {
                return false;
            }
            if ($member->isPostingSuspended()) {
                return false;
            }

            return true;
        }

        if ($parent instanceof Organization) {
            return $this->eligibility->isOrgFollowerOrMember($user, $parent);
        }

        if ($parent instanceof CareAlliance) {
            return $this->eligibility->isAllianceFollowerOrMember($user, $parent);
        }

        return false;
    }

    public function canModerate(User $user, Model $parent): bool
    {
        return $this->canCreateAnnouncement($user, $parent)
            || ($user->hasRole('admin'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $author, Model $parent, CommunityContentType $type, array $data): CommunityContent
    {
        if ($type === CommunityContentType::Announcement && ! $this->canCreateAnnouncement($author, $parent)) {
            throw ValidationException::withMessages(['type' => 'You are not allowed to create announcements here.']);
        }
        if ($type === CommunityContentType::Discussion && ! $this->canCreateDiscussion($author, $parent)) {
            throw ValidationException::withMessages(['type' => 'You are not allowed to create discussions here.']);
        }

        $publishNow = ! empty($data['publish_now']) || empty($data['scheduled_at']);
        $publishedAt = $publishNow ? now() : null;
        $scheduledAt = ! empty($data['scheduled_at']) ? $data['scheduled_at'] : null;

        if (! $publishNow && $scheduledAt) {
            $publishedAt = null;
        }
        if ($publishNow) {
            $scheduledAt = null;
            $publishedAt = now();
        }

        $coverPath = null;
        if (($data['cover_image'] ?? null) instanceof UploadedFile) {
            $coverPath = $data['cover_image']->store('community/covers', 'public');
        }

        $attachmentPath = null;
        $attachmentName = null;
        if (($data['attachment'] ?? null) instanceof UploadedFile) {
            $attachmentPath = $data['attachment']->store('community/attachments', 'public');
            $attachmentName = $data['attachment']->getClientOriginalName();
        }

        return CommunityContent::create([
            'parent_type' => $this->parentMorphType($parent),
            'parent_id' => $parent->getKey(),
            'type' => $type,
            'title' => $data['title'],
            'body' => $data['body'],
            'cover_image' => $coverPath,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'author_id' => $author->id,
            'category' => $data['category'] ?? null,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            'is_locked' => false,
            'comments_enabled' => array_key_exists('comments_enabled', $data) ? (bool) $data['comments_enabled'] : true,
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'expires_at' => $data['expires_at'] ?? null,
            'visibility_status' => 'visible',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommunityContent $content, User $editor, array $data): CommunityContent
    {
        CommunityContentVersion::create([
            'community_content_id' => $content->id,
            'edited_by' => $editor->id,
            'title' => $content->title,
            'body' => $content->body,
            'meta' => [
                'comments_enabled' => $content->comments_enabled,
                'is_pinned' => $content->is_pinned,
                'category' => $content->category,
            ],
        ]);

        $updates = [
            'title' => $data['title'] ?? $content->title,
            'body' => $data['body'] ?? $content->body,
            'category' => $data['category'] ?? $content->category,
        ];

        if (array_key_exists('comments_enabled', $data)) {
            $updates['comments_enabled'] = (bool) $data['comments_enabled'];
        }
        if (array_key_exists('is_pinned', $data)) {
            $updates['is_pinned'] = (bool) $data['is_pinned'];
        }
        if (array_key_exists('expires_at', $data)) {
            $updates['expires_at'] = $data['expires_at'];
        }
        if (! empty($data['archive'])) {
            $updates['archived_at'] = now();
        }

        if (($data['cover_image'] ?? null) instanceof UploadedFile) {
            $updates['cover_image'] = $data['cover_image']->store('community/covers', 'public');
        }
        if (($data['attachment'] ?? null) instanceof UploadedFile) {
            $updates['attachment_path'] = $data['attachment']->store('community/attachments', 'public');
            $updates['attachment_name'] = $data['attachment']->getClientOriginalName();
        }

        $content->update($updates);

        return $content->fresh();
    }

    public function addReply(CommunityContent $content, User $author, string $body, ?UploadedFile $attachment = null, ?int $parentReplyId = null): CommunityReply
    {
        if ($content->is_locked || ! $content->comments_enabled) {
            throw ValidationException::withMessages(['body' => 'This discussion is locked or comments are disabled.']);
        }

        if ($content->visibility_status !== 'visible') {
            throw ValidationException::withMessages(['body' => 'This content is not available for replies.']);
        }

        $parent = $content->parent;
        if ($parent instanceof Group) {
            $member = $this->membership($author, $parent);
            if (! $member) {
                throw ValidationException::withMessages(['body' => 'Join the group to reply.']);
            }
            if ($member->isPostingSuspended()) {
                throw ValidationException::withMessages(['body' => 'Your posting privileges are suspended.']);
            }
        }

        $attachmentPath = null;
        $attachmentName = null;
        if ($attachment) {
            $attachmentPath = $attachment->store('community/attachments', 'public');
            $attachmentName = $attachment->getClientOriginalName();
        }

        return CommunityReply::create([
            'community_content_id' => $content->id,
            'parent_reply_id' => $parentReplyId,
            'author_id' => $author->id,
            'body' => $body,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'visibility_status' => 'visible',
        ]);
    }

    public function parentMorphType(Model $parent): string
    {
        if ($parent instanceof Organization) {
            return MembershipAccountType::Organization->value;
        }
        if ($parent instanceof CareAlliance) {
            return MembershipAccountType::UnityImpactAlliance->value;
        }
        if ($parent instanceof Group) {
            return MembershipAccountType::Group->value;
        }

        return $parent->getMorphClass();
    }

    public function resolveParent(string $type, int $id): ?Model
    {
        return match ($type) {
            MembershipAccountType::Organization->value, 'Organization' => Organization::find($id),
            MembershipAccountType::UnityImpactAlliance->value, 'UnityImpactAlliance', 'CareAlliance' => CareAlliance::find($id),
            MembershipAccountType::Group->value, 'Group' => Group::find($id),
            default => null,
        };
    }

    private function membership(User $user, Group $group): ?GroupMember
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function groupRole(User $user, Group $group): ?GroupMemberRole
    {
        return $this->membership($user, $group)?->role;
    }
}
