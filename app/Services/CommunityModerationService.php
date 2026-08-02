<?php

namespace App\Services;

use App\Models\CommunityContent;
use App\Models\CommunityReply;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CommunityModerationService
{
    public function record(
        Model $target,
        User $actor,
        string $action,
        ?string $reason = null,
        ?string $statusBefore = null,
        ?string $statusAfter = null,
        ?array $originalSnapshot = null,
    ): ModerationAction {
        return ModerationAction::create([
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'actor_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
            'original_snapshot' => $originalSnapshot ?? $this->snapshot($target),
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
        ]);
    }

    public function hideContent(CommunityContent $content, User $actor, ?string $reason = null): CommunityContent
    {
        $before = $content->visibility_status;
        $content->update(['visibility_status' => 'hidden']);
        $this->record($content, $actor, 'hide', $reason, $before, 'hidden');

        return $content->fresh();
    }

    public function removeContentFromView(CommunityContent $content, User $actor, ?string $reason = null): CommunityContent
    {
        $before = $content->visibility_status;
        $content->update(['visibility_status' => 'removed']);
        $this->record($content, $actor, 'remove_from_view', $reason, $before, 'removed');

        return $content->fresh();
    }

    public function restoreContent(CommunityContent $content, User $actor, ?string $reason = null): CommunityContent
    {
        $before = $content->visibility_status;
        $content->update(['visibility_status' => 'visible']);
        $this->record($content, $actor, 'restore', $reason, $before, 'visible');

        return $content->fresh();
    }

    public function lockContent(CommunityContent $content, User $actor, ?string $reason = null): CommunityContent
    {
        $before = $content->is_locked ? 'locked' : 'unlocked';
        $content->update(['is_locked' => true]);
        $this->record($content, $actor, 'lock', $reason, $before, 'locked');

        return $content->fresh();
    }

    public function unlockContent(CommunityContent $content, User $actor, ?string $reason = null): CommunityContent
    {
        $before = $content->is_locked ? 'locked' : 'unlocked';
        $content->update(['is_locked' => false]);
        $this->record($content, $actor, 'unlock', $reason, $before, 'unlocked');

        return $content->fresh();
    }

    public function hideReply(CommunityReply $reply, User $actor, ?string $reason = null): CommunityReply
    {
        $before = $reply->visibility_status;
        $reply->update(['visibility_status' => 'hidden']);
        $this->record($reply, $actor, 'hide', $reason, $before, 'hidden');

        return $reply->fresh();
    }

    public function restoreReply(CommunityReply $reply, User $actor, ?string $reason = null): CommunityReply
    {
        $before = $reply->visibility_status;
        $reply->update(['visibility_status' => 'visible']);
        $this->record($reply, $actor, 'restore', $reason, $before, 'visible');

        return $reply->fresh();
    }

    public function suspendMemberPosting(GroupMember $member, User $actor, ?\DateTimeInterface $until = null, ?string $reason = null): GroupMember
    {
        $until = $until ?? now()->addDays(7);
        $before = $member->posting_suspended_until?->toIso8601String();
        $member->update(['posting_suspended_until' => $until]);
        $this->record($member, $actor, 'suspend_posting', $reason, $before, $until->format(\DateTimeInterface::ATOM));

        return $member->fresh();
    }

    public function restoreMemberPosting(GroupMember $member, User $actor, ?string $reason = null): GroupMember
    {
        $before = $member->posting_suspended_until?->toIso8601String();
        $member->update(['posting_suspended_until' => null]);
        $this->record($member, $actor, 'restore_posting', $reason, $before, 'active');

        return $member->fresh();
    }

    public function hideGroupOnParent(Group $group, User $actor, ?string $reason = null): Group
    {
        $before = $group->is_hidden_on_parent ? 'hidden' : 'visible';
        $group->update(['is_hidden_on_parent' => true]);
        $this->record($group, $actor, 'hide_on_parent', $reason, $before, 'hidden');

        return $group->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $target): array
    {
        if ($target instanceof CommunityContent) {
            return [
                'title' => $target->title,
                'body' => $target->body,
                'type' => $target->type?->value ?? $target->type,
                'visibility_status' => $target->visibility_status,
                'is_locked' => $target->is_locked,
            ];
        }

        if ($target instanceof CommunityReply) {
            return [
                'body' => $target->body,
                'visibility_status' => $target->visibility_status,
            ];
        }

        if ($target instanceof Group) {
            return [
                'name' => $target->name,
                'slug' => $target->slug,
                'is_hidden_on_parent' => $target->is_hidden_on_parent,
                'status' => $target->status,
            ];
        }

        return $target->toArray();
    }
}
