<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\FamilyReunion\FamilyReunionService;
use Illuminate\Http\Request;

abstract class FamilyReunionController extends Controller
{
    public function __construct(
        protected FamilyReunionService $familyReunion,
    ) {}

    protected function organization(Request $request): Organization
    {
        /** @var Organization|null $fromMiddleware */
        $fromMiddleware = $request->attributes->get('family_reunion_organization');
        if ($fromMiddleware instanceof Organization) {
            return $fromMiddleware;
        }

        $organization = $this->familyReunion->resolveOrganization($request->user());
        if (! $organization) {
            abort(403);
        }

        $this->familyReunion->assertFamilyReunion($organization);

        return $organization;
    }
}
