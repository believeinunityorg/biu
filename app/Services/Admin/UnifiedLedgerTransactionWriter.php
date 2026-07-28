<?php

namespace App\Services\Admin;

use App\Models\BelievePointPurchase;
use App\Models\BelievePointProcessingLot;
use App\Models\BelievePointWalletTransfer;
use App\Models\Donation;
use App\Models\Organization;
use App\Models\RewardPointLedger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BelievePointPurchaseSettlementStatusService;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerBrpActivity;
use App\Support\UnifiedLedgerOwner;
use App\Support\UnifiedLedgerType;

/**
 * Writes Money / BP / BRP rows into the unified admin transaction history.
 */
final class UnifiedLedgerTransactionWriter
{
    public static function syncBelievePointPurchaseRows(BelievePointPurchase $purchase): void
    {
        if (! $purchase->user_id) {
            return;
        }

        $purchase->loadMissing('user');
        $user = $purchase->user;
        if (! $user) {
            return;
        }

        self::syncMoneyPurchaseRow($purchase);
        self::syncBpCreditRow($purchase, $user);

        $brp = round((float) ($purchase->reward_points_awarded ?? 0), 2);
        if ($brp > 0 && $purchase->status === 'completed') {
            self::syncBrpEarnedForPurchase($purchase, $user, $brp);
        }
    }

    public static function syncMoneyPurchaseRow(BelievePointPurchase $purchase): void
    {
        // Money row is maintained by BelievePointPurchaseSettlementService::syncAdminLedgerPurchaseRow.
        $tx = Transaction::query()
            ->where('related_id', $purchase->id)
            ->where('type', 'purchase')
            ->where(function ($q) {
                $q->where('related_type', BelievePointPurchase::class)
                    ->orWhere('related_type', 'like', '%BelievePointPurchase');
            })
            ->where(function ($q) {
                $q->whereNull('ledger_type')
                    ->orWhere('ledger_type', UnifiedLedgerType::MONEY);
            })
            ->orderBy('id')
            ->first();

        if ($tx) {
            $tx->forceFill([
                'ledger_type' => UnifiedLedgerType::MONEY,
                'bp_status' => UnifiedLedgerBpStatus::NA,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => null,
                'available_at' => null,
            ])->saveQuietly();
        }
    }

    public static function syncBpCreditRow(BelievePointPurchase $purchase, User $user): void
    {
        $points = round((float) $purchase->points, 2);
        if ($points <= 0) {
            return;
        }

        $bpStatus = self::bpStatusForPurchase($purchase);
        $status = match ($purchase->status) {
            'completed' => $bpStatus === UnifiedLedgerBpStatus::PROCESSING
                ? Transaction::STATUS_PENDING
                : Transaction::STATUS_COMPLETED,
            'failed' => Transaction::STATUS_FAILED,
            'cancelled' => Transaction::STATUS_CANCELLED,
            default => Transaction::STATUS_PENDING,
        };

        $availableAt = $bpStatus === UnifiedLedgerBpStatus::AVAILABLE && $purchase->points_released
            ? (BelievePointPurchaseSettlementStatusService::settlementDate($purchase) ?? $purchase->points_available_at)
            : null;

        $meta = [
            'source' => 'believe_points_purchase_bp',
            'ledger_type' => UnifiedLedgerType::BP,
            'ledger_role' => 'bp_credit',
            'believe_point_purchase_id' => $purchase->id,
            'intended_points' => $points,
            'bp_status' => $bpStatus,
            'owner_type' => 'supporter',
            'current_owner' => $user->name,
            'event_name' => 'BP Purchase',
            'description' => 'Purchased Believe Points',
            'from_type' => 'module',
            'from_name' => 'BIU Platform',
            'to_type' => 'module',
            'to_name' => 'General Module',
            'to_id' => $user->id,
        ];

        if ($availableAt) {
            $meta['settlement_date'] = $availableAt->toIso8601String();
        }

        Transaction::query()->updateOrCreate(
            ['transaction_id' => 'bp_credit:purchase:'.$purchase->id],
            [
                'user_id' => $user->id,
                'related_id' => $purchase->id,
                'related_type' => BelievePointPurchase::class,
                'type' => 'purchase',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => $bpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $user->name,
                'available_at' => $availableAt,
                'status' => $status,
                'amount' => $points,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => null,
                'processed_at' => $purchase->updated_at ?? now(),
                'meta' => $meta,
            ],
        );

        self::syncBrpPurchaseRewardRow($purchase);
    }

