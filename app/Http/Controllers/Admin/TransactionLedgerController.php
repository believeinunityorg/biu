<?php

namespace App\Http\Controllers\Admin;

use App\Exports\LedgerFlatFileExport;
use App\Http\Controllers\Controller;
use App\Models\BelievePointPurchase;
use App\Models\BelievePointWalletTransfer;
use App\Models\CareAlliance;
use App\Models\CareAllianceDonation;
use App\Models\Course;
use App\Models\Donation;
use App\Models\Enrollment;
use App\Models\FundMeDonation;
use App\Models\GiftCard;
use App\Models\MerchantHubOfferRedemption;
use App\Models\MerchantHubReferralReward;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Raffle;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\LedgerListFilters;
use App\Services\Admin\UnifiedLedgerFlatFileMapper;
use App\Services\Admin\UnifiedLedgerPresenter;
use App\Services\DonationProcessingFeeEstimator;
use App\Services\EnrollmentLedgerService;
use App\Services\GiftCardLedgerService;
use App\Services\MarketplaceOrderLedgerService;
use App\Services\ServiceOrderLedgerService;
use App\Support\StripeReferenceMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Support\Header;
use Laravel\Cashier\Cashier;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TransactionLedgerController extends Controller
{
    public function __construct(
        private readonly UnifiedLedgerPresenter $unifiedLedgerPresenter,
        private readonly UnifiedLedgerFlatFileMapper $flatFileMapper,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        if ($this->isLedgerOrgPickerPartialOnly($request)) {
            return Inertia::render('admin/transactions/ledger', [
                'ledgerOrgPicker' => $this->buildLedgerOrgPickerPayload($request),
            ]);
        }

        [$query, $organizationFilterActive, $orgId] = $this->ledgerFilteredQuery($request);

        $perPage = $request->integer('per_page', 10);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $transactions = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Transaction $t) {
                $p = $this->prepareLedgerPresentation($t);

                return [
                    'id' => $t->id,
                    'transaction_id' => $t->transaction_id,
                    'type' => $t->type,
                    'status' => $t->status,
                    'amount' => (float) $t->amount,
                    'fee' => (float) $t->fee,
                    'currency' => $t->currency,
                    'payment_method' => $t->payment_method,
                    'related_kind' => $p['related']['related_kind'],
                    'related_purpose' => $p['related']['related_purpose'],
                    'related_display_name' => $p['related']['related_display_name'],
                    'related_label' => $p['related']['related_label'],
                    'related_source' => $p['related']['related_source'],
                    'donation_badge' => $p['donationBadge'],
                    'donation_badge_label' => $p['donationBadgeLabel'],
                    'donation_ledger_perspective' => $p['donationLedgerPerspective'],
                    'processed_at' => $t->processed_at?->toIso8601String(),
                    'created_at' => $t->created_at->toIso8601String(),
                    'user' => $t->user ? [
                        'id' => $t->user->id,
                        'name' => $t->user->name,
                        'email' => $t->user->email,
                    ] : null,
                    'meta' => $t->meta,
                    'ledger_report' => $p['ledgerReport'],
                    'unified_ledger' => $p['unified'],
                ];
            });

        $stats = $this->ledgerStatsForFilteredQuery($query);

        return Inertia::render('admin/transactions/ledger', [
            'transactions' => $transactions,
            'stats' => $stats,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'type' => $request->filled('type') ? $request->string('type')->toString() : 'all',
                'status' => $request->filled('status') ? $request->string('status')->toString() : 'all',
                'per_page' => $perPage,
                'organization_id' => $organizationFilterActive ? $orgId : null,
                'module' => $request->filled('module') ? $request->string('module')->toString() : 'all',
                'period' => $request->filled('period') ? $request->string('period')->toString() : 'all',
                'ledger_type' => $request->filled('ledger_type') ? $request->string('ledger_type')->toString() : 'all',
                'connection_hub_type' => $request->filled('connection_hub_type') ? $request->string('connection_hub_type')->toString() : 'all',
            ],
            'moduleOptions' => LedgerListFilters::moduleOptions(),
            'connectionHubTypeOptions' => LedgerListFilters::connectionHubTypeOptions(),
            'ledgerTypeOptions' => LedgerListFilters::ledgerTypeOptions(),
            'ledgerOrganizationInitial' => $organizationFilterActive && $orgId > 0
                ? [
                    [
                        'value' => (string) $orgId,
                        'label' => Organization::query()->whereKey($orgId)->value('name') ?? 'Organization #'.$orgId,
                    ],
                ]
                : [],
            /** @see resources/js/lib/transaction-type-labels.ts TRANSACTION_TYPE_FILTER_ORDER (keep in sync) */
            'typeOptions' => [
                'adjustment',
                'administrative_fee',
                'animal_purchase',
                'believe_points_auto_replenish',
                'believe_points_auto_replenish_setup',
                'believe_points_purchase',
                'believe_points_wallet_transfer',
                'bp_gift_sent',
                'bp_gift_claimed',
                'bp_gift_cancelled',
                'bp_gift_expired',
                'bp_gift_refunded',
                'bp_settlement',
                'big_boss_override',
                'cancellation',
                'commission',
                'compliance_application',
                'credit_purchase',
                'deposit',
                'direct_referral',
                'donation',
                'email_purchase',
                'enrollment',
                'form_1023_application',
                'fractional_ownership',
                'free',
                'fundme_donation',
                'gift_card_purchase',
                'kyc_fee',
                'merchant_hub_redemption',
                'merchant_subscription',
                'newsletter_pro_targeting_lifetime',
                'paid',
                'plan_subscription',
                'purchase',
                'raffle_sale',
                'raffle_tickets',
                'referral_reward',
                'refund',
                'service_order',
                'sms_purchase',
                'transfer',
                'transfer_in',
                'transfer_out',
                'wallet_subscription',
                'winning_bid',
                'withdrawal',
            ],
            'statusOptions' => [
                'pending',
                'completed',
                'failed',
                'cancelled',
                'withdrawal',
                'refund',
                'deposit',
                'rejected',
            ],
        ]);
    }

    /**
     * Excel download (client flat-file columns; same filters as the ledger index).
     */
    public function exportFlatFile(Request $request): BinaryFileResponse|SymfonyResponse
    {
        if ($this->isLedgerOrgPickerPartialOnly($request)) {
            abort(400);
        }

        // Inertia visits send X-Inertia; a binary XLSX is not valid Inertia JSON — force a full navigation.
        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        [$baseQuery] = $this->ledgerFilteredQuery($request);
        $filename = 'ledger-export-'.now()->format('Y-m-d-His').'.xlsx';

        $query = $baseQuery->clone()->with(['user:id,name,email'])->orderBy('id');

        $rows = (function () use ($query) {
            foreach ($query->cursor() as $t) {
                if (! $t instanceof Transaction) {
                    continue;
                }
                $presentation = $this->prepareLedgerPresentation($t);
                $flat = $this->flatFileMapper->map(
                    $t,
                    $presentation['unified'],
                    $presentation['related'],
                );
                yield array_map(
                    static fn (string $h) => $flat[$h] ?? '',
                    UnifiedLedgerFlatFileMapper::CSV_HEADERS
                );
            }
        })();

        return Excel::download(
            new LedgerFlatFileExport($rows),
            $filename,
            ExcelWriterType::XLSX,
        );
    }

    /**
     * @return array{0: Builder, 1: bool, 2: int}
     */
    private function ledgerFilteredQuery(Request $request): array
    {
        $query = Transaction::query()->with(['user:id,name,email']);

        if ($request->filled('search')) {
            $s = $request->string('search')->trim();
            $query->where(function ($q) use ($s) {
                $q->where('transaction_id', 'like', '%'.$s.'%')
                    ->orWhere('payment_method', 'like', '%'.$s.'%')
                    ->orWhere('meta', 'like', '%'.$s.'%')
                    ->orWhere('related_type', 'like', '%'.$s.'%');
                if (ctype_digit((string) $s)) {
                    $id = (int) $s;
                    $q->orWhere('related_id', $id)
                        ->orWhere('meta->donation_id', $id)
                        ->orWhere('meta->care_alliance_donation_id', $id)
                        ->orWhere('meta->enrollment_record_id', $id)
                        ->orWhere('meta->course_id', $id);
                }
                $q->orWhere('meta->enrollment_id', 'like', '%'.$s.'%')
                    ->orWhere('meta->course_name', 'like', '%'.$s.'%');
                $q->orWhereHas('user', function ($uq) use ($s) {
                    $uq->where('name', 'like', '%'.$s.'%')
                        ->orWhere('email', 'like', '%'.$s.'%');
                });
            });
        }

        if ($request->filled('type') && $request->string('type') !== 'all') {
            $type = $request->string('type')->toString();
            if ($type === 'enrollment') {
                LedgerListFilters::applyEnrollmentWalletType($query);
            } elseif (in_array($type, ['bp_gift', 'bp_gift_sent', 'bp_gift_claimed', 'bp_gift_cancelled', 'bp_gift_expired', 'bp_gift_refunded'], true)
                || str_starts_with($type, 'bp_gift_')) {
                $query->where(function ($q) use ($type) {
                    if ($type === 'bp_gift') {
                        $q->whereIn('type', [
                            'bp_gift',
                            'bp_gift_sent',
                            'bp_gift_claimed',
                            'bp_gift_cancelled',
                            'bp_gift_expired',
                            'bp_gift_refunded',
                            'bp_gift_hold',
                            'bp_gift_claim',
                            'bp_gift_hold_refund',
                            'bp_gift_email_changed',
                        ])->orWhere('transaction_id', 'like', 'bp_gift%');
                    } else {
                        $q->where('type', $type)
                            ->orWhere('meta->source', $type)
                            ->orWhere('transaction_id', 'like', $type.':%');
                    }
                });
            } else {
                $query->where('type', $type);
            }
        }

        if ($request->filled('status') && $request->string('status') !== 'all') {
            $query->where('status', $request->string('status'));
        }

        $orgId = $request->integer('organization_id');
        $organizationFilterActive = $orgId > 0 && Organization::query()
            ->whereKey($orgId)
            ->where('registration_status', 'approved')
            ->excludingCareAllianceHubs()
            ->exists();
        if ($organizationFilterActive) {
            LedgerListFilters::applyOrganization($query, $orgId);
        }

        if ($request->filled('module') && $request->string('module') !== 'all') {
            LedgerListFilters::applyModule($query, $request->string('module')->toString());
        }

        if ($request->filled('period') && $request->string('period') !== 'all') {
            LedgerListFilters::applyPeriod($query, $request->string('period')->toString());
        }

        if ($request->filled('ledger_type') && $request->string('ledger_type') !== 'all') {
            LedgerListFilters::applyLedgerType($query, $request->string('ledger_type')->toString());
        }

        if ($request->filled('connection_hub_type') && $request->string('connection_hub_type') !== 'all') {
            LedgerListFilters::applyConnectionHubType($query, $request->string('connection_hub_type')->toString());
        }

        return [$query, $organizationFilterActive, $orgId];
    }

    /**
     * Stats for the same row set as the ledger table and export (search, type, status, org, module, period).
     *
     * @return array{total_records: int, completed_sum: float, pending_count: int, failed_count: int}
     */
    private function ledgerStatsForFilteredQuery(Builder $filteredBase): array
    {
        return [
            'total_records' => (int) $filteredBase->clone()->count(),
            'completed_sum' => (float) $filteredBase->clone()
                ->where('status', Transaction::STATUS_COMPLETED)
                ->where(function ($q) {
                    $q->whereNull('meta')
                        ->orWhereNull('meta->exclude_from_wallet_stats')
                        ->orWhere('meta->exclude_from_wallet_stats', false);
                })
                ->sum('amount'),
            'pending_count' => (int) $filteredBase->clone()
                ->where('status', Transaction::STATUS_PENDING)
                ->count(),
            'failed_count' => (int) $filteredBase->clone()
                ->where('status', Transaction::STATUS_FAILED)
                ->count(),
        ];
    }

    /**
     * Shared path for unified ledger + ledger_report (index rows and flat export).
     *
     * @return array{
     *     related: array<string, mixed>,
     *     donationPayload: mixed,
     *     donationBadge: bool,
     *     donationBadgeLabel: string,
     *     donationLedgerPerspective: string|null,
     *     ledgerReport: array<string, mixed>,
     *     unified: array<string, mixed>
     * }
     */
    private function prepareLedgerPresentation(Transaction $t): array
    {
        $this->maybeSyncEnrollmentLedgerMeta($t);

        $related = $this->resolveRelatedDetails($t->related_type, $t->related_id, $t->meta);
        $donationPayload = $this->resolveDonationForLedger($t);
        $donationBadge = $donationPayload !== null;
        $donationBadgeLabel = $this->resolveDonationBadgeLabel($t, $donationPayload);
        $donationLedgerPerspective = $this->resolveDonationLedgerPerspective($t, $donationPayload);
        if ($donationPayload !== null && ! $this->shouldKeepStrongPolymorphicOnlyForRelated($t)) {
            $related = $this->overlayLedgerRelatedWithDonation($t, $related, $donationPayload);
        }

        $ledgerReport = $this->buildLedgerReportRow($t, $donationPayload);
        $unifiedRelated = [
            'related_kind' => $related['related_kind'],
            'related_label' => $related['related_label'],
            'related_display_name' => $related['related_display_name'],
        ];
        $unified = $this->unifiedLedgerPresenter->present(
            $t,
            $ledgerReport,
            $donationPayload,
            $donationLedgerPerspective,
            $unifiedRelated,
        );

        return [
            'related' => $related,
            'donationPayload' => $donationPayload,
            'donationBadge' => $donationBadge,
            'donationBadgeLabel' => $donationBadgeLabel,
            'donationLedgerPerspective' => $donationLedgerPerspective,
            'ledgerReport' => $ledgerReport,
            'unified' => $unified,
        ];
    }

    /**
     * Inertia partial reload: only `ledgerOrgPicker` — skips the heavy transaction query.
     */
    private function isLedgerOrgPickerPartialOnly(Request $request): bool
    {
        if (! $request->header(Header::INERTIA)) {
            return false;
        }
        $only = $request->header(Header::PARTIAL_ONLY, '');
        if ($only === '') {
            return false;
        }
        $props = array_values(array_filter(array_map('trim', explode(',', $only))));

        return $props === ['ledgerOrgPicker'];
    }

    /**
     * @return array{items: array<int, array{value: string, label: string}>, has_more: bool, page: int, search: string}
     */
    private function buildLedgerOrgPickerPayload(Request $request): array
    {
        $perPage = 20;
        $page = max(1, $request->integer('org_picker_page', 1));
        $search = $request->string('org_picker_q')->trim();

        $orgQuery = Organization::query()
            ->where('registration_status', 'approved')
            ->excludingCareAllianceHubs()
            ->orderBy('name');

        if ($search !== '') {
            $orgQuery->where('name', 'like', '%'.$search.'%');
        }

        $paginator = $orgQuery->paginate($perPage, ['id', 'name'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(fn (Organization $o) => [
                'value' => (string) $o->id,
                'label' => $o->name,
            ])
            ->values()
            ->all();

        if ($page === 1) {
            array_unshift($items, ['value' => 'all', 'label' => 'All organizations']);
        }

        return [
            'items' => $items,
            'has_more' => $paginator->hasMorePages(),
            'page' => $page,
            'search' => $search,
        ];
    }

    public function show(Transaction $transaction): InertiaResponse
    {
        $transaction->load(['user:id,name,email,stripe_id']);

        return Inertia::render('admin/transactions/show', [
            'transaction' => $this->serializeTransaction($transaction),
        ]);
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $ref = $transaction->transaction_id;
        $transaction->delete();

        return redirect()
            ->route('admin.transactions.ledger')
            ->with('success', 'Transaction '.$ref.' was removed from the ledger.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(Transaction $t): array
    {
        $related = $this->resolveRelatedDetails($t->related_type, $t->related_id, $t->meta);
        $donationLedger = $this->resolveDonationForLedger($t);
        if ($donationLedger !== null && ! $this->shouldKeepStrongPolymorphicOnlyForRelated($t)) {
            $related = $this->overlayLedgerRelatedWithDonation($t, $related, $donationLedger);
        }

        $ledgerReportRow = $this->buildLedgerReportRow($t, $donationLedger);
        $donationPerspective = $this->resolveDonationLedgerPerspective($t, $donationLedger);
        $unifiedRelated = [
            'related_kind' => $related['related_kind'],
            'related_label' => $related['related_label'],
            'related_display_name' => $related['related_display_name'],
        ];

        return [
            'id' => $t->id,
            'transaction_id' => $t->transaction_id,
            'type' => $t->type,
            'status' => $t->status,
            'amount' => (float) $t->amount,
            'fee' => (float) $t->fee,
            'currency' => $t->currency,
            'payment_method' => $t->payment_method,
            'related_type' => $t->related_type,
            'related_id' => $t->related_id,
            'related_kind' => $related['related_kind'],
            'related_purpose' => $related['related_purpose'],
            'related_display_name' => $related['related_display_name'],
            'related_label' => $related['related_label'],
            'related_source' => $related['related_source'],
            'processed_at' => $t->processed_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
            'updated_at' => $t->updated_at->toIso8601String(),
            'user' => $t->user ? [
                'id' => $t->user->id,
                'name' => $t->user->name,
                'email' => $t->user->email,
            ] : null,
            'meta' => $t->meta,
            'stripe' => $this->buildStripePresentation($t),
            'donation' => $donationLedger,
            'donation_badge' => $donationLedger !== null,
            'donation_badge_label' => $this->resolveDonationBadgeLabel($t, $donationLedger),
            'donation_ledger_perspective' => $donationPerspective,
            'ledger_actor_context' => $this->resolveLedgerActorContext($t, $donationLedger),
            'ledger_report' => $ledgerReportRow,
            'unified_ledger' => $this->unifiedLedgerPresenter->present(
                $t,
                $ledgerReportRow,
                $donationLedger,
                $donationPerspective,
                $unifiedRelated,
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDonationForLedger(Transaction $t): ?array
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        $donationId = $this->extractMetaDonationId($meta);
        if (! $donationId && $t->related_type === Donation::class && $t->related_id) {
            $donationId = (int) $t->related_id;
        }
        if ($donationId > 0) {
            $d = Donation::query()->with(['organization:id,name,user_id', 'careAlliance:id,name,slug'])->find($donationId);

            return $this->serializeMainDonation($d, $donationId);
        }

        if (! empty($meta['care_alliance_donation_id'])) {
            $cad = CareAllianceDonation::query()
                ->with(['campaign.careAlliance'])
                ->find((int) $meta['care_alliance_donation_id']);
            if ($cad) {
                return $this->serializeCareAllianceCampaignDonation($cad);
            }
        }

        foreach ($this->stripePaymentIntentCandidatesFromTransaction($t, $meta) as $pi) {
            $d = Donation::query()
                ->with(['organization:id,name,user_id', 'careAlliance:id,name,slug'])
                ->where('transaction_id', $pi)
                ->first();
            if ($d) {
                return $this->serializeMainDonation($d, null);
            }
        }

        $inferred = $this->inferDonationFromCareAllianceSplit($t);
        if ($inferred) {
            return $this->serializeMainDonation($inferred, null);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeMainDonation(?Donation $d, ?int $requestedId): ?array
    {
        if (! $d) {
            return $requestedId !== null && $requestedId > 0
                ? [
                    'kind' => 'donation',
                    'missing' => true,
                    'donation_id' => $requestedId,
                ]
                : null;
        }

        $rawRef = (string) ($d->transaction_id ?? '');

        return [
            'kind' => 'donation',
            'id' => $d->id,
            'status' => $d->status,
            'frequency' => $d->frequency,
            'amount_display' => $d->formatted_amount,
            'amount_raw' => (float) $d->amount,
            'checkout_total' => $d->checkout_total !== null ? (float) $d->checkout_total : null,
            'processing_fee_estimate' => $d->processing_fee_estimate !== null ? (float) $d->processing_fee_estimate : null,
            'donor_covers_processing_fees' => (bool) $d->donor_covers_processing_fees,
            'payment_method' => $d->payment_method,
            'stripe_reference' => $rawRef !== '' ? $rawRef : null,
            'organization_id' => $d->organization_id ? (int) $d->organization_id : null,
            'organization_name' => $d->organization?->name,
            'care_alliance_name' => $d->careAlliance?->name,
            'donor_user_id' => $d->user_id ? (int) $d->user_id : null,
            'recipient_user_id' => $d->organization?->user_id ? (int) $d->organization->user_id : null,
            'message' => $d->message ?? $d->messages,
            'donation_date' => $d->donation_date?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCareAllianceCampaignDonation(CareAllianceDonation $cad): array
    {
        $campaign = $cad->campaign;
        $amount = (int) ($cad->amount_cents ?? 0) / 100;

        return [
            'kind' => 'care_alliance_campaign',
            'id' => $cad->id,
            'status' => $cad->status,
            'amount_display' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper((string) ($cad->currency ?? 'USD')),
            'payment_reference' => $cad->payment_reference,
            'campaign_name' => $campaign?->name,
            'care_alliance_name' => $campaign?->careAlliance?->name,
        ];
    }

    private function extractMetaDonationId(array $meta): ?int
    {
        foreach (['donation_id', 'donationId'] as $key) {
            if (! isset($meta[$key]) || $meta[$key] === '' || $meta[$key] === null) {
                continue;
            }
            $v = $meta[$key];
            if (is_numeric($v)) {
                $id = (int) $v;

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    /**
     * Stripe PaymentIntent ids found only in JSON meta (no transaction row column).
     *
     * @return array<int, string>
     */
    private function stripePaymentIntentCandidatesFromMetaArray(array $meta): array
    {
        $out = [];
        foreach (['stripe_payment_intent', 'stripe_payment_intent_id', 'payment_intent', 'original_payment_intent'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k]) && str_starts_with($meta[$k], 'pi_')) {
                $out[] = $meta[$k];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<int, string>
     */
    private function stripePaymentIntentCandidatesFromTransaction(Transaction $t, array $meta): array
    {
        $out = $this->stripePaymentIntentCandidatesFromMetaArray($meta);
        $tid = (string) ($t->transaction_id ?? '');
        if (str_starts_with($tid, 'pi_')) {
            $out[] = $tid;
        }

        return array_values(array_unique($out));
    }

    private function inferDonationFromCareAllianceSplit(Transaction $t): ?Donation
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        if (($meta['source'] ?? '') !== 'care_alliance_split') {
            return null;
        }
        $aid = (int) ($meta['care_alliance_id'] ?? 0);
        if ($aid < 1) {
            return null;
        }
        $role = (string) ($meta['role'] ?? '');
        $txAt = $t->created_at;

        $q = Donation::query()
            ->where('care_alliance_id', $aid)
            ->whereIn('status', ['completed', 'active'])
            ->where('created_at', '<=', $txAt);

        if ($role === 'member_share' && ! empty($meta['organization_id'])) {
            $q->where('organization_id', (int) $meta['organization_id']);
        }

        return $q->orderByDesc('id')->first();
    }

    private function shouldKeepStrongPolymorphicOnlyForRelated(Transaction $t): bool
    {
        if (! $t->related_type || $t->related_id === null || $t->related_id === '') {
            return false;
        }
        $type = ltrim((string) $t->related_type, '\\');
        if (! str_contains($type, '\\') || ! class_exists($type)) {
            return false;
        }
        $basename = class_basename($type);

        return in_array($basename, [
            'Order',
            'ServiceOrder',
            'Enrollment',
            'GiftCard',
            'Plan',
            'Raffle',
            'MerchantHubOfferRedemption',
            'MerchantHubReferralReward',
            'BelievePointPurchase',
        ], true);
    }

    /**
     * Donor vs recipient (and split) for Believe / Unity Impact Alliance donation ledger rows.
     *
     * @param  array<string, mixed>|null  $donationPayload
     * @return string|null donor|recipient_direct|recipient_split|alliance_fee|campaign
     */
    private function resolveDonationLedgerPerspective(Transaction $t, ?array $donationPayload): ?string
    {
        if ($donationPayload === null || ($donationPayload['missing'] ?? false)) {
            return null;
        }
        if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign') {
            return 'campaign';
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        if (($meta['ledger_role'] ?? '') === 'donor_payment') {
            return 'donor';
        }
        if (($meta['source'] ?? '') === 'organization_donation') {
            return 'recipient_direct';
        }
        if (($meta['source'] ?? '') === 'care_alliance_split') {
            return (($meta['role'] ?? '') === 'alliance_fee') ? 'alliance_fee' : 'recipient_split';
        }

        if (($donationPayload['kind'] ?? '') === 'donation') {
            $donorId = $donationPayload['donor_user_id'] ?? null;
            $recipientId = $donationPayload['recipient_user_id'] ?? null;
            $uid = $t->user_id;
            if ($donorId && $uid === $donorId && $t->type === 'purchase') {
                return 'donor';
            }
            if ($recipientId && $uid === $recipientId && $t->type === 'deposit') {
                return 'recipient_direct';
            }
        }

        if ($t->type === 'deposit' && ($t->payment_method === 'donation')) {
            return 'recipient_direct';
        }
        if ($t->type === 'deposit' && ($t->payment_method === 'care_alliance_split')) {
            return (($meta['role'] ?? '') === 'alliance_fee') ? 'alliance_fee' : 'recipient_split';
        }
        if ($t->type === 'purchase' && ($t->payment_method === 'donation')) {
            return 'donor';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     */
    private function resolveDonationBadgeLabel(Transaction $t, ?array $donationPayload): string
    {
        if ($donationPayload === null) {
            return 'Donation';
        }
        if (($donationPayload['missing'] ?? false)) {
            return 'Donation (missing)';
        }
        if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign') {
            return 'Campaign gift';
        }

        return match ($this->resolveDonationLedgerPerspective($t, $donationPayload)) {
            'donor' => 'Donation sent',
            'recipient_direct' => 'Donation received',
            'recipient_split' => 'Donation received (split)',
            'alliance_fee' => 'Alliance fee (donation)',
            'campaign' => 'Campaign gift',
            default => 'Donation',
        };
    }

    /**
     * Whether this row is primarily about a personal user, a nonprofit (organization wallet), or Unity Impact Alliance.
     *
     * @param  array<string, mixed>|null  $donationLedger
     * @return array{kind: string, label: string, detail: string|null, organization_id: int|null, care_alliance_id: int|null, care_alliance_name: string|null}
     */
    private function resolveLedgerActorContext(Transaction $t, ?array $donationLedger): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $uid = $t->user_id;
        $dl = $donationLedger ?? [];

        $perspective = $this->resolveDonationLedgerPerspective($t, $donationLedger);

        $caId = (int) ($meta['care_alliance_id'] ?? 0);
        $caFromMeta = $caId > 0
            || (($meta['source'] ?? '') === 'care_alliance_split')
            || ($t->payment_method === 'care_alliance_split');
        $caFromPerspective = in_array($perspective, ['recipient_split', 'alliance_fee'], true);

        if ($caFromMeta || $caFromPerspective) {
            $name = $meta['care_alliance_name'] ?? ($dl['care_alliance_name'] ?? null);
            if ($caId > 0 && $name === null) {
                $name = CareAlliance::query()->where('id', $caId)->value('name');
            }

            return [
                'kind' => 'care_alliance',
                'label' => 'Unity Impact Alliance',
                'detail' => $name,
                'organization_id' => null,
                'care_alliance_id' => $caId > 0 ? $caId : null,
                'care_alliance_name' => $name,
            ];
        }

        $isCampaignDonor = (($dl['kind'] ?? '') === 'care_alliance_campaign' && $t->type === 'purchase');
        if (($meta['ledger_role'] ?? '') === 'donor_payment' || $perspective === 'donor' || $isCampaignDonor) {
            return [
                'kind' => 'user',
                'label' => 'User',
                'detail' => $isCampaignDonor ? 'Campaign donor' : 'Donor / personal wallet',
                'organization_id' => null,
                'care_alliance_id' => null,
                'care_alliance_name' => null,
            ];
        }

        if ($t->related_type && $t->related_id !== null && $t->related_id !== '' && $this->relatedIsOrganization($t)) {
            $org = Organization::query()->find($t->related_id);
            if ($org) {
                return [
                    'kind' => 'organization',
                    'label' => 'Organization',
                    'detail' => $org->name,
                    'organization_id' => $org->id,
                    'care_alliance_id' => null,
                    'care_alliance_name' => null,
                ];
            }
        }

        $ownedOrg = $uid ? Organization::query()->where('user_id', $uid)->first(['id', 'name']) : null;
        $metaOrgId = (int) ($meta['organization_id'] ?? 0);
        $orgMatch = $ownedOrg && $metaOrgId > 0 && $metaOrgId === $ownedOrg->id;
        $orgRecipient = $perspective === 'recipient_direct' && $donationLedger
            && (($dl['kind'] ?? '') === 'donation')
            && $uid && ($uid === ($dl['recipient_user_id'] ?? null));
        $srcOrgDonation = (($meta['source'] ?? '') === 'organization_donation');

        if ($ownedOrg && ($orgMatch || $orgRecipient || $srcOrgDonation)) {
            return [
                'kind' => 'organization',
                'label' => 'Organization',
                'detail' => $ownedOrg->name,
                'organization_id' => $ownedOrg->id,
                'care_alliance_id' => null,
                'care_alliance_name' => null,
            ];
        }

        if (($dl['kind'] ?? '') === 'care_alliance_campaign' && $perspective === 'campaign') {
            $name = $dl['care_alliance_name'] ?? $dl['campaign_name'] ?? null;

            return [
                'kind' => 'care_alliance',
                'label' => 'Unity Impact Alliance',
                'detail' => $name,
                'organization_id' => null,
                'care_alliance_id' => null,
                'care_alliance_name' => $dl['care_alliance_name'] ?? null,
            ];
        }

        return [
            'kind' => 'user',
            'label' => 'User',
            'detail' => $ownedOrg ? 'Personal wallet (also owns an organization)' : 'Personal Believe wallet',
            'organization_id' => null,
            'care_alliance_id' => null,
            'care_alliance_name' => null,
        ];
    }

    private function relatedIsOrganization(Transaction $t): bool
    {
        if (! $t->related_type || $t->related_id === null || $t->related_id === '') {
            return false;
        }
        $rt = ltrim((string) $t->related_type, '\\');

        return $rt === Organization::class || str_ends_with($rt, '\\Organization');
    }

    /**
     * Client-facing ledger report columns: source type, fee breakdown (from metadata when present), org, payout hint.
     *
     * @param  array<string, mixed>|null  $donationPayload
     * @return array<string, mixed>
     */
    private function buildLedgerReportRow(Transaction $t, ?array $donationPayload): array
    {
        // A Stripe PI can match both a selling row and a Donation row; selling-related transactions must use
        // marketplace / service-order merges, not donation NET rules (merge is skipped when donationPayload is set).
        $financialDonationPayload = $donationPayload;
        if ($financialDonationPayload !== null && $this->shouldKeepStrongPolymorphicOnlyForRelated($t)) {
            $financialDonationPayload = null;
        }

        $fin = $this->ledgerReportFinancials($t, $financialDonationPayload);
        $org = $this->ledgerReportOrganization($t, $donationPayload);

        $date = $t->processed_at ?? $t->created_at;

        return [
            'date' => $date->toIso8601String(),
            'reference' => (string) ($t->transaction_id ?? ''),
            'stripe_mode' => StripeReferenceMode::modeForTransaction($t, false),
            'source_type' => $this->resolveLedgerSourceType($t, $donationPayload),
            'gross_amount' => $fin['gross_amount'],
            'stripe_fee' => $fin['stripe_fee'],
            'bridge_fee' => $fin['bridge_fee'],
            'biu_fee' => $fin['biu_fee'],
            'split_deduction' => $fin['split_deduction'],
            'refund_amount' => $fin['refund_amount'],
            'net_to_organization' => $fin['net_to_organization'],
            'payout_status' => $fin['payout_status'],
            'organization_id' => $org['organization_id'],
            'organization_name' => $org['organization_name'],
            'organization_ein' => $org['organization_ein'] ?? null,
            'subtotal_amount' => $fin['subtotal_amount'] ?? null,
            'sales_tax_amount' => $fin['sales_tax_amount'] ?? null,
            'shipping_amount' => $fin['shipping_amount'] ?? null,
            'supplier_payout' => $fin['supplier_payout'] ?? null,
            'organization_payout' => $fin['organization_payout'] ?? null,
            'platform_payout' => $fin['platform_payout'] ?? null,
            'supporter_payout' => $fin['supporter_payout'] ?? null,
            'supplier_name' => $fin['supplier_name'] ?? null,
            'supplier_type' => $fin['supplier_type'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     */
    private function resolveLedgerSourceType(Transaction $t, ?array $donationPayload): string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';

        if ($rt === BelievePointPurchase::class || str_ends_with($rt, 'BelievePointPurchase')) {
            return 'believe_points_purchase';
        }
        if ($rt === BelievePointWalletTransfer::class || str_ends_with($rt, 'BelievePointWalletTransfer')) {
            return 'believe_points_wallet_transfer';
        }
        if (($meta['source'] ?? '') === 'believe_points_wallet_transfer') {
            return 'believe_points_wallet_transfer';
        }
        if (($meta['source'] ?? '') === 'bp_settlement' || $t->type === 'bp_settlement') {
            return 'bp_settlement';
        }
        if (($meta['source'] ?? '') === 'believe_points_purchase'
            || ($meta['source'] ?? '') === 'believe_points_purchase_refund') {
            return 'believe_points_purchase';
        }
        if (($meta['source'] ?? '') === 'course_enrollment') {
            return 'enrollment';
        }

        if ($rt === CareAllianceDonation::class || str_ends_with($rt, 'CareAllianceDonation')) {
            return 'care_alliance_donation';
        }
        if (! empty($meta['care_alliance_donation_id'])) {
            return 'care_alliance_donation';
        }
        if ($rt === FundMeDonation::class || str_ends_with($rt, 'FundMeDonation')) {
            return 'fundme_donation';
        }
        if (! empty($meta['fundme_donation_id']) || ! empty($meta['fundme_campaign_id'])) {
            return 'fundme_donation';
        }
        if ($rt === MerchantHubOfferRedemption::class || str_ends_with($rt, 'MerchantHubOfferRedemption')) {
            return 'merchant_hub_redemption';
        }
        if ($rt === MerchantHubReferralReward::class || str_ends_with($rt, 'MerchantHubReferralReward')) {
            return 'merchant_hub_referral';
        }
        if ($this->ledgerSourceIsOrgMarketingComms($t)) {
            return 'ledger_unclassified';
        }
        if ($rt === ServiceOrder::class || str_ends_with($rt, 'ServiceOrder')) {
            return 'service_order';
        }
        if ($rt === Order::class || str_ends_with($rt, 'Order')) {
            return 'order';
        }
        if ($rt === Donation::class || str_ends_with($rt, 'Donation')) {
            return 'donation';
        }
        if (($meta['source'] ?? '') === 'care_alliance_split'
            || ($meta['source'] ?? '') === 'care_alliance_campaign_split') {
            return 'care_alliance_donation';
        }
        if (($meta['source'] ?? '') === 'organization_donation' || ($meta['ledger_role'] ?? '') === 'donor_payment') {
            return 'donation';
        }
        if (($t->payment_method ?? '') === 'donation') {
            return 'donation';
        }
        if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign') {
            return 'care_alliance_donation';
        }
        if (($donationPayload['kind'] ?? '') === 'donation') {
            return 'donation';
        }

        if ($rt === Enrollment::class || str_ends_with($rt, 'Enrollment')) {
            return 'enrollment';
        }
        if (! empty($meta['enrollment_id'])) {
            return 'enrollment';
        }

        if ($rt === Plan::class || str_ends_with($rt, 'Plan')) {
            return 'plan_subscription';
        }
        if (! empty($meta['wallet_plan_id']) || ($meta['type'] ?? '') === 'wallet_subscription') {
            return 'wallet_plan_subscription';
        }

        if ($rt === GiftCard::class || str_ends_with($rt, 'GiftCard')) {
            return 'gift_card';
        }

        if (! empty($meta['raffle_id']) || strtolower((string) $rt) === 'raffle'
            || $rt === Raffle::class || str_ends_with($rt, 'Raffle')) {
            return 'raffle';
        }

        if ($t->type === 'commission') {
            return 'commission';
        }

        if ($t->type === 'wallet_subscription') {
            return 'wallet_plan_subscription';
        }
        if (in_array($t->type, ['plan_subscription', 'kyc_fee'], true)) {
            return 'plan_subscription';
        }

        if (! empty($meta['service_order_id'])) {
            return 'service_order';
        }

        if ($this->ledgerSourceIsOrgMarketingComms($t)) {
            return 'ledger_unclassified';
        }

        if (! empty($meta['order_id']) && empty($meta['donation_id']) && empty($meta['care_alliance_donation_id'])) {
            return 'order';
        }

        return 'ledger_unclassified';
    }

    /**
     * Stripe / legacy rows may store org newsletter & credit packs as `purchase` with product kind in meta only.
     */
    private function ledgerSourceIsOrgMarketingComms(Transaction $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $fromMeta = strtolower(trim((string) ($meta['type'] ?? '')));
        if (in_array($fromMeta, ['newsletter_pro_targeting_lifetime', 'sms_purchase', 'email_purchase'], true)) {
            return true;
        }
        $ty = strtolower(trim((string) ($t->type ?? '')));
        if (in_array($ty, ['newsletter_pro_targeting_lifetime', 'sms_purchase', 'email_purchase'], true)) {
            return true;
        }
        $desc = (string) ($meta['description'] ?? '');
        if ($desc !== '' && str_contains($desc, 'Newsletter Pro targeting') && str_contains($desc, 'lifetime')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function ledgerMetaFloat(array $meta, array $keys): float
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $meta) || $meta[$k] === null || $meta[$k] === '') {
                continue;
            }
            $v = $meta[$k];
            if (is_numeric($v)) {
                return round((float) $v, 2);
            }
        }

        return 0.0;
    }

    /**
     * Pending donations use payment_method "stripe"; after Stripe Checkout succeeds the column holds PM types (card, us_bank_account, link, …).
     * Fee coverage / NET must run for all of those, not only the literal string "stripe".
     */
    private function donationPaymentUsesStripeFees(string $paymentMethod): bool
    {
        if ($paymentMethod === 'believe_points') {
            return false;
        }

        return $paymentMethod !== '';
    }

    /**
     * Believe Points purchase rows store platform fee and Stripe processing fee separately on the purchase + transaction meta.
     */
    private function isBelievePointsPurchaseLedgerRow(Transaction $t): bool
    {
        if ($t->type === 'refund') {
            return false;
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        $source = (string) ($meta['source'] ?? '');
        if ($source === 'believe_points_purchase_refund') {
            return false;
        }
        if ($source === 'believe_points_purchase') {
            return true;
        }

        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';

        return $rt === BelievePointPurchase::class || str_ends_with($rt, 'BelievePointPurchase');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{gross_amount: float, stripe_fee: float, bridge_fee: float, biu_fee: float, split_deduction: float, refund_amount: float, net_to_organization: float|null}  $fin
     * @return array{gross_amount: float, stripe_fee: float, biu_fee: float, net_to_organization: float, platform_payout: float|null}
     */
    private function mergeBelievePointsPurchaseLedgerFinancials(
        Transaction $t,
        array $meta,
        array $fin,
        float $feeCol,
    ): array {
        $purchase = null;
        $needsPurchaseLookup = $t->related_id !== null
            && (int) $t->related_id > 0
            && (
                $this->ledgerMetaFloat($meta, ['base_points_usd', 'intended_points']) <= 0
                || $this->ledgerMetaFloat($meta, ['checkout_total_usd', 'gross_amount']) <= 0
                || (
                    $fin['biu_fee'] <= 0
                    && $this->ledgerMetaFloat($meta, ['platform_fee', 'platform_fee_amount', 'platform_fee_usd']) <= 0
                )
                || (
                    $fin['stripe_fee'] <= 0
                    && $this->ledgerMetaFloat($meta, [
                        'stripe_fee', 'stripe_fee_amount', 'payment_processor_fee', 'processor_fee', 'stripe_processing_fee',
                        'processing_fee_estimate',
                    ]) <= 0
                )
            );
        if ($needsPurchaseLookup) {
            $purchase = BelievePointPurchase::query()->find((int) $t->related_id);
        }

        $basePoints = $this->ledgerMetaFloat($meta, ['base_points_usd', 'intended_points']);
        if ($basePoints <= 0 && $purchase !== null) {
            $basePoints = round((float) $purchase->amount, 2);
        }

        $checkoutTotal = $this->ledgerMetaFloat($meta, ['checkout_total_usd', 'gross_amount']);
        if ($checkoutTotal <= 0 && $purchase?->checkout_total !== null) {
            $checkoutTotal = round((float) $purchase->checkout_total, 2);
        }
        if ($checkoutTotal <= 0) {
            $checkoutTotal = (float) $t->amount;
        }

        $platformFee = $fin['biu_fee'];
        if ($platformFee <= 0) {
            $platformFee = $this->ledgerMetaFloat($meta, ['platform_fee', 'platform_fee_amount', 'platform_fee_usd']);
        }
        if ($platformFee <= 0 && $purchase?->platform_fee !== null) {
            $platformFee = round((float) $purchase->platform_fee, 2);
        }

        $processingFee = $fin['stripe_fee'];
        $syncSource = (string) ($meta['stripe_fee_sync_source'] ?? '');
        if (in_array($syncSource, ['webhook', 'balance_transaction'], true)) {
            $syncedFee = $this->ledgerMetaFloat($meta, [
                'stripe_fee', 'stripe_fee_amount', 'stripe_processing_fee', 'payment_processor_fee', 'processor_fee',
            ]);
            if ($syncedFee > 0) {
                $processingFee = $syncedFee;
            }
        }
        if ($processingFee <= 0) {
            $processingFee = $this->ledgerMetaFloat($meta, [
                'stripe_fee', 'stripe_fee_amount', 'payment_processor_fee', 'processor_fee', 'stripe_processing_fee',
            ]);
        }
        if ($processingFee <= 0) {
            $processingFee = $this->ledgerMetaFloat($meta, [
                'processing_fee_estimate',
            ]);
        }
        if ($processingFee <= 0 && $purchase?->processing_fee_estimate !== null) {
            $processingFee = round((float) $purchase->processing_fee_estimate, 2);
        }

        if ($processingFee <= 0 && $platformFee > 0 && $feeCol > $platformFee) {
            $processingFee = round($feeCol - $platformFee, 2);
        } elseif ($platformFee <= 0 && $processingFee > 0 && $feeCol > $processingFee) {
            $platformFee = round($feeCol - $processingFee, 2);
        } elseif ($processingFee <= 0 && $platformFee <= 0 && $feeCol > 0 && $purchase !== null) {
            $processingFee = $purchase->processing_fee_estimate !== null
                ? round((float) $purchase->processing_fee_estimate, 2)
                : 0.0;
            $platformFee = $purchase->platform_fee !== null
                ? round((float) $purchase->platform_fee, 2)
                : 0.0;
            if ($processingFee <= 0 && $platformFee <= 0) {
                $processingFee = round($feeCol, 2);
            }
        }

        if ($checkoutTotal <= 0 && $basePoints > 0) {
            $checkoutTotal = round($basePoints + $platformFee + $processingFee, 2);
        }
        if ($basePoints <= 0 && $checkoutTotal > 0) {
            $basePoints = round(max(0.0, $checkoutTotal - $platformFee - $processingFee), 2);
        }

        $net = $basePoints > 0
            ? $basePoints
            : round(max(0.0, $checkoutTotal - $processingFee - $platformFee - $fin['bridge_fee'] - $fin['split_deduction']), 2);

        return [
            'gross_amount' => round($checkoutTotal, 2),
            'stripe_fee' => round($processingFee, 2),
            'biu_fee' => round($platformFee, 2),
            'net_to_organization' => round($net, 2),
            'platform_payout' => $platformFee > 0 ? round($platformFee, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     * @return array{gross_amount: float, stripe_fee: float, bridge_fee: float, biu_fee: float, split_deduction: float, refund_amount: float, net_to_organization: float|null, payout_status: string|null}
     */
    private function ledgerReportFinancials(Transaction $t, ?array $donationPayload = null): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        $gross = $this->ledgerMetaFloat($meta, ['gross_amount', 'amount_gross', 'total_amount', 'subtotal']);
        if ($gross <= 0) {
            $gross = (float) $t->amount;
        }

        $stripeFee = $this->ledgerMetaFloat($meta, [
            'stripe_fee', 'stripe_fee_amount', 'payment_processor_fee', 'processor_fee', 'stripe_processing_fee',
            'processing_fee_estimate',
        ]);
        $bridgeFee = $this->ledgerMetaFloat($meta, ['bridge_fee', 'bridge_fee_amount', 'bridge_transaction_fee']);
        $biuFee = $this->ledgerMetaFloat($meta, [
            'biu_fee', 'biu_amount', 'biu', 'believe_biu_fee',
            'platform_fee', 'platform_fee_amount', 'platform_fee_usd',
        ]);
        $splitDed = $this->ledgerMetaFloat($meta, [
            'split_deduction', 'split_amount', 'allocation_deduction', 'care_alliance_split_amount', 'member_pool_deduction',
        ]);

        $feeCol = (float) $t->fee;
        if ($stripeFee <= 0 && $feeCol > 0 && $bridgeFee <= 0) {
            if ($biuFee > 0 && $feeCol > $biuFee) {
                $stripeFee = round($feeCol - $biuFee, 2);
            } elseif ($biuFee <= 0) {
                $stripeFee = $feeCol;
            }
        }

        $refund = 0.0;
        if ($t->type === 'refund') {
            $refund = (float) $t->amount;
        } else {
            $refund = $this->ledgerMetaFloat($meta, ['refund_amount', 'refunded_amount', 'refund_total']);
        }

        $netMeta = $this->ledgerMetaFloat($meta, [
            'net_to_organization', 'net_amount', 'net', 'org_net', 'amount_net', 'nonprofit_net', 'organization_net',
        ]);
        $hasFeeBreakdown = $stripeFee > 0 || $bridgeFee > 0 || $biuFee > 0 || $splitDed > 0;
        if ($netMeta > 0) {
            $net = $netMeta;
        } elseif ($hasFeeBreakdown) {
            $net = $gross - $stripeFee - $bridgeFee - $biuFee - $splitDed;
        } else {
            $net = (float) $t->amount;
        }

        // Believe donation: Stripe card/ACH — gross/net depend on whether the donor covered processing fees.
        if ($donationPayload !== null && ($donationPayload['kind'] ?? '') === 'donation') {
            $feeEst = (float) ($donationPayload['processing_fee_estimate'] ?? 0);
            $giftToOrg = (float) ($donationPayload['amount_raw'] ?? $t->amount);
            $donorCovers = (bool) ($donationPayload['donor_covers_processing_fees'] ?? false);
            $pm = (string) ($donationPayload['payment_method'] ?? '');
            // Org absorbs: if no stored estimate and no fee on the row, derive the same way as checkout (card vs ACH).
            if ($this->donationPaymentUsesStripeFees($pm) && ! $donorCovers && $feeEst <= 0 && $giftToOrg > 0) {
                $feeRail = (string) ($meta['fee_rail'] ?? $meta['donation_fee_rail'] ?? '');
                $useAch = $pm === 'us_bank_account' || $feeRail === 'bank';
                $feeEst = $useAch
                    ? DonationProcessingFeeEstimator::estimateAchFeeOnChargeUsd($giftToOrg)
                    : DonationProcessingFeeEstimator::estimateCardFeeOnChargeUsd($giftToOrg);
            }
            if ($feeEst > 0 && $stripeFee <= 0) {
                $stripeFee = $feeEst;
                $hasFeeBreakdown = $stripeFee > 0 || $bridgeFee > 0 || $biuFee > 0 || $splitDed > 0;
            }
            $checkout = $donationPayload['checkout_total'] ?? null;
            $checkoutF = $checkout !== null ? (float) $checkout : 0.0;

            if ($this->donationPaymentUsesStripeFees($pm)) {
                if ($donorCovers) {
                    // Donor pays gift + processing: gross = total charged; net = pay amount minus fees (gift to org).
                    if ($checkoutF > 0) {
                        $gross = round($checkoutF, 2);
                    } elseif ($feeEst > 0) {
                        $gross = round($giftToOrg + $feeEst, 2);
                    } else {
                        $gross = round(max($gross, $giftToOrg), 2);
                    }
                    $feeTotal = $stripeFee + $bridgeFee + $biuFee + $splitDed;
                    if ($feeTotal <= 0 && $gross > $giftToOrg + 0.005) {
                        $stripeFee = round($gross - $giftToOrg, 2);
                        $feeTotal = $stripeFee + $bridgeFee + $biuFee + $splitDed;
                    }
                    if ($feeTotal > 0) {
                        $net = round(max(0.0, $gross - $stripeFee - $bridgeFee - $biuFee - $splitDed), 2);
                    } else {
                        $net = round($giftToOrg, 2);
                    }
                } else {
                    // Org absorbs processing: donor pays the gift amount; NET = donation amount − processing fee (− other fees if any).
                    if ($checkoutF > 0) {
                        $gross = round($checkoutF, 2);
                    } else {
                        $gross = round($giftToOrg, 2);
                    }
                    $net = round(max(0.0, $giftToOrg - $stripeFee - $bridgeFee - $biuFee - $splitDed), 2);
                }
            } else {
                // Believe Points / other rails: face gift only on the ledger (no Stripe gross-up).
                if ($checkoutF > 0) {
                    $gross = round($checkoutF, 2);
                } elseif ($feeEst > 0) {
                    $gross = round($giftToOrg + $feeEst, 2);
                }
                $net = round($giftToOrg, 2);
            }
        }

        $platformPayout = null;
        if ($this->isBelievePointsPurchaseLedgerRow($t)) {
            $bpFinancials = $this->mergeBelievePointsPurchaseLedgerFinancials($t, $meta, [
                'gross_amount' => round($gross, 2),
                'stripe_fee' => round($stripeFee, 2),
                'bridge_fee' => round($bridgeFee, 2),
                'biu_fee' => round($biuFee, 2),
                'split_deduction' => round($splitDed, 2),
                'refund_amount' => round($refund, 2),
                'net_to_organization' => round($net, 2),
            ], $feeCol);
            $gross = $bpFinancials['gross_amount'];
            $stripeFee = $bpFinancials['stripe_fee'];
            $biuFee = $bpFinancials['biu_fee'];
            $net = $bpFinancials['net_to_organization'];
            $platformPayout = $bpFinancials['platform_payout'] ?? null;
        }

        $payout = null;
        foreach (['payout_status', 'settlement_status', 'bridge_payout_status', 'payout_state', 'transfer_status'] as $k) {
            if (! empty($meta[$k])) {
                $v = $meta[$k];
                $payout = is_scalar($v) ? (string) $v : json_encode($v);

                break;
            }
        }
        if ($payout === null) {
            $payout = match ($t->status) {
                Transaction::STATUS_PENDING => 'pending',
                Transaction::STATUS_COMPLETED => 'completed',
                Transaction::STATUS_FAILED => 'failed',
                default => $t->status,
            };
        }

        $out = [
            'gross_amount' => round($gross, 2),
            'stripe_fee' => round($stripeFee, 2),
            'bridge_fee' => round($bridgeFee, 2),
            'biu_fee' => round($biuFee, 2),
            'split_deduction' => round($splitDed, 2),
            'refund_amount' => round($refund, 2),
            'net_to_organization' => round($net, 2),
            'payout_status' => $payout,
        ];
        if ($platformPayout !== null && $platformPayout > 0) {
            $out['platform_payout'] = round($platformPayout, 2);
        }

        if (
            ($donationPayload === null || ($donationPayload['kind'] ?? '') !== 'donation')
            && $t->type !== 'refund'
            && $t->related_id !== null
            && (int) $t->related_id > 0
        ) {
            $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
            $relatedBasename = $rt !== '' ? class_basename($rt) : '';
            $isOrder = $rt === Order::class || $relatedBasename === 'Order';
            $isServiceOrder = $rt === ServiceOrder::class || str_ends_with($rt, 'ServiceOrder');
            $isEnrollment = $rt === Enrollment::class || str_ends_with($rt, 'Enrollment');
            $isGiftCard = $rt === GiftCard::class || str_ends_with($rt, 'GiftCard');
            if ($isOrder) {
                $order = Order::query()->with('orderSplit')->find((int) $t->related_id);
                if ($order) {
                    $out = MarketplaceOrderLedgerService::mergeLedgerFinancials($order, $t, $out);
                }
            } elseif ($isServiceOrder) {
                $serviceOrder = ServiceOrder::query()->find((int) $t->related_id);
                if ($serviceOrder) {
                    $out = ServiceOrderLedgerService::mergeLedgerFinancials($serviceOrder, $t, $out);
                }
            } elseif ($isEnrollment || ($meta['source'] ?? '') === 'course_enrollment') {
                $enrollmentDbId = (int) ($t->related_id ?: ($meta['enrollment_record_id'] ?? 0));
                if ($enrollmentDbId > 0) {
                    $enrollment = Enrollment::query()->with('course.creator', 'course.organization.organization')->find($enrollmentDbId);
                    if ($enrollment !== null) {
                        $out = EnrollmentLedgerService::mergeLedgerFinancials($enrollment, $t, $out);
                    }
                }
            } elseif ($isGiftCard) {
                $giftCard = GiftCard::query()->with('organization')->find((int) $t->related_id);
                if ($giftCard) {
                    $out = GiftCardLedgerService::mergeLedgerFinancials($giftCard, $t, $out);
                }
            }
        }

        // Marketplace rows sometimes only store order_id in meta (no polymorphic related_type). Still apply workbook merge.
        if (
            ($donationPayload === null || ($donationPayload['kind'] ?? '') !== 'donation')
            && $t->type !== 'refund'
            && ! array_key_exists('organization_payout', $out)
            && ! empty($meta['order_id'])
            && empty($meta['donation_id'])
        ) {
            $oid = (int) $meta['order_id'];
            if ($oid > 0) {
                $order = Order::query()->with('orderSplit')->find($oid);
                if ($order) {
                    $out = MarketplaceOrderLedgerService::mergeLedgerFinancials($order, $t, $out);
                }
            }
        }

        $sourceType = $this->resolveLedgerSourceType($t, $donationPayload);
        $out = $this->applyLedgerSellingPayoutsFromMeta($t, $sourceType, $out);

        if ($donationPayload !== null && ($donationPayload['kind'] ?? '') === 'donation') {
            $out['organization_payout'] = $out['net_to_organization'];
        }

        return $out;
    }

    /**
     * Fill supplier / org / platform payout from transaction meta when not merged from Order/ServiceOrder (e.g. gift cards, enrollments).
     *
     * @param  array<string, mixed>  $fin
     * @return array<string, mixed>
     */
    private function applyLedgerSellingPayoutsFromMeta(Transaction $t, string $sourceType, array $fin): array
    {
        $selling = [
            'order', 'service_order', 'gift_card', 'raffle', 'enrollment',
            'merchant_hub_redemption', 'merchant_hub_referral',
        ];
        if (! in_array($sourceType, $selling, true)) {
            return $fin;
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        $map = [
            'supplier_payout' => ['supplier_payout', 'merchant_payout'],
            'organization_payout' => ['organization_payout'],
            'platform_payout' => ['platform_payout'],
            'supporter_payout' => ['supporter_payout', 'instructor_payout', 'instructor_settlement', 'creator_payout'],
        ];
        foreach ($map as $key => $metaKeys) {
            if (! array_key_exists($key, $fin) || $fin[$key] === null) {
                $v = $this->ledgerMetaFloat($meta, $metaKeys);
                if ($v > 0) {
                    $fin[$key] = round($v, 2);
                }
            }
        }

        return $fin;
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     * @return array{organization_id: int|null, organization_name: string|null}
     */
    private function ledgerReportOrganization(Transaction $t, ?array $donationPayload): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $oid = (int) ($meta['organization_id'] ?? 0);
        $oname = isset($meta['organization_name']) && is_string($meta['organization_name']) ? $meta['organization_name'] : null;

        $dl = $donationPayload ?? [];
        if (($dl['kind'] ?? '') === 'donation') {
            if ($oid < 1 && ! empty($dl['organization_id'])) {
                $oid = (int) $dl['organization_id'];
            }
            if ($oname === null && ! empty($dl['organization_name']) && is_string($dl['organization_name'])) {
                $oname = $dl['organization_name'];
            }
        }

        if (($oid < 1 || $oname === null) && $t->related_id !== null && (int) $t->related_id > 0 && $t->related_type) {
            $rt = ltrim((string) $t->related_type, '\\');
            if ($rt === Order::class) {
                $order = Order::query()->find((int) $t->related_id);
                if ($order) {
                    $ctx = MarketplaceOrderLedgerService::organizationContextFromOrder($order);
                    if ($oid < 1 && $ctx['organization_id'] !== null) {
                        $oid = (int) $ctx['organization_id'];
                    }
                    if ($oname === null && $ctx['organization_name'] !== null) {
                        $oname = $ctx['organization_name'];
                    }
                }
            }
            if ($rt === GiftCard::class || str_ends_with($rt, 'GiftCard')) {
                $gc = GiftCard::query()->with('organization:id,name')->find((int) $t->related_id);
                if ($gc !== null) {
                    if ($oid < 1 && ! empty($gc->organization_id)) {
                        $oid = (int) $gc->organization_id;
                    }
                    if ($oname === null && $gc->relationLoaded('organization') && $gc->organization !== null) {
                        $oname = (string) $gc->organization->name;
                    }
                }
            }
            if ($rt === Enrollment::class || str_ends_with($rt, 'Enrollment')) {
                $enrollment = Enrollment::query()->with('course.organization.organization')->find((int) $t->related_id);
                if ($enrollment?->course) {
                    $orgCtx = EnrollmentLedgerService::organizationContext($enrollment->course);
                    if ($oid < 1 && ! empty($orgCtx['organization_id'])) {
                        $oid = (int) $orgCtx['organization_id'];
                    }
                    if ($oname === null && ! empty($orgCtx['organization_name'])) {
                        $oname = (string) $orgCtx['organization_name'];
                    }
                }
            }
        }

        // Polymorphic link may be missing but meta still references the course (legacy enrollment rows).
        if (($oid < 1 || $oname === null) && ! empty($meta['course_id']) && is_numeric($meta['course_id'])) {
            $course = Course::query()
                ->with('organization.organization')
                ->find((int) $meta['course_id']);
            if ($course !== null) {
                $orgCtx = EnrollmentLedgerService::organizationContext($course);
                if ($oid < 1 && ! empty($orgCtx['organization_id'])) {
                    $oid = (int) $orgCtx['organization_id'];
                }
                if ($oname === null && ! empty($orgCtx['organization_name'])) {
                    $oname = (string) $orgCtx['organization_name'];
                }
            }
        }

        // Polymorphic link may be missing but meta still references the card (some webhooks / legacy rows).
        if (($oid < 1 || $oname === null) && ! empty($meta['gift_card_id']) && is_numeric($meta['gift_card_id'])) {
            $gc = GiftCard::query()->with('organization:id,name')->find((int) $meta['gift_card_id']);
            if ($gc !== null) {
                if ($oid < 1 && ! empty($gc->organization_id)) {
                    $oid = (int) $gc->organization_id;
                }
                if ($oname === null && $gc->organization) {
                    $oname = (string) $gc->organization->name;
                }
            }
        }

        if ($oid > 0 && $oname === null) {
            $orgRow = Organization::query()->whereKey($oid)->first(['name', 'ein']);
            if ($orgRow !== null) {
                $oname = $orgRow->name;
            }
        }

        $ein = null;
        if ($oid > 0) {
            if (isset($orgRow) && $orgRow !== null) {
                $ein = $orgRow->ein;
            } else {
                $ein = Organization::query()->whereKey($oid)->value('ein');
            }
        }

        return [
            'organization_id' => $oid > 0 ? $oid : null,
            'organization_name' => $oname,
            'organization_ein' => $ein !== null && trim((string) $ein) !== '' ? trim((string) $ein) : null,
        ];
    }

    /**
     * @param  array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string, related_source: string}  $related
     * @param  array<string, mixed>  $donationPayload
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string, related_source: string}
     */
    private function overlayLedgerRelatedWithDonation(Transaction $t, array $related, array $donationPayload): array
    {
        if ($donationPayload['missing'] ?? false) {
            return [
                'related_kind' => 'Donation',
                'related_purpose' => 'Donation id in metadata was referenced but the row is missing.',
                'related_display_name' => 'Donation #'.($donationPayload['donation_id'] ?? '?').' (missing)',
                'related_label' => 'Missing donation',
                'related_source' => 'meta',
            ];
        }

        if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign') {
            $campaign = trim((string) ($donationPayload['campaign_name'] ?? 'Campaign'));
            $caName = trim((string) ($donationPayload['care_alliance_name'] ?? ''));
            $label = $caName !== '' ? $campaign.' · '.$caName : $campaign;

            return [
                'related_kind' => 'Campaign donation',
                'related_purpose' => 'Campaign donation checkout (Unity Impact Alliance). Shows as a gift from the donor’s side; recipient org lines may appear separately.',
                'related_display_name' => $label,
                'related_label' => (string) ($donationPayload['campaign_name'] ?? 'Campaign'),
                'related_source' => 'meta',
            ];
        }

        $org = $donationPayload['organization_name'] ?? '';
        $ca = $donationPayload['care_alliance_name'] ?? '';
        $parts = array_filter([
            $org !== '' ? $org : null,
            $ca !== '' ? $ca : null,
        ]);
        $display = trim(implode(' · ', $parts));
        if ($display === '') {
            $display = 'Donation #'.($donationPayload['id'] ?? '');
        }

        $perspective = $this->resolveDonationLedgerPerspective($t, $donationPayload);
        $purpose = match ($perspective) {
            'donor' => 'Donation sent: payment from the donor (card, points, or subscription). Audit row may not change wallet balance.',
            'recipient_direct' => 'Donation received: credit to this nonprofit’s wallet from the gift.',
            'recipient_split' => 'Donation received: member share of a Unity Impact Alliance–distributed gift.',
            'alliance_fee' => 'Unity Impact Alliance fee share from a distributed donation (credited per alliance rules).',
            default => 'Believe donation (Stripe payment, points, or Unity Impact Alliance distribution).',
        };

        return [
            'related_kind' => 'Donation',
            'related_purpose' => $purpose,
            'related_display_name' => $display,
            'related_label' => $org !== '' ? $org : ('Donation #'.($donationPayload['id'] ?? '')),
            'related_source' => 'meta',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function otherLedgerTransactionsForUser(?int $userId, int $excludeId): array
    {
        if (! $userId) {
            return [];
        }

        return Transaction::query()
            ->where('user_id', $userId)
            ->where('id', '!=', $excludeId)
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Transaction $row) => [
                'id' => $row->id,
                'transaction_id' => $row->transaction_id,
                'type' => $row->type,
                'status' => $row->status,
                'amount' => (float) $row->amount,
                'currency' => $row->currency,
                'created_at' => $row->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cashierSubscriptionsForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        try {
            return $user->subscriptions()
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => (string) ($s->type ?? 'default'),
                    'stripe_id' => $s->stripe_id,
                    'stripe_status' => $s->stripe_status,
                    'dashboard_url' => $s->stripe_id
                        ? 'https://dashboard.stripe.com/subscriptions/'.$s->stripe_id
                        : null,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStripePresentation(Transaction $t): array
    {
        $identifiers = $this->collectStripeIdentifiers($t);

        $out = [
            'customer_id' => $t->user?->stripe_id,
            'customer_dashboard_url' => $this->stripeCustomerDashboardUrl($t->user?->stripe_id),
            'payment_intent' => null,
            'checkout_session' => null,
            'charge' => null,
            'subscription' => null,
            'payment_intent_dashboard_url' => null,
            'identifiers_found' => $identifiers,
            'fetch_error' => null,
        ];

        $hasIds = $identifiers['payment_intent_ids'] !== []
            || $identifiers['session_ids'] !== []
            || $identifiers['charge_ids'] !== []
            || $identifiers['subscription_ids'] !== [];

        if (! $hasIds) {
            return $out;
        }

        try {
            $stripe = Cashier::stripe();

            if ($identifiers['payment_intent_ids'] !== []) {
                $pi = $stripe->paymentIntents->retrieve($identifiers['payment_intent_ids'][0]);
                $out['payment_intent'] = $this->serializeStripePaymentIntent($pi);
                $out['payment_intent_dashboard_url'] = $this->stripePaymentIntentDashboardUrl($pi->id);
            } elseif ($identifiers['session_ids'] !== []) {
                $cs = $stripe->checkout->sessions->retrieve($identifiers['session_ids'][0]);
                $out['checkout_session'] = $this->serializeStripeCheckoutSession($cs);
                if (! empty($cs->payment_intent) && is_string($cs->payment_intent)) {
                    $pi = $stripe->paymentIntents->retrieve($cs->payment_intent);
                    $out['payment_intent'] = $this->serializeStripePaymentIntent($pi);
                    $out['payment_intent_dashboard_url'] = $this->stripePaymentIntentDashboardUrl($pi->id);
                }
            } elseif ($identifiers['charge_ids'] !== []) {
                $ch = $stripe->charges->retrieve($identifiers['charge_ids'][0]);
                $out['charge'] = $this->serializeStripeCharge($ch);
                if (! empty($ch->payment_intent) && is_string($ch->payment_intent)) {
                    $pi = $stripe->paymentIntents->retrieve($ch->payment_intent);
                    $out['payment_intent'] = $this->serializeStripePaymentIntent($pi);
                    $out['payment_intent_dashboard_url'] = $this->stripePaymentIntentDashboardUrl($pi->id);
                }
            } elseif ($identifiers['subscription_ids'] !== []) {
                $sub = $stripe->subscriptions->retrieve($identifiers['subscription_ids'][0]);
                $out['subscription'] = $this->serializeStripeSubscription($sub);
            }
        } catch (\Throwable $e) {
            $out['fetch_error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * @return array{payment_intent_ids: array<int, string>, session_ids: array<int, string>, charge_ids: array<int, string>, subscription_ids: array<int, string>}
     */
    private function collectStripeIdentifiers(Transaction $t): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $pi = [];
        $cs = [];
        $ch = [];
        $sub = [];

        foreach (['stripe_payment_intent', 'stripe_payment_intent_id', 'payment_intent', 'original_payment_intent'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k]) && str_starts_with($meta[$k], 'pi_')) {
                $pi[] = $meta[$k];
            }
        }

        if (! empty($meta['stripe_session_id']) && is_string($meta['stripe_session_id'])) {
            $cs[] = $meta['stripe_session_id'];
        }

        $tid = (string) ($t->transaction_id ?? '');
        if (str_starts_with($tid, 'pi_')) {
            $pi[] = $tid;
        }
        if (str_starts_with($tid, 'cs_')) {
            $cs[] = $tid;
        }
        if (str_starts_with($tid, 'ch_')) {
            $ch[] = $tid;
        }
        if (str_starts_with($tid, 'sub_')) {
            $sub[] = $tid;
        }

        $this->mergeStripeIdentifiersFromDonation($t, $pi, $cs, $ch, $sub);
        $this->mergeStripeIdentifiersFromCareAllianceDonationMeta($t, $pi, $cs, $ch, $sub);

        return [
            'payment_intent_ids' => array_values(array_unique($pi)),
            'session_ids' => array_values(array_unique($cs)),
            'charge_ids' => array_values(array_unique($ch)),
            'subscription_ids' => array_values(array_unique($sub)),
        ];
    }

    /**
     * @param  array<int, string>  $pi
     * @param  array<int, string>  $cs
     * @param  array<int, string>  $ch
     * @param  array<int, string>  $sub
     */
    private function mergeStripeIdentifiersFromDonation(Transaction $t, array &$pi, array &$cs, array &$ch, array &$sub): void
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $donationId = $this->extractMetaDonationId($meta);
        if (! $donationId && $t->related_type === Donation::class && $t->related_id) {
            $donationId = (int) $t->related_id;
        }
        if ($donationId) {
            $d = Donation::query()->find($donationId);
            if ($d && ! empty($d->transaction_id)) {
                $this->pushStripeRefFromString((string) $d->transaction_id, $pi, $cs, $ch, $sub);
            }

            return;
        }

        foreach ($this->stripePaymentIntentCandidatesFromTransaction($t, $meta) as $candidate) {
            $d = Donation::query()->where('transaction_id', $candidate)->first();
            if ($d && ! empty($d->transaction_id)) {
                $this->pushStripeRefFromString((string) $d->transaction_id, $pi, $cs, $ch, $sub);
            }
        }

        $inferred = $this->inferDonationFromCareAllianceSplit($t);
        if ($inferred && ! empty($inferred->transaction_id)) {
            $this->pushStripeRefFromString((string) $inferred->transaction_id, $pi, $cs, $ch, $sub);
        }
    }

    /**
     * @param  array<int, string>  $pi
     * @param  array<int, string>  $cs
     * @param  array<int, string>  $ch
     * @param  array<int, string>  $sub
     */
    private function mergeStripeIdentifiersFromCareAllianceDonationMeta(Transaction $t, array &$pi, array &$cs, array &$ch, array &$sub): void
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        if (empty($meta['care_alliance_donation_id'])) {
            return;
        }
        $cad = CareAllianceDonation::query()->find((int) $meta['care_alliance_donation_id']);
        if (! $cad || empty($cad->payment_reference)) {
            return;
        }
        $this->pushStripeRefFromString((string) $cad->payment_reference, $pi, $cs, $ch, $sub);
    }

    /**
     * @param  array<int, string>  $pi
     * @param  array<int, string>  $cs
     * @param  array<int, string>  $ch
     * @param  array<int, string>  $sub
     */
    private function pushStripeRefFromString(string $ref, array &$pi, array &$cs, array &$ch, array &$sub): void
    {
        if (str_starts_with($ref, 'pi_')) {
            $pi[] = $ref;
        } elseif (str_starts_with($ref, 'cs_')) {
            $cs[] = $ref;
        } elseif (str_starts_with($ref, 'ch_')) {
            $ch[] = $ref;
        } elseif (str_starts_with($ref, 'sub_')) {
            $sub[] = $ref;
        }
    }

    private function stripeCustomerDashboardUrl(?string $customerId): ?string
    {
        if (! $customerId || ! str_starts_with($customerId, 'cus_')) {
            return null;
        }

        return 'https://dashboard.stripe.com/customers/'.$customerId;
    }

    private function stripePaymentIntentDashboardUrl(string $paymentIntentId): string
    {
        return 'https://dashboard.stripe.com/payments/'.$paymentIntentId;
    }

    /**
     * @param  \Stripe\PaymentIntent  $pi
     * @return array<string, mixed>
     */
    private function serializeStripePaymentIntent(object $pi): array
    {
        $amount = (int) ($pi->amount ?? 0);

        return [
            'id' => $pi->id,
            'amount_cents' => $amount,
            'amount_display' => number_format($amount / 100, 2, '.', ''),
            'currency' => strtoupper((string) ($pi->currency ?? '')),
            'status' => $pi->status,
            'created' => isset($pi->created) ? Carbon::createFromTimestamp($pi->created)->toIso8601String() : null,
            'description' => $pi->description ?? null,
            'livemode' => $pi->livemode ?? null,
        ];
    }

    /**
     * @param  \Stripe\Checkout\Session  $cs
     * @return array<string, mixed>
     */
    private function serializeStripeCheckoutSession(object $cs): array
    {
        $total = (int) ($cs->amount_total ?? 0);

        return [
            'id' => $cs->id,
            'amount_total_cents' => $total,
            'amount_total_display' => number_format($total / 100, 2, '.', ''),
            'currency' => strtoupper((string) ($cs->currency ?? '')),
            'payment_status' => $cs->payment_status,
            'status' => $cs->status ?? null,
            'created' => isset($cs->created) ? Carbon::createFromTimestamp($cs->created)->toIso8601String() : null,
            'payment_intent_id' => $cs->payment_intent ?? null,
            'customer' => $cs->customer ?? null,
            'livemode' => $cs->livemode ?? null,
        ];
    }

    /**
     * @param  \Stripe\Charge  $ch
     * @return array<string, mixed>
     */
    private function serializeStripeCharge(object $ch): array
    {
        $amount = (int) ($ch->amount ?? 0);

        return [
            'id' => $ch->id,
            'amount_cents' => $amount,
            'amount_display' => number_format($amount / 100, 2, '.', ''),
            'currency' => strtoupper((string) ($ch->currency ?? '')),
            'status' => $ch->status,
            'paid' => $ch->paid ?? null,
            'created' => isset($ch->created) ? Carbon::createFromTimestamp($ch->created)->toIso8601String() : null,
        ];
    }

    /**
     * @param  \Stripe\Subscription  $subscription
     * @return array<string, mixed>
     */
    private function serializeStripeSubscription(object $subscription): array
    {
        $items = $subscription->items->data ?? [];
        $firstItem = is_array($items) && $items !== [] ? $items[0] : null;
        $price = $firstItem && isset($firstItem->price) ? $firstItem->price : null;

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'currency' => strtoupper((string) ($subscription->currency ?? '')),
            'customer' => $subscription->customer ?? null,
            'current_period_end' => isset($subscription->current_period_end)
                ? Carbon::createFromTimestamp($subscription->current_period_end)->toIso8601String()
                : null,
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? null,
            'price_id' => $price->id ?? null,
            'unit_amount_cents' => isset($price->unit_amount) ? (int) $price->unit_amount : null,
            'unit_amount_display' => isset($price->unit_amount)
                ? number_format(((int) $price->unit_amount) / 100, 2, '.', '')
                : null,
        ];
    }

    /**
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string, related_source: string}
     */
    private function resolveRelatedDetails(?string $relatedType, mixed $relatedId, ?array $meta): array
    {
        $fromMeta = $this->resolveRelatedDetailsFromMeta($meta);

        if (! $relatedType || $relatedId === null || $relatedId === '') {
            if ($fromMeta !== null) {
                return array_merge($fromMeta, ['related_source' => 'meta']);
            }

            return $this->emptyRelated();
        }

        if (! str_contains($relatedType, '\\')) {
            $resolved = $this->resolveRelatedDetailsForStringType($relatedType, $relatedId);

            return $this->applyMetaOverlayWhenWeak($resolved, $fromMeta, 'polymorphic');
        }

        $class = ltrim($relatedType, '\\');
        if (! class_exists($class)) {
            $short = class_basename($relatedType);
            $resolved = [
                'related_kind' => 'Unknown type',
                'related_purpose' => 'The stored related_type is not a loadable PHP class. Inspect the raw related_type column and metadata to interpret this row.',
                'related_display_name' => $short.' #'.$relatedId,
                'related_label' => $short.' #'.$relatedId,
            ];

            return $this->applyMetaOverlayWhenWeak($resolved, $fromMeta, 'polymorphic');
        }

        $kindPurpose = $this->relatedKindAndPurposeForClass($class);
        $model = $this->loadRelatedModel($class, $relatedId);
        $basename = class_basename($class);
        $displayName = $this->relatedDisplayNameFromModel($model, $basename, $relatedId);

        $resolved = [
            'related_kind' => $kindPurpose['kind'],
            'related_purpose' => $kindPurpose['purpose'],
            'related_display_name' => $displayName,
            'related_label' => $displayName,
        ];

        if ($model === null && $fromMeta !== null) {
            return [
                'related_kind' => $resolved['related_kind'],
                'related_purpose' => $resolved['related_purpose'].' Metadata also describes: '.$fromMeta['related_display_name'].'.',
                'related_display_name' => $fromMeta['related_display_name'],
                'related_label' => $fromMeta['related_label'],
                'related_source' => 'meta',
            ];
        }

        if ($this->displayNameLooksMissing($displayName) && $fromMeta !== null) {
            return $this->applyMetaOverlayWhenWeak($resolved, $fromMeta, 'polymorphic');
        }

        return array_merge($resolved, ['related_source' => 'polymorphic']);
    }

    /**
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string}
     */
    private function resolveRelatedDetailsFromMeta(?array $meta): ?array
    {
        if ($meta === null || $meta === []) {
            return null;
        }

        $m = $meta;

        $donationIdFromMeta = $this->extractMetaDonationId($m);
        if ($donationIdFromMeta !== null && $donationIdFromMeta > 0) {
            $d = Donation::query()->with(['organization:id,name'])->find($donationIdFromMeta);
            if ($d) {
                $org = $d->organization?->name ?? ('Organization #'.$d->organization_id);
                $purpose = $d->care_alliance_id
                    ? 'Believe donation (Unity Impact Alliance general give). Recipient credit or split lines follow alliance financial rules when configured.'
                    : 'Believe donation to a nonprofit (direct recipient credit, or Believe Points).';

                return [
                    'related_kind' => 'Donation',
                    'related_purpose' => $purpose,
                    'related_display_name' => $org,
                    'related_label' => 'Donation #'.$d->id,
                ];
            }

            return [
                'related_kind' => 'Donation',
                'related_purpose' => 'Metadata references a donation id that could not be loaded.',
                'related_display_name' => 'Donation #'.$donationIdFromMeta.' (missing)',
                'related_label' => 'Donation #'.$donationIdFromMeta,
            ];
        }

        if (! empty($m['care_alliance_donation_id'])) {
            $cad = CareAllianceDonation::query()
                ->with(['campaign.careAlliance'])
                ->find((int) $m['care_alliance_donation_id']);
            if ($cad) {
                $camp = $cad->campaign?->name ?? 'Campaign';
                $alliance = $cad->campaign?->careAlliance?->name;
                $label = $camp.($alliance !== null ? ' · '.$alliance : '');

                return [
                    'related_kind' => 'Campaign donation',
                    'related_purpose' => 'Unity Impact Alliance campaign checkout donation (care_alliance_donations).',
                    'related_display_name' => $label,
                    'related_label' => $camp,
                ];
            }
        }

        if (($m['source'] ?? '') === 'care_alliance_split' && ! empty($m['care_alliance_id'])) {
            $aid = (int) $m['care_alliance_id'];
            $alliance = CareAlliance::query()->find($aid);
            $name = is_string($m['care_alliance_name'] ?? null)
                ? (string) $m['care_alliance_name']
                : ($alliance?->name ?? ('Alliance #'.$aid));
            $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : null;
            $roleLabel = $role === 'alliance_fee'
                ? 'Alliance fee'
                : ($role === 'member_share' ? 'Member share' : ($role ?? 'split'));

            return [
                'related_kind' => 'Donation',
                'related_purpose' => $role === 'alliance_fee'
                    ? 'Wallet credit from a Believe donation: Unity Impact Alliance fee share (split distribution).'
                    : ($role === 'member_share'
                        ? 'Wallet credit from a Believe donation: member nonprofit share (Unity Impact Alliance split).'
                        : 'Wallet credit from a Believe donation (Unity Impact Alliance split; see role in metadata).'),
                'related_display_name' => $name.' · '.$roleLabel,
                'related_label' => $name,
            ];
        }

        foreach ($this->stripePaymentIntentCandidatesFromMetaArray($m) as $pi) {
            $d = Donation::query()->with(['organization:id,name'])->where('transaction_id', $pi)->first();
            if ($d) {
                $org = $d->organization?->name ?? ('Organization #'.$d->organization_id);
                $purpose = $d->care_alliance_id
                    ? 'Believe donation matched by Stripe PaymentIntent (Unity Impact Alliance general give may apply).'
                    : 'Believe donation matched by Stripe PaymentIntent (normal nonprofit gift).';

                return [
                    'related_kind' => 'Donation',
                    'related_purpose' => $purpose,
                    'related_display_name' => $org,
                    'related_label' => 'Donation #'.$d->id,
                ];
            }
        }

        if (! empty($m['care_alliance_name']) && is_string($m['care_alliance_name'])) {
            $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : null;
            $cid = $m['care_alliance_id'] ?? null;
            $label = $m['care_alliance_name'].($cid !== null ? ' · alliance #'.$cid : '');

            return [
                'related_kind' => 'Donation',
                'related_purpose' => $role === 'alliance_fee'
                    ? 'Donation-related wallet movement: Unity Impact Alliance fee context in metadata.'
                    : ($role === 'member_share'
                        ? 'Donation-related wallet movement: member share (Unity Impact Alliance context in metadata).'
                        : 'Donation-related wallet movement (Unity Impact Alliance name in metadata).'),
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['course_name']) && is_string($m['course_name'])) {
            $cid = $m['course_id'] ?? null;
            $label = $m['course_name'].($cid !== null ? ' · course #'.$cid : '');

            return [
                'related_kind' => 'Course (metadata)',
                'related_purpose' => 'Course enrollment or tuition context stored in meta when the polymorphic link may be absent or redundant.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['plan_name']) && is_string($m['plan_name'])) {
            $pid = $m['plan_id'] ?? null;
            $label = 'Plan: '.$m['plan_name'].($pid !== null ? ' · #'.$pid : '');

            return [
                'related_kind' => 'Subscription plan (metadata)',
                'related_purpose' => 'Plan subscription details captured at checkout (name, frequency, add-ons).',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['plan_id']) && is_numeric($m['plan_id'])) {
            $plan = Plan::query()->find((int) $m['plan_id']);
            if ($plan) {
                $label = 'Plan: '.$plan->name;

                return [
                    'related_kind' => 'Subscription plan (metadata)',
                    'related_purpose' => 'Resolved plan from plan_id in metadata.',
                    'related_display_name' => $label,
                    'related_label' => $label,
                ];
            }
        }

        if (! empty($m['raffle_id']) && is_numeric($m['raffle_id'])) {
            $rid = (int) $m['raffle_id'];
            $raffle = Raffle::query()->find($rid);
            $title = $raffle && ($raffle->title ?? null) ? (string) $raffle->title : 'Raffle #'.$rid;

            return [
                'related_kind' => 'Raffle tickets',
                'related_purpose' => 'Raffle context from metadata (ticket quantity may also be in meta).',
                'related_display_name' => $title,
                'related_label' => $title,
            ];
        }

        if (! empty($m['order_id']) && is_numeric($m['order_id'])) {
            $oid = (int) $m['order_id'];
            $order = Order::query()->find($oid);
            $label = $order && $order->reference_number
                ? 'Order '.$order->reference_number
                : 'Order #'.$oid;

            return [
                'related_kind' => 'Marketplace order (metadata)',
                'related_purpose' => 'Order reference taken from metadata (e.g. Believe Points checkout sidecar).',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['gift_card_id']) || ! empty($m['brand'])) {
            $gid = $m['gift_card_id'] ?? null;
            $brand = isset($m['brand']) && is_string($m['brand']) ? $m['brand'] : null;
            $gc = is_numeric($gid) ? GiftCard::query()->find((int) $gid) : null;
            $parts = array_filter([
                $brand,
                $gc ? ($gc->brand_name ?? $gc->brand) : null,
                $gid !== null ? 'Gift card #'.$gid : null,
            ]);
            $label = $parts !== [] ? implode(' · ', $parts) : 'Gift card';

            return [
                'related_kind' => 'Gift card (metadata)',
                'related_purpose' => 'Gift card purchase or refund context from Phaze/Stripe metadata.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['bridge_transfer_id'])) {
            $tid = (string) $m['bridge_transfer_id'];
            $extra = isset($m['recipient_name']) ? ' · to '.$m['recipient_name'] : '';
            $extra .= isset($m['sender_name']) ? ' · from '.$m['sender_name'] : '';

            return [
                'related_kind' => 'Bridge transfer',
                'related_purpose' => 'On/off-ramp or internal Bridge movement; use bridge_transfer_id with Bridge tools.',
                'related_display_name' => 'Transfer '.$tid.$extra,
                'related_label' => 'Bridge '.$tid,
            ];
        }

        if (! empty($m['offer_id']) || ! empty($m['receipt_code'])) {
            $bits = array_filter([
                isset($m['receipt_code']) ? 'Receipt '.$m['receipt_code'] : null,
                isset($m['offer_id']) ? 'Offer #'.$m['offer_id'] : null,
            ]);
            $label = $bits !== [] ? implode(' · ', $bits) : 'Merchant offer';

            return [
                'related_kind' => 'Merchant Hub (metadata)',
                'related_purpose' => 'Offer redemption context stored alongside the transaction.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['recipient_email']) || ! empty($m['recipient_name'])) {
            $name = trim((string) ($m['recipient_name'] ?? ''));
            $email = trim((string) ($m['recipient_email'] ?? ''));
            $rid = $m['recipient_id'] ?? null;
            $label = $name !== '' ? $name : 'Recipient';
            if ($email !== '') {
                $label .= ' · '.$email;
            }
            if ($rid !== null && $rid !== '') {
                $label .= ' · #'.$rid;
            }

            return [
                'related_kind' => 'Wallet transfer (recipient)',
                'related_purpose' => 'Peer or org wallet send: counterparty from metadata.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['sender_email']) || ! empty($m['sender_name'])) {
            $name = trim((string) ($m['sender_name'] ?? ''));
            $email = trim((string) ($m['sender_email'] ?? ''));
            $sid = $m['sender_id'] ?? null;
            $label = $name !== '' ? $name : 'Sender';
            if ($email !== '') {
                $label .= ' · '.$email;
            }
            if ($sid !== null && $sid !== '') {
                $label .= ' · #'.$sid;
            }

            return [
                'related_kind' => 'Wallet transfer (sender)',
                'related_purpose' => 'Incoming transfer: sender details from metadata.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['sender_organization_name'])) {
            $label = (string) $m['sender_organization_name'];
            if (! empty($m['sender_organization_id'])) {
                $label .= ' · org #'.$m['sender_organization_id'];
            }

            return [
                'related_kind' => 'Organization wallet',
                'related_purpose' => 'Org-to-user or org transfer context from metadata.',
                'related_display_name' => $label,
                'related_label' => $label,
            ];
        }

        if (! empty($m['description']) && is_string($m['description'])) {
            $d = trim($m['description']);

            return [
                'related_kind' => 'Checkout note',
                'related_purpose' => 'Human-readable description saved on the transaction (often from Stripe or internal checkout).',
                'related_display_name' => Str::limit($d, 160),
                'related_label' => Str::limit($d, 80),
            ];
        }

        $hints = $this->compactMetaHints($m);
        if ($hints !== null) {
            return [
                'related_kind' => 'Metadata hints',
                'related_purpose' => 'No polymorphic link; we surfaced a short summary from scalar fields in the JSON payload.',
                'related_display_name' => $hints,
                'related_label' => Str::limit($hints, 120),
            ];
        }

        return null;
    }

    /**
     * @param  array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string}  $resolved
     * @param  array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string}|null  $fromMeta
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string, related_source: string}
     */
    private function applyMetaOverlayWhenWeak(array $resolved, ?array $fromMeta, string $baseSource): array
    {
        if ($fromMeta === null) {
            return array_merge($resolved, ['related_source' => $baseSource]);
        }

        if ($this->displayNameLooksMissing($resolved['related_display_name'])) {
            return [
                'related_kind' => $resolved['related_kind'],
                'related_purpose' => $resolved['related_purpose'].' Also from metadata: '.$fromMeta['related_display_name'].'.',
                'related_display_name' => $fromMeta['related_display_name'],
                'related_label' => $fromMeta['related_label'],
                'related_source' => 'meta',
            ];
        }

        return array_merge($resolved, ['related_source' => $baseSource]);
    }

    private function displayNameLooksMissing(string $displayName): bool
    {
        return str_contains($displayName, '(record missing)') || str_contains($displayName, '(missing)');
    }

    /**
     * Backfill ledger meta for legacy enrollment rows when the admin ledger is rendered.
     */
    private function maybeSyncEnrollmentLedgerMeta(Transaction $t): void
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        if (($meta['source'] ?? '') === 'course_enrollment'
            && ! empty($meta['organization_id'])
            && ! empty($meta['enrollment_record_id'])
            && self::relatedTypeIsEnrollment($t)) {
            return;
        }

        if (! EnrollmentLedgerService::transactionIsEnrollment($t)) {
            return;
        }

        $enrollment = EnrollmentLedgerService::resolveEnrollmentForLedgerTransaction($t);
        if ($enrollment?->course === null) {
            return;
        }

        EnrollmentLedgerService::syncTransaction($t, $enrollment, $enrollment->course);
        $t->refresh();
    }

    private function relatedTypeIsEnrollment(Transaction $t): bool
    {
        return EnrollmentLedgerService::relatedTypeIsEnrollment($t);
    }

    private function compactMetaHints(array $meta): ?string
    {
        $skip = [
            'stripe_session_id', 'stripe_payment_intent', 'stripe_customer_id', 'stripe_refund_id',
            'stripe_payment_intent_id', 'phaze_order_id', 'phaze_purchase_id', 'phaze_status',
        ];
        $parts = [];
        foreach ($meta as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            if (is_scalar($v) && $v !== '' && ! is_bool($v)) {
                $parts[] = $k.': '.Str::limit((string) $v, 48);
            }
            if (count($parts) >= 5) {
                break;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string, related_source: string}
     */
    private function emptyRelated(): array
    {
        return [
            'related_kind' => '—',
            'related_purpose' => 'No polymorphic link and no recognizable fields in metadata. If JSON exists below, read it for gateway ids, session ids, and custom keys.',
            'related_display_name' => '—',
            'related_label' => '—',
            'related_source' => 'none',
        ];
    }

    /**
     * @return array{related_kind: string, related_purpose: string, related_display_name: string, related_label: string}
     */
    private function resolveRelatedDetailsForStringType(string $type, mixed $relatedId): array
    {
        $id = is_numeric($relatedId) ? (int) $relatedId : $relatedId;

        if ($type === 'raffle') {
            $raffle = Raffle::query()->find($id);
            $name = $raffle && ($raffle->title ?? null)
                ? (string) $raffle->title
                : ('Raffle #'.$id.($raffle ? '' : ' (missing)'));

            return [
                'related_kind' => 'Raffle tickets',
                'related_purpose' => 'Payment for raffle ticket(s). The raffle record holds title, draw schedule, ticket counts, and prize configuration.',
                'related_display_name' => $name,
                'related_label' => $name,
            ];
        }

        return [
            'related_kind' => ucfirst(str_replace('_', ' ', $type)),
            'related_purpose' => 'A custom or legacy related_type marker. Use the related id and metadata JSON to see how this transaction was created.',
            'related_display_name' => $type.' #'.$id,
            'related_label' => $type.' #'.$id,
        ];
    }

    private function loadRelatedModel(string $class, mixed $relatedId): ?Model
    {
        $id = is_numeric($relatedId) ? (int) $relatedId : $relatedId;

        try {
            return match (class_basename($class)) {
                'Enrollment' => $class::query()->with('course')->find($id),
                'MerchantHubOfferRedemption' => $class::query()->with('offer')->find($id),
                default => $class::query()->find($id),
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function relatedDisplayNameFromModel(?Model $model, string $basename, mixed $relatedId): string
    {
        $suffix = is_scalar($relatedId) ? (string) $relatedId : '';

        if (! $model) {
            return $basename.' #'.$suffix.' (record missing)';
        }

        return match ($basename) {
            'Order' => $model->reference_number
                ? 'Order '.$model->reference_number
                : 'Order #'.$model->id,
            'Plan' => $model->name ? 'Plan: '.$model->name : 'Plan #'.$model->id,
            'Enrollment' => $this->enrollmentDisplayName($model),
            'GiftCard' => $this->giftCardDisplayName($model),
            'User' => $model->name ? $model->name.' (user)' : 'User #'.$model->id,
            'Organization' => $model->name ? $model->name.' (organization)' : 'Organization #'.$model->id,
            'MerchantHubOfferRedemption' => $this->merchantRedemptionDisplayName($model),
            'MerchantHubReferralReward' => 'Referral reward #'.$model->id,
            'Raffle' => ($model->title ?? null) ? (string) $model->title : 'Raffle #'.$model->id,
            'Donation' => $this->donationRelatedDisplayName($model),
            default => $basename.' #'.$model->id,
        };
    }

    private function donationRelatedDisplayName(Model $model): string
    {
        if (! $model instanceof Donation) {
            return 'Donation #'.$model->getKey();
        }
        $model->loadMissing('organization:id,name', 'careAlliance:id,name');
        $org = $model->organization?->name;
        $ca = $model->careAlliance?->name;
        $parts = array_filter([
            $org,
            $ca ? 'Unity Impact Alliance: '.$ca : null,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : ('Donation #'.$model->id);
    }

    private function enrollmentDisplayName(Model $enrollment): string
    {
        $courseName = $enrollment->relationLoaded('course') && $enrollment->course
            ? $enrollment->course->name
            : null;

        $parts = array_filter([
            $courseName,
            'Enrollment #'.$enrollment->id,
        ]);

        return implode(' · ', $parts);
    }

    private function giftCardDisplayName(Model $giftCard): string
    {
        $brand = trim((string) ($giftCard->brand_name ?? $giftCard->brand ?? ''));
        $bits = array_filter([
            $brand !== '' ? $brand : null,
            $giftCard->voucher ? 'Code '.$giftCard->voucher : null,
        ]);

        return $bits !== [] ? implode(' · ', $bits) : 'Gift card #'.$giftCard->id;
    }

    private function merchantRedemptionDisplayName(Model $redemption): string
    {
        if ($redemption->relationLoaded('offer') && $redemption->offer && ($redemption->offer->title ?? null)) {
            return (string) $redemption->offer->title;
        }

        if ($redemption->receipt_code) {
            return 'Redemption '.$redemption->receipt_code;
        }

        return 'Merchant redemption #'.$redemption->id;
    }

    /**
     * @return array{kind: string, purpose: string}
     */
    private function relatedKindAndPurposeForClass(string $class): array
    {
        return match ($class) {
            Order::class => [
                'kind' => 'Marketplace order',
                'purpose' => 'Connects this payment to a Believe storefront checkout (line items, shipping, tax, optional donation). Use the order reference in Orders to audit fulfillment and refunds.',
            ],
            Plan::class => [
                'kind' => 'Subscription plan',
                'purpose' => 'Billing for a membership or recurring plan (Stripe product/price on the plan record). Often used for platform access or premium features.',
            ],
            Enrollment::class => [
                'kind' => 'Course enrollment',
                'purpose' => 'Tuition or fee for a course: links the learner, the course schedule, and payment state (active, refunded, etc.).',
            ],
            GiftCard::class => [
                'kind' => 'Gift card',
                'purpose' => 'Gift card purchase or partner flow (e.g. Phaze): ties commission splits and brand metadata to this ledger row.',
            ],
            User::class => [
                'kind' => 'User (counterparty)',
                'purpose' => 'Another Believe member in a wallet transfer—usually the recipient or sender referenced by this side of the transfer pair.',
            ],
            Organization::class => [
                'kind' => 'Organization',
                'purpose' => 'A nonprofit or business profile: common in org wallet sends, transfers, or payouts involving an org account.',
            ],
            MerchantHubOfferRedemption::class => [
                'kind' => 'Merchant Hub redemption',
                'purpose' => 'A user claimed a merchant hub offer (reward points or cash, verified receipt). Links to the offer and merchant workflow.',
            ],
            MerchantHubReferralReward::class => [
                'kind' => 'Merchant referral reward',
                'purpose' => 'Points or wallet value issued when a referral completes an eligible Merchant Hub redemption.',
            ],
            Raffle::class => [
                'kind' => 'Raffle',
                'purpose' => 'Ticket purchase or settlement tied to a raffle campaign (pool, draw date, organization).',
            ],
            Donation::class => [
                'kind' => 'Donation',
                'purpose' => 'Believe donation (card, subscription, or points); may route through Unity Impact Alliance splits.',
            ],
            BelievePointPurchase::class => [
                'kind' => 'Believe Points purchase',
                'purpose' => 'Stripe card or ACH purchase of Believe Points wallet balance; links to gross checkout, fees, and points credited.',
            ],
            default => [
                'kind' => class_basename($class),
                'purpose' => 'Linked polymorphic record of type '.class_basename($class).'. Open that table by id in admin or the database for full detail.',
            ],
        };
    }
}
