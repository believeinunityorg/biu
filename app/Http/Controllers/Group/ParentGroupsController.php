<?php

namespace App\Http\Controllers\Group;

use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Services\GroupEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ParentGroupsController extends Controller
{
    public function __construct(
        private readonly GroupEligibilityService $eligibility,
    ) {}

    public function organizationIndex(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::forAuthUser($user);
        $alliance = null;

        if (! $organization && $user->hasRole('care_alliance')) {
            $alliance = CareAlliance::query()->where('creator_user_id', $user->id)->first();
        }

        // Supporters: show groups they created or joined
        if (! $organization && ! $alliance) {
            return $this->renderMemberGroupsIndex($user);
        }

        return $this->renderParentGroupsIndex($user, $organization ?? $alliance);
    }

    /**
     * Unity Impact Alliance workspace: manage groups under the alliance parent.
     */
    public function careAllianceIndex(Request $request): Response
    {
        $user = $request->user();
        $alliance = CareAlliance::query()->where('creator_user_id', $user->id)->first();

        if (! $alliance) {
            abort(404, 'Unity Impact Alliance not found for this account.');
        }

        return $this->renderParentGroupsIndex($user, $alliance);
    }

    private function renderMemberGroupsIndex(User $user): Response
    {
        $groups = Group::query()
            ->where('status', 'active')
            ->where(function ($q) use ($user) {
                $q->where('creator_user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('membership_status', 'active'));
            })
            ->with('parent')
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('Group/Index', [
            'parent' => null,
            'canCreate' => false,
            'createUrl' => null,
            'groups' => $groups->map(fn (Group $g) => $this->groupCard($g, $user))->values(),
            'title' => 'My Community Groups',
            'canManageParent' => false,
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    private function renderParentGroupsIndex(User $user, Organization|CareAlliance $parent): Response
    {
        $parentType = $parent instanceof Organization
            ? MembershipAccountType::Organization->value
            : MembershipAccountType::UnityImpactAlliance->value;

        $groups = Group::query()
            ->where('parent_type', $parentType)
            ->where('parent_id', $parent->id)
            ->where('status', 'active')
            ->with('parent')
            ->withCount('members')
            ->orderByDesc('is_pinned')
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->get();

        $canCreate = $this->eligibility->canCreateGroupUnder($user, $parent);
        $title = $parent instanceof CareAlliance
            ? 'Unity Impact Alliance Groups'
            : 'Organization Groups';

        return Inertia::render('Group/Index', [
            'parent' => [
                'type' => $parentType,
                'id' => $parent->id,
                'name' => $parent->name,
            ],
            'canCreate' => $canCreate,
            'createUrl' => $canCreate
                ? route('groups.create', [
                    'parent_type' => $parentType,
                    'parent_id' => $parent->id,
                    'org_dashboard' => 1,
                ])
                : null,
            'groups' => $groups->map(fn (Group $g) => $this->groupCard($g, $user))->values(),
            'title' => $title,
            'canManageParent' => true,
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupCard(Group $group, User $user): array
    {
        $canEdit = Gate::forUser($user)->allows('update', $group);
        $canDelete = Gate::forUser($user)->allows('delete', $group);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'description' => $group->description,
            'category' => $group->category,
            'cover_image' => $group->cover_image ? asset('storage/'.$group->cover_image) : null,
            'is_featured' => (bool) $group->is_featured,
            'is_pinned' => (bool) $group->is_pinned,
            'is_hidden_on_parent' => (bool) $group->is_hidden_on_parent,
            'members_count' => $group->members_count ?? $group->members()->count(),
            'url' => route('groups.show', $group),
            'edit_url' => $canEdit ? route('groups.settings', $group) : null,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'parent_name' => $group->relationLoaded('parent') ? ($group->parent->name ?? null) : null,
        ];
    }
}
