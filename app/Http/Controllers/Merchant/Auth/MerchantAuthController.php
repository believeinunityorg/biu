<?php

namespace App\Http\Controllers\Merchant\Auth;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ParticipationActivityService;
use App\Support\BrpParticipationModule;
use App\Services\TurnstileService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class MerchantAuthController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('merchant/Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:merchants'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'business_name' => ['nullable', 'string', 'max:255'],
            'preferred_payout_method' => ['nullable', 'string', 'in:stripe,paypal'],
            'referralCode' => ['nullable', 'string', 'max:64'],
        ], TurnstileService::rules()));

        $referrerUserId = null;
        if ($request->filled('referralCode')) {
            $referrer = User::where('referral_code', $request->input('referralCode'))->first();
            if ($referrer !== null) {
                $referrerUserId = $referrer->id;
            }
        }

        $merchant = Merchant::create([
            'referrer_user_id' => $referrerUserId,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'business_name' => $request->business_name,
            'preferred_payout_method' => $request->input('preferred_payout_method'),
            'status' => 'active',
            'role' => 'merchant',
        ]);

        event(new Registered($merchant));

        if ($referrerUserId !== null) {
            $referrer = User::find($referrerUserId);
            if ($referrer !== null) {
                ParticipationActivityService::complete(
                    $referrer,
                    BrpParticipationModule::MERCHANT_REFERRAL,
                    $merchant->id,
                    'Merchant referral reward: referred merchant registered',
                    [
                        'merchant_id' => $merchant->id,
                        'merchant_email' => $merchant->email,
                    ],
                    'merchant_referral',
                    'merchant_signup',
                );
            }
        }

        Auth::guard('merchant')->login($merchant);

        $redirect = redirect()->route('merchant.dashboard', [], 303);
        if ($request->filled('preferred_payout_method')) {
            $redirect->with('flash', ['success' => 'Account created. Complete your payout setup under Payouts in the dashboard.']);
        }

        return $redirect;
    }

    /**
     * Display the login view.
     */
    public function showLoginForm(Request $request): Response
    {
        return Inertia::render('merchant/Auth/Login', [
            'canResetPassword' => false,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], TurnstileService::rules()));

        if (Auth::guard('merchant')->attempt($request->only('email', 'password'), $request->boolean('remember', true))) {
            $request->session()->regenerate();

            // Do not use intended(): it may point at the main app and breaks subdomain auth.
            // 303: POST→redirect must not replay POST on the next hop (Inertia only upgrades DELETE etc. to 303).
            $request->session()->forget('url.intended');

            return redirect()->route('merchant.dashboard', [], 303);
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'email' => __('The provided credentials do not match our records.'),
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('merchant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('merchant.home');
    }
}
