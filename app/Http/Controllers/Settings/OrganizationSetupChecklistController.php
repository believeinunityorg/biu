<?php

namespace App\Http\Controllers\Settings;

use App\Data\OrganizationReadinessModules;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationSetupChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSetupChecklistController extends Controller
{
    public function __construct(
        private readonly OrganizationSetupChecklistService $checklistService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::forAuthUser($user);

        if (! $organization) {
            abort(403, 'Organization profile required.');
        }

        $checklist = $this->checklistService->forOrganization($organization, $user);
        $moduleId = $request->string('module')->toString();
        $activeModule = null;

        if ($moduleId !== '' && OrganizationReadinessModules::find($moduleId) !== null) {
            $activeModule = collect($checklist['modules'] ?? [])
                ->firstWhere('id', $moduleId);
        }

        return Inertia::render('settings/setup-checklist', [
            'checklist' => $checklist,
            'organizationName' => $organization->name,
            'activeModuleId' => $activeModule ? $moduleId : null,
            'activeModule' => $activeModule,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = Organization::forAuthUser($user);

        if (! $organization) {
            return response()->json(['message' => 'Organization profile required.'], 403);
        }

        $checklist = $this->checklistService->forOrganization($organization, $user);

        return response()->json(['checklist' => $checklist]);
    }
}
