<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommunicationHub\ModerateRequest;
use App\Http\Requests\CommunicationHub\StoreAnnouncementCommentRequest;
use App\Http\Requests\CommunicationHub\StoreAnnouncementRequest;
use App\Http\Requests\CommunicationHub\UpdateAnnouncementRequest;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationAnnouncementComment;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use App\Services\CommunicationHub\CommunicationHubPresenter;
use App\Services\CommunicationHub\OrganizationAnnouncementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrganizationAnnouncementController extends Controller
{
    public function __construct(
        private readonly OrganizationAnnouncementService $announcements,
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
     * Guard against cross-organization access via route-model-bound announcements/comments.
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

        $this->authorize('viewAny', [OrganizationAnnouncement::class, $org]);

        $announcements = $this->announcements->listForOrganization($org, [
            'filter' => $request->query('filter', 'all'),
            'search' => $request->query('search'),
            'page' => $request->query('page'),
        ])->through(fn (OrganizationAnnouncement $announcement) => $this->presenter->announcementToArray($announcement));

        return Inertia::render('Organization/CommunicationHub/Announcements/Index', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'announcements' => $announcements,
            'filters' => [
                'filter' => $request->query('filter', 'all'),
                'search' => $request->query('search', ''),
            ],
            'categories' => config('communication_hub.announcement_categories', []),
            'can' => [
                'manage' => $this->permissions->canManageAnnouncements(Auth::user(), $org),
            ],
        ]);
    }

    public function create()
    {
        $org = $this->resolveOrg();

        $this->authorize('create', [OrganizationAnnouncement::class, $org]);

        return Inertia::render('Organization/CommunicationHub/Announcements/Create', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'categories' => config('communication_hub.announcement_categories', []),
        ]);
    }

    public function store(StoreAnnouncementRequest $request)
    {
        $org = $this->resolveOrg();

        $this->authorize('create', [OrganizationAnnouncement::class, $org]);

        $data = $request->validated();
        $data['cover_image'] = $request->file('cover_image');
        $data['attachments'] = $request->file('attachments', []);

        $announcement = $this->announcements->create(Auth::user(), $org, $data);

        return redirect()->route('org.communication-hub.announcements.show', $announcement->slug)
            ->with('success', 'Announcement created successfully.');
    }

    public function show(OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);

        $isManager = $this->permissions->canManageAnnouncements(Auth::user(), $org);

        // The route binding intentionally includes trashed announcements (so managers can
        // reach them to restore); non-managers must never see a soft-deleted announcement.
        if ($announcement->trashed() && ! $isManager) {
            abort(404);
        }

        $this->authorize('view', $announcement);

        $announcement->load(['creator', 'updater', 'publisher'])
            ->loadCount(['comments' => fn ($q) => $q->where('is_hidden', false)]);

        $comments = OrganizationAnnouncementComment::query()
            ->where('announcement_id', $announcement->id)
            ->whereNull('parent_id')
            ->when(! $isManager, fn ($q) => $q->where('is_hidden', false))
            ->with(['user', 'children' => function ($q) use ($isManager) {
                $q->when(! $isManager, fn ($qq) => $qq->where('is_hidden', false))->with('user');
            }])
            ->oldest()
            ->get();

        return Inertia::render('Organization/CommunicationHub/Announcements/Show', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'announcement' => $this->presenter->announcementToArray($announcement),
            'comments' => $comments->map(fn (OrganizationAnnouncementComment $c) => $this->presenter->commentToArray($c))->values()->all(),
            'history' => $isManager
                ? $this->announcements->history($announcement)->map(fn ($h) => [
                    'id' => $h->id,
                    'title' => $h->title,
                    'message' => $h->message,
                    'meta' => $h->meta,
                    'editor' => $h->editor ? ['id' => $h->editor->id, 'name' => $h->editor->name] : null,
                    'created_at' => $h->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'can' => [
                'manage' => $isManager,
                'comment' => $this->permissions->canCommentOnAnnouncements(Auth::user(), $org),
            ],
        ]);
    }

    public function edit(OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);
        $this->authorize('update', $announcement);

        return Inertia::render('Organization/CommunicationHub/Announcements/Edit', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'announcement' => $this->presenter->announcementToArray($announcement->load(['creator', 'updater'])),
            'categories' => config('communication_hub.announcement_categories', []),
        ]);
    }

    public function update(UpdateAnnouncementRequest $request, OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);
        $this->authorize('update', $announcement);

        $data = $request->validated();
        $data['cover_image'] = $request->file('cover_image');
        $data['attachments'] = $request->file('attachments', []);

        $announcement = $this->announcements->update($announcement, Auth::user(), $data);

        return redirect()->route('org.communication-hub.announcements.show', $announcement->slug)
            ->with('success', 'Announcement updated successfully.');
    }

    /**
     * Announcements are never permanently deleted — always soft deleted.
     */
    public function destroy(OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);
        $this->authorize('delete', $announcement);

        $this->announcements->softDelete($announcement, Auth::user());

        return redirect()->route('org.communication-hub.announcements.index')
            ->with('success', 'Announcement deleted.');
    }

    public function moderate(ModerateRequest $request, OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);

        /** @var User $actor */
        $actor = Auth::user();
        $action = $request->validated('action');
        $reason = $request->validated('reason');

        $this->authorizeAnnouncementAction($action, $announcement);

        match ($action) {
            'publish' => $this->announcements->publish($announcement, $actor),
            'schedule' => $this->announcements->schedule($announcement, $actor, $request->validated('scheduled_at')),
            'archive' => $this->announcements->archive($announcement, $actor, $reason),
            'restore' => $this->announcements->restore($announcement, $actor),
            'restore_deleted' => $this->announcements->restoreDeleted($announcement->id, $actor),
            'hide' => $this->announcements->hide($announcement, $actor, $reason),
            'pin' => $this->announcements->pin($announcement, $actor),
            'unpin' => $this->announcements->unpin($announcement, $actor),
            'toggle_comments' => $this->announcements->toggleComments($announcement, $actor),
            'delete' => $this->announcements->softDelete($announcement, $actor, $reason),
            default => throw ValidationException::withMessages(['action' => 'Unsupported moderation action for announcements.']),
        };

        return redirect()->back()->with('success', 'Announcement updated.');
    }

    public function storeComment(StoreAnnouncementCommentRequest $request, OrganizationAnnouncement $announcement)
    {
        $org = $this->resolveOrg();
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

    public function updateComment(Request $request, OrganizationAnnouncement $announcement, OrganizationAnnouncementComment $comment)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);

        if ((int) $comment->announcement_id !== (int) $announcement->id) {
            abort(404);
        }

        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->announcements->updateComment($comment, Auth::user(), $validated['body']);

        return redirect()->back()->with('success', 'Comment updated.');
    }

    public function destroyComment(OrganizationAnnouncement $announcement, OrganizationAnnouncementComment $comment)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);

        if ((int) $comment->announcement_id !== (int) $announcement->id) {
            abort(404);
        }

        $this->authorize('delete', $comment);

        $this->announcements->softDeleteComment($comment, Auth::user());

        return redirect()->back()->with('success', 'Comment deleted.');
    }

    public function moderateComment(ModerateRequest $request, OrganizationAnnouncement $announcement, OrganizationAnnouncementComment $comment)
    {
        $org = $this->resolveOrg();
        $this->assertOrgScope($org, $announcement);

        if ((int) $comment->announcement_id !== (int) $announcement->id) {
            abort(404);
        }

        /** @var User $actor */
        $actor = Auth::user();
        $action = $request->validated('action');
        $reason = $request->validated('reason');

        match ($action) {
            'hide' => $this->authorizeThen('hide', $comment, fn () => $this->announcements->hideComment($comment, $actor, $reason)),
            'restore' => $this->authorizeThen('restore', $comment, fn () => $this->announcements->restoreComment($comment, $actor)),
            'lock' => $this->authorizeThen('hide', $comment, fn () => $this->announcements->lockComments($comment, $actor, true)),
            'unlock' => $this->authorizeThen('hide', $comment, fn () => $this->announcements->lockComments($comment, $actor, false)),
            'delete' => $this->authorizeThen('delete', $comment, fn () => $this->announcements->softDeleteComment($comment, $actor)),
            default => throw ValidationException::withMessages(['action' => 'Unsupported moderation action for comments.']),
        };

        return redirect()->back()->with('success', 'Comment updated.');
    }

    private function authorizeThen(string $ability, Model $model, \Closure $callback): mixed
    {
        $this->authorize($ability, $model);

        return $callback();
    }

    private function authorizeAnnouncementAction(string $action, OrganizationAnnouncement $announcement): void
    {
        match ($action) {
            'publish' => $this->authorize('publish', $announcement),
            'schedule' => $this->authorize('schedule', $announcement),
            'archive' => $this->authorize('archive', $announcement),
            'restore', 'restore_deleted' => $this->authorize('restore', $announcement),
            'hide' => $this->authorize('hide', $announcement),
            'pin', 'unpin' => $this->authorize('pin', $announcement),
            'toggle_comments' => $this->authorize('moderateComments', $announcement),
            'delete' => $this->authorize('delete', $announcement),
            default => throw ValidationException::withMessages(['action' => 'Unsupported moderation action for announcements.']),
        };
    }
}
