<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Organization\Concerns\ResolvesCommunicationHubOrganization;
use App\Http\Requests\CommunicationHub\ReportContentRequest;
use App\Http\Requests\CommunicationHub\StoreAnnouncementCommentRequest;
use App\Http\Requests\CommunicationHub\StoreDiscussionReplyRequest;
use App\Http\Requests\CommunicationHub\StoreDiscussionRequest;
use App\Http\Requests\CommunicationHub\UpdateDiscussionRequest;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationAnnouncementComment;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\OrganizationDiscussionFollow;
use App\Models\OrganizationDiscussionReply;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPageService;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use App\Services\CommunicationHub\CommunicationHubPresenter;
use App\Services\CommunicationHub\OrganizationAnnouncementService;
use App\Services\CommunicationHub\OrganizationDiscussionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Supporter / community-facing Communication Hub (A&D) — resolves org by public slug
 * so followers and members can view announcements and participate in discussions.
 */
class CommunityCommunicationHubController extends Controller
{
    use ResolvesCommunicationHubOrganization;

    public function __construct(
        private readonly CommunicationHubPageService $pageService,
        private readonly CommunicationHubPermissionService $permissions,
        private readonly OrganizationAnnouncementService $announcements,
        private readonly OrganizationDiscussionService $discussions,
        private readonly CommunicationHubPresenter $presenter,
    ) {}

    public function show(Request $request, string $slug)
    {
        $organization = $this->resolveOrgBySlug($slug);

        $tab = (string) $request->query('tab', 'announcements');
        if (! in_array($tab, ['announcements', 'discussions'], true)) {
            $tab = 'announcements';
        }

        $props = $this->pageService->hubProps($organization, Auth::user(), [
            'activeTab' => $tab,
            'announcementsLimit' => 10,
            'discussionsLimit' => 10,
            'community' => true,
        ]);

        return Inertia::render('Organization/CommunicationHub/PublicIndex', $props);
    }

    public function showAnnouncement(string $slug, OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $announcement);

        if ($announcement->trashed()) {
            abort(404);
        }

        $this->authorize('view', $announcement);

        /** @var User|null $user */
        $user = Auth::user();
        $isManager = $user && $this->permissions->canManageAnnouncements($user, $org);

        $announcement->load(['creator', 'updater', 'publisher'])
            ->loadCount(['comments' => fn ($q) => $q->where('is_hidden', false)]);

        $comments = OrganizationAnnouncementComment::query()
            ->where('announcement_id', $announcement->id)
            ->whereNull('parent_id')
            ->where('is_hidden', false)
            ->with(['user', 'children' => fn ($q) => $q->where('is_hidden', false)->with('user')])
            ->oldest()
            ->get();

