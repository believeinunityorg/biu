<?php

namespace App\Http\Controllers\Livestock\Auth;

use App\Http\Controllers\Controller;
use App\Models\LivestockUser;
use App\Services\TurnstileService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Route;

class LivestockRegisteredUserController extends Controller
{
    /**
     * Show the registration page for livestock users.
     */
    public function create(): Response
    {
        return Inertia::render('Livestock/Auth/Register');
    }

    /**
     * Handle an incoming registration request for livestock users.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:livestock_users,email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], TurnstileService::rules()));

        $user = LivestockUser::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        // Send verification email with current domain (where user is registering from)
        // Use actual request host, not config value
        $scheme = $request->getScheme();
        $host = $request->getHost();
        $port = $request->getPort();
        $currentDomain = $scheme . '://' . $host . ($port && $port != 80 && $port != 443 ? ':' . $port : '');
        $user->sendEmailVerificationNotification($currentDomain);

        Auth::guard('livestock')->login($user);

        // Redirect to livestock seller dashboard or home
        if (Route::has('seller.dashboard')) {
            return redirect()->route('seller.dashboard');
        }
        return redirect()->route('home');
    }
}

