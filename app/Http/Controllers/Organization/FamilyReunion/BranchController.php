<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Enums\FamilyBranchStatus;
use App\Models\FamilyBranch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);

        $branches = FamilyBranch::query()
            ->forOrganization($organization->id)
            ->with(['headMember:id,full_name,photo', 'adminUser:id,name,email'])
            ->withCount(['members' => fn ($q) => $q->visible()])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FamilyBranch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'status' => $b->status?->value ?? $b->status,
                'sort_order' => $b->sort_order,
                'members_count' => $b->members_count,
                'head_member' => $b->headMember ? [
                    'id' => $b->headMember->id,
                    'full_name' => $b->headMember->full_name,
                    'photo_url' => $b->headMember->photo_url,
                ] : null,
                'admin_user' => $b->adminUser ? [
                    'id' => $b->adminUser->id,
                    'name' => $b->adminUser->name,
                    'email' => $b->adminUser->email,
                ] : null,
            ]);

        return Inertia::render('Organization/FamilyReunion/Branches', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'branches' => $branches,
            'has_founders' => (bool) $organization->familyFounder,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        if (! $organization->familyFounder) {
            return back()->with('error', 'Add the founding couple before creating branches.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'head_member_full_name' => ['nullable', 'string', 'max:255'],
            'head_member_id' => [
                'nullable',
                'integer',
                'exists:family_members,id',
            ],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->familyReunion->createBranch($organization, $validated, $request->user());

        return back()->with('success', 'Family branch created.');
    }

    public function update(Request $request, FamilyBranch $branch): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->assertBranch($organization->id, $branch);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:'.implode(',', FamilyBranchStatus::values())],
            'admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('admin_user_id', $validated) && $validated['admin_user_id']) {
            User::query()->findOrFail($validated['admin_user_id']);
        }

        $branch->fill($validated)->save();

        return back()->with('success', 'Branch updated.');
    }

    private function assertBranch(int $organizationId, FamilyBranch $branch): void
    {
        if ((int) $branch->organization_id !== $organizationId) {
            abort(404);
        }
    }
}
