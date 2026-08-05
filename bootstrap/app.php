<?php

// PHP defines STDIN/STDOUT/STDERR only in CLI. Artisan::call() from HTTP (e.g. admin settings) loads
// Symfony Console code that references STDIN; provide a harmless stream in web / php-fpm.
if (! defined('STDIN')) {
    define('STDIN', fopen('php://memory', 'r'));
}

use App\Http\Helpers\AuthRedirectHelper;
use App\Http\Middleware\AllowDonateWidgetEmbed;
use App\Http\Middleware\BarterNetworkAccess;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckRoleSimple;
use App\Http\Middleware\CheckTopicsSelected;
use App\Http\Middleware\DenyCareAllianceHubUser;
use App\Http\Middleware\DetectTimezone;
use App\Http\Middleware\EnsureBridgeVerified;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\GrantMeetingEmbedPermissions;
use App\Http\Middleware\EnsureApiEmailVerified;
use App\Http\Middleware\EnsureCanCreateEvents;
use App\Http\Middleware\EnsureCanReadEventTypes;
use App\Http\Middleware\EnsureCanReadTopics;
use App\Http\Middleware\EnsureCareAllianceWalletEligible;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureServiceHubSeller;
use App\Http\Middleware\EnsureSupporterProfileComplete;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IncreaseUploadLimits;
use App\Http\Middleware\NoCacheAuthPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        // Default Laravel sends unauthenticated users to route('login') (main app). On the
        // merchant host, auth:merchant must redirect to merchant.login; guest routes must send
        // authenticated merchants to merchant.dashboard to avoid login↔dashboard loops.
        $middleware->redirectGuestsTo(fn () => request_is_merchant_portal()
            ? route('merchant.login')
            : route('login'));

        $middleware->redirectUsersTo(function () {
            if (request_is_merchant_portal()) {
                return route('merchant.dashboard');
            }

            return AuthRedirectHelper::defaultRedirectForUser(auth()->user());
        });

        // Webhooks are called by external services and cannot include Laravel CSRF tokens.
        // Security is handled in the webhook controller via signature verification.
        $middleware->validateCsrfTokens(except: [
            'webhooks/bridge',
            'stripe/webhook',
            'api/*', // Exclude all API routes from CSRF protection
        ]);

        $middleware->encryptCookies(except: [
            'appearance',
            'sidebar_state',
            'XSRF-TOKEN',
        ]);

        // DetectTimezone syncs the viewer's IANA timezone to users.timezone (X-Timezone header).
        $middleware->web(prepend: [
            ForceHttps::class,
            \App\Http\Middleware\ConfigureCashierStripeFromDatabase::class,
        ]);

        $middleware->web(append: [
            GrantMeetingEmbedPermissions::class,
            HandleAppearance::class,
            DetectTimezone::class, // Persists browser timezone to users.timezone (app stays UTC)
            EnsureBridgeVerified::class,
            EnsureSupporterProfileComplete::class, // Role user: finish /profile/edit before rest of app
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            IncreaseUploadLimits::class,
            NoCacheAuthPages::class, // Prevent caching of login/register to avoid 419 CSRF
        ]);

        // Also apply timezone detection to API routes
        $middleware->api(append: [
            DetectTimezone::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'role.simple' => CheckRoleSimple::class, // Simple role check without guard issues
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'topics.selected' => CheckTopicsSelected::class,
            'profile.complete' => EnsureSupporterProfileComplete::class,
            'check.permission' => CheckPermission::class,
            'check.role' => CheckRole::class,
            'EnsureEmailIsVerified' => EnsureEmailIsVerified::class,
            'api.email.verified' => EnsureApiEmailVerified::class, // Secure API email verification guard
            'barter.access' => BarterNetworkAccess::class,
            'care_alliance.wallet' => EnsureCareAllianceWalletEligible::class,
            'deny.care_alliance.hub' => DenyCareAllianceHubUser::class,
            'can.read.event_types' => EnsureCanReadEventTypes::class,
            'can.read.topics' => EnsureCanReadTopics::class,
            'can.create.events' => EnsureCanCreateEvents::class,
            'ensure.service.hub.seller' => EnsureServiceHubSeller::class,
            'allow.donate.widget.embed' => AllowDonateWidgetEmbed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        $exceptions->render(function (HttpException $e, $request) {
            if ($request->is('stripe/webhook')) {
                return response($e->getMessage() ?: 'Forbidden', $e->getStatusCode());
            }

            if ($e->getStatusCode() === 413) {
                if ($request->header('X-Inertia')) {
                    return redirect()->back()->with(
                        'error',
                        'The upload is too large. Challenge cover images must be 5MB or smaller (JPEG, PNG, or WebP). If this appears for a small file, the server limit may be lower — try compressing the image or ask your host to raise PHP post_max_size and nginx client_max_body_size.'
                    );
                }
            }

            if ($e->getStatusCode() === 404) {
                // If it's an AJAX request, return JSON response
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'The requested resource was not found.',
                        'status' => 404,
                    ], 404);
                }

                // For web requests, show custom 404 page
                $user = $request->user();
                $backUrl = $request->header('referer');

                if (! $backUrl && $user) {
                    $userRole = $user->role ?? null;
                    if ($userRole === 'admin' || $userRole === 'organization' || $userRole === 'organization_pending') {
                        $backUrl = route('dashboard');
                    } elseif ($userRole === 'merchant') {
                        $backUrl = route('merchant.dashboard');
                    } elseif ($userRole === 'user') {
                        $backUrl = route('user.profile.index');
                    } else {
                        $backUrl = '/';
                    }
                } elseif (! $backUrl) {
                    $backUrl = '/';
                }

                return Inertia::render('errors/404', [
                    'backUrl' => $backUrl,
                    'errorMessage' => $e->getMessage() ?: 'Page not found',
                    'auth' => [
                        'user' => $user ? [
                            'id' => $user->id,
                            'role' => $user->role ?? null,
                        ] : null,
                    ],
                ])->toResponse($request)->setStatusCode(404);
            }

            if ($e->getStatusCode() === 403) {
                // If it's an AJAX request, return JSON response
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'status' => 403,
                    ], 403);
                }

                // For web requests, show custom permission denied page
                $user = $request->user();
                $userRoles = [];
                $userPermissions = [];

                if ($user && method_exists($user, 'getRoleNames')) {
                    try {
                        $userRoles = $user->getRoleNames()->toArray();
                    } catch (Exception $ex) {
                        // If getRoleNames fails, try to get role from user property
                        $userRoles = $user->role ? [$user->role] : [];
                    }
                } elseif ($user && property_exists($user, 'role') && $user->role) {
                    $userRoles = [$user->role];
                }

                if ($user && method_exists($user, 'getAllPermissions')) {
                    try {
                        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();
                    } catch (Exception $ex) {
                        $userPermissions = [];
                    }
                }

                // Try to extract required permission/role from error message
                $requiredPermission = null;
                $requiredRoles = [];
                $errorMessage = $e->getMessage();

                // Extract permission from error message if it contains "Required permission:"
                if (preg_match('/Required permission:\s*([^\s]+)/i', $errorMessage, $matches)) {
                    $requiredPermission = $matches[1];
                }

                // Extract roles from error message if it contains "Required role(s):"
                if (preg_match('/Required role[s]?:\s*([^\.]+)/i', $errorMessage, $matches)) {
                    $rolesString = trim($matches[1]);
                    $requiredRoles = array_map('trim', explode(',', $rolesString));
                }

                // Get role-specific back URL
                $backUrl = $request->header('referer');
                if (! $backUrl && $user) {
                    $userRole = $userRoles[0] ?? $user->role ?? null;
                    if ($userRole === 'admin' || $userRole === 'organization' || $userRole === 'organization_pending') {
                        $backUrl = route('dashboard');
                    } elseif ($userRole === 'merchant') {
                        $backUrl = route('merchant.dashboard');
                    } elseif ($userRole === 'user') {
                        $backUrl = route('user.profile.index');
                    } else {
                        $backUrl = '/';
                    }
                } elseif (! $backUrl) {
                    $backUrl = '/';
                }

                return Inertia::render('errors/permission-denied', [
                    'permission' => 'access_denied',
                    'userRole' => $userRoles[0] ?? ($user->role ?? null),
                    'userRoles' => $userRoles,
                    'userPermissions' => $userPermissions,
                    'requiredPermission' => $requiredPermission ?: 'access_denied',
                    'requiredRoles' => ! empty($requiredRoles) ? $requiredRoles : null,
                    'backUrl' => $backUrl,
                    'errorMessage' => $errorMessage,
                    'auth' => [
                        'user' => $user ? [
                            'id' => $user->id,
                            'role' => $user->role ?? null,
                        ] : null,
                        'roles' => $userRoles,
                        'permissions' => $userPermissions,
                    ],
                ])->toResponse($request)->setStatusCode(403);
            }
        });
    })
    ->create();
