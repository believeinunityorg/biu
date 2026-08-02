<?php

namespace App\Services;

use App\Enums\MembershipAccountType;
use App\Models\CareAlliance;
use App\Models\CareAllianceCampaign;
use App\Models\CareAllianceMembership;
use App\Models\Donation;
use App\Models\Event;
use App\Models\ExcelData;
use App\Models\FacebookPost;
use App\Models\Group;
use App\Models\JobPost;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\GroupEligibilityService;
use App\Services\MembershipAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the same Inertia props as organization public profiles, for Unity Impact Alliance public URLs.
 */
class CareAlliancePublicPageService
{
    public function resolveAlliance(string $allianceSlug): CareAlliance
    {
        $slug = rawurldecode($allianceSlug);

        return CareAlliance::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with(['creator:id,slug,name,email,image,cover_img', 'primaryActionCategories:id,name'])
            ->firstOrFail();
    }

    /**
     * @return array<int, int>
     */
    public function memberOrganizationIds(CareAlliance $alliance): array
    {
        return CareAllianceMembership::query()
            ->where('care_alliance_id', $alliance->id)
            ->where('status', 'active')
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function syntheticOrganization(CareAlliance $alliance, ?Organization $creatorOrg, User $creator): array
    {
        $desc = $alliance->description ?? '';

        // Excel row id still used for favorite lookup (legacy rows keyed by excel_data_id / org).
        $excelIdForToggle = null;
        if ($creatorOrg && $creatorOrg->ein) {
            $excelIdForToggle = ExcelData::query()
                ->where('ein', $creatorOrg->ein)
                ->where('status', 'complete')
                ->orderByDesc('id')
                ->value('id');
        }
        /**
         * Follow POST must use care_alliances.id + context "alliance". Using context "organization" with the hub's
         * organizations.id fails: OrganizationController looks up approved orgs with excludingCareAllianceHubs(), and
         * hub nonprofits are excluded → 404 "page not found" on follow.
         */
        $toggleFavoriteId = (int) $alliance->id;

        // Favorites row may key off hub org id, Excel-linked org id, or excel_data_id — match what toggleFavorite stores.
        $orgIdsForFavoriteLookup = [];
        if ($creatorOrg) {
            $orgIdsForFavoriteLookup[] = (int) $creatorOrg->id;
        }
        if ($excelIdForToggle) {
            $excelRow = ExcelData::query()->find((int) $excelIdForToggle);
            if ($excelRow && $excelRow->ein) {
                $orgViaExcel = Organization::query()
                    ->where('ein', $excelRow->ein)
                    ->where('registration_status', 'approved')
                    ->value('id');
                if ($orgViaExcel) {
                    $orgIdsForFavoriteLookup[] = (int) $orgViaExcel;
                }
            }
        }
        $orgIdsForFavoriteLookup = array_values(array_unique(array_filter($orgIdsForFavoriteLookup)));

        $hubFavorite = null;
        if (Auth::check() && ($orgIdsForFavoriteLookup !== [] || $excelIdForToggle)) {
            $hubFavorite = UserFavoriteOrganization::query()
                ->where('user_id', Auth::id())
                ->where(function ($q) use ($orgIdsForFavoriteLookup, $excelIdForToggle) {
                    if ($orgIdsForFavoriteLookup !== []) {
                        $q->whereIn('organization_id', $orgIdsForFavoriteLookup);
                    }
                    if ($excelIdForToggle) {
                        if ($orgIdsForFavoriteLookup !== []) {
                            $q->orWhere('excel_data_id', (int) $excelIdForToggle);
                        } else {
                            $q->where('excel_data_id', (int) $excelIdForToggle);
                        }
                    }
                })
                ->first();
        }
        // Alliance-only follow row (no hub nonprofit linked yet) — same Follow button on public page.
        if (! $hubFavorite && Auth::check()) {
            $hubFavorite = UserFavoriteOrganization::query()
                ->where('user_id', Auth::id())
                ->where('care_alliance_id', $alliance->id)
                ->first();
        }

        $toggleFavoriteContext = 'alliance';

        return [
            /** Hub org id when present (display / compatibility); not used for toggle-favorite URL (see toggle_favorite_id). */
            'id' => $creatorOrg !== null ? (int) $creatorOrg->id : (int) $alliance->id,
            /** POST /organizations/{id}/toggle-favorite — always care_alliances.id with toggle_favorite_context=alliance. */
            'toggle_favorite_id' => $toggleFavoriteId,
            /**
             * Disambiguates numeric id vs excel_data / organizations / care_alliances primary keys.
             * Must be sent with toggle-favorite and toggle-notifications for Unity Impact Alliance public pages.
             */
            'toggle_favorite_context' => $toggleFavoriteContext,
            'ein' => $alliance->ein ?? '',
            'is_own_organization' => Auth::check() && (int) Auth::id() === (int) $alliance->creator_user_id,
            'name' => $alliance->name,
            'ico' => '',
            'street' => '',
            'city' => $alliance->city ?? '',
            'state' => $alliance->state ?? '',
            'zip' => '',
            'zipcode' => '',
            'classification' => '',
            'ntee_code' => '',
            'created_at' => $alliance->created_at,
            'is_registered' => true,
            'is_care_alliance_public' => true,
            'is_favorited' => (bool) $hubFavorite,
            'notifications_enabled' => (bool) ($hubFavorite?->notifications ?? false),
            /** When false, the hub nonprofit record is missing — follow uses hub org favorites. */
            'alliance_hub_follow_enabled' => (bool) $creatorOrg,
            'registered_organization' => [
                'id' => $creatorOrg?->id,
                'name' => $alliance->name,
                'user' => [
                    'id' => $creator->id,
                    'slug' => $alliance->slug,
                    'name' => $creator->name,
                    'email' => $creator->email,
                    'image' => $creator->image,
                    'cover_img' => $creator->cover_img,
                ],
            ],
            'description' => $desc !== '' ? $desc : 'This Unity Impact Alliance partners with nonprofits for shared fundraising.',
            'mission' => '',
            'website' => $alliance->website,
            'wefunder_project_url' => null,
            'ruling' => 'N/A',
            'phone' => $creatorOrg?->phone,
            'email' => $creatorOrg?->email ?? $creator->email,
            'contact_name' => $creatorOrg?->contact_name,
            'contact_title' => $creatorOrg?->contact_title,
            'social_accounts' => $creatorOrg?->social_accounts ?? [],
        ];
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array{believePointsEarned: float|int, believePointsSpent: int, believePointsBalance: float|int}
     */
    public function aggregateBelievePoints(array $memberIds): array
    {
        if ($memberIds === []) {
            return [
                'believePointsEarned' => 0,
                'believePointsSpent' => 0,
                'believePointsBalance' => 0,
            ];
        }

        $earned = Donation::query()
            ->whereIn('organization_id', $memberIds)
            ->where('payment_method', 'believe_points')
            ->where('status', 'completed')
            ->sum('amount');

        return [
            'believePointsEarned' => $earned,
            'believePointsSpent' => 0,
            'believePointsBalance' => $earned,
        ];
    }

    /**
     * Alliance public pages only surface the hub account + hub org — no member-org aggregation.
     *
     * @return array{peopleYouMayKnow: array<int, array<string, mixed>>, trendingOrganizations: array<int, array<string, mixed>>, eventsCount: int}
     */
    public function allianceHubSidebar(?int $hubOrganizationId): array
    {
        return [
            'peopleYouMayKnow' => [],
            'trendingOrganizations' => [],
            'eventsCount' => $hubOrganizationId
                ? Event::where('organization_id', $hubOrganizationId)->count()
                : 0,
        ];
    }

    /**
     * Counts for hub user posts + hub org Facebook / jobs / favorites only.
     *
     * @return array{postsCount: int, supportersCount: int, jobsCount: int}
     */
    public function hubAllianceCounts(int $creatorUserId, ?int $hubOrganizationId, ?int $careAllianceId = null): array
    {
        $postsCount = Post::where('user_id', $creatorUserId)->count();
        if ($hubOrganizationId) {
            $postsCount += FacebookPost::where('organization_id', $hubOrganizationId)
                ->where('status', 'published')
                ->count();
        }

        $supporterUserIds = collect();
        if ($hubOrganizationId) {
            $supporterUserIds = $supporterUserIds->merge(
                UserFavoriteOrganization::query()
                    ->where('organization_id', $hubOrganizationId)
                    ->pluck('user_id')
            );
        }
        if ($careAllianceId !== null) {
            $supporterUserIds = $supporterUserIds->merge(
                UserFavoriteOrganization::query()
                    ->where('care_alliance_id', $careAllianceId)
                    ->pluck('user_id')
            );
        }
        $supportersCount = $supporterUserIds->unique()->count();

        $jobsCount = $hubOrganizationId
            ? JobPost::where('organization_id', $hubOrganizationId)->count()
            : 0;

        return [
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
        ];
    }

    /**
     * Platform users following the alliance hub organization (shown as "Followers" on the public alliance profile).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allianceFollowersForHub(?int $hubOrganizationId, ?int $careAllianceId = null): array
    {
        $userWithOrg = function ($q) {
            $q->select('id', 'name', 'email', 'image', 'slug', 'role')
                ->with(['organization.user:id,slug']);
        };

        $rows = collect();

        if ($hubOrganizationId !== null) {
            $rows = $rows->merge(
                UserFavoriteOrganization::query()
                    ->where('organization_id', $hubOrganizationId)
                    ->with(['user' => $userWithOrg])
                    ->latest()
                    ->limit(50)
                    ->get()
            );
        }

        if ($careAllianceId !== null) {
            $rows = $rows->merge(
                UserFavoriteOrganization::query()
                    ->where('care_alliance_id', $careAllianceId)
                    ->with(['user' => $userWithOrg])
                    ->latest()
                    ->limit(50)
                    ->get()
            );
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $uniqueFavorites = $rows->unique('user_id')->take(50)->values();

        $userIds = $uniqueFavorites->pluck('user_id')->unique()->filter()->map(fn ($id) => (int) $id)->all();

        $ownedOrgsByUserId = Organization::query()
            ->whereIn('user_id', $userIds)
            ->with(['user:id,slug'])
            ->get()
            ->keyBy(fn (Organization $o) => (int) $o->user_id);

        return $uniqueFavorites
            ->map(function (UserFavoriteOrganization $favorite) use ($ownedOrgsByUserId) {
                return $this->mapAllianceFollowerRow($favorite, $ownedOrgsByUserId);
            })
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Organization>  $ownedOrgsByUserId
     * @return array<string, mixed>
     */
    private function mapAllianceFollowerRow(UserFavoriteOrganization $favorite, $ownedOrgsByUserId): array
    {
        $user = $favorite->user;
        if ($user === null) {
            return [
                'id' => $favorite->id,
                'user_id' => $favorite->user_id,
                'user' => null,
                'notifications' => $favorite->notifications ?? false,
                'joined_at' => $favorite->created_at?->toIso8601String(),
                'follower_display_name' => 'Anonymous',
                'follower_avatar' => null,
                'is_organization_follower' => false,
                'organization_public_slug' => null,
            ];
        }

        $role = (string) ($user->role ?? '');
        $isOrgAccount = in_array($role, ['organization', 'organization_pending'], true);

        $org = $ownedOrgsByUserId->get((int) $user->id) ?? $user->organization;

        $displayName = (string) $user->name;
        $avatar = $user->image;
        $orgSlug = null;
        $isOrgFollower = false;

        if ($isOrgAccount && $org !== null) {
            $isOrgFollower = true;
            $displayName = (string) $org->name;
            $orgSlug = $org->user?->slug;
            if (! empty($org->registered_user_image)) {
                $avatar = $org->registered_user_image;
            }
        }

        return [
            'id' => $favorite->id,
            'user_id' => $favorite->user_id,
            'user' => [
                'id' => $user->id,
                'slug' => $user->slug,
                'name' => $user->name,
                'email' => $user->email,
                'image' => $user->image,
                'role' => $user->role,
            ],
            'notifications' => $favorite->notifications ?? false,
            'joined_at' => $favorite->created_at?->toIso8601String(),
            'follower_display_name' => $displayName,
            'follower_avatar' => $avatar,
            'is_organization_follower' => $isOrgFollower,
            'organization_public_slug' => $orgSlug,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadPostsForAllianceHub(int $creatorUserId, ?int $hubOrganizationId, ?int $authUserId, string $postFilter = 'organization'): array
    {
        $postsQuery = Post::query()
            ->select('id', 'content', 'images', 'created_at', 'user_id')
            ->with(['user:id,name,image,slug,role'])
            ->withCount(['reactions', 'comments'])
            ->where('user_id', $creatorUserId);

        $recentPosts = $postsQuery->latest()
            ->limit(5)
            ->get()
            ->map(function ($post) use ($authUserId) {
                return $this->mapPostToArray($post, $authUserId);
            });

        $facebookPosts = collect([]);
        if ($postFilter === 'organization' && $hubOrganizationId) {
            $facebookPosts = FacebookPost::where('organization_id', $hubOrganizationId)
                ->where('status', 'published')
                ->select('id', 'message', 'image', 'published_at', 'created_at')
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($post) {
                    return [
                        'id' => 'fb_'.$post->id,
                        'title' => null,
                        'content' => $post->message,
                        'image' => $post->image,
                        'created_at' => $post->published_at ?? $post->created_at,
                        'likes_count' => 0,
                        'comments_count' => 0,
                    ];
                });
        }

        return collect($recentPosts->all())
            ->merge($facebookPosts)
            ->sortByDesc('created_at')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPostToArray(Post $post, ?int $authUserId): array
    {
        $image = null;
        if ($post->images && is_array($post->images) && count($post->images) > 0) {
            $image = $post->images[0];
        }

        $userReaction = null;
        if ($authUserId) {
            $reaction = \App\Models\PostReaction::where('post_id', $post->id)
                ->where('user_id', $authUserId)
                ->first();
            if ($reaction) {
                $userReaction = [
                    'id' => $reaction->id,
                    'type' => $reaction->type,
                    'user_id' => $reaction->user_id,
                ];
            }
        }

        $reactions = \App\Models\PostReaction::where('post_id', $post->id)
            ->with('user:id,name,image')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($reaction) {
                return [
                    'id' => $reaction->id,
                    'type' => $reaction->type,
                    'user_id' => $reaction->user_id,
                    'user' => $reaction->user ? [
                        'id' => $reaction->user->id,
                        'name' => $reaction->user->name,
                        'image' => $reaction->user->image,
                    ] : null,
                ];
            });

        $comments = \App\Models\PostComment::where('post_id', $post->id)
            ->with('user:id,name,image')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at,
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'image' => $comment->user->image,
                    ] : null,
                ];
            });

