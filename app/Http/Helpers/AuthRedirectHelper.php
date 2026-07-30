<?php

namespace App\Http\Helpers;

use App\Models\CareAlliance;
use App\Models\User;
use App\Services\SupporterProfileCompletionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Central place for post-login / post-register / post-verify redirects.
 * Prevents access denied by sending each role to the correct first page.
 */
class AuthRedirectHelper
{
    /**
     * Get the default redirect URL for the authenticated user (by role).
     *
     * - Supporter (user): /profile/edit until required fields filled, then public profile
     * - Care Alliance (Spatie role care_alliance): public alliance hub (/alliances/{slug})
     * - Organization / organization_pending: public org page
     * - Admin: dashboard
     */
    public static function defaultRedirectForUser(?Authenticatable $user): string
    {
        if (! $user) {
            return Route::has('dashboard') ? route('dashboard') : '/';
        }

        $role = $user->role ?? (method_exists($user, 'getRoleNames') ? $user->getRoleNames()->first() : null);

        if ($role === 'user') {
            if ($user instanceof User && SupporterProfileCompletionService::needsOnboarding($user)) {
                return Route::has('user.onboarding') ? route('user.onboarding') : '/onboarding';
            }

            if ($user instanceof User && SupporterProfileCompletionService::needsProfileSetup($user)) {
                return Route::has('user.profile.edit') ? route('user.profile.edit') : '/profile/edit';
            }

            $slug = $user->slug ?? $user->id;

            return Route::has('users.show') ? route('users.show', $slug) : '/';
        }

        $isCareAlliance = $role === 'care_alliance'
            || (method_exists($user, 'hasRole') && $user->hasRole('care_alliance'));

        if ($isCareAlliance) {
            $alliance = CareAlliance::query()
                ->where('creator_user_id', $user->id)
                ->orderBy('id')
                ->first();

            if ($alliance && filled($alliance->slug) && Route::has('alliances.show')) {
                return route('alliances.show', $alliance->slug);
            }

            if (Route::has('care-alliance.workspace.members')) {
                return route('care-alliance.workspace.members');
            }

            return Route::has('dashboard') ? route('dashboard') : '/';
        }

        if (in_array($role, ['organization', 'organization_pending'], true)) {
            $slug = $user->slug ?? $user->id;

            return Route::has('organizations.show') ? route('organizations.show', $slug) : '/';
        }

        if ($role === 'admin') {
            return Route::has('dashboard') ? route('dashboard') : '/';
        }

        return Route::has('dashboard') ? route('dashboard') : '/';
    }
}
