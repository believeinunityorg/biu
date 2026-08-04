<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilyReunionOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $organization = Organization::forAuthUser($user);
        if (! $organization || ! $organization->isFamilyReunion()) {
            abort(403, 'Family Reunion features are only available for Family Reunion organizations.');
        }

        $request->attributes->set('family_reunion_organization', $organization);

        return $next($request);
    }
}
