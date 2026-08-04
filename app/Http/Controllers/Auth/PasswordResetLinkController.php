<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SeoService;
use App\Services\TurnstileService;
use App\Support\PasswordResetCooldown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    private const BROKER = 'users';

    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('frontend/forgot-password', [
            'seo' => SeoService::forPage('forgot_password'),
            'status' => $request->session()->get('status'),
            'passwordResetCooldownUntil' => $request->session()->get('passwordResetCooldownUntil'),
            'passwordResetThrottleSeconds' => PasswordResetCooldown::seconds(self::BROKER),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'email' => 'required|email',
        ], TurnstileService::rules()));

        $email = (string) $request->input('email');

        $remaining = PasswordResetCooldown::remaining($email, self::BROKER);
        if ($remaining > 0) {
            return $this->throttledResponse($request, $email, $remaining);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_THROTTLED) {
            $remaining = PasswordResetCooldown::remaining($email, self::BROKER)
                ?: PasswordResetCooldown::seconds(self::BROKER);

            return $this->throttledResponse($request, $email, $remaining);
        }

        PasswordResetCooldown::record($email, self::BROKER);

        return back()
            ->with('status', __('A reset link will be sent if the account exists.'))
            ->with('passwordResetCooldownUntil', PasswordResetCooldown::untilTimestamp($email, self::BROKER));
    }

    private function throttledResponse(Request $request, string $email, int $remaining): RedirectResponse
    {
        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => __('Please wait :seconds seconds before requesting another reset link.', [
                    'seconds' => $remaining,
                ]),
            ])
            ->with('passwordResetCooldownUntil', now()->addSeconds($remaining)->getTimestamp());
    }
}
