<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\CareAlliance;
use App\Models\CareAllianceCampaign;
use App\Models\CareAllianceDonation;
use App\Models\CareAllianceJoinRequest;
use App\Models\CareAllianceMembership;
use App\Models\ComplianceApplication;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Form1023Application;
use App\Models\FractionalOrder;
use App\Models\GiftCard;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\Organization;
use App\Models\PromotionalBanner;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\OrganizationProfileCompletionService;
use App\Services\PhazeBalanceService;
use App\Services\TaxComplianceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __construct(
        private TaxComplianceService $taxComplianceService,
        private PhazeBalanceService $phazeBalanceService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        if ($isAdmin) {
            // Admin Dashboard Statistics
            $stats = [
                'totalOrganizations' => Organization::count(),
                'approvedOrganizations' => Organization::where('registration_status', 'approved')->excludingCareAllianceHubs()->count(),
                'pendingOrganizations' => Organization::where('registration_status', 'pending')->count(),
                'totalUsers' => User::count(),
                'totalForm1023Applications' => Form1023Application::count(),
                'pendingForm1023Applications' => Form1023Application::whereIn('status', ['pending_payment', 'awaiting_review', 'needs_more_info'])->count(),
                'approvedForm1023Applications' => Form1023Application::where('status', 'approved')->count(),
                'totalComplianceApplications' => ComplianceApplication::count(),
                'pendingComplianceApplications' => ComplianceApplication::whereIn('status', ['pending', 'under_review'])->count(),
                'totalRoles' => Role::count(),
                'totalPermissions' => Permission::count(),
            ];

            // Recent Form 1023 Applications
            $recentForm1023Applications = Form1023Application::with(['organization.user'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($app) {
                    return [
                        'id' => $app->id,
                        'application_number' => $app->application_number,
                        'status' => $app->status,
                        'payment_status' => $app->payment_status,
                        'organization_name' => $app->organization->name ?? 'N/A',
                        'submitted_at' => $app->submitted_at?->toIso8601String(),
                        'amount' => $app->amount,
                    ];
                });

            // Recent Organizations — approved + registered (linked user) only; no pending
            $recentOrganizations = Organization::query()
                ->where('registration_status', 'approved')
                ->whereNotNull('user_id')
                ->excludingCareAllianceHubs()
                ->with('user')
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($org) {
                    return [
                        'id' => $org->id,
                        'name' => $org->name,
                        'created_at' => $org->created_at->toIso8601String(),
                    ];
                });

            // Payment Statistics - Include Fractional Ownership revenue and Gift Card commissions
            $form1023Revenue = Form1023Application::where('payment_status', 'paid')->sum('amount');
            $complianceRevenue = ComplianceApplication::where('payment_status', 'paid')->sum('amount');
            $fractionalRevenue = FractionalOrder::where('status', 'paid')->sum('amount');
            $giftCardPlatformCommission = GiftCard::whereNotNull('purchased_at')
                ->whereNotNull('platform_commission')
                ->sum('platform_commission');

            $paymentStats = [
                'totalRevenue' => $form1023Revenue + $complianceRevenue + $fractionalRevenue + $giftCardPlatformCommission,
                'pendingPayments' => Form1023Application::where('payment_status', 'pending')->sum('amount') +
                                    ComplianceApplication::where('payment_status', 'pending')->sum('amount'),
                'paidApplications' => Form1023Application::where('payment_status', 'paid')->count() +
                                     ComplianceApplication::where('payment_status', 'paid')->count(),
                'giftCardPlatformCommission' => $giftCardPlatformCommission,
            ];

            // Monthly Revenue Data (Last 6 months)
            $monthlyRevenue = [];
            for ($i = 5; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $startOfMonth = $date->copy()->startOfMonth();
                $endOfMonth = $date->copy()->endOfMonth();

                $monthForm1023 = Form1023Application::where('payment_status', 'paid')
                    ->whereBetween('submitted_at', [$startOfMonth, $endOfMonth])
                    ->sum('amount');

                $monthCompliance = ComplianceApplication::where('payment_status', 'paid')
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->sum('amount');

                $monthFractional = FractionalOrder::where('status', 'paid')
                    ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                    ->sum('amount');

                $monthGiftCardCommission = GiftCard::whereNotNull('purchased_at')
                    ->whereNotNull('platform_commission')
                    ->whereBetween('purchased_at', [$startOfMonth, $endOfMonth])
                    ->sum('platform_commission');

                $monthlyRevenue[] = [
                    'month' => $date->format('M Y'),
                    'monthShort' => $date->format('M'),
                    'revenue' => $monthForm1023 + $monthCompliance + $monthFractional + $monthGiftCardCommission,
                ];
            }

            // Recent Transactions - Combine Form1023, Compliance, and Fractional Orders
            $recentTransactions = collect();

            // Form 1023 Applications
            $form1023Transactions = Form1023Application::with(['organization.user'])
                ->where('payment_status', 'paid')
                ->latest('submitted_at')
                ->limit(10)
                ->get()
                ->map(function ($app) {
                    return [
                        'id' => 'form1023_'.$app->id,
                        'type' => 'Form 1023',
                        'description' => $app->application_number.' - '.($app->organization->name ?? 'N/A'),
                        'amount' => $app->amount,
                        'status' => 'completed',
                        'user_name' => $app->organization->user->name ?? 'N/A',
                        'date' => $app->submitted_at?->toIso8601String(),
                        'currency' => 'USD',
                    ];
                });

            // Compliance Applications
            $complianceTransactions = ComplianceApplication::with(['organization.user'])
                ->where('payment_status', 'paid')
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(function ($app) {
                    return [
                        'id' => 'compliance_'.$app->id,
                        'type' => 'Compliance',
                        'description' => 'Compliance Application - '.($app->organization->name ?? 'N/A'),
                        'amount' => $app->amount,
                        'status' => 'completed',
                        'user_name' => $app->organization->user->name ?? 'N/A',
                        'date' => $app->created_at->toIso8601String(),
                        'currency' => 'USD',
                    ];
                });

            // Fractional Orders
            $fractionalTransactions = FractionalOrder::with(['user', 'offering'])
                ->where('status', 'paid')
                ->latest('paid_at')
                ->limit(10)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => 'fractional_'.$order->id,
                        'type' => 'Fractional Ownership',
                        'description' => $order->offering->title.' - Order '.($order->order_number ?? '#'.$order->id),
                        'amount' => $order->amount,
                        'status' => 'completed',
                        'user_name' => $order->user->name ?? 'N/A',
                        'date' => $order->paid_at?->toIso8601String(),
                        'currency' => $order->offering->currency ?? 'USD',
                    ];
                });

            // Combine and sort by date
            $recentTransactions = $form1023Transactions
                ->concat($complianceTransactions)
                ->concat($fractionalTransactions)
                ->sortByDesc('date')
                ->take(10)
                ->values();

            // Promotional banners are only shown for organization users, not admins
            return Inertia::render('dashboard', [
                'isAdmin' => true,
                'stats' => $stats,
                'recentForm1023Applications' => $recentForm1023Applications,
                'recentOrganizations' => $recentOrganizations,
                'paymentStats' => $paymentStats,
                'recentTransactions' => $recentTransactions,
                'monthlyRevenue' => $monthlyRevenue,
                'phazeBalanceSummary' => $this->phazeBalanceService->getSummary(fetchLivePhazeBalance: true),
                'promotionalBanner' => null,
                'promotionalBanners' => null,
            ]);
        }

        // Organization User Dashboard
        $careAlliance = CareAlliance::query()
            ->where('creator_user_id', $user->id)
            ->first();
        $isCareAllianceCreator = $careAlliance !== null;

        $organization = Organization::forAuthUser($user) ?? $user->organization;
        $totalFav = 0;
        $volunteers = 0;
        $donations = 0;
        $events = 0;

        // Hub org exists for Care Alliance creators but dashboard metrics / compliance here are nonprofit-org–scoped; skip org table for CA dashboard.
        if ($organization && ! $isCareAllianceCreator) {
            $organization = $this->refreshComplianceIfNeeded($organization);
            $totalFav = UserFavoriteOrganization::where('organization_id', $organization->id)->count();

            // 1. Total Volunteers Count (approved only)
            $jobPosts = JobPost::where('organization_id', $organization->id)->pluck('id');
            if ($jobPosts->isNotEmpty()) {
                $volunteers = JobApplication::whereIn('job_post_id', $jobPosts)
                    ->where('status', 'accepted')
                    ->count();
            }

            // 2. Total Donations Count
            $donations = Donation::where('organization_id', $organization->id)
                ->where('status', 'completed')
                ->count();

            // 3. Total Events Count
            $events = Event::where('organization_id', $organization->id)->count();
        } elseif ($organization && $isCareAllianceCreator) {
            $organization = $this->refreshComplianceIfNeeded($organization);
        }

        $user->load('interestedTopics');

        // Get latest Form 1023 application status
        $form1023Application = null;
        if ($organization && ! $isCareAllianceCreator) {
            $latestApplication = $organization->form1023Applications()
                ->latest()
                ->first();

            if ($latestApplication) {
                $form1023Application = [
                    'id' => $latestApplication->id,
                    'application_number' => $latestApplication->application_number,
                    'status' => $latestApplication->status,
                    'payment_status' => $latestApplication->payment_status,
                    'submitted_at' => $latestApplication->submitted_at?->toIso8601String(),
                    'amount' => $latestApplication->amount,
                ];
            }
        }

        // Get all active promotional banners for carousel (only if global setting is on)
        $showPromotionalBanners = (bool) AdminSetting::get('promotional_banners_show_on_dashboard', true);
        $promotionalBanners = $showPromotionalBanners ? PromotionalBanner::getActiveBanners() : collect();
        $promotionalBanner = $promotionalBanners->first(); // For backward compatibility

        // Get Form 990 filing status
        $form990Filings = null;
        $overdueForm990Filings = [];
        if ($organization && ! $isCareAllianceCreator) {
            $overdueFilings = $organization->getOverdueForm990Filings();

            $overdueForm990Filings = $overdueFilings->map(function ($filing) {
                return [
                    'id' => $filing->id,
                    'tax_year' => $filing->tax_year,
                    'form_type' => $filing->form_type ?? '990',
                    'due_date' => $filing->due_date?->toIso8601String(),
                    'extended_due_date' => $filing->extended_due_date?->toIso8601String(),
                    'is_filed' => $filing->is_filed,
                    'days_until_due' => $filing->daysUntilDue(),
                    'is_overdue' => $filing->isOverdue(),
                ];
            })->toArray();

            // Get latest filing status
            $latestFiling = $organization->getLatestForm990Filing();
            if ($latestFiling) {
                $form990Filings = [
                    'id' => $latestFiling->id,
                    'tax_year' => $latestFiling->tax_year,
                    'form_type' => $latestFiling->form_type ?? '990',
                    'filing_date' => $latestFiling->filing_date?->toIso8601String(),
                    'is_filed' => $latestFiling->is_filed,
                    'due_date' => $latestFiling->due_date?->toIso8601String(),
                    'extended_due_date' => $latestFiling->extended_due_date?->toIso8601String(),
                    'is_overdue' => $latestFiling->isOverdue(),
                    'days_until_due' => $latestFiling->daysUntilDue(),
                    'last_checked_at' => $latestFiling->last_checked_at?->toIso8601String(),
                ];
            }
        }

        // Check if organization user has active subscription
        // For organization users: check if they have any active plan subscription
        $hasSubscription = $user->current_plan_id !== null;

        // Care Alliance hub (creator): dashboard totals + pool balance for /dashboard
        $careAllianceDashboard = null;
        if ($careAlliance) {
            $aid = (int) $careAlliance->id;

            $campaignRaisedCents = (int) CareAllianceDonation::query()
                ->whereHas('campaign', fn ($q) => $q->where('care_alliance_id', $aid))
                ->where('status', CareAllianceDonation::STATUS_COMPLETED)
                ->sum('amount_cents');

            $generalRaisedCents = (int) round((float) Donation::query()
                ->where('care_alliance_id', $aid)
                ->whereIn('status', ['completed', 'active'])
                ->sum('amount') * 100);

            $completedGiftCount = CareAllianceDonation::query()
                ->whereHas('campaign', fn ($q) => $q->where('care_alliance_id', $aid))
                ->where('status', CareAllianceDonation::STATUS_COMPLETED)
                ->count()
                + Donation::query()
                    ->where('care_alliance_id', $aid)
                    ->whereIn('status', ['completed', 'active'])
                    ->count();

            $careAllianceDashboard = [
                'total_raised_cents' => $campaignRaisedCents + $generalRaisedCents,
                'active_members_count' => CareAllianceMembership::query()
                    ->where('care_alliance_id', $aid)
                    ->where('status', 'active')
                    ->count(),
                'campaigns_count' => CareAllianceCampaign::query()
                    ->where('care_alliance_id', $aid)
                    ->count(),
                'pending_join_requests_count' => CareAllianceJoinRequest::query()
                    ->where('care_alliance_id', $aid)
                    ->where('status', 'pending')
                    ->count(),
                'completed_gift_count' => $completedGiftCount,
            ];
        }

        // Profile completion (required onboarding documents) — hub org only, not Care Alliance dashboard.
        $profileCompletion = null;
        if ($organization && ! $isCareAllianceCreator) {
            $profileCompletion = OrganizationProfileCompletionService::forOrganization($organization);
        }

        return Inertia::render('dashboard', [
            'isAdmin' => false,
            'profileCompletion' => $profileCompletion,
            'totalOrg' => 0,
            'orgInfo' => $organization ?? null,
            'totalFav' => $totalFav ?? 0,
            'volunteers' => $volunteers ?? 0,
            'donations' => $donations ?? 0,
            'events' => $events ?? 0,
            'form1023Application' => $form1023Application,
            'form990Filings' => $form990Filings,
            'overdueForm990Filings' => $overdueForm990Filings,
            'promotionalBanner' => $promotionalBanner ? (function () use ($promotionalBanner) {
                $imageUrl = $promotionalBanner->image_url;
                // Convert path to full URL if needed
                if ($imageUrl) {
                    $baseUrl = \Illuminate\Support\Facades\Storage::disk('public')->url('');
                    if (strpos($imageUrl, $baseUrl) !== 0) {
                        // It's a path, convert to full URL
                        $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($imageUrl);
                    }
                }

                return [
                    'id' => $promotionalBanner->id,
                    'title' => $promotionalBanner->title,
                    'type' => $promotionalBanner->type,
                    'image_url' => $imageUrl,
                    'text_content' => $promotionalBanner->text_content,
                    'external_link' => $promotionalBanner->external_link,
                    'background_color' => $promotionalBanner->background_color,
                    'text_color' => $promotionalBanner->text_color,
                    'description' => $promotionalBanner->description,
                ];
            })() : null,
            'promotionalBanners' => $promotionalBanners->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'type' => $banner->type,
                    'image_url' => $banner->image_url,
                    'text_content' => $banner->text_content,
                    'external_link' => $banner->external_link,
                    'background_color' => $banner->background_color,
                    'text_color' => $banner->text_color,
                    'description' => $banner->description,
                ];
            })->toArray(),
            'topics' => $user->interestedTopics->map(function ($topic) {
                return [
                    'id' => $topic->id,
                    'name' => $topic->name,
                ];
            }),
            'hasSubscription' => $hasSubscription,
            'careAllianceDashboard' => $careAllianceDashboard,
            'careAllianceProfile' => $careAlliance ? [
                'id' => $careAlliance->id,
                'name' => $careAlliance->name,
                'slug' => $careAlliance->slug,
                'ein' => $careAlliance->ein,
                'description' => $careAlliance->description,
                'city' => $careAlliance->city,
                'state' => $careAlliance->state,
                'website' => $careAlliance->website,
            ] : null,
        ]);
    }

    public function getUserTopic(Request $request)
    {
        return $request->user()->interestedTopics()->get();
    }

    public function destroyUserTopic(Request $request, $topicId)
    {
        $request->user()->interestedTopics()->detach($topicId);

        return redirect()->back()->with('success', 'Topic removed successfully');
    }

    private function refreshComplianceIfNeeded(Organization $organization): Organization
    {
        $shouldRefresh = ! $organization->tax_compliance_checked_at
            || $organization->tax_compliance_checked_at->lte(Carbon::now()->subDay());

        if (! $shouldRefresh) {
            $this->syncOrganizationOwnerRole($organization);

            return $organization;
        }

        $evaluation = $this->taxComplianceService->evaluateForOrganization($organization);

        $organization->tax_compliance_status = $evaluation['status'];
        $organization->tax_compliance_checked_at = $evaluation['checked_at'];
        $organization->tax_compliance_meta = $evaluation['meta'];
        $organization->is_compliance_locked = $evaluation['should_lock'];

        if ($evaluation['should_lock']) {
            $organization->status = 'Inactive';
            if ($organization->registration_status === 'approved') {
                $organization->registration_status = 'pending';
            }
        } else {
            if ($organization->status === 'Inactive') {
                $organization->status = 'Active';
            }
            // Non-EIN and tax-current EIN orgs stay/become approved for full module access.
            if ($organization->registration_status === 'pending') {
                $organization->registration_status = 'approved';
            }
        }

        if ($organization->isDirty([
            'tax_compliance_status',
            'tax_compliance_checked_at',
            'tax_compliance_meta',
            'is_compliance_locked',
            'status',
            'registration_status',
        ])) {
            $organization->save();
            $organization->refresh();
        }

        $this->syncOrganizationOwnerRole($organization);

        return $organization;
    }

    /**
     * @param  list<string>  $orgRoleNames
     */
    private function syncOrganizationSpatieRoles(User $user, array $orgRoleNames): void
    {
        $names = array_values(array_unique($orgRoleNames));
        if (CareAlliance::where('creator_user_id', $user->id)->exists()) {
            $names[] = 'care_alliance';
            $names = array_values(array_unique($names));
        }

        $roles = collect($names)
            ->map(fn (string $n) => Role::findOrCreate($n, 'web'))
            ->all();
        $user->syncRoles($roles);
    }

    private function syncOrganizationOwnerRole(Organization $organization): void
    {
        $user = $organization->user;

        if (! $user) {
            return;
        }

        $hasCareAlliance = CareAlliance::where('creator_user_id', $user->id)->exists();

        // Check if there's an approved Form 1023 application
        // If approved, user should have organization role
        $hasApprovedForm1023 = $organization->form1023Applications()
            ->where('status', 'approved')
            ->exists();

        // If there's an approved Form 1023 application, ensure user has organization role
        if ($hasApprovedForm1023) {
            $expectedColumnRole = $hasCareAlliance ? 'care_alliance' : 'organization';
            if (! $user->hasRole('organization') || $user->role !== $expectedColumnRole) {
                // Remove organization_pending role if it exists
                if ($user->hasRole('organization_pending')) {
                    $user->removeRole('organization_pending');
                }

                $this->syncOrganizationSpatieRoles($user, ['organization']);
                $user->role = $expectedColumnRole;
                $user->save();

                // Ensure registration_status is approved
                if ($organization->registration_status !== 'approved') {
                    $organization->registration_status = 'approved';
                    $organization->save();
                }
            }

            return;
        }

        // Check if there's a Form 1023 application in progress
        // If so, keep organization role even if registration_status is pending
        $hasForm1023Application = $organization->form1023Applications()
            ->whereIn('status', ['draft', 'pending_payment', 'awaiting_review', 'needs_more_info'])
            ->exists();

        // If user has organization role and has Form 1023 application, keep it
        if ($hasForm1023Application && $user->hasRole('organization')) {
            $expectedColumnRole = $hasCareAlliance ? 'care_alliance' : 'organization';
            if ($user->role !== $expectedColumnRole) {
                $user->role = $expectedColumnRole;
                $user->save();
            }
            $this->syncOrganizationSpatieRoles($user, ['organization']);

            return;
        }

        $targetRole = ($organization->registration_status === 'approved' && ! $organization->is_compliance_locked)
            ? 'organization'
            : 'organization_pending';

        Role::findOrCreate($targetRole);

        $expectedColumnRole = $hasCareAlliance ? 'care_alliance' : $targetRole;
        $expectedRoleCount = 1 + ($hasCareAlliance ? 1 : 0);
        if ($user->hasRole($targetRole) && $user->role === $expectedColumnRole && $user->roles()->count() === $expectedRoleCount && (! $hasCareAlliance || $user->hasRole('care_alliance'))) {
            return;
        }

        $this->syncOrganizationSpatieRoles($user, [$targetRole]);

        if ($user->role !== $expectedColumnRole) {
            $user->role = $expectedColumnRole;
            $user->save();
        }
    }
}
