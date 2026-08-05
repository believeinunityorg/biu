<?php

namespace App\Services\CommunicationHub;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationAnnouncementComment;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\OrganizationDiscussionReply;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Serializes Communication Hub models into plain arrays for Inertia pages (this project does not
 * use API Resources).
 */
class CommunicationHubPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function announcementToArray(OrganizationAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'slug' => $announcement->slug,
            'title' => $announcement->title,
            'message' => $announcement->message,
            'excerpt' => $announcement->excerpt,
            'category' => $announcement->category,
            'cover_image_url' => $announcement->cover_image_url,
            'attachments' => $this->attachmentsToArray($announcement->attachments),
            'status' => $announcement->status?->value,
            'status_label' => $announcement->status?->label(),
            'is_pinned' => (bool) $announcement->is_pinned,
            'allow_comments' => (bool) $announcement->allow_comments,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'comments_count' => (int) ($announcement->comments_count ?? 0),
            'author' => $this->userToArray($announcement->relationLoaded('creator') ? $announcement->creator : null),
            'updated_by' => $this->userToArray($announcement->relationLoaded('updater') ? $announcement->updater : null),
            'published_by' => $this->userToArray($announcement->relationLoaded('publisher') ? $announcement->publisher : null),
            'badges' => $this->announcementBadges($announcement),
            'created_at' => $announcement->created_at?->toIso8601String(),
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function discussionToArray(OrganizationDiscussion $discussion): array
    {
        return [
            'id' => $discussion->id,
            'slug' => $discussion->slug,
            'title' => $discussion->title,
            'body' => $discussion->body,
            'excerpt' => $discussion->excerpt,
            'attachments' => $this->attachmentsToArray($discussion->attachments),
            'category' => $discussion->relationLoaded('category') && $discussion->category
                ? $this->categoryToArray($discussion->category)
                : null,
            'author' => $this->userToArray($discussion->relationLoaded('author') ? $discussion->author : null),
            'is_pinned' => (bool) $discussion->is_pinned,
            'is_locked' => (bool) $discussion->is_locked,
            'is_hidden' => (bool) $discussion->is_hidden,
            'is_archived' => (bool) $discussion->is_archived,
            'posting_suspended' => (bool) $discussion->posting_suspended,
            'replies_count' => (int) ($discussion->replies_count ?? 0),
            'views_count' => (int) ($discussion->views_count ?? 0),
            'reactions_count' => (int) ($discussion->reactions_count ?? 0),
            'last_activity_at' => $discussion->last_activity_at?->toIso8601String(),
            'approved_at' => $discussion->approved_at?->toIso8601String(),
            'is_approved' => $discussion->approved_at !== null,
            'badges' => $this->discussionBadges($discussion),
            'created_at' => $discussion->created_at?->toIso8601String(),
            'updated_at' => $discussion->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commentToArray(OrganizationAnnouncementComment $comment): array
    {
        return [
            'id' => $comment->id,
            'announcement_id' => $comment->announcement_id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'is_hidden' => (bool) $comment->is_hidden,
            'is_locked' => (bool) $comment->is_locked,
            'user' => $this->userToArray($comment->relationLoaded('user') ? $comment->user : null),
            'replies' => $comment->relationLoaded('children')
                ? $comment->children->map(fn (OrganizationAnnouncementComment $c) => $this->commentToArray($c))->values()->all()
                : [],
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replyToArray(OrganizationDiscussionReply $reply): array
    {
        return [
            'id' => $reply->id,
            'discussion_id' => $reply->discussion_id,
            'parent_id' => $reply->parent_id,
            'body' => $reply->body,
            'attachments' => $this->attachmentsToArray($reply->attachments),
            'is_hidden' => (bool) $reply->is_hidden,
            'reactions_count' => (int) ($reply->reactions_count ?? 0),
            'user' => $this->userToArray($reply->relationLoaded('user') ? $reply->user : null),
            'replies' => $reply->relationLoaded('children')
                ? $reply->children->map(fn (OrganizationDiscussionReply $r) => $this->replyToArray($r))->values()->all()
                : [],
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryToArray(OrganizationDiscussionCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'color' => $category->color,
            'sort_order' => $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'discussions_count' => $category->discussions_count ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function settingsToArray(array $settings, Organization $organization): array
    {
        return [
            'organization_id' => $organization->id,
            'allow_followers_to_post' => (bool) ($settings['allow_followers_to_post'] ?? true),
            'allow_members_to_post' => (bool) ($settings['allow_members_to_post'] ?? true),
            'require_approval' => (bool) ($settings['require_approval'] ?? false),
            'allow_attachments' => (bool) ($settings['allow_attachments'] ?? true),
            'allow_mentions' => (bool) ($settings['allow_mentions'] ?? true),
            'enable_reactions' => (bool) ($settings['enable_reactions'] ?? true),
            'enable_comments' => (bool) ($settings['enable_comments'] ?? true),
            'enable_reporting' => (bool) ($settings['enable_reporting'] ?? true),
            'auto_archive_days' => $settings['auto_archive_days'] ?? null,
            'reaction_emojis' => config('communication_hub.reaction_emojis', []),
            'report_reasons' => config('communication_hub.report_reasons', []),
            'announcement_categories' => config('communication_hub.announcement_categories', []),
        ];
    }

    /**
     * @return array{id: int, name: string, avatar_url: string}|null
     */
    private function userToArray(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentsToArray(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        return collect($attachments)
            ->map(function ($attachment) {
                if (! is_array($attachment) || empty($attachment['path'])) {
                    return null;
                }

                return [
                    'path' => $attachment['path'],
                    'name' => $attachment['name'] ?? basename($attachment['path']),
                    'mime' => $attachment['mime'] ?? null,
                    'size' => $attachment['size'] ?? null,
                    'url' => Storage::disk('public')->url($attachment['path']),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function announcementBadges(OrganizationAnnouncement $announcement): array
    {
        $badges = [];

        if ($announcement->is_pinned) {
            $badges[] = 'pinned';
        }
        if ($announcement->status?->value === 'scheduled') {
            $badges[] = 'scheduled';
        }
        if ($announcement->status?->value === 'archived') {
            $badges[] = 'archived';
        }
        if ($announcement->status?->value === 'hidden') {
            $badges[] = 'hidden';
        }
        if ($announcement->expires_at && $announcement->expires_at->isPast()) {
            $badges[] = 'expired';
        }

        return $badges;
    }

    /**
     * @return list<string>
     */
    private function discussionBadges(OrganizationDiscussion $discussion): array
    {
        $badges = [];

        if ($discussion->is_pinned) {
            $badges[] = 'pinned';
        }
        if ($discussion->is_locked) {
            $badges[] = 'locked';
        }
        if ($discussion->is_hidden) {
            $badges[] = 'hidden';
        }
        if ($discussion->is_archived) {
            $badges[] = 'archived';
        }
        if ($discussion->posting_suspended) {
            $badges[] = 'posting_suspended';
        }
        if ((int) ($discussion->replies_count ?? 0) === 0) {
            $badges[] = 'unanswered';
        }
        if ($discussion->approved_at === null) {
            $badges[] = 'pending_approval';
        }

        return $badges;
    }
}
