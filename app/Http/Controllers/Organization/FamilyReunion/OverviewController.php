<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends FamilyReunionController
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);
        $payload = $this->familyReunion->overviewPayload($organization);

        $recentMembers = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->with(['branch:id,name'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (FamilyMember $m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'photo_url' => $m->photo_url,
                'branch' => $m->branch?->name,
                'generation' => $m->generation,
                'status' => $m->status?->value ?? $m->status,
                'is_claimed' => $m->is_claimed,
            ]);

        return Inertia::render('Organization/FamilyReunion/Overview', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'registered_user_image' => $organization->registered_user_image
                    ? '/storage/'.$organization->registered_user_image
                    : null,
                'created_at' => $organization->created_at?->toISOString(),
            ],
            ...$payload,
            'directory_preview' => $recentMembers,
        ]);
    }
}
