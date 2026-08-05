<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BridgeIntegration;
use App\Models\SupporterPosition;
use App\Models\User;
use App\Services\BridgeService;
use App\Services\SupporterPrimaryOrganizationService;
use App\Services\TurnstileService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class RegisteredUserController extends Controller
{
    protected BridgeService $bridgeService;

    public function __construct(
        BridgeService $bridgeService,
        private readonly SupporterPrimaryOrganizationService $primaryOrgService,
    ) {
        $this->bridgeService = $bridgeService;
    }

    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'agreeToTerms' => 'required|accepted',
            'positions' => 'required|array|min:1',
            'positions.*' => 'exists:supporter_positions,id',
        ], TurnstileService::rules()));

        $referredBy = null;
        $referrer = null;
        if ($request->filled('referralCode')) {
            $referrer = User::where('referral_code', $request->referralCode)->first();
            if ($referrer) {
                $referredBy = $referrer->id;
            }
        }

        // Generate unique slug with incremental numbers
        $baseSlug = Str::slug($request->name);
        $slug = $baseSlug;
        $counter = 1;
        while (User::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $user = User::create([
            'name' => $request->name,
            'slug' => $slug,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
            'referred_by' => $referredBy,
        ]);

        // Positions সেভ করুন
        if ($request->has('positions')) {
            $user->supporterPositions()->sync($request->positions);
        }

        if ($referrer !== null) {
            $this->primaryOrgService->assignLockedPrimaryFromReferrer($user, $referrer);
        }

        event(new Registered($user));
        $user->assignRole('user');

        // Send verification email with current domain (where user is registering from)
        // Use actual request host, not config value
        $scheme = $request->getScheme();
        $host = $request->getHost();
        $port = $request->getPort();
        $currentDomain = $scheme . '://' . $host . ($port && $port != 80 && $port != 443 ? ':' . $port : '');
        $user->sendEmailVerificationNotification($currentDomain);

        Auth::login($user);

        // Initialize Bridge for the user (non-blocking)
        try {
            $this->initializeBridgeForUser($user);
        } catch (\Exception $e) {
            // Log error but don't fail registration
            Log::error('Failed to initialize Bridge during user registration', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Supporter: complete profile first
        return redirect()->route('user.profile.edit');
    }

    /**
     * Initialize Bridge for a newly registered user
     */
    private function initializeBridgeForUser(User $user): void
    {
        // Check if Bridge integration already exists
        $existingIntegration = BridgeIntegration::where('integratable_id', $user->id)
            ->where('integratable_type', User::class)
            ->first();

        if ($existingIntegration && $existingIntegration->bridge_customer_id) {
            Log::info('Bridge customer already exists for user', [
                'user_id' => $user->id,
                'bridge_customer_id' => $existingIntegration->bridge_customer_id,
            ]);
            return;
        }

        // Get required fields
        $email = trim($user->email ?? '');
        $fullName = trim($user->name ?? '');

        if (empty($email)) {
            throw new \Exception('Email is required to create Bridge customer');
        }

        if (empty($fullName)) {
            throw new \Exception('Name is required to create Bridge customer');
        }

        // Create KYC Link - Bridge's recommended approach for individuals
        $kycLinkData = [
            'full_name' => $fullName,
            'email' => $email,
            'type' => 'individual',
        ];

        Log::info('Creating Bridge KYC Link for user during registration', [
            'user_id' => $user->id,
            'kyc_link_data' => $kycLinkData,
        ]);

        $kycLinkResult = $this->bridgeService->createKYCLink($kycLinkData);

        if (!$kycLinkResult['success']) {
            Log::error('Failed to create Bridge KYC Link', [
                'user_id' => $user->id,
                'error' => $kycLinkResult['error'] ?? 'Unknown error',
                'response' => $kycLinkResult['response'] ?? null,
            ]);
            throw new \Exception($kycLinkResult['error'] ?? 'Failed to create Bridge KYC Link');
        }

        $response = $kycLinkResult['data'];

        // Log if we're using an existing link
        if (isset($kycLinkResult['is_existing']) && $kycLinkResult['is_existing']) {
            Log::info('Using existing Bridge KYC Link', [
                'user_id' => $user->id,
                'kyc_link_id' => $response['id'] ?? null,
                'customer_id' => $response['customer_id'] ?? null,
                'email' => $response['email'] ?? null,
            ]);
        }

        // Save Bridge integration
        if (!$existingIntegration) {
            $existingIntegration = new BridgeIntegration();
            $existingIntegration->integratable_id = $user->id;
            $existingIntegration->integratable_type = User::class;
        }

        $existingIntegration->bridge_customer_id = $response['customer_id'] ?? null;
        $existingIntegration->kyc_link_id = $response['id'] ?? null;
        $existingIntegration->kyc_link_url = $response['kyc_link'] ?? null;
        $existingIntegration->tos_link_url = $response['tos_link'] ?? null;
        $existingIntegration->kyc_status = $response['kyc_status'] ?? 'not_started';
        $existingIntegration->tos_status = $response['tos_status'] ?? 'pending';
        $existingIntegration->bridge_metadata = [
            'kyc_link_response' => $response,
            'type' => 'individual',
            'created_at_registration' => true,
            'registration_data' => [
                'name' => $fullName,
                'email' => $email,
            ],
        ];
        $existingIntegration->save();

        Log::info('Bridge customer created successfully for user during registration', [
            'user_id' => $user->id,
            'bridge_customer_id' => $existingIntegration->bridge_customer_id,
            'kyc_link_id' => $existingIntegration->kyc_link_id,
        ]);
    }
}
