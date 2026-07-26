<?php

namespace App\Services;

use App\Models\BelievePointGiftInvite;
use App\Models\BelievePointPurchase;
use App\Models\BelievePointProcessingLot;
use App\Models\BelievePointWalletTransfer;
use App\Models\BelievePointsLedgerEntry;
use App\Models\SupporterBelievePointGift;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Believe Point Wallet ledger — BP-only transactions with running balances.
 *
 * This ledger never includes payment processing fees or platform fees (those belong in the Payment Ledger).
 */
final class BelievePointsWalletLedgerService
{
    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    public function paginateForUser(User $user, int $perPage = 25, int $page = 1): array
    {
        $rows = $this->buildRowsForUser($user);
        $total = count($rows);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return [
            'data' => $slice,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildRowsForUser(User $user): array
    {
        $events = $this->collectEvents($user);
        usort($events, static function (array $a, array $b): int {
            $cmp = ($a['sort_at'] ?? '') <=> ($b['sort_at'] ?? '');
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['sort_key'] ?? 0) <=> ($b['sort_key'] ?? 0);
        });

        $available = 0.0;
        $processing = 0.0;
        $gifted = 0.0;
        $rows = [];

        foreach ($events as $event) {
            $credit = round(max(0, (float) ($event['credit'] ?? 0)), 2);
            $debit = round(max(0, (float) ($event['debit'] ?? 0)), 2);
            $deltaAvailable = round((float) ($event['delta_available'] ?? 0), 2);
            $deltaProcessing = round((float) ($event['delta_processing'] ?? 0), 2);
            $deltaGifted = round((float) ($event['delta_gifted'] ?? 0), 2);

            $prevRunning = round($available + $processing, 2);

            if ($deltaAvailable !== 0.0 || $deltaProcessing !== 0.0 || $deltaGifted !== 0.0) {
                $available = round($available + $deltaAvailable, 2);
                $processing = round($processing + $deltaProcessing, 2);
                $gifted = round($gifted + $deltaGifted, 2);
            } else {
                $available = round($available + $credit - $debit, 2);
            }

            $newRunning = round($available + $processing, 2);

            $rows[] = [
                'id' => $event['id'],
                'date' => $event['date'],
                'transaction_number' => $event['transaction_number'],
                'description' => $event['description'],
                'entry_type' => $event['entry_type'],
                'debit' => $debit > 0 ? $debit : null,
                'credit' => $credit > 0 ? $credit : null,
                'bp_change' => round($newRunning - $prevRunning, 2),
                'processing_balance' => $processing,
                'available_balance' => $available,
                'gifted_balance' => $gifted,
                'running_balance' => round($available + $processing, 2),
            ];
        }

        return $rows;
    }

    public static function recordPurchaseProcessing(BelievePointPurchase $purchase): void
    {
        if (! $purchase->user_id || $purchase->status !== 'completed') {
            return;
        }

        $points = round((float) $purchase->points, 2);
        if ($points <= 0) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $purchase->user_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_PURCHASE)
            ->where('metadata->believe_point_purchase_id', $purchase->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $purchase->user_id,
            'amount' => $points,
            'entry_type' => BelievePointsLedgerEntry::TYPE_PURCHASE,
            'description' => 'Believe Point purchase (processing)',
            'metadata' => [
                'believe_point_purchase_id' => $purchase->id,
                'bucket' => 'processing',
                'transaction_number' => self::purchaseTransactionNumber($purchase),
            ],
        ]);
    }

    public static function recordSettlement(BelievePointPurchase $purchase, float $points): void
    {
        $points = round(max(0, $points), 2);
        if ($points <= 0 || ! $purchase->user_id) {
            return;
        }

        $lotId = BelievePointProcessingLot::query()
            ->where('believe_point_purchase_id', $purchase->id)
            ->whereNotNull('released_at')
            ->orderByDesc('id')
            ->value('id');

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $purchase->user_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_SETTLEMENT)
            ->where('metadata->believe_point_purchase_id', $purchase->id)
            ->when($lotId, fn ($q) => $q->where('metadata->believe_point_processing_lot_id', $lotId))
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $purchase->user_id,
            'amount' => $points,
            'entry_type' => BelievePointsLedgerEntry::TYPE_SETTLEMENT,
            'description' => 'Settlement complete — Processing → Available',
            'metadata' => [
                'believe_point_purchase_id' => $purchase->id,
                'believe_point_processing_lot_id' => $lotId,
                'transaction_number' => BelievePointPurchaseSettlementStatusService::settlementReference($purchase)
                    ?? ('bp_settlement:purchase:'.$purchase->id),
            ],
        ]);
    }

    public static function recordGiftSent(SupporterBelievePointGift $gift): void
    {
        $amount = round((float) $gift->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $gift->sender_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_GIFT_SENT)
            ->where('metadata->supporter_believe_point_gift_id', $gift->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $gift->sender_id,
            'amount' => -$amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_GIFT_SENT,
            'description' => 'Gift Believe Points',
            'metadata' => [
                'supporter_believe_point_gift_id' => $gift->id,
                'recipient_id' => $gift->recipient_id,
                'transaction_number' => 'bp_gift:'.$gift->id,
            ],
        ]);
    }

    public static function recordGiftReceived(SupporterBelievePointGift $gift): void
    {
        $amount = round((float) $gift->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $gift->recipient_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_GIFT_RECEIVED)
            ->where('metadata->supporter_believe_point_gift_id', $gift->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $gift->recipient_id,
            'amount' => $amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_GIFT_RECEIVED,
            'description' => 'Received gifted Believe Points',
            'metadata' => [
                'supporter_believe_point_gift_id' => $gift->id,
                'sender_id' => $gift->sender_id,
                'transaction_number' => 'bp_gift:'.$gift->id,
            ],
        ]);
    }

    public static function recordGiftHold(BelievePointGiftInvite $invite): void
    {
        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $invite->sender_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_GIFT_HOLD)
            ->where('metadata->believe_point_gift_invite_id', $invite->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $invite->sender_id,
            'amount' => -$amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_GIFT_HOLD,
            'description' => 'Gift holding (awaiting claim)',
            'metadata' => [
                'believe_point_gift_invite_id' => $invite->id,
                'recipient_email' => $invite->recipient_email,
                'transaction_number' => 'bp_gift_hold:'.$invite->id,
            ],
        ]);
    }

    public static function recordGiftHoldRefund(BelievePointGiftInvite $invite, float $amount): void
    {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $invite->sender_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_GIFT_HOLD_REFUND)
            ->where('metadata->believe_point_gift_invite_id', $invite->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $invite->sender_id,
            'amount' => $amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_GIFT_HOLD_REFUND,
            'description' => 'Gift hold refunded to Available',
            'metadata' => [
                'believe_point_gift_invite_id' => $invite->id,
                'recipient_email' => $invite->recipient_email,
                'transaction_number' => 'bp_gift_hold_refund:'.$invite->id,
            ],
        ]);
    }

    public static function recordPurchaseRefund(BelievePointPurchase $purchase, float $points): void
    {
        $points = round(max(0, $points), 2);
        if ($points <= 0 || ! $purchase->user_id) {
            return;
        }

        $exists = BelievePointsLedgerEntry::query()
            ->where('user_id', $purchase->user_id)
            ->where('entry_type', BelievePointsLedgerEntry::TYPE_REFUND)
            ->where('metadata->believe_point_purchase_id', $purchase->id)
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $purchase->user_id,
            'amount' => -$points,
            'entry_type' => BelievePointsLedgerEntry::TYPE_REFUND,
            'description' => 'Believe Point purchase refund',
            'metadata' => [
                'believe_point_purchase_id' => $purchase->id,
                'transaction_number' => 'bp_refund:purchase:'.$purchase->id,
            ],
        ]);
    }

    public static function recordWalletTransfer(BelievePointWalletTransfer $transfer): void
    {
        if (! $transfer->user_id) {
            return;
        }

        $amount = round((float) $transfer->amount, 2);
        if ($amount <= 0) {
            return;
        }

        if (self::ledgerEntryExists(
            $transfer->user_id,
            BelievePointsLedgerEntry::TYPE_WALLET_TRANSFER,
            'believe_point_wallet_transfer_id',
            $transfer->id,
        )) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $transfer->user_id,
            'amount' => -$amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_WALLET_TRANSFER,
            'description' => 'Moved Believe Points to Believe wallet',
            'metadata' => [
                'believe_point_wallet_transfer_id' => $transfer->id,
                'transaction_number' => 'bp_wallet_transfer:'.$transfer->id,
            ],
        ]);
    }

    public static function recordWalletTransferRefund(BelievePointWalletTransfer $transfer, ?string $reason = null): void
    {
        if (! $transfer->user_id) {
            return;
        }

        $amount = round((float) $transfer->amount, 2);
        if ($amount <= 0) {
            return;
        }

        if (self::ledgerEntryExists(
            $transfer->user_id,
            BelievePointsLedgerEntry::TYPE_WALLET_TRANSFER_REFUND,
            'believe_point_wallet_transfer_id',
            $transfer->id,
        )) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $transfer->user_id,
            'amount' => $amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_WALLET_TRANSFER_REFUND,
            'description' => 'Returned to Available BP — wallet transfer not completed',
            'metadata' => array_filter([
                'believe_point_wallet_transfer_id' => $transfer->id,
                'reason' => $reason,
                'transaction_number' => 'bp_wallet_transfer_refund:'.$transfer->id,
            ], static fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    public static function recordDonationSpend(
        int $userId,
        int $donationId,
        int $organizationId,
        float $amount,
        float $fromProcessing,
        float $fromAvailable,
    ): void {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return;
        }

        if (self::ledgerEntryExists(
            $userId,
            BelievePointsLedgerEntry::TYPE_DONATION_SPEND,
            'donation_id',
            $donationId,
        )) {
            return;
        }

        BelievePointsLedgerEntry::query()->create([
            'user_id' => $userId,
            'amount' => -$amount,
            'entry_type' => BelievePointsLedgerEntry::TYPE_DONATION_SPEND,
            'description' => 'Believe Points donation',
            'metadata' => [
                'donation_id' => $donationId,
                'organization_id' => $organizationId,
                'from_processing' => $fromProcessing,
                'from_available' => $fromAvailable,
                'transaction_number' => 'bp_donation:'.$donationId,
            ],
        ]);
    }

    private static function ledgerEntryExists(int $userId, string $entryType, string $metadataKey, int $referenceId): bool
    {
        return BelievePointsLedgerEntry::query()
            ->where('user_id', $userId)
            ->where('entry_type', $entryType)
            ->where('metadata->'.$metadataKey, $referenceId)
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function dedupeEventsById(array $events): array
    {
        $seen = [];
        $deduped = [];

        foreach ($events as $event) {
            $id = (string) ($event['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $deduped[] = $event;
        }

        return $deduped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectEvents(User $user): array
    {
        $events = [];

        foreach (BelievePointPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->orderBy('id')
            ->get() as $purchase) {
            $points = round((float) $purchase->points, 2);
            if ($points <= 0) {
                continue;
            }

            $createdAt = $purchase->created_at ?? now();
            $events[] = [
                'id' => 'purchase-'.$purchase->id,
                'sort_at' => $createdAt->toIso8601String(),
                'sort_key' => (int) $purchase->id,
                'date' => $createdAt->toIso8601String(),
                'transaction_number' => self::purchaseTransactionNumber($purchase),
                'description' => 'Believe Point purchase (processing)',
                'entry_type' => 'purchase_processing',
                'credit' => $points,
                'debit' => 0.0,
                'delta_available' => 0.0,
                'delta_processing' => $points,
                'delta_gifted' => 0.0,
            ];

            if ($purchase->points_released) {
                $settlementAt = $purchase->settlement_at
                    ?? $purchase->points_available_at
                    ?? $purchase->updated_at
                    ?? $createdAt;
                $events[] = [
                    'id' => 'settlement-'.$purchase->id,
                    'sort_at' => Carbon::parse($settlementAt)->toIso8601String(),
                    'sort_key' => (int) $purchase->id + 500000,
                    'date' => Carbon::parse($settlementAt)->toIso8601String(),
                    'transaction_number' => BelievePointPurchaseSettlementStatusService::settlementReference($purchase)
                        ?? ('bp_settlement:purchase:'.$purchase->id),
                    'description' => 'Settlement complete — Processing → Available',
                    'entry_type' => 'settlement',
                    'credit' => $points,
                    'debit' => 0.0,
                    'delta_available' => $points,
                    'delta_processing' => -$points,
                    'delta_gifted' => 0.0,
                ];
            }
        }

        foreach (BelievePointWalletTransfer::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                BelievePointWalletTransfer::STATUS_PENDING,
                BelievePointWalletTransfer::STATUS_SUBMITTED,
                BelievePointWalletTransfer::STATUS_COMPLETED,
                BelievePointWalletTransfer::STATUS_REFUNDED,
            ])
            ->orderBy('id')
            ->get() as $transfer) {
            $amount = round((float) $transfer->amount, 2);
            if ($amount <= 0) {
                continue;
            }
            $at = $transfer->created_at ?? now();
            $isPending = in_array($transfer->status, [
                BelievePointWalletTransfer::STATUS_PENDING,
                BelievePointWalletTransfer::STATUS_SUBMITTED,
            ], true);
            $events[] = [
                'id' => 'wallet-transfer-'.$transfer->id,
                'sort_at' => $at->toIso8601String(),
                'sort_key' => 1000000 + (int) $transfer->id,
                'date' => $at->toIso8601String(),
                'transaction_number' => 'bp_wallet_transfer:'.$transfer->id,
                'description' => $isPending
                    ? 'Moving Believe Points to Believe wallet'
                    : ($transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED
                        ? 'Believe Points reserved for wallet transfer'
                        : 'Moved Believe Points to Believe wallet'),
                'entry_type' => 'wallet_transfer',
                'credit' => 0.0,
                'debit' => $amount,
                'delta_available' => -$amount,
                'delta_processing' => 0.0,
                'delta_gifted' => 0.0,
            ];
        }

        foreach (BelievePointWalletTransfer::query()
            ->where('user_id', $user->id)
            ->where('status', BelievePointWalletTransfer::STATUS_REFUNDED)
            ->orderBy('id')
            ->get() as $transfer) {
            $amount = round((float) $transfer->amount, 2);
            if ($amount <= 0) {
                continue;
            }
            $at = $transfer->completed_at ?? $transfer->updated_at ?? $transfer->created_at ?? now();
            $events[] = [
                'id' => 'wallet-transfer-refund-'.$transfer->id,
                'sort_at' => Carbon::parse($at)->toIso8601String(),
                'sort_key' => 1500000 + (int) $transfer->id,
                'date' => Carbon::parse($at)->toIso8601String(),
                'transaction_number' => 'bp_wallet_transfer_refund:'.$transfer->id,
                'description' => 'Returned to Available BP — wallet transfer not completed',
                'entry_type' => 'wallet_transfer_refund',
                'credit' => $amount,
                'debit' => 0.0,
                'delta_available' => $amount,
                'delta_processing' => 0.0,
                'delta_gifted' => 0.0,
            ];
        }

        foreach (SupporterBelievePointGift::query()
            ->where('sender_id', $user->id)
            ->orderBy('id')
            ->get() as $gift) {
            $amount = round((float) $gift->amount, 2);
            if ($amount <= 0) {
                continue;
            }
            $at = $gift->sent_at ?? $gift->created_at ?? now();
            $events[] = [
                'id' => 'gift-sent-'.$gift->id,
                'sort_at' => Carbon::parse($at)->toIso8601String(),
                'sort_key' => 2000000 + (int) $gift->id,
                'date' => Carbon::parse($at)->toIso8601String(),
                'transaction_number' => 'bp_gift:'.$gift->id,
                'description' => 'Gift Believe Points',
                'entry_type' => 'gift_sent',
                'credit' => 0.0,
                'debit' => $amount,
                'delta_available' => -$amount,
                'delta_processing' => 0.0,
                'delta_gifted' => 0.0,
            ];
        }

        foreach (SupporterBelievePointGift::query()
            ->where('recipient_id', $user->id)
            ->orderBy('id')
            ->get() as $gift) {
            $amount = round((float) $gift->amount, 2);
            if ($amount <= 0) {
                continue;
            }
            $at = $gift->sent_at ?? $gift->created_at ?? now();
            $events[] = [
                'id' => 'gift-received-'.$gift->id,
                'sort_at' => Carbon::parse($at)->toIso8601String(),
                'sort_key' => 2500000 + (int) $gift->id,
                'date' => Carbon::parse($at)->toIso8601String(),
                'transaction_number' => 'bp_gift:'.$gift->id,
                'description' => 'Received gifted Believe Points',
                'entry_type' => 'gift_received',
                'credit' => $amount,
                'debit' => 0.0,
                'delta_available' => 0.0,
                'delta_processing' => 0.0,
                'delta_gifted' => $amount,
            ];
        }

        foreach (BelievePointPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('refunded_at')
            ->orderBy('id')
            ->get() as $purchase) {
            $points = round((float) $purchase->points, 2);
            if ($points <= 0) {
                continue;
            }
            $at = $purchase->refunded_at ?? $purchase->updated_at ?? now();
            $events[] = [
                'id' => 'refund-'.$purchase->id,
                'sort_at' => Carbon::parse($at)->toIso8601String(),
                'sort_key' => 3000000 + (int) $purchase->id,
                'date' => Carbon::parse($at)->toIso8601String(),
                'transaction_number' => 'bp_refund:purchase:'.$purchase->id,
                'description' => 'Believe Point purchase refund',
                'entry_type' => 'refund',
                'credit' => 0.0,
                'debit' => $points,
                'delta_available' => -$points,
                'delta_processing' => 0.0,
                'delta_gifted' => 0.0,
            ];
        }

        return self::dedupeEventsById($events);
    }

    private static function purchaseTransactionNumber(BelievePointPurchase $purchase): string
    {
        $pi = trim((string) ($purchase->stripe_payment_intent_id ?? ''));

        return $pi !== '' ? $pi : 'believe_points_purchase:'.$purchase->id;
    }
}
