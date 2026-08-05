<?php

namespace App\Services\CommunicationHub;

use App\Jobs\SendDiscussionNotification;
use App\Models\Organization;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\OrganizationDiscussionFollow;
use App\Models\OrganizationDiscussionReaction;
use App\Models\OrganizationDiscussionReply;
use App\Models\OrganizationDiscussionReport;
use App\Models\OrganizationDiscussionView;
use App\Models\OrganizationModerationLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrganizationDiscussionService
{
    private const ATTACHMENT_DISK_PATH = 'communication-hub/discussions/attachments';

    private const REACTABLE_TYPES = [OrganizationDiscussion::class, OrganizationDiscussionReply::class];

    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Seed the default discussion categories for an organization the first time it's needed.
     */
    public function ensureDefaultCategories(Organization $organization): void
    {
        $alreadySeeded = OrganizationDiscussionCategory::query()
            ->where('organization_id', $organization->id)
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $names = (array) config('communication_hub.default_discussion_categories', []);

        foreach ($names as $index => $name) {
            OrganizationDiscussionCategory::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => OrganizationDiscussionCategory::uniqueSlugForOrganization($organization->id, $name),
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $author, Organization $organization, array $data): OrganizationDiscussion
    {
        if (! $this->permissions->canCreateDiscussion($author, $organization)) {
            throw ValidationException::withMessages([
                'organization' => 'You are not allowed to start discussions in this organization.',
            ]);
        }

        $title = (string) $data['title'];
        $requiresApproval = (bool) ($this->permissions->getSettings($organization)['require_approval'] ?? false);

        $discussion = OrganizationDiscussion::create([
            'organization_id' => $organization->id,
            'category_id' => $this->resolveCategoryId($organization, $data['category_id'] ?? null),
            'title' => $title,
            'slug' => OrganizationDiscussion::uniqueSlugFromTitle($title),
            'body' => $data['body'] ?? '',
            'attachments' => $this->storeAttachmentFiles($data['attachments'] ?? []),
            'created_by' => $author->id,
            'is_pinned' => false,
            'last_activity_at' => now(),
            'approved_at' => $requiresApproval ? null : now(),
            'approved_by' => $requiresApproval ? null : $author->id,
        ]);

        $this->follow($discussion, $author);
        $this->logActivity($discussion, $author, 'discussion.created');

        $discussion = $discussion->fresh(['author', 'category']);

        if ($discussion->approved_at !== null) {
            SendDiscussionNotification::dispatch($discussion);
        }

        return $discussion;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OrganizationDiscussion $discussion, User $editor, array $data): OrganizationDiscussion
    {
        $this->authorizeOwnerOrModerator($editor, $discussion);

        $updates = [
            'title' => $data['title'] ?? $discussion->title,
            'body' => $data['body'] ?? $discussion->body,
            'updated_by' => $editor->id,
        ];

        if (array_key_exists('category_id', $data)) {
            $updates['category_id'] = $this->resolveCategoryId($this->organizationFor($discussion), $data['category_id']);
        }

        $attachments = $discussion->attachments ?? [];

        if (! empty($data['attachments']) && is_array($data['attachments'])) {
            $attachments = array_merge($attachments, $this->storeAttachmentFiles($data['attachments']));
        }

        if (! empty($data['remove_attachment_paths']) && is_array($data['remove_attachment_paths'])) {
            $removePaths = $data['remove_attachment_paths'];
            $attachments = collect($attachments)
                ->reject(fn (array $a) => in_array($a['path'] ?? null, $removePaths, true))
                ->values()
                ->all();
            Storage::disk('public')->delete($removePaths);
        }

        $updates['attachments'] = $attachments;

        $discussion->update($updates);
        $this->logActivity($discussion, $editor, 'discussion.updated');

        return $discussion->fresh(['author', 'category']);
    }

    /**
     * Never permanently deleted — soft delete only, so moderation history and replies survive.
     */
    public function softDelete(OrganizationDiscussion $discussion, User $actor): void
    {
        $this->authorizeOwnerOrModerator($actor, $discussion);

        if ((int) $discussion->created_by !== (int) $actor->id) {
            $this->logModeration($discussion, $actor, 'discussion.deleted');
        }

        $discussion->delete();
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function reply(OrganizationDiscussion $discussion, User $user, string $body, ?int $parentId = null, array $attachments = []): OrganizationDiscussionReply
    {
        $this->assertCanPost($discussion, $user);

        $parent = null;
        if ($parentId) {
            $parent = OrganizationDiscussionReply::query()
                ->where('discussion_id', $discussion->id)
                ->findOrFail($parentId);
        }

        $storedAttachments = $this->storeAttachmentFiles($attachments);

        $reply = DB::transaction(function () use ($discussion, $user, $body, $parent, $storedAttachments) {
            $reply = OrganizationDiscussionReply::create([
                'organization_id' => $discussion->organization_id,
                'discussion_id' => $discussion->id,
                'parent_id' => $parent?->id,
                'user_id' => $user->id,
                'body' => trim($body),
                'attachments' => $storedAttachments,
            ]);

            $discussion->increment('replies_count');
            $discussion->update(['last_activity_at' => now()]);

            return $reply;
        });

        $this->follow($discussion, $user);
        $this->logActivity($reply, $user, 'reply.created');

        return $reply->fresh(['user']);
    }

    public function updateReply(OrganizationDiscussionReply $reply, User $user, string $body): OrganizationDiscussionReply
    {
        $this->authorizeReplyAccess($reply, $user, 'edit');

        $reply->update(['body' => trim($body)]);

        return $reply->fresh();
    }

    public function softDeleteReply(OrganizationDiscussionReply $reply, User $user): void
    {
        $isOwner = $this->authorizeReplyAccess($reply, $user, 'delete');

        if (! $isOwner) {
            $this->logModeration($reply, $user, 'reply.deleted');
        }

        DB::transaction(function () use ($reply) {
            $discussion = OrganizationDiscussion::query()->find($reply->discussion_id);
            $reply->delete();

            if ($discussion && $discussion->replies_count > 0) {
                $discussion->decrement('replies_count');
            }
        });
    }

    /**
     * Toggle a reaction emoji on a discussion or reply for this user. Returns whether the
     * reaction is now active (true) or was removed (false).
     */
    public function react(Model $reactable, User $user, string $emoji): bool
    {
        $this->assertReactable($reactable);

        $organization = $this->organizationFor($reactable);
        $settings = $this->permissions->getSettings($organization);

        if (! ($settings['enable_reactions'] ?? true)) {
            throw ValidationException::withMessages(['emoji' => 'Reactions are disabled for this organization.']);
        }

        $allowedEmojis = (array) config('communication_hub.reaction_emojis', []);
        if ($allowedEmojis !== [] && ! in_array($emoji, $allowedEmojis, true)) {
            throw ValidationException::withMessages(['emoji' => 'Unsupported reaction.']);
        }

        $existing = OrganizationDiscussionReaction::query()
            ->where('reactable_type', $reactable->getMorphClass())
            ->where('reactable_id', $reactable->getKey())
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        $active = false;

        if ($existing) {
            $existing->delete();
        } else {
            OrganizationDiscussionReaction::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
                'reactable_type' => $reactable->getMorphClass(),
                'reactable_id' => $reactable->getKey(),
            ]);
            $active = true;
        }

        $count = OrganizationDiscussionReaction::query()
            ->where('reactable_type', $reactable->getMorphClass())
            ->where('reactable_id', $reactable->getKey())
            ->count();

        $reactable->update(['reactions_count' => $count]);

        return $active;
    }

    public function follow(OrganizationDiscussion $discussion, User $user): OrganizationDiscussionFollow
    {
        return OrganizationDiscussionFollow::query()->firstOrCreate(
            ['discussion_id' => $discussion->id, 'user_id' => $user->id],
            ['organization_id' => $discussion->organization_id, 'is_muted' => false]
        );
    }

    public function unfollow(OrganizationDiscussion $discussion, User $user): void
    {
        OrganizationDiscussionFollow::query()
            ->where('discussion_id', $discussion->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function mute(OrganizationDiscussion $discussion, User $user): OrganizationDiscussionFollow
    {
        $follow = OrganizationDiscussionFollow::query()->firstOrCreate(
            ['discussion_id' => $discussion->id, 'user_id' => $user->id],
            ['organization_id' => $discussion->organization_id, 'is_muted' => true]
        );

        if (! $follow->is_muted) {
            $follow->update(['is_muted' => true]);
        }

        return $follow->fresh();
    }

    public function unmute(OrganizationDiscussion $discussion, User $user): void
    {
        OrganizationDiscussionFollow::query()
            ->where('discussion_id', $discussion->id)
            ->where('user_id', $user->id)
            ->update(['is_muted' => false]);
    }

    /**
     * Bump the view counter, avoiding duplicate counts for the same user (or guest IP) on the same day.
     */
    public function recordView(OrganizationDiscussion $discussion, ?User $user = null, ?string $ipAddress = null): void
    {
        $startOfDay = now()->startOfDay();

        $alreadyViewedToday = OrganizationDiscussionView::query()
            ->where('discussion_id', $discussion->id)
            ->where('viewed_at', '>=', $startOfDay)
            ->when(
                $user,
                fn (Builder $q) => $q->where('user_id', $user->id),
                fn (Builder $q) => $q->whereNull('user_id')->where('ip_address', $ipAddress)
            )
            ->exists();

        if ($alreadyViewedToday) {
            return;
        }

        OrganizationDiscussionView::create([
            'organization_id' => $discussion->organization_id,
            'discussion_id' => $discussion->id,
            'user_id' => $user?->id,
            'ip_address' => $ipAddress,
            'viewed_at' => now(),
        ]);

        $discussion->increment('views_count');
    }

    public function pin(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_pinned' => true]);
        $this->logModeration($discussion, $actor, 'discussion.pinned');

        return $discussion->fresh();
    }

    public function unpin(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_pinned' => false]);
        $this->logModeration($discussion, $actor, 'discussion.unpinned');

        return $discussion->fresh();
    }

    public function lock(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_locked' => true]);
        $this->logModeration($discussion, $actor, 'discussion.locked');

        return $discussion->fresh();
    }

    public function unlock(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_locked' => false]);
        $this->logModeration($discussion, $actor, 'discussion.unlocked');

        return $discussion->fresh();
    }

    public function hide(OrganizationDiscussion $discussion, User $actor, ?string $reason = null): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_hidden' => true]);
        $this->logModeration($discussion, $actor, 'discussion.hidden', $reason ? ['reason' => $reason] : null, $reason);

        return $discussion->fresh();
    }

    /**
     * Reverse hide/archive back to a normal visible state (and un-trash if it was soft deleted).
     */
    public function restore(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        if ($discussion->trashed()) {
            $discussion->restore();
        }

        $discussion->update(['is_hidden' => false, 'is_archived' => false]);
        $this->logModeration($discussion, $actor, 'discussion.restored');

        return $discussion->fresh();
    }

    public function archive(OrganizationDiscussion $discussion, User $actor): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['is_archived' => true]);
        $this->logModeration($discussion, $actor, 'discussion.archived');

        return $discussion->fresh();
    }

    public function suspendPosting(OrganizationDiscussion $discussion, User $actor, bool $suspended = true): OrganizationDiscussion
    {
        $this->authorizeModerate($actor, $discussion);

        $discussion->update(['posting_suspended' => $suspended]);
        $this->logModeration($discussion, $actor, $suspended ? 'discussion.posting_suspended' : 'discussion.posting_resumed');

        return $discussion->fresh();
    }

    public function report(Model $reportable, User $reporter, string $reason, ?string $details = null): OrganizationDiscussionReport
    {
        $this->assertReactable($reportable); // discussion or reply — same allow-list

        $organization = $this->organizationFor($reportable);
        $settings = $this->permissions->getSettings($organization);

        if (! ($settings['enable_reporting'] ?? true)) {
            throw ValidationException::withMessages(['reason' => 'Reporting is disabled for this organization.']);
        }

        return OrganizationDiscussionReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $reporter->id,
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
            'reason' => $reason,
            'details' => $details,
            'status' => 'open',
        ]);
    }

    public function approve(OrganizationDiscussion $discussion, User $moderator): OrganizationDiscussion
    {
        $this->authorizeModerate($moderator, $discussion);

        $wasUnapproved = $discussion->approved_at === null;

        $discussion->update(['approved_at' => now(), 'approved_by' => $moderator->id]);
        $this->logModeration($discussion, $moderator, 'discussion.approved');

        $discussion = $discussion->fresh();

        if ($wasUnapproved) {
            SendDiscussionNotification::dispatch($discussion);
        }

        return $discussion;
    }

    /**
     * @param  array<string, mixed>  $filters  tab: all|recent|popular|unanswered|mine, search, sort:
     *                                          newest|oldest|most_replies|most_reactions, category_id,
     *                                          user_id (required for the "mine" tab), per_page, page
     */
    public function listForOrganization(Organization $organization, array $filters = []): LengthAwarePaginator
    {
        $lightweight = (bool) ($filters['lightweight'] ?? false);

        $query = OrganizationDiscussion::query()
            ->where('organization_id', $organization->id)
            ->with(['author:id,name,image', 'category:id,name,slug,color']);

        // replies_count column is denormalized on the discussions table; skip withCount for hub previews.
        if (! $lightweight) {
            $query->withCount('replies');
        }

        $tab = $filters['tab'] ?? 'all';

        match ($tab) {
            'recent' => $query->where('created_at', '>=', now()->subDays(30)),
            'unanswered' => $query->where('replies_count', 0),
            'mine' => $query->where('created_by', (int) ($filters['user_id'] ?? 0)),
            default => null,
        };

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('body', 'like', "%{$term}%")
                    ->orWhereHas('author', fn (Builder $a) => $a->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        $query->orderByDesc('is_pinned');

        $sort = $filters['sort'] ?? ($tab === 'popular' ? 'most_reactions' : 'newest');

        match ($sort) {
            'oldest' => $query->orderBy('created_at'),
            'most_replies' => $query->orderByDesc('replies_count'),
            'most_reactions' => $query->orderByDesc('reactions_count')->orderByDesc('views_count'),
            default => $query->orderByDesc('last_activity_at')->orderByDesc('created_at'),
        };

        return $query->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', $filters['page'] ?? null);
    }

    /**
     * @param  list<UploadedFile>  $attachments
     * @return list<array<string, mixed>>
     */
    private function storeAttachmentFiles(array $attachments): array
    {
        $stored = [];

        foreach ($attachments as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $stored[] = [
                'path' => $file->store(self::ATTACHMENT_DISK_PATH, 'public'),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $stored;
    }

    private function resolveCategoryId(Organization $organization, mixed $categoryId): ?int
    {
        if (! $categoryId) {
            return null;
        }

        return OrganizationDiscussionCategory::query()
            ->where('organization_id', $organization->id)
            ->where('id', (int) $categoryId)
            ->value('id');
    }

    private function assertCanPost(OrganizationDiscussion $discussion, User $user): void
    {
        if ($discussion->is_locked || $discussion->is_archived || $discussion->is_hidden || $discussion->posting_suspended) {
            throw ValidationException::withMessages(['body' => 'This discussion is not accepting new replies.']);
        }

        if (! $this->permissions->canCreateDiscussion($user, $this->organizationFor($discussion))) {
            throw ValidationException::withMessages(['body' => 'You are not allowed to reply in this organization.']);
        }
    }

    private function assertReactable(Model $model): void
    {
        if (! in_array(get_class($model), self::REACTABLE_TYPES, true)) {
            throw ValidationException::withMessages(['reactable' => 'This item does not support that action.']);
        }
    }

    private function authorizeOwnerOrModerator(User $user, OrganizationDiscussion $discussion): void
    {
        $isOwner = (int) $discussion->created_by === (int) $user->id;

        if (! $isOwner && ! $this->permissions->canModerateDiscussions($user, $this->organizationFor($discussion))) {
            throw ValidationException::withMessages(['discussion' => 'You are not allowed to modify this discussion.']);
        }
    }

    private function authorizeModerate(User $user, OrganizationDiscussion $discussion): void
    {
        if (! $this->permissions->canModerateDiscussions($user, $this->organizationFor($discussion))) {
            throw ValidationException::withMessages(['discussion' => 'You are not allowed to moderate this discussion.']);
        }
    }

    /**
     * @return bool whether the actor is the reply's own author
     */
    private function authorizeReplyAccess(OrganizationDiscussionReply $reply, User $user, string $action): bool
    {
        $isOwner = (int) $reply->user_id === (int) $user->id;

        if ($isOwner) {
            return true;
        }

        if (! $this->permissions->canModerateDiscussions($user, $this->organizationFor($reply))) {
            throw ValidationException::withMessages(['body' => "You are not allowed to {$action} this reply."]);
        }

        return false;
    }

    private function organizationFor(Model $model): Organization
    {
        if ($model->relationLoaded('organization') && $model->organization) {
            return $model->organization;
        }

        return Organization::findOrFail($model->organization_id);
    }

    private function logModeration(Model $target, User $moderator, string $action, ?array $meta = null, ?string $reason = null): void
    {
        OrganizationModerationLog::create([
            'organization_id' => $target->organization_id,
            'moderator_id' => $moderator->id,
            'action' => $action,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'meta' => $meta,
        ]);

        $this->logActivity($target, $moderator, $action);
    }

    private function logActivity(Model $subject, User $causer, string $description): void
    {
        if (! function_exists('activity')) {
            return;
        }

        activity()
            ->performedOn($subject)
            ->causedBy($causer)
            ->log($description);
    }
}
