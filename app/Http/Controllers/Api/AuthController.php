<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationCodeMail;
use App\Models\BridgeIntegration;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\BridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected BridgeService $bridgeService;

    public function __construct(BridgeService $bridgeService)
    {
        $this->bridgeService = $bridgeService;
    }

    /**
     * Resolve ISO 3166-1 alpha-2 country code from client IP (e.g. for session display).
     * Uses a free geo API; fails closed (returns null on error or invalid IP).
     */
    private function getCountryCodeFromIp(?string $ip): ?string
    {
        if (! $ip || $ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }
        try {
            $response = Http::timeout(2)->get("https://ip-api.com/json/{$ip}", [
                'fields' => 'countryCode',
            ]);
            if (! $response->successful()) {
                return null;
            }
            $code = $response->json('countryCode');

            return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
        } catch (\Throwable $e) {
            Log::debug('GeoIP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'sometimes|string|in:user,organization',
            'device_name' => 'sometimes|string|max:255',
            'device_country' => 'sometimes|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'user',
            'balance' => 0,
            'reward_points' => 0,
            'believe_points' => 0,
        ]);

        // Assign role if not already assigned
        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }

        // Initialize Bridge for the user (non-blocking)
        try {
            $this->initializeBridgeForUser($user);
        } catch (\Exception $e) {
            // Log error but don't fail registration
            Log::error('Failed to initialize Bridge during API user registration', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Send email verification code (non-blocking)
        try {
            $this->sendVerificationCode($user);
        } catch (\Exception $e) {
            // Log error but don't fail registration
            Log::error('Failed to send verification code during API user registration', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $deviceName = $request->input('device_name', 'Web Device');
        $tokenResult = $user->createToken($deviceName);
        $country = $request->filled('device_country')
            ? strtoupper(substr($request->device_country, 0, 2))
            : $this->getCountryCodeFromIp($request->ip());
        if ($country) {
            $tokenResult->token->forceFill(['device_country' => $country])->save();
        }
        $token = $tokenResult->accessToken;

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully. Please check your email for verification code.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
            'requires_email_verification' => true,
        ], 201);
    }

    /**
     * Login user and create token
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:255',
            'device_country' => 'sometimes|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        $deviceName = $request->input('device_name', 'Web Device');
        $tokenResult = $user->createToken($deviceName);
        $country = $request->filled('device_country')
            ? strtoupper(substr($request->device_country, 0, 2))
            : $this->getCountryCodeFromIp($request->ip());
        if ($country) {
            $tokenResult->token->forceFill(['device_country' => $country])->save();
        }
        $token = $tokenResult->accessToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Revoke all tokens for the user
            $user->tokens->each(function ($token) {
                $token->revoke();
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'image' => $user->image,
                'balance' => $user->balance ?? 0,
                'reward_points' => $user->reward_points ?? 0,
                'believe_points' => $user->believe_points ?? 0,
                'gifted_believe_points' => $user->gifted_believe_points ?? 0,
                'purchased_believe_points' => $user->purchasedBelievePointsBalance(),
                'believe_points_total' => $user->totalBelievePointsBalance(),
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
                'role' => $user->role ?? 'user',
            ],
        ]);
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

        Log::info('Creating Bridge KYC Link for user during API registration', [
            'user_id' => $user->id,
            'kyc_link_data' => $kycLinkData,
        ]);

        $kycLinkResult = $this->bridgeService->createKYCLink($kycLinkData);

        if (! $kycLinkResult['success']) {
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
        if (! $existingIntegration) {
            $existingIntegration = new BridgeIntegration;
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

        Log::info('Bridge customer created successfully for user during API registration', [
            'user_id' => $user->id,
            'bridge_customer_id' => $existingIntegration->bridge_customer_id,
            'kyc_link_id' => $existingIntegration->kyc_link_id,
        ]);
    }

    /**
     * Send verification code email to user
     */
    private function sendVerificationCode(User $user): void
    {
        // Create verification code
        $verificationCode = EmailVerificationCode::createForUser($user);

        // Queue email job
        Mail::to($user->email)->queue(new EmailVerificationCodeMail($user, $verificationCode->code));

        Log::info('Verification code email queued for user', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    /**
     * Verify email with code
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = trim($request->email);
        $code = trim($request->code);

        // First, check if a code with this email and code exists
        $verificationCode = EmailVerificationCode::where('email', $email)
            ->where('code', $code)
            ->first();

        if (! $verificationCode) {
            return response()->json([
                'success' => false,
                'message' => 'The code you entered is incorrect',
            ], 400);
        }

        // Check if code is already used
        if ($verificationCode->is_used) {
            return response()->json([
                'success' => false,
                'message' => 'This code has already been used',
            ], 400);
        }

        // Check if code has expired
        if ($verificationCode->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'This code has expired',
            ], 400);
        }

        // Get user
        $user = $verificationCode->user;

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        // Mark code as used
        $verificationCode->markAsUsed();

        // Verify user email
        $user->email_verified_at = now();
        $user->save();

        // Refresh user to get updated data
        $user->refresh();

        Log::info('Email verified successfully', [
            'user_id' => $user->id,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully!',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
            ],
        ]);
    }

    /**
     * Resend verification code
     */
    public function resendVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Email is already verified.',
            ], 400);
        }

        // Send verification code
        try {
            $this->sendVerificationCode($user);

            return response()->json([
                'success' => true,
                'message' => 'Verification code sent successfully. Please check your email.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend verification code', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification code. Please try again later.',
            ], 500);
        }
    }
}
