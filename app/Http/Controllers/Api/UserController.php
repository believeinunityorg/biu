<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BridgeIntegration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Get user profile
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'dob' => $user->dob,
            'image' => $user->image,
            'cover_img' => $user->cover_img,
            'balance' => $user->balance ?? 0,
            'reward_points' => $user->reward_points ?? 0,
            'believe_points' => $user->believe_points ?? 0,
            'gifted_believe_points' => $user->gifted_believe_points ?? 0,
            'purchased_believe_points' => $user->purchasedBelievePointsBalance(),
            'believe_points_total' => $user->totalBelievePointsBalance(),
            'created_at' => $user->created_at,
            'role' => $user->role ?? 'user',
            'kyc_status' => 'not_started',
        ];

        $isOrgUser = in_array($user->role ?? '', ['organization', 'organization_pending'], true);
        if ($isOrgUser) {
            $org = Organization::where('user_id', $user->id)->first();
            if ($org) {
                // Return profile image from profile-photos for display (wallet uploads). Prefer user.image over legacy organizations/ path.
                $orgDisplayImage = $org->registered_user_image;
                if ($org->registered_user_image && str_starts_with($org->registered_user_image, 'organizations/') && $user->image) {
                    $orgDisplayImage = $user->image;
                } elseif (! $org->registered_user_image && $user->image) {
                    $orgDisplayImage = $user->image;
                }
                $data['organization'] = [
                    'id' => $org->id,
                    'name' => $org->name,
                    'ein' => $org->ein,
                    'email' => $org->email,
                    'phone' => $org->phone,
                    'contact_name' => $org->contact_name,
                    'contact_title' => $org->contact_title,
                    'street' => $org->street,
                    'city' => $org->city,
                    'state' => $org->state,
                    'zip' => $org->zip,
                    'website' => $org->website,
                    'description' => $org->description,
                    'mission' => $org->mission,
                    'registered_user_image' => $orgDisplayImage,
                ];
                $integration = BridgeIntegration::where('integratable_id', $org->id)
                    ->where('integratable_type', Organization::class)->first();
                if ($integration) {
                    $data['kyc_status'] = $integration->kyb_status === 'approved' ? 'verified'
                        : ($integration->kyb_status === 'rejected' ? 'rejected' : 'pending');
                }
            } else {
                $data['organization'] = null;
            }
        } else {
            $integration = BridgeIntegration::where('integratable_id', $user->id)
                ->where('integratable_type', User::class)->first();
            if ($integration) {
                $data['kyc_status'] = $integration->kyc_status === 'approved' ? 'verified'
                    : ($integration->kyc_status === 'rejected' ? 'rejected' : 'pending');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Update user profile (only allowed until verified)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $isOrgUser = in_array($user->role ?? '', ['organization', 'organization_pending'], true);
        if ($isOrgUser) {
            $org = Organization::where('user_id', $user->id)->first();
            if ($org) {
                $integration = BridgeIntegration::where('integratable_id', $org->id)
                    ->where('integratable_type', Organization::class)->first();
                if ($integration && $integration->kyb_status === 'approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Profile cannot be updated after verification.',
                    ], 422);
                }
            }
        } else {
            $integration = BridgeIntegration::where('integratable_id', $user->id)
                ->where('integratable_type', User::class)->first();
            if ($integration && $integration->kyc_status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile cannot be updated after verification.',
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'contact_number' => 'sometimes|string|max:20',
            'dob' => 'sometimes|date',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['name', 'contact_number', 'dob']);

        // Handle image upload: store new image first, then delete old one from storage
        if ($request->hasFile('image')) {
            $filename = 'profile-'.$user->id.'-'.time().'.'.$request->file('image')->extension();
            $path = $request->file('image')->storeAs('profile-photos', $filename, 'public');
            $updateData['image'] = $path;

            // Delete existing image from storage (after new one is stored so we never leave user without an image)
            $oldImage = $user->image;
            if ($oldImage) {
                try {
                    if (Storage::disk('public')->exists($oldImage)) {
                        Storage::disk('public')->delete($oldImage);
                    }
                } catch (\Throwable $e) {
                    // Log but don't fail the request; new image is already stored
                    Log::warning('Failed to delete old profile image from storage', [
                        'user_id' => $user->id,
                        'path' => $oldImage,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $user->update($updateData);

        // When organization user uploads profile image, also set organization's registered_user_image so it displays
        if ($request->hasFile('image') && isset($updateData['image'])) {
            $org = Organization::where('user_id', $user->id)->first();
            if ($org) {
                $org->registered_user_image = $updateData['image'];
                $org->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
                'dob' => $user->dob,
                'image' => $user->image,
            ],
        ]);
    }

    /**
     * Update organization profile (only allowed until verified)
     */
    public function updateOrganizationProfile(Request $request)
    {
        $user = $request->user();
        $org = Organization::where('user_id', $user->id)->first();

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'No organization found for this account.',
            ], 404);
        }

        $integration = BridgeIntegration::where('integratable_id', $org->id)
            ->where('integratable_type', Organization::class)->first();
        if ($integration && $integration->kyb_status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Organization data cannot be updated after verification.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'ein' => 'sometimes|nullable|string|max:50',
            'email' => 'sometimes|email|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'contact_name' => 'sometimes|nullable|string|max:255',
            'contact_title' => 'sometimes|nullable|string|max:100',
            'street' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'zip' => 'sometimes|nullable|string|max:20',
            'website' => 'sometimes|nullable|string|max:500',
            'description' => 'sometimes|nullable|string|max:5000',
            'mission' => 'sometimes|nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $fields = [
            'name', 'ein', 'email', 'phone', 'contact_name', 'contact_title',
            'street', 'city', 'state', 'zip', 'website', 'description', 'mission',
        ];
        $org->update($request->only($fields));

        return response()->json([
            'success' => true,
            'message' => 'Organization profile updated successfully',
            'data' => [
                'id' => $org->id,
                'name' => $org->name,
                'ein' => $org->ein,
                'email' => $org->email,
                'phone' => $org->phone,
                'contact_name' => $org->contact_name,
                'contact_title' => $org->contact_title,
                'street' => $org->street,
                'city' => $org->city,
                'state' => $org->state,
                'zip' => $org->zip,
                'website' => $org->website,
                'description' => $org->description,
                'mission' => $org->mission,
            ],
        ]);
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', function ($attr, $value, $fail) use ($request) {
                if (! Hash::check($value, $request->user()->password)) {
                    $fail('The current password is incorrect.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Get security preferences (2FA, biometric)
     */
    public function getSecurityPrefs(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'two_fa_enabled' => (bool) ($user->two_fa_enabled ?? false),
                'biometric_enabled' => (bool) ($user->biometric_enabled ?? false),
            ],
        ]);
    }

    /**
     * Update security preferences
     */
    public function updateSecurityPrefs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'two_fa_enabled' => 'sometimes|boolean',
            'biometric_enabled' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }
        $user = $request->user();
        if ($request->has('two_fa_enabled')) {
            $user->two_fa_enabled = $request->boolean('two_fa_enabled');
        }
        if ($request->has('biometric_enabled')) {
            $user->biometric_enabled = $request->boolean('biometric_enabled');
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated.',
            'data' => [
                'two_fa_enabled' => (bool) $user->two_fa_enabled,
                'biometric_enabled' => (bool) $user->biometric_enabled,
            ],
        ]);
    }

    /**
     * List active sessions (tokens) for the user
     */
    public function getSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = null;
        try {
            $token = $request->user()->token();
            if ($token) {
                $currentTokenId = $token->id;
            }
        } catch (\Throwable $e) {
            // token() may not exist on user in some setups
        }
        $tokens = $user->tokens()
            ->where('revoked', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($token) use ($currentTokenId) {
                $name = $token->name && $token->name !== 'auth_token' ? $token->name : 'Web Device';

                return [
                    'id' => $token->id,
                    'device_name' => $name,
                    'name' => $name,
                    'device_country' => $token->device_country ?? null,
                    'created_at' => $token->created_at->toIso8601String(),
                    'is_current' => $token->id === $currentTokenId,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => ['sessions' => $tokens],
        ]);
    }

    /**
     * Revoke all other sessions (keep current token)
     */
    public function revokeOtherSessions(Request $request)
    {
        $user = $request->user();
        $currentId = null;
        try {
            $token = $request->user()->token();
            if ($token) {
                $currentId = $token->id;
            }
        } catch (\Throwable $e) {
        }
        $revoked = 0;
        $user->tokens()->where('revoked', false)->each(function ($token) use ($currentId, &$revoked) {
            if ($token->id !== $currentId) {
                $token->revoke();
                $revoked++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => $revoked > 0 ? "Signed out {$revoked} other device(s)." : 'No other sessions to sign out.',
            'data' => ['revoked' => $revoked],
        ]);
    }

    /**
     * Revoke a single session (token) by id
     */
    public function revokeSession(Request $request, string $id)
    {
        $user = $request->user();
        $token = $user->tokens()->where('id', $id)->where('revoked', false)->first();
        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found or already signed out.',
            ], 404);
        }
        $token->revoke();

        return response()->json([
            'success' => true,
            'message' => 'Session signed out.',
        ]);
    }

    /**
     * Get user balance
     */
    public function getBalance(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $user->balance ?? 0,
                'currency' => 'USD',
            ],
        ]);
    }

    /**
     * Get user points
     */
    public function getPoints(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'reward_points' => $user->reward_points ?? 0,
                'believe_points' => $user->believe_points ?? 0,
                'gifted_believe_points' => $user->gifted_believe_points ?? 0,
                'purchased_believe_points' => $user->purchasedBelievePointsBalance(),
                'believe_points_total' => $user->totalBelievePointsBalance(),
            ],
        ]);
    }

    /**
     * Get user's posts for profile
     */
    public function getProfilePosts(Request $request)
    {
        $userId = $request->get('user_id', $request->user()->id);
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 10);

        $posts = Post::where('user_id', $userId)
            ->with(['user', 'reactions.user'])
            ->withCount(['reactions', 'comments'])
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage);

        // Load user reactions
        $currentUserId = $request->user()->id;
        $posts->getCollection()->transform(function ($post) use ($currentUserId) {
            $userReaction = $post->reactions()->where('user_id', $currentUserId)->first();
            $post->user_reaction = $userReaction;

            return $post;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'posts' => $posts->items(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ],
        ]);
    }

    /**
     * Get trending organizations
     */
    public function getTrendingOrganizations(Request $request)
    {
        $limit = $request->get('limit', 4);

        // Get organizations with most donations or followers
        $organizations = Organization::where('registration_status', 'approved')
            ->excludingCareAllianceHubs()
            ->withCount(['donations', 'users'])
            ->orderBy('donations_count', 'desc')
            ->orderBy('users_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'description' => $org->description,
                    'mission' => $org->mission,
                    'ico' => $org->ico,
                    'city' => $org->city,
                    'state' => $org->state,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $organizations,
        ]);
    }

    /**
     * Get suggested people to follow
     */
    public function getSuggestedPeople(Request $request)
    {
        $currentUser = $request->user();
        $limit = $request->get('limit', 4);

        // Get users that the current user is not following
        // For now, just get random users (you can improve this with actual follow logic)
        $suggestedUsers = User::where('id', '!=', $currentUser->id)
            ->whereNotNull('name')
            ->inRandomOrder()
            ->limit($limit)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'image' => $user->image,
                    'description' => 'Member of Believe in Unity',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $suggestedUsers,
        ]);
    }

    /**
     * Get suggested causes
     */
    public function getSuggestedCauses(Request $request)
    {
        // Return predefined causes based on NTEE codes or categories
        $causes = [
            [
                'id' => 1,
                'name' => 'Homelessness Support',
                'description' => 'Supporting individuals and families experiencing homelessness',
                'icon' => 'home',
                'color' => 'purple',
            ],
            [
                'id' => 2,
                'name' => 'Mental Health Advocacy',
                'description' => 'Promoting mental wellness and access to mental health services',
                'icon' => 'heart',
                'color' => 'purple',
            ],
            [
                'id' => 3,
                'name' => 'Environmental Advocacy',
                'description' => 'Protecting our environment and promoting sustainability',
                'icon' => 'leaf',
                'color' => 'green',
            ],
            [
                'id' => 4,
                'name' => 'Education & Youth Services',
                'description' => 'Supporting education and youth development programs',
                'icon' => 'book',
                'color' => 'blue',
            ],
            [
                'id' => 5,
                'name' => 'Faith-Based Initiatives',
                'description' => 'Faith-based community support and initiatives',
                'icon' => 'hands',
                'color' => 'purple',
            ],
            [
                'id' => 6,
                'name' => 'Children & Youth',
                'description' => 'Programs and services for children and youth',
                'icon' => 'users',
                'color' => 'blue',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $causes,
        ]);
    }
}