        return Inertia::render('Organization/CommunicationHub/Announcements/Show', [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $slug],
            'announcement' => $this->presenter->announcementToArray($announcement),
            'comments' => $comments->map(fn (OrganizationAnnouncementComment $c) => $this->presenter->commentToArray($c))->values()->all(),
            'history' => [],
            'can' => [
                'manage' => $isManager,
                'comment' => $user ? $this->permissions->canCommentOnAnnouncements($user, $org) : false,
            ],
            'hubContext' => $this->hubContext($org, true),
        ]);
    }

    public function storeComment(StoreAnnouncementCommentRequest $request, string $slug, OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $announcement);
        $this->authorize('create', [OrganizationAnnouncementComment::class, $announcement]);

        $this->announcements->addComment(
            $announcement,
            Auth::user(),
            $request->validated('body'),
            $request->validated('parent_id')
        );

        return redirect()->back()->with('success', 'Comment posted.');
    }

    public function discussionsIndex(Request $request, string $slug)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->discussions->ensureDefaultCategories($org);
        $this->authorize('viewAny', [OrganizationDiscussion::class, $org]);

        /** @var User|null $user */
        $user = Auth::user();
        $canModerate = $user ? $this->permissions->canModerateDiscussions($user, $org) : false;

        $discussions = $this->discussions->listForOrganization($org, [
            'tab' => $request->query('tab', 'all'),
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'category_id' => $request->query('category_id'),
            'user_id' => $user?->id,
            'page' => $request->query('page'),
            'public_only' => ! $canModerate,
        ])->through(fn (OrganizationDiscussion $discussion) => $this->presenter->discussionToArray($discussion));

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->withCount('discussions')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Organization/CommunicationHub/Discussions/Index', [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $slug],
            'discussions' => $discussions,
            'categories' => $categories->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))->values()->all(),
            'filters' => [
                'tab' => $request->query('tab', 'all'),
                'search' => $request->query('search', ''),
                'sort' => $request->query('sort', 'newest'),
                'category_id' => $request->query('category_id'),
            ],
            'can' => [
                'create' => $user ? $this->permissions->canCreateDiscussion($user, $org) : false,
                'moderate' => $canModerate,
            ],
            'hubContext' => $this->hubContext($org, true),
        ]);
    }

    public function discussionsCreate(string $slug)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->authorize('create', [OrganizationDiscussion::class, $org]);

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Organization/CommunicationHub/Discussions/Create', [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $slug],
            'categories' => $categories->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))->values()->all(),
            'hubContext' => $this->hubContext($org, true),
        ]);
    }

    public function discussionsStore(StoreDiscussionRequest $request, string $slug)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->authorize('create', [OrganizationDiscussion::class, $org]);

        $data = $request->validated();
        $data['attachments'] = $request->file('attachments', []);

        $discussion = $this->discussions->create(Auth::user(), $org, $data);

        return redirect()
            ->route('organizations.communication-hub.discussions.show', [$slug, $discussion->slug])
            ->with('success', 'Discussion created successfully.');
    }

    public function discussionsShow(Request $request, string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('view', $discussion);

        $this->discussions->recordView($discussion, Auth::user(), $request->ip());

        $discussion->load(['author', 'category'])->loadCount('replies');

        $replies = OrganizationDiscussionReply::query()
            ->where('discussion_id', $discussion->id)
            ->whereNull('parent_id')
            ->where('is_hidden', false)
            ->with(['user', 'children' => fn ($q) => $q->where('is_hidden', false)->with('user')])
            ->oldest()
            ->get();

        $follow = Auth::check()
            ? OrganizationDiscussionFollow::query()
                ->where('discussion_id', $discussion->id)
                ->where('user_id', Auth::id())
                ->first()
            : null;

        return Inertia::render('Organization/CommunicationHub/Discussions/Show', [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $slug],
            'discussion' => $this->presenter->discussionToArray($discussion),
            'replies' => $replies->map(fn (OrganizationDiscussionReply $r) => $this->presenter->replyToArray($r))->values()->all(),
            'isFollowing' => (bool) $follow,
            'isMuted' => (bool) ($follow?->is_muted),
            'can' => [
                'update' => Auth::check() && Gate::allows('update', $discussion),
                'delete' => Auth::check() && Gate::allows('delete', $discussion),
                'reply' => Auth::check() && Gate::allows('reply', $discussion),
                'react' => Auth::check() && Gate::allows('react', $discussion),
                'moderate' => Auth::check() && Gate::allows('moderate', $discussion),
                'report' => Auth::check() && Gate::allows('report', $discussion),
            ],
            'reportReasons' => config('communication_hub.report_reasons', []),
            'reactionEmojis' => config('communication_hub.reaction_emojis', []),
            'hubContext' => $this->hubContext($org, true),
        ]);
    }

    public function discussionsUpdate(UpdateDiscussionRequest $request, string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('update', $discussion);

        $data = $request->validated();
        $data['attachments'] = $request->file('attachments', []);

        $discussion = $this->discussions->update($discussion, Auth::user(), $data);

        return redirect()
            ->route('organizations.communication-hub.discussions.show', [$slug, $discussion->slug])
            ->with('success', 'Discussion updated successfully.');
    }

    public function discussionsDestroy(string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('delete', $discussion);

        $this->discussions->softDelete($discussion, Auth::user());

        return redirect()
            ->route('organizations.communication-hub.discussions.index', $slug)
            ->with('success', 'Discussion deleted.');
    }

    public function reply(StoreDiscussionReplyRequest $request, string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('reply', $discussion);

        $this->discussions->reply(
            $discussion,
            Auth::user(),
            $request->validated('body'),
            $request->validated('parent_id'),
            $request->file('attachments', [])
        );

        return redirect()->back()->with('success', 'Reply posted.');
    }

    public function updateReply(Request $request, string $slug, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        if ((int) $reply->discussion_id !== (int) $discussion->id) {
            abort(404);
        }

        $isOwner = (int) $reply->user_id === (int) Auth::id();
        if (! $isOwner) {
            $this->authorize('moderate', $discussion);
        }

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $this->discussions->updateReply($reply, Auth::user(), $validated['body']);

        return redirect()->back()->with('success', 'Reply updated.');
    }

    public function destroyReply(string $slug, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        if ((int) $reply->discussion_id !== (int) $discussion->id) {
            abort(404);
        }

        $isOwner = (int) $reply->user_id === (int) Auth::id();
        if (! $isOwner) {
            $this->authorize('moderate', $discussion);
        }

        $this->discussions->softDeleteReply($reply, Auth::user());

        return redirect()->back()->with('success', 'Reply deleted.');
    }

    public function react(Request $request, string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('react', $discussion);

        $validated = $request->validate([
            'emoji' => ['required', 'string'],
        ]);

        $active = $this->discussions->react($discussion, Auth::user(), $validated['emoji']);

        return redirect()->back()->with('success', $active ? 'Reaction added.' : 'Reaction removed.');
    }

    public function reactReply(Request $request, string $slug, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        if ((int) $reply->discussion_id !== (int) $discussion->id) {
            abort(404);
        }

        $this->authorize('react', $discussion);

        $validated = $request->validate([
            'emoji' => ['required', 'string'],
        ]);

        $active = $this->discussions->react($reply, Auth::user(), $validated['emoji']);

        return redirect()->back()->with('success', $active ? 'Reaction added.' : 'Reaction removed.');
    }

    public function follow(string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('follow', $discussion);

        $this->discussions->follow($discussion, Auth::user());

        return redirect()->back()->with('success', 'You are now following this discussion.');
    }

    public function unfollow(string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        $this->discussions->unfollow($discussion, Auth::user());

        return redirect()->back()->with('success', 'You unfollowed this discussion.');
    }

    public function mute(string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        $this->discussions->mute($discussion, Auth::user());

        return redirect()->back()->with('success', 'Notifications muted for this discussion.');
    }

    public function unmute(string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        $this->discussions->unmute($discussion, Auth::user());

        return redirect()->back()->with('success', 'Notifications unmuted for this discussion.');
    }

    public function report(ReportContentRequest $request, string $slug, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);
        $this->authorize('report', $discussion);

        $this->discussions->report(
            $discussion,
            Auth::user(),
            $request->validated('reason'),
            $request->validated('details')
        );

        return redirect()->back()->with('success', 'Thanks — this has been reported to the moderators.');
    }

    public function reportReply(ReportContentRequest $request, string $slug, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrgBySlug($slug);
        $this->assertOrgScope($org, $discussion);

        if ((int) $reply->discussion_id !== (int) $discussion->id) {
            abort(404);
        }

        $this->authorize('report', $discussion);

        $this->discussions->report(
            $reply,
            Auth::user(),
            $request->validated('reason'),
            $request->validated('details')
        );

        return redirect()->back()->with('success', 'Thanks — this has been reported to the moderators.');
    }

    private function assertOrgScope(Organization $org, Model $model): void
    {
        if ((int) $model->organization_id !== (int) $org->id) {
            abort(404);
        }
    }
}
