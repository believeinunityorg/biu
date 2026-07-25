<?php

namespace App\Services;

use App\Models\BelievePointGiftInvite;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerBrpActivity;
use App\Support\UnifiedLedgerType;

/**
 * Admin unified ledger rows for Gift BP lifecycle (hold / claim / refund / email change).
 */
final class BelievePointGiftAdminLedgerService
{
    /**
     * Upsert admin ledger rows for an invite based on its current status (for sync/backfill).
     */
    public static function syncFromInvite(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');

        match ($invite->status) {
            BelievePointGiftInvite::STATUS_PENDING => self::recordHold($invite),
            BelievePointGiftInvite::STATUS_CLAIMED => self::recordClaim($invite),
            BelievePointGiftInvite::STATUS_CANCELLED,
            BelievePointGiftInvite::STATUS_EXPIRED => self::recordHoldRefund(
                $invite,
                round((float) $invite->amount, 2),
                $invite->status === BelievePointGiftInvite::STATUS_CANCELLED ? 'cancelled' : 'expired',
            ),
            default => null,
        };
    }

    public static function recordHold(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient');
        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0 || ! $invite->sender) {
            return;
        }

        $sender = $invite->sender;
        $recipientLabel = self::recipientLabel($invite);
        $forRegistered = (int) ($invite->recipient_id ?? 0) > 0;

