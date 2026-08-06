<?php

namespace App\Http\Controllers\Organization\FamilyReunion;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FounderController extends FamilyReunionController
{
    public function edit(Request $request): Response
    {
        $organization = $this->organization($request);
        $founder = $organization->familyFounder;

        return Inertia::render('Organization/FamilyReunion/FoundingCouple', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'founders' => $founder ? [
                'grandfather_name' => $founder->grandfather_name,
                'grandmother_name' => $founder->grandmother_name,
                'grandfather_photo_url' => $founder->grandfather_photo_url,
                'grandmother_photo_url' => $founder->grandmother_photo_url,
                'grandfather_birth_year' => $founder->grandfather_birth_year,
                'grandfather_death_year' => $founder->grandfather_death_year,
                'grandmother_birth_year' => $founder->grandmother_birth_year,
                'grandmother_death_year' => $founder->grandmother_death_year,
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'grandfather_name' => ['required', 'string', 'max:255'],
            'grandmother_name' => ['required', 'string', 'max:255'],
            'grandfather_birth_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'grandfather_death_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'grandmother_birth_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'grandmother_death_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'grandfather_photo' => ['nullable', 'image', 'max:5120'],
            'grandmother_photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $this->familyReunion->saveFoundingCouple(
            $organization,
            $validated,
            $request->file('grandfather_photo'),
            $request->file('grandmother_photo'),
            $request->user(),
        );

        return redirect()
            ->route('organization.family-reunion.founders.edit')
            ->with('success', 'Founding couple saved.');
    }
}
