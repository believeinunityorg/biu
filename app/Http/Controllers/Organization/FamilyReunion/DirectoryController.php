<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Models\FamilyBranch;
use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DirectoryController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);

        $search = trim((string) $request->query('q', ''));
        $branchId = $request->integer('branch_id') ?: null;
        $generation = $request->query('generation');
        $status = $request->query('status');

        $members = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->with(['branch:id,name', 'father:id,full_name', 'mother:id,full_name'])
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($generation !== null && $generation !== '', fn ($q) => $q->where('generation', (int) $generation))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (FamilyMember $m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'photo_url' => $m->photo_url,
                'email' => $m->email,
                'branch' => $m->branch?->name,
                'branch_id' => $m->branch_id,
                'generation' => $m->generation,
                'relationship_label' => $m->relationship_label,
                'father' => $m->father?->full_name,
                'father_id' => $m->father_id,
                'mother' => $m->mother?->full_name,
                'mother_id' => $m->mother_id,
                'status' => $m->status?->value ?? $m->status,
                'is_claimed' => $m->is_claimed,
                'is_founding' => $m->is_founding_grandfather || $m->is_founding_grandmother,
            ]);

        return Inertia::render('Organization/FamilyReunion/Directory', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'members' => $members,
            'branches' => FamilyBranch::query()
                ->forOrganization($organization->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),
            'filters' => [
                'q' => $search,
                'branch_id' => $branchId,
                'generation' => $generation,
                'status' => $status,
            ],
        ]);
    }
}
