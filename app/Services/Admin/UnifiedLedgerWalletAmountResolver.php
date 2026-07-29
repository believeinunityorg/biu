<?php

namespace App\Services\Admin;

use App\Models\Transaction;
use App\Support\UnifiedLedgerType;

/**
 * Wallet Amount = signed change to the supporter's total BP balance (ending − beginning).
 *
 * Not derived from event labels alone — reflects whether available + processing BP went up or down.
 */
final class UnifiedLedgerWalletAmountResolver
{
    /**
     * Signed BP/BRP wallet balance change. Null when the row is not a points wallet line.
     */
    public static function resolve(Transaction $transaction): ?float
    {
        $classified = UnifiedLedgerClassificationService::classify($transaction);
        $ledgerType = $classified['ledger_type'];
        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $stored = round((float) $transaction->amount, 2);

        if ($ledgerType === UnifiedLedgerType::BRP) {
            return $stored === 0.0 ? null : $stored;
        }

        $sourceEarly = (string) ($meta['source'] ?? '');
        $typeEarly = (string) $transaction->type;

        // Processing → Available is a status change inside BP Wallet — total BP unchanged.
        // Check before bp_wallet_delta / spend heuristics so live rows never show ±N.
        if ($typeEarly === 'bp_settlement' || $sourceEarly === 'bp_settlement') {
            return 0.0;
        }

        if (isset($meta['bp_wallet_delta']) && is_numeric($meta['bp_wallet_delta'])) {
            return round((float) $meta['bp_wallet_delta'], 2);
        }

        // Gift cards, marketplace, courses, raffles, etc. store positive Money amounts with
        // payment_method=believe_points. Wallet Amount must show BP leaving the supporter (−).
        if (self::isBelievePointsPaidSpend($transaction, $meta)) {
            $points = round(abs($stored), 2);

            return $points > 0.0 ? -$points : null;
        }

        if ($ledgerType !== UnifiedLedgerType::BP) {
            return null;
        }

        $source = $sourceEarly;
        $type = $typeEarly;

        // Refunded / reversed BP rows credit the supporter (+BP).
        if (strtoupper((string) ($transaction->currency ?? '')) === 'BP'
            && $transaction->status === Transaction::STATUS_REFUND
            && $stored > 0) {
            return $stored;
        }

        $points = round(abs($stored), 2);
        if ($points <= 0.0 && $stored === 0.0) {
            return null;
        }

        // Prefer signed amount on BP currency rows (balance delta stored at write time).
        if (strtoupper((string) ($transaction->currency ?? '')) === 'BP') {
            if ($stored < 0) {
                return $stored;
            }

            // Legacy rows stored positive amounts for debits (donation spend, etc.).
            return self::isBpDebitEvent($source, $type, $transaction, $meta) ? -$points : $stored;
        }

        return $stored !== 0.0 ? $stored : null;
    }

    /**
     * Commerce / product rows paid with Believe Points (not deposits or refunds that restore BP).
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function isBelievePointsPaidSpend(Transaction $transaction, ?array $meta = null): bool
    {
        $meta ??= is_array($transaction->meta) ? $transaction->meta : [];

        $pm = strtolower((string) ($transaction->payment_method ?? ''));
        if ($pm !== 'believe_points') {
            return false;
        }

        // Org/recipient deposits and refunds that restore BP stay credits (+).
        if (in_array((string) $transaction->type, ['deposit', 'credit', 'refund', 'bp_settlement'], true)) {
            return false;
        }

        if ($transaction->status === Transaction::STATUS_REFUND) {
            return false;
        }

        // Explicit ledger roles that credit the wallet.
        $role = strtolower((string) ($meta['ledger_role'] ?? ''));
        if (in_array($role, ['bp_credit', 'wallet_transfer_refund', 'gift_claim', 'gift_received'], true)) {
            return false;
        }

        $source = strtolower((string) ($meta['source'] ?? ''));
        if (in_array($source, [
            'believe_points_purchase_bp',
            'believe_points_purchase',
            'bp_gift_claimed',
            'bp_gift_received',
            'bp_settlement',
        ], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function isBpDebitEvent(string $source, string $type, Transaction $transaction, array $meta): bool
    {
        if (in_array($source, [
            'bp_redemption',
            'believe_points_donation',
            'gift_card_purchase',
        ], true)) {
            return true;
        }

        if (in_array($type, [
            'bp_redemption',
            'gift_card_purchase',
        ], true)) {
            return true;
        }

        $rt = ltrim((string) ($transaction->related_type ?? ''), '\\');
        if ($rt !== '' && (str_ends_with($rt, 'GiftCard') || class_basename($rt) === 'GiftCard')) {
            return true;
        }

        if (! empty($meta['gift_card_id'])) {
            return true;
        }

        return false;
    }
}
