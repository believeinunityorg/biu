<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GiftCardStatus;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\GiftCardRedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GiftCardRedemptionController extends Controller
{
    public function __construct(
        private readonly GiftCardRedemptionService $redemptionService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $status = $request->string('status')->toString() ?: GiftCardStatus::PendingFulfillment->value;

        if (! in_array($status, GiftCardStatus::adminQueueStatuses(), true)) {
            $status = GiftCardStatus::PendingFulfillment->value;
        }

        $redemptions = GiftCard::query()
            ->with(['user:id,name,email', 'organization:id,name'])
            ->whereIn('payment_method', ['believe_points', 'bridge_wallet'])
            ->when(
                $status === GiftCardStatus::Completed->value,
                fn ($query) => $query->whereIn('status', [
                    GiftCardStatus::Completed->value,
                    GiftCardStatus::Active->value,
                ]),
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (GiftCard $giftCard) => $this->transformRedemption($giftCard));

        $counts = GiftCard::query()
            ->whereIn('payment_method', ['believe_points', 'bridge_wallet'])
            ->whereIn('status', GiftCardStatus::adminQueueStatuses())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('admin/gift-card-redemptions/Index', [
            'redemptions' => $redemptions,
            'filters' => ['status' => $status],
            'counts' => [
                GiftCardStatus::PendingFulfillment->value => (int) ($counts[GiftCardStatus::PendingFulfillment->value] ?? 0),
                GiftCardStatus::Processing->value => (int) ($counts[GiftCardStatus::Processing->value] ?? 0),
                GiftCardStatus::Completed->value => (int) ($counts[GiftCardStatus::Completed->value] ?? 0)
                    + (int) ($counts[GiftCardStatus::Active->value] ?? 0),
                GiftCardStatus::Failed->value => (int) ($counts[GiftCardStatus::Failed->value] ?? 0),
                GiftCardStatus::CapacityReached->value => (int) ($counts[GiftCardStatus::CapacityReached->value] ?? 0),
            ],
            'statusOptions' => collect(GiftCardStatus::adminQueueStatuses())
                ->map(fn (string $value) => [
                    'value' => $value,
                    'label' => GiftCardStatus::tryFrom($value)?->label() ?? $value,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function retry(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $this->authorizeAdmin($request);

        try {
            $giftCard = $this->redemptionService->queueAdminRetry($giftCard, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['retry' => $e->getMessage()]);
        }

        if (GiftCardStatus::isFulfilled($giftCard->status)) {
            return back()->with('success', 'Gift card redemption fulfilled via Phaze.');
        }

        return back()->withErrors([
            'retry' => $this->adminFailureMessage($giftCard)
                ?: 'Retry did not complete. Check Phaze balance and try again.',
        ]);
    }

    public function forceFulfill(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $this->authorizeAdmin($request);

        try {
            $giftCard = $this->redemptionService->queueAdminForceFulfill($giftCard, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['force_fulfill' => $e->getMessage()]);
        }

        if (GiftCardStatus::isFulfilled($giftCard->status)) {
            return back()->with('success', 'Gift card fulfilled via Phaze.');
        }

        return back()->withErrors([
            'force_fulfill' => $this->adminFailureMessage($giftCard)
                ?: 'Fulfillment did not complete. Check live Phaze balance / API and try again.',
        ]);
    }

    private function adminFailureMessage(GiftCard $giftCard): ?string
    {
        $failureMeta = is_array($giftCard->meta['phaze_fulfillment_failure'] ?? null)
            ? $giftCard->meta['phaze_fulfillment_failure']
            : [];

        foreach (['error_admin', 'error'] as $key) {
            if (is_string($failureMeta[$key] ?? null) && trim($failureMeta[$key]) !== '') {
                return trim($failureMeta[$key]);
            }
        }

        return is_string($giftCard->failure_reason) && trim($giftCard->failure_reason) !== ''
            ? trim($giftCard->failure_reason)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRedemption(GiftCard $giftCard): array
    {
        $status = GiftCardStatus::tryFrom($giftCard->status);
        $failureMeta = is_array($giftCard->meta['phaze_fulfillment_failure'] ?? null)
            ? $giftCard->meta['phaze_fulfillment_failure']
            : [];

        $adminFailureReason = is_string($failureMeta['error_admin'] ?? null) && $failureMeta['error_admin'] !== ''
            ? $failureMeta['error_admin']
            : (is_string($failureMeta['error'] ?? null) && $failureMeta['error'] !== ''
                ? $failureMeta['error']
                : $giftCard->failure_reason);

        return [
            'id' => $giftCard->id,
            'status' => $giftCard->status,
            'status_label' => $status?->label() ?? $giftCard->status,
            'amount' => (float) $giftCard->amount,
            'currency' => $giftCard->currency,
            'brand_name' => $giftCard->brand_name,
            'payment_method' => $giftCard->payment_method,
            'requested_at' => $giftCard->requested_at?->toIso8601String(),
            'scheduled_fulfillment_at' => $giftCard->scheduled_fulfillment_at?->toIso8601String(),
            'fulfilled_at' => $giftCard->fulfilled_at?->toIso8601String(),
            'fulfillment_attempt_count' => (int) $giftCard->fulfillment_attempt_count,
            'failure_reason' => $giftCard->failure_reason,
            'admin_failure_reason' => $adminFailureReason,
            'external_id' => $giftCard->external_id,
            'can_retry' => GiftCardStatus::isRetryEligible($giftCard->status),
            'can_force_fulfill' => GiftCardStatus::isForceFulfillEligible($giftCard->status),
            'user' => $giftCard->user ? [
                'id' => $giftCard->user->id,
                'name' => $giftCard->user->name,
                'email' => $giftCard->user->email,
            ] : null,
            'organization' => $giftCard->organization ? [
                'id' => $giftCard->organization->id,
                'name' => $giftCard->organization->name,
            ] : null,
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
            abort(403);
        }
    }
}
