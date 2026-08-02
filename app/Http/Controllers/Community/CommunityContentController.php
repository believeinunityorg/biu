<?php

namespace App\Http\Controllers\Community;

use App\Enums\CommunityContentType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\CommunityContent;
use App\Models\CommunityContentVersion;
use App\Models\CommunityFollow;
use App\Models\CommunityReaction;
use App\Models\CommunityReply;
use App\Models\Group;
use App\Models\Organization;
use App\Services\CommunityContentService;
use App\Services\CommunityModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommunityContentController extends Controller
{
    public function __construct(
        private readonly CommunityContentService $contentService,
        private readonly CommunityModerationService $moderation,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'parent_type' => ['required', 'string'],
            'parent_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['announcement', 'discussion'])],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_pinned' => ['sometimes', 'boolean'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'publish_now' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        $parent = $this->contentService->resolveParent($validated['parent_type'], (int) $validated['parent_id']);
        if (! $parent) {
            return redirect()->back()->with('error', 'Invalid parent community.');
        }

        $type = CommunityContentType::from($validated['type']);
        $content = $this->contentService->create($user, $parent, $type, array_merge($validated, [
            'cover_image' => $request->file('cover_image'),
            'attachment' => $request->file('attachment'),
            'publish_now' => $request->boolean('publish_now', true),
            'is_pinned' => $request->boolean('is_pinned'),
            'comments_enabled' => $request->boolean('comments_enabled', true),
        ]));

        return redirect()
            ->route('community.contents.show', $content)
            ->with('success', $type === CommunityContentType::Announcement ? 'Announcement published.' : 'Discussion started.');
    }

    public function show(Request $request, CommunityContent $content): Response|RedirectResponse
    {
        $raw = $request->segment(3);
        if (ctype_digit((string) $raw) && filled($content->slug)) {
            return redirect()->route('community.contents.show', $content, 301);
        }

        Gate::authorize('view', $content);
        $content->load(['author:id,name', 'parent']);

        $replies = CommunityReply::query()
            ->where('community_content_id', $content->id)
            ->where('visibility_status', 'visible')
            ->with(['author:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (CommunityReply $r) => [
                'id' => $r->id,
                'body' => $r->body,
                'parent_reply_id' => $r->parent_reply_id,
                'created_at' => $r->created_at?->toIso8601String(),
                'author' => $r->author ? ['id' => $r->author->id, 'name' => $r->author->name] : null,
                'attachment_url' => $r->attachment_path ? asset('storage/'.$r->attachment_path) : null,
                'attachment_name' => $r->attachment_name,
                'likes_count' => $r->reactions()->where('type', 'like')->count(),
                'liked_by_me' => $request->user()
                    ? $r->reactions()->where('type', 'like')->where('user_id', $request->user()->id)->exists()
                    : false,
            ]);

        $follow = $request->user()
            ? CommunityFollow::query()
                ->where('community_content_id', $content->id)
                ->where('user_id', $request->user()->id)
                ->first()
            : null;

        $parent = $content->parent;
        $tab = $content->type === CommunityContentType::Announcement ? 'announcements' : 'discussion';
        $parentName = null;
        if ($parent instanceof Group) {
            $backUrl = route('groups.show', ['group' => $parent, 'tab' => $tab]);
            $parentName = $parent->name;
        } elseif ($parent instanceof Organization || $parent instanceof CareAlliance) {
            $backUrl = route('community.parent.show', [
                'parentType' => $this->contentService->parentMorphType($parent),
                'parentId' => $parent->id,
                'tab' => $tab,
            ]);
            $parentName = $parent->name ?? null;
        } else {
            $backUrl = url()->previous();
        }

        $versions = CommunityContentVersion::query()
            ->where('community_content_id', $content->id)
            ->with(['editor:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CommunityContentVersion $v) => [
                'id' => $v->id,
                'title' => $v->title,
                'body' => $v->body,
                'created_at' => $v->created_at?->toIso8601String(),
                'editor' => $v->editor ? ['id' => $v->editor->id, 'name' => $v->editor->name] : null,
            ]);

        return Inertia::render('Community/ContentShow', [
            'content' => [
                'id' => $content->id,
                'slug' => $content->slug,
                'type' => $content->type?->value ?? $content->type,
                'title' => $content->title,
                'body' => $content->body,
                'category' => $content->category,
                'is_pinned' => (bool) $content->is_pinned,
                'is_locked' => (bool) $content->is_locked,
                'comments_enabled' => (bool) $content->comments_enabled,
                'published_at' => $content->published_at?->toIso8601String(),
                'expires_at' => $content->expires_at?->toIso8601String(),
                'archived_at' => $content->archived_at?->toIso8601String(),
                'visibility_status' => $content->visibility_status,
                'cover_image' => $content->cover_image ? asset('storage/'.$content->cover_image) : null,
                'attachment_url' => $content->attachment_path ? asset('storage/'.$content->attachment_path) : null,
                'attachment_name' => $content->attachment_name,
                'author' => $content->author ? ['id' => $content->author->id, 'name' => $content->author->name] : null,
                'likes_count' => $content->reactions()->where('type', 'like')->count(),
                'liked_by_me' => $request->user()
                    ? $content->reactions()->where('type', 'like')->where('user_id', $request->user()->id)->exists()
                    : false,
                'parent_type' => $content->parent_type,
                'parent_id' => $content->parent_id,
                'parent_name' => $parentName,
                'replies_count' => $replies->count(),
            ],
            'replies' => $replies,
            'versions' => $versions,
            'following' => (bool) $follow,
            'muted' => (bool) ($follow?->is_muted),
            'canModerate' => $request->user() ? Gate::allows('moderate', $content) : false,
            'canUpdate' => $request->user() ? Gate::allows('update', $content) : false,
            'canReply' => $request->user()
                && $content->comments_enabled
                && ! $content->is_locked
                && $content->visibility_status === 'visible',
            'backUrl' => $backUrl,
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    public function update(Request $request, CommunityContent $content): RedirectResponse
    {
        Gate::authorize('update', $content);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_pinned' => ['sometimes', 'boolean'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'archive' => ['sometimes', 'boolean'],
            'cover_image' => ['nullable', 'image', 'max:5120'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        $this->contentService->update($content, $request->user(), array_merge($validated, [
            'cover_image' => $request->file('cover_image'),
            'attachment' => $request->file('attachment'),
            'is_pinned' => $request->has('is_pinned') ? $request->boolean('is_pinned') : $content->is_pinned,
            'comments_enabled' => $request->has('comments_enabled') ? $request->boolean('comments_enabled') : $content->comments_enabled,
            'archive' => $request->boolean('archive'),
        ]));

        return redirect()->back()->with('success', 'Content updated. Previous version saved to history.');
    }

    public function reply(Request $request, CommunityContent $content): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'parent_reply_id' => ['nullable', 'integer', 'exists:community_replies,id'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        $this->contentService->addReply(
            $content,
            $request->user(),
            $validated['body'],
            $request->file('attachment'),
            $validated['parent_reply_id'] ?? null,
        );

        return redirect()->back()->with('success', 'Reply posted.');
    }

    public function react(Request $request, CommunityContent $content): RedirectResponse
    {
        $user = $request->user();
        $existing = CommunityReaction::query()
            ->where('reactable_type', $content->getMorphClass())
            ->where('reactable_id', $content->id)
            ->where('user_id', $user->id)
            ->where('type', 'like')
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            CommunityReaction::create([
                'reactable_type' => $content->getMorphClass(),
                'reactable_id' => $content->id,
                'user_id' => $user->id,
                'type' => 'like',
            ]);
        }

        return redirect()->back();
    }

    public function follow(Request $request, CommunityContent $content): RedirectResponse
    {
        $follow = CommunityFollow::firstOrCreate(
            ['community_content_id' => $content->id, 'user_id' => $request->user()->id],
            ['is_muted' => false]
        );

        if ($request->boolean('mute')) {
            $follow->update(['is_muted' => true]);
        } elseif ($request->boolean('unmute')) {
            $follow->update(['is_muted' => false]);
        } elseif ($request->boolean('unfollow')) {
            $follow->delete();
        }

        return redirect()->back();
    }

    public function moderate(Request $request, CommunityContent $content): RedirectResponse
    {
        Gate::authorize('moderate', $content);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['hide', 'remove', 'restore', 'lock', 'unlock'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        match ($validated['action']) {
            'hide' => $this->moderation->hideContent($content, $actor, $validated['reason'] ?? null),
            'remove' => $this->moderation->removeContentFromView($content, $actor, $validated['reason'] ?? null),
            'restore' => $this->moderation->restoreContent($content, $actor, $validated['reason'] ?? null),
            'lock' => $this->moderation->lockContent($content, $actor, $validated['reason'] ?? null),
            'unlock' => $this->moderation->unlockContent($content, $actor, $validated['reason'] ?? null),
        };

        return redirect()->back()->with('success', 'Moderation action applied.');
    }

    public function moderateReply(Request $request, CommunityReply $reply): RedirectResponse
    {
        $content = $reply->content;
        Gate::authorize('moderate', $content);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['hide', 'restore'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        match ($validated['action']) {
            'hide' => $this->moderation->hideReply($reply, $request->user(), $validated['reason'] ?? null),
            'restore' => $this->moderation->restoreReply($reply, $request->user(), $validated['reason'] ?? null),
        };

        return redirect()->back()->with('success', 'Reply moderation applied.');
    }
}
