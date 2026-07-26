<?php

namespace App\Services;

use App\Models\BelievePointProcessingLot;
use App\Models\BelievePointPurchase;
use App\Models\User;
use App\Support\SupporterSubscriptionService;
use Carbon\CarbonInterface;

/**
 * Client-facing BP / settlement status for purchase history and admin audit.
 */
final class BelievePointPurchaseSettlementStatusService
{
    public const BP_PROCESSING = 'processing';

    public const BP_AVAILABLE = 'available';

    public const SETTLEMENT_PROCESSING = 'processing';

    public const SETTLEMENT_AVAILABLE = 'available';

    public const SETTLEMENT_FAILED = 'failed';

    public const SETTLEMENT_REVERSED = 'reversed';

    public static function bpStatus(BelievePointPurchase $purchase): string
    {
        if (in_array($purchase->status, ['failed', 'cancelled'], true)) {
            return self::BP_PROCESSING;
        }

        if ($purchase->refunded_at !== null) {
            return self::BP_AVAILABLE;
        }

        return $purchase->points_released ? self::BP_AVAILABLE : self::BP_PROCESSING;
    }

    public static function settlementStatus(BelievePointPurchase $purchase): string
    {
        if (in_array($purchase->status, ['failed', 'cancelled'], true)) {
            return self::SETTLEMENT_FAILED;
        }

        if ($purchase->refunded_at !== null) {
            return self::SETTLEMENT_REVERSED;
        }

        if ($purchase->points_released) {
            return self::SETTLEMENT_AVAILABLE;
        }

        if ($purchase->status === 'completed') {
            return self::SETTLEMENT_PROCESSING;
        }

        return self::SETTLEMENT_PROCESSING;
    }

    public static function settlementDate(BelievePointPurchase $purchase): ?CarbonInterface
    {
        if ($purchase->settlement_at) {
            return $purchase->settlement_at;
        }

        if ($purchase->points_released) {
            return $purchase->stripe_funds_available_at
                ?? $purchase->bridge_reserve_confirmed_at;
        }

        return null;
    }

    public static function settlementReference(BelievePointPurchase $purchase): ?string
    {
        $stripe = trim((string) ($purchase->stripe_settlement_reference ?? ''));
        if ($stripe !== '') {
            return $stripe;
        }

        $payout = trim((string) ($purchase->stripe_payout_id ?? ''));
        if ($payout !== '') {
            return $payout;
        }

        $bridge = trim((string) ($purchase->bridge_settlement_reference ?? ''));
        if ($bridge !== '') {
            return $bridge;
        }

        $pi = trim((string) ($purchase->stripe_payment_intent_id ?? ''));

        return $pi !== '' ? $pi : null;
    }

    /**
     * @return list<array{id: int, name: string|null, email: string|null}>
     */
    public static function currentOwners(BelievePointPurchase $purchase): array
    {
        $lots = BelievePointProcessingLot::query()
            ->with('user:id,name,email')
            ->where('believe_point_purchase_id', $purchase->id)
            ->whereNull('released_at')
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->get();

        if ($lots->isEmpty() && $purchase->points_released) {
            return [];
        }

        if ($lots->isEmpty() && $purchase->user) {
            return [[
                'id' => (int) $purchase->user_id,
                'name' => $purchase->user->name,
                'email' => $purchase->user->email,
            ]];
        }

        $owners = [];
        foreach ($lots as $lot) {
            $userId = (int) $lot->user_id;
            if (isset($owners[$userId])) {
                continue;
            }
            $owners[$userId] = [
                'id' => $userId,
                'name' => $lot->user?->name,
                'email' => $lot->user?->email,
            ];
        }

        return array_values($owners);
    }

    public static function syncPurchaseSettlementFields(BelievePointPurchase $purchase): void
    {
        $purchase->update([
            'settlement_status' => self::settlementStatus($purchase),
            'settlement_at' => $purchase->points_released
                ? (self::settlementDate($purchase) ?? now())
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function historyPayload(BelievePointPurchase $purchase): array
    {
        $owners = self::currentOwners($purchase);
        $primaryOwner = $owners[0] ?? null;

        return [
            'bp_status' => self::bpStatus($purchase),
            'settlement_status' => self::settlementStatus($purchase),
            'settlement_date' => self::settlementDate($purchase)?->toIso8601String(),
            'settlement_reference' => self::settlementReference($purchase),
            'stripe_payout_id' => $purchase->stripe_payout_id,
            'stripe_balance_transaction_id' => $purchase->stripe_balance_transaction_id,
            'current_bp_owner' => $primaryOwner,
            'current_bp_owners' => $owners,
            /** Expected Available On for Processing BP (supporter-facing). */
            'available_on' => $purchase->points_released
                ? null
                : ($purchase->points_available_at ?? $purchase->stripe_funds_available_at)?->toIso8601String(),
        ];
    }

    public static function userCanTransferToWallet(User $user): bool
    {
        if ($user->hasNonprofitDashboardRole()) {
            return true;
        }

        return SupporterSubscriptionService::currentTierSlug($user) === SupporterSubscriptionService::SLUG_PRIME;
    }
}
