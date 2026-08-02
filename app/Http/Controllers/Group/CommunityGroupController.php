<?php

namespace App\Http\Controllers\Group;

use App\Enums\GroupMemberRole;
use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\CommunityContent;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommunityContentService;
use App\Services\CommunityModerationService;
use App\Services\GroupEligibilityService;
use App\Services\MembershipAccountService;
use App\Support\UnityMeetInviteRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommunityGroupController extends Controller
{
    public function __construct(
        private readonly GroupEligibilityService $eligibility,
        private readonly CommunityContentService $contentService,
        private readonly MembershipAccountService $membershipAccountService,
        private readonly CommunityModerationService $moderation,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $parentType = $request->string('parent_type')->toString();
        $parentId = (int) $request->input('parent_id');

        $parent = $this->resolveParent($parentType, $parentId);
        if (! $parent || ! $this->eligibility->canCreateGroupUnder($user, $parent)) {
            return redirect()->back()->with('error', 'You must follow or be a member of this organization or alliance to create a group.');
        }

        return Inertia::render('Group/Create', [
            'parent' => [
                'type' => $this->contentService->parentMorphType($parent),
                'id' => $parent->id,
                'name' => $parent->name ?? 'Community',
            ],
            'categories' => [
                'Bible Study',
                'Volunteer Team',
                'Youth',
                'Support Group',
                'Interest Group',
                'Committee',
                'Project Team',
                'Other',
            ],
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'parent_type' => ['required', 'string', Rule::in(MembershipAccountType::values())],
            'parent_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'cover_image' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ]);

        $parent = $this->resolveParent($validated['parent_type'], (int) $validated['parent_id']);
        if (! $parent || ! $this->eligibility->canCreateGroupUnder($user, $parent)) {
            return redirect()->back()->with('error', 'You are not allowed to create a group here.');
        }

        $coverPath = $request->file('cover_image')->store('groups/covers', 'public');

        $group = DB::transaction(function () use ($validated, $user, $parent, $coverPath) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug !== '' ? $baseSlug : 'group';
            $counter = 1;
            while (Group::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            $group = Group::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'],
                'cover_image' => $coverPath,
                'category' => $validated['category'] ?? null,
                'parent_type' => $this->contentService->parentMorphType($parent),
                'parent_id' => $parent->id,
                'creator_user_id' => $user->id,
                'memberships_enabled' => false,
                'status' => 'active',
            ]);

            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role' => GroupMemberRole::Admin,
                'joined_at' => now(),
            ]);

            return $group;
        });

        return redirect()->route('groups.show', $group)->with('success', 'Group created. You are the Group Administrator.');
    }

    public function show(Request $request, Group $group): Response
    {
        Gate::authorize('view', $group);

        $user = $request->user();
        $group->load(['parent', 'creator:id,name']);
        $membership = $user
            ? GroupMember::query()->where('group_id', $group->id)->where('user_id', $user->id)->first()
            : null;

        $tab = $request->string('tab')->toString() ?: 'discussion';
        $allowedTabs = ['about', 'discussion', 'announcements', 'events', 'photos', 'videos', 'files', 'members'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'discussion';
        }

        $discussions = CommunityContent::query()
            ->where('parent_type', MembershipAccountType::Group->value)
            ->where('parent_id', $group->id)
            ->where('type', 'discussion')
            ->visiblePublic()
            ->with(['author:id,name'])
            ->withCount(['replies' => fn ($q) => $q->where('visibility_status', 'visible')])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get()
            ->map(fn (CommunityContent $c) => $this->contentPayload($c));

        $announcements = CommunityContent::query()
            ->where('parent_type', MembershipAccountType::Group->value)
            ->where('parent_id', $group->id)
            ->where('type', 'announcement')
            ->visiblePublic()
            ->with(['author:id,name'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get()
            ->map(fn (CommunityContent $c) => $this->contentPayload($c));

        $members = GroupMember::query()
            ->where('group_id', $group->id)
            ->with('user:id,name,email')
            ->orderByRaw("FIELD(role, 'admin', 'moderator', 'member')")
            ->orderBy('joined_at')
            ->get()
            ->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'role' => $m->role?->value ?? $m->role,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'posting_suspended' => $m->isPostingSuspended(),
                'user' => [
                    'id' => $m->user?->id,
                    'name' => $m->user?->name,
                    'email' => $m->user?->email,
                ],
            ]);

        $membershipJoin = $this->membershipAccountService->publicJoinPayload($group, $user);
        $canManage = $user && ($group->isManagedBy($user) || ($membership?->role === GroupMemberRole::Admin));
        $canModerate = $user ? Gate::allows('moderate', $group) : false;

        return Inertia::render('Group/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'cover_image' => $group->cover_image ? asset('storage/'.$group->cover_image) : null,
                'category' => $group->category,
                'memberships_enabled' => (bool) $group->memberships_enabled,
                'is_featured' => (bool) $group->is_featured,
                'is_pinned' => (bool) $group->is_pinned,
                'is_hidden_on_parent' => (bool) $group->is_hidden_on_parent,
                'parent' => $this->parentPayload($group),
                'creator' => $group->creator ? ['id' => $group->creator->id, 'name' => $group->creator->name] : null,
                'members_count' => $members->count(),
            ],
            'tab' => $tab,
            'membership' => $membership ? [
                'role' => $membership->role?->value ?? $membership->role,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'posting_suspended' => $membership->isPostingSuspended(),
            ] : null,
            'canManage' => (bool) $canManage,
            'canModerate' => $canModerate,
            'canJoin' => $user ? Gate::allows('join', $group) : false,
            'canLeave' => $user ? Gate::allows('leave', $group) : false,
            'canCreateDiscussion' => $user && $membership && ! $membership->isPostingSuspended(),
            'canCreateAnnouncement' => $user ? $this->contentService->canCreateAnnouncement($user, $group) : false,
            'canFeatureOnParent' => $user ? Gate::allows('featureOnParent', $group) : false,
            'discussions' => $discussions,
            'announcements' => $announcements,
            'members' => $members,
            'membershipJoin' => $membershipJoin,
            'membershipRoute' => $group->memberships_enabled && $canManage
                ? route('groups.membership.index', $group)
                : null,
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    public function settings(Request $request, Group $group): Response
    {
        Gate::authorize('update', $group);

        return Inertia::render('Group/Settings', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'category' => $group->category,
                'cover_image' => $group->cover_image ? asset('storage/'.$group->cover_image) : null,
                'is_featured' => (bool) $group->is_featured,
                'is_pinned' => (bool) $group->is_pinned,
                'is_hidden_on_parent' => (bool) $group->is_hidden_on_parent,
            ],
            'categories' => [
                'Bible Study',
                'Volunteer Team',
                'Youth',
                'Support Group',
                'Interest Group',
                'Committee',
                'Project Team',
                'Other',
            ],
        ]);
    }

    public function updateSettings(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ]);

        $updates = [
            'name' => $validated['name'],
            'description' => $validated['description'],
            'category' => $validated['category'] ?? null,
        ];

        if ($request->hasFile('cover_image')) {
            $updates['cover_image'] = $request->file('cover_image')->store('groups/covers', 'public');
        }

        $group->update($updates);

        return redirect()->route('groups.show', $group)->with('success', 'Group settings updated.');
    }

    public function join(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('join', $group);
        $user = $request->user();

        GroupMember::firstOrCreate(
            ['group_id' => $group->id, 'user_id' => $user->id],
            ['role' => GroupMemberRole::Member, 'joined_at' => now()]
        );

        return redirect()->route('groups.show', ['group' => $group, 'tab' => 'discussion'])
            ->with('success', 'You joined the group.');
    }

    public function leave(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('leave', $group);

        GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return redirect()->route('groups.show', $group)->with('success', 'You left the group.');
    }

    public function searchInviteRecipients(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('moderate', $group);

        $q = trim((string) $request->query('q', ''));
        $memberIds = GroupMember::query()
            ->where('group_id', $group->id)
            ->pluck('user_id')
            ->all();

        $results = collect(UnityMeetInviteRecipients::search($request->user(), $q))
            ->reject(fn (array $row) => in_array((int) $row['id'], $memberIds, true))
            ->values()
            ->all();

        return response()->json(['results' => $results]);
    }

    public function invite(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('moderate', $group);

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (empty($validated['email']) && empty($validated['user_id'])) {
            return redirect()->back()->with('error', 'Provide an email or user to invite.');
        }

        $invitee = null;
        if (! empty($validated['user_id'])) {
            $invitee = User::find($validated['user_id']);
        } elseif (! empty($validated['email'])) {
            $invitee = User::where('email', $validated['email'])->first();
        }

        if ($invitee && GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $invitee->id)
            ->exists()) {
            return redirect()->back()->with('error', 'That person is already a member of this group.');
        }

        $invite = GroupInvite::create([
            'group_id' => $group->id,
            'inviter_id' => $request->user()->id,
            'invitee_user_id' => $invitee?->id,
            'invitee_email' => $validated['email'] ?? $invitee?->email,
            'status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Invite created. Share link: '.route('groups.invites.accept', $invite->token));
    }

    public function acceptInvite(Request $request, string $token): RedirectResponse
    {
        $invite = GroupInvite::query()->where('token', $token)->where('status', 'pending')->firstOrFail();
        $user = $request->user();

        if ($invite->invitee_user_id && (int) $invite->invitee_user_id !== (int) $user->id) {
            return redirect()->route('groups.show', $invite->group)->with('error', 'This invite is for another user.');
        }

        if ($invite->invitee_email && strcasecmp($invite->invitee_email, (string) $user->email) !== 0 && ! $invite->invitee_user_id) {
            // allow if email not set to specific other user
        }

        GroupMember::firstOrCreate(
            ['group_id' => $invite->group_id, 'user_id' => $user->id],
            ['role' => GroupMemberRole::Member, 'joined_at' => now()]
        );

        $invite->update(['status' => 'accepted', 'accepted_at' => now(), 'invitee_user_id' => $user->id]);

        return redirect()->route('groups.show', $invite->group)->with('success', 'Invite accepted. Welcome to the group!');
    }

    public function updateMemberRole(Request $request, Group $group, GroupMember $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $group);

        if ((int) $member->group_id !== (int) $group->id) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'moderator', 'member'])],
        ]);

        if ($member->role === GroupMemberRole::Admin && $validated['role'] !== 'admin') {
            $adminCount = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('role', GroupMemberRole::Admin)
                ->count();
            if ($adminCount <= 1) {
                return redirect()->back()->with('error', 'Cannot demote the last administrator.');
            }
        }

        $member->update(['role' => $validated['role']]);

        return redirect()->back()->with('success', 'Member role updated.');
    }

    public function removeMember(Request $request, Group $group, GroupMember $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $group);

        if ((int) $member->group_id !== (int) $group->id) {
            abort(404);
        }

        if ($member->role === GroupMemberRole::Admin) {
            $adminCount = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('role', GroupMemberRole::Admin)
                ->count();
            if ($adminCount <= 1) {
                return redirect()->back()->with('error', 'Cannot remove the last administrator.');
            }
        }

        $member->delete();

        return redirect()->back()->with('success', 'Member removed.');
    }

    public function suspendMember(Request $request, Group $group, GroupMember $member): RedirectResponse
    {
        Gate::authorize('moderate', $group);

        if ((int) $member->group_id !== (int) $group->id) {
            abort(404);
        }

        if ($member->role === GroupMemberRole::Admin) {
            return redirect()->back()->with('error', 'Cannot suspend an administrator.');
        }

        $validated = $request->validate([
            'suspend' => ['required', 'boolean'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->boolean('suspend')) {
            $until = now()->addDays((int) ($validated['days'] ?? 7));
            $this->moderation->suspendMemberPosting(
                $member,
                $request->user(),
                $until,
                $validated['reason'] ?? 'Posting suspended by moderator'
            );

            return redirect()->back()->with('success', 'Member posting suspended.');
        }

        $this->moderation->restoreMemberPosting(
            $member,
            $request->user(),
            $validated['reason'] ?? 'Posting restored by moderator'
        );

        return redirect()->back()->with('success', 'Member posting restored.');
    }

    public function updateParentControls(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('featureOnParent', $group);

        $validated = $request->validate([
            'is_featured' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_hidden_on_parent' => ['sometimes', 'boolean'],
        ]);

        $group->update([
            'is_featured' => $request->boolean('is_featured', $group->is_featured),
            'is_pinned' => $request->boolean('is_pinned', $group->is_pinned),
            'is_hidden_on_parent' => $request->boolean('is_hidden_on_parent', $group->is_hidden_on_parent),
        ]);

        return redirect()->back()->with('success', 'Organization/Alliance group controls updated.');
    }

    private function resolveParent(string $type, int $id): Organization|CareAlliance|null
    {
        return match ($type) {
            MembershipAccountType::Organization->value, 'Organization' => Organization::find($id),
            MembershipAccountType::UnityImpactAlliance->value, 'UnityImpactAlliance', 'CareAlliance' => CareAlliance::find($id),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parentPayload(Group $group): ?array
    {
        $parent = $group->parent;
        if ($parent === null) {
            return null;
        }

        return [
            'type' => $group->parent_type?->value,
            'id' => $parent->id,
            'name' => $parent->name ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPayload(CommunityContent $content): array
    {
        return [
            'id' => $content->id,
            'type' => $content->type?->value ?? $content->type,
            'title' => $content->title,
            'body' => $content->body,
            'category' => $content->category,
            'is_pinned' => (bool) $content->is_pinned,
            'is_locked' => (bool) $content->is_locked,
            'comments_enabled' => (bool) $content->comments_enabled,
            'published_at' => $content->published_at?->toIso8601String(),
            'replies_count' => $content->replies_count ?? $content->replies()->where('visibility_status', 'visible')->count(),
            'author' => $content->author ? [
                'id' => $content->author->id,
                'name' => $content->author->name,
            ] : null,
            'cover_image' => $content->cover_image ? asset('storage/'.$content->cover_image) : null,
            'attachment_url' => $content->attachment_path ? asset('storage/'.$content->attachment_path) : null,
            'attachment_name' => $content->attachment_name,
        ];
    }
}
