<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SupporterProfileCompletionService;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normal supporters (role user) must complete onboarding (then required profile fields) before using the rest of the app.
 */
class EnsureSupporterProfileComplete
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response|\Illuminate\Contracts\Support\Responsable)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($redirect = $this->redirectIfIncomplete($request)) {
            return $redirect;
        }

        return $this->normalizeResponse($request, $next($request));
    }

    private function redirectIfIncomplete(Request $request): ?Response
    {
        if (! config('app.require_supporter_profile', true)) {
            return null;
        }

        if (function_exists('request_is_merchant_portal') && request_is_merchant_portal()) {
            return null;
        }

        if (function_exists('is_livestock_domain') && is_livestock_domain()) {
            return null;
        }

        $user = $request->user();
        if (! $user instanceof User || ($user->role ?? null) !== 'user') {
            return null;
        }

        if (SupporterProfileCompletionService::needsOnboarding($user)) {
            if ($this->isOnboardingRoute($request)) {
                return null;
            }

            return $this->buildRedirect($request, route('user.onboarding'), 'Let\'s personalize your Believe In Unity experience.');
        }

        if (! SupporterProfileCompletionService::hasRequiredEditFields($user)) {
            if ($this->isProfileEditRoute($request)) {
                return null;
            }

            return $this->buildRedirect($request, route('user.profile.edit'), 'Please complete your profile to continue.');
        }

        return null;
    }

    private function buildRedirect(Request $request, string $url, string $message): Response
    {
        if ($this->wantsJsonBlock($request)) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => $url,
            ], 403);
        }

        return redirect($url)->with('info', $message);
    }

    private function isOnboardingRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if (in_array($routeName, [
            'user.onboarding',
            'user.onboarding.step',
            'user.onboarding.finish',
        ], true)) {
            return true;
        }

        return $request->is(
            'onboarding',
            'onboarding/step',
            'onboarding/finish',
        );
    }

    private function isProfileEditRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        $excludedRoutes = [
            'user.profile.edit',
            'user.profile.update',
            'user.profile.primary-organization.change',
            'user.profile.timezone',
            'logout',
            'logout.main',
            'verification.notice',
            'verification.send',
            'verification.verify',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'password.confirm',
            'password.update',
        ];

        if ($routeName !== null && in_array($routeName, $excludedRoutes, true)) {
            return true;
        }

        return $request->is(
            'profile/edit',
            'profile/update',
            'profile/primary-organization/change',
            'profile/timezone',
            'verify-email',
            'verify-email/*',
            'email/verify/*',
            'logout',
            'unity-call/*',
            'unity-calls/*',
            'broadcasting/*',
        );
    }

    private function wantsJsonBlock(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->is('api/*')
            || $request->header('Accept') === 'application/json';
    }

    /**
     * @param  Response|Responsable  $response
     */
    private function normalizeResponse(Request $request, mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if ($response instanceof Responsable) {
            return $response->toResponse($request);
        }

        throw new \InvalidArgumentException('Middleware closure must return a Symfony or Responsable response.');
    }
}
