<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommunicationHub\ModerateRequest;
use App\Http\Requests\CommunicationHub\ReportContentRequest;
use App\Http\Requests\CommunicationHub\StoreDiscussionReplyRequest;
use App\Http\Requests\CommunicationHub\StoreDiscussionRequest;
use App\Http\Requests\CommunicationHub\UpdateDiscussionRequest;
use App\Models\Organization;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\OrganizationDiscussionFollow;
use App\Models\OrganizationDiscussionReply;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use App\Services\CommunicationHub\CommunicationHubPresenter;
use App\Services\CommunicationHub\OrganizationDiscussionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrganizationDiscussionController extends Controller
{
    public function __construct(
        private readonly OrganizationDiscussionService $discussions,
        private readonly CommunicationHubPermissionService $permissions,
        private readonly CommunicationHubPresenter $presenter,
    ) {}

    /**
     * Resolve the authenticated user's organisation (or abort 403).
     */
    protected function resolveOrg(): Organization
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthenticated.');
        }

        $org = Organization::forAuthUser($user);

        if (! $org) {
            abort(403, 'No organization linked to your account.');
        }

        return $org;
    }

    /**
     * Guard against cross-organization access via route-model-bound discussions/replies.
     */
    private function assertOrgScope(Organization $org, Model $model): void
    {
        if ((int) $model->organization_id !== (int) $org->id) {
            abort(404);
        }
    }

    public function index(Request $request)
    {
        $org = $this->resolveOrg();
        $this->discussions->ensureDefaultCategories($org);

        $this->authorize('viewAny', [OrganizationDiscussion::class, $org]);

        $discussions = $this->discussions->listForOrganization($org, [
            'tab' => $request->query('tab', 'all'),
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'category_id' => $request->query('category_id'),
            'user_id' => Auth::id(),
            'page' => $request->query('page'),
        ])->through(fn (OrganizationDiscussion $discussion) => $this->presenter->discussionToArray($discussion));

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->withCount('discussions')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Organization/CommunicationHub/Discussions/Index', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'discussions' => $discussions,
            'categories' => $categories->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))->values()->all(),
            'filters' => [
                'tab' => $request->query('tab', 'all'),
                'search' => $request->query('search', ''),
                'sort' => $request->query('sort', 'newest'),
                'category_id' => $request->query('category_id'),
            ],
            'can' => [
                'create' => $this->permissions->canCreateDiscussion(Auth::user(), $org),
                'moderate' => $this->permissions->canModerateDiscussions(Auth::user(), $org),
            ],
        ]);
    }

    public function create()
    {
        $org = $this->resolveOrg();
        $this->authorize('create', [OrganizationDiscussion::class, $org]);

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Organization/CommunicationHub/Discussions/Create', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'categories' => $categories->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))->values()->all(),
        ]);
    }

    public function store(StoreDiscussionRequest $request)
    {
        $org = $this->resolveOrg();
        $this->authorize('create', [OrganizationDiscussion::class, $org]);

        $data = $request->validated();
        $data['attachments'] = $request->file('attachments', []);

        $discussion = $this->discussions->create(Auth::user(), $org, $data);

        return redirect()->route('org.communication-hub.discussions.show', $discussion->slug)
            ->with('success', 'Discussion created successfully.');
    }

    public function show(Request $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('view', $discussion);

        $this->discussions->recordView($discussion, Auth::user(), $request->ip());

        $discussion->load(['author', 'category'])
            ->loadCount('replies');

        $replies = OrganizationDiscussionReply::query()
            ->where('discussion_id', $discussion->id)
            ->whereNull('parent_id')
            ->with(['user', 'children.user'])
            ->oldest()
            ->get();

        $follow = OrganizationDiscussionFollow::query()
            ->where('discussion_id', $discussion->id)
            ->where('user_id', Auth::id())
            ->first();

        return Inertia::render('Organization/CommunicationHub/Discussions/Show', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'discussion' => $this->presenter->discussionToArray($discussion),
            'replies' => $replies->map(fn (OrganizationDiscussionReply $r) => $this->presenter->replyToArray($r))->values()->all(),
            'isFollowing' => (bool) $follow,
            'isMuted' => (bool) ($follow?->is_muted),
            'can' => [
                'update' => Gate::allows('update', $discussion),
                'delete' => Gate::allows('delete', $discussion),
                'reply' => Gate::allows('reply', $discussion),
                'react' => Gate::allows('react', $discussion),
                'moderate' => Gate::allows('moderate', $discussion),
                'report' => Gate::allows('report', $discussion),
            ],
            'reportReasons' => config('communication_hub.report_reasons', []),
            'reactionEmojis' => config('communication_hub.reaction_emojis', []),
        ]);
    }

    public function edit(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('update', $discussion);

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Organization/CommunicationHub/Discussions/Edit', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'discussion' => $this->presenter->discussionToArray($discussion->load(['author', 'category'])),
            'categories' => $categories->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))->values()->all(),
        ]);
    }

    public function update(UpdateDiscussionRequest $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('update', $discussion);

        $data = $request->validated();
        $data['attachments'] = $request->file('attachments', []);

        $discussion = $this->discussions->update($discussion, Auth::user(), $data);

        return redirect()->route('org.communication-hub.discussions.show', $discussion->slug)
            ->with('success', 'Discussion updated successfully.');
    }

    /**
     * Discussions are never permanently deleted — always soft deleted.
     */
    public function destroy(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('delete', $discussion);

        $this->discussions->softDelete($discussion, Auth::user());

        return redirect()->route('org.communication-hub.discussions.index')
            ->with('success', 'Discussion deleted.');
    }

    public function reply(StoreDiscussionReplyRequest $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
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

    public function updateReply(Request $request, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrg();
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

    public function destroyReply(OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrg();
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

    public function react(Request $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('react', $discussion);

        $validated = $request->validate([
            'emoji' => ['required', 'string'],
        ]);

        $active = $this->discussions->react($discussion, Auth::user(), $validated['emoji']);

        return redirect()->back()->with('success', $active ? 'Reaction added.' : 'Reaction removed.');
    }

    public function reactReply(Request $request, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrg();
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

    public function follow(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('follow', $discussion);

        $this->discussions->follow($discussion, Auth::user());

        return redirect()->back()->with('success', 'You are now following this discussion.');
    }

    public function unfollow(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);

        $this->discussions->unfollow($discussion, Auth::user());

        return redirect()->back()->with('success', 'You unfollowed this discussion.');
    }

    public function mute(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);

        $this->discussions->mute($discussion, Auth::user());

        return redirect()->back()->with('success', 'Notifications muted for this discussion.');
    }

    public function unmute(OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);

        $this->discussions->unmute($discussion, Auth::user());

        return redirect()->back()->with('success', 'Notifications unmuted for this discussion.');
    }

    public function moderate(ModerateRequest $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $discussion);
        $this->authorize('moderate', $discussion);

        /** @var User $actor */
        $actor = Auth::user();
        $action = $request->validated('action');
        $reason = $request->validated('reason');

        match ($action) {
            'pin' => $this->discussions->pin($discussion, $actor),
            'unpin' => $this->discussions->unpin($discussion, $actor),
            'lock' => $this->discussions->lock($discussion, $actor),
            'unlock' => $this->discussions->unlock($discussion, $actor),
            'hide' => $this->discussions->hide($discussion, $actor, $reason),
            'restore' => $this->discussions->restore($discussion, $actor),
            'archive' => $this->discussions->archive($discussion, $actor),
            'suspend_posting' => $this->discussions->suspendPosting($discussion, $actor, true),
            'unsuspend_posting' => $this->discussions->suspendPosting($discussion, $actor, false),
            'approve' => $this->discussions->approve($discussion, $actor),
            'delete' => $this->discussions->softDelete($discussion, $actor),
            default => throw ValidationException::withMessages(['action' => 'Unsupported moderation action for discussions.']),
        };

        return redirect()->back()->with('success', 'Discussion updated.');
    }

    public function report(ReportContentRequest $request, OrganizationDiscussion $discussion)
    {
        $org = $this->resolveOrg();
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

    public function reportReply(ReportContentRequest $request, OrganizationDiscussion $discussion, OrganizationDiscussionReply $reply)
    {
        $org = $this->resolveOrg();
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
}
