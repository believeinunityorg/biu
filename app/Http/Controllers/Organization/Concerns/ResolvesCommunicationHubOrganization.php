<?php

namespace App\Http\Controllers\Organization\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait ResolvesCommunicationHubOrganization
{
    /**
     * Resolve the authenticated user's own organisation (management context).
     */
    protected function resolveOwnedOrg(): Organization
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthenticated.');
        }

        $org = Organization::forAuthUser($user);

        if (! $org) {
            abort(403, 'No organization linked to your account.');
        }

        return $org;
    }

    /**
     * Resolve an approved organization by its public (owner user) slug.
     */
    protected function resolveOrgBySlug(string $slug): Organization
    {
        $user = User::where('slug', $slug)->first();

        $organization = $user
            ? Organization::where('user_id', $user->id)
                ->where('registration_status', 'approved')
                ->first()
            : null;

        if (! $organization) {
            abort(404, 'Organization not found.');
        }

        return $organization;
    }

    /**
     * Management routes use the owned org; community routes pass a public slug.
     */
    protected function resolveHubOrganization(?string $slug = null): Organization
    {
        return $slug !== null && $slug !== ''
            ? $this->resolveOrgBySlug($slug)
            : $this->resolveOwnedOrg();
    }

    /**
     * @return array{mode: string, org_slug: string|null}
     */
    protected function hubContext(Organization $organization, bool $community = false): array
    {
        $slug = null;

        if ($community) {
            $organization->loadMissing('user:id,slug');
            $slug = $organization->user?->slug;
        }

        return [
            'mode' => $community ? 'community' : 'manage',
            'org_slug' => $slug,
        ];
    }
}