    public static function syncBpSettlementRow(
        BelievePointPurchase $purchase,
        BelievePointProcessingLot $lot,
        User $owner,
        float $points,
    ): void {
        $points = round(max(0, $points), 2);
        if ($points <= 0) {
            return;
        }

        $settlementAt = BelievePointPurchaseSettlementStatusService::settlementDate($purchase) ?? now();
        $reference = BelievePointPurchaseSettlementStatusService::settlementReference($purchase)
            ?? ('bp_settlement:purchase:'.$purchase->id.':lot:'.$lot->id);

        Transaction::query()->updateOrCreate(
            ['transaction_id' => $reference],
            [
                'user_id' => $owner->id,
                'related_id' => $lot->id,
                'related_type' => BelievePointProcessingLot::class,
                'type' => 'bp_settlement',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $owner->name,
                'available_at' => $settlementAt,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => $points,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $settlementAt,
                'meta' => [
                    'source' => 'bp_settlement',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'believe_point_purchase_id' => $purchase->id,
                    'believe_point_processing_lot_id' => $lot->id,
                    'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                    'bp_wallet_delta' => 0,
                    'owner_type' => 'supporter',
                    'event_name' => 'BP Settlement',
                    'description' => 'Processing BP → Available BP',
                    'from_type' => 'General Module',
                    'from_name' => 'General Module',
                    'to_type' => 'General Module',
                    'to_name' => 'General Module',
                    'to_id' => $owner->id,
                    'current_bp_owner_user_id' => $owner->id,
                    'current_bp_owner_name' => $owner->name,
                    'settlement_date' => $settlementAt->toIso8601String(),
                ],
            ],
        );

        self::syncBpCreditRow($purchase->fresh() ?? $purchase, $owner);
    }

    public static function syncBpDonationSpendRow(
        User $donor,
        Organization $organization,
        Donation $donation,
        float $amount,
        float $fromProcessing,
        float $fromAvailable,
    ): void {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return;
        }

        $organization->loadMissing('user');
        $ownerName = trim((string) ($organization->name ?: ''));
        if ($ownerName === '') {
            $ownerName = UnifiedLedgerOwner::ORGANIZATION;
        }
        $bpStatus = $fromProcessing > 0 && $fromAvailable <= 0
            ? UnifiedLedgerBpStatus::PROCESSING
            : UnifiedLedgerBpStatus::AVAILABLE;

