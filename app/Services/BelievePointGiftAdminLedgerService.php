<?php

namespace App\Services;

use App\Models\BelievePointGiftInvite;
use App\Models\SupporterBelievePointGift;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerBrpActivity;
use App\Support\UnifiedLedgerType;

/**
 * Admin unified ledger: one "BP Gift" row per gift (sender → recipient), status = gift lifecycle.
 */
final class BelievePointGiftAdminLedgerService
{
    /**
     * Upsert the canonical ledger row for an invite (and prune old multi-row ids).
     */
    public static function syncFromInvite(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');
        self::upsertCanonicalFromInvite($invite);
        self::pruneLegacyInviteRows($invite->id);
    }

    /**
     * Backfill gifts that completed under the old immediate-transfer path (no invite row).
     */
    public static function syncFromLegacyGift(SupporterBelievePointGift $gift): void
    {
        $gift->loadMissing('sender', 'recipient');
        if (! $gift->sender || round((float) $gift->amount, 2) <= 0) {
            return;
        }

        // Skip if an invite already owns this gift (invite sync covers it).
        $linkedInvite = BelievePointGiftInvite::query()
            ->where('supporter_believe_point_gift_id', $gift->id)
            ->exists();
        if ($linkedInvite) {
            return;
        }

        $sender = $gift->sender;
        $recipient = $gift->recipient;
        $amount = round((float) $gift->amount, 2);
        $recipientLabel = $recipient?->name ?: 'Recipient';

        Transaction::query()->updateOrCreate(
            ['transaction_id' => 'bp_gift_legacy:'.$gift->id],
            [
                'user_id' => $sender->id,
                'related_id' => $gift->id,
                'related_type' => SupporterBelievePointGift::class,
                'type' => 'bp_gift',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $sender->name,
                'available_at' => null,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => -$amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $gift->sent_at ?? $gift->created_at ?? now(),
                'meta' => array_filter([
                    'source' => 'bp_gift',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'ledger_role' => 'bp_gift',
                    'event_name' => 'BP Gift',
                    'description' => "Gift of {$amount} BP to {$recipientLabel} (completed).",
                    'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                    'supporter_believe_point_gift_id' => $gift->id,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                    'recipient_id' => $recipient?->id,
                    'recipient_email' => $recipient?->email,
                    'recipient_name' => $recipientLabel,
                    'from_type' => 'supporter',
                    'from_name' => $sender->name,
                    'from_id' => $sender->id,
                    'to_type' => 'supporter',
                    'to_name' => $recipientLabel,
                    'to_id' => $recipient?->id,
                    'points_amount' => $amount,
                    'bp_available_delta' => -$amount,
                    'bp_holding_delta' => 0,
                    'bp_wallet_delta' => -$amount,
                    'gross_amount' => $amount,
                    'occasion' => $gift->occasion,
                    'current_owner' => $sender->name,
                    'owner_type' => 'supporter',
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );
    }

    public static function recordHold(BelievePointGiftInvite $invite): void
    {
        self::upsertCanonicalFromInvite($invite->loadMissing('sender', 'recipient'));
    }

    public static function recordClaim(BelievePointGiftInvite $invite): void
    {
        self::upsertCanonicalFromInvite($invite->loadMissing('sender', 'recipient', 'gift'));
        self::pruneLegacyInviteRows($invite->id);
    }

    public static function recordHoldRefund(BelievePointGiftInvite $invite, float $refundAmount, string $reason): void
    {
        self::upsertCanonicalFromInvite(
            $invite->loadMissing('sender', 'recipient'),
            ['refund_reason' => $reason, 'refund_amount' => round(max(0, $refundAmount), 2)],
        );
        self::pruneLegacyInviteRows($invite->id);
    }

    public static function recordEmailChanged(BelievePointGiftInvite $invite, string $previousEmail): void
    {
        self::upsertCanonicalFromInvite(
            $invite->loadMissing('sender', 'recipient'),
            [
                'previous_email' => $previousEmail,
                'new_email' => $invite->recipient_email,
                'email_changed_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private static function upsertCanonicalFromInvite(BelievePointGiftInvite $invite, array $extraMeta = []): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');
        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0 || ! $invite->sender) {
            return;
        }

        $sender = $invite->sender;
        $recipient = $invite->recipient;
        $recipientLabel = self::recipientLabel($invite);
        $giftStatus = (string) $invite->status;

        [$txnStatus, $bpStatus, $description, $availableDelta, $holdingDelta, $processedAt] = match ($giftStatus) {
            BelievePointGiftInvite::STATUS_PENDING => [
                Transaction::STATUS_PENDING,
                UnifiedLedgerBpStatus::PROCESSING,
                (int) ($invite->recipient_id ?? 0) > 0
                    ? "Gift of {$amount} BP to {$recipientLabel} is holding until they accept."
                    : "Gift of {$amount} BP to {$recipientLabel} is holding until they register and claim.",
                -$amount,
                $amount,
                null,
            ],
            BelievePointGiftInvite::STATUS_CLAIMED => [
                Transaction::STATUS_COMPLETED,
                UnifiedLedgerBpStatus::AVAILABLE,
                "Gift of {$amount} BP to {$recipientLabel} was claimed.",
                -$amount,
                0.0,
                $invite->claimed_at ?? now(),
            ],
            BelievePointGiftInvite::STATUS_CANCELLED => [
                Transaction::STATUS_CANCELLED,
                UnifiedLedgerBpStatus::REVERSED,
                "Gift of {$amount} BP to {$recipientLabel} was cancelled; BP returned to Available.",
                0.0,
                0.0,
                $invite->refunded_at ?? now(),
            ],
            BelievePointGiftInvite::STATUS_EXPIRED => [
                Transaction::STATUS_CANCELLED,
                UnifiedLedgerBpStatus::REVERSED,
                "Gift of {$amount} BP to {$recipientLabel} expired; BP returned to Available.",
                0.0,
                0.0,
                $invite->refunded_at ?? now(),
            ],
            default => [
                Transaction::STATUS_PENDING,
                UnifiedLedgerBpStatus::PROCESSING,
                "Gift of {$amount} BP to {$recipientLabel}.",
                -$amount,
                $amount,
                null,
            ],
        };

        Transaction::query()->updateOrCreate(
            ['transaction_id' => 'bp_gift:'.$invite->id],
            [
                'user_id' => $sender->id,
                'related_id' => $invite->id,
                'related_type' => BelievePointGiftInvite::class,
                'type' => 'bp_gift',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => $bpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $sender->name,
                'available_at' => null,
                'status' => $txnStatus,
                'amount' => -$amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $processedAt,
                'meta' => array_filter([
                    'source' => 'bp_gift',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'ledger_role' => 'bp_gift',
                    'event_name' => 'BP Gift',
                    'description' => $description,
                    'believe_point_gift_invite_id' => $invite->id,
                    'gift_id' => $invite->id,
                    'gift_status' => $giftStatus,
                    'sender_id' => $invite->sender_id,
                    'sender_name' => $sender->name,
                    'recipient_id' => $invite->recipient_id,
                    'recipient_email' => $invite->recipient_email,
                    'recipient_name' => $recipient?->name ?? $recipientLabel,
                    'from_type' => 'supporter',
                    'from_name' => $sender->name,
                    'from_id' => $sender->id,
                    'to_type' => 'supporter',
                    'to_name' => $recipient?->name ?? $recipientLabel,
                    'to_id' => $invite->recipient_id,
                    'points_amount' => $amount,
                    'bp_available_delta' => $availableDelta,
                    'bp_holding_delta' => $holdingDelta,
                    'bp_wallet_delta' => $availableDelta,
                    'gross_amount' => $amount,
                    'occasion' => $invite->occasion,
                    'supporter_believe_point_gift_id' => $invite->supporter_believe_point_gift_id,
                    'claimed_at' => $invite->claimed_at?->toIso8601String(),
                    'current_owner' => $sender->name,
                    'owner_type' => 'supporter',
                    ...$extraMeta,
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );
    }

    private static function pruneLegacyInviteRows(int $inviteId): void
    {
        Transaction::query()
            ->where(function ($q) use ($inviteId) {
                $q->whereIn('transaction_id', [
                    'bp_gift_hold:'.$inviteId,
                    'bp_gift_claim:'.$inviteId,
                    'bp_gift_hold_refund:'.$inviteId,
                ])->orWhere('transaction_id', 'like', 'bp_gift_email_changed:'.$inviteId.':%');
            })
            ->delete();
    }

    private static function recipientLabel(BelievePointGiftInvite $invite): string
    {
        if ($invite->recipient && filled($invite->recipient->name)) {
            return (string) $invite->recipient->name;
        }

        return (string) $invite->recipient_email;
    }
}
