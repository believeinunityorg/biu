<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Enums\FamilyMemberStatus;
use App\Models\FamilyBranch;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);
        $branchFilter = $request->integer('branch_id') ?: null;

        $members = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->with(['branch:id,name', 'father:id,full_name', 'mother:id,full_name', 'spouse:id,full_name'])
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->orderBy('generation')
            ->orderBy('full_name')
            ->get()
            ->map(fn (FamilyMember $m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'email' => $m->email,
                'photo_url' => $m->photo_url,
                'generation' => $m->generation,
                'relationship_label' => $m->relationship_label,
                'status' => $m->status?->value ?? $m->status,
                'branch_id' => $m->branch_id,
                'branch' => $m->branch?->name,
                'father_id' => $m->father_id,
                'mother_id' => $m->mother_id,
                'spouse_id' => $m->spouse_id,
                'father_name' => $m->father?->full_name,
                'mother_name' => $m->mother?->full_name,
                'spouse_name' => $m->spouse?->full_name,
                'is_claimed' => $m->is_claimed,
                'is_founding' => $m->is_founding_grandfather || $m->is_founding_grandmother,
            ]);

        return Inertia::render('Organization/FamilyReunion/Members', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'branches' => FamilyBranch::query()
                ->forOrganization($organization->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),
            'members' => $members,
            'filters' => [
                'branch_id' => $branchFilter,
            ],
        ]);
    }

    public function inviteForm(Request $request): Response
    {
        $organization = $this->organization($request);

        return Inertia::render('Organization/FamilyReunion/Invite', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'branches' => FamilyBranch::query()
                ->forOrganization($organization->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:family_branches,id'],
            'relationship_label' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($validated['branch_id'])) {
            $this->assertBelongsToOrg($organization->id, 'branch_id', (int) $validated['branch_id']);
        }

        $this->familyReunion->inviteMember($organization, $validated, $request->user());

        return back()->with('success', 'Invite sent. When they accept, they can confirm their family relationship.');
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:family_branches,id'],
            'father_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'mother_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'spouse_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'relationship_label' => ['nullable', 'string', 'max:64'],
        ]);

        foreach (['branch_id', 'father_id', 'mother_id', 'spouse_id'] as $key) {
            if (! empty($validated[$key])) {
                $this->assertBelongsToOrg($organization->id, $key, (int) $validated[$key]);
            }
        }

        if (empty($validated['relationship_label'])) {
            $validated['relationship_label'] = 'Child';
        }

        $this->familyReunion->addChild($organization, $validated, $request->user());

        return back()->with('success', 'Family member added.');
    }

    public function update(Request $request, FamilyMember $member): RedirectResponse
    {
        $organization = $this->organization($request);
        if ((int) $member->organization_id !== (int) $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:family_branches,id'],
            'father_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'mother_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'spouse_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'relationship_label' => ['nullable', 'string', 'max:64'],
        ]);

        foreach (['branch_id', 'father_id', 'mother_id', 'spouse_id'] as $key) {
            if (! empty($validated[$key])) {
                $this->assertBelongsToOrg($organization->id, $key, (int) $validated[$key]);
            }
        }

        $this->familyReunion->updateMember($organization, $member, $validated, $request->user());

        return back()->with('success', 'Member updated.');
    }

    public function destroy(Request $request, FamilyMember $member): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->familyReunion->archiveMember($organization, $member, $request->user());

        return back()->with('success', 'Member removed from the visible family list.');
    }

    public function setupProfile(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:family_branches,id'],
            'father_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'mother_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'parent_not_listed' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['parent_not_listed']) && empty($validated['father_id']) && empty($validated['mother_id'])) {
            return back()->withErrors([
                'father_id' => 'Select a parent or choose “Parent not listed”.',
            ]);
        }

        $this->assertBelongsToOrg($organization->id, 'branch_id', (int) $validated['branch_id']);
        foreach (['father_id', 'mother_id'] as $key) {
            if (! empty($validated[$key])) {
                $this->assertBelongsToOrg($organization->id, $key, (int) $validated[$key]);
            }
        }

        $this->familyReunion->setupMemberProfile($organization, $request->user(), $validated);

        return back()->with('success', 'Family profile saved.');
    }

    public function search(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $q = trim((string) $request->query('q', ''));

        $members = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->when($q !== '', fn ($query) => $query->where('full_name', 'like', "%{$q}%"))
            ->orderBy('full_name')
            ->limit(25)
            ->get(['id', 'full_name', 'generation', 'branch_id', 'photo', 'status', 'relationship_label'])
            ->map(fn (FamilyMember $m) => [
                'id' => $m->id,
                'label' => $m->full_name
                    .($m->relationship_label ? " ({$m->relationship_label})" : '')
                    .($m->generation !== null ? " · Gen {$m->generation}" : ''),
                'full_name' => $m->full_name,
                'photo_url' => $m->photo_url,
                'generation' => $m->generation,
                'branch_id' => $m->branch_id,
                'status' => $m->status?->value ?? $m->status,
            ]);

        return response()->json(['data' => $members]);
    }

    public function updateRelationships(Request $request, FamilyMember $member): RedirectResponse
    {
        $organization = $this->organization($request);
        if ((int) $member->organization_id !== (int) $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'father_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'mother_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'spouse_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'branch_id' => ['nullable', 'integer', 'exists:family_branches,id'],
            'status' => ['nullable', 'in:'.implode(',', FamilyMemberStatus::values())],
            'generation' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        foreach (['father_id', 'mother_id', 'spouse_id', 'branch_id'] as $key) {
            if (! empty($validated[$key])) {
                $this->assertBelongsToOrg($organization->id, $key, (int) $validated[$key]);
            }
        }

        $this->familyReunion->updateRelationships($organization, $member, $validated, $request->user());

        if (isset($validated['status'])) {
            $member->status = FamilyMemberStatus::from($validated['status']);
            $member->save();
        }

        return back()->with('success', 'Relationships updated.');
    }

    private function assertBelongsToOrg(int $organizationId, string $key, int $id): void
    {
        if ($key === 'branch_id') {
            $ok = FamilyBranch::query()->where('organization_id', $organizationId)->where('id', $id)->exists();
        } else {
            $ok = FamilyMember::query()->where('organization_id', $organizationId)->where('id', $id)->exists();
        }

        if (! $ok) {
            abort(422, 'Invalid '.$key.' for this organization.');
        }
    }
}
