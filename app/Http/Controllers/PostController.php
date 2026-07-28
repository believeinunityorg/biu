<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CareAlliance;
use App\Models\CommunityVideo;
use App\Models\ExcelData;
use App\Models\FundMeCampaign;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RewardPointLedger;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Models\UserFollow;
use App\Services\SeoService;
use App\Services\YouTubeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PostController extends Controller
{
    /**
     * Display a listing of posts for the feed.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();

        // Get all post IDs that user has seen
        $seenPostIds = DB::table('post_seen')
            ->where('user_id', $userId)
            ->pluck('post_id')
            ->toArray();

        // Get posts with unseen first, then seen posts (shorts belong only in the reels strip)
        $posts = Post::with([
            'user.organization',
            'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
            'attachedOrganization.user:id,slug,name,image',
            'attachedCampaign',
            'attachedFundMe',
            'reactions.user',
        ])
            ->where(function ($query) {
                $query->whereNull('post_type')
                    ->orWhere('post_type', '!=', Post::POST_TYPE_YOUTUBE_SHORT);
            })
            ->withCount(['reactions', 'comments'])
            ->when(count($seenPostIds) > 0, function ($query) use ($seenPostIds) {
                return $query->orderByRaw('CASE WHEN id IN ('.implode(',', $seenPostIds).') THEN 1 ELSE 0 END');
            })
            ->orderBy('created_at', 'DESC')
            ->paginate(10);

        // Load comments (only first 5 per post) and user reactions
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $userReaction = $post->reactions()->where('user_id', $userId)->first();
            $post->user_reaction = $userReaction;

            // Load only first 5 comments
            $comments = $post->comments()->with('user')->latest()->limit(5)->get();
            $post->comments = $comments;
            $post->has_more_comments = $post->comments_count > 5;

            $this->hydratePostCreatorForSocialFeed($post);

            return $post;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'posts' => $posts->items(),
                'next_page_url' => $posts->nextPageUrl(),
                'has_more' => $posts->hasMorePages(),
            ]);
        }

        // Get sidebar data
        $user = Auth::user();
        $peopleYouMayKnow = [];
        $trendingOrganizations = [];
        $userStats = [
            'postsCount' => 0,
            'believePointsBalance' => 0,
            'believePointsEarned' => 0,
            'rewardPointsBalance' => 0,
            'rewardPointsEarned' => 0,
            'followersCount' => 0,
        ];

        if ($user) {
            // Get user stats
            $userStats['postsCount'] = Post::where('user_id', $user->id)->count();
            $userStats['followersCount'] = UserFavoriteOrganization::where('user_id', $user->id)->count();

            // Get reward points
            $rewardPointsEarned = RewardPointLedger::where('user_id', $user->id)
                ->where('type', 'credit')
                ->sum('points');
            $rewardPointsSpent = RewardPointLedger::where('user_id', $user->id)
                ->where('type', 'debit')
                ->sum('points');
            $userStats['rewardPointsBalance'] = $rewardPointsEarned - $rewardPointsSpent;
            $userStats['rewardPointsEarned'] = $rewardPointsEarned;

            // Get people you may know (suggested organizations)
            $userFavoriteOrgIds = UserFavoriteOrganization::where('user_id', $user->id)
                ->pluck('organization_id')
                ->toArray();

            $suggestedOrgs = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->whereNotIn('id', $userFavoriteOrgIds)
                ->with('user:id,slug,name,image')
                ->limit(4)
                ->get();

            if ($suggestedOrgs->isNotEmpty()) {
                $eins = $suggestedOrgs->pluck('ein')->filter()->unique()->toArray();
                $excelDataMap = ExcelData::whereIn('ein', $eins)
                    ->where('status', 'complete')
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('ein')
                    ->map(function ($group) {
                        return $group->first()->id;
                    });

                $peopleYouMayKnow = $suggestedOrgs->map(function ($org) use ($excelDataMap) {
                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                        'slug' => $org->user?->slug ?? null,
                        'name' => $org->name,
                        'org' => $org->description ? \Illuminate\Support\Str::limit($org->description, 30) : 'Organization',
                        'avatar' => $org->user?->image ? Storage::url($org->user->image) : null,
                    ];
                })->toArray();
            }

            // Get trending organizations
            $trendingOrgs = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->withCount('followers')
                ->with('user:id,slug,name')
                ->orderBy('followers_count', 'desc')
                ->limit(4)
                ->get();

            if ($trendingOrgs->isNotEmpty()) {
                $eins = $trendingOrgs->pluck('ein')->filter()->unique()->toArray();
                $excelDataMap = ExcelData::whereIn('ein', $eins)
                    ->where('status', 'complete')
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('ein')
                    ->map(function ($group) {
                        return $group->first()->id;
                    });

                $colors = ['bg-rose-500', 'bg-cyan-500', 'bg-teal-500', 'bg-blue-500'];

                $trendingOrganizations = $trendingOrgs->map(function ($org, $index) use ($excelDataMap, $colors) {
                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                        'slug' => $org->user?->slug ?? null,
                        'name' => $org->name,
                        'desc' => $org->description ? \Illuminate\Support\Str::limit($org->description, 50) : 'Organization description',
                        'color' => $colors[$index % count($colors)],
                    ];
                })->toArray();
            }
        }

        return Inertia::render('frontend/social-feed', [
            'posts' => $posts->items(),
            'next_page_url' => $posts->nextPageUrl(),
            'has_more' => $posts->hasMorePages(),
            'userStats' => $userStats,
            'peopleYouMayKnow' => $peopleYouMayKnow,
            'trendingOrganizations' => $trendingOrganizations,
            'youtubeShortAttachOptions' => $this->buildYoutubeShortAttachOptionsForUser(Auth::user()),
            'feedReels' => $this->loadFeedReelsStrip(),
            'shortImportContext' => [
                'organization_id' => null,
                'campaign_id' => null,
                'fundme_id' => null,
            ],
        ]);
    }

    /**
     * Store a newly created post.
     */
    public function store(Request $request)
    {
        if ($request->input('post_type') === Post::POST_TYPE_YOUTUBE_SHORT) {
            return $this->storeYoutubeShortPost($request);
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048', // 2MB max per image (to avoid nginx 413 error)
        ]);

        $images = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('posts', 'public');
                $images[] = Storage::url($path);
            }
        }

        $post = Post::create([
            'user_id' => Auth::id(),
            'post_type' => Post::POST_TYPE_STANDARD,
            'content' => $validated['content'] ?? null,
            'images' => $images,
        ]);

        $post->load([
            'user.organization',
            'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
            'attachedOrganization.user:id,slug,name,image',
            'attachedCampaign',
            'attachedFundMe',
            'reactions',
            'comments',
        ]);
        $this->hydratePostCreatorForSocialFeed($post);

        return response()->json([
            'message' => 'Post created successfully',
            'post' => $post,
        ], 201);
    }

    /**
     * Update the specified post.
     */
    public function update(Request $request, Post $post)
    {
        if (in_array($post->post_type, [Post::POST_TYPE_YOUTUBE_SHORT, Post::POST_TYPE_YOUTUBE_VIDEO], true)) {
            return response()->json([
                'message' => 'YouTube posts cannot be edited.',
            ], 422);
        }

        $this->authorize('update', $post);

        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048', // 2MB max per image
            'existing_images' => 'nullable|array',
            'existing_images.*' => 'string',
            'images_to_remove' => 'nullable|array',
            'images_to_remove.*' => 'string',
        ]);

        $currentImages = $post->images ?? [];
        $imagesToKeep = $request->input('existing_images', []);
        $imagesToRemove = $request->input('images_to_remove', []);

        // Start with images to keep
        $finalImages = array_intersect($currentImages, $imagesToKeep);

        // Delete removed images
        foreach ($imagesToRemove as $imageUrl) {
            $path = str_replace('/storage/', '', $imageUrl);
            Storage::disk('public')->delete($path);
        }

        // Add new images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('posts', 'public');
                $finalImages[] = Storage::url($path);
            }
        }

        // Ensure we don't exceed 10 images
        $finalImages = array_slice($finalImages, 0, 10);

        // Validate: must have either content or images
        $content = trim($validated['content'] ?? '');
        if (empty($content) && empty($finalImages)) {
            return response()->json([
                'message' => 'Post must have either text content or at least one image.',
            ], 422);
        }

        $post->update([
            'content' => ! empty($content) ? $content : null,
            'images' => $finalImages,
            'is_edited' => true,
        ]);

        $post->load([
            'user.organization',
            'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
            'attachedOrganization.user:id,slug,name,image',
            'attachedCampaign',
            'attachedFundMe',
            'reactions',
            'comments',
        ]);
        $this->hydratePostCreatorForSocialFeed($post);

        return response()->json([
            'message' => 'Post updated successfully',
            'post' => $post,
        ]);
    }

    /**
     * Remove the specified post.
     */
    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        // Delete associated images
        if ($post->images) {
            foreach ($post->images as $image) {
                $path = str_replace('/storage/', '', $image);
                Storage::disk('public')->delete($path);
            }
        }

        $post->delete();

        return response()->json([
            'message' => 'Post deleted successfully',
        ]);
    }

    /**
     * Add or update reaction to a post.
     */
    public function react(Request $request, Post $post)
    {
        $validated = $request->validate([
            'type' => 'required|in:like,love,care,angry,haha',
        ]);

        $existingReaction = PostReaction::where('post_id', $post->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existingReaction && $existingReaction->type === $validated['type']) {
            // If same reaction, remove it (toggle off)
            $existingReaction->delete();
            $reaction = null;
        } else {
            // Update or create reaction
            $reaction = PostReaction::updateOrCreate(
                [
                    'post_id' => $post->id,
                    'user_id' => Auth::id(),
                ],
                [
                    'type' => $validated['type'],
                ]
            );
            // Load user relationship
            $reaction->load('user');
        }

        $reactionsCount = $post->reactions()->count();

        return response()->json([
            'message' => 'Reaction updated successfully',
            'reaction' => $reaction ? [
                'id' => $reaction->id,
                'type' => $reaction->type,
                'user_id' => $reaction->user_id,
                'user' => $reaction->user ? [
                    'id' => $reaction->user->id,
                    'name' => $reaction->user->name,
                    'image' => $reaction->user->image,
                ] : null,
            ] : null,
            'reactions_count' => $reactionsCount,
        ]);
    }

    /**
     * Remove reaction from a post.
     */
    public function removeReaction(Post $post)
    {
        PostReaction::where('post_id', $post->id)
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json([
            'message' => 'Reaction removed successfully',
        ]);
    }

    /**
     * Add a comment to a post.
     */
    public function comment(Request $request, Post $post)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => Auth::id(),
            'content' => $validated['content'],
        ]);

        $comment->load('user');

        return response()->json([
            'message' => 'Comment added successfully',
            'comment' => $comment,
        ], 201);
    }

    /**
     * Delete a comment.
     */
    public function deleteComment(PostComment $comment)
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully',
        ]);
    }

    /**
     * Get more comments for a post.
     */
    public function getComments(Post $post, Request $request)
    {
        $offset = $request->get('offset', 0);
        $limit = $request->get('limit', 10);

        $comments = $post->comments()
            ->with('user')
            ->latest()
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'comments' => $comments,
            'has_more' => $post->comments()->count() > ($offset + $limit),
        ]);
    }

    /**
     * Mark a post as seen.
     */
    public function markAsSeen(Post $post)
    {
        $userId = Auth::id();

        \DB::table('post_seen')->updateOrInsert(
            [
                'post_id' => $post->id,
                'user_id' => $userId,
            ],
            [
                'seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Post marked as seen',
        ]);
    }

    /**
     * Search for registered organizations and users (supporters)
     * Returns Inertia response with search results (called from searchPage)
     */
    public function search(Request $request)
    {
        // This method is now handled by searchPage directly
        // Redirect to search page with query params
        $searchTerm = trim($request->get('q', ''));
        $type = $request->get('type', 'all');

        return redirect()->route('search.index', [
            'q' => $searchTerm,
            'type' => $type,
        ]);
    }

    /**
     * Show the search page
     */
    public function searchPage(Request $request)
    {
        $user = Auth::user();

        // Get search query from request
        $searchQuery = trim($request->get('q', ''));
        $searchType = $request->get('type', 'all');

        // Perform search if query is provided (minimum 1 character)
        $searchResults = ['users' => [], 'organizations' => []];
        if (! empty($searchQuery) && strlen($searchQuery) >= 1) {
            $searchResults = $this->performSearch($searchQuery, $searchType, $user);
        }

        // Get sidebar data (same as index)
        $peopleYouMayKnow = [];
        $trendingOrganizations = [];
        $userStats = [
            'postsCount' => 0,
            'believePointsBalance' => 0,
            'believePointsEarned' => 0,
            'rewardPointsBalance' => 0,
            'rewardPointsEarned' => 0,
            'followersCount' => 0,
        ];

        if ($user) {
            // Get user stats
            $userStats['postsCount'] = Post::where('user_id', $user->id)->count();
            $userStats['followersCount'] = UserFavoriteOrganization::where('user_id', $user->id)->count();

            // Get reward points
            $rewardPointsEarned = RewardPointLedger::where('user_id', $user->id)
                ->where('type', 'credit')
                ->sum('points');
            $rewardPointsSpent = RewardPointLedger::where('user_id', $user->id)
                ->where('type', 'debit')
                ->sum('points');
            $userStats['rewardPointsBalance'] = $rewardPointsEarned - $rewardPointsSpent;
            $userStats['rewardPointsEarned'] = $rewardPointsEarned;

            // Get people you may know (suggested organizations)
            $userFavoriteOrgIds = UserFavoriteOrganization::where('user_id', $user->id)
                ->pluck('organization_id')
                ->toArray();

            $suggestedOrgs = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->whereNotIn('id', $userFavoriteOrgIds)
                ->with('user:id,slug,name,image')
                ->limit(4)
                ->get();

            if ($suggestedOrgs->isNotEmpty()) {
                $eins = $suggestedOrgs->pluck('ein')->filter()->unique()->toArray();
                $excelDataMap = ExcelData::whereIn('ein', $eins)
                    ->where('status', 'complete')
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('ein')
                    ->map(function ($group) {
                        return $group->first()->id;
                    });

                $peopleYouMayKnow = $suggestedOrgs->map(function ($org) use ($excelDataMap) {
                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                        'slug' => $org->user?->slug ?? null,
                        'name' => $org->name,
                        'org' => $org->description ? \Illuminate\Support\Str::limit($org->description, 30) : 'Organization',
                        'avatar' => $org->user?->image ? Storage::url($org->user->image) : null,
                    ];
                })->toArray();
            }

            // Get trending organizations
            $trendingOrgs = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->withCount('followers')
                ->with('user:id,slug,name')
                ->orderBy('followers_count', 'desc')
                ->limit(4)
                ->get();

            if ($trendingOrgs->isNotEmpty()) {
                $eins = $trendingOrgs->pluck('ein')->filter()->unique()->toArray();
                $excelDataMap = ExcelData::whereIn('ein', $eins)
                    ->where('status', 'complete')
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('ein')
                    ->map(function ($group) {
                        return $group->first()->id;
                    });

                $colors = ['bg-rose-500', 'bg-cyan-500', 'bg-teal-500', 'bg-blue-500'];

                $trendingOrganizations = $trendingOrgs->map(function ($org, $index) use ($excelDataMap, $colors) {
                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                        'slug' => $org->user?->slug ?? null,
                        'name' => $org->name,
                        'desc' => $org->description ? \Illuminate\Support\Str::limit($org->description, 50) : 'Organization description',
                        'color' => $colors[$index % count($colors)],
                    ];
                })->toArray();
            }
        }

        return Inertia::render('frontend/search', [
            'seo' => SeoService::forPage('search'),
            'userStats' => $userStats,
            'peopleYouMayKnow' => $peopleYouMayKnow,
            'trendingOrganizations' => $trendingOrganizations,
            'searchResults' => $searchResults,
            'searchQuery' => $searchQuery,
            'searchType' => $searchType,
        ]);
    }

    /**
     * Perform search and return results (extracted for reuse)
     */
    private function performSearch($searchTerm, $type, $user)
    {
        $results = [
            'users' => [],
            'organizations' => [],
        ];

        // Search users (supporters) - only registered users with 'user' role
        if ($type === 'all' || $type === 'users') {
            $usersQuery = User::whereHas('roles', function ($query) {
                $query->where('name', 'user');
            })
                ->whereDoesntHave('roles', function ($query) {
                    $query->whereIn('name', ['admin', 'organization', 'organization_pending']);
                })
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'LIKE', '%'.$searchTerm.'%')
                        ->orWhere('email', 'LIKE', '%'.$searchTerm.'%')
                        ->orWhere('slug', 'LIKE', '%'.$searchTerm.'%');
                });

            // Exclude current user if authenticated
            if ($user) {
                $usersQuery->where('id', '!=', $user->id);
            }

            $users = $usersQuery->select('id', 'name', 'email', 'slug', 'image')
                ->limit(20)
                ->get()
                ->map(function ($userResult) use ($user) {
                    // Check if current user is following this user
                    $isFollowing = false;
                    if ($user) {
                        $isFollowing = UserFollow::where('follower_id', $user->id)
                            ->where('following_id', $userResult->id)
                            ->exists();
                    }

                    return [
                        'id' => $userResult->id,
                        'name' => $userResult->name,
                        'email' => $userResult->email,
                        'slug' => $userResult->slug,
                        'image' => $userResult->image ? Storage::url($userResult->image) : null,
                        'is_following' => $isFollowing,
                        'type' => 'user',
                    ];
                });

            $results['users'] = $users;
        }

        // Search registered organizations
        if ($type === 'all' || $type === 'organizations') {
            $organizations = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'LIKE', '%'.$searchTerm.'%')
                        ->orWhere('email', 'LIKE', '%'.$searchTerm.'%')
                        ->orWhereHas('user', function ($q) use ($searchTerm) {
                            $q->where('slug', 'LIKE', '%'.$searchTerm.'%')
                                ->orWhere('name', 'LIKE', '%'.$searchTerm.'%');
                        });
                })
                ->with('user:id,slug,name,image')
                ->limit(20)
                ->get()
                ->map(function ($org) use ($user) {
                    // Get excel_data_id for this organization (needed for toggleFavorite)
                    $excelData = ExcelData::where('ein', $org->ein)
                        ->where('status', 'complete')
                        ->orderBy('id', 'desc')
                        ->first();

                    $excelDataId = $excelData ? $excelData->id : null;

                    $isFollowing = false;
                    if ($user) {
                        // Check both organization_id and excel_data_id for following status
                        $isFollowing = UserFavoriteOrganization::where('user_id', $user->id)
                            ->where(function ($query) use ($org, $excelDataId) {
                                $query->where('organization_id', $org->id);
                                if ($excelDataId) {
                                    $query->orWhere('excel_data_id', $excelDataId);
                                }
                            })
                            ->exists();
                    }

                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataId, // Add excel_data_id for toggleFavorite
                        'name' => $org->name,
                        'email' => $org->email,
                        'slug' => $org->user?->slug,
                        'image' => $org->user?->image ? Storage::url($org->user->image) : null,
                        'description' => $org->description ? \Illuminate\Support\Str::limit($org->description, 100) : null,
                        'is_following' => $isFollowing,
                        'type' => 'organization',
                    ];
                });

            $results['organizations'] = $organizations;
        }

        return $results;
    }

    /**
     * Recent YouTube Short posts for the reels-style strip at top of the feed.
     *
     * @return list<Post>
     */
    protected function loadFeedReelsStrip(): array
    {
        $reelsPosts = Post::query()
            ->with([
                'user.organization',
                'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
                'attachedOrganization.user:id,slug,name,image',
                'attachedCampaign',
                'attachedFundMe',
            ])
            ->where('post_type', Post::POST_TYPE_YOUTUBE_SHORT)
            ->orderByDesc('created_at')
            ->limit(32)
            ->get();

        foreach ($reelsPosts as $post) {
            $this->hydratePostCreatorForSocialFeed($post);
        }

        return $reelsPosts->values()->all();
    }

    /**
     * Public URL for a user/org profile image stored on the public disk.
     */
    protected function socialFeedImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::url($path);
    }

    /**
     * Sets creator_name / creator_slug / creator_type for social feed (Care Alliance vs org vs user).
     */
    protected function hydratePostCreatorForSocialFeed(Post $post): void
    {
        if ($post->organization_id && ($org = $post->attachedOrganization) && $org->user) {
            $imageUrl = $this->socialFeedImageUrl($org->user->image);
            $post->creator = [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->user->slug,
                'image' => $imageUrl,
            ];
            $post->creator_type = 'organization';
            $post->creator_name = $org->name;
            $post->creator_slug = $org->user->slug;
            $post->creator_image = $imageUrl;
            $this->hydrateYouTubeEmbedPresentation($post);

            return;
        }

        if (! $post->user) {
            $this->hydrateYouTubeEmbedPresentation($post);

            return;
        }

        $user = $post->user;
        $userImageUrl = $this->socialFeedImageUrl($user->image);
        $post->creator = null;
        $post->creator_type = 'user';
        $post->creator_name = $user->name;
        $post->creator_slug = $user->slug;
        $post->creator_image = $userImageUrl;

        $isCareAllianceAuthor = $user->role === 'care_alliance' || $user->hasRole('care_alliance');

        if ($isCareAllianceAuthor) {
            $alliance = $user->relationLoaded('createdCareAlliances')
                ? $user->createdCareAlliances->first()
                : CareAlliance::query()
                    ->where('creator_user_id', $user->id)
                    ->where('status', 'active')
                    ->orderByDesc('id')
                    ->first();
            if ($alliance) {
                $post->creator = [
                    'id' => $alliance->id,
                    'name' => $alliance->name,
                    'slug' => $alliance->slug,
                    'image' => $userImageUrl,
                ];
                $post->creator_type = 'care_alliance';
                $post->creator_name = $alliance->name;
                $post->creator_slug = $alliance->slug;
                $post->creator_image = $userImageUrl;
            }
            $this->hydrateYouTubeEmbedPresentation($post);

            return;
        }

        if ($user->role === 'organization' && $user->organization) {
            $org = $user->organization;
            $post->creator = [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $user->slug,
                'image' => $userImageUrl,
            ];
            $post->creator_type = 'organization';
            $post->creator_name = $org->name;
            $post->creator_slug = $user->slug;
            $post->creator_image = $userImageUrl;
        }

        $this->hydrateYouTubeEmbedPresentation($post);
    }

    protected function hydrateYouTubeEmbedPresentation(Post $post): void
    {
        $post->attach_context = null;
        $post->youtube_embed_url = null;

        if (! in_array($post->post_type, [Post::POST_TYPE_YOUTUBE_SHORT, Post::POST_TYPE_YOUTUBE_VIDEO], true)) {
            return;
        }

        if ($post->youtube_video_id) {
            $id = rawurlencode($post->youtube_video_id);
            $post->youtube_embed_url = 'https://www.youtube.com/embed/'.$id
                .'?playsinline=1&modestbranding=1&rel=0';
        }

        $label = null;
        $href = null;
        if ($post->campaign_id && $post->attachedCampaign) {
            $label = $post->attachedCampaign->name ?? 'Campaign';
            $href = route('campaigns.show', ['campaign' => $post->campaign_id]);
        }
        if ((! $label) && $post->fundme_id && $post->attachedFundMe) {
            $label = $post->attachedFundMe->title ?? 'FundMe';
            $slug = $post->attachedFundMe->slug ?? null;
            $href = $slug ? route('fundme.show', ['slug' => $slug]) : null;
        }
        if ((! $label) && $post->organization_id && $post->attachedOrganization) {
            $org = $post->attachedOrganization;
            $slug = $org->user?->slug;
            if ($slug) {
                $label = $org->name ?? 'Organization';
                $href = route('organizations.show', ['slug' => $slug]);
            }
        }

        if ($label) {
            $post->attach_context = [
                'label' => $label,
                'href' => $href,
            ];
        }
    }

    /**
     * Share an already-imported YouTube video from Video Hub to the social feed (no re-upload).
     */
    public function shareVideoFromHub(Request $request)
    {
        $validated = $request->validate([
            'youtube_video_id' => 'required|string|max:32',
            'watch_url' => 'required|string|max:2048',
            'title' => 'required|string|max:500',
            'thumbnail_url' => 'nullable|string|max:2048',
            'duration' => 'nullable|string|max:32',
            'attach_type' => 'nullable|string|in:profile,organization,campaign,fundme',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
            'fundme_id' => 'nullable|integer|exists:fundme_campaigns,id',
        ]);

        $videoId = trim($validated['youtube_video_id']);
        $watchUrl = trim($validated['watch_url']);
        $extracted = $this->extractYoutubeVideoId($watchUrl);
        if (! $extracted || $extracted !== $videoId) {
            return response()->json([
                'message' => 'Video link does not match the selected video.',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        // Hub cards may omit organization_id or send the channel owner’s org. Only attach when this
        // user may post for that org; otherwise share as a personal feed post.
        $allowedOrgIds = $this->collectAllowedOrganizationIdsForUser($user);
        $hubOrgId = isset($validated['organization_id']) ? (int) $validated['organization_id'] : null;
        if ($hubOrgId && ! in_array($hubOrgId, $allowedOrgIds, true)) {
            unset($validated['organization_id']);
        }

        if (Post::query()->where('user_id', $user->id)->where('youtube_video_id', $videoId)->exists()) {
            return response()->json([
                'message' => 'You already shared this video to the community feed.',
            ], 422);
        }

        $attachment = $this->resolveYoutubeShortAttachment($validated, $user);
        if ($attachment instanceof \Illuminate\Http\JsonResponse) {
            return $attachment;
        }

        ['organizationId' => $organizationId, 'campaignId' => $campaignId, 'fundmeId' => $fundmeId] = $attachment;

        $durationDisplay = isset($validated['duration']) ? (string) $validated['duration'] : '';
        $durationSecondsFromClient = (int) $request->input('duration_seconds', 0);
        $parsedSeconds = $this->parseYoutubeDurationDisplayToSeconds($durationDisplay);
        $durationSeconds = $durationSecondsFromClient > 0 ? $durationSecondsFromClient : $parsedSeconds;

        $details = app(YouTubeService::class)->getVideoDetails($videoId);
        if (is_array($details)) {
            if (isset($details['duration_seconds'])) {
                $durationSeconds = max($durationSeconds, (int) $details['duration_seconds']);
            }
            if (empty($validated['thumbnail_url'] ?? null) && ! empty($details['thumbnail_url'])) {
                $validated['thumbnail_url'] = (string) $details['thumbnail_url'];
            }
        }

        $thumbnailUrl = ! empty($validated['thumbnail_url'] ?? null)
            ? (string) $validated['thumbnail_url']
            : sprintf('https://img.youtube.com/vi/%s/maxresdefault.jpg', $videoId);

        $videoTitle = Str::limit(trim($validated['title']), 255);
        $postType = $this->hubVideoLooksLikeShort($watchUrl, $durationDisplay, $durationSeconds)
            ? Post::POST_TYPE_YOUTUBE_SHORT
            : Post::POST_TYPE_YOUTUBE_VIDEO;

        $category = $postType === Post::POST_TYPE_YOUTUBE_SHORT ? 'shorts' : 'videos';

        $postBody = $videoTitle;

        $post = DB::transaction(function () use ($user, $watchUrl, $videoId, $thumbnailUrl, $organizationId, $campaignId, $fundmeId, $postBody, $videoTitle, $durationSeconds, $postType, $category) {
            $communityVideo = CommunityVideo::updateOrCreate(
                ['youtube_video_id' => $videoId],
                [
                    'title' => $videoTitle,
                    'slug' => 'yt-'.$videoId,
                    'description' => null,
                    'thumbnail_url' => $thumbnailUrl,
                    'video_url' => $watchUrl,
                    'duration_seconds' => max(0, $durationSeconds),
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'category' => $category,
                ]
            );

            return Post::create([
                'user_id' => $user->id,
                'post_type' => $postType,
                'content' => $postBody,
                'caption' => $postBody,
                'images' => [],
                'youtube_url' => $watchUrl,
                'youtube_video_id' => $videoId,
                'thumbnail_url' => $thumbnailUrl,
                'organization_id' => $organizationId,
                'campaign_id' => $campaignId,
                'fundme_id' => $fundmeId,
                'community_video_id' => $communityVideo->id,
                'visibility' => 'public',
            ]);
        });

        $post->load([
            'user.organization',
            'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
            'attachedOrganization.user:id,slug,name,image',
            'attachedCampaign',
            'attachedFundMe',
            'reactions',
            'comments',
        ]);
        $this->hydratePostCreatorForSocialFeed($post);

        $msg = $postType === Post::POST_TYPE_YOUTUBE_SHORT
            ? 'Your YouTube Short was added to Shorts.'
            : 'Your video was shared to the community feed.';

        return response()->json([
            'message' => $msg,
            'post' => $post,
        ], 201);
    }

    protected function hubVideoLooksLikeShort(string $watchUrl, string $durationDisplay, int $durationSeconds): bool
    {
        if (str_contains(strtolower($watchUrl), '/shorts/')) {
            return true;
        }
        if ($durationDisplay !== '' && preg_match('/^(?:0:\d{1,2}|1:00)$/', $durationDisplay)) {
            return true;
        }
        if ($durationSeconds > 0 && $durationSeconds <= 60) {
            return true;
        }

        return false;
    }

    protected function parseYoutubeDurationDisplayToSeconds(?string $d): int
    {
        if ($d === null || $d === '' || $d === 'LIVE') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{2})$/', $d, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $d, $m)) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }

        return 0;
    }

    protected function extractYoutubeVideoId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('~(?:www\.)?youtube\.com/shorts/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~(?:www\.)?youtube\.com/watch\?[^#]*\bv=([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtu\.be/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/embed/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~m\.youtube\.com/shorts/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{organizations: array<int, array{id: int, name: string}>, campaigns: array<int, array{id: int, name: string}>, fundmes: array<int, array{id: int, title: string, slug: string|null}>}
     */
    protected function buildYoutubeShortAttachOptionsForUser(?User $user): array
    {
        if (! $user) {
            return [
                'organizations' => [],
                'campaigns' => [],
                'fundmes' => [],
            ];
        }

        $user->loadMissing(['organizations']);
        $allowedOrgIds = $this->collectAllowedOrganizationIdsForUser($user);

        $organizations = [];
        if ($allowedOrgIds !== []) {
            $organizations = Organization::query()
                ->whereIn('id', $allowedOrgIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])
                ->values()
                ->all();
        }

        $campaigns = Campaign::query()
            ->where(function ($q) use ($user, $allowedOrgIds) {
                $q->where('user_id', $user->id);
                if ($allowedOrgIds !== []) {
                    $q->orWhereIn('organization_id', $allowedOrgIds);
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (Campaign $c) => ['id' => $c->id, 'name' => $c->name])
            ->values()
            ->all();

        $fundmes = [];
        if ($allowedOrgIds !== []) {
            $fundmes = FundMeCampaign::query()
                ->whereIn('organization_id', $allowedOrgIds)
                ->where('status', FundMeCampaign::STATUS_LIVE)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'title', 'slug'])
                ->map(fn (FundMeCampaign $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'slug' => $f->slug,
                ])
                ->values()
                ->all();
        }

        return [
            'organizations' => $organizations,
            'campaigns' => $campaigns,
            'fundmes' => $fundmes,
        ];
    }

    /**
     * @return list<int>
     */
    protected function collectAllowedOrganizationIdsForUser(User $user): array
    {
        $ids = collect();

        $owned = Organization::query()->where('user_id', $user->id)->pluck('id');
        $ids = $ids->merge($owned);

        $boardIds = $user->organizations()->pluck('organizations.id');
        $ids = $ids->merge($boardIds);

        $primary = Organization::forAuthUser($user);
        if ($primary) {
            $ids->push($primary->id);
        }

        return $ids->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    protected function extractYoutubeShortsVideoId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('~(?:www\.)?youtube\.com/shorts/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~m\.youtube\.com/shorts/([a-zA-Z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function storeYoutubeShortPost(Request $request)
    {
        $validated = $request->validate([
            'youtube_url' => 'required|string|max:2048',
            'caption' => 'nullable|string|max:5000',
            'attach_type' => 'nullable|string|in:profile,organization,campaign,fundme',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
            'fundme_id' => 'nullable|integer|exists:fundme_campaigns,id',
        ]);

        $url = trim($validated['youtube_url']);
        $videoId = $this->extractYoutubeShortsVideoId($url);
        if (! $videoId) {
            return response()->json([
                'message' => 'Please paste a valid YouTube Shorts link (for example https://www.youtube.com/shorts/xxxxxxxxxxx).',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $attachment = $this->resolveYoutubeShortAttachment($validated, $user);
        if ($attachment instanceof \Illuminate\Http\JsonResponse) {
            return $attachment;
        }

        ['organizationId' => $organizationId, 'campaignId' => $campaignId, 'fundmeId' => $fundmeId] = $attachment;

        $thumbnailUrl = sprintf('https://img.youtube.com/vi/%s/maxresdefault.jpg', $videoId);
        $videoTitle = 'YouTube Short';
        $durationSeconds = 0;
        $videoDescription = null;

        $details = app(YouTubeService::class)->getVideoDetails($videoId);
        if (is_array($details)) {
            if (! empty($details['thumbnail_url'])) {
                $thumbnailUrl = (string) $details['thumbnail_url'];
            }
            if (! empty($details['title'])) {
                $videoTitle = (string) $details['title'];
            }
            if (isset($details['duration_seconds'])) {
                $durationSeconds = max(0, (int) $details['duration_seconds']);
            }
            if (! empty($details['description'])) {
                $videoDescription = Str::limit((string) $details['description'], 2000);
            }
        }

        $manualCaption = isset($validated['caption']) ? trim((string) $validated['caption']) : '';
        $postBody = $manualCaption !== '' ? $manualCaption : $videoTitle;

        $post = DB::transaction(function () use ($user, $url, $videoId, $thumbnailUrl, $organizationId, $campaignId, $fundmeId, $postBody, $videoTitle, $durationSeconds, $videoDescription) {
            $communityVideo = CommunityVideo::updateOrCreate(
                ['youtube_video_id' => $videoId],
                [
                    'title' => Str::limit($videoTitle, 255),
                    'slug' => 'yt-'.$videoId,
                    'description' => $videoDescription,
                    'thumbnail_url' => $thumbnailUrl,
                    'video_url' => $url,
                    'duration_seconds' => $durationSeconds,
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'category' => 'shorts',
                ]
            );

            return Post::create([
                'user_id' => $user->id,
                'post_type' => Post::POST_TYPE_YOUTUBE_SHORT,
                'content' => $postBody,
                'caption' => $postBody,
                'images' => [],
                'youtube_url' => $url,
                'youtube_video_id' => $videoId,
                'thumbnail_url' => $thumbnailUrl,
                'organization_id' => $organizationId,
                'campaign_id' => $campaignId,
                'fundme_id' => $fundmeId,
                'community_video_id' => $communityVideo->id,
                'visibility' => 'public',
            ]);
        });

        $post->load([
            'user.organization',
            'user.createdCareAlliances' => fn ($q) => $q->where('status', 'active')->orderByDesc('id'),
            'attachedOrganization.user:id,slug,name,image',
            'attachedCampaign',
            'attachedFundMe',
            'reactions',
            'comments',
        ]);
        $this->hydratePostCreatorForSocialFeed($post);

        return response()->json([
            'message' => 'Your YouTube Short was added to Shorts.',
            'post' => $post,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{organizationId: ?int, campaignId: ?int, fundmeId: ?int}|\Illuminate\Http\JsonResponse
     */
    protected function resolveYoutubeShortAttachment(array $validated, User $user): array|\Illuminate\Http\JsonResponse
    {
        $allowedOrgIds = $this->collectAllowedOrganizationIdsForUser($user);

        $organizationId = null;
        $campaignId = null;
        $fundmeId = null;

        if (! empty($validated['attach_type'] ?? null)) {
            switch ($validated['attach_type']) {
                case 'profile':
                    break;
                case 'organization':
                    $oid = isset($validated['organization_id']) ? (int) $validated['organization_id'] : null;
                    if (! $oid || ! in_array($oid, $allowedOrgIds, true)) {
                        return response()->json([
                            'message' => 'Choose an organization you are allowed to post for.',
                        ], 422);
                    }
                    $organizationId = $oid;
                    break;
                case 'campaign':
                    $cid = isset($validated['campaign_id']) ? (int) $validated['campaign_id'] : null;
                    if (! $cid) {
                        return response()->json([
                            'message' => 'Select a campaign to attach.',
                        ], 422);
                    }
                    $campaign = Campaign::query()->find($cid);
                    if (! $campaign) {
                        return response()->json([
                            'message' => 'Campaign not found.',
                        ], 422);
                    }
                    $canPostCampaign = ((int) $campaign->user_id === (int) $user->id)
                        || in_array((int) $campaign->organization_id, $allowedOrgIds, true);
                    if (! $canPostCampaign) {
                        return response()->json([
                            'message' => 'You cannot attach this campaign.',
                        ], 422);
                    }
                    $campaignId = $campaign->id;
                    $organizationId = (int) $campaign->organization_id;
                    break;
                case 'fundme':
                    $fid = isset($validated['fundme_id']) ? (int) $validated['fundme_id'] : null;
                    if (! $fid) {
                        return response()->json([
                            'message' => 'Select a FundMe campaign to attach.',
                        ], 422);
                    }
                    $fundme = FundMeCampaign::query()->find($fid);
                    if (! $fundme || $fundme->status !== FundMeCampaign::STATUS_LIVE) {
                        return response()->json([
                            'message' => 'FundMe campaign not available.',
                        ], 422);
                    }
                    if (! in_array((int) $fundme->organization_id, $allowedOrgIds, true)) {
                        return response()->json([
                            'message' => 'You cannot attach this FundMe campaign.',
                        ], 422);
                    }
                    $fundmeId = $fundme->id;
                    $organizationId = (int) $fundme->organization_id;
                    break;
                default:
                    return response()->json(['message' => 'Invalid attach type.'], 422);
            }

            return compact('organizationId', 'campaignId', 'fundmeId');
        }

        // Import flow: infer from optional IDs (fundme > campaign > organization), else personal
        if (! empty($validated['fundme_id'] ?? null)) {
            $fid = (int) $validated['fundme_id'];
            $fundme = FundMeCampaign::query()->find($fid);
            if (! $fundme || $fundme->status !== FundMeCampaign::STATUS_LIVE) {
                return response()->json([
                    'message' => 'FundMe campaign not available.',
                ], 422);
            }
            if (! in_array((int) $fundme->organization_id, $allowedOrgIds, true)) {
                return response()->json([
                    'message' => 'You cannot attach this FundMe campaign.',
                ], 422);
            }
            $fundmeId = $fundme->id;
            $organizationId = (int) $fundme->organization_id;

            return compact('organizationId', 'campaignId', 'fundmeId');
        }

        if (! empty($validated['campaign_id'] ?? null)) {
            $cid = (int) $validated['campaign_id'];
            $campaign = Campaign::query()->find($cid);
            if (! $campaign) {
                return response()->json([
                    'message' => 'Campaign not found.',
                ], 422);
            }
            $canPostCampaign = ((int) $campaign->user_id === (int) $user->id)
                || in_array((int) $campaign->organization_id, $allowedOrgIds, true);
            if (! $canPostCampaign) {
                return response()->json([
                    'message' => 'You cannot attach this campaign.',
                ], 422);
            }
            $campaignId = $campaign->id;
            $organizationId = (int) $campaign->organization_id;

            return compact('organizationId', 'campaignId', 'fundmeId');
        }

        if (! empty($validated['organization_id'] ?? null)) {
            $oid = (int) $validated['organization_id'];
            if (! in_array($oid, $allowedOrgIds, true)) {
                return response()->json([
                    'message' => 'Choose an organization you are allowed to post for.',
                ], 422);
            }
            $organizationId = $oid;
        }

        return compact('organizationId', 'campaignId', 'fundmeId');
    }
}