        Transaction::query()->updateOrCreate(
            ['transaction_id' => 'bp_donation:donation:'.$donation->id.':donor:'.$donor->id],
            [
                'user_id' => $donor->id,
                'related_id' => $donation->id,
                'related_type' => Donation::class,
                'type' => 'donation',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => $bpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $ownerName,
                'available_at' => $bpStatus === UnifiedLedgerBpStatus::AVAILABLE
                    ? now()
                    : null,
                'status' => $bpStatus === UnifiedLedgerBpStatus::PROCESSING
                    ? Transaction::STATUS_PENDING
                    : Transaction::STATUS_COMPLETED,
                'amount' => -$amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => now(),
                'meta' => [
                    'source' => 'believe_points_donation',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'donation_id' => $donation->id,
                    'organization_id' => $donation->organization_id,
                    'from_processing' => $fromProcessing,
                    'from_available' => $fromAvailable,
                    'bp_status' => $bpStatus,
                    'owner_type' => 'organization',
                    'organization_name' => $organization->name,
                    'event_name' => 'Processing BP Donation',
                    'description' => 'Believe Points donation',
                    'from_type' => 'Supporter',
                    'from_name' => $donor->name,
                    'to_type' => 'Organization',
                    'to_name' => $ownerName,
                ],
            ],
        );
    }

    public static function syncBrpEarnedForPurchase(BelievePointPurchase $purchase, User $user, float $brp): void
    {
        self::syncBrpPurchaseRewardRow($purchase);
    }

    /**
     * BRP from a BP purchase stays Processing until the purchase settles to Available BP.
     */
    public static function syncBrpPurchaseRewardRow(BelievePointPurchase $purchase): void
    {
        $purchase->loadMissing('user');
        $user = $purchase->user;
        if (! $user) {
            return;
        }

        $brp = round((float) ($purchase->reward_points_awarded ?? 0), 2);
        if ($brp <= 0 || $purchase->status !== 'completed') {
            return;
        }

        $brpStatus = self::bpStatusForPurchase($purchase);
        $availableAt = $brpStatus === UnifiedLedgerBpStatus::AVAILABLE
            ? (BelievePointPurchaseSettlementStatusService::settlementDate($purchase) ?? $purchase->points_available_at)
            : null;
        $txStatus = $brpStatus === UnifiedLedgerBpStatus::PROCESSING
            ? Transaction::STATUS_PENDING
            : Transaction::STATUS_COMPLETED;

        Transaction::query()->updateOrCreate(
            ['transaction_id' => 'brp:earned:purchase:'.$purchase->id],
            [
                'user_id' => $user->id,
                'related_id' => $purchase->id,
                'related_type' => BelievePointPurchase::class,
                'type' => 'reward',
                'ledger_type' => UnifiedLedgerType::BRP,
                'bp_status' => $brpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::EARNED,
                'current_owner' => $user->name,
                'available_at' => $availableAt,
                'status' => $txStatus,
                'amount' => $brp,
                'fee' => 0,
                'currency' => 'BRP',
                'payment_method' => null,
                'processed_at' => $availableAt ?? ($purchase->updated_at ?? now()),
                'meta' => [
                    'source' => 'believe_points_purchase_brp',
                    'ledger_type' => UnifiedLedgerType::BRP,
                    'brp_activity_type' => UnifiedLedgerBrpActivity::EARNED,
                    'believe_point_purchase_id' => $purchase->id,
                    'intended_points' => $brp,
                    'brp_status' => $brpStatus,
                    'bp_status' => $brpStatus,
                    'event_name' => 'BRP Participation Reward',
                    'description' => $brpStatus === UnifiedLedgerBpStatus::PROCESSING
                        ? sprintf('Believe Reward Points earned for qualifying BP purchase (%s BRP Processing)', number_format($brp, 2))
                        : sprintf('Believe Reward Points earned for qualifying BP purchase (%s BRP Available)', number_format($brp, 2)),
                    'from_type' => 'BIU Platform',
                    'from_name' => UnifiedLedgerOwner::PLATFORM,
                    'to_type' => 'Supporter',
                    'to_name' => $user->name,
                    'to_id' => $user->id,
                ],
            ],
        );

        self::pruneDuplicateBrpPurchaseRows($purchase);
    }

    /**
     * One BRP earn row per BP purchase — drop legacy brp:ledger:* duplicates.
     */
    private static function pruneDuplicateBrpPurchaseRows(BelievePointPurchase $purchase): void
    {
        $canonical = 'brp:earned:purchase:'.$purchase->id;

        Transaction::query()
            ->where('ledger_type', UnifiedLedgerType::BRP)
            ->where('related_id', $purchase->id)
            ->where('transaction_id', '!=', $canonical)
            ->delete();
    }

    public static function syncFromRewardPointLedger(RewardPointLedger $entry): void
    {
        if ($entry->type === 'credit'
            && in_array($entry->source, ['believe_points_card_purchase', 'believe_points_ach_purchase'], true)
            && $entry->reference_id) {
            $purchase = BelievePointPurchase::query()->find($entry->reference_id);
            if ($purchase) {
                self::syncBrpPurchaseRewardRow($purchase);

                return;
            }
        }

        $activity = self::brpActivityForEntry($entry);

        $amount = round((float) $entry->points, 2);
        if ($entry->type === 'debit') {
            $amount = -abs($amount);
        }

        $user = $entry->user;
        $reference = 'brp:ledger:'.$entry->id;

        Transaction::query()->updateOrCreate(
            ['transaction_id' => $reference],
            [
                'user_id' => $entry->user_id,
                'related_id' => $entry->reference_id,
                'related_type' => null,
                'type' => $entry->type === 'debit' ? 'redemption' : 'reward',
                'ledger_type' => UnifiedLedgerType::BRP,
                'bp_status' => UnifiedLedgerBpStatus::NA,
                'brp_activity_type' => $activity,
                'current_owner' => $user?->name,
                'available_at' => null,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => $amount,
                'fee' => 0,
                'currency' => 'BRP',
                'payment_method' => null,
                'processed_at' => $entry->created_at ?? now(),
                'meta' => [
                    'source' => 'reward_point_ledger',
                    'ledger_type' => UnifiedLedgerType::BRP,
                    'brp_activity_type' => $activity,
                    'reward_point_ledger_id' => $entry->id,
                    'reward_source' => $entry->source,
                    'direction' => $entry->type,
                    'event_name' => self::brpEventName($entry->source, $activity),
                    'description' => $entry->description,
                ],
            ],
        );
    }

    /**
     * BP → Bridge wallet: two ledger rows (BP redemption burn + money transfer to Bridge wallet).
     */
    public static function syncWalletTransferRows(BelievePointWalletTransfer $transfer): void
    {
        if (! $transfer->user_id) {
            return;
        }

        $transfer->loadMissing('user');
        $user = $transfer->user;
        if (! $user) {
            return;
        }

        $amount = round((float) $transfer->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $metadata = is_array($transfer->metadata) ? $transfer->metadata : [];
        $bridgeTransferId = trim((string) ($transfer->bridge_transfer_id ?? ''));

        $status = match ($transfer->status) {
            BelievePointWalletTransfer::STATUS_COMPLETED => Transaction::STATUS_COMPLETED,
            BelievePointWalletTransfer::STATUS_FAILED => Transaction::STATUS_FAILED,
            BelievePointWalletTransfer::STATUS_REFUNDED => Transaction::STATUS_REFUND,
            BelievePointWalletTransfer::STATUS_SUBMITTED => Transaction::STATUS_PENDING,
            default => Transaction::STATUS_PENDING,
        };

        $processedAt = in_array($transfer->status, [
            BelievePointWalletTransfer::STATUS_COMPLETED,
            BelievePointWalletTransfer::STATUS_FAILED,
            BelievePointWalletTransfer::STATUS_REFUNDED,
        ], true)
            ? ($transfer->completed_at ?? $transfer->updated_at ?? now())
            : null;

        $bpReference = 'bp_redemption:wallet_transfer:'.$transfer->id;
        $moneyReference = $bridgeTransferId !== ''
            ? $bridgeTransferId
            : 'bridge_wallet_transfer:wallet_transfer:'.$transfer->id;

        $sharedMeta = [
            'believe_point_wallet_transfer_id' => $transfer->id,
            'believe_point_wallet_transfer_status' => $transfer->status,
            'bridge_transfer_id' => $bridgeTransferId !== '' ? $bridgeTransferId : null,
            'bridge_transfer_state' => $transfer->bridge_transfer_state,
            'recipient_customer_id' => $metadata['recipient_customer_id'] ?? null,
            'recipient_wallet_id' => $metadata['recipient_wallet_id'] ?? null,
            'points_amount' => $amount,
            'awaiting_liquidity' => (bool) ($metadata['awaiting_liquidity'] ?? false),
            'failure_message' => $transfer->failure_message,
        ];

        Transaction::query()->updateOrCreate(
            ['transaction_id' => $bpReference],
            [
                'user_id' => $user->id,
                'related_id' => $transfer->id,
                'related_type' => BelievePointWalletTransfer::class,
                'type' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                    ? 'refund'
                    : 'bp_redemption',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => UnifiedLedgerBpStatus::NA,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $user->name,
                'available_at' => null,
                'status' => $status,
                'amount' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                    ? $amount
                    : -$amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $processedAt,
                'meta' => array_filter([
                    ...$sharedMeta,
                    'source' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                        ? 'believe_points_wallet_transfer'
                        : 'bp_redemption',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'ledger_role' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                        ? 'wallet_transfer_refund'
                        : 'bp_redemption',
                    'event_name' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                        ? 'Transfer to BIU Wallet Refund'
                        : 'Transfer to BIU Wallet',
                    'description' => self::walletTransferBpDescription($transfer),
                    'bp_wallet_delta' => $transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                        ? $amount
                        : -$amount,
                    'gross_amount' => $amount,
                    'from_type' => 'module',
                    'from_name' => 'General Module',
                    'from_id' => $user->id,
                    'to_type' => 'module',
                    'to_name' => 'BIU Wallet',
                    'current_owner' => $user->name,
                    'owner_type' => 'supporter',
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );

        Transaction::query()->updateOrCreate(
            ['transaction_id' => $moneyReference],
            [
                'user_id' => $user->id,
                'related_id' => $transfer->id,
                'related_type' => BelievePointWalletTransfer::class,
                'type' => 'bridge_wallet_transfer',
                'ledger_type' => UnifiedLedgerType::MONEY,
                'bp_status' => UnifiedLedgerBpStatus::NA,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => null,
                'available_at' => null,
                'status' => $status,
                'amount' => $amount,
                'fee' => 0,
                'currency' => 'USD',
                'payment_method' => 'bridge',
                'processed_at' => $processedAt,
                'meta' => array_filter([
                    ...$sharedMeta,
                    'source' => 'bridge_wallet_transfer',
                    'ledger_type' => UnifiedLedgerType::MONEY,
                    'ledger_role' => 'bridge_money_transfer',
                    'event_name' => 'BIU Wallet Transfer',
                    'description' => self::walletTransferMoneyDescription($transfer),
                    'gross_amount' => $amount,
                    'from_type' => 'BIU Platform',
                    'from_name' => UnifiedLedgerOwner::PLATFORM,
                    'to_type' => 'Supporter',
                    'to_name' => $user->name,
                    'to_id' => $user->id,
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );

        if ($transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED) {
            $bpTx = Transaction::query()->where('transaction_id', $bpReference)->first();
            if ($bpTx) {
                $meta = is_array($bpTx->meta) ? $bpTx->meta : [];
                $meta['refund_amount'] = $amount;
                $bpTx->forceFill([
                    'bp_status' => UnifiedLedgerBpStatus::REVERSED,
                    'meta' => $meta,
                ])->saveQuietly();
            }
        }

        Transaction::query()
            ->where('related_id', $transfer->id)
            ->where(function ($q) {
                $q->where('related_type', BelievePointWalletTransfer::class)
                    ->orWhere('related_type', 'like', '%BelievePointWalletTransfer');
            })
            ->whereNotIn('transaction_id', [$bpReference, $moneyReference])
            ->delete();
    }

    private static function walletTransferBpDescription(BelievePointWalletTransfer $transfer): string
    {
        return match ($transfer->status) {
            BelievePointWalletTransfer::STATUS_PENDING => 'BP redemption for Bridge wallet (pending liquidity)',
            BelievePointWalletTransfer::STATUS_SUBMITTED => 'BP redemption for Bridge wallet (processing)',
            BelievePointWalletTransfer::STATUS_COMPLETED => 'BP redeemed for Bridge wallet transfer',
            BelievePointWalletTransfer::STATUS_REFUNDED => 'Returned to Available BP — wallet transfer not completed',
            BelievePointWalletTransfer::STATUS_FAILED => 'BP redemption failed',
            default => 'BP redemption for Bridge wallet',
        };
    }

    private static function walletTransferMoneyDescription(BelievePointWalletTransfer $transfer): string
    {
        return match ($transfer->status) {
            BelievePointWalletTransfer::STATUS_PENDING => 'Bridge wallet transfer from BIU Reserve (pending)',
            BelievePointWalletTransfer::STATUS_SUBMITTED => 'Bridge wallet transfer from BIU Reserve (processing)',
            BelievePointWalletTransfer::STATUS_COMPLETED => 'Bridge wallet transfer from BIU Reserve Account',
            BelievePointWalletTransfer::STATUS_REFUNDED => 'Bridge wallet transfer refunded',
            BelievePointWalletTransfer::STATUS_FAILED => 'Bridge wallet transfer failed',
            default => 'Bridge wallet transfer from BIU Reserve Account',
        };
    }

    private static function bpStatusForPurchase(BelievePointPurchase $purchase): string
    {
        if ($purchase->refunded_at !== null) {
            return UnifiedLedgerBpStatus::REVERSED;
        }

        if ($purchase->points_released) {
            return UnifiedLedgerBpStatus::AVAILABLE;
        }

        if ($purchase->status === 'completed') {
            return UnifiedLedgerBpStatus::PROCESSING;
        }

        return UnifiedLedgerBpStatus::PROCESSING;
    }

    private static function brpActivityForEntry(RewardPointLedger $entry): string
    {
        $source = strtolower(trim((string) $entry->source));

        if (str_contains($source, 'expir')) {
            return UnifiedLedgerBrpActivity::EXPIRED;
        }

        if (str_contains($source, 'adjust') || in_array($source, ['manual', 'admin_adjustment'], true)) {
            return UnifiedLedgerBrpActivity::ADJUSTED;
        }

        if ($entry->type === 'debit') {
            return UnifiedLedgerBrpActivity::REDEEMED;
        }

        return UnifiedLedgerBrpActivity::EARNED;
    }

    private static function brpEventName(string $source, string $activity): string
    {
        if ($activity === UnifiedLedgerBrpActivity::REDEEMED) {
            return 'Merchant Hub Offer Redemption';
        }

        return match ($source) {
            'believe_points_card_purchase', 'believe_points_ach_purchase' => 'BRP Participation Reward',
            default => 'Believe Reward Points '.UnifiedLedgerBrpActivity::label($activity),
        };
    }
}
