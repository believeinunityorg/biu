<?php

namespace App\Http\Controllers\FamilyReunion;

use App\Http\Controllers\Controller;
use App\Models\FamilyBranch;
use App\Models\FamilyMember;
use App\Services\FamilyReunion\FamilyReunionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ClaimInviteController extends Controller
{
    public function __construct(
        private readonly FamilyReunionService $familyReunion,
    ) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $member = $this->familyReunion->findByInviteToken($token);
        if (! $member) {
            abort(404, 'This invite is invalid or has already been used.');
        }

        if (! Auth::check()) {
            return redirect()->guest(route('register', [
                'email' => $member->email,
            ]))->with('url.intended', url('/family/claim/'.$token));
        }

        $organization = $member->organization;
        $parents = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->orderBy('full_name')
            ->limit(100)
            ->get(['id', 'full_name', 'generation', 'relationship_label'])
            ->map(fn (FamilyMember $m) => [
                'id' => $m->id,
                'label' => $m->full_name
                    .($m->relationship_label ? " ({$m->relationship_label})" : '')
                    .($m->generation !== null ? " · Gen {$m->generation}" : ''),
            ]);

        return Inertia::render('Organization/FamilyReunion/ClaimInvite', [
            'token' => $token,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'branch_id' => $member->branch_id,
                'relationship_label' => $member->relationship_label,
                'father_id' => $member->father_id,
                'mother_id' => $member->mother_id,
            ],
            'branches' => FamilyBranch::query()
                ->forOrganization($organization->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name']),
            'parents' => $parents,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $member = $this->familyReunion->findByInviteToken($token);
        if (! $member) {
            abort(404, 'This invite is invalid or has already been used.');
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login', ['redirect' => url('/family/claim/'.$token)]));
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:family_branches,id'],
            'father_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'mother_id' => ['nullable', 'integer', 'exists:family_members,id'],
            'relationship_label' => ['nullable', 'string', 'max:64'],
        ]);

        foreach (['branch_id', 'father_id', 'mother_id'] as $key) {
            if (empty($validated[$key])) {
                continue;
            }
            $id = (int) $validated[$key];
            $ok = $key === 'branch_id'
                ? FamilyBranch::query()->where('organization_id', $member->organization_id)->where('id', $id)->exists()
                : FamilyMember::query()->where('organization_id', $member->organization_id)->where('id', $id)->exists();
            if (! $ok) {
                abort(422, 'Invalid '.$key);
            }
        }

        $this->familyReunion->acceptInvite($member, $user, $validated);

        return redirect('/')
            ->with('success', 'Welcome to the family. Your profile has been linked.');
    }
}
