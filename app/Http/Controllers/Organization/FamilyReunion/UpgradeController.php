<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Http\Controllers\Controller;
use App\Models\CommunityOrganizationType;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UpgradeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $organization = Organization::forAuthUser($request->user());
        if (! $organization) {
            abort(403);
        }

        if ($organization->isFamilyReunion()) {
            return redirect()->route('organization.family-reunion.overview');
        }

        $organization->loadMissing('communityOrganizationType');

        return Inertia::render('Organization/FamilyReunion/Upgrade', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'community_organization_type_id' => $organization->community_organization_type_id,
                'community_organization_type_slug' => $organization->communityOrganizationType?->slug,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = Organization::forAuthUser($request->user());
        if (! $organization) {
            abort(403);
        }

        $familyReunionId = CommunityOrganizationType::familyReunionId();
        if (! $familyReunionId) {
            abort(500, 'Family Reunion organization type is not configured.');
        }

        $organization->community_organization_type_id = $familyReunionId;
        $organization->community_organization_type_other = null;
        $organization->save();

        return redirect()
            ->route('organization.family-reunion.overview')
            ->with('success', 'Family Reunion features are now enabled for your organization.');
    }
}
