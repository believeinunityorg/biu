<?php

namespace App\Http\Controllers;

use App\Models\CareAlliance;
use App\Models\Donation;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CareAlliancePublicPageService;
use App\Services\BelievePointsPaymentMethodSyncService;
use App\Services\DonationProcessingFeeEstimator;
use App\Services\DonationStripeConnectCheckoutSessionService;
use App\Services\DonationStripeConnectRouting;
use App\Services\DonationStripeDescriptionBuilder;
use App\Services\DonationStripePaymentCompletion;
use App\Services\UserStripePaymentMethodService;
use App\Services\ImpactScoreService;
use App\Services\SeoService;
use App\Services\SupporterPrimaryOrganizationService;
use App\Services\StripeConfigService;
use App\Services\StripeConnectOrganizationService;
use App\Services\StripeEnvironmentSyncService;
use App\Services\Payments\BelievePointsRewardService;
use App\Services\Payments\OrganizationPaymentMethodResolver;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\StripeProcessingFeeEstimator;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

class DonationController extends Controller
{
    protected $impactScoreService;

    public function __construct(ImpactScoreService $impactScoreService)
    {
        $this->impactScoreService = $impactScoreService;
    }

    /**
     * Recipient nonprofit is allowed to receive donations if they have a platform plan and/or an active Stripe subscription.
     * Aligns with ProductController: Cashier subscriptions often exist without users.current_plan_id being set.
     */
    private function recipientUserHasActiveSubscription(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->current_plan_id !== null) {
            return true;
        }