        $creator = null;
        $creatorType = 'user';
        $creatorName = $post->user->name ?? 'Unknown';
        $creatorSlug = $post->user->slug ?? null;
        $creatorImage = $post->user->image ? Storage::url($post->user->image) : null;

        if ($post->user && $post->user->role === 'organization') {
            $org = Organization::where('user_id', $post->user->id)->first();
            if ($org) {
                $creator = [
                    'id' => $org->id,
                    'name' => $org->name,
                    'slug' => $post->user->slug,
                    'image' => $post->user->image ? Storage::url($post->user->image) : null,
                ];
                $creatorType = 'organization';
                $creatorName = $org->name;
                $creatorSlug = $post->user->slug;
                $creatorImage = $post->user->image ? Storage::url($post->user->image) : null;
            }
        } elseif ($post->user) {
            $creator = [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'slug' => $post->user->slug,
                'image' => $post->user->image ? Storage::url($post->user->image) : null,
            ];
        }

        return [
            'id' => $post->id,
            'title' => null,
            'content' => $post->content,
            'image' => $image,
            'images' => $post->images ?? [],
            'created_at' => $post->created_at,
            'reactions_count' => $post->reactions_count,
            'comments_count' => $post->comments_count,
            'user_reaction' => $userReaction,
            'reactions' => $reactions->toArray(),
            'comments' => $comments->toArray(),
            'has_more_comments' => $post->comments_count > 5,
            'creator' => $creator,
            'creator_type' => $creatorType,
            'creator_name' => $creatorName,
            'creator_slug' => $creatorSlug,
            'creator_image' => $creatorImage,
            'user' => $post->user ? [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'image' => $post->user->image ? Storage::url($post->user->image) : null,
                'slug' => $post->user->slug,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function careAllianceProfilePayload(CareAlliance $alliance, User $creator): array
    {
        return [
            'focus_areas' => $alliance->primaryActionCategories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()->all(),
            'website' => $alliance->website,
            'hub_lead_name' => $creator->name,
            'ein' => $alliance->ein,
        ];
    }

    /**
     * Active member nonprofits with public profile fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allianceMembersForPublic(CareAlliance $alliance): array
    {
        return CareAllianceMembership::query()
            ->where('care_alliance_id', $alliance->id)
            ->where('status', 'active')
            ->with(['organization' => fn ($q) => $q->with('user:id,slug,name,image')])
            ->orderByDesc('responded_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CareAllianceMembership $m) {
                $org = $m->organization;
                if (! $org) {
                    return null;
                }
                $user = $org->user;
                $desc = $org->description ?? '';

                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'public_slug' => $user?->slug,
                    'image' => $user?->image,
                    'city' => $org->city,
                    'state' => $org->state,
                    'description_excerpt' => $desc !== '' ? Str::limit(trim(strip_tags($desc)), 140) : null,
                    'joined_at' => $m->responded_at?->toIso8601String() ?? $m->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaignsForAlliance(CareAlliance $alliance): array
    {
        return CareAllianceCampaign::query()
            ->where('care_alliance_id', $alliance->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'description'])
            ->map(fn (CareAllianceCampaign $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
                'description' => $c->description,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    public function loadProductsForHub(?int $hubOrganizationId): array
    {
        if (! $hubOrganizationId) {
            return [];
        }

        $paginator = Product::query()
            ->where('organization_id', $hubOrganizationId)
            ->where('publish_status', 'published')
            ->with(['categories', 'variants'])
            ->latest()
            ->paginate(12);

        $paginator->getCollection()->transform(function (Product $p) {
            $row = $p->toArray();
            $row['price'] = $p->unit_price;

            return $row;
        });

        return $paginator->items();
    }

    /**
     * @return array<int, mixed>
     */
    public function loadJobsForHub(?int $hubOrganizationId): array
    {
        if (! $hubOrganizationId) {
            return [];
        }

        $q = JobPost::query()
            ->where('organization_id', $hubOrganizationId)
            ->with(['position', 'organization'])
            ->latest();

        if (Auth::check()) {
            $q->withExists([
                'applications as has_applied' => function ($sub) {
                    $sub->where('user_id', Auth::id());
                },
            ]);
        }

        return $q->paginate(12)->items();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function loadEventsPaginatorForHub(\Illuminate\Http\Request $request, ?int $hubOrganizationId)
    {
        if (! $hubOrganizationId) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                [],
                0,
                12,
                1,
                ['path' => $request->url(), 'pageName' => 'page']
            );
        }

        return Event::query()
            ->where('organization_id', $hubOrganizationId)
            ->with(['eventType', 'organization'])
            ->latest()
            ->paginate(12)
            ->withPath($request->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInertiaProps(CareAlliance $alliance, Request $request, ?string $currentPage): array
    {
        $creator = $alliance->creator;
        if (! $creator) {
            abort(404, 'Unity Impact Alliance creator not found.');
        }

        $creatorOrg = $this->hubOrganizationForAlliance($alliance);

        $hubOrganizationId = $creatorOrg?->id;
        $memberIds = $this->memberOrganizationIds($alliance);
        $organization = $this->syntheticOrganization($alliance, $creatorOrg, $creator);
        $authUserId = Auth::id();

        $counts = $this->hubAllianceCounts($creator->id, $hubOrganizationId, (int) $alliance->id);
        $bp = $this->aggregateBelievePoints($hubOrganizationId !== null ? [$hubOrganizationId] : []);
        $sidebar = $this->allianceHubSidebar($hubOrganizationId);

        $postFilter = (string) $request->query('filter', 'organization');
        $posts = ($currentPage === null || $currentPage === '')
            ? $this->loadPostsForAllianceHub($creator->id, $hubOrganizationId, $authUserId, $postFilter)
            : [];

        $campaigns = $this->campaignsForAlliance($alliance);

        $alliance->loadMissing('membershipPlan');

        $props = [
            'organization' => $organization,
            'membershipJoin' => app(MembershipAccountService::class)->publicJoinPayload($alliance, Auth::user()),
            'posts' => $posts,
            'postsCount' => $counts['postsCount'],
            'supportersCount' => $counts['supportersCount'],
            'partnerOrganizationsCount' => count($memberIds),
            'jobsCount' => $counts['jobsCount'],
            'eventsCount' => $sidebar['eventsCount'],
            'supporters' => [],
            'peopleYouMayKnow' => $sidebar['peopleYouMayKnow'],
            'trendingOrganizations' => $sidebar['trendingOrganizations'],
            'isFav' => (bool) ($organization['is_favorited'] ?? false),
            'believePointsEarned' => $bp['believePointsEarned'],
            'believePointsSpent' => $bp['believePointsSpent'],
            'believePointsBalance' => $bp['believePointsBalance'],
            'postFilter' => $postFilter,
            'careAlliancePublic' => [
                'slug' => $alliance->slug,
                'id' => (int) $alliance->id,
            ],
            'careAllianceCampaigns' => $campaigns,
            'careAllianceProfile' => $this->careAllianceProfilePayload($alliance, $creator),
            'allianceMembers' => [],
            'products' => [],
            'jobs' => [],
            'connectedCareAlliances' => [],
            ...$this->communityGroupsPayload($alliance, Auth::user()),
            ...app(CommunityContentService::class)->publicParentFeedProps($alliance, Auth::user()),
        ];

        if ($currentPage === 'products') {
            $props['products'] = $this->loadProductsForHub($hubOrganizationId);
        } elseif ($currentPage === 'jobs') {
            $props['jobs'] = $this->loadJobsForHub($hubOrganizationId);
        } elseif ($currentPage === 'events') {
            $props['events'] = $this->loadEventsPaginatorForHub($request, $hubOrganizationId);
        } elseif ($currentPage === 'supporters') {
            $props['supporters'] = $this->allianceFollowersForHub($hubOrganizationId, (int) $alliance->id);
        } elseif ($currentPage === 'members') {
            $props['allianceMembers'] = $this->allianceMembersForPublic($alliance);
        }

        if ($currentPage !== null && $currentPage !== '') {
            $props['currentPage'] = $currentPage;
        }

        return $props;
    }

    /**
     * @return array{communityGroups: list<array<string, mixed>>, canCreateCommunityGroup: bool, createCommunityGroupUrl: string|null}
     */
    private function communityGroupsPayload(CareAlliance $alliance, ?User $viewer): array
    {
        $canManage = $viewer && (int) $alliance->creator_user_id === (int) $viewer->id;

        $groups = Group::query()
            ->where('parent_type', MembershipAccountType::UnityImpactAlliance->value)
            ->where('parent_id', $alliance->id)
            ->where('status', 'active')
            ->when(! $canManage, fn ($q) => $q->where('is_hidden_on_parent', false))
            ->withCount('members')
            ->orderByDesc('is_pinned')
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'description' => $g->description,
                'category' => $g->category,
                'cover_image' => $g->cover_image ? asset('storage/'.$g->cover_image) : null,
                'is_featured' => (bool) $g->is_featured,
                'is_pinned' => (bool) $g->is_pinned,
                'members_count' => $g->members_count,
                'url' => route('groups.show', $g),
            ])
            ->values()
            ->all();

        $canCreate = $viewer
            ? app(GroupEligibilityService::class)->canCreateGroupUnder($viewer, $alliance)
            : false;

        return [
            'communityGroups' => $groups,
            'canCreateCommunityGroup' => $canCreate,
            'createCommunityGroupUrl' => $canCreate
                ? route('groups.create', [
                    'parent_type' => MembershipAccountType::UnityImpactAlliance->value,
                    'parent_id' => $alliance->id,
                ])
                : null,
        ];
    }

    private function persistHubOrganizationIdIfMissing(CareAlliance $alliance, Organization $org): void
    {
        if (! Schema::hasColumn('care_alliances', 'hub_organization_id')) {
            return;
        }
        if ($alliance->hub_organization_id) {
            return;
        }
        $alliance->forceFill(['hub_organization_id' => $org->id])->saveQuietly();
    }

    /**
     * Nonprofit owned by this user (organizations.user_id) or where they are a board member.
     * Uses the user id only so the hub still resolves if the users row was removed but org rows remain.
     */
    private function organizationLinkedToCreatorUserId(?int $creatorUserId): ?Organization
    {
        if ($creatorUserId === null || $creatorUserId === 0) {
            return null;
        }

        $cid = (int) $creatorUserId;

        return Organization::query()
            ->where(function ($q) use ($cid) {
                $q->where('user_id', $cid)
                    ->orWhereHas('boardMembers', function ($sub) use ($cid) {
                        $sub->where('user_id', $cid);
                    });
            })
            ->orderByRaw("CASE WHEN LOWER(COALESCE(registration_status, '')) = 'approved' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN organizations.user_id = '.$cid.' THEN 0 ELSE 1 END')
            ->orderByDesc('organizations.id')
            ->first();
    }

    /**
     * Match organizations.ein to alliance EIN (digits-only, XX-XXXXXXX, DB-specific normalization).
     */
    private function organizationMatchingAllianceEin(?string $allianceEin): ?Organization
    {
        $einDigits = $allianceEin ? preg_replace('/\D/', '', (string) $allianceEin) : '';
        if (strlen($einDigits) < 9) {
            return null;
        }
        $einDigits = substr($einDigits, 0, 9);
        $hyphenated = substr($einDigits, 0, 2).'-'.substr($einDigits, 2);

        $direct = Organization::query()
            ->whereIn('ein', [$einDigits, $hyphenated])
            ->orderByDesc('id')
            ->first();
        if ($direct) {
            return $direct;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return Organization::query()
                ->whereNotNull('ein')
                ->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(ein, \'\'), \'-\', \'\'), \' \', \'\'), \'.\', \'\'), \'/\', \'\') = ?', [$einDigits])
                ->orderByDesc('id')
                ->first();
        }
        if ($driver === 'sqlite') {
            return Organization::query()
                ->whereNotNull('ein')
                ->whereRaw("replace(replace(replace(coalesce(ein, ''), '-', ''), ' ', ''), '.', '') = ?", [$einDigits])
                ->orderByDesc('id')
                ->first();
        }
        if ($driver === 'pgsql') {
            return Organization::query()
                ->whereNotNull('ein')
                ->whereRaw("regexp_replace(coalesce(ein, ''), '[^0-9]', '', 'g') = ?", [$einDigits])
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * Hub nonprofit registered with the same name as the alliance under the creator account.
     */
    private function organizationOwnedByCreatorWithAllianceName(CareAlliance $alliance): ?Organization
    {
        $uid = $alliance->creator_user_id;
        if ($uid === null) {
            return null;
        }
        $name = trim((string) $alliance->name);
        if ($name === '') {
            return null;
        }

        return Organization::query()
            ->where('user_id', (int) $uid)
            ->where('name', $name)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * When several orgs share the alliance name, prefer the one owned by the alliance creator.
     */
    private function organizationByAllianceNamePreferringHubCreator(CareAlliance $alliance): ?Organization
    {
        $name = trim((string) $alliance->name);
        if ($name === '') {
            return null;
        }

        $candidates = Organization::query()
            ->where('name', $name)
            ->orderByDesc('id')
            ->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        $uid = $alliance->creator_user_id;
        if ($uid !== null) {
            foreach ($candidates as $org) {
                if ((int) $org->user_id === (int) $uid) {
                    return $org;
                }
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Approved organization that should receive donations for this alliance on /donate.
     * Uses pinned/resolved hub when approved; else first approved active member; else creator's approved org.
     */
    public function donationRecipientOrganizationForAlliance(CareAlliance $alliance): ?Organization
    {
        $hub = $this->hubOrganizationForAlliance($alliance);
        if ($hub !== null && strtolower((string) $hub->registration_status) === 'approved') {
            return $hub;
        }

        $memberIds = $this->memberOrganizationIds($alliance);
        if ($memberIds !== []) {
            $fromMembers = Organization::query()
                ->whereIn('id', $memberIds)
                ->where('registration_status', 'approved')
                ->orderBy('id')
                ->first();
            if ($fromMembers !== null) {
                return $fromMembers;
            }
        }

        $creatorUserId = $alliance->creator_user_id;
        if ($creatorUserId !== null && (int) $creatorUserId !== 0) {
            return Organization::query()
                ->where('user_id', (int) $creatorUserId)
                ->where('registration_status', 'approved')
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    public function hubOrganizationForAlliance(CareAlliance $alliance): ?Organization
    {
        $allianceId = (int) $alliance->id;

        // Pinned hub on the alliance row — read from DB; clear if org was deleted.
        if (Schema::hasColumn('care_alliances', 'hub_organization_id')) {
            $hubFromDb = DB::table('care_alliances')->where('id', $allianceId)->value('hub_organization_id');
            if ($hubFromDb) {
                $pinned = Organization::query()->find((int) $hubFromDb);
                if ($pinned) {
                    return $pinned;
                }
                DB::table('care_alliances')->where('id', $allianceId)->update(['hub_organization_id' => null]);
                $alliance->setAttribute('hub_organization_id', null);
            }
        }

        $ownedName = $this->organizationOwnedByCreatorWithAllianceName($alliance);
        if ($ownedName) {
            $this->persistHubOrganizationIdIfMissing($alliance, $ownedName);

            return $ownedName;
        }

        $linked = $this->organizationLinkedToCreatorUserId(
            $alliance->creator_user_id !== null ? (int) $alliance->creator_user_id : null
        );
        if ($linked) {
            $this->persistHubOrganizationIdIfMissing($alliance, $linked);

            return $linked;
        }

        $einOrg = $this->organizationMatchingAllianceEin($alliance->ein);
        if ($einOrg) {
            $this->persistHubOrganizationIdIfMissing($alliance, $einOrg);

            return $einOrg;
        }

        $nameOrg = $this->organizationByAllianceNamePreferringHubCreator($alliance);
        if ($nameOrg) {
            $this->persistHubOrganizationIdIfMissing($alliance, $nameOrg);

            return $nameOrg;
        }

        return null;
    }
}