        self::upsert(
            transactionId: 'bp_gift_hold:'.$invite->id,
            user: $sender,
            invite: $invite,
            type: 'bp_gift_hold',
            status: Transaction::STATUS_PENDING,
            amount: -$amount,
            bpStatus: UnifiedLedgerBpStatus::PROCESSING,
            source: 'bp_gift_hold',
            eventName: 'BP Gift Hold',
            description: $forRegistered
                ? "Gift of {$amount} BP to {$recipientLabel} is holding until they accept."
                : "Gift of {$amount} BP to {$recipientLabel} is holding until they register and claim.",
            availableDelta: -$amount,
            holdingDelta: $amount,
            processedAt: null,
            extraMeta: [
                'gift_status' => BelievePointGiftInvite::STATUS_PENDING,
            ],
        );
    }

    public static function recordClaim(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');
        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0 || ! $invite->sender) {
            return;
        }

        $sender = $invite->sender;
        $recipient = $invite->recipient;
        $recipientLabel = self::recipientLabel($invite);

        // Mark the hold row completed (ownership left Holding).
        self::upsert(
            transactionId: 'bp_gift_hold:'.$invite->id,
            user: $sender,
            invite: $invite,
            type: 'bp_gift_hold',
            status: Transaction::STATUS_COMPLETED,
            amount: -$amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            source: 'bp_gift_hold',
            eventName: 'BP Gift Hold',
            description: "Gift of {$amount} BP to {$recipientLabel} was claimed.",
            availableDelta: -$amount,
            holdingDelta: 0,
            processedAt: $invite->claimed_at ?? now(),
            extraMeta: [
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'claimed_at' => $invite->claimed_at?->toIso8601String(),
                'supporter_believe_point_gift_id' => $invite->supporter_believe_point_gift_id,
            ],
        );

        if ($recipient) {
            self::upsert(
                transactionId: 'bp_gift_claim:'.$invite->id,
                user: $recipient,
                invite: $invite,
                type: 'bp_gift_claim',
                status: Transaction::STATUS_COMPLETED,
                amount: $amount,
                bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
                source: 'bp_gift_claim',
                eventName: 'BP Gift Claimed',
                description: "{$sender->name} gifted {$amount} BP — credited to Gifted BP wallet.",
                availableDelta: 0,
                holdingDelta: -$amount,
                processedAt: $invite->claimed_at ?? now(),
                extraMeta: [
                    'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                    'gifted_bp_delta' => $amount,
                    'supporter_believe_point_gift_id' => $invite->supporter_believe_point_gift_id,
                    'from_type' => 'Supporter',
                    'from_name' => $sender->name,
                    'from_id' => $sender->id,
                    'to_type' => 'Supporter',
                    'to_name' => $recipient->name,
                    'to_id' => $recipient->id,
                ],
            );
        }
    }

    public static function recordHoldRefund(BelievePointGiftInvite $invite, float $refundAmount, string $reason): void
    {
        $invite->loadMissing('sender', 'recipient');
        $refundAmount = round(max(0, $refundAmount), 2);
        if ($refundAmount <= 0 || ! $invite->sender) {
            return;
        }

        $sender = $invite->sender;
        $recipientLabel = self::recipientLabel($invite);
        $statusLabel = $invite->status === BelievePointGiftInvite::STATUS_CANCELLED
            ? 'cancelled'
            : 'expired';

        self::upsert(
            transactionId: 'bp_gift_hold:'.$invite->id,
            user: $sender,
            invite: $invite,
            type: 'bp_gift_hold',
            status: Transaction::STATUS_CANCELLED,
            amount: -$refundAmount,
            bpStatus: UnifiedLedgerBpStatus::REVERSED,
            source: 'bp_gift_hold',
            eventName: 'BP Gift Hold',
            description: "Gift hold to {$recipientLabel} was {$statusLabel}.",
            availableDelta: -$refundAmount,
            holdingDelta: 0,
            processedAt: $invite->refunded_at ?? now(),
            extraMeta: [
                'gift_status' => $invite->status,
            ],
        );

        self::upsert(
            transactionId: 'bp_gift_hold_refund:'.$invite->id,
            user: $sender,
            invite: $invite,
            type: 'bp_gift_hold_refund',
            status: Transaction::STATUS_COMPLETED,
            amount: $refundAmount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            source: 'bp_gift_hold_refund',
            eventName: 'BP Gift Hold Refund',
            description: "{$refundAmount} BP returned to Available after gift {$statusLabel} ({$reason}).",
            availableDelta: $refundAmount,
            holdingDelta: -$refundAmount,
            processedAt: $invite->refunded_at ?? now(),
            extraMeta: [
                'gift_status' => $invite->status,
                'refund_reason' => $reason,
            ],
        );
    }

    public static function recordEmailChanged(BelievePointGiftInvite $invite, string $previousEmail): void
    {
        $invite->loadMissing('sender');
        $amount = round((float) $invite->amount, 2);
        if (! $invite->sender) {
            return;
        }

        self::upsert(
            transactionId: 'bp_gift_email_changed:'.$invite->id.':'.now()->timestamp,
            user: $invite->sender,
            invite: $invite,
            type: 'bp_gift_email_changed',
            status: Transaction::STATUS_COMPLETED,
            amount: 0,
            bpStatus: UnifiedLedgerBpStatus::NA,
            source: 'bp_gift_email_changed',
            eventName: 'BP Gift Recipient Email Changed',
            description: "Gift invite email changed from {$previousEmail} to {$invite->recipient_email}. Holding BP unchanged ({$amount} BP).",
            availableDelta: 0,
            holdingDelta: 0,
            processedAt: now(),
            extraMeta: [
                'gift_status' => BelievePointGiftInvite::STATUS_PENDING,
                'previous_email' => $previousEmail,
                'new_email' => $invite->recipient_email,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private static function upsert(
        string $transactionId,
        User $user,
        BelievePointGiftInvite $invite,
        string $type,
        string $status,
        float $amount,
        string $bpStatus,
        string $source,
        string $eventName,
        string $description,
        float $availableDelta,
        float $holdingDelta,
        mixed $processedAt,
        array $extraMeta = [],
    ): void {
        $recipientLabel = self::recipientLabel($invite);

        Transaction::query()->updateOrCreate(
            ['transaction_id' => $transactionId],
            [
                'user_id' => $user->id,
                'related_id' => $invite->id,
                'related_type' => BelievePointGiftInvite::class,
                'type' => $type,
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => $bpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $user->name,
                'available_at' => null,
                'status' => $status,
                'amount' => $amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $processedAt,
                'meta' => array_filter([
                    'source' => $source,
                    'ledger_type' => UnifiedLedgerType::BP,
                    'ledger_role' => $source,
                    'event_name' => $eventName,
                    'description' => $description,
                    'believe_point_gift_invite_id' => $invite->id,
                    'gift_id' => $invite->id,
                    'sender_id' => $invite->sender_id,
                    'sender_name' => $invite->sender?->name,
                    'recipient_id' => $invite->recipient_id,
                    'recipient_email' => $invite->recipient_email,
                    'recipient_name' => $invite->recipient?->name ?? $recipientLabel,
                    'points_amount' => round((float) $invite->amount, 2),
                    'bp_available_delta' => $availableDelta,
                    'bp_holding_delta' => $holdingDelta,
                    'bp_wallet_delta' => $availableDelta,
                    'gross_amount' => round((float) $invite->amount, 2),
                    'occasion' => $invite->occasion,
                    'current_owner' => $user->name,
                    'owner_type' => 'supporter',
                    ...$extraMeta,
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );
    }

    private static function recipientLabel(BelievePointGiftInvite $invite): string
    {
        if ($invite->recipient && filled($invite->recipient->name)) {
            return (string) $invite->recipient->name;
        }

        return (string) $invite->recipient_email;
    }
}