        if (method_exists($user, 'subscribed')) {
            try {
                if ($user->subscribed()) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to check Cashier subscription for donation recipient', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Named subscriptions (not only `default`) — any active/trialing row in Cashier
        try {
            if ($user->subscriptions()->whereIn('stripe_status', ['active', 'trialing'])->exists()) {
                return true;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to list subscriptions for donation recipient', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Unity Impact Alliance donations often credit a member/hub org whose owner is not the paying platform user.
     * Allow when the alliance creator (subscriber) has an active plan, if they are the real billing account.
     */
    private function donationRecipientMatchesAlliance(CareAlliance $alliance, Organization $recipientOrg): bool
    {
        if (strtolower(trim((string) $alliance->status)) !== 'active') {
            return false;
        }
        $expected = app(CareAlliancePublicPageService::class)->donationRecipientOrganizationForAlliance($alliance);

        return $expected !== null && (int) $expected->id === (int) $recipientOrg->id;
    }

    private function careAllianceCandidateIdsForRecipientOrganization(Organization $recipientOrg): Collection
    {
        $ids = CareAlliance::query()
            ->where('hub_organization_id', $recipientOrg->id)
            ->whereRaw('LOWER(TRIM(status)) = ?', ['active'])
            ->pluck('id');

        if (Schema::hasTable('care_alliance_memberships')) {
            $ids = $ids->merge(
                DB::table('care_alliance_memberships')
                    ->where('organization_id', $recipientOrg->id)
                    ->where('status', 'active')
                    ->pluck('care_alliance_id')
            );
        }

        return $ids->unique()->values();
    }

    private function careAllianceCreatorSubscriptionCoversDonation(int $careAllianceId, Organization $recipientOrg): bool
    {
        $alliance = CareAlliance::query()->find($careAllianceId);
        if (! $alliance || ! $this->donationRecipientMatchesAlliance($alliance, $recipientOrg)) {
            return false;
        }
        if (! $alliance->creator_user_id) {
            return false;
        }
        $creator = User::query()->find($alliance->creator_user_id);

        return $creator && $this->recipientUserHasActiveSubscription($creator);
    }

    /**
     * When care_alliance_id is missing, find active alliances whose resolved donation recipient is this org
     * and whose creator has a subscription (hub org and/or active member org).
     */
    private function anyAllianceCreatorPlanCoversRecipientOrganization(Organization $recipientOrg): bool
    {
        foreach ($this->careAllianceCandidateIdsForRecipientOrganization($recipientOrg)->all() as $aid) {
            $alliance = CareAlliance::query()->find($aid);
            if (! $alliance || ! $this->donationRecipientMatchesAlliance($alliance, $recipientOrg)) {
                continue;
            }
            if ($alliance->creator_user_id) {
                $creator = User::query()->find($alliance->creator_user_id);
                if ($creator && $this->recipientUserHasActiveSubscription($creator)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * First matching alliance for this recipient (stable order) — used for Stripe checkout line item label.
     */
    private function firstCareAllianceMatchingRecipientOrganization(Organization $recipientOrg): ?CareAlliance
    {
        foreach ($this->careAllianceCandidateIdsForRecipientOrganization($recipientOrg)->sort()->values()->all() as $aid) {
            $alliance = CareAlliance::query()->find($aid);
            if ($alliance && $this->donationRecipientMatchesAlliance($alliance, $recipientOrg)) {
                return $alliance;
            }
        }

        return null;
    }

    private function resolveCareAllianceForCheckoutDisplay(Request $request, Organization $recipientOrg): ?CareAlliance
    {
        if ($request->input('recipient_kind') === 'care_alliance') {
            $careAllianceId = $request->input('care_alliance_id');
            if ($careAllianceId !== null && $careAllianceId !== '') {
                $alliance = CareAlliance::query()->find((int) $careAllianceId);
                if ($alliance && $this->donationRecipientMatchesAlliance($alliance, $recipientOrg)) {
                    return $alliance;
                }
            }

            return $this->firstCareAllianceMatchingRecipientOrganization($recipientOrg);
        }

        // Org profile / favorites / embedded donate flows omit recipient_kind but use the same organization_id
        // as the alliance's public donation recipient — attach alliance so financial settings apply.
        if ($request->missing('recipient_kind')) {
            return $this->firstCareAllianceMatchingRecipientOrganization($recipientOrg);
        }

        return null;
    }

    /**
     * Display a listing of the donations.
     */
    // In your DonationController.php
    public function index(Request $request)
    {
        $user = Auth::user();
        $primaryOrgService = app(SupporterPrimaryOrganizationService::class);
        $organizationFilterId = $primaryOrgService->resolveListingOrganizationFilterId($request, 'organization_id');

        $search = $request->input('search');
        $hasSearch = $search !== null && $search !== '';

        // Approved nonprofits available for donations
        $orgQuery = Organization::where('registration_status', 'approved')->excludingCareAllianceHubs();
        if ($organizationFilterId !== null) {
            $orgQuery->where('id', $organizationFilterId);
        }
        if ($hasSearch) {
            $orgQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('mission', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%')
                    ->orWhere('state', 'like', '%'.$search.'%');
            });
        }

        $orgRows = $orgQuery->orderBy('name')->get()->map(function (Organization $org) {
            return [
                'id' => 'org-'.$org->id,
                'kind' => 'organization',
                'organization_id' => $org->id,
                'name' => $org->name,
                'description' => $org->description ?? $org->mission ?? 'No description available.',
                'image' => $org->registered_user_image ? asset('storage/'.$org->registered_user_image) : null,
                'raised' => (float) ($org->balance ?? 0),
                'goal' => 0,
                'supporters' => $org->donations()->distinct('user_id')->count('user_id'),
                'has_ein' => (bool) $org->has_ein,
                'ein_verified' => (bool) $org->has_ein,
            ];
        });

        // Unity Impact Alliances: show when an approved recipient org exists (hub or active member).
        // Listing matches organizations (no subscription filter); store() enforces subscription at checkout.
        $allianceQuery = CareAlliance::query()
            ->whereRaw('LOWER(TRIM(status)) = ?', ['active'])
            ->with('creator:id,image');
        if ($hasSearch) {
            $allianceQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $publicPage = app(CareAlliancePublicPageService::class);
        $allianceRows = $allianceQuery->orderBy('name')->get()->map(function (CareAlliance $alliance) use ($publicPage, $organizationFilterId) {
            $recipient = $publicPage->donationRecipientOrganizationForAlliance($alliance);
            if ($recipient === null) {
                return null;
            }
            if ($organizationFilterId !== null && (int) $recipient->id !== $organizationFilterId) {
                return null;
            }

            $image = null;
            if ($alliance->creator?->image) {
                $img = (string) $alliance->creator->image;
                $image = str_starts_with($img, 'http') ? $img : asset('storage/'.ltrim($img, '/'));
            }

            return [
                'id' => 'ca-'.$alliance->id,
                'kind' => 'care_alliance',
                'organization_id' => $recipient->id,
                'name' => $alliance->name,
                'description' => $alliance->description
                    ? strip_tags((string) $alliance->description)
                    : 'Unity Impact Alliance — shared fundraising for member nonprofits.',
                'image' => $image,
                'raised' => (float) ($recipient->balance ?? 0),
                'goal' => 0,
                'supporters' => $recipient->donations()->distinct('user_id')->count('user_id'),
                'alliance_slug' => $alliance->slug,
                'care_alliance_id' => $alliance->id,
            ];
        })->filter()->values();

        $organizations = $orgRows->concat($allianceRows)->sortBy('name', SORT_NATURAL)->values();

        // Year-to-date giving and top 3 organizations for logged-in users
        $thisYearDonated = 0.0;
        $givingGoal = 1000; // default goal for progress bar
        $topOrganizations = [];
        if ($user) {
            $thisYearDonated = (float) Donation::where('user_id', $user->id)
                ->whereIn('status', ['completed', 'active'])
                ->whereYear('donation_date', now()->year)
                ->sum('amount');
            $topOrganizations = Donation::where('user_id', $user->id)
                ->whereIn('status', ['completed', 'active'])
                ->selectRaw('organization_id, SUM(amount) as total')
                ->groupBy('organization_id')
                ->orderByDesc('total')
                ->take(3)
                ->with('organization:id,name')
                ->get()
                ->map(function ($row) {
                    return [
                        'name' => $row->organization?->name ?? 'Unknown',
                        'total' => (float) $row->total,
                    ];
                })
                ->values()
                ->all();

            // Full cause rows for the org dropdown: organizations this user has donated to (with lifetime total).
            // Newest donation first (most recently active recipient).
            $donationAggregates = Donation::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'active'])
                ->selectRaw('organization_id')
                ->selectRaw('care_alliance_id')
                ->selectRaw('SUM(amount) as donated_total')
                ->selectRaw('MAX(donation_date) as last_donated_at')
                ->groupBy('organization_id', 'care_alliance_id')
                ->orderByDesc('last_donated_at')
                ->get();

            $donatedCauses = [];
            foreach ($donationAggregates as $agg) {
                $donatedTotal = (float) $agg->donated_total;
                if ($agg->care_alliance_id) {
                    $alliance = CareAlliance::query()
                        ->where('id', $agg->care_alliance_id)
                        ->with('creator:id,image')
                        ->first();
                    if ($alliance) {
                        $recipient = $publicPage->donationRecipientOrganizationForAlliance($alliance);
                        if ($recipient !== null) {
                            $image = null;
                            if ($alliance->creator?->image) {
                                $img = (string) $alliance->creator->image;
                                $image = str_starts_with($img, 'http') ? $img : asset('storage/'.ltrim($img, '/'));
                            }
                            $donatedCauses[] = [
                                'id' => 'ca-'.$alliance->id,
                                'kind' => 'care_alliance',
                                'organization_id' => $recipient->id,
                                'name' => $alliance->name,
                                'description' => $alliance->description
                                    ? strip_tags((string) $alliance->description)
                                    : 'Unity Impact Alliance — shared fundraising for member nonprofits.',
                                'image' => $image,
                                'raised' => (float) ($recipient->balance ?? 0),
                                'goal' => 0,
                                'supporters' => $recipient->donations()->distinct('user_id')->count('user_id'),
                                'alliance_slug' => $alliance->slug,
                                'care_alliance_id' => $alliance->id,
                                'donated_total' => $donatedTotal,
                            ];

                            continue;
                        }
                    }
                }
                $org = Organization::query()->find($agg->organization_id);
                if ($org) {
                    $donatedCauses[] = [
                        'id' => 'org-'.$org->id,
                        'kind' => 'organization',
                        'organization_id' => $org->id,
                        'name' => $org->name,
                        'description' => $org->description ?? $org->mission ?? 'No description available.',
                        'image' => $org->registered_user_image ? asset('storage/'.$org->registered_user_image) : null,
                        'raised' => (float) ($org->balance ?? 0),
                        'goal' => 0,
                        'supporters' => $org->donations()->distinct('user_id')->count('user_id'),
                        'donated_total' => $donatedTotal,
                        'has_ein' => (bool) $org->has_ein,
                        'ein_verified' => (bool) $org->has_ein,
                    ];
                }
            }
        } else {
            $donatedCauses = [];
        }

        if ($organizationFilterId !== null) {
            $donatedCauses = array_values(array_filter(
                $donatedCauses,
                fn (array $cause) => (int) ($cause['organization_id'] ?? 0) === $organizationFilterId
            ));
        }

        $feePreview = null;
        $feePreviewCheckoutTotalsByRail = null;
        if ($request->filled('fee_preview_amount')) {
            $validator = Validator::make($request->only(['fee_preview_amount', 'fee_preview_donor_covers', 'fee_preview_rail']), [
                'fee_preview_amount' => 'required|numeric|min:0.01',
                'fee_preview_donor_covers' => 'sometimes|boolean',
                'fee_preview_rail' => 'nullable|in:card,bank',
            ]);
            if (! $validator->fails()) {
                $base = round((float) $validator->validated()['fee_preview_amount'], 2);
                $donorCovers = $request->boolean('fee_preview_donor_covers');
                $rail = $request->input('fee_preview_rail', 'card');
                $rail = in_array($rail, ['card', 'bank'], true) ? $rail : 'card';
                $feePreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, $rail);
                $cardPreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, 'card');
                $bankPreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, 'bank');
                $feePreviewCheckoutTotalsByRail = [
                    'card' => $cardPreview['checkout_total_usd'],
                    'bank' => $bankPreview['checkout_total_usd'],
                ];
            }
        }

        $orgPaymentMethods = $this->resolveDonatePagePaymentMethods($request);

        return Inertia::render('frontend/donate', [
            'seo' => SeoService::forPage('donate'),
            'organizations' => $organizations->values(),
            'secondaryOrganizations' => $this->secondaryDonateCausesForUser($user),
            'message' => 'Please log in to view your donations.',
            'user' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'searchQuery' => $request->input('search', ''),
            'thisYearDonated' => $thisYearDonated,
            'givingGoal' => $givingGoal,
            'topOrganizations' => $topOrganizations,
            'donatedCauses' => $donatedCauses,
            'feePreview' => $feePreview,
            'feePreviewCheckoutTotalsByRail' => $feePreviewCheckoutTotalsByRail,
            'organizationFilterLock' => $primaryOrgService->listingFilterLockState($request, 'organization_id'),
            'primaryOrganizationLocked' => $user ? (bool) $user->primary_organization_locked : false,
            'rewardPointsAmount' => BelievePointsRewardService::donationBrpAmountForUser($user),
            'orgPaymentMethods' => $orgPaymentMethods,
            'defaultPaymentMethods' => [
                'stripe_card' => false,
                'stripe_ach' => false,
                'venmo' => false,
                'venmo_manual' => false,
                'cash_app_pay' => false,
                'paypal' => false,
                'cashapp' => false,
                'zelle' => false,
                'believe_points' => false,
            ],
        ]);
    }

    /**
     * JSON context for the organization profile donation popup:
     * enabled payment methods for the org plus an optional fee preview.
     * Mirrors the /donate page so the embedded modal stays in sync.
     */
    public function organizationDonationContext(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer',
            'fee_preview_amount' => 'nullable|numeric|min:0.01',
            'fee_preview_donor_covers' => 'sometimes|boolean',
            'fee_preview_rail' => 'nullable|in:card,bank',
        ]);

        $organization = Organization::query()
            ->where('id', (int) $validated['organization_id'])
            ->where('registration_status', 'approved')
            ->first();

        if (! $organization) {
            return response()->json([
                'methods' => null,
                'feePreview' => null,
                'feePreviewCheckoutTotalsByRail' => null,
            ], 404);
        }

        $methods = OrganizationPaymentMethodResolver::availableMethodsForOrganization($organization);

        $feePreview = null;
        $feePreviewCheckoutTotalsByRail = null;
        if ($request->filled('fee_preview_amount')) {
            $base = round((float) $validated['fee_preview_amount'], 2);
            $donorCovers = $request->boolean('fee_preview_donor_covers');
            $rail = $validated['fee_preview_rail'] ?? 'card';
            $rail = in_array($rail, ['card', 'bank'], true) ? $rail : 'card';
            $feePreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, $rail);
            $cardPreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, 'card');
            $bankPreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $donorCovers, 'bank');
            $feePreviewCheckoutTotalsByRail = [
                'card' => $cardPreview['checkout_total_usd'],
                'bank' => $bankPreview['checkout_total_usd'],
            ];
        }

        return response()->json([
            'methods' => $methods,
            'feePreview' => $feePreview,
            'feePreviewCheckoutTotalsByRail' => $feePreviewCheckoutTotalsByRail,
        ]);
    }

    /**
     * Payment methods available on /donate for the selected organization.
     *
     * @return array<string, bool>|null
     */
    private function resolveDonatePagePaymentMethods(Request $request): ?array
    {
        $primaryOrgService = app(SupporterPrimaryOrganizationService::class);
        $orgId = (int) ($primaryOrgService->resolveListingOrganizationFilterId($request, 'organization_id') ?? 0);
        if ($orgId <= 0) {
            return null;
        }

        $organization = Organization::query()
            ->where('id', $orgId)
            ->where('registration_status', 'approved')
            ->first();

        if (! $organization) {
            return null;
        }

        return OrganizationPaymentMethodResolver::availableMethodsForOrganization($organization);
    }

    /**
     * Supporter profile secondary orgs — always returned for instant donate-page toggle UX.
     *
     * @return list<array<string, mixed>>
     */
    private function secondaryDonateCausesForUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $primaryOrgService = app(SupporterPrimaryOrganizationService::class);
        $primaryId = (int) ($user->primary_organization_id ?? 0);
        $ids = collect($primaryOrgService->resolveSecondaryOrganizationIds($user))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && ($primaryId === 0 || $id !== $primaryId))
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', $ids)
            ->where('registration_status', 'approved')
            ->excludingCareAllianceHubs()
            ->orderBy('name')
            ->get()
            ->map(function (Organization $org) {
                return [
                    'id' => 'org-'.$org->id,
                    'kind' => 'organization',
                    'organization_id' => $org->id,
                    'name' => $org->name,
                    'description' => $org->description ?? $org->mission ?? 'No description available.',
                    'image' => $org->registered_user_image ? asset('storage/'.$org->registered_user_image) : null,
                    'raised' => (float) ($org->balance ?? 0),
                    'goal' => 0,
                    'supporters' => $org->donations()->distinct('user_id')->count('user_id'),
                    'has_ein' => (bool) $org->has_ein,
                    'ein_verified' => (bool) $org->has_ein,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Store a non-cash (in-kind) donation request.
     * Admin reviews and approves before it becomes official.
     */
    public function storeNonCash(Request $request)
    {
        $validated = $request->validate([
            'non_cash_type' => 'required|in:goods,services,stocks_crypto,vehicle,other',
            'item_name' => 'required|string|max:500',
            'estimated_fair_market_value' => 'required|numeric|min:0',
            'condition' => 'nullable|string|max:50',
            'organization_id' => 'required|exists:organizations,id',
            'upload_photos' => 'boolean',
        ]);

        $user = $request->user();
        $organization = Organization::findOrFail($validated['organization_id']);

        if ($organization->registration_status !== 'approved') {
            return redirect()->back()->withErrors([
                'organization_id' => 'The selected organization is not approved for donations.',
            ]);
        }

        // TODO: Create NonCashDonationRequest model and store when ready.
        // For now we acknowledge the request and redirect with success.
        Log::info('Non-cash donation request received', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'type' => $validated['non_cash_type'],
            'item_name' => $validated['item_name'],
            'estimated_value' => $validated['estimated_fair_market_value'],
        ]);

        return redirect()->back()->with('success', 'Your non-cash donation request has been submitted. We\'ll be in touch shortly.');
    }

    /**
     * Show the donation form
     */
    public function create(Request $request, Organization $organization)
    {
        return Inertia::render('Donations/Create', [
            'organization' => $organization,
            'stripeKey' => \App\Services\StripeConfigService::getPublishableKey() ?? config('cashier.key'),
            'user' => $request->user() ? [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ] : null,
        ]);
    }

    /**
     * Process the donation
     */
    public function store(Request $request)
    {
        // First, try to find organization by ID
        $organizationId = $request->input('organization_id');
        $organization = Organization::find($organizationId);

        // If not found, it might be an ExcelData ID - try to find by EIN
        if (! $organization) {
            $excelData = \App\Models\ExcelData::find($organizationId);
            if ($excelData) {
                // Find Organization by EIN
                $organization = Organization::where('ein', $excelData->ein)
                    ->where('registration_status', 'approved')
                    ->first();
            }
        }

        // Validate organization exists and is approved
        if (! $organization || $organization->registration_status !== 'approved') {
            return redirect()->back()->withErrors([
                'organization_id' => 'The selected organization is invalid or not approved for donations.',
            ]);
        }

        $requireSubscription = filter_var(env('REQUIRE_SUBSCRIPTION_FOR_DONATIONS', true), FILTER_VALIDATE_BOOLEAN);
        if ($requireSubscription) {
            $organization->loadMissing('user');
            $orgUser = $organization->user;
            $orgRecipientHasPlan = $orgUser && $this->recipientUserHasActiveSubscription($orgUser);

            $recipientKind = $request->input('recipient_kind', 'organization');
            $careAllianceId = $request->input('care_alliance_id');

            if ($recipientKind === 'care_alliance') {
                $creatorCovers = false;
                if ($careAllianceId !== null && $careAllianceId !== '') {
                    $creatorCovers = $this->careAllianceCreatorSubscriptionCoversDonation((int) $careAllianceId, $organization);
                }
                if (! $creatorCovers) {
                    $creatorCovers = $this->anyAllianceCreatorPlanCoversRecipientOrganization($organization);
                }

                if (! $orgRecipientHasPlan && ! $creatorCovers) {
                    return redirect()->back()->withErrors([
                        'subscription' => 'The nonprofit receiving donations for this Unity Impact Alliance does not have an active subscription. Donations are not available at this time.',
                    ])->with('subscription_required', true);
                }
            } elseif ($orgUser && ! $orgRecipientHasPlan) {
                $creatorCovers = $this->anyAllianceCreatorPlanCoversRecipientOrganization($organization);
                if (! $creatorCovers) {
                    return redirect()->back()->withErrors([
                        'subscription' => 'This organization does not have an active subscription. Donations are not available at this time.',
                    ])->with('subscription_required', true);
                }
            }
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'frequency' => 'required|in:one-time,weekly,monthly',
            'message' => 'nullable|string|max:500',
            'payment_method' => 'nullable|string|in:stripe,stripe_card,stripe_ach,paypal,cashapp,zelle,venmo,venmo_manual,cash_app_pay,believe_points',
            'donor_covers_processing_fees' => 'sometimes|boolean',
            'donation_fee_rail' => 'nullable|in:card,bank',
            'saved_payment_method_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $paymentMethod = $validated['payment_method'] ?? 'stripe';
        $feeRailEarly = $validated['donation_fee_rail'] ?? 'card';
        if ($paymentMethod === 'stripe') {
            $paymentMethod = $feeRailEarly === 'bank' ? 'stripe_ach' : 'stripe_card';
        }
        $baseGiftUsd = (float) $validated['amount'];
        $isStripeFamily = in_array($paymentMethod, ['stripe', 'stripe_card', 'stripe_ach', 'venmo', 'cash_app_pay'], true);
        $donorCoversFees = $isStripeFamily && $request->boolean('donor_covers_processing_fees');
        $feeRail = $validated['donation_fee_rail'] ?? 'card';
        $feeRail = in_array($feeRail, ['card', 'bank'], true) ? $feeRail : 'card';

        $checkoutTotalUsd = $baseGiftUsd;
        $processingFeeEstimate = null;
        if ($isStripeFamily) {
            if ($donorCoversFees) {
                if ($feeRail === 'bank') {
                    $checkoutTotalUsd = DonationProcessingFeeEstimator::grossUpAchChargeUsdForNetGiftUsd($baseGiftUsd);
                    $processingFeeEstimate = DonationProcessingFeeEstimator::feeAddonWhenDonorCoversAchUsd($baseGiftUsd);
                } else {
                    $checkoutTotalUsd = DonationProcessingFeeEstimator::grossUpCardChargeUsdForNetGiftUsd($baseGiftUsd);
                    $processingFeeEstimate = DonationProcessingFeeEstimator::feeAddonWhenDonorCoversUsd($baseGiftUsd);
                }
            } else {
                $checkoutTotalUsd = $baseGiftUsd;
                $processingFeeEstimate = $feeRail === 'bank'
                    ? DonationProcessingFeeEstimator::estimateAchFeeOnChargeUsd($baseGiftUsd)
                    : DonationProcessingFeeEstimator::estimateCardFeeOnChargeUsd($baseGiftUsd);
            }
        }

        if ($isStripeFamily && ! $donorCoversFees && StripeProcessingFeeEstimator::customerPaysProcessingFeeEnabled()) {
            $rail = $feeRail === 'bank' ? 'us_bank_account' : 'card';
            $amountInCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd($baseGiftUsd, $rail);
        } else {
            $amountInCents = (int) round($checkoutTotalUsd * 100);
        }
        $organizationName = $organization->name;
        // Supporters and organization users can donate; block admin
        if ($user->hasRole('admin')) {
            return redirect()->back()->with('warning', 'Please use a supporter or organization account to make a donation.');
        }

        // Prevent organization from donating to itself
        $donorOrg = $user->organization ?? null;
        if ($donorOrg && (int) $donorOrg->id === (int) $organization->id) {
            return redirect()->back()->with('warning', 'You cannot donate to your own organization.');
        }

        // Believe Points are not accepted for donations.
        if ($paymentMethod === 'believe_points') {
            return redirect()->back()->withErrors([
                'payment_method' => 'Donations cannot be paid with Believe Points. Please choose another payment method.',
            ]);
        }

        $allianceForCheckout = $this->resolveCareAllianceForCheckoutDisplay($request, $organization);
        if ($allianceForCheckout && ! $allianceForCheckout->financial_settings_completed_at) {
            return redirect()->back()->withErrors([
                'message' => 'This alliance must complete Financial Settings under Settings → Profile before receiving donations.',
            ]);
        }

        StripeConnectOrganizationService::syncAccountStatusFromStripe($organization);
        $organization->refresh();

        $useStripeConnectCheckout = $isStripeFamily && DonationStripeConnectRouting::shouldRouteThroughStripeConnect(
            $organization,
            $allianceForCheckout,
            $validated['frequency'],
            'stripe_card'
        );

        $eligiblePlainOrgConnect = $isStripeFamily
            && $validated['frequency'] === 'one-time'
            && ! ($allianceForCheckout !== null && $allianceForCheckout->financial_settings_completed_at);

        if (DonationStripeConnectRouting::requireConnectPerConfig() && $eligiblePlainOrgConnect
            && ! StripeConnectOrganizationService::organizationCanAcceptDirectDonations($organization)) {
            return redirect()->back()->withErrors([
                'message' => 'This nonprofit must connect Stripe payouts first: Dashboard → Integrations → Stripe payouts (donations).',
            ]);
        }

        // Platform fee (BIU's cut on Stripe Connect destination charges) — set only when the
        // donation is actually routing through Connect. Mirrors processing_fee_estimate so BIU
        // collects the processing-fee portion and the organization receives the gift amount.
        $applicationFeeInCents = 0;
        if ($useStripeConnectCheckout && $processingFeeEstimate !== null && $processingFeeEstimate > 0) {
            $applicationFeeInCents = (int) round(((float) $processingFeeEstimate) * 100);
            // Stripe requires the application fee to be strictly less than the charge.
            $applicationFeeInCents = max(0, min($applicationFeeInCents, max(0, $amountInCents - 1)));
        }

        $donation = Donation::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'stripe_connect_account_id' => $useStripeConnectCheckout ? $organization->stripe_connect_account_id : null,
            'stripe_application_fee_amount' => $useStripeConnectCheckout && $applicationFeeInCents > 0
                ? round($applicationFeeInCents / 100, 2)
                : null,
            'care_alliance_id' => $allianceForCheckout?->id,
            'amount' => $baseGiftUsd,
            'donor_covers_processing_fees' => $isStripeFamily && $donorCoversFees,
            'processing_fee_estimate' => $processingFeeEstimate,
            'checkout_total' => $isStripeFamily ? round($checkoutTotalUsd, 2) : round($baseGiftUsd, 2),
            'frequency' => $validated['frequency'],
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'transaction_id' => rand(100000, 999999), // Temporary transaction ID
            'donation_date' => now(),
            'message' => $validated['message'] ?? null,
        ]);

        $isOneTime = $validated['frequency'] === 'one-time';

        if ($isOneTime && ! in_array($paymentMethod, ['stripe'], true)
            && ! ($isStripeFamily && filled($validated['saved_payment_method_id'] ?? null))) {
            try {
                OrganizationPaymentMethodResolver::assertMethodAllowed($organization, $paymentMethod);

                return app(PaymentOrchestratorService::class)->initiateDonation(
                    $user,
                    $organization,
                    $donation,
                    $paymentMethod,
                    [
                        'donor_covers_processing_fees' => $donorCoversFees,
                        'donation_fee_rail' => $feeRail,
                        'alliance' => $allianceForCheckout,
                        'stripe_metadata' => [
                            'donation_id' => (string) $donation->id,
                            'organization_id' => (string) $organization->id,
                            'organization_name' => mb_substr((string) $organizationName, 0, 500),
                            'donor_user_id' => (string) $user->id,
                            'donor_name' => mb_substr((string) ($user->name ?? ''), 0, 500),
                            'donor_email' => mb_substr((string) ($user->email ?? ''), 0, 500),
                            'base_gift_amount' => (string) $baseGiftUsd,
                            'donor_covers_processing_fees' => $donorCoversFees ? '1' : '0',
                            'fee_rail' => $feeRail,
                            'frequency' => (string) $validated['frequency'],
                            'care_alliance_id' => $allianceForCheckout ? (string) $allianceForCheckout->id : null,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                $donation->update(['status' => 'failed']);
                Log::error('Donation payment orchestration failed', [
                    'donation_id' => $donation->id,
                    'payment_method' => $paymentMethod,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->back()->withErrors([
                    'payment_method' => $e->getMessage() ?: 'Payment could not be started. Please try again.',
                ]);
            }
        }

        if (! $isOneTime && ! $isStripeFamily) {
            $donation->update(['status' => 'failed']);

            return redirect()->back()->withErrors([
                'payment_method' => 'Recurring donations are only available via card or bank (Stripe).',
            ]);
        }

        // Handle Stripe payment (one-time legacy stripe_card/ach or recurring)
        try {
            // Stripe line item: alliance donations show only the Unity Impact Alliance name (no member list or payout copy).
            $checkoutLineTitle = $allianceForCheckout !== null
                ? sprintf('Donation to %s', $allianceForCheckout->name)
                : "Donation to {$organizationName}";

            // Rich descriptions for the Stripe Dashboard (Description column on Payments) and the
            // donor's bank statement. Same builder used by Connect & non-Connect paths so admin
            // sees consistent labels regardless of routing.
            $isOneTime = $validated['frequency'] === 'one-time';
            $piDescription = DonationStripeDescriptionBuilder::forPaymentIntent(
                $organization,
                $allianceForCheckout,
                $user,
                $baseGiftUsd,
                $paymentMethod === 'stripe' ? $checkoutTotalUsd : null,
                $donorCoversFees,
                $processingFeeEstimate !== null ? (float) $processingFeeEstimate : null
            );
            $subscriptionDescription = DonationStripeDescriptionBuilder::forSubscription(
                $organization,
                $allianceForCheckout,
                $user,
                $baseGiftUsd,
                $validated['frequency']
            );
            $statementSuffix = DonationStripeDescriptionBuilder::forStatementDescriptorSuffix(
                $organization,
                $allianceForCheckout
            );

            $metadata = [
                'donation_id' => (string) $donation->id,
                'organization_id' => (string) $organization->id,
                'organization_name' => mb_substr((string) $organizationName, 0, 500),
                'donor_user_id' => (string) $user->id,
                'donor_name' => mb_substr((string) ($user->name ?? ''), 0, 500),
                'donor_email' => mb_substr((string) ($user->email ?? ''), 0, 500),
                'base_gift_amount' => (string) $baseGiftUsd,
                'donor_covers_processing_fees' => $donorCoversFees ? '1' : '0',
                'fee_rail' => $feeRail,
                'frequency' => (string) $validated['frequency'],
            ];
            if ($allianceForCheckout !== null) {
                $metadata['care_alliance_id'] = (string) $allianceForCheckout->id;
                $metadata['care_alliance_name'] = mb_substr((string) $allianceForCheckout->name, 0, 500);
            }

            $paymentMethodTypes = $feeRail === 'bank' ? ['us_bank_account'] : ['card'];

            if ($isOneTime && filled($validated['saved_payment_method_id'] ?? null)) {
                return $this->donateWithSavedPaymentMethod(
                    $user,
                    $donation,
                    $organization,
                    (string) $validated['saved_payment_method_id'],
                    $feeRail,
                    $amountInCents,
                    $applicationFeeInCents,
                    $useStripeConnectCheckout,
                    $metadata,
                    $piDescription,
                    $statementSuffix
                );
            }

            if ($useStripeConnectCheckout) {
                $successUrl = route('donations.success').'?donation_id='.$donation->id.'&session_id={CHECKOUT_SESSION_ID}';
                $cancelUrl = route('donations.cancel').'?donation_id='.$donation->id.'&session_id={CHECKOUT_SESSION_ID}';

                $checkoutSession = DonationStripeConnectCheckoutSessionService::create(
                    $user,
                    $organization,
                    $donation,
                    $amountInCents,
                    $applicationFeeInCents,
                    mb_substr($checkoutLineTitle, 0, 250),
                    $metadata,
                    $paymentMethodTypes,
                    $successUrl,
                    $cancelUrl,
                    $piDescription,
                    $statementSuffix
                );
                $donation->update(['stripe_checkout_session_id' => $checkoutSession->id]);

                return Inertia::location($checkoutSession->url);
            }

            if (! StripeEnvironmentSyncService::ensureUserStripeCustomer($user)) {
                $donation->update(['status' => 'failed']);
                Log::error('Donation Stripe checkout failed', [
                    'user_id' => $user->id,
                    'error' => 'Could not prepare Stripe customer for current account',
                ]);

                return redirect()->back()->withErrors([
                    'message' => 'Payment setup failed. Please try again in a moment.',
                ]);
            }
            $user->refresh();

            $checkoutOptions = [
                'success_url' => route('donations.success').'?donation_id='.$donation->id.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('donations.cancel').'?donation_id='.$donation->id.'&session_id={CHECKOUT_SESSION_ID}',
                'metadata' => $metadata,
                'payment_method_types' => $paymentMethodTypes,
                'billing_address_collection' => 'auto',
            ];

            if ($isOneTime) {
                $checkoutOptions['payment_intent_data'] = [
                    'description' => mb_substr($piDescription, 0, 1000),
                    'statement_descriptor_suffix' => mb_substr($statementSuffix, 0, 22),
                    'metadata' => $metadata,
                ];
            } else {
                $checkoutOptions['subscription_data'] = [
                    'description' => mb_substr($subscriptionDescription, 0, 500),
                    'metadata' => $metadata,
                ];
            }

            if ($isOneTime) {
                $checkout = $user->checkoutCharge(
                    $amountInCents,
                    mb_substr($checkoutLineTitle, 0, 250),
                    1,
                    $checkoutOptions,
                    [],
                    []
                );
            } else {
                // Recurring donation (product name comes from Stripe product; description on subscription)
                $priceId = $this->createDynamicStripePrice($amountInCents, $validated['frequency']);

                $checkout = $user->newSubscription('donation', $priceId)
                    ->allowPromotionCodes()
                    ->checkout($checkoutOptions);
            }

            return Inertia::location($checkout->url);
        } catch (\Exception $e) {
            $donation->update(['status' => 'failed']);
            Log::error('Donation Stripe checkout failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'message' => 'Payment processing failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Handle successful payment
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');
        $donationId = $request->get('donation_id');

        // Believe Points and saved Stripe payment methods complete without a Checkout session.
        if (! $sessionId) {
            if ($donationId) {
                $donation = Donation::with(['organization', 'user', 'careAlliance:id,name,slug'])->find($donationId);

                if ($donation) {
                    $resolved = $this->resolveDonationSuccessWithoutCheckoutSession($donation);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }

            return redirect()->route('donate')->withErrors([
                'message' => 'Invalid donation session',
            ]);
        }

        try {
            // Connect donations now use destination charges, so the Checkout Session lives on the
            // platform Stripe account — NOT on the connected account. Retrieve from the platform.
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
            $metaDonationId = $session->metadata->donation_id ?? null;
            if ($metaDonationId === null || $metaDonationId === '') {
                throw new \RuntimeException('Checkout session is missing donation metadata.');
            }

            $donation = Donation::with(['organization', 'user', 'careAlliance:id,name,slug'])->findOrFail($metaDonationId);

            $user = $donation->user;

            // Check payment status from Stripe session
            if ($session->payment_status === 'paid') {
                if ($session->payment_intent) {
                    $piRef = $session->payment_intent;
                    $paymentIntentId = is_string($piRef) ? $piRef : $piRef->id;
                    // One-time: only settle wallets once (success URL can be reloaded).
                    $alreadySettled = $donation->status === 'completed'
                        && (string) $donation->transaction_id === (string) $paymentIntentId;
                    if (! $alreadySettled) {
                        // Both classic platform charges AND Connect destination charges produce a
                        // PaymentIntent on the platform account, so no stripe_account header needed.
                        DonationStripePaymentCompletion::completeSuccessfulOneTime(
                            $donation,
                            (string) $paymentIntentId,
                            $this->stripeCheckoutPaymentMethodLabel($session)
                        );
                    }
                } elseif ($session->subscription) {
                    // Recurring payment - store subscription using Laravel Cashier
                    try {
                        // Ensure user has stripe_id (required for Cashier)
                        if (! $user->stripe_id && $session->customer) {
                            $user->stripe_id = $session->customer;
                            $user->save();
                        }

                        // Use Cashier's subscription relationship to find or create
                        $subscription = $user->subscriptions()->firstOrNew([
                            'stripe_id' => $session->subscription,
                        ]);

                        // If subscription doesn't exist, retrieve from Stripe and sync using Cashier
                        if (! $subscription->exists) {
                            $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($session->subscription);

                            // Get the price ID from the subscription
                            $priceId = $stripeSubscription->items->data[0]->price->id ?? null;

                            // Use Cashier's subscription model properties
                            $subscription->type = 'donation';
                            $subscription->stripe_id = $stripeSubscription->id;
                            $subscription->stripe_status = $stripeSubscription->status;
                            $subscription->stripe_price = $priceId;
                            $subscription->quantity = $stripeSubscription->items->data[0]->quantity ?? 1;
                            $subscription->trial_ends_at = $stripeSubscription->trial_end ?
                                \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null;
                            $subscription->ends_at = $stripeSubscription->cancel_at ?
                                \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) : null;

                            $subscription->save();

                            Log::info('Donation subscription stored using Cashier', [
                                'user_id' => $user->id,
                                'subscription_id' => $subscription->id,
                                'stripe_subscription_id' => $session->subscription,
                                'donation_id' => $donation->id,
                            ]);
                        } else {
                            // Update existing subscription using Cashier's subscription model
                            $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($session->subscription);
                            $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
                            $subscription->stripe_status = $stripeSubscription->status;
                            $subscription->stripe_price = $priceId;
                            $subscription->quantity = $stripeSubscription->items->data[0]->quantity ?? 1;
                            $subscription->trial_ends_at = $stripeSubscription->trial_end ?
                                \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null;
                            $subscription->ends_at = $stripeSubscription->cancel_at ?
                                \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) : null;
                            $subscription->save();

                            Log::info('Donation subscription updated using Cashier', [
                                'user_id' => $user->id,
                                'subscription_id' => $subscription->id,
                                'stripe_subscription_id' => $session->subscription,
                                'donation_id' => $donation->id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to store donation subscription using Cashier', [
                            'error' => $e->getMessage(),
                            'user_id' => $user->id,
                            'session_id' => $sessionId,
                            'stripe_subscription_id' => $session->subscription ?? null,
                            'donation_id' => $donation->id,
                        ]);
                        // Continue even if subscription storage fails - webhook will handle it
                    }

                    // Recurring: first checkout only — same guard as one-time (page refresh).
                    $alreadySettled = in_array($donation->status, ['active', 'completed'], true)
                        && (string) $donation->transaction_id === (string) $session->subscription;
                    if (! $alreadySettled) {
                        DB::transaction(function () use ($donation, $session) {
                            $donation->update([
                                'transaction_id' => $session->subscription,
                                'payment_method' => $this->stripeCheckoutPaymentMethodLabel($session),
                                'status' => 'active',
                                'donation_date' => now(),
                            ]);
                            $this->applyDonationToBalances($donation->fresh());
                        });
                    }

                    // Status is "active" for recurring checkout — awardDonationPoints only runs for "completed".
                    if (! $alreadySettled) {
                        $this->impactScoreService->awardDonationPoints($donation->fresh());
                        BelievePointsRewardService::issueDonationReward($donation->fresh());
                    }
                } else {
                    // Payment is paid but no payment_intent or subscription found
                    $alreadySettled = $donation->status === 'completed'
                        && (string) $donation->transaction_id === (string) $session->id;
                    if (! $alreadySettled) {
                        DB::transaction(function () use ($donation, $session) {
                            $donation->update([
                                'transaction_id' => $session->id,
                                'payment_method' => $this->stripeCheckoutPaymentMethodLabel($session),
                                'status' => 'completed',
                                'donation_date' => now(),
                            ]);
                            $this->applyDonationToBalances($donation->fresh());
                        });

                        $this->impactScoreService->awardDonationPoints($donation->fresh());
                        BelievePointsRewardService::issueDonationReward($donation->fresh());
                    }
                }
            } else {
                // Payment not completed yet
                Log::warning('Donation success page accessed but payment not completed', [
                    'donation_id' => $donation->id,
                    'session_id' => $sessionId,
                    'payment_status' => $session->payment_status,
                ]);
            }

            // Refresh donation to get updated status (re-load display relations)
            $donation->refresh();
            $donation->load(['organization', 'user', 'careAlliance:id,name,slug']);

            return Inertia::render('frontend/organization/donation/success', $this->donationSuccessProps($donation, 'stripe'));
        } catch (\Exception $e) {
            return Inertia::render('frontend/organization/donation/success')->withErrors([
                'message' => 'Error verifying payment: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Handle canceled payment
     */
    public function cancel(Request $request)
    {
        $sessionId = $request->get('session_id');

        if ($sessionId) {
            try {
                // Destination-charge sessions live on the platform account; no stripe_account needed.
                $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
                if ($session->metadata->donation_id) {
                    Donation::where('id', $session->metadata->donation_id)
                        ->update(['status' => 'canceled']);
                }
            } catch (\Exception $e) {
                // Log error but continue to cancel page
                Log::error('Error updating canceled donation: '.$e->getMessage());
            }
        }

        return Inertia::render('frontend/organization/donation/cancel');
    }

    /**
     * Create dynamic Stripe price for recurring donations
     */
    protected function createDynamicStripePrice(int $amountInCents, string $frequency): string
    {
        // Map your app frequencies to Stripe-recognized intervals
        $intervalMap = [
            'weekly' => 'week',
            'monthly' => 'month',
        ];

        if (! array_key_exists($frequency, $intervalMap)) {
            throw new \InvalidArgumentException("Invalid recurring frequency: {$frequency}");
        }

        $interval = $intervalMap[$frequency];

        // Get donation product ID dynamically based on current environment
        $productId = StripeConfigService::getDonationProductId();

        if (! $productId) {
            throw new \Exception('Failed to get or create donation product. Please check your Stripe configuration.');
        }

        // Create dynamic price with Stripe
        $price = Cashier::stripe()->prices->create([
            'unit_amount' => $amountInCents,
            'currency' => 'usd',
            'recurring' => ['interval' => $interval],
            'product' => $productId,
        ]);

        return $price->id;
    }

    /**
     * Resolve Stripe payment method type (card, us_bank_account, …) from a completed Checkout Session.
     */
    private function stripeCheckoutPaymentMethodLabel(object $session, ?string $stripeConnectAccountId = null): string
    {
        try {
            $acctOpts = [];
            if ($stripeConnectAccountId !== null && $stripeConnectAccountId !== '') {
                $acctOpts['stripe_account'] = $stripeConnectAccountId;
            }

            if (! empty($session->payment_intent)) {
                $piId = is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent->id;
                $pi = Cashier::stripe()->paymentIntents->retrieve($piId, ['expand' => ['payment_method']], $acctOpts);
                if ($pi->payment_method && is_object($pi->payment_method) && isset($pi->payment_method->type)) {
                    return (string) $pi->payment_method->type;
                }
            }
            if (! empty($session->subscription)) {
                $subId = is_string($session->subscription) ? $session->subscription : $session->subscription->id;
                $sub = Cashier::stripe()->subscriptions->retrieve($subId, ['expand' => ['default_payment_method']], $acctOpts);
                if ($sub->default_payment_method && is_object($sub->default_payment_method) && isset($sub->default_payment_method->type)) {
                    return (string) $sub->default_payment_method->type;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('stripeCheckoutPaymentMethodLabel failed', ['error' => $e->getMessage()]);
        }

        if (is_array($session->payment_method_types ?? null) && $session->payment_method_types !== []) {
            return (string) $session->payment_method_types[0];
        }

        return 'card';
    }

    private function applyDonationToBalances(Donation $donation): void
    {
        DonationStripePaymentCompletion::applyDonationLedgerAndBalances($donation);
    }

    /**
     * Success page for donations that never created a Stripe Checkout session.
     */
    private function resolveDonationSuccessWithoutCheckoutSession(Donation $donation): RedirectResponse|InertiaResponse|null
    {
        if ($donation->payment_method === 'believe_points') {
            if ($donation->status === 'completed') {
                return Inertia::render('frontend/organization/donation/success', $this->donationSuccessProps($donation, 'believe_points'));
            }

            return redirect()->route('donate')->withErrors([
                'message' => 'Donation is still processing. Please wait a moment.',
            ]);
        }

        $transactionId = (string) $donation->transaction_id;
        $isPaymentIntent = str_starts_with($transactionId, 'pi_');
        $isStripeRail = in_array($donation->payment_method, ['stripe', 'card', 'us_bank_account'], true);

        if (! $isPaymentIntent && ! $isStripeRail) {
            return null;
        }

        if ($donation->status === 'completed' && $isStripeRail) {
            return Inertia::render('frontend/organization/donation/success', $this->donationSuccessProps($donation, 'stripe'));
        }

        if (! $isPaymentIntent) {
            return null;
        }

        try {
            $intent = Cashier::stripe()->paymentIntents->retrieve($transactionId);

            if ($intent->status === 'succeeded') {
                $feeRail = (string) ($intent->metadata->fee_rail ?? 'card');
                $paymentLabel = $feeRail === 'bank' ? 'us_bank_account' : 'card';
                DonationStripePaymentCompletion::completeSuccessfulOneTime($donation, $intent->id, $paymentLabel);
                $donation->refresh();
                $donation->load(['organization', 'user', 'careAlliance:id,name,slug']);

                return Inertia::render('frontend/organization/donation/success', $this->donationSuccessProps($donation, 'stripe'));
            }

            if (in_array($intent->status, ['processing', 'requires_confirmation'], true)) {
                return redirect()->route('donate')
                    ->with('info', 'Your payment is still processing. Your donation will be recorded once it completes.');
            }
        } catch (\Throwable $e) {
            Log::warning('Donation success without Checkout session: PaymentIntent verify failed', [
                'donation_id' => $donation->id,
                'transaction_id' => $transactionId,
                'payment_method' => $donation->payment_method,
                'status' => $donation->status,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Charge a saved Stripe card or bank account for a one-time donation.
     */
    private function donateWithSavedPaymentMethod(
        User $user,
        Donation $donation,
        Organization $organization,
        string $paymentMethodId,
        string $feeRail,
        int $amountInCents,
        int $applicationFeeInCents,
        bool $useStripeConnectCheckout,
        array $metadata,
        string $piDescription,
        ?string $statementDescriptorSuffix
    ): RedirectResponse|Response {
        $railFromPm = UserStripePaymentMethodService::railForPaymentMethod($paymentMethodId);

        if ($railFromPm !== $feeRail) {
            $donation->update(['status' => 'failed']);

            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'message' => 'The selected saved payment method does not match this payment type.',
                ]);
        }

        if (! UserStripePaymentMethodService::paymentMethodBelongsToUser($user, $paymentMethodId)) {
            $donation->update(['status' => 'failed']);

            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'message' => 'Invalid saved payment method.',
                ]);
        }

        try {
            if (! StripeEnvironmentSyncService::ensureUserStripeCustomer($user)) {
                $donation->update(['status' => 'failed']);

                return redirect()->back()
                    ->withInput()
                    ->withErrors([
                        'message' => 'Could not prepare your billing profile. Please try again.',
                    ]);
            }
            $user->refresh();

            BelievePointsPaymentMethodSyncService::ensurePaymentMethodBelongsToCustomer($user, $paymentMethodId);

            $intentPayload = [
                'amount' => $amountInCents,
                'currency' => config('cashier.currency'),
                'customer' => $user->stripe_id,
                'payment_method' => $paymentMethodId,
                'confirm' => true,
                'off_session' => false,
                'return_url' => route('donations.complete-saved-payment', ['donation' => $donation->id]),
                'description' => mb_substr($piDescription, 0, 1000),
                'metadata' => $metadata,
            ];

            if ($useStripeConnectCheckout && $organization->stripe_connect_account_id) {
                $applicationFeeInCents = max(0, min($applicationFeeInCents, $amountInCents - 1));
                $intentPayload['transfer_data'] = [
                    'destination' => $organization->stripe_connect_account_id,
                ];
                $intentPayload['on_behalf_of'] = $organization->stripe_connect_account_id;
                if ($applicationFeeInCents > 0) {
                    $intentPayload['application_fee_amount'] = $applicationFeeInCents;
                }
                if ($statementDescriptorSuffix !== null && trim($statementDescriptorSuffix) !== '') {
                    $intentPayload['statement_descriptor_suffix'] = mb_substr(trim($statementDescriptorSuffix), 0, 22);
                }
            }

            $intent = Cashier::stripe()->paymentIntents->create($intentPayload);

            $donation->update([
                'transaction_id' => $intent->id,
            ]);

            $paymentLabel = $feeRail === 'bank' ? 'us_bank_account' : 'card';

            if ($intent->status === 'succeeded') {
                DonationStripePaymentCompletion::completeSuccessfulOneTime($donation->fresh(), $intent->id, $paymentLabel);

                return redirect(route('donations.success').'?donation_id='.$donation->id)
                    ->with('success', 'Donation completed successfully!');
            }

            if ($intent->status === 'requires_action') {
                return Inertia::location(route('cashier.payment', $intent->id));
            }

            if (in_array($intent->status, ['processing', 'requires_confirmation'], true)) {
                return redirect()->route('donate')
                    ->with('info', 'Your bank payment is processing. Your donation will be recorded once it completes.');
            }

            $donation->update(['status' => 'failed']);

            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'message' => 'Payment could not be completed. Please try another method.',
                ]);
        } catch (ApiErrorException $e) {
            $donation->update(['status' => 'failed']);
            Log::error('Donation saved payment method Stripe error', [
                'donation_id' => $donation->id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'message' => 'Payment failed. Please try again or use a different payment method.',
                ]);
        } catch (\Throwable $e) {
            $donation->update(['status' => 'failed']);
            Log::error('Donation saved payment method error', [
                'donation_id' => $donation->id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'message' => 'Payment failed. Please try again.',
                ]);
        }
    }

    public function completeSavedPayment(Request $request, int $donation): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $donationModel = Donation::query()
            ->where('id', $donation)
            ->where('user_id', $user->id)
            ->first();

        if (! $donationModel || ! is_string($donationModel->transaction_id) || ! str_starts_with($donationModel->transaction_id, 'pi_')) {
            return redirect()->route('donate')->withErrors([
                'message' => 'Could not find this donation payment.',
            ]);
        }

        try {
            $intent = Cashier::stripe()->paymentIntents->retrieve($donationModel->transaction_id);
            $feeRail = (string) ($intent->metadata->fee_rail ?? 'card');
            $paymentLabel = $feeRail === 'bank' ? 'us_bank_account' : 'card';

            if ($intent->status === 'succeeded') {
                DonationStripePaymentCompletion::completeSuccessfulOneTime($donationModel, $intent->id, $paymentLabel);

                return redirect(route('donations.success').'?donation_id='.$donationModel->id)
                    ->with('success', 'Donation completed successfully!');
            }

            if (in_array($intent->status, ['processing', 'requires_confirmation'], true)) {
                return redirect()->route('donate')
                    ->with('info', 'Your payment is still processing. Your donation will be recorded once it completes.');
            }

            return redirect()->route('donate')->withErrors([
                'message' => 'Payment was not completed.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Donation complete saved payment error', [
                'donation_id' => $donation,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('donate')->withErrors([
                'message' => 'Could not confirm payment status.',
            ]);
        }
    }

    /**
     * Display donations for the organization
     */
    public function organizationIndex(Request $request)
    {
        $organization = Organization::forAuthUser($request->user());

        if (! $organization) {
            abort(404, 'Organization not found');
        }

        $status = $request->input('status', 'all');
        $allowedStatuses = ['all', 'pending', 'completed', 'active', 'rejected', 'canceled', 'failed'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $baseQuery = Donation::query()->where('organization_id', $organization->id);

        $stats = [
            'total_amount' => (float) (clone $baseQuery)
                ->whereIn('status', ['completed', 'active'])
                ->sum('amount'),
            'total_count' => (clone $baseQuery)->count(),
            'completed_count' => (clone $baseQuery)->where('status', 'completed')->count(),
            'active_recurring_count' => (clone $baseQuery)
                ->where('status', 'active')
                ->where('frequency', '!=', 'one-time')
                ->count(),
            'pending_count' => (clone $baseQuery)->where('status', 'pending')->count(),
            'rejected_count' => (clone $baseQuery)->where('status', 'rejected')->count(),
        ];

        $donationsQuery = (clone $baseQuery)->with(['user:id,name,email']);

        if ($status !== 'all') {
            $donationsQuery->where('status', $status);
        }

        $donations = $donationsQuery
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Donation $donation) => [
                'id' => $donation->id,
                'user_id' => $donation->user_id,
                'organization_id' => $donation->organization_id,
                'amount' => (float) $donation->amount,
                'frequency' => $donation->frequency,
                'payment_method' => $donation->payment_method,
                'transaction_id' => $donation->transaction_id,
                'status' => $donation->status,
                'messages' => $donation->messages ?? $donation->message,
                'created_at' => $donation->created_at?->toDateTimeString(),
                'user' => $donation->user ? [
                    'id' => $donation->user->id,
                    'name' => $donation->user->name,
                    'email' => $donation->user->email,
                ] : null,
            ]);

        return Inertia::render('Donations/Index', [
            'donations' => $donations,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'stats' => $stats,
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function donationSuccessProps(Donation $donation, string $paymentMethod): array
    {
        $donation->loadMissing(['organization', 'user', 'careAlliance:id,name,slug']);

        return [
            'donation' => $donation,
            'paymentMethod' => $paymentMethod,
            'brpEarned' => BelievePointsRewardService::donationBrpAmountForUser($donation->user),
        ];
    }
}
