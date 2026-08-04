<?php

namespace App\Services\CommunicationHub;

use App\Enums\OrganizationAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationAnnouncementComment;
use App\Models\OrganizationAnnouncementHistory;
use App\Models\OrganizationModerationLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrganizationAnnouncementService
{
    private const COVER_DISK_PATH = 'communication-hub/announcements/covers';

    private const ATTACHMENT_DISK_PATH = 'communication-hub/announcements/attachments';

    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $author, Organization $organization, array $data): OrganizationAnnouncement
    {
        if (! $this->permissions->canManageAnnouncements($author, $organization)) {
            throw ValidationException::withMessages([
                'organization' => 'You are not allowed to manage announcements for this organization.',
            ]);
        }

        $title = (string) $data['title'];
        $stored = $this->storeAttachments($data['cover_image'] ?? null, $data['attachments'] ?? []);
        [$status, $publishedAt, $scheduledAt] = $this->resolveStatusDates($data);

        $announcement = OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'title' => $title,
            'slug' => OrganizationAnnouncement::uniqueSlugFromTitle($title),
            'message' => $data['message'] ?? '',
            'category' => $data['category'] ?? null,
            'cover_image' => $stored['cover_image'],
            'attachments' => $stored['attachments'],
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'expires_at' => $data['expires_at'] ?? null,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            'allow_comments' => array_key_exists('allow_comments', $data) ? (bool) $data['allow_comments'] : true,
            'status' => $status->value,
            'created_by' => $author->id,
            'published_by' => $status === OrganizationAnnouncementStatus::Published ? $author->id : null,
        ]);

        $this->logActivity($announcement, $author, 'announcement.created');

        return $announcement->fresh(['creator']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OrganizationAnnouncement $announcement, User $editor, array $data): OrganizationAnnouncement
    {
        $this->authorizeManage($editor, $announcement);

        OrganizationAnnouncementHistory::create([
            'organization_id' => $announcement->organization_id,
            'announcement_id' => $announcement->id,
            'edited_by' => $editor->id,
            'title' => $announcement->title,
            'message' => $announcement->message,
            'meta' => [
                'category' => $announcement->category,
                'status' => $announcement->status?->value,
                'is_pinned' => $announcement->is_pinned,
                'allow_comments' => $announcement->allow_comments,
                'cover_image' => $announcement->cover_image,
                'attachments' => $announcement->attachments,
                'expires_at' => $announcement->expires_at?->toIso8601String(),
            ],
        ]);

        $updates = [
            'title' => $data['title'] ?? $announcement->title,
            'message' => $data['message'] ?? $announcement->message,
            'updated_by' => $editor->id,
        ];

        if (array_key_exists('category', $data)) {
            $updates['category'] = $data['category'];
        }
        if (array_key_exists('is_pinned', $data)) {
            $updates['is_pinned'] = (bool) $data['is_pinned'];
        }
        if (array_key_exists('allow_comments', $data)) {
            $updates['allow_comments'] = (bool) $data['allow_comments'];
        }
        if (array_key_exists('expires_at', $data)) {
            $updates['expires_at'] = $data['expires_at'];
        }
        if (($data['status'] ?? null) === OrganizationAnnouncementStatus::Draft->value) {
            $updates['status'] = OrganizationAnnouncementStatus::Draft->value;
        }

        if (($data['cover_image'] ?? null) instanceof UploadedFile) {
            if ($announcement->cover_image) {
                Storage::disk('public')->delete($announcement->cover_image);
            }
            $updates['cover_image'] = $data['cover_image']->store(self::COVER_DISK_PATH, 'public');
        }

        $attachments = $announcement->attachments ?? [];

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

        $announcement->update($updates);

        $this->logActivity($announcement, $editor, 'announcement.updated');

        return $announcement->fresh(['creator', 'updater']);
    }

    public function publish(OrganizationAnnouncement $announcement, User $actor): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update([
            'status' => OrganizationAnnouncementStatus::Published->value,
            'published_at' => $announcement->published_at ?? now(),
            'scheduled_at' => null,
            'published_by' => $actor->id,
        ]);

        $this->logModeration($announcement, $actor, 'announcement.published');

        return $announcement->fresh();
    }

    public function schedule(OrganizationAnnouncement $announcement, User $actor, string|Carbon $scheduledAt): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $scheduledAt = $scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse($scheduledAt);

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Scheduled time must be in the future.']);
        }

        $announcement->update([
            'status' => OrganizationAnnouncementStatus::Scheduled->value,
            'scheduled_at' => $scheduledAt,
            'published_at' => null,
        ]);

        $this->logModeration($announcement, $actor, 'announcement.scheduled', ['scheduled_at' => $scheduledAt->toIso8601String()]);

        return $announcement->fresh();
    }

    public function archive(OrganizationAnnouncement $announcement, User $actor, ?string $reason = null): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update(['status' => OrganizationAnnouncementStatus::Archived->value]);

        $this->logModeration($announcement, $actor, 'announcement.archived', $reason ? ['reason' => $reason] : null, $reason);

        return $announcement->fresh();
    }

    /**
     * Revert an archived/hidden announcement back to its natural state (published if it was ever
     * published, draft otherwise). To recover a soft-deleted announcement, use {@see restoreDeleted()}.
     */
    public function restore(OrganizationAnnouncement $announcement, User $actor): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $status = $announcement->published_at
            ? OrganizationAnnouncementStatus::Published
            : OrganizationAnnouncementStatus::Draft;

        $announcement->update(['status' => $status->value]);

        $this->logModeration($announcement, $actor, 'announcement.restored');

        return $announcement->fresh();
    }

    public function restoreDeleted(int $announcementId, User $actor): OrganizationAnnouncement
    {
        $announcement = OrganizationAnnouncement::withTrashed()->findOrFail($announcementId);
        $this->authorizeManage($actor, $announcement);

        $announcement->restore();
        $this->logModeration($announcement, $actor, 'announcement.restored_from_trash');

        return $announcement->fresh();
    }

    public function hide(OrganizationAnnouncement $announcement, User $actor, ?string $reason = null): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update(['status' => OrganizationAnnouncementStatus::Hidden->value]);

        $this->logModeration($announcement, $actor, 'announcement.hidden', $reason ? ['reason' => $reason] : null, $reason);

        return $announcement->fresh();
    }

    public function pin(OrganizationAnnouncement $announcement, User $actor): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update(['is_pinned' => true]);
        $this->logModeration($announcement, $actor, 'announcement.pinned');

        return $announcement->fresh();
    }

    public function unpin(OrganizationAnnouncement $announcement, User $actor): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update(['is_pinned' => false]);
        $this->logModeration($announcement, $actor, 'announcement.unpinned');

        return $announcement->fresh();
    }

    public function toggleComments(OrganizationAnnouncement $announcement, User $actor): OrganizationAnnouncement
    {
        $this->authorizeManage($actor, $announcement);

        $announcement->update(['allow_comments' => ! $announcement->allow_comments]);
        $this->logActivity($announcement, $actor, $announcement->allow_comments ? 'announcement.comments_enabled' : 'announcement.comments_disabled');

        return $announcement->fresh();
    }

    /**
     * Announcements are never permanently deleted — always soft deleted so history/audit trail survives.
     */
    public function softDelete(OrganizationAnnouncement $announcement, User $actor, ?string $reason = null): void
    {
        $this->authorizeManage($actor, $announcement);

        $this->logModeration($announcement, $actor, 'announcement.deleted', $reason ? ['reason' => $reason] : null, $reason);

        $announcement->delete();
    }

    /**
     * Store an optional cover image plus a list of attachment uploads.
     *
     * @param  list<UploadedFile>  $attachments
     * @return array{cover_image: ?string, attachments: list<array<string, mixed>>}
     */
    public function storeAttachments(?UploadedFile $cover, array $attachments = []): array
    {
        return [
            'cover_image' => $cover instanceof UploadedFile ? $cover->store(self::COVER_DISK_PATH, 'public') : null,
            'attachments' => $this->storeAttachmentFiles($attachments),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForOrganization(Organization $organization, array $filters = []): LengthAwarePaginator
    {
        $lightweight = (bool) ($filters['lightweight'] ?? false);

        $query = OrganizationAnnouncement::query()
            ->forOrganization($organization->id)
            ->with($lightweight
                ? ['creator:id,name,image']
                : ['creator:id,name,image', 'updater:id,name,image', 'publisher:id,name,image']);

        if (! $lightweight) {
            $query->withCount(['comments' => fn (Builder $q) => $q->where('is_hidden', false)]);
        }

        match ($filters['filter'] ?? 'all') {
            'pinned' => $query->where('is_pinned', true),
            'scheduled' => $query->where('status', OrganizationAnnouncementStatus::Scheduled->value),
            'archived' => $query->where('status', OrganizationAnnouncementStatus::Archived->value),
            default => null,
        };

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            });
        }

        $query->orderByDesc('is_pinned')->orderByDesc('created_at');

        return $query->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', $filters['page'] ?? null);
    }

    /**
     * Publicly visible announcements: promotes due scheduled announcements first, then pinned/newest.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listPublic(Organization $organization, array $filters = []): LengthAwarePaginator
    {
        $lightweight = (bool) ($filters['lightweight'] ?? false);

        // Hub previews already promote scheduled items in CommunicationHubPageService.
        if (! $lightweight) {
            OrganizationAnnouncement::promoteScheduledForOrganization($organization->id);
        }

        $query = OrganizationAnnouncement::query()
            ->forOrganization($organization->id)
            ->visibleToPublic()
            ->with(['creator:id,name,image']);

        if (! $lightweight) {
            $query->withCount(['comments' => fn (Builder $q) => $q->where('is_hidden', false)]);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%");
            });
        }

        $query->pinnedFirst();

        return $query->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', $filters['page'] ?? null);
    }

    public function addComment(OrganizationAnnouncement $announcement, User $user, string $body, ?int $parentId = null): OrganizationAnnouncementComment
    {
        $organization = $this->organizationFor($announcement);

        if (! $announcement->allow_comments || $announcement->status !== OrganizationAnnouncementStatus::Published) {
            throw ValidationException::withMessages(['body' => 'Comments are disabled for this announcement.']);
        }

        if (! $this->permissions->canCommentOnAnnouncements($user, $organization)) {
            throw ValidationException::withMessages(['body' => 'You are not allowed to comment on this announcement.']);
        }

        $parent = null;
        if ($parentId) {
            $parent = OrganizationAnnouncementComment::query()
                ->where('announcement_id', $announcement->id)
                ->findOrFail($parentId);

            if ($parent->is_locked) {
                throw ValidationException::withMessages(['body' => 'This comment thread is locked.']);
            }
        }

        return OrganizationAnnouncementComment::create([
            'organization_id' => $announcement->organization_id,
            'announcement_id' => $announcement->id,
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'body' => trim($body),
        ])->fresh(['user']);
    }

    public function updateComment(OrganizationAnnouncementComment $comment, User $user, string $body): OrganizationAnnouncementComment
    {
        $this->authorizeCommentAccess($comment, $user, 'edit');

        $comment->update(['body' => trim($body)]);

        return $comment->fresh();
    }

    public function softDeleteComment(OrganizationAnnouncementComment $comment, User $user): void
    {
        $isOwner = $this->authorizeCommentAccess($comment, $user, 'delete');

        if (! $isOwner) {
            $this->logModeration($comment, $user, 'comment.deleted');
        }

        $comment->delete();
    }

    public function hideComment(OrganizationAnnouncementComment $comment, User $actor, ?string $reason = null): OrganizationAnnouncementComment
    {
        $organization = $this->organizationFor($comment);

        if (! $this->permissions->canManageAnnouncements($actor, $organization)) {
            throw ValidationException::withMessages(['body' => 'You are not allowed to moderate comments.']);
        }

        $comment->update(['is_hidden' => true]);
        $this->logModeration($comment, $actor, 'comment.hidden', $reason ? ['reason' => $reason] : null, $reason);

        return $comment->fresh();
    }

    public function restoreComment(OrganizationAnnouncementComment $comment, User $actor): OrganizationAnnouncementComment
    {
        $organization = $this->organizationFor($comment);

        if (! $this->permissions->canManageAnnouncements($actor, $organization)) {
            throw ValidationException::withMessages(['body' => 'You are not allowed to moderate comments.']);
        }

        if ($comment->trashed()) {
            $comment->restore();
        }
        $comment->update(['is_hidden' => false]);

        $this->logModeration($comment, $actor, 'comment.restored');

        return $comment->fresh();
    }

    public function lockComments(OrganizationAnnouncementComment $comment, User $actor, bool $locked = true): OrganizationAnnouncementComment
    {
        $organization = $this->organizationFor($comment);

        if (! $this->permissions->canManageAnnouncements($actor, $organization)) {
            throw ValidationException::withMessages(['body' => 'You are not allowed to moderate comments.']);
        }

        $comment->update(['is_locked' => $locked]);
        $this->logModeration($comment, $actor, $locked ? 'comment.locked' : 'comment.unlocked');

        return $comment->fresh();
    }

    /**
     * @return Collection<int, OrganizationAnnouncementHistory>
     */
    public function history(OrganizationAnnouncement $announcement): Collection
    {
        return OrganizationAnnouncementHistory::query()
            ->where('announcement_id', $announcement->id)
            ->with('editor:id,name,image')
            ->latest()
            ->get();
    }

    /**
     * @return array{0: OrganizationAnnouncementStatus, 1: ?Carbon, 2: ?Carbon}
     */
    private function resolveStatusDates(array $data): array
    {
        if (($data['status'] ?? null) === OrganizationAnnouncementStatus::Draft->value) {
            return [OrganizationAnnouncementStatus::Draft, null, null];
        }

        if (! empty($data['scheduled_at'])) {
            return [OrganizationAnnouncementStatus::Scheduled, null, Carbon::parse($data['scheduled_at'])];
        }

        return [OrganizationAnnouncementStatus::Published, now(), null];
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

    private function authorizeManage(User $actor, OrganizationAnnouncement $announcement): void
    {
        $organization = $this->organizationFor($announcement);

        if (! $this->permissions->canManageAnnouncements($actor, $organization)) {
            throw ValidationException::withMessages([
                'organization' => 'You are not allowed to manage announcements for this organization.',
            ]);
        }
    }

    /**
     * @return bool whether the actor is the comment's own author
     */
    private function authorizeCommentAccess(OrganizationAnnouncementComment $comment, User $user, string $action): bool
    {
        $isOwner = (int) $comment->user_id === (int) $user->id;

        if ($isOwner) {
            return true;
        }

        $organization = $this->organizationFor($comment);

        if (! $this->permissions->canManageAnnouncements($user, $organization)) {
            throw ValidationException::withMessages(['body' => "You are not allowed to {$action} this comment."]);
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
