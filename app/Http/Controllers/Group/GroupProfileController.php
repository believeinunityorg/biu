<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\MembershipAccountService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupProfileController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function show(Request $request, Group $group): Response
    {
        $group->load('parent');
        $canManage = $request->user() !== null && $group->isManagedBy($request->user());

        return Inertia::render('Group/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'memberships_enabled' => (bool) $group->memberships_enabled,
                'parent' => $this->parentPayload($group),
            ],
            'canManage' => $canManage,
            'membershipRoute' => $group->memberships_enabled && $canManage
                ? route('groups.membership.index', $group)
                : null,
        ]);
    }

    public function edit(Request $request, Group $group): Response
    {
        $this->membershipAccountService->authorizeManagement($request->user(), $group);

        return Inertia::render('Group/ProfileSettings', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
            ],
            'membership' => $this->membershipAccountService->settingsPayload($group),
            'membershipRoute' => route('groups.membership.index', $group),
        ]);
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
            'name' => $parent->name ?? null,
        ];
    }
}
