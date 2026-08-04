<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TreeController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);
        $branchId = $request->integer('branch_id') ?: null;

        return Inertia::render('Organization/FamilyReunion/Tree', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'tree' => $this->familyReunion->buildTree($organization, $branchId),
            'filters' => [
                'branch_id' => $branchId,
            ],
            'branches' => $organization->familyBranches()
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),
        ]);
    }
}
