<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrganizationInviteJob;
use App\Models\CareAlliance;
use App\Models\CareAllianceMembership;
use App\Models\Course;
use App\Models\Donation;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\ExcelData;
use App\Models\FacebookPost;
use App\Models\FollowerPosition;
use App\Models\FollowingUserPosition;
use App\Models\JobPost;
use App\Models\NteeCode;
use App\Enums\MembershipAccountType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\PrimaryActionCategory;
use App\Models\Product;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\CareAlliancePublicPageService;
use App\Services\CommunityContentService;
use App\Services\ExcelDataTransformer;
use App\Services\ImpactScoreService;
use App\Services\GroupEligibilityService;
use App\Services\MembershipAccountService;
use App\Services\OpenAiService;
use App\Services\SeoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class OrganizationController extends BaseController
{
    protected $impactScoreService;

    public function __construct(ImpactScoreService $impactScoreService)
    {
        $this->impactScoreService = $impactScoreService;
    }

    public function index(Request $request)
    {
        // Get search parameters - ADD category_description
        $search = $request->get('search');
        $category = $request->get('category');
        $categoryDescription = $request->get('category_description'); // Add this
        $state = $request->get('state');
        $city = $request->get('city');
        $zip = $request->get('zip');
        $causeId = $request->integer('cause_id');
        $status = $request->get('status');
        $page = $request->get('page', 1);
        $sort = $request->get('sort', 'relevance');
        $perPage = min($request->get('per_page', 6), 50);

        // Optimized query
        $query = ExcelData::where('status', 'complete')
            ->where('ein', '!=', 'EIN')
            ->whereNotNull('ein');

        $this->applyOrganizationDirectoryTextSearch($query, $search);

        $needsNteeJoin = ($category && $category !== 'All Categories')
            || ($categoryDescription && $categoryDescription !== 'All Descriptions');
        if ($needsNteeJoin) {
            $query->join('ntee_codes', 'excel_data.ntee_code_virtual', '=', 'ntee_codes.ntee_codes');
        }
        if ($category && $category !== 'All Categories') {
            $query->where('ntee_codes.category', $category);
        }
        if ($categoryDescription && $categoryDescription !== 'All Descriptions') {
            $query->where('ntee_codes.description', $categoryDescription);
        }

        // Filter by location
        if ($state && $state !== 'All States') {
            $query->where('state_virtual', $state);
        }

        if ($city && $city !== 'All Cities') {
            $query->where('city_virtual', $city);
        }

        if ($zip) {
            if (strlen($zip) >= 3) {
                $query->where('zip_virtual', 'LIKE', $zip.'%');
            } else {
                $query->where('zip_virtual', 'LIKE', '%'.$zip.'%');
            }
        }

        // Registered orgs that list this Primary Action Category → match spreadsheet rows by EIN variants
        if ($causeId > 0) {
            $einList = Organization::query()
                ->where('registration_status', 'approved')
                ->excludingCareAllianceHubs()
                ->whereHas('primaryActionCategories', static fn ($q) => $q->where('primary_action_categories.id', $causeId))
                ->pluck('ein')
                ->filter()
                ->values()
                ->all();

            $variantSet = [];
            foreach ($einList as $ein) {
                foreach ($this->einLookupStringVariants([$ein]) as $v) {
                    $variantSet[$v] = true;
                }
            }
            $variants = array_keys($variantSet);

            if ($variants === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('ein', $variants);
            }
        }

        // Filter by platform join status (registered org match by canonical EIN digits, hyphen-insensitive)
        if ($status === 'joined' || $status === 'not_joined') {
            $useEinDigits = Schema::hasColumn('excel_data', 'ein_digits');
            $existsSub = function ($sub) use ($useEinDigits) {
                $sub->from('organizations')
                    ->where('registration_status', 'approved');
                if ($useEinDigits) {
                    $sub->whereColumn('organizations.ein', 'excel_data.ein_digits');
                } else {
                    $sub->whereRaw("REPLACE(organizations.ein,'-','') = REPLACE(excel_data.ein,'-','')");
                }

                if (Schema::hasTable('care_alliances') && Schema::hasColumn('care_alliances', 'hub_organization_id')) {
                    $sub->whereNotExists(function ($hub) {
                        $hub->from('care_alliances')
                            ->whereColumn('care_alliances.hub_organization_id', 'organizations.id')
                            ->whereNotNull('care_alliances.hub_organization_id');
                    });
                }
            };

            if ($status === 'joined') {
                $query->whereExists(function ($sub) use ($existsSub) {
                    $sub->selectRaw('1');
                    $existsSub($sub);
                });
            } else {
                $query->whereNotExists(function ($sub) use ($existsSub) {
                    $sub->selectRaw('1');
                    $existsSub($sub);
                });
            }
        }

        // Continue with the rest of your existing code...
        $query->select([
            'excel_data.id',
            'excel_data.ein',
            'excel_data.row_data',
            'excel_data.created_at',
            'excel_data.updated_at',
            'excel_data.name_virtual',
            'excel_data.state_virtual',
            'excel_data.city_virtual',
            'excel_data.ntee_code_virtual',
            'excel_data.zip_virtual',
            'excel_data.file_id',
        ]);

        // Apply sorting
        switch ($sort) {
            case 'name':
                $query->orderBy('name_virtual', 'asc');
                break;
            case 'state':
                $query->orderBy('state_virtual', 'asc');
                break;
            case 'city':
                $query->orderBy('city_virtual', 'asc');
                break;
            case 'relevance':
            default:
                $query->orderBy('id', 'asc');
                break;
        }

        // Length-aware pagination (numbered steps + total count). Requires COUNT(*) — can be slow on huge filtered sets;
        // use DB indexes / completed FTS migration to keep the underlying query fast.
        $organizations = $query->paginate($perPage);

        // Get all EINs from the excel data results
        $eins = $organizations->pluck('ein')->filter()->toArray();

        // Match registered nonprofits by canonical 9-digit EIN (spreadsheet rows may use XX-XXXXXXX vs XXXXXXXXX).
        /** @var array<string, Organization> */
        $registeredOrgs = [];
        $userFavoriteOrgIds = [];
        $userFavoriteExcelIds = [];

        if ($eins !== []) {
            $einVariantsForQuery = $this->einLookupStringVariants($eins);
            $rows = Organization::query()
                ->where('registration_status', 'approved')
                ->excludingCareAllianceHubs()
                ->whereIn('ein', $einVariantsForQuery)
                ->with([
                    'primaryActionCategories' => fn ($q) => $q
                        ->orderBy('sort_order')
                        ->orderBy('name'),
                    'user:id,registered_user_image,image,name',
                ])
                ->get();
            foreach ($rows as $o) {
                $k = $this->canonicalEin($o->ein);
                if ($k !== null && ! isset($registeredOrgs[$k])) {
                    $registeredOrgs[$k] = $o;
                }
            }
        }

        $pageExcelIds = $organizations->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $registeredOrgIds = collect($registeredOrgs)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if (Auth::check() && ($pageExcelIds !== [] || $registeredOrgIds !== [])) {
            $favoriteRows = UserFavoriteOrganization::query()
                ->where('user_id', Auth::id())
                ->where(function ($q) use ($pageExcelIds, $registeredOrgIds) {
                    if ($pageExcelIds !== []) {
                        $q->whereIn('excel_data_id', $pageExcelIds);
                    }
                    if ($registeredOrgIds !== []) {
                        $q->orWhereIn('organization_id', $registeredOrgIds);
                    }
                })
                ->get(['organization_id', 'excel_data_id']);

            $userFavoriteOrgIds = $favoriteRows->pluck('organization_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $userFavoriteExcelIds = $favoriteRows->pluck('excel_data_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        // Get NTEE codes and their categories/descriptions for the organizations
        $nteeCodes = $organizations->pluck('ntee_code_virtual')->filter()->toArray();
        $nteeCategories = [];
        $nteeDescriptions = [];

        if (! empty($nteeCodes)) {
            $nteeData = NteeCode::whereIn('ntee_codes', $nteeCodes)
                ->select('ntee_codes', 'category', 'description')
                ->get()
                ->keyBy('ntee_codes');

            $nteeCategories = $nteeData->pluck('category', 'ntee_codes')->toArray();
            $nteeDescriptions = $nteeData->pluck('description', 'ntee_codes')->toArray();
        }

        // Transform the data
        $transformedOrganizations = $organizations->getCollection()->map(function ($item) use ($registeredOrgs, $userFavoriteOrgIds, $userFavoriteExcelIds, $nteeCategories, $nteeDescriptions) {
            $rowData = $item->row_data;
            $nteeCode = $item->ntee_code_virtual ?? $rowData[26] ?? '';

            // Get NTEE category and description
            $nteeCategory = '';
            $nteeDescription = '';
            if ($nteeCode && isset($nteeCategories[$nteeCode])) {
                $nteeCategory = $nteeCategories[$nteeCode];
                $nteeDescription = $nteeDescriptions[$nteeCode] ?? '';
            }

            // Format NTEE code with category
            $formattedNteeCode = $nteeCode;
            if ($nteeCode && $nteeCategory) {
                $formattedNteeCode = $nteeCode.' - '.$nteeCategory;
            }

            // Resolve registered Organization by canonical EIN (spreadsheet formatting may differ from organizations.ein)
            $canonicalRowEin = $this->canonicalEin($item->ein);
            $registeredOrg = ($canonicalRowEin !== null && isset($registeredOrgs[$canonicalRowEin]))
                ? $registeredOrgs[$canonicalRowEin]
                : null;
            $isRegistered = $registeredOrg !== null;

            $logoUrl = null;
            if ($registeredOrg !== null) {
                /** Prefer fresh `users.image` (profile-photos/* from ProfilePhotoController), then legacy uploads — mirrors Api\UserController / profile UI. */
                $candidates = [
                    $registeredOrg->user?->image,
                    $registeredOrg->user?->registered_user_image,
                    $registeredOrg->registered_user_image,
                ];
                foreach ($candidates as $path) {
                    $logoUrl = $this->publicStorageHref(is_string($path) ? $path : null);
                    if ($logoUrl !== null) {
                        break;
                    }
                }
            }

            $primaryCauseRows = [];
            if ($registeredOrg !== null && $registeredOrg->relationLoaded('primaryActionCategories')) {
                $primaryCauseRows = $registeredOrg->primaryActionCategories->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'slug' => (string) $c->slug,
                ])->values()->all();
            }

            $isFavorited = false;
            if (Auth::check()) {
                $excelRowId = (int) $item->id;
                if ($isRegistered) {
                    $isFavorited = in_array((int) $registeredOrg->id, $userFavoriteOrgIds, true)
                        || in_array($excelRowId, $userFavoriteExcelIds, true);
                } else {
                    $isFavorited = in_array($excelRowId, $userFavoriteExcelIds, true);
                }
            }

            return [
                'id' => $item->id,
                'ein' => $item->ein,
                'name' => $item->name_virtual ?? $rowData[1] ?? '',
                'city' => $item->city_virtual ?? $rowData[4] ?? '',
                'state' => $item->state_virtual ?? $rowData[5] ?? '',
                'zip' => $item->zip_virtual ?? $rowData[6] ?? '',
                'classification' => $rowData[10] ?? '',
                'ntee_code' => $formattedNteeCode,
                'ntee_code_raw' => $nteeCode,
                'ntee_category' => $nteeCategory,
                'ntee_category_description' => $nteeDescription, // Add this
                'created_at' => $item->created_at,
                'is_registered' => $isRegistered,
                'is_favorited' => $isFavorited,
                'logo_url' => $logoUrl,
                'verified' => $isRegistered,
                'primary_action_categories' => $primaryCauseRows,
            ];
        });

        // Cache filter options - ADD categoryDescriptions
        $categories = cache()->remember('orgs_categories', 3600, function () {
            return DB::table('ntee_codes')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->prepend('All Categories');
        });

        $categoryDescriptions = cache()->remember('orgs_category_descriptions', 3600, function () {
            return DB::table('ntee_codes')
                ->distinct()
                ->orderBy('description')
                ->pluck('description')
                ->prepend('All Descriptions');
        });

        $causeFilterOptions = cache()->remember('orgs_primary_action_categories_active_v1', 3600, function () {
            return PrimaryActionCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                ])
                ->values()
                ->all();
        });

        $filterOptions = [
            'categories' => $categories,
            'categoryDescriptions' => $categoryDescriptions, // Add this
            'states' => $this->getStates(),
            'cities' => ['All Cities'],
            'causes' => $causeFilterOptions,
        ];

        $organizations->setCollection($transformedOrganizations);

        // How many supporters saved each cause on their profile (popularity for ordering chips).
        $supporterCountsByCause = DB::table('primary_action_category_user')
            ->selectRaw('primary_action_category_id, COUNT(*) as cnt')
            ->groupBy('primary_action_category_id')
            ->pluck('cnt', 'primary_action_category_id');

        $myCauses = collect();
        if (Auth::check()) {
            $myCauses = Auth::user()
                ->supporterInterestCategories()
                ->get(['primary_action_categories.id', 'primary_action_categories.name', 'primary_action_categories.slug']);
            $myCauses = $myCauses->sortByDesc(fn ($c) => (int) $supporterCountsByCause->get($c->id, 0))
                ->values();
        }

        // Logged-out (or no profile interests): show global top causes by supporter picks.
        if ($myCauses->isEmpty()) {
            $topIds = DB::table('primary_action_category_user')
                ->selectRaw('primary_action_category_id, COUNT(*) as cnt')
                ->groupBy('primary_action_category_id')
                ->orderByDesc('cnt')
                ->limit(12)
                ->pluck('primary_action_category_id');

            if ($topIds->isNotEmpty()) {
                $cats = PrimaryActionCategory::query()
                    ->whereIn('id', $topIds->all())
                    ->where('is_active', true)
                    ->get(['id', 'name', 'slug'])
                    ->keyBy('id');

                $myCauses = $topIds->map(fn ($id) => $cats->get($id))->filter()->values();
            }
        }

        return Inertia::render('frontend/organization/organizations', [
            'seo' => SeoService::forPage('organizations'),
            'organizations' => $organizations,
            'myCauses' => $myCauses->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'slug' => (string) $c->slug,
            ])->values(),
            'filters' => [
                'search' => $search,
                'category' => $category,
                'category_description' => $categoryDescription, // Add this
                'state' => $state,
                'city' => $city,
                'zip' => $zip,
                'cause_id' => $causeId > 0 ? (string) $causeId : null,
                'status' => $status,
                'sort' => $sort,
                'per_page' => $perPage,
            ],
            'filterOptions' => $filterOptions,
            'hasActiveFilters' => $search || ($category && $category !== 'All Categories') ||
                ($categoryDescription && $categoryDescription !== 'All Descriptions') || // Add this
                ($state && $state !== 'All States') ||
                ($city && $city !== 'All Cities') || $zip ||
                $causeId > 0 || ($status && $status !== 'All Status'),
        ]);
    }

    /**
     * Directory keyword search: MySQL/MariaDB/PostgreSQL use FULLTEXT when available (see migration
     * excel_data_search_fulltext); otherwise LIKE across virtual columns. Short queries (<3 chars) use LIKE
     * so behavior stays predictable vs InnoDB minimum token length.
     *
     * @param  Builder<ExcelData>  $query
     */
    private function applyOrganizationDirectoryTextSearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $trimmed = trim($search);
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasColumn('excel_data', 'org_directory_fts') && mb_strlen($trimmed) >= 3) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $boolean = $this->mysqlOrganizationDirectoryFulltextBoolean($trimmed);
                if ($boolean !== null && $boolean !== '') {
                    $query->whereFullText(
                        ['excel_data.org_directory_fts'],
                        $boolean,
                        ['mode' => 'boolean'],
                        'and'
                    );

                    return;
                }

                $query->whereFullText(
                    ['excel_data.org_directory_fts'],
                    $trimmed,
                    [],
                    'and'
                );

                return;
            }

            if ($driver === 'pgsql') {
                $query->whereFullText(
                    ['excel_data.org_directory_fts'],
                    $trimmed,
                    ['mode' => 'websearch'],
                    'and'
                );

                return;
            }
        }

        $like = '%'.$trimmed.'%';
        $query->where(function ($q) use ($like) {
            $q->where('excel_data.name_virtual', 'LIKE', $like)
                ->orWhere('excel_data.sort_name_virtual', 'LIKE', $like)
                ->orWhere('excel_data.city_virtual', 'LIKE', $like)
                ->orWhere('excel_data.state_virtual', 'LIKE', $like)
                ->orWhere('excel_data.zip_virtual', 'LIKE', $like);
        });
    }

    /**
     * BOOLEAN MODE with required prefix wildcards per token — tends to use the InnoDB FTS index much better
     * than NATURAL LANGUAGE on a 1.8M-row table. Tokens shorter than 3 chars are skipped (InnoDB ft_min_token_size).
     *
     * @return non-empty-string|null
     */
    private function mysqlOrganizationDirectoryFulltextBoolean(string $trimmed): ?string
    {
        $tokens = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        $parts = [];
        foreach ($tokens as $token) {
            $word = preg_replace('/[^\p{L}\p{N}]+/u', '', $token);
            if ($word === '' || mb_strlen($word) < 3) {
                continue;
            }
            $parts[] = '+'.mb_substr($word, 0, 64).'*';
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    /** Canonical 9-digit EIN digits-only (matches IRS lookups regardless of hyphenation in spreadsheets). */
    private function canonicalEin(?string $ein): ?string
    {
        if ($ein === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $ein);

        return strlen($digits) === 9 ? $digits : null;
    }

    /**
     * @param  array<int, mixed>  $einsFromSpreadsheetRows
     * @return array<int, string>
     */
    private function einLookupStringVariants(array $einsFromSpreadsheetRows): array
    {
        $variants = [];
        foreach ($einsFromSpreadsheetRows as $ein) {
            if (! is_string($ein) && ! is_numeric($ein)) {
                continue;
            }
            $s = trim((string) $ein);
            if ($s === '' || strtoupper($s) === 'EIN') {
                continue;
            }
            $variants[] = $s;
            $c = $this->canonicalEin($s);
            if ($c !== null) {
                $variants[] = $c;
                $variants[] = substr($c, 0, 2).'-'.substr($c, 2);
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Build a browser-safe URL for public-disk stored paths — matches shared auth payloads (`'/storage/'.$path`).
     * Avoids Storage::url() absolute URLs pointing at APP_URL when the app is opened from another host/port.
     */
    private function publicStorageHref(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }
        // Already routed to public disk
        if (Str::startsWith($path, '/storage/')) {
            return $path;
        }
        // Strip duplicated "storage/" sometimes saved in legacy data
        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return '/storage/'.ltrim($path, '/');
    }

    private function getStates()
    {
        // Static list of all U.S. States and Territories abbreviations only
        $statesAndTerritories = [
            'AL',
            'AK',
            'AZ',
            'AR',
            'CA',
            'CO',
            'CT',
            'DE',
            'FL',
            'GA',
            'HI',
            'ID',
            'IL',
            'IN',
            'IA',
            'KS',
            'KY',
            'LA',
            'ME',
            'MD',
            'MA',
            'MI',
            'MN',
            'MS',
            'MO',
            'MT',
            'NE',
            'NV',
            'NH',
            'NJ',
            'NM',
            'NY',
            'NC',
            'ND',
            'OH',
            'OK',
            'OR',
            'PA',
            'RI',
            'SC',
            'SD',
            'TN',
            'TX',
            'UT',
            'VT',
            'VA',
            'WA',
            'WV',
            'WI',
            'WY',
            'DC',
            'AS',
            'GU',
            'MP',
            'PR',
            'VI',
        ];

        // Create a collection with just the abbreviations, sorted alphabetically
        $statesCollection = collect($statesAndTerritories)
            ->sort()
            ->values() // Reset keys to maintain proper order
            ->prepend('All States');

        return $statesCollection;
    }

    // Get states for filter options
    // private function getStates()
    // {
    //     $cacheKey = 'states_filter_v3'; // Change cache key to force refresh

    //     return cache()->remember($cacheKey, 86400, function () {
    //         return ExcelData::where('status', 'complete')
    //             ->whereRaw("JSON_EXTRACT(row_data, '$[0]') != 'EIN'")
    //             ->whereNotNull(DB::raw("JSON_EXTRACT(row_data, '$[5]')"))
    //             ->whereRaw("JSON_EXTRACT(row_data, '$[5]') != ''")
    //             ->distinct()
    //             ->orderBy(DB::raw("JSON_EXTRACT(row_data, '$[5]')"))
    //             ->pluck(DB::raw("TRIM(BOTH '\"' FROM JSON_EXTRACT(row_data, '$[5]'))")) // Remove quotes
    //             ->prepend('All States');
    //     });
    // }

    // private function getStates()
    // {
    //     $cacheKey = 'states_filter_v3';

    //     return cache()->remember($cacheKey, 86400, function () {
    //         return ExcelData::where('status', 'complete')
    //             ->whereNotNull('state_virtual')
    //             ->where('state_virtual', '!=', '')
    //             ->whereNotIn('id', function ($subQuery) {
    //                 $subQuery->select(DB::raw('MIN(id)'))
    //                     ->from('excel_data')
    //                     ->groupBy('file_id');
    //             })
    //             ->distinct()
    //             ->orderBy('state_virtual')
    //             ->pluck('state_virtual')
    //             ->prepend('All States');
    //     });
    // }

    public function getCitiesByState(Request $request)
    {
        // dd(
        //     $request->all()
        // );
        $state = $request->get('state');
        $cacheKey = 'cities_filter_v2_'.md5($state ?? 'all');

        $cities = cache()->remember($cacheKey, 3600, function () use ($state) {
            $query = ExcelData::where('status', 'complete')
                ->where('ein', '!=', 'EIN')
                ->whereNotNull('ein')
                ->whereNotNull('city_virtual')
                ->when($state && $state !== 'All States', function ($q) use ($state) {
                    return $q->where('state_virtual', $state);
                })
                ->distinct('city_virtual')
                ->orderBy('city_virtual');
            // When a specific state is selected, return all cities. When "All States", cap at 5000.
            if (! $state || $state === 'All States') {
                $query->limit(5000);
            }

            return $query->pluck('city_virtual')->prepend('All Cities');
        });

        return response()->json($cities);
    }

    public function show(Request $request, string $id)
    {
        // $this->authorizePermission($request, 'organization.read');
        // Ultra-fast lookup: Try direct ID first (most common case, uses primary key index)
        $organization = ExcelData::where('id', $id)
            ->where('status', 'complete')
            ->select('id', 'ein', 'row_data', 'created_at', 'updated_at') // Only select needed columns
            ->first();

        $registeredOrg = null;

        if ($organization) {
            // If found by ID, check if registered (single query with eager load)
            $registeredOrg = Organization::where('ein', $organization->ein)
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->with('user:id,slug,name,email,image,cover_img')
                ->first();
        } else {
            // Fallback: Try by user slug (less common)
            $user = User::where('slug', $id)->select('id', 'slug')->first();

            if ($user) {
                $registeredOrg = Organization::where('user_id', $user->id)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->with('user:id,slug,name,email,image,cover_img')
                    ->select('id', 'ein', 'user_id', 'name', 'description', 'mission', 'website', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state', 'memberships_enabled', 'has_members', 'membership_type')
                    ->first();

                if ($registeredOrg) {
                    // Find ExcelData by EIN - use limit 1 for speed
                    $organization = ExcelData::where('ein', $registeredOrg->ein)
                        ->where('status', 'complete')
                        ->select('id', 'ein', 'row_data', 'created_at', 'updated_at')
                        ->orderBy('id', 'desc')
                        ->limit(1)
                        ->first();
                }
            }
        }

        if (! $organization) {
            abort(404, 'Organization not found');
        }

        $rowData = $organization->row_data;
        $transformedData = ExcelDataTransformer::transform($rowData);

        // Get any Organization record for mission (only if not already found)
        $anyOrgRecord = $registeredOrg ?: Organization::where('ein', $organization->ein)->first();

        $isFav = false;
        $notificationsEnabled = false;

        if (Auth::check()) {
            if ($registeredOrg) {
                // Check favorite by organization_id for registered organizations
                $favorite = UserFavoriteOrganization::where('user_id', Auth::id())
                    ->where('organization_id', $registeredOrg->id)
                    ->first();
            } else {
                // Check favorite by excel_data_id for unregistered organizations
                $excelDataId = (int) $organization->id;

                // Query with explicit integer binding to ensure type matching
                $favorite = UserFavoriteOrganization::where('user_id', Auth::id())
                    ->where('excel_data_id', $excelDataId)
                    ->first();
            }

            if ($favorite) {
                $isFav = true;
                $notificationsEnabled = $favorite->notifications;
            }
        }

        // User already loaded above with eager loading
        $isOwnOrganization = false;
        if (Auth::check()) {
            $authUserOrg = Auth::user()->organization ?? Organization::where('user_id', Auth::id())->first();
            if ($authUserOrg) {
                $isOwnOrganization = $authUserOrg->ein === $organization->ein
                    || ($registeredOrg && $authUserOrg->id === $registeredOrg->id);
            }
        }

        $transformedOrganization = [
            'id' => $organization->id,
            'ein' => $organization->ein,
            'is_own_organization' => $isOwnOrganization,
            'name' => $transformedData[1] ?? $rowData[1] ?? '',
            'ico' => $transformedData[2] ?? $rowData[2] ?? '',
            'street' => $transformedData[3] ?? $rowData[3] ?? '',
            'city' => $transformedData[4] ?? $rowData[4] ?? '',
            'state' => $transformedData[5] ?? $rowData[5] ?? '',
            'zip' => $transformedData[6] ?? $rowData[6] ?? '',
            'classification' => $transformedData[10] ?? $rowData[10] ?? '',
            'ntee_code' => $transformedData[26] ?? $rowData[26] ?? '',
            'created_at' => $registeredOrg?->created_at ?? $organization->created_at,
            'is_registered' => (bool) $registeredOrg,
            'is_favorited' => $isFav,
            'notifications_enabled' => $notificationsEnabled,
            'registered_organization' => $registeredOrg ? [
                'id' => $registeredOrg->id,
                'name' => $registeredOrg->name,
                'user' => $registeredOrg->user ? [
                    'id' => $registeredOrg->user->id,
                    'slug' => $registeredOrg->user->slug,
                    'name' => $registeredOrg->user->name,
                    'email' => $registeredOrg->user->email,
                    'image' => $registeredOrg->user->image,
                    'cover_img' => $registeredOrg->user->cover_img,
                ] : null,
            ] : null,
            'description' => $registeredOrg ? $registeredOrg->description : ($anyOrgRecord ? $anyOrgRecord->description : 'This organization is listed in our database but has not yet registered for additional features.'),
            'mission' => $anyOrgRecord && $anyOrgRecord->mission && trim($anyOrgRecord->mission) !== ''
                ? $anyOrgRecord->mission
                : 'Mission statement not available for unregistered organizations.',
            'website' => $registeredOrg && $registeredOrg->website ? $registeredOrg->website : ($anyOrgRecord && $anyOrgRecord->website ? $anyOrgRecord->website : null),
            'wefunder_project_url' => $registeredOrg && $registeredOrg->wefunder_project_url ? $registeredOrg->wefunder_project_url : ($anyOrgRecord && $anyOrgRecord->wefunder_project_url ? $anyOrgRecord->wefunder_project_url : null),
            'ruling' => $transformedData[7] ?? $rowData[7] ?? 'N/A', // Ruling year from excel data
        ];

        // Get posts count and supporters count for both registered and unregistered organizations
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $posts = [];
        $supporters = [];
        $bp = $this->believePointsForOrganizationViewer($registeredOrg, $isOwnOrganization);
        $believePointsEarned = $bp['believePointsEarned'];
        $believePointsSpent = $bp['believePointsSpent'];
        $believePointsBalance = $bp['believePointsBalance'];

        if ($registeredOrg) {
            $registeredOrg->loadMissing('membershipPlan', 'user:id,slug');
            // Get posts count - use single query with union for faster counting
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();

            // Get supporters count only - defer loading supporter details
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $supporters = []; // Load on demand when supporters tab is accessed

            // Get jobs count
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $organization->id)->count();
            $supporters = []; // Load on demand when supporters tab is accessed
        }

        // Load posts with minimal data initially - defer detailed loading (only for registered orgs)
        if ($registeredOrg) {
            $userId = Auth::id();

            // Get filter from request (default to 'organization' for organization posts only)
            $postFilter = $request->get('filter', 'organization'); // 'all' or 'organization'

            // Load posts with reactions and comments data
            // Don't eager load user.organization to avoid ambiguous column error
            // We'll load organization data manually in the map function
            $postsQuery = Post::select('id', 'content', 'images', 'created_at', 'user_id')
                ->with(['user:id,name,image,slug,role'])
                ->withCount(['reactions', 'comments']);

            if ($postFilter === 'organization') {
                // Only this organization's posts
                $postsQuery->where('user_id', $registeredOrg->user_id);
            } elseif ($postFilter === 'all') {
                // "All Posts" means: organization's posts + posts from supporters who follow this organization

                // Get all user IDs who follow this organization (supporters)
                // Check both organization_id (for registered orgs) and excel_data_id (for unregistered orgs)
                $supporterQuery = UserFavoriteOrganization::query();

                if ($registeredOrg) {
                    // Registered organizations: match by organization_id
                    $supporterQuery->where('organization_id', $registeredOrg->id);
                }

                if ($organization) {
                    // Also check excel_data_id (for unregistered orgs or if someone followed by excel_data_id)
                    if ($registeredOrg) {
                        $supporterQuery->orWhere('excel_data_id', $organization->id);
                    } else {
                        $supporterQuery->where('excel_data_id', $organization->id);
                    }
                }

                $supporterUserIds = $supporterQuery
                    ->pluck('user_id')
                    ->unique()
                    ->toArray();

                // Combine: organization's user_id + all supporter user IDs
                $allowedUserIds = array_merge(
                    [$registeredOrg->user_id], // Organization's own posts
                    $supporterUserIds // Posts from supporters who follow this organization
                );

                $postsQuery->whereIn('user_id', array_unique($allowedUserIds));
            }

            $recentPosts = $postsQuery->latest()
                ->limit(5) // Reduced initial load
                ->get()
                ->map(function ($post) use ($userId) {
                    $image = null;
                    if ($post->images && is_array($post->images) && count($post->images) > 0) {
                        $image = $post->images[0];
                    }

                    // Get user's reaction if authenticated
                    $userReaction = null;
                    if ($userId) {
                        $reaction = PostReaction::where('post_id', $post->id)
                            ->where('user_id', $userId)
                            ->first();
                        if ($reaction) {
                            $userReaction = [
                                'id' => $reaction->id,
                                'type' => $reaction->type,
                                'user_id' => $reaction->user_id,
                            ];
                        }
                    }

                    // Load recent reactions with user data (limit to 10 for performance)
                    $reactions = PostReaction::where('post_id', $post->id)
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

                    // Load recent comments with user data (limit to 5 for initial load)
                    $comments = PostComment::where('post_id', $post->id)
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

                    // Determine creator info
                    $creator = null;
                    $creatorType = 'user';
                    $creatorName = $post->user->name ?? 'Unknown';
                    $creatorSlug = $post->user->slug ?? null;
                    $creatorImage = $post->user->image ? Storage::url($post->user->image) : null;

                    // Check if user has an organization - load it manually to avoid relationship issues
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
                        } else {
                            // User with organization role but no organization record
                            $creator = [
                                'id' => $post->user->id,
                                'name' => $post->user->name,
                                'slug' => $post->user->slug,
                                'image' => $post->user->image ? Storage::url($post->user->image) : null,
                            ];
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
                        'title' => null, // Post model doesn't have title field
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
                });

            // Load Facebook posts (only if showing organization posts)
            $facebookPosts = collect([]);
            if ($postFilter === 'organization') {
                $facebookPosts = FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->select('id', 'message', 'image', 'published_at', 'created_at')
                    ->latest()
                    ->limit(5) // Reduced initial load
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

            // $recentPosts is an Eloquent Collection; after mapping to arrays it can still be an Eloquent\Collection
            // which expects Models (calls getKey()). Convert to a base collection before merging.
            $posts = collect($recentPosts->all())
                ->merge($facebookPosts)
                ->sortByDesc('created_at')
                ->take(5)
                ->values();
        }

        // Load sidebar data using optimized helper method
        $sidebarData = $this->getSidebarData($registeredOrg);
        $peopleYouMayKnow = $sidebarData['peopleYouMayKnow'];
        $trendingOrganizations = $sidebarData['trendingOrganizations'];
        $eventsCount = $registeredOrg ? Event::where('organization_id', $registeredOrg->id)->count() : 0;

        $communityGroupsPayload = $this->communityGroupsPayload($registeredOrg, $request->user());

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $transformedOrganization,
            'seo' => $this->seoForOrganizationShow($transformedOrganization),
            'posts' => $posts,
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'eventsCount' => $eventsCount,
            'supporters' => $supporters,
            'peopleYouMayKnow' => $peopleYouMayKnow,
            'trendingOrganizations' => $trendingOrganizations,
            'isFav' => $isFav,
            'believePointsEarned' => $believePointsEarned,
            'believePointsSpent' => $believePointsSpent,
            'believePointsBalance' => $believePointsBalance,
            'postFilter' => $postFilter ?? 'organization', // Pass filter to frontend
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            'communityGroups' => $communityGroupsPayload['groups'],
            'canCreateCommunityGroup' => $communityGroupsPayload['can_create'],
            'createCommunityGroupUrl' => $communityGroupsPayload['create_url'],
            ...($registeredOrg
                ? array_merge(
                    app(CommunityContentService::class)->publicParentFeedProps($registeredOrg, $request->user()),
                    [
                        'communicationHub' => app(\App\Services\CommunicationHub\CommunicationHubPageService::class)->hubProps(
                            $registeredOrg,
                            $request->user(),
                            [
                                'activeTab' => 'announcements',
                                'announcementsLimit' => 5,
                                'discussionsLimit' => 5,
                            ]
                        ),
                    ]
                )
                : [
                    'communityDiscussions' => [],
                    'communityAnnouncements' => [],
                    'canCreateCommunityDiscussion' => false,
                    'canCreateCommunityAnnouncement' => false,
                    'canModerateCommunity' => false,
                    'communityParentType' => null,
                    'communityParentId' => null,
                    'communityReportReasons' => config('community.report_reasons'),
                    'communicationHub' => null,
                ]),
        ]);
    }

    /**
     * Display enrollments for an organization's courses/events
     */
    public function enrollments(Request $request, string $slug)
    {
        // Find organization by user slug
        $user = User::where('slug', $slug)
            ->where('role', 'organization')
            ->firstOrFail();

        $organization = Organization::where('user_id', $user->id)->firstOrFail();

        // Get all courses/events created by this organization
        $courses = Course::where('organization_id', $user->id)
            ->with(['topic', 'eventType'])
            ->get();

        // Get all enrollments grouped by course/event
        $enrollmentsByCourse = [];
        foreach ($courses as $course) {
            $enrollments = Enrollment::where('course_id', $course->id)
                ->whereIn('status', ['active', 'completed', 'pending'])
                ->with('user:id,name,email')
                ->orderBy('enrolled_at', 'desc')
                ->get();

            if ($enrollments->count() > 0) {
                $enrollmentsByCourse[] = [
                    'course' => $course,
                    'enrollments' => $enrollments,
                    'total_enrolled' => $enrollments->count(),
                ];
            }
        }

        return Inertia::render('frontend/organization/enrollments', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'user' => [
                    'slug' => $user->slug,
                ],
            ],
            'enrollmentsByCourse' => $enrollmentsByCourse,
        ]);
    }

    public function toggleFavorite(Request $request, int $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canFollowOrganizations()) {
            return $this->favoriteForbiddenForNonSupporterResponse($request);
        }

        $ctx = $request->input('toggle_favorite_context');
        if (in_array($ctx, ['excel', 'organization', 'alliance'], true)) {
            if ($ctx === 'organization') {
                $org = Organization::query()
                    ->whereKey($id)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->first();
                if ($org === null) {
                    abort(404);
                }

                return $this->toggleFavoriteToggleRegisteredOrg($request, $user, $org);
            }
            if ($ctx === 'alliance') {
                $alliance = CareAlliance::query()->whereKey($id)->where('status', 'active')->first();
                if ($alliance === null) {
                    abort(404);
                }
                $org = app(CareAlliancePublicPageService::class)->hubOrganizationForAlliance($alliance);
                if ($org === null) {
                    return $this->toggleFavoriteToggleCareAllianceOnly($request, $user, $alliance);
                }

                return $this->toggleFavoriteToggleRegisteredOrg($request, $user, $org);
            }
            $excelDataOrg = ExcelData::find($id);
            if ($excelDataOrg === null) {
                abort(404);
            }

            return $this->toggleFavoriteViaExcelRow($request, $user, $id, $excelDataOrg);
        }

        $excelDataOrg = ExcelData::find($id);
        if ($excelDataOrg !== null) {
            return $this->toggleFavoriteViaExcelRow($request, $user, $id, $excelDataOrg);
        }

        $org = Organization::query()
            ->whereKey($id)
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        if ($org === null) {
            $alliance = CareAlliance::query()->whereKey($id)->where('status', 'active')->first();
            if ($alliance !== null) {
                $org = app(CareAlliancePublicPageService::class)->hubOrganizationForAlliance($alliance);
                if ($org === null) {
                    return $this->toggleFavoriteToggleCareAllianceOnly($request, $user, $alliance);
                }
            }
        }

        if ($org === null) {
            abort(404);
        }

        return $this->toggleFavoriteToggleRegisteredOrg($request, $user, $org);
    }

    /**
     * @return RedirectResponse|JsonResponse
     */
    private function favoriteForbiddenForNonSupporterResponse(Request $request): mixed
    {
        $message = __('Only supporter accounts can follow organizations.');

        $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' && ! $request->header('X-Inertia');
        if ($isAjax || $request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }

    /**
     * Follow / unfollow a Unity Impact Alliance when no hub nonprofit is linked yet (organization_id-only favorites are impossible).
     */
    private function toggleFavoriteToggleCareAllianceOnly(Request $request, $user, CareAlliance $alliance): mixed
    {
        if ((int) $user->id === (int) $alliance->creator_user_id) {
            $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' && ! $request->header('X-Inertia');
            if ($isAjax || $request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('You cannot follow your own Unity Impact Alliance.'),
                ], 403);
            }

            return redirect()->back()->with('error', __('You cannot follow your own Unity Impact Alliance.'));
        }

        $isFollowing = false;
        $message = '';

        $fav = UserFavoriteOrganization::where('user_id', $user->id)
            ->where('care_alliance_id', $alliance->id)
            ->first();

        if ($fav) {
            $fav->delete();
            $isFollowing = false;
            $message = 'Unfollowed alliance';
        } else {
            UserFavoriteOrganization::create([
                'user_id' => $user->id,
                'organization_id' => null,
                'excel_data_id' => null,
                'care_alliance_id' => $alliance->id,
                'notifications' => true,
            ]);
            $isFollowing = true;
            $message = 'Following alliance with notifications';
        }

        $isAjaxRequest = $request->header('X-Requested-With') === 'XMLHttpRequest'
            && ! $request->header('X-Inertia');

        if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'is_following' => $isFollowing,
                'message' => $message,
            ]);
        }

        $referer = $request->header('Referer');
        if ($referer) {
            return redirect($referer);
        }

        return redirect()->back();
    }

    /**
     * Public nonprofit profile: toggle by IRS / Excel row id.
     */
    private function toggleFavoriteViaExcelRow(Request $request, $user, int $id, ExcelData $excelDataOrg)
    {
        $org = Organization::where('ein', $excelDataOrg->ein)
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        $userOrg = $user->organization ?? Organization::where('user_id', $user->id)->first();
        if ($userOrg) {
            $isOwnByEin = $userOrg->ein === $excelDataOrg->ein;
            $isOwnById = $org && $userOrg->id === $org->id;
            if ($isOwnByEin || $isOwnById) {
                $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' && ! $request->header('X-Inertia');
                if ($isAjax || $request->wantsJson() || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => __('You cannot follow your own organization.'),
                    ], 403);
                }

                return redirect()->back()->with('error', __('You cannot follow your own organization.'));
            }
        }

        $isFollowing = false;
        $message = '';

        if ($org) {
            $fav = UserFavoriteOrganization::where('user_id', $user->id)
                ->where('organization_id', $org->id)
                ->first();

            if ($fav) {
                $fav->delete();
                $isFollowing = false;
                $message = 'Unfollowed organization';
            } else {
                UserFavoriteOrganization::create([
                    'user_id' => $user->id,
                    'organization_id' => $org->id,
                    'excel_data_id' => $id,
                    'notifications' => true,
                ]);
                $isFollowing = true;
                $message = 'Following organization with notifications';
            }
        } else {
            $excelDataId = (int) $id;
            $fav = UserFavoriteOrganization::where('user_id', $user->id)
                ->where('excel_data_id', $excelDataId)
                ->first();

            if ($fav) {
                $fav->delete();
                $isFollowing = false;
                $message = 'Unfollowed organization';
            } else {
                $createdFavorite = UserFavoriteOrganization::create([
                    'user_id' => $user->id,
                    'organization_id' => null,
                    'excel_data_id' => $excelDataId,
                    'notifications' => true,
                ]);
                $createdFavorite->refresh();
                $isFollowing = true;
                $message = 'Following organization with notifications';
            }
        }

        $isAjaxRequest = $request->header('X-Requested-With') === 'XMLHttpRequest'
            && ! $request->header('X-Inertia');

        if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'is_following' => $isFollowing,
                'message' => $message,
            ]);
        }

        $referer = $request->header('Referer');
        if ($referer) {
            return redirect($referer);
        }

        return redirect()->route('organizations.show', $id)
            ->with('success', $message);
    }

    /**
     * Registered hub / org id or equivalent (after Unity Impact Alliance resolution).
     */
    private function toggleFavoriteToggleRegisteredOrg(Request $request, $user, Organization $org)
    {
        $userOrg = $user->organization ?? Organization::where('user_id', $user->id)->first();
        if ($userOrg) {
            $isOwnByEin = $org->ein && $userOrg->ein && $userOrg->ein === $org->ein;
            $isOwnById = (int) $userOrg->id === (int) $org->id;
            if ($isOwnByEin || $isOwnById) {
                $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' && ! $request->header('X-Inertia');
                if ($isAjax || $request->wantsJson() || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => __('You cannot follow your own organization.'),
                    ], 403);
                }

                return redirect()->back()->with('error', __('You cannot follow your own organization.'));
            }
        }

        $excelDataIdForRow = null;
        if ($org->ein) {
            $excelDataIdForRow = ExcelData::query()
                ->where('ein', $org->ein)
                ->where('status', 'complete')
                ->orderByDesc('id')
                ->value('id');
        }

        $isFollowing = false;
        $message = '';

        $allianceIdsPinnedToHub = CareAlliance::query()
            ->where('hub_organization_id', $org->id)
            ->pluck('id');

        $fav = UserFavoriteOrganization::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();

        if ($fav) {
            $fav->delete();
            if ($allianceIdsPinnedToHub->isNotEmpty()) {
                UserFavoriteOrganization::where('user_id', $user->id)
                    ->whereIn('care_alliance_id', $allianceIdsPinnedToHub)
                    ->delete();
            }
            $isFollowing = false;
            $message = 'Unfollowed organization';
        } else {
            if ($allianceIdsPinnedToHub->isNotEmpty()) {
                UserFavoriteOrganization::where('user_id', $user->id)
                    ->whereIn('care_alliance_id', $allianceIdsPinnedToHub)
                    ->delete();
            }
            UserFavoriteOrganization::create([
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'excel_data_id' => $excelDataIdForRow,
                'care_alliance_id' => null,
                'notifications' => true,
            ]);
            $isFollowing = true;
            $message = 'Following organization with notifications';
        }

        $isAjaxRequest = $request->header('X-Requested-With') === 'XMLHttpRequest'
            && ! $request->header('X-Inertia');

        if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'is_following' => $isFollowing,
                'message' => $message,
            ]);
        }

        $referer = $request->header('Referer');
        if ($referer) {
            return redirect($referer);
        }

        $fallback = $excelDataIdForRow ?? $org->user?->slug;
        if ($fallback !== null) {
            return redirect()->route('organizations.show', $fallback);
        }

        return redirect()->back();
    }

    // public function toggleFavorite(Request $request, int $id)
    // {
    //     $user = Auth::user();

    //     // Get the ExcelData organization
    //     $excelDataOrg = ExcelData::findOrFail($id);

    //     // Find the registered organization by EIN
    //     $org = Organization::where('ein', $excelDataOrg->ein)
    //         ->where('registration_status', 'approved')->excludingCareAllianceHubs()
    //         ->first();

    //     if (!$org) {
    //         if ($request->header('X-Inertia')) {
    //             return redirect()->back()->with('error', 'You can only follow registered organizations.');
    //         }
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'You can only follow registered organizations.'
    //         ], 404);
    //     }

    //     $fav = UserFavoriteOrganization::where('user_id', $user->id)
    //         ->where('organization_id', $org->id)
    //         ->first();

    //     if ($fav) {
    //         $fav->delete();

    //         if ($request->header('X-Inertia')) {
    //             return redirect()->back()->with('success', 'Unfollowed organization');
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Unfollowed organization',
    //             'is_following' => false
    //         ]);
    //     } else {
    //         // Check if user has any follower positions
    //         $hasPositions = FollowingUserPosition::where('user_id', $user->id)->exists();

    //         if (!$hasPositions) {
    //             if ($request->header('X-Inertia')) {
    //                 // For Inertia requests, we'll handle this differently
    //                 return response()->json([
    //                     'requires_positions' => true,
    //                     'organization_id' => $id,
    //                     'organization_name' => $excelDataOrg->name_virtual ?? 'Organization'
    //                 ]);
    //             }

    //             return response()->json([
    //                 'requires_positions' => true,
    //                 'organization_id' => $id,
    //                 'organization_name' => $excelDataOrg->name_virtual ?? 'Organization'
    //             ]);
    //         }

    //         // User has positions, create the favorite
    //         $favorite = UserFavoriteOrganization::create([
    //             'user_id' => $user->id,
    //             'organization_id' => $org->id,
    //             'notifications' => true
    //         ]);

    //         // Award impact points for following
    //         $this->impactScoreService->awardFollowPoints($favorite);

    //         if ($request->header('X-Inertia')) {
    //             return redirect()->back()->with('success', 'Following organization with notifications');
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Following organization with notifications',
    //             'is_following' => true
    //         ]);
    //     }
    // }

    // public function savePositionsAndFollow(Request $request, int $orgId)
    // {
    //     $user = Auth::user();

    //     // Validate the request
    //     $request->validate([
    //         'positions' => 'required|array|min:1',
    //         'positions.*.follower_position_id' => 'required|exists:follower_positions,id',
    //         'positions.*.experience_level' => 'required|in:beginner,intermediate,expert',
    //         'positions.*.years_of_experience' => 'required|integer|min:0',
    //         'positions.*.skills' => 'nullable|string',
    //         'positions.*.portfolio_url' => 'nullable|url',
    //     ]);

    //     // Delete existing positions for this user
    //     FollowingUserPosition::where('user_id', $user->id)->delete();

    //     // Save new positions
    //     foreach ($request->positions as $positionData) {
    //         FollowingUserPosition::create([
    //             'user_id' => $user->id,
    //             'follower_position_id' => $positionData['follower_position_id'],
    //             'experience_level' => $positionData['experience_level'],
    //             'years_of_experience' => $positionData['years_of_experience'],
    //             'skills' => $positionData['skills'] ?? null,
    //             'portfolio_url' => $positionData['portfolio_url'] ?? null,
    //             'is_primary' => $positionData['is_primary'] ?? false,
    //         ]);
    //     }

    //     // Get the ExcelData organization
    //     $excelDataOrg = ExcelData::findOrFail($orgId);

    //     // Find the registered organization by EIN
    //     $org = Organization::where('ein', $excelDataOrg->ein)
    //         ->where('registration_status', 'approved')->excludingCareAllianceHubs()
    //         ->first();

    //     if (!$org) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Organization not found'
    //         ], 404);
    //     }

    //     // Create the favorite
    //     UserFavoriteOrganization::create([
    //         'user_id' => $user->id,
    //         'organization_id' => $org->id,
    //         'notifications' => true
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Following organization with notifications',
    //         'redirect_url' => route('organizations.show', $orgId)
    //     ]);
    // }

    // public function getPositionsForSelection()
    // {
    //     $positions = FollowerPosition::where('is_active', true)
    //         ->orderBy('sort_order')
    //         ->orderBy('name')
    //         ->get()
    //         ->groupBy('category');

    //     $userPositions = FollowingUserPosition::where('user_id', Auth::id())
    //         ->with('followerPosition')
    //         ->get();

    //     return response()->json([
    //         'all_positions' => $positions,
    //         'user_positions' => $userPositions
    //     ]);
    // }

    public function toggleNotifications(Request $request, int $id)
    {
        $user = Auth::user();

        $isAjaxRequest = $request->header('X-Requested-With') === 'XMLHttpRequest'
            && ! $request->header('X-Inertia');

        $ctx = $request->input('toggle_favorite_context');
        $org = null;
        $excelRowUsedForLookup = false;

        if (in_array($ctx, ['excel', 'organization', 'alliance'], true)) {
            if ($ctx === 'organization') {
                $org = Organization::query()
                    ->whereKey($id)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->first();
            } elseif ($ctx === 'alliance') {
                $alliance = CareAlliance::query()->whereKey($id)->where('status', 'active')->first();
                if ($alliance !== null) {
                    $org = app(CareAlliancePublicPageService::class)->hubOrganizationForAlliance($alliance);
                }
            } else {
                $excelDataOrg = ExcelData::find($id);
                if ($excelDataOrg !== null) {
                    $excelRowUsedForLookup = true;
                    $org = Organization::where('ein', $excelDataOrg->ein)
                        ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                        ->first();
                }
            }
        } else {
            $excelDataOrg = ExcelData::find($id);
            if ($excelDataOrg !== null) {
                $excelRowUsedForLookup = true;
                $org = Organization::where('ein', $excelDataOrg->ein)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->first();
            } else {
                $org = Organization::query()
                    ->whereKey($id)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->first();
                if (! $org) {
                    $alliance = CareAlliance::query()->whereKey($id)->where('status', 'active')->first();
                    if ($alliance !== null) {
                        $org = app(CareAlliancePublicPageService::class)->hubOrganizationForAlliance($alliance);
                    }
                }
            }
        }

        $fav = null;

        if ($org) {
            $fav = UserFavoriteOrganization::where('user_id', $user->id)
                ->where('organization_id', $org->id)
                ->first();

            if (! $fav) {
                $allianceIds = CareAlliance::query()
                    ->where('hub_organization_id', $org->id)
                    ->pluck('id');
                if ($allianceIds->isNotEmpty()) {
                    $fav = UserFavoriteOrganization::where('user_id', $user->id)
                        ->whereIn('care_alliance_id', $allianceIds)
                        ->first();
                }
            }
        } else {
            $allianceByKey = CareAlliance::query()->whereKey($id)->where('status', 'active')->first();
            if ($allianceByKey) {
                $fav = UserFavoriteOrganization::where('user_id', $user->id)
                    ->where('care_alliance_id', $allianceByKey->id)
                    ->first();
            } else {
                if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Organization not found',
                    ], 404);
                }

                $referer = $request->header('Referer');
                if ($referer) {
                    return redirect($referer)->with('error', 'Organization not found');
                }
                if ($excelRowUsedForLookup) {
                    return redirect()->route('organizations.show', $id)
                        ->with('error', 'Organization not found');
                }

                return redirect()->back()->with('error', 'Organization not found');
            }
        }

        if (! $fav) {
            if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not following this organization',
                ], 400);
            }

            $referer = $request->header('Referer');
            if ($referer) {
                return redirect($referer)->with('error', 'You are not following this organization');
            }

            $fallback = ExcelData::query()
                ->where('ein', $org->ein)
                ->where('status', 'complete')
                ->orderByDesc('id')
                ->value('id') ?? $org->user?->slug;
            if ($fallback !== null) {
                return redirect()->route('organizations.show', $fallback)
                    ->with('error', 'You are not following this organization');
            }

            return redirect()->back()->with('error', 'You are not following this organization');
        }

        $fav->update([
            'notifications' => ! $fav->notifications,
        ]);

        $message = $fav->notifications ? 'Notifications enabled' : 'Notifications disabled';

        if ($isAjaxRequest || $request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'notifications' => (bool) $fav->notifications,
                'message' => $message,
            ]);
        }

        $referer = $request->header('Referer');
        if ($referer) {
            return redirect($referer)->with('success', $message);
        }

        $fallback = ExcelData::query()
            ->where('ein', $org->ein)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->value('id') ?? $org->user?->slug;
        if ($fallback !== null) {
            return redirect()->route('organizations.show', $fallback)
                ->with('success', $message);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Generate mission statement for an organization using OpenAI
     * Accessible from frontend - no admin restriction, works for registered and unregistered orgs
     * Accepts ExcelData ID (the public organization ID)
     */
    public function generateMission(Request $request, int $id)
    {
        $openAiApiKey = config('services.openai.api_key');
        if (empty($openAiApiKey) || ! is_string($openAiApiKey) || trim($openAiApiKey) === '') {
            Log::warning('Generate mission skipped: OPENAI_API_KEY is not set');

            return response()->json([
                'error' => 'AI description is not configured. Please set OPENAI_API_KEY in the server environment.',
            ], 503);
        }

        try {
            // Find the ExcelData record (this is the public organization ID)
            $excelData = ExcelData::where('id', $id)
                ->where('status', 'complete')
                ->firstOrFail();

            $rowData = $excelData->row_data ?? [];
            $transformedData = is_array($rowData) ? ExcelDataTransformer::transform($rowData) : [];

            // Get organization details from ExcelData
            $orgName = $transformedData[1] ?? $rowData[1] ?? '';
            $orgEin = $excelData->ein;
            $orgCity = $transformedData[4] ?? $rowData[4] ?? '';
            $orgState = $transformedData[5] ?? $rowData[5] ?? '';
            $orgClassification = $transformedData[10] ?? $rowData[10] ?? '';
            $orgNteeCode = $transformedData[26] ?? $rowData[26] ?? '';

            // Find or create Organization record by EIN
            $organization = Organization::where('ein', $orgEin)->first();

            if ($organization) {
                // Check if description already exists and is not empty
                if ($organization->description && trim($organization->description) !== '' &&
                    $organization->description !== 'This organization is listed in our database but has not yet registered for additional features.') {
                    return response()->json([
                        'error' => 'Organization description already exists',
                    ], 400);
                }

                $orgDescription = $organization->description ?? '';
                // Use organization's city/state if available, otherwise use ExcelData
                $orgCity = $organization->city ?? $orgCity;
                $orgState = $organization->state ?? $orgState;
            } else {
                // Create a new Organization record for unregistered orgs
                // user_id can be null now
                $organization = Organization::create([
                    'ein' => $orgEin,
                    'name' => $orgName,
                    'street' => $transformedData[3] ?? $rowData[3] ?? '',
                    'city' => $orgCity,
                    'state' => $orgState,
                    'zip' => $transformedData[6] ?? $rowData[6] ?? '',
                    'classification' => $orgClassification,
                    'ntee_code' => $orgNteeCode,
                    'email' => '',
                    'phone' => '',
                    'contact_name' => '',
                    'contact_title' => '',
                    'description' => '',
                    'mission' => '',
                    'registration_status' => 'pending',
                    'user_id' => null, // Nullable now - no user for unregistered orgs
                ]);

                $orgDescription = '';
            }

            // Build prompt for OpenAI to generate "about" description
            // Include Name, City, and State to avoid confusion when multiple organizations have the same name
            $prompt = "Write a comprehensive and engaging 'About Us' description for a nonprofit organization. ";
            $prompt .= "Organization Name: \"{$orgName}\". ";

            if ($orgCity && trim($orgCity) !== '') {
                $prompt .= "Location: {$orgCity}";
                if ($orgState && trim($orgState) !== '') {
                    $prompt .= ", {$orgState}";
                }
                $prompt .= '. ';
            } elseif ($orgState && trim($orgState) !== '') {
                $prompt .= "Location: {$orgState}. ";
            }

            if ($organization->mission) {
                $prompt .= "The organization's mission is: {$organization->mission}. ";
            }

            if ($orgClassification) {
                $prompt .= "The organization is classified as: {$orgClassification}. ";
            }

            if ($orgNteeCode) {
                $prompt .= "The NTEE code is: {$orgNteeCode}. ";
            }

            $prompt .= 'The description should be informative, engaging, and provide a clear overview of what the organization does, who it serves, and its impact. ';
            $prompt .= "IMPORTANT: The generated description MUST include the organization's name \"{$orgName}\"";
            if ($orgCity && trim($orgCity) !== '') {
                $prompt .= ", the city \"{$orgCity}\"";
            }
            if ($orgState && trim($orgState) !== '') {
                $prompt .= ", and the state \"{$orgState}\"";
            }
            $prompt .= ' within the description text itself. Make sure to naturally incorporate these details into the narrative. ';
            $prompt .= 'Keep it comprehensive (approximately 200-400 words). Return only the description text, no additional commentary or formatting.';

            // Generate description using OpenAI (no token tracking for this feature)
            $openAiService = new OpenAiService;
            $result = $openAiService->chatCompletion([
                [
                    'role' => 'system',
                    'content' => 'You are a professional nonprofit consultant specializing in writing compelling organization descriptions. Create clear, engaging, and informative "About Us" descriptions for nonprofit organizations.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ]);
            $generatedDescription = $result['content'];

            // Update organization with generated description
            $organization->update([
                'description' => trim($generatedDescription),
            ]);

            return response()->json([
                'success' => true,
                'description' => trim($generatedDescription),
            ]);

        } catch (\Throwable $e) {
            Log::error('Error generating mission statement', [
                'excel_data_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = 'Failed to generate mission statement. Please try again later.';
            if (str_contains($e->getMessage(), 'OpenAI') || str_contains($e->getMessage(), 'API')) {
                $message = 'AI service error. Please check that OPENAI_API_KEY is set and valid.';
            }

            return response()->json([
                'error' => $message,
            ], 500);
        }
    }

    /**
     * Get full organization data for tab pages
     */
    private function getOrganizationData(string $id): array
    {
        // Ultra-fast lookup: Try direct ID first (uses primary key index)
        $organization = ExcelData::where('id', $id)
            ->where('status', 'complete')
            ->select('id', 'ein', 'row_data', 'created_at', 'updated_at') // Only select needed columns
            ->first();

        $registeredOrg = null;

        if ($organization) {
            // Single query to get registered org if exists
            $registeredOrg = Organization::where('ein', $organization->ein)
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->with('user:id,slug,name,email,image,cover_img')
                ->select('id', 'ein', 'user_id', 'name', 'description', 'mission', 'website', 'wefunder_project_url', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state', 'memberships_enabled', 'has_members', 'membership_type')
                ->first();
        } else {
            // Fallback: Try by user slug
            $user = User::where('slug', $id)->select('id', 'slug')->first();

            if ($user) {
                $registeredOrg = Organization::where('user_id', $user->id)
                    ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                    ->with('user:id,slug,name,email,image,cover_img')
                    ->select('id', 'ein', 'user_id', 'name', 'description', 'mission', 'website', 'wefunder_project_url', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state', 'memberships_enabled', 'has_members', 'membership_type')
                    ->first();

                if ($registeredOrg) {
                    $organization = ExcelData::where('ein', $registeredOrg->ein)
                        ->where('status', 'complete')
                        ->select('id', 'ein', 'row_data', 'created_at', 'updated_at')
                        ->orderBy('id', 'desc')
                        ->limit(1)
                        ->first();
                }
            }
        }

        if (! $organization) {
            abort(404, 'Organization not found');
        }

        $rowData = $organization->row_data;
        $transformedData = ExcelDataTransformer::transform($rowData);

        // Reuse registeredOrg if already loaded, otherwise query once with select for speed
        if (! $registeredOrg) {
            $registeredOrg = Organization::where('ein', $organization->ein)
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->with('user:id,slug,name,email,image,cover_img')
                ->select('id', 'ein', 'user_id', 'name', 'description', 'mission', 'website', 'wefunder_project_url', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state', 'memberships_enabled', 'has_members', 'membership_type')
                ->first();
        }

        // Get any org record only if registeredOrg not found (for mission/description fallback)
        $anyOrgRecord = $registeredOrg;
        if (! $anyOrgRecord) {
            $anyOrgRecord = Organization::where('ein', $organization->ein)
                ->select('id', 'ein', 'description', 'mission', 'website', 'wefunder_project_url', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state')
                ->first();
        }

        // Check favorite status only if user is authenticated (optimized)
        $isFav = false;
        $notificationsEnabled = false;

        if (Auth::check()) {
            if ($registeredOrg) {
                // Check favorite by organization_id for registered organizations
                $favorite = UserFavoriteOrganization::where('user_id', Auth::id())
                    ->where('organization_id', $registeredOrg->id)
                    ->select('id', 'notifications')
                    ->first();

                if ($favorite) {
                    $isFav = true;
                    $notificationsEnabled = $favorite->notifications;
                }
            } else {
                // Check favorite by excel_data_id for unregistered organizations
                $excelDataId = (int) $organization->id;
                $favorite = UserFavoriteOrganization::where('user_id', Auth::id())
                    ->where('excel_data_id', $excelDataId)
                    ->select('id', 'notifications')
                    ->first();

                if ($favorite) {
                    $isFav = true;
                    $notificationsEnabled = $favorite->notifications;
                }
            }
        }

        // User already loaded with eager loading above, no need to reload
        $isOwnOrganization = false;
        if (Auth::check()) {
            $authUserOrg = Auth::user()->organization ?? Organization::where('user_id', Auth::id())->first();
            if ($authUserOrg) {
                $isOwnOrganization = $authUserOrg->ein === $organization->ein
                    || ($registeredOrg && $authUserOrg->id === $registeredOrg->id);
            }
        }

        return [
            'id' => $organization->id,
            'ein' => $organization->ein,
            'is_own_organization' => $isOwnOrganization,
            'name' => $transformedData[1] ?? $rowData[1] ?? '',
            'ico' => $transformedData[2] ?? $rowData[2] ?? '',
            'street' => $transformedData[3] ?? $rowData[3] ?? '',
            'city' => $transformedData[4] ?? $rowData[4] ?? '',
            'state' => $transformedData[5] ?? $rowData[5] ?? '',
            'zip' => $transformedData[6] ?? $rowData[6] ?? '',
            'classification' => $transformedData[10] ?? $rowData[10] ?? '',
            'ntee_code' => $transformedData[26] ?? $rowData[26] ?? '',
            'created_at' => $registeredOrg?->created_at ?? $organization->created_at,
            'is_registered' => (bool) $registeredOrg,
            'is_favorited' => $isFav,
            'notifications_enabled' => $notificationsEnabled,
            'registered_organization' => $registeredOrg ? [
                'id' => $registeredOrg->id,
                'name' => $registeredOrg->name,
                'user' => $registeredOrg->user ? [
                    'id' => $registeredOrg->user->id,
                    'slug' => $registeredOrg->user->slug,
                    'name' => $registeredOrg->user->name,
                    'email' => $registeredOrg->user->email,
                    'image' => $registeredOrg->user->image,
                    'cover_img' => $registeredOrg->user->cover_img,
                ] : null,
            ] : null,
            'description' => $registeredOrg ? $registeredOrg->description : ($anyOrgRecord ? $anyOrgRecord->description : 'This organization is listed in our database but has not yet registered for additional features.'),
            'mission' => $anyOrgRecord && $anyOrgRecord->mission && trim($anyOrgRecord->mission) !== ''
                ? $anyOrgRecord->mission
                : 'Mission statement not available for unregistered organizations.',
            'website' => $registeredOrg && $registeredOrg->website ? $registeredOrg->website : ($anyOrgRecord && $anyOrgRecord->website ? $anyOrgRecord->website : null),
            'wefunder_project_url' => $registeredOrg && $registeredOrg->wefunder_project_url ? $registeredOrg->wefunder_project_url : ($anyOrgRecord && $anyOrgRecord->wefunder_project_url ? $anyOrgRecord->wefunder_project_url : null),
            'ruling' => $transformedData[7] ?? $rowData[7] ?? 'N/A',
            'phone' => $registeredOrg ? $registeredOrg->phone : ($anyOrgRecord ? $anyOrgRecord->phone : null),
            'email' => $registeredOrg ? $registeredOrg->email : ($anyOrgRecord ? $anyOrgRecord->email : null),
            'contact_name' => $registeredOrg ? $registeredOrg->contact_name : ($anyOrgRecord ? $anyOrgRecord->contact_name : null),
            'contact_title' => $registeredOrg ? $registeredOrg->contact_title : ($anyOrgRecord ? $anyOrgRecord->contact_title : null),
            'social_accounts' => $registeredOrg ? $registeredOrg->social_accounts : ($anyOrgRecord ? $anyOrgRecord->social_accounts : []),
            '_registered_org' => $registeredOrg, // Include for reuse in tab methods
        ];
    }

    /**
     * Show organization products tab
     */
    public function products(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);
        $registeredOrg = Organization::where('ein', $organizationData['ein'])
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        $products = [];
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $excelDataId = (int) $organizationData['id'];

        if ($registeredOrg) {
            $products = Product::where('organization_id', $registeredOrg->id)
                ->where('publish_status', 'published')
                ->with(['categories', 'variants'])
                ->latest()
                ->paginate(12)
                ->items();

            // Get counts only - defer loading full data
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();
            $supporters = []; // Defer loading - only needed for supporters tab
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
        }

        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'products'),
            'products' => $products,
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'supporters' => $supporters,
            'currentPage' => 'products',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$sidebarData,
        ]);
    }

    /**
     * Show organization jobs tab
     */
    public function jobs(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);
        // Reuse registeredOrg from getOrganizationData to avoid duplicate query
        $registeredOrg = $organizationData['_registered_org'] ?? null;

        if (! $registeredOrg && $organizationData['is_registered']) {
            $registeredOrg = Organization::where('ein', $organizationData['ein'])
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->select('id', 'user_id', 'name', 'description', 'mission', 'website', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state')
                ->first();
        }

        $jobs = [];
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $excelDataId = (int) $organizationData['id'];

        if ($registeredOrg) {
            $jobsQuery = JobPost::where('organization_id', $registeredOrg->id)
                ->with(['position', 'organization'])
                ->latest();

            // Add has_applied check if user is authenticated
            if (Auth::check()) {
                $jobsQuery->withExists([
                    'applications as has_applied' => function ($q) {
                        $q->where('user_id', Auth::id());
                    },
                ]);
            }

            // Get total count before pagination
            $jobsCount = $jobsQuery->count();
            $jobs = $jobsQuery->paginate(12)->items();

            // Get all data needed for the main organization page
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count();
            $postsCount += FacebookPost::where('organization_id', $registeredOrg->id)->where('status', 'published')->count();
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $supporters = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)
                ->with('user:id,name,email,image,slug,role')
                ->latest()
                ->get()
                ->map(function ($favorite) {
                    return [
                        'id' => $favorite->id,
                        'user_id' => $favorite->user_id,
                        'user' => $favorite->user ? [
                            'id' => $favorite->user->id,
                            'slug' => $favorite->user->slug,
                            'name' => $favorite->user->name,
                            'email' => $favorite->user->email,
                            'image' => $favorite->user->image,
                            'role' => $favorite->user->role,
                        ] : null,
                        'notifications' => $favorite->notifications ?? false,
                        'joined_at' => $favorite->created_at?->toIso8601String(),
                    ];
                })
                ->toArray();
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
        }

        $believePoints = $this->believePointsForOrganizationViewer(
            $registeredOrg,
            (bool) ($organizationData['is_own_organization'] ?? false)
        );
        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'jobs'),
            'jobs' => $jobs,
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'supporters' => $supporters,
            'currentPage' => 'jobs',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$believePoints,
            ...$sidebarData,
        ]);
    }

    /**
     * Show organization events tab
     */
    public function events(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);
        $registeredOrg = Organization::where('ein', $organizationData['ein'])
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        $eventsPaginator = new LengthAwarePaginator([], 0, 12);
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $excelDataId = (int) $organizationData['id'];

        if ($registeredOrg) {
            $eventsPaginator = Event::where('organization_id', $registeredOrg->id)
                ->with(['eventType', 'organization'])
                ->latest()
                ->paginate(12);

            // Get counts only - defer loading full data
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();
            $supporters = []; // Defer loading - only needed for supporters tab
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
        }

        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'events'),
            'events' => $eventsPaginator,
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'supporters' => $supporters,
            'currentPage' => 'events',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$sidebarData,
        ]);
    }

    /**
     * Show organization social media tab
     */
    public function socialMedia(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);
        $registeredOrg = Organization::where('ein', $organizationData['ein'])
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        if (! $registeredOrg) {
            abort(404, 'Organization not found');
        }

        // Get Facebook posts
        $facebookPosts = FacebookPost::where('organization_id', $registeredOrg->id)
            ->where('status', 'published')
            ->with('facebookAccount')
            ->latest()
            ->paginate(12);

        return Inertia::render('frontend/organization/tabs/SocialMedia', [
            'organization' => $organizationData,
            'facebookPosts' => $facebookPosts,
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
        ]);
    }

    /**
     * Show organization about tab
     */
    public function about(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);

        // Reuse registeredOrg from getOrganizationData to avoid duplicate query
        $registeredOrg = $organizationData['_registered_org'] ?? null;

        if (! $registeredOrg && $organizationData['is_registered']) {
            $registeredOrg = Organization::where('ein', $organizationData['ein'])
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->select('id', 'user_id', 'name', 'description', 'mission', 'website', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state')
                ->first();
        }
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $excelDataId = (int) $organizationData['id'];

        if ($registeredOrg) {
            // Get counts only - defer loading full data
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();
            $supporters = []; // Defer loading - only needed for supporters tab
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
        }

        $believePoints = $this->believePointsForOrganizationViewer(
            $registeredOrg,
            (bool) ($organizationData['is_own_organization'] ?? false)
        );
        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'about'),
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'supporters' => $supporters,
            'currentPage' => 'about',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$believePoints,
            ...$sidebarData,
        ]);
    }

    /**
     * Show organization impact tab
     */
    public function impact(Request $request, string $id)
    {
        $organization = ExcelData::findOrFail($id);
        $registeredOrg = Organization::where('ein', $organization->ein)
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();

        // Get impact data
        $impactScore = $this->impactScoreService->calculateImpactScore($organization->ein);

        return Inertia::render('frontend/organization/tabs/Impact', [
            'organization' => [
                'id' => $organization->id,
                'name' => $registeredOrg ? $registeredOrg->name : '',
                'ein' => $organization->ein,
            ],
            'impactScore' => $impactScore,
        ]);
    }

    /**
     * Show organization details tab
     */
    public function details(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);

        return Inertia::render('frontend/organization/tabs/Details', [
            'organization' => $organizationData,
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
        ]);
    }

    /**
     * Show organization contact tab
     */
    public function contact(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);

        // Get all data needed for the main organization page
        $registeredOrg = Organization::where('ein', $organizationData['ein'])
            ->where('registration_status', 'approved')->excludingCareAllianceHubs()
            ->first();
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $excelDataId = (int) $organizationData['id'];

        if ($registeredOrg) {
            // Get counts only - defer loading full data
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();
            $supportersCount = UserFavoriteOrganization::where('organization_id', $registeredOrg->id)->count();
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();
            $supporters = []; // Defer loading - only needed for supporters tab
        } else {
            // For unregistered organizations, count supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
        }

        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'contact'),
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'supporters' => $supporters,
            'currentPage' => 'contact',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$sidebarData,
        ]);
    }

    /**
     * Show organization supporters tab
     */
    public function supporters(Request $request, string $id)
    {
        $organizationData = $this->getOrganizationData($id);

        // Reuse registeredOrg from getOrganizationData to avoid duplicate query
        $registeredOrg = $organizationData['_registered_org'] ?? null;

        if (! $registeredOrg && $organizationData['is_registered']) {
            $registeredOrg = Organization::where('ein', $organizationData['ein'])
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->select('id', 'user_id', 'name', 'description', 'mission', 'website', 'phone', 'email', 'contact_name', 'contact_title', 'social_accounts', 'city', 'state')
                ->first();
        }
        $postsCount = 0;
        $supportersCount = 0;
        $jobsCount = 0;
        $supporters = [];
        $primarySupporters = [];
        $secondarySupporters = [];
        $primarySupportersCount = 0;
        $secondarySupportersCount = 0;

        // Get organization ID (excel_data_id for unregistered, organization id for registered)
        $orgId = $registeredOrg ? $registeredOrg->id : null;
        $excelDataId = $registeredOrg ? null : (int) $organizationData['id'];

        if ($registeredOrg) {
            // Get counts only - defer loading full data for faster tab switching
            $postsCount = Post::where('user_id', $registeredOrg->user_id)->count()
                + FacebookPost::where('organization_id', $registeredOrg->id)
                    ->where('status', 'published')
                    ->count();
            $supportersPayload = app(\App\Services\SupporterPrimaryOrganizationService::class)
                ->supportersPayloadForOrganization($registeredOrg, 50);
            $supportersCount = $supportersPayload['primary_count'] + $supportersPayload['secondary_count'];
            $jobsCount = JobPost::where('organization_id', $registeredOrg->id)->count();

            $supporters = $supportersPayload['secondary'];
            $primarySupporters = $supportersPayload['primary'];
            $secondarySupporters = $supportersPayload['secondary'];
            $primarySupportersCount = $supportersPayload['primary_count'];
            $secondarySupportersCount = $supportersPayload['secondary_count'];
        } else {
            // For unregistered organizations, get supporters by excel_data_id
            $supportersCount = UserFavoriteOrganization::where('excel_data_id', $excelDataId)->count();
            $jobsCount = 0; // Unregistered orgs don't have jobs

            // Load supporters data for unregistered organizations
            $favoriteRecords = UserFavoriteOrganization::where('excel_data_id', $excelDataId)
                ->with('user:id,name,email,image,slug,role')
                ->latest()
                ->limit(50)
                ->get();

            $supporters = $favoriteRecords->map(function ($favorite) {
                if ($favorite->user) {
                    return [
                        'id' => $favorite->id,
                        'user_id' => $favorite->user_id,
                        'user' => [
                            'id' => $favorite->user->id,
                            'slug' => $favorite->user->slug,
                            'name' => $favorite->user->name,
                            'email' => $favorite->user->email,
                            'image' => $favorite->user->image,
                            'role' => $favorite->user->role,
                        ],
                        'notifications' => $favorite->notifications ?? false,
                        'joined_at' => $favorite->created_at?->toIso8601String(),
                    ];
                }

                return null;
            })->filter()->values()->toArray();
        }

        $believePoints = $this->believePointsForOrganizationViewer(
            $registeredOrg,
            (bool) ($organizationData['is_own_organization'] ?? false)
        );
        $sidebarData = $this->getSidebarData($registeredOrg);

        return Inertia::render('frontend/organization/organization-show', [
            'organization' => $organizationData,
            'seo' => $this->seoForOrganizationShow($organizationData, 'supporters'),
            'postsCount' => $postsCount,
            'supportersCount' => $supportersCount,
            'jobsCount' => $jobsCount,
            'supporters' => $supporters,
            'primarySupporters' => $primarySupporters,
            'secondarySupporters' => $secondarySupporters,
            'primarySupportersCount' => $primarySupportersCount,
            'secondarySupportersCount' => $secondarySupportersCount,
            'currentPage' => 'supporters',
            'auth' => Auth::user() ? ['user' => Auth::user()] : null,
            'connectedCareAlliances' => $this->connectedCareAlliancesPayload($registeredOrg),
            'membershipJoin' => $this->membershipJoinPayload($registeredOrg),
            ...$believePoints,
            ...$sidebarData,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function organizationTabSeoLabels(): array
    {
        return [
            'products' => 'Products',
            'jobs' => 'Jobs',
            'events' => 'Events',
            'about' => 'About',
            'contact' => 'Contact',
            'supporters' => 'Supporters',
        ];
    }

    /**
     * @param  array<string, mixed>  $organization
     * @return array<string, mixed>
     */
    private function seoForOrganizationShow(array $organization, ?string $currentPage = null): array
    {
        $tabLabel = $this->organizationTabSeoLabels()[$currentPage ?? ''] ?? null;

        return SeoService::forOrganization($organization, $tabLabel);
    }

    /**
     * Active Unity Impact Alliances this nonprofit is a member of (public profile).
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    private function connectedCareAlliancesPayload(?Organization $registeredOrg): array
    {
        if ($registeredOrg === null) {
            return [];
        }

        return CareAllianceMembership::query()
            ->where('organization_id', $registeredOrg->id)
            ->where('status', 'active')
            ->whereHas('careAlliance', fn ($q) => $q->where('status', 'active'))
            ->with(['careAlliance:id,slug,name'])
            ->get()
            ->map(function (CareAllianceMembership $m) {
                $alliance = $m->careAlliance;

                return $alliance ? [
                    'id' => (int) $alliance->id,
                    'name' => $alliance->name,
                    'slug' => $alliance->slug,
                ] : null;
            })
            ->filter()
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function membershipJoinPayload(?Organization $registeredOrg): ?array
    {
        if ($registeredOrg === null) {
            return null;
        }

        // Profile queries often use a limited select(); reload membership fields explicitly.
        $org = Organization::query()
            ->whereKey($registeredOrg->getKey())
            ->with(['membershipPlan', 'user:id,slug'])
            ->first([
                'id',
                'name',
                'user_id',
                'ein',
                'memberships_enabled',
                'has_members',
                'membership_type',
            ]);

        if ($org === null) {
            return null;
        }

        return app(MembershipAccountService::class)->publicJoinPayload($org, Auth::user());
    }

    /**
     * Public community groups for an organization page (hidden groups excluded for non-managers).
     *
     * @return array{groups: list<array<string, mixed>>, can_create: bool, create_url: string|null}
     */
    private function communityGroupsPayload(?Organization $registeredOrg, ?User $viewer): array
    {
        if ($registeredOrg === null) {
            return ['groups' => [], 'can_create' => false, 'create_url' => null];
        }

        $canManage = false;
        if ($viewer) {
            $own = Organization::forAuthUser($viewer);
            $canManage = $own !== null && (int) $own->id === (int) $registeredOrg->id;
        }

        $groups = Group::query()
            ->where('parent_type', MembershipAccountType::Organization->value)
            ->where('parent_id', $registeredOrg->id)
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
            ? app(GroupEligibilityService::class)->canCreateGroupUnder($viewer, $registeredOrg)
            : false;

        return [
            'groups' => $groups,
            'can_create' => $canCreate,
            'create_url' => $canCreate
                ? route('groups.create', [
                    'parent_type' => MembershipAccountType::Organization->value,
                    'parent_id' => $registeredOrg->id,
                ])
                : null,
        ];
    }

    /**
     * Believe points for the public org profile: only the org account holder gets real values.
     *
     * @return array{believePointsEarned: float|int, believePointsSpent: int, believePointsBalance: float|int}
     */
    private function believePointsForOrganizationViewer(?Organization $registeredOrg, bool $isOwnOrganization): array
    {
        if (! $registeredOrg || ! $isOwnOrganization) {
            return [
                'believePointsEarned' => 0,
                'believePointsSpent' => 0,
                'believePointsBalance' => 0,
            ];
        }

        return $this->calculateBelievePoints($registeredOrg->id);
    }

    /**
     * Calculate believe points for an organization
     */
    private function calculateBelievePoints($organizationId)
    {
        $believePointsEarned = Donation::where('organization_id', $organizationId)
            ->where('payment_method', 'believe_points')
            ->where('status', 'completed')
            ->sum('amount');

        $believePointsSpent = 0; // TODO: Calculate from transactions if organizations can spend points
        $believePointsBalance = $believePointsEarned - $believePointsSpent;

        return [
            'believePointsEarned' => $believePointsEarned,
            'believePointsSpent' => $believePointsSpent,
            'believePointsBalance' => $believePointsBalance,
        ];
    }

    /**
     * Get sidebar data (People You May Know and Trending Organizations)
     * Optimized to use bulk queries instead of N+1 queries
     */
    private function getSidebarData($registeredOrg = null)
    {
        // Get people you may know (suggested users/organizations)
        $peopleYouMayKnow = [];
        if (Auth::check()) {
            // Get other organizations the user might be interested in
            $userFavoriteOrgIds = UserFavoriteOrganization::where('user_id', Auth::id())
                ->pluck('organization_id')
                ->toArray();

            $suggestedOrgs = Organization::where('registration_status', 'approved')
                ->excludingCareAllianceHubs()
                ->whereNotIn('id', $userFavoriteOrgIds)
                ->when($registeredOrg, function ($query) use ($registeredOrg) {
                    return $query->where('id', '!=', $registeredOrg->id);
                })
                ->with('user:id,slug,name,image')
                ->limit(4)
                ->get();

            // Bulk fetch ExcelData IDs for all suggested orgs at once (optimized)
            if ($suggestedOrgs->isNotEmpty()) {
                $eins = $suggestedOrgs->pluck('ein')->filter()->unique()->toArray();

                // Get ExcelData IDs in bulk - use latest record per EIN (much faster, no subquery)
                $excelDataMap = ExcelData::whereIn('ein', $eins)
                    ->where('status', 'complete')
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('ein')
                    ->map(function ($group) {
                        return $group->first()->id; // Get the first (latest) record
                    });

                $peopleYouMayKnow = $suggestedOrgs->map(function ($org) use ($excelDataMap) {
                    return [
                        'id' => $org->id,
                        'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                        'slug' => $org->user?->slug ?? null,
                        'name' => $org->name,
                        'org' => $org->description ? Str::limit($org->description, 30) : 'Organization',
                        'avatar' => $org->user?->image ? '/storage/'.$org->user->image : null,
                    ];
                })->toArray();
            }
        }

        // Get trending organizations (by follower count)
        $trendingOrgs = Organization::where('registration_status', 'approved')
            ->excludingCareAllianceHubs()
            ->withCount('followers')
            ->with('user:id,slug,name')
            ->orderBy('followers_count', 'desc')
            ->limit(4)
            ->get();

        // Bulk fetch ExcelData IDs for all trending orgs at once (optimized - no subquery)
        $trendingOrganizations = [];
        if ($trendingOrgs->isNotEmpty()) {
            $eins = $trendingOrgs->pluck('ein')->filter()->unique()->toArray();

            // Get ExcelData IDs in bulk - use latest record per EIN (much faster)
            $excelDataMap = ExcelData::whereIn('ein', $eins)
                ->where('status', 'complete')
                ->orderBy('id', 'desc')
                ->get()
                ->groupBy('ein')
                ->map(function ($group) {
                    return $group->first()->id; // Get the first (latest) record
                });

            $colors = ['bg-rose-500', 'bg-cyan-500', 'bg-teal-500', 'bg-blue-500'];

            $trendingOrganizations = $trendingOrgs->map(function ($org, $index) use ($excelDataMap, $colors) {
                return [
                    'id' => $org->id,
                    'excel_data_id' => $excelDataMap->get($org->ein) ?? null,
                    'slug' => $org->user?->slug ?? null,
                    'name' => $org->name,
                    'desc' => $org->description ? Str::limit($org->description, 50) : 'Organization description',
                    'color' => $colors[$index % count($colors)],
                ];
            })->toArray();
        }

        $eventsCount = $registeredOrg ? Event::where('organization_id', $registeredOrg->id)->count() : 0;

        return [
            'peopleYouMayKnow' => $peopleYouMayKnow,
            'trendingOrganizations' => $trendingOrganizations,
            'eventsCount' => $eventsCount,
        ];
    }

    /**
     * Invite an unregistered organization via email.
     *
     * After the invite is accepted and the organization verifies email, the inviter's Believe Points
     * schedule starts (AwardInviteRewardPoints listener). Remaining installments are credited daily by
     * the `organizations:process-invite-believe-point-schedule` Artisan command.
     */
    public function inviteOrganization(Request $request)
    {
        $request->validate([
            'organization_id' => 'required|exists:excel_data,id',
            'organization_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'You must be logged in to invite organizations',
            ], 401);
        }

        try {
            // Get organization data
            $organization = ExcelData::findOrFail($request->organization_id);

            // Check if organization is already registered
            $registeredOrg = Organization::where('ein', $organization->ein)
                ->where('registration_status', 'approved')->excludingCareAllianceHubs()
                ->first();

            if ($registeredOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'This organization is already registered',
                ], 400);
            }

            // Check if user has already invited this organization
            $existingInvite = OrganizationInvite::where('excel_data_id', $organization->id)
                ->where('inviter_id', $user->id)
                ->where('email', $request->email)
                ->first();

            if ($existingInvite) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already invited this organization to this email address',
                ], 400);
            }

            // Create invite record
            $invite = OrganizationInvite::create([
                'excel_data_id' => $organization->id,
                'inviter_id' => $user->id,
                'email' => $request->email,
                'organization_name' => $request->organization_name,
                'ein' => $organization->ein,
                'status' => 'pending',
            ]);

            // Dispatch job to send email via queue
            SendOrganizationInviteJob::dispatch($invite);

            Log::info('Organization invite job dispatched', [
                'inviter_id' => $user->id,
                'organization_id' => $organization->id,
                'email' => $request->email,
                'invite_id' => $invite->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invitation sent successfully!',
                'data' => [
                    'invite_id' => $invite->id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send organization invite', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'organization_id' => $request->organization_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send invitation. Please try again later.',
            ], 500);
        }
    }
}
