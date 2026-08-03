<?php

namespace App\Http\Controllers\Group;

use App\Enums\GroupMemberRole;
use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\CommunityContent;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupInvite;
use App\Models\GroupLibraryItem;
use App\Models\GroupMember;
use App\Models\GroupPoll;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommunityContentService;
use App\Services\CommunityModerationService;
use App\Services\GroupEligibilityService;
use App\Services\MembershipAccountService;
use App\Mail\GroupInviteMail;
use App\Support\UnityMeetInviteRecipients;
use App\Support\UserEmailCredits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

    public function index(Request $request): Response
    {
        $q = trim($request->string('q')->toString());
        $category = trim($request->string('category')->toString());
        $visibility = $request->string('visibility')->toString();
        if (! in_array($visibility, ['public', 'private'], true)) {
            $visibility = '';
        }
        $sort = $request->string('sort')->toString() ?: 'newest';
        if (! in_array($sort, ['newest', 'most_members', 'name', 'featured'], true)) {
            $sort = 'newest';
        }

        $categories = Group::query()
            ->where('status', 'active')
            ->where('is_hidden_on_parent', false)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values()
            ->all();

        $groups = Group::query()
            ->where('status', 'active')
            ->where('is_hidden_on_parent', false)
            ->where(function ($visibilityQuery) {
                $visibilityQuery
                    ->whereNull('visibility')
                    ->orWhere('visibility', 'public')
                    ->orWhere('visibility', 'private');
            })
            ->when($visibility !== '', fn ($query) => $query->where('visibility', $visibility))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner
                        ->where('name', 'like', '%'.$q.'%')
                        ->orWhere('category', 'like', '%'.$q.'%')
                        ->orWhere('description', 'like', '%'.$q.'%');
                });
            })
            ->with('parent')
            ->withCount(['members' => fn ($m) => $m->where('membership_status', 'active')])
            ->when($sort === 'most_members', fn ($query) => $query->orderByDesc('members_count')->orderBy('name'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name'))
            ->when($sort === 'featured', fn ($query) => $query->orderByDesc('is_featured')->orderByDesc('is_pinned')->orderByDesc('created_at'))
            ->when($sort === 'newest', fn ($query) => $query->orderByDesc('created_at'))
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'category' => $group->category,
                'description_preview' => $group->description
                    ? \Illuminate\Support\Str::limit(strip_tags($group->description), 140)
                    : null,
                'cover_image' => $group->cover_image ? asset('storage/'.$group->cover_image) : null,
                'icon_image' => $group->icon_image ? asset('storage/'.$group->icon_image) : null,
                'visibility' => $group->visibility ?? 'public',
                'members_count' => (int) ($group->members_count ?? 0),
                'url' => route('groups.show', $group),
                'parent_name' => $group->parent?->name ?? null,
            ]);

        $user = $request->user();
        $canCreate = false;
        $createUrl = null;
        if ($user) {
            $creatableParents = $this->eligibility->creatableParentsFor($user);
            $canCreate = $creatableParents !== [];
            $createUrl = $canCreate ? route('groups.create') : null;
        }

        return Inertia::render('Group/Browse', [
            'seo' => [
                'title' => 'Community Groups',
                'description' => 'Discover Community Groups across Believe In Unity.',
            ],
            'groups' => $groups,
            'categories' => $categories,
            'searchQuery' => $q,
            'filters' => [
                'category' => $category,
                'visibility' => $visibility,
                'sort' => $sort,
            ],
            'canCreate' => $canCreate,
            'createUrl' => $createUrl,
            'authUser' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $ownOrg = Organization::forAuthUser($user);

        // Organization accounts always host under their own org — no host picker.
        if ($ownOrg) {
            $parents = [[
                'type' => MembershipAccountType::Organization->value,
                'id' => (int) $ownOrg->id,
                'name' => (string) $ownOrg->name,
                'label' => 'Organization',
            ]];
            $parent = $ownOrg;
        } else {
            // Supporters choose an org / Unity Impact Alliance they follow or belong to.
            $parents = $this->eligibility->creatableParentsFor($user);

            if ($parents === []) {
                return redirect()
                    ->route('groups')
                    ->with('error', 'Follow or join an organization or Unity Impact Alliance to create a group.');
            }

            $parentType = $request->string('parent_type')->toString();
            $parentId = (int) $request->input('parent_id');
            $parent = null;

            if ($parentType !== '' && $parentId > 0) {
                $parent = $this->resolveParent($parentType, $parentId);
                if (! $parent || ! $this->eligibility->canCreateGroupUnder($user, $parent)) {
                    return redirect()
                        ->route('groups.create')
                        ->with('error', 'You must follow or be a member of this organization or alliance to create a group.');
                }
            } elseif (count($parents) === 1) {
                $only = $parents[0];
                $parent = $this->resolveParent($only['type'], (int) $only['id']);
            }
        }

        $parentPayload = null;
        if ($parent) {
            $parentPayload = [
                'type' => $parent instanceof CareAlliance
                    ? MembershipAccountType::UnityImpactAlliance->value
                    : MembershipAccountType::Organization->value,
                'id' => $parent->id,
                'name' => $parent->name ?? 'Organization',
                'label' => $parent instanceof CareAlliance ? 'Unity Impact Alliance' : 'Organization',
            ];
        }

        $fromOrgDashboard = $request->boolean('org_dashboard');
        $backUrl = route('groups');
        if ($fromOrgDashboard && $parent instanceof CareAlliance) {
            $backUrl = url('/care-alliance/workspace/groups');
        } elseif ($fromOrgDashboard) {
            $backUrl = route('organization.groups.index');
        }

        return Inertia::render('Group/Create', [
            'parent' => $parentPayload,
            'parents' => $parents,
            'categories' => array_values(config('community.group_categories', [])),
            'visibilityOptions' => config('community.group_visibility', []),
            'joinPolicyOptions' => config('community.group_join_policies', []),
            'postingPolicyOptions' => config('community.group_posting_policies', []),
            'ruleExamples' => array_values(config('community.group_rule_examples', [])),
            'reportReasons' => config('community.report_reasons'),
            'backUrl' => $backUrl,
            'consumer' => ! $fromOrgDashboard,
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
            'category' => ['required', 'string', Rule::in(config('community.group_categories', []))],
            'visibility' => ['required', 'string', Rule::in(array_keys(config('community.group_visibility', [])))],
            'join_policy' => ['required', 'string', Rule::in(array_keys(config('community.group_join_policies', [])))],
            'posting_policy' => ['required', 'string', Rule::in(array_keys(config('community.group_posting_policies', [])))],
            'rules' => ['nullable', 'array', 'max:20'],
            'rules.*' => ['string', 'max:255'],
            'allow_photos' => ['sometimes', 'boolean'],
            'allow_videos' => ['sometimes', 'boolean'],
            'allow_documents' => ['sometimes', 'boolean'],
            'allow_polls' => ['sometimes', 'boolean'],
            'allow_events' => ['sometimes', 'boolean'],
            'cover_image' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'icon_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
        ]);

        $ownOrg = Organization::forAuthUser($user);
        if ($ownOrg) {
            $parent = $ownOrg;
        } else {
            $parent = $this->resolveParent($validated['parent_type'], (int) $validated['parent_id']);
            if (! $parent || ! $this->eligibility->canCreateGroupUnder($user, $parent)) {
                return redirect()->back()->with('error', 'You are not allowed to create a group here.');
            }
        }

        $visibility = $validated['visibility'];
        $joinPolicy = $validated['join_policy'];
        if ($visibility === 'hidden' && $joinPolicy === 'anyone') {
            $joinPolicy = 'invite_only';
        }

        $rules = collect($validated['rules'] ?? [])
            ->map(fn ($rule) => trim((string) $rule))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $coverPath = $request->file('cover_image')->store('groups/covers', 'public');
        $iconPath = $request->hasFile('icon_image')
            ? $request->file('icon_image')->store('groups/icons', 'public')
            : null;

        $group = DB::transaction(function () use ($validated, $user, $parent, $coverPath, $iconPath, $visibility, $joinPolicy, $rules, $request) {
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
                'icon_image' => $iconPath,
                'category' => $validated['category'],
                'visibility' => $visibility,
                'join_policy' => $joinPolicy,
                'posting_policy' => $validated['posting_policy'],
                'rules' => $rules !== [] ? $rules : null,
                'allow_photos' => $request->boolean('allow_photos', true),
                'allow_videos' => $request->boolean('allow_videos', true),
                'allow_documents' => $request->boolean('allow_documents', true),
                'allow_polls' => $request->boolean('allow_polls', true),
                'allow_events' => $request->boolean('allow_events', true),
                'is_hidden_on_parent' => $visibility === 'hidden',
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
                'membership_status' => 'active',
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
        $activeMembership = $membership && $membership->isActive() ? $membership : null;
        $pendingMembership = $membership && $membership->isPending() ? $membership : null;

        $tab = $request->string('tab')->toString() ?: 'discussion';
        $allowedTabs = ['about', 'discussion', 'announcements', 'events', 'photos', 'videos', 'files', 'polls', 'members'];
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'discussion';
        }

        if ($tab === 'polls' && ! ($group->allow_polls ?? true)) {
            $tab = 'about';
        }
        if ($tab === 'photos' && ! ($group->allow_photos ?? true)) {
            $tab = 'about';
        }
        if ($tab === 'videos' && ! ($group->allow_videos ?? true)) {
            $tab = 'about';
        }
        if ($tab === 'files' && ! ($group->allow_documents ?? true)) {
            $tab = 'about';
        }
        if ($tab === 'events' && ! ($group->allow_events ?? true)) {
            $tab = 'about';
        }

        $canViewFeeds = $activeMembership !== null
            || $group->isPublicVisibility()
            || ($user && Gate::allows('moderate', $group));

        if (! $canViewFeeds && in_array($tab, ['discussion', 'announcements', 'members', 'polls', 'photos', 'videos', 'files', 'events'], true)) {
            $tab = 'about';
        }

        $discussions = $canViewFeeds ? CommunityContent::query()
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
            ->map(fn (CommunityContent $c) => $this->contentPayload($c)) : collect();

        $announcements = $canViewFeeds ? CommunityContent::query()
            ->where('parent_type', MembershipAccountType::Group->value)
            ->where('parent_id', $group->id)
            ->where('type', 'announcement')
            ->visiblePublic()
            ->with(['author:id,name'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get()
            ->map(fn (CommunityContent $c) => $this->contentPayload($c)) : collect();

        $members = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('membership_status', 'active')
            ->with('user:id,name,email')
            ->orderByRaw("FIELD(role, 'admin', 'moderator', 'member')")
            ->orderBy('joined_at')
            ->get()
            ->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'role' => $m->role?->value ?? $m->role,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'posting_suspended' => $m->isPostingSuspended(),
                'membership_status' => $m->membership_status ?? 'active',
                'user' => [
                    'id' => $m->user?->id,
                    'name' => $m->user?->name,
                    'email' => $m->user?->email,
                ],
            ]);

        $pendingMembers = [];
        $pendingInvites = [];
        $canModerate = $user ? Gate::allows('moderate', $group) : false;
        if ($canModerate) {
            $pendingMembers = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('membership_status', 'pending')
                ->with('user:id,name,email')
                ->orderBy('created_at')
                ->get()
                ->map(fn (GroupMember $m) => [
                    'id' => $m->id,
                    'role' => $m->role?->value ?? $m->role,
                    'joined_at' => $m->joined_at?->toIso8601String(),
                    'membership_status' => 'pending',
                    'user' => [
                        'id' => $m->user?->id,
                        'name' => $m->user?->name,
                        'email' => $m->user?->email,
                    ],
                ])
                ->all();

            $pendingInvites = GroupInvite::query()
                ->where('group_id', $group->id)
                ->where('status', 'pending')
                ->with(['invitee:id,name,email', 'inviter:id,name'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (GroupInvite $invite) => [
                    'id' => $invite->id,
                    'email' => $invite->invitee_email ?: $invite->invitee?->email,
                    'status' => 'invited',
                    'created_at' => $invite->created_at?->toIso8601String(),
                    'accept_url' => route('groups.invites.accept', $invite->token),
                    'user' => $invite->invitee ? [
                        'id' => $invite->invitee->id,
                        'name' => $invite->invitee->name,
                        'email' => $invite->invitee->email,
                    ] : null,
                    'inviter' => $invite->inviter ? [
                        'id' => $invite->inviter->id,
                        'name' => $invite->inviter->name,
                    ] : null,
                ])
                ->values()
                ->all();
        }

        $membershipJoin = $this->membershipAccountService->publicJoinPayload($group, $user);
        $canManage = $user && ($group->isManagedBy($user) || ($activeMembership?->role === GroupMemberRole::Admin));
        $canCreatePoll = (bool) ($group->allow_polls ?? true)
            && $activeMembership
            && ! $activeMembership->isPostingSuspended();
        $canVotePolls = (bool) ($group->allow_polls ?? true) && (bool) $activeMembership;
        $canContributeLibrary = $canModerate
            || ($activeMembership && ! $activeMembership->isPostingSuspended());
        $canUploadPhotos = $canContributeLibrary && (bool) ($group->allow_photos ?? true);
        $canUploadVideos = $canContributeLibrary && (bool) ($group->allow_videos ?? true);
        $canUploadFiles = $canContributeLibrary && (bool) ($group->allow_documents ?? true);
        $canCreateEvent = $canContributeLibrary && (bool) ($group->allow_events ?? true);

        $polls = [];
        if (($group->allow_polls ?? true) && $canViewFeeds) {
            $polls = GroupPoll::query()
                ->where('group_id', $group->id)
                ->with([
                    'creator:id,name',
                    'options',
                    'votes:id,group_poll_id,group_poll_option_id,user_id',
                ])
                ->orderByDesc('created_at')
                ->limit(40)
                ->get()
                ->map(fn (GroupPoll $poll) => $this->pollPayload($poll, $user, $canModerate))
                ->values()
                ->all();
        }

        $libraryPhotos = [];
        $libraryVideos = [];
        $libraryFiles = [];
        if ($canViewFeeds) {
            if ($group->allow_photos ?? true) {
                $libraryPhotos = GroupLibraryItem::query()
                    ->where('group_id', $group->id)
                    ->where('visibility_status', 'visible')
                    ->where('type', GroupLibraryItem::TYPE_PHOTO)
                    ->with('uploader:id,name')
                    ->orderByDesc('created_at')
                    ->limit(60)
                    ->get()
                    ->map(fn (GroupLibraryItem $item) => $this->libraryPayload($item, $user, $canModerate))
                    ->values()
                    ->all();
            }

            if ($group->allow_videos ?? true) {
                $libraryVideos = GroupLibraryItem::query()
                    ->where('group_id', $group->id)
                    ->where('visibility_status', 'visible')
                    ->where('type', GroupLibraryItem::TYPE_VIDEO)
                    ->with('uploader:id,name')
                    ->orderByDesc('created_at')
                    ->limit(60)
                    ->get()
                    ->map(fn (GroupLibraryItem $item) => $this->libraryPayload($item, $user, $canModerate))
                    ->values()
                    ->all();
            }

            if ($group->allow_documents ?? true) {
                $libraryFiles = GroupLibraryItem::query()
                    ->where('group_id', $group->id)
                    ->where('visibility_status', 'visible')
                    ->where('type', GroupLibraryItem::TYPE_DOCUMENT)
                    ->with('uploader:id,name')
                    ->orderByDesc('created_at')
                    ->limit(60)
                    ->get()
                    ->map(fn (GroupLibraryItem $item) => $this->libraryPayload($item, $user, $canModerate))
                    ->values()
                    ->all();
            }
        }

        $groupEvents = [];
        if (($group->allow_events ?? true) && $canViewFeeds) {
            $groupEvents = GroupEvent::query()
                ->where('group_id', $group->id)
                ->with('creator:id,name')
                ->orderByDesc('start_date')
                ->limit(40)
                ->get()
                ->map(fn (GroupEvent $event) => $this->groupEventPayload($event, $user, $canModerate))
                ->values()
                ->all();
        }

        $recentGroups = [];
        if ($group->parent_type && $group->parent_id) {
            $canSeeHidden = $user && ($group->isManagedBy($user) || $user->hasRole('admin'));
            $recentGroups = Group::query()
                ->where('parent_type', $group->parent_type)
                ->where('parent_id', $group->parent_id)
                ->where('status', 'active')
                ->where('id', '!=', $group->id)
                ->when(
                    ! $canSeeHidden,
                    fn ($q) => $q
                        ->where('is_hidden_on_parent', false)
                        ->where(function ($visibility) {
                            $visibility
                                ->whereNull('visibility')
                                ->orWhere('visibility', 'public')
                                ->orWhere('visibility', 'private');
                        })
                )
                ->withCount(['members' => fn ($m) => $m->where('membership_status', 'active')])
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn (Group $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'slug' => $g->slug,
                    'category' => $g->category,
                    'cover_image' => $g->cover_image ? asset('storage/'.$g->cover_image) : null,
                    'icon_image' => $g->icon_image ? asset('storage/'.$g->icon_image) : null,
                    'visibility' => $g->visibility ?? 'public',
                    'members_count' => (int) ($g->members_count ?? 0),
                    'created_at' => $g->created_at?->toIso8601String(),
                    'url' => route('groups.show', $g),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Group/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'cover_image' => $group->cover_image ? asset('storage/'.$group->cover_image) : null,
                'icon_image' => $group->icon_image ? asset('storage/'.$group->icon_image) : null,
                'category' => $group->category,
                'visibility' => $group->visibility ?? 'public',
                'join_policy' => $group->join_policy ?? 'anyone',
                'posting_policy' => $group->posting_policy ?? 'members',
                'rules' => $group->rulesList(),
                'allow_photos' => (bool) ($group->allow_photos ?? true),
                'allow_videos' => (bool) ($group->allow_videos ?? true),
                'allow_documents' => (bool) ($group->allow_documents ?? true),
                'allow_polls' => (bool) ($group->allow_polls ?? true),
                'allow_events' => (bool) ($group->allow_events ?? true),
                'memberships_enabled' => (bool) $group->memberships_enabled,
                'is_featured' => (bool) $group->is_featured,
                'is_pinned' => (bool) $group->is_pinned,
                'is_hidden_on_parent' => (bool) $group->is_hidden_on_parent,
                'parent' => $this->parentPayload($group),
                'creator' => $group->creator ? ['id' => $group->creator->id, 'name' => $group->creator->name] : null,
                'members_count' => $members->count(),
            ],
            'tab' => $tab,
            'membership' => $activeMembership ? [
                'role' => $activeMembership->role?->value ?? $activeMembership->role,
                'joined_at' => $activeMembership->joined_at?->toIso8601String(),
                'posting_suspended' => $activeMembership->isPostingSuspended(),
                'status' => 'active',
            ] : ($pendingMembership ? [
                'role' => $pendingMembership->role?->value ?? $pendingMembership->role,
                'joined_at' => null,
                'posting_suspended' => false,
                'status' => 'pending',
            ] : null),
            'canManage' => (bool) $canManage,
            'canModerate' => $canModerate,
            'canJoin' => $user ? Gate::allows('join', $group) : false,
            'canLeave' => $user ? Gate::allows('leave', $group) : false,
            'canViewFeeds' => $canViewFeeds,
            'canCreateDiscussion' => $user ? $this->contentService->canCreateDiscussion($user, $group) : false,
            'canCreateAnnouncement' => $user ? $this->contentService->canCreateAnnouncement($user, $group) : false,
            'canFeatureOnParent' => $user ? Gate::allows('featureOnParent', $group) : false,
            'canCreatePoll' => $canCreatePoll,
            'canVotePolls' => $canVotePolls,
            'canUploadPhotos' => $canUploadPhotos,
            'canUploadVideos' => $canUploadVideos,
            'canUploadFiles' => $canUploadFiles,
            'canCreateEvent' => $canCreateEvent,
            'discussions' => $discussions,
            'announcements' => $announcements,
            'polls' => $polls,
            'libraryPhotos' => $libraryPhotos,
            'libraryVideos' => $libraryVideos,
            'libraryFiles' => $libraryFiles,
            'groupEvents' => $groupEvents,
            'members' => $members,
            'pendingMembers' => $pendingMembers,
            'pendingInvites' => $pendingInvites,
            'emailCredits' => $canModerate && $user ? UserEmailCredits::stats($user) : null,
            'recentGroups' => $recentGroups,
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
                'icon_image' => $group->icon_image ? asset('storage/'.$group->icon_image) : null,
                'visibility' => $group->visibility ?? 'public',
                'join_policy' => $group->join_policy ?? 'anyone',
                'posting_policy' => $group->posting_policy ?? 'members',
                'rules' => $group->rulesList(),
                'allow_photos' => (bool) ($group->allow_photos ?? true),
                'allow_videos' => (bool) ($group->allow_videos ?? true),
                'allow_documents' => (bool) ($group->allow_documents ?? true),
                'allow_polls' => (bool) ($group->allow_polls ?? true),
                'allow_events' => (bool) ($group->allow_events ?? true),
                'is_featured' => (bool) $group->is_featured,
                'is_pinned' => (bool) $group->is_pinned,
                'is_hidden_on_parent' => (bool) $group->is_hidden_on_parent,
                'parent' => $this->parentPayload($group),
            ],
            'categories' => array_values(array_unique(array_filter([
                ...config('community.group_categories', []),
                $group->category,
            ]))),
            'visibilityOptions' => config('community.group_visibility', []),
            'joinPolicyOptions' => config('community.group_join_policies', []),
            'postingPolicyOptions' => config('community.group_posting_policies', []),
            'ruleExamples' => array_values(config('community.group_rule_examples', [])),
            'canDelete' => Gate::allows('delete', $group),
        ]);
    }

    public function updateSettings(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => [
                'required',
                'string',
                'max:100',
                Rule::in(array_values(array_unique(array_filter([
                    ...config('community.group_categories', []),
                    $group->category,
                ])))),
            ],
            'visibility' => ['required', 'string', Rule::in(array_keys(config('community.group_visibility', [])))],
            'join_policy' => ['required', 'string', Rule::in(array_keys(config('community.group_join_policies', [])))],
            'posting_policy' => ['required', 'string', Rule::in(array_keys(config('community.group_posting_policies', [])))],
            'rules' => ['nullable', 'array', 'max:20'],
            'rules.*' => ['string', 'max:255'],
            'allow_photos' => ['sometimes', 'boolean'],
            'allow_videos' => ['sometimes', 'boolean'],
            'allow_documents' => ['sometimes', 'boolean'],
            'allow_polls' => ['sometimes', 'boolean'],
            'allow_events' => ['sometimes', 'boolean'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'icon_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
        ]);

        $visibility = $validated['visibility'];
        $joinPolicy = $validated['join_policy'];
        if ($visibility === 'hidden' && $joinPolicy === 'anyone') {
            $joinPolicy = 'invite_only';
        }

        $rules = collect($validated['rules'] ?? [])
            ->map(fn ($rule) => trim((string) $rule))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $updates = [
            'name' => $validated['name'],
            'description' => $validated['description'],
            'category' => $validated['category'],
            'visibility' => $visibility,
            'join_policy' => $joinPolicy,
            'posting_policy' => $validated['posting_policy'],
            'rules' => $rules !== [] ? $rules : null,
            'allow_photos' => $request->boolean('allow_photos', true),
            'allow_videos' => $request->boolean('allow_videos', true),
            'allow_documents' => $request->boolean('allow_documents', true),
            'allow_polls' => $request->boolean('allow_polls', true),
            'allow_events' => $request->boolean('allow_events', true),
            'is_hidden_on_parent' => $visibility === 'hidden' ? true : $group->is_hidden_on_parent,
        ];

        if ($visibility !== 'hidden' && $group->visibility === 'hidden') {
            $updates['is_hidden_on_parent'] = false;
        }

        $oldCover = $group->cover_image;
        $oldIcon = $group->icon_image;
        $deleteCover = false;
        $deleteIcon = false;

        if ($request->hasFile('cover_image')) {
            $updates['cover_image'] = $request->file('cover_image')->store('groups/covers', 'public');
            $deleteCover = filled($oldCover) && $oldCover !== $updates['cover_image'];
        }

        if ($request->hasFile('icon_image')) {
            $updates['icon_image'] = $request->file('icon_image')->store('groups/icons', 'public');
            $deleteIcon = filled($oldIcon) && $oldIcon !== $updates['icon_image'];
        }

        $group->update($updates);

        // Remove replaced files only after the DB points at the new paths.
        if ($deleteCover) {
            Storage::disk('public')->delete($oldCover);
        }
        if ($deleteIcon) {
            Storage::disk('public')->delete($oldIcon);
        }

        return redirect()->route('groups.show', $group)->with('success', 'Group updated.');
    }

    /**
     * Facebook-style cover / icon update from the group page (admins/creators).
     * Replaces the stored file and deletes the previous one from public disk.
     */
    public function updateMedia(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('update', $group);

        $request->validate([
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'icon_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
        ]);

        if (! $request->hasFile('cover_image') && ! $request->hasFile('icon_image')) {
            return redirect()->back()->withErrors([
                'cover_image' => 'Choose a cover photo or group picture to upload.',
            ]);
        }

        $oldCover = $group->cover_image;
        $oldIcon = $group->icon_image;
        $updates = [];
        $deleteCover = false;
        $deleteIcon = false;

        if ($request->hasFile('cover_image')) {
            $updates['cover_image'] = $request->file('cover_image')->store('groups/covers', 'public');
            $deleteCover = filled($oldCover) && $oldCover !== $updates['cover_image'];
        }

        if ($request->hasFile('icon_image')) {
            $updates['icon_image'] = $request->file('icon_image')->store('groups/icons', 'public');
            $deleteIcon = filled($oldIcon) && $oldIcon !== $updates['icon_image'];
        }

        $group->update($updates);

        // Remove replaced files only after the DB points at the new paths.
        if ($deleteCover) {
            Storage::disk('public')->delete($oldCover);
        }
        if ($deleteIcon) {
            Storage::disk('public')->delete($oldIcon);
        }

        $message = $request->hasFile('cover_image') && $request->hasFile('icon_image')
            ? 'Cover photo and group picture updated.'
            : ($request->hasFile('cover_image') ? 'Cover photo updated.' : 'Group picture updated.');

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('delete', $group);

        $group->load('parent');
        $parent = $group->parent;
        $parentType = $group->parent_type;
        $cover = $group->cover_image;
        $icon = $group->icon_image;
        $name = $group->name;

        DB::transaction(function () use ($group) {
            CommunityContent::query()
                ->where('parent_type', MembershipAccountType::Group->value)
                ->where('parent_id', $group->id)
                ->delete();

            $group->delete();
        });

        if ($cover) {
            Storage::disk('public')->delete($cover);
        }
        if ($icon) {
            Storage::disk('public')->delete($icon);
        }

        if ($parent instanceof CareAlliance || $parentType === MembershipAccountType::UnityImpactAlliance) {
            return redirect()
                ->route('care-alliance.workspace.groups')
                ->with('success', "“{$name}” was permanently deleted.");
        }

        return redirect()
            ->route('organization.groups.index')
            ->with('success', "“{$name}” was permanently deleted.");
    }

    public function join(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('join', $group);
        $user = $request->user();

        $policy = $group->join_policy ?? 'anyone';

        if ($policy === 'approval') {
            GroupMember::updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $user->id],
                [
                    'role' => GroupMemberRole::Member,
                    'membership_status' => 'pending',
                    'joined_at' => null,
                ]
            );

            return redirect()->route('groups.show', ['group' => $group, 'tab' => 'about'])
                ->with('success', 'Join request sent. A group admin must approve before you can participate.');
        }

        GroupMember::updateOrCreate(
            ['group_id' => $group->id, 'user_id' => $user->id],
            [
                'role' => GroupMemberRole::Member,
                'membership_status' => 'active',
                'joined_at' => now(),
            ]
        );

        return redirect()->route('groups.show', ['group' => $group, 'tab' => 'discussion'])
            ->with('success', 'You joined the group.');
    }

    public function approveMember(Request $request, Group $group, GroupMember $member): RedirectResponse
    {
        Gate::authorize('moderate', $group);

        if ((int) $member->group_id !== (int) $group->id) {
            abort(404);
        }

        if (! $member->isPending()) {
            return redirect()->back()->with('error', 'That join request is no longer pending.');
        }

        $member->update([
            'membership_status' => 'active',
            'joined_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Join request approved.');
    }

    public function rejectMember(Request $request, Group $group, GroupMember $member): RedirectResponse
    {
        Gate::authorize('moderate', $group);

        if ((int) $member->group_id !== (int) $group->id) {
            abort(404);
        }

        if (! $member->isPending()) {
            return redirect()->back()->with('error', 'That join request is no longer pending.');
        }

        $member->delete();

        return redirect()->back()->with('success', 'Join request declined.');
    }

    public function leave(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('leave', $group);

        $membership = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $wasPending = $membership?->isPending() ?? false;
        $membership?->delete();

        return redirect()->route('groups.show', $group)->with(
            'success',
            $wasPending ? 'Join request cancelled.' : 'You left the group.'
        );
    }

    public function searchInviteRecipients(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('moderate', $group);

        $q = trim((string) $request->query('q', ''));
        $memberIds = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('membership_status', 'active')
            ->pluck('user_id')
            ->all();

        $pendingInvites = GroupInvite::query()
            ->where('group_id', $group->id)
            ->where('status', 'pending')
            ->get(['invitee_user_id', 'invitee_email']);

        $invitedUserIds = $pendingInvites
            ->pluck('invitee_user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $invitedEmails = $pendingInvites
            ->pluck('invitee_email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->all();

        $results = collect(UnityMeetInviteRecipients::search($request->user(), $q))
            ->reject(fn (array $row) => in_array((int) $row['id'], $memberIds, true))
            ->map(function (array $row) use ($invitedUserIds, $invitedEmails) {
                $email = strtolower((string) ($row['email'] ?? ''));
                $alreadyInvited = in_array((int) $row['id'], $invitedUserIds, true)
                    || ($email !== '' && in_array($email, $invitedEmails, true));

                return [
                    ...$row,
                    'already_invited' => $alreadyInvited,
                ];
            })
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

        $inviter = $request->user();
        $invitee = null;
        if (! empty($validated['user_id'])) {
            $invitee = User::find($validated['user_id']);
        } elseif (! empty($validated['email'])) {
            $invitee = User::where('email', $validated['email'])->first();
        }

        if ($invitee && GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $invitee->id)
            ->where('membership_status', 'active')
            ->exists()) {
            return redirect()->back()->with('error', 'That person is already a member of this group.');
        }

        $email = $validated['email'] ?? $invitee?->email;
        if (! $email) {
            return redirect()->back()->with('error', 'An email address is required to send an invite.');
        }

        if (! UserEmailCredits::canSend($inviter)) {
            return redirect()->back()->with('error', UserEmailCredits::insufficientMessage($inviter));
        }

        $existingInvite = GroupInvite::query()
            ->where('group_id', $group->id)
            ->where('status', 'pending')
            ->where(function ($q) use ($invitee, $email) {
                if ($invitee) {
                    $q->where('invitee_user_id', $invitee->id);
                }
                if ($email) {
                    $q->orWhere('invitee_email', $email);
                }
            })
            ->first();

        if ($existingInvite) {
            $sent = $this->sendGroupInviteEmail($group, $existingInvite, $inviter, $email);
            if (! $sent) {
                return redirect()->back()->with(
                    'error',
                    'Invite already exists, but the email could not be sent. Share link: '.route('groups.invites.accept', $existingInvite->token)
                );
            }

            UserEmailCredits::consume($inviter);

            return redirect()->back()->with(
                'success',
                'Invite email resent (1 email credit used). Share link: '.route('groups.invites.accept', $existingInvite->token)
            );
        }

        $invite = GroupInvite::create([
            'group_id' => $group->id,
            'inviter_id' => $inviter->id,
            'invitee_user_id' => $invitee?->id,
            'invitee_email' => $email,
            'status' => 'pending',
        ]);

        $sent = $this->sendGroupInviteEmail($group, $invite, $inviter, $email);
        if (! $sent) {
            return redirect()->back()->with(
                'error',
                'Invite created, but the email could not be sent. No credit was used. Share link: '.route('groups.invites.accept', $invite->token)
            );
        }

        UserEmailCredits::consume($inviter);

        return redirect()->back()->with(
            'success',
            'Invite email sent (1 email credit used). Share link: '.route('groups.invites.accept', $invite->token)
        );
    }

    public function resendInvite(Request $request, Group $group, GroupInvite $invite): RedirectResponse
    {
        Gate::authorize('moderate', $group);

        if ((int) $invite->group_id !== (int) $group->id) {
            abort(404);
        }

        if (($invite->status ?? '') !== 'pending') {
            return redirect()->back()->with('error', 'Only pending invites can be resent.');
        }

        $invite->loadMissing('invitee:id,email');
        $email = $invite->invitee_email ?: $invite->invitee?->email;
        if (! $email) {
            return redirect()->back()->with('error', 'This invite has no email address to resend to.');
        }

        $inviter = $request->user();
        if (! UserEmailCredits::canSend($inviter)) {
            return redirect()->back()->with('error', UserEmailCredits::insufficientMessage($inviter));
        }

        $sent = $this->sendGroupInviteEmail($group, $invite, $inviter, $email);
        if (! $sent) {
            return redirect()->back()->with(
                'error',
                'Invite email could not be sent. Share link: '.route('groups.invites.accept', $invite->token)
            );
        }

        UserEmailCredits::consume($inviter);

        return redirect()->back()->with(
            'success',
            'Invite email resent (1 email credit used). Share link: '.route('groups.invites.accept', $invite->token)
        );
    }

    private function sendGroupInviteEmail(Group $group, GroupInvite $invite, User $inviter, string $email): bool
    {
        try {
            Mail::to($email)->send(new GroupInviteMail($group, $invite, $inviter));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Group invite email failed', [
                'group_id' => $group->id,
                'invite_id' => $invite->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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

        GroupMember::updateOrCreate(
            ['group_id' => $invite->group_id, 'user_id' => $user->id],
            [
                'role' => GroupMemberRole::Member,
                'membership_status' => 'active',
                'joined_at' => now(),
            ]
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
                ->where('membership_status', 'active')
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
                ->where('membership_status', 'active')
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
            'slug' => $content->slug,
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

    /**
     * @return array<string, mixed>
     */
    private function pollPayload(GroupPoll $poll, ?User $user, bool $canModerate = false): array
    {
        $votes = $poll->relationLoaded('votes')
            ? $poll->votes
            : $poll->votes()->get(['id', 'group_poll_id', 'group_poll_option_id', 'user_id']);
        $voteCounts = $votes->groupBy('group_poll_option_id')->map->count();
        $totalVoters = $votes->pluck('user_id')->unique()->count();

        $viewerOptionIds = [];
        if ($user) {
            $viewerOptionIds = $votes
                ->where('user_id', $user->id)
                ->pluck('group_poll_option_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'allow_multiple' => (bool) $poll->allow_multiple,
            'closes_at' => $poll->closes_at?->toIso8601String(),
            'closed_at' => $poll->closed_at?->toIso8601String(),
            'is_closed' => $poll->isClosed(),
            'created_at' => $poll->created_at?->toIso8601String(),
            'total_votes' => $totalVoters,
            'viewer_option_ids' => $viewerOptionIds,
            'viewer_has_voted' => $viewerOptionIds !== [],
            'can_manage' => $user && (
                (int) $poll->creator_user_id === (int) $user->id || $canModerate
            ),
            'creator' => $poll->creator ? [
                'id' => $poll->creator->id,
                'name' => $poll->creator->name,
            ] : null,
            'options' => $poll->options->map(function ($option) use ($voteCounts, $totalVoters) {
                $count = (int) ($voteCounts[$option->id] ?? 0);

                return [
                    'id' => $option->id,
                    'label' => $option->label,
                    'votes_count' => $count,
                    'percent' => $totalVoters > 0 ? (int) round(($count / $totalVoters) * 100) : 0,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryPayload(GroupLibraryItem $item, ?User $user, bool $canModerate = false): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'caption' => $item->caption,
            'original_name' => $item->original_name,
            'mime' => $item->mime,
            'size' => (int) $item->size,
            'url' => $item->url(),
            'created_at' => $item->created_at?->toIso8601String(),
            'can_manage' => $user && (
                (int) $item->uploader_user_id === (int) $user->id || $canModerate
            ),
            'uploader' => $item->uploader ? [
                'id' => $item->uploader->id,
                'name' => $item->uploader->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupEventPayload(GroupEvent $event, ?User $user, bool $canModerate = false): array
    {
        return [
            'id' => $event->id,
            'title' => $event->name,
            'description' => $event->description,
            'starts_at' => $event->start_date?->toIso8601String(),
            'ends_at' => $event->end_date?->toIso8601String(),
            'location' => $event->location,
            'location_url' => $event->location_url ?? null,
            'cover_image' => $event->coverUrl(),
            'status' => $event->status ?? 'upcoming',
            'is_cancelled' => $event->isCancelled(),
            'created_at' => $event->created_at?->toIso8601String(),
            'can_manage' => $user && (
                (int) $event->created_by === (int) $user->id || $canModerate
            ),
            'creator' => $event->creator ? [
                'id' => $event->creator->id,
                'name' => $event->creator->name,
            ] : null,
        ];
    }
}
