<?php

namespace App\Http\Controllers\Livestock\Auth;

use App\Http\Controllers\Controller;
use App\Services\TurnstileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class LivestockPasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Livestock/Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request for livestock users.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'email' => 'required|email',
        ], TurnstileService::rules()));

        // Use the 'livestock_users' password broker
        $status = Password::broker('livestock_users')->sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __($status === Password::RESET_LINK_SENT 
            ? 'A reset link has been sent to your email address.' 
            : 'A reset link will be sent if the account exists.'));
    }
}



