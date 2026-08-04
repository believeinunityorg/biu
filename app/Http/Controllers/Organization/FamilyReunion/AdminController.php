<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Models\FamilyMember;
use App\Models\FamilyRelationshipAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);

        $audits = FamilyRelationshipAudit::query()
            ->where('organization_id', $organization->id)
            ->with(['familyMember:id,full_name', 'changedBy:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (FamilyRelationshipAudit $a) => [
                'id' => $a->id,
                'member' => $a->familyMember?->full_name,
                'field' => $a->field,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'changed_by' => $a->changedBy?->name,
                'created_at' => $a->created_at?->toISOString(),
            ]);

        return Inertia::render('Organization/FamilyReunion/Admin', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'audits' => $audits,
            'stats' => [
                'members' => FamilyMember::query()->forOrganization($organization->id)->visible()->count(),
                'unclaimed' => FamilyMember::query()->forOrganization($organization->id)->where('status', 'unclaimed')->count(),
                'merged' => FamilyMember::query()->forOrganization($organization->id)->where('status', 'merged')->count(),
            ],
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'keep_member_id' => ['required', 'integer', 'exists:family_members,id'],
            'duplicate_member_id' => ['required', 'integer', 'different:keep_member_id', 'exists:family_members,id'],
        ]);

        $keep = FamilyMember::query()->forOrganization($organization->id)->findOrFail($validated['keep_member_id']);
        $duplicate = FamilyMember::query()->forOrganization($organization->id)->findOrFail($validated['duplicate_member_id']);

        $this->familyReunion->mergeMembers($organization, $keep, $duplicate, $request->user());

        return back()->with('success', 'Duplicate family records merged.');
    }
}
