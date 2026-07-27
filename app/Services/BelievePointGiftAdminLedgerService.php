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
 * Gift BP admin ledger — one immutable row per business event:
 * Gift Sent · Gift Claimed · Gift Cancelled · Gift Expired · Gift Refunded
 */
final class BelievePointGiftAdminLedgerService
{
    public static function syncFromInvite(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');
        if (! $invite->sender || round((float) $invite->amount, 2) <= 0) {
            return;
        }

        self::writeGiftSent($invite);

        match ($invite->status) {
            BelievePointGiftInvite::STATUS_CLAIMED => self::writeGiftClaimed($invite),
            BelievePointGiftInvite::STATUS_CANCELLED => self::writeCancelOrExpireWithRefund($invite, 'cancelled'),
            BelievePointGiftInvite::STATUS_EXPIRED => self::writeCancelOrExpireWithRefund($invite, 'expired'),
            default => null,
        };

        self::pruneObsoleteRows($invite->id);
    }

    public static function syncFromLegacyGift(SupporterBelievePointGift $gift): void
    {
        $gift->loadMissing('sender', 'recipient');
        if (! $gift->sender || round((float) $gift->amount, 2) <= 0) {
            return;
        }

        if (BelievePointGiftInvite::query()->where('supporter_believe_point_gift_id', $gift->id)->exists()) {
            return;
        }

        $sender = $gift->sender;
        $recipient = $gift->recipient;
        $amount = round((float) $gift->amount, 2);
        $recipientLabel = $recipient?->name ?: ($recipient?->email ?: 'Recipient');
        $when = $gift->sent_at ?? $gift->created_at ?? now();

        // Immediate legacy transfer: document as Sent + Claimed for audit completeness.
        self::upsertEvent(
            transactionId: 'bp_gift_sent:legacy:'.$gift->id,
            user: $sender,
            relatedId: $gift->id,
            relatedType: SupporterBelievePointGift::class,
            type: 'bp_gift_sent',
            status: Transaction::STATUS_COMPLETED,
            amount: -$amount,
            bpStatus: UnifiedLedgerBpStatus::HOLDING,
            eventName: 'Gift Sent',
            description: "Gift of {$amount} BP to {$recipientLabel} was sent (legacy immediate gift).",
            currentOwner: $sender->name,
            availableDelta: -$amount,
            holdingDelta: $amount,
            recipientBpDelta: 0,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'supporter_believe_point_gift_id' => $gift->id,
                'occasion' => $gift->occasion,
                'legacy_immediate' => true,
            ]),
        );

        self::upsertEvent(
            transactionId: 'bp_gift_claimed:legacy:'.$gift->id,
            user: $recipient ?? $sender,
            relatedId: $gift->id,
            relatedType: SupporterBelievePointGift::class,
            type: 'bp_gift_claimed',
            status: Transaction::STATUS_COMPLETED,
            amount: $amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            eventName: 'Gift Claimed',
            description: "{$sender->name} gifted {$amount} BP — Available +{$amount}; Gift BP reporting +{$amount}.",
            currentOwner: $recipient?->name ?? $recipientLabel,
            availableDelta: $amount,
            holdingDelta: -$amount,
            recipientBpDelta: $amount,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'supporter_believe_point_gift_id' => $gift->id,
                'gifted_bp_delta' => $amount,
                'occasion' => $gift->occasion,
                'legacy_immediate' => true,
            ]),
        );
    }

    public static function recordHold(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient');
        self::writeGiftSent($invite);
    }

    /**
     * Immediate gift to an existing supporter (no Holding): Available → Available + Gift reporting.
     */
    public static function recordImmediateGift(SupporterBelievePointGift $gift): void
    {
        $gift->loadMissing('sender', 'recipient');
        if (! $gift->sender || round((float) $gift->amount, 2) <= 0) {
            return;
        }

        $sender = $gift->sender;
        $recipient = $gift->recipient;
        $amount = round((float) $gift->amount, 2);
        $recipientLabel = $recipient?->name ?: ($recipient?->email ?: 'Recipient');
        $when = $gift->sent_at ?? $gift->created_at ?? now();

        self::upsertEvent(
            transactionId: 'bp_gift_sent:immediate:'.$gift->id,
            user: $sender,
            relatedId: $gift->id,
            relatedType: SupporterBelievePointGift::class,
            type: 'bp_gift_sent',
            status: Transaction::STATUS_COMPLETED,
            amount: -$amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            eventName: 'Gift Sent',
            description: "Gift of {$amount} BP to {$recipientLabel}: Available −{$amount}. Delivered immediately.",
            currentOwner: $recipient?->name ?? $recipientLabel,
            availableDelta: -$amount,
            holdingDelta: 0,
            recipientBpDelta: 0,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'supporter_believe_point_gift_id' => $gift->id,
                'occasion' => $gift->occasion,
                'immediate' => true,
            ]),
        );

        if (! $recipient) {
            return;
        }

        self::upsertEvent(
            transactionId: 'bp_gift_claimed:immediate:'.$gift->id,
            user: $recipient,
            relatedId: $gift->id,
            relatedType: SupporterBelievePointGift::class,
            type: 'bp_gift_claimed',
            status: Transaction::STATUS_COMPLETED,
            amount: $amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            eventName: 'Gift Claimed',
            description: "Gift received: Available +{$amount}; Gift BP reporting +{$amount}.",
            currentOwner: $recipient->name,
            availableDelta: $amount,
            holdingDelta: 0,
            recipientBpDelta: $amount,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'supporter_believe_point_gift_id' => $gift->id,
                'gifted_bp_delta' => $amount,
                'occasion' => $gift->occasion,
                'immediate' => true,
            ]),
        );
    }

    public static function recordClaim(BelievePointGiftInvite $invite): void
    {
        $invite->loadMissing('sender', 'recipient', 'gift');
        self::writeGiftSent($invite);
        self::writeGiftClaimed($invite);
        self::pruneObsoleteRows($invite->id);
    }

    public static function recordHoldRefund(BelievePointGiftInvite $invite, float $refundAmount, string $reason): void
    {
        $invite->loadMissing('sender', 'recipient');
        self::writeGiftSent($invite);
        $kind = str_contains(strtolower($reason), 'expir')
            || $invite->status === BelievePointGiftInvite::STATUS_EXPIRED
            ? 'expired'
            : 'cancelled';
        self::writeCancelOrExpireWithRefund($invite, $kind, $refundAmount);
        self::pruneObsoleteRows($invite->id);
    }

    public static function recordEmailChanged(BelievePointGiftInvite $invite, string $previousEmail): void
    {
        // Not a BP movement — no ledger row (client: only Sent/Claimed/Cancelled/Expired/Refunded).
        unset($invite, $previousEmail);
    }

    private static function writeGiftSent(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $recipient = $invite->recipient;
        $recipientLabel = self::recipientLabel($invite);
        $stillPending = $invite->status === BelievePointGiftInvite::STATUS_PENDING;

        self::upsertEvent(
            transactionId: 'bp_gift_sent:'.$invite->id,
            user: $sender,
            relatedId: $invite->id,
            relatedType: BelievePointGiftInvite::class,
            type: 'bp_gift_sent',
            status: $stillPending ? Transaction::STATUS_PENDING : Transaction::STATUS_COMPLETED,
            amount: -$amount,
            bpStatus: UnifiedLedgerBpStatus::HOLDING,
            eventName: 'Gift Sent',
            description: "Gift of {$amount} BP to {$recipientLabel}: Available −{$amount}, Holding +{$amount}. Recipient BP unchanged until claim.",
            currentOwner: $sender->name,
            availableDelta: -$amount,
            holdingDelta: $amount,
            recipientBpDelta: 0,
            processedAt: $invite->created_at ?? now(),
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'believe_point_gift_invite_id' => $invite->id,
                'gift_id' => $invite->id,
                'gift_status' => BelievePointGiftInvite::STATUS_PENDING,
                'occasion' => $invite->occasion,
            ]),
        );
    }

    private static function writeGiftClaimed(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        $recipient = $invite->recipient;
        if (! $sender || ! $recipient) {
            return;
        }

        $amount = round((float) $invite->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $recipientLabel = self::recipientLabel($invite);

        self::upsertEvent(
            transactionId: 'bp_gift_claimed:'.$invite->id,
            user: $recipient,
            relatedId: $invite->id,
            relatedType: BelievePointGiftInvite::class,
            type: 'bp_gift_claimed',
            status: Transaction::STATUS_COMPLETED,
            amount: $amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            eventName: 'Gift Claimed',
            description: "Gift claimed: sender Holding −{$amount}; {$recipientLabel} Available +{$amount} (Gift BP reporting +{$amount}).",
            currentOwner: $recipient->name,
            availableDelta: $amount,
            holdingDelta: -$amount,
            recipientBpDelta: $amount,
            processedAt: $invite->claimed_at ?? now(),
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'believe_point_gift_invite_id' => $invite->id,
                'gift_id' => $invite->id,
                'gift_status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'gifted_bp_delta' => $amount,
                'supporter_believe_point_gift_id' => $invite->supporter_believe_point_gift_id,
                'claimed_at' => $invite->claimed_at?->toIso8601String(),
                'occasion' => $invite->occasion,
            ]),
        );
    }

    private static function writeCancelOrExpireWithRefund(
        BelievePointGiftInvite $invite,
        string $kind,
        ?float $refundAmount = null,
    ): void {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        $amount = round((float) ($refundAmount ?? $invite->amount), 2);
        if ($amount <= 0) {
            return;
        }

        $recipient = $invite->recipient;
        $recipientLabel = self::recipientLabel($invite);
        $isExpired = $kind === 'expired';
        $eventType = $isExpired ? 'bp_gift_expired' : 'bp_gift_cancelled';
        $eventName = $isExpired ? 'Gift Expired' : 'Gift Cancelled';
        $when = $invite->refunded_at ?? $invite->cancelled_at ?? now();

        self::upsertEvent(
            transactionId: ($isExpired ? 'bp_gift_expired:' : 'bp_gift_cancelled:').$invite->id,
            user: $sender,
            relatedId: $invite->id,
            relatedType: BelievePointGiftInvite::class,
            type: $eventType,
            status: Transaction::STATUS_CANCELLED,
            amount: 0,
            bpStatus: UnifiedLedgerBpStatus::HOLDING,
            eventName: $eventName,
            description: $isExpired
                ? "Gift of {$amount} BP to {$recipientLabel} expired before claim."
                : "Gift of {$amount} BP to {$recipientLabel} was cancelled before claim.",
            currentOwner: $sender->name,
            availableDelta: 0,
            holdingDelta: 0,
            recipientBpDelta: 0,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'believe_point_gift_invite_id' => $invite->id,
                'gift_id' => $invite->id,
                'gift_status' => $isExpired
                    ? BelievePointGiftInvite::STATUS_EXPIRED
                    : BelievePointGiftInvite::STATUS_CANCELLED,
                'occasion' => $invite->occasion,
            ]),
        );

        self::upsertEvent(
            transactionId: 'bp_gift_refunded:'.$invite->id,
            user: $sender,
            relatedId: $invite->id,
            relatedType: BelievePointGiftInvite::class,
            type: 'bp_gift_refunded',
            status: Transaction::STATUS_COMPLETED,
            amount: $amount,
            bpStatus: UnifiedLedgerBpStatus::AVAILABLE,
            eventName: 'Gift Refunded',
            description: "Gift refund: Holding −{$amount}, Available +{$amount} returned to {$sender->name}.",
            currentOwner: $sender->name,
            availableDelta: $amount,
            holdingDelta: -$amount,
            recipientBpDelta: 0,
            processedAt: $when,
            meta: self::partyMeta($sender, $recipient, $recipientLabel, [
                'believe_point_gift_invite_id' => $invite->id,
                'gift_id' => $invite->id,
                'gift_status' => $isExpired
                    ? BelievePointGiftInvite::STATUS_EXPIRED
                    : BelievePointGiftInvite::STATUS_CANCELLED,
                'refund_reason' => $isExpired ? 'expired' : 'cancelled',
                'occasion' => $invite->occasion,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function upsertEvent(
        string $transactionId,
        User $user,
        int $relatedId,
        string $relatedType,
        string $type,
        string $status,
        float $amount,
        string $bpStatus,
        string $eventName,
        string $description,
        string $currentOwner,
        float $availableDelta,
        float $holdingDelta,
        float $recipientBpDelta,
        mixed $processedAt,
        array $meta,
    ): void {
        Transaction::query()->updateOrCreate(
            ['transaction_id' => $transactionId],
            [
                'user_id' => $user->id,
                'related_id' => $relatedId,
                'related_type' => $relatedType,
                'type' => $type,
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => $bpStatus,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'current_owner' => $currentOwner,
                'available_at' => null,
                'status' => $status,
                'amount' => $amount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'processed_at' => $processedAt,
                'meta' => array_filter([
                    'source' => $type,
                    'ledger_type' => UnifiedLedgerType::BP,
                    'ledger_role' => $type,
                    'event_name' => $eventName,
                    'description' => $description,
                    'points_amount' => abs($amount) > 0 ? abs($amount) : abs($availableDelta ?: $holdingDelta),
                    'bp_available_delta' => $availableDelta,
                    'bp_holding_delta' => $holdingDelta,
                    'bp_recipient_delta' => $recipientBpDelta,
                    'bp_wallet_delta' => $availableDelta,
                    'gross_amount' => abs($availableDelta ?: $holdingDelta ?: $amount),
                    'current_owner' => $currentOwner,
                    'owner_type' => 'supporter',
                    ...$meta,
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function partyMeta(?User $sender, ?User $recipient, string $recipientLabel, array $extra = []): array
    {
        return array_merge([
            'sender_id' => $sender?->id,
            'sender_name' => $sender?->name,
            'recipient_id' => $recipient?->id,
            'recipient_email' => $recipient?->email,
            'recipient_name' => $recipient?->name ?? $recipientLabel,
            'from_type' => 'supporter',
            'from_name' => $sender?->name,
            'from_id' => $sender?->id,
            'to_type' => 'supporter',
            'to_name' => $recipient?->name ?? $recipientLabel,
            'to_id' => $recipient?->id,
        ], $extra);
    }

    private static function pruneObsoleteRows(int $inviteId): void
    {
        Transaction::query()
            ->where(function ($q) use ($inviteId) {
                $q->whereIn('transaction_id', [
                    'bp_gift:'.$inviteId,
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
