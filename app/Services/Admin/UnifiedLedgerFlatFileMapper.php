<?php

namespace App\Services\Admin;

use App\Models\Transaction;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerType;

/**
 * Maps unified ledger rows to the client's reconciliation flat-file columns (CSV / ETL).
 *
 * @see UnifiedLedgerPresenter::present()
 */
class UnifiedLedgerFlatFileMapper
{
    /** @var list<string> */
    public const CSV_HEADERS = [
        'transaction_number',
        'datetime',
        'status',
        'ledger_type',
        'bp_status',
        'available_at',
        'current_owner',
        'brp_activity_type',
        'module',
        'event',
        'transaction_type',
        'from_type',
        'from_id',
        'from_name',
        'to_type',
        'to_id',
        'to_name',
        'wallet_type',
        'wallet_amount',
        'bp_credit',
        'bp_debit',
        'processing_bp',
        'available_bp',
        'balance_after_transaction',
        'gross_amount',
        'payment_processing_fee',
        'platform_fee_amount',
        'net_amount',
        'provider',
        'payment_method',
        'payment_intent_id',
        'charge_id',
        'balance_transaction_id',
        'related_type',
        'related_id',
        'description',
        'from_to',
        'subscriber_name',
        'subscriber_email',
        'organization_name',
        'organization_ein',
        'merchant_name',
        'campaign_name',
        'event_name',
        'processor_fee_amount',
        'split_amount',
        'refund_amount',
        'supplier_payout_amount',
        'organization_payout_amount',
        'platform_payout_amount',
        'related_name',
        'processed_at',
    ];

    /** @var array<string, string> */
    public const CSV_HEADER_LABELS = [
        'transaction_number' => 'Transaction Number',
        'datetime' => 'Date/Time',
        'status' => 'Status',
        'ledger_type' => 'Ledger Type',
        'bp_status' => 'BP Status',
        'available_at' => 'Available Date',
        'current_owner' => 'Current Owner',
        'brp_activity_type' => 'BRP Activity',
        'module' => 'Module',
        'event' => 'Event',
        'transaction_type' => 'Transaction Type',
        'from_type' => 'From Type',
        'from_id' => 'From ID',
        'from_name' => 'From Name',
        'to_type' => 'To Type',
        'to_id' => 'To ID',
        'to_name' => 'To Name',
        'wallet_type' => 'Wallet Type',
        'wallet_amount' => 'Wallet Amount',
        'bp_credit' => 'BP Credit',
        'bp_debit' => 'BP Debit',
        'processing_bp' => 'Processing BP',
        'available_bp' => 'Available BP',
        'balance_after_transaction' => 'Balance After Transaction',
        'gross_amount' => 'Gross Amount',
        'payment_processing_fee' => 'Payment Provider Fee',
        'platform_fee_amount' => 'Platform Fee',
        'net_amount' => 'Net Amount',
        'provider' => 'Provider',
        'payment_method' => 'Payment Method',
        'payment_intent_id' => 'Payment Intent ID',
        'charge_id' => 'Charge ID',
        'balance_transaction_id' => 'Balance Transaction ID',
        'related_type' => 'Related Type',
        'related_id' => 'Related ID',
        'description' => 'Description',
        'from_to' => 'From → To',
        'subscriber_name' => 'Subscriber Name',
        'subscriber_email' => 'Subscriber Email',
        'organization_name' => 'Organization Name',
        'organization_ein' => 'Organization EIN',
        'merchant_name' => 'Merchant Name',
        'campaign_name' => 'Campaign Name',
        'event_name' => 'Event Name',
        'processor_fee_amount' => 'Payment Provider Fee Amount',
        'split_amount' => 'Split Amount',
        'refund_amount' => 'Refund Amount',
        'supplier_payout_amount' => 'Supplier Payout Amount',
        'organization_payout_amount' => 'Organization Payout Amount',
        'platform_payout_amount' => 'Platform Payout Amount',
        'related_name' => 'Related',
        'processed_at' => 'Processed At',
    ];

    /**
     * Human-readable Excel / CSV column titles (same order as {@see CSV_HEADERS}).
     *
     * @return list<string>
     */
    public static function exportHeadings(): array
    {
        return array_map(
            static fn (string $key): string => self::CSV_HEADER_LABELS[$key] ?? $key,
            self::CSV_HEADERS,
        );
    }

    /**
     * @param  array<string, mixed>  $unified  Output of UnifiedLedgerPresenter::present()
     * @param  array{related_kind?: string, related_label?: string, related_display_name?: string, related_purpose?: string, related_source?: string}  $related
     * @return array<string, string|int|float|null>
     */
    public function map(Transaction $t, array $unified, array $related = []): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $txnNumber = (string) ($t->transaction_id !== null && $t->transaction_id !== ''
            ? $t->transaction_id
            : 'TXN-'.$t->id);

        $module = (string) ($unified['module'] ?? '');
        $walletType = $this->resolveWalletType($t, $module, $meta);
        $bpAmounts = $this->resolveBelievePointAmounts($t, $meta, $module);
        $ledgerFields = UnifiedLedgerClassificationService::presentForTransaction($t);

        $row = [
            'transaction_number' => $txnNumber,
            'datetime' => (string) ($unified['datetime_iso'] ?? ''),
            'status' => (string) ($unified['status'] ?? $t->status ?? ''),
            'ledger_type' => (string) ($ledgerFields['ledger_type_label'] ?? 'Money'),
            'bp_status' => (string) ($ledgerFields['bp_status_label'] ?? 'N/A'),
            'available_at' => (string) ($ledgerFields['available_at'] ?? ''),
            'current_owner' => (string) ($ledgerFields['current_owner'] ?? ''),
            'brp_activity_type' => (string) ($ledgerFields['brp_activity_label'] ?? 'N/A'),
            'module' => $this->moduleTableLabel($module),
            'event' => (string) ($unified['event_name'] ?? $meta['event_name'] ?? ''),
            'transaction_type' => $this->humanizeTransactionType((string) ($unified['transaction_type'] ?? '')),
            'from_type' => (string) ($unified['from_type'] ?? ''),
            'from_id' => $this->nullableInt($unified['from_id'] ?? null),
            'from_name' => (string) ($unified['from_name'] ?? ''),
            'to_type' => (string) ($unified['to_type'] ?? ''),
            'to_id' => $this->nullableInt($unified['to_id'] ?? null),
            'to_name' => (string) ($unified['to_name'] ?? ''),
            'wallet_type' => $walletType,
            'wallet_amount' => $this->nullableMoney($bpAmounts['wallet_amount'] ?? null),
            'bp_credit' => $this->nullableMoney($bpAmounts['credit'] ?? null),
            'bp_debit' => $this->nullableMoney($bpAmounts['debit'] ?? null),
            'processing_bp' => $this->nullableMoney($bpAmounts['processing'] ?? null),
            'available_bp' => $this->nullableMoney($bpAmounts['available'] ?? null),
            'balance_after_transaction' => $this->nullableMoney($bpAmounts['balance_after'] ?? null),
            'gross_amount' => $this->money($unified['gross_amount'] ?? 0),
            'payment_processing_fee' => $this->money($unified['processor_fee_amount'] ?? 0),
            'platform_fee_amount' => $this->money($unified['biu_fee_amount'] ?? 0),
            'net_amount' => $this->nullableMoney($unified['net_amount'] ?? null),
            'provider' => (string) ($unified['provider'] ?? ''),
            'payment_method' => (string) ($t->payment_method ?? ''),
            'payment_intent_id' => $this->stripeId($meta, [
                'stripe_payment_intent', 'stripe_payment_intent_id', 'payment_intent_id',
            ], $t->transaction_id),
            'charge_id' => $this->stripeId($meta, ['stripe_charge_id', 'charge_id']),
            'balance_transaction_id' => $this->stripeId($meta, [
                'stripe_balance_transaction_id', 'balance_transaction_id',
            ]),
            'related_type' => $t->related_type ? class_basename((string) $t->related_type) : '',
            'related_id' => $this->nullableInt($t->related_id),
            'description' => (string) ($meta['description'] ?? $related['related_label'] ?? ''),
            'from_to' => $this->partiesSummary($unified),
            'subscriber_name' => (string) ($unified['subscriber_name'] ?? ''),
            'subscriber_email' => (string) ($unified['subscriber_email'] ?? ''),
            'organization_name' => (string) ($unified['organization_name'] ?? ''),
            'organization_ein' => (string) ($unified['organization_ein'] ?? ''),
            'merchant_name' => (string) ($unified['merchant_name'] ?? ''),
            'campaign_name' => (string) ($unified['campaign_name'] ?? ''),
            'event_name' => (string) ($unified['event_name'] ?? ''),
            'processor_fee_amount' => $this->money($unified['processor_fee_amount'] ?? 0),
            'split_amount' => $this->money($unified['split_amount'] ?? 0),
            'refund_amount' => $this->money($unified['refund_amount'] ?? 0),
            'supplier_payout_amount' => $this->nullableMoney($unified['supplier_payout_amount'] ?? null),
            'organization_payout_amount' => $this->nullableMoney($unified['organization_payout_amount'] ?? null),
            'platform_payout_amount' => $this->nullableMoney($unified['platform_payout_amount'] ?? null),
            'related_name' => $this->resolveRelatedName($related, $unified),
            'processed_at' => (string) ($unified['datetime_iso'] ?? ''),
        ];

        return $row;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{credit: float|null, debit: float|null, processing: float|null, available: float|null, balance_after: float|null, wallet_amount: float|null}
     */
    private function resolveBelievePointAmounts(Transaction $t, array $meta, string $module): array
    {
        $ledgerType = UnifiedLedgerClassificationService::classify($t)['ledger_type'];
        $walletAmount = UnifiedLedgerWalletAmountResolver::resolve($t);

        if ($ledgerType === UnifiedLedgerType::BRP && $walletAmount !== null) {
            $points = round(abs($walletAmount), 2);
            $brpStatus = UnifiedLedgerBpStatus::normalize((string) ($t->bp_status ?? $meta['brp_status'] ?? $meta['bp_status'] ?? ''));

            if ($brpStatus === UnifiedLedgerBpStatus::PROCESSING) {
                return [
                    'credit' => $walletAmount > 0 ? $points : null,
                    'debit' => $walletAmount < 0 ? $points : null,
                    'processing' => $walletAmount > 0 ? $points : null,
                    'available' => null,
                    'balance_after' => $walletAmount > 0 ? $points : null,
                    'wallet_amount' => $walletAmount,
                ];
            }

            if ($brpStatus === UnifiedLedgerBpStatus::AVAILABLE) {
                return [
                    'credit' => $walletAmount > 0 ? $points : null,
                    'debit' => $walletAmount < 0 ? $points : null,
                    'processing' => null,
                    'available' => $walletAmount > 0 ? $points : null,
                    'balance_after' => $walletAmount > 0 ? $points : null,
                    'wallet_amount' => $walletAmount,
                ];
            }

            return [
                'credit' => $walletAmount > 0 ? abs($walletAmount) : null,
                'debit' => $walletAmount < 0 ? abs($walletAmount) : null,
                'processing' => null,
                'available' => null,
                'balance_after' => null,
                'wallet_amount' => $walletAmount,
            ];
        }

        if ($ledgerType !== UnifiedLedgerType::BP && $module !== 'believe_points') {
            return [
                'credit' => null,
                'debit' => null,
                'processing' => null,
                'available' => null,
                'balance_after' => null,
                'wallet_amount' => null,
            ];
        }

        $bucketColumns = $this->resolveBpBucketColumns($t, $meta);
        $credit = $walletAmount !== null && $walletAmount > 0 ? abs($walletAmount) : null;
        $debit = $walletAmount !== null && $walletAmount < 0 ? abs($walletAmount) : null;

        return [
            'credit' => $credit,
            'debit' => $debit,
            'processing' => $bucketColumns['processing'],
            'available' => $bucketColumns['available'],
            'balance_after' => $bucketColumns['balance_after'],
            'wallet_amount' => $walletAmount,
        ];
    }

    /**
     * Bucket snapshot columns for export (processing vs available), separate from wallet balance delta.
     *
     * @param  array<string, mixed>  $meta
     * @return array{processing: float|null, available: float|null, balance_after: float|null}
     */
    private function resolveBpBucketColumns(Transaction $t, array $meta): array
    {
        $points = round((float) ($meta['intended_points'] ?? $meta['points_credited'] ?? $meta['points_amount'] ?? abs((float) $t->amount)), 2);
        $source = (string) ($meta['source'] ?? '');

        if ($source === 'bp_settlement' || $t->type === 'bp_settlement') {
            return [
                'processing' => null,
                'available' => $points > 0 ? $points : null,
                'balance_after' => $points > 0 ? $points : null,
            ];
        }

        if ($source === 'believe_points_purchase' && ($meta['bp_status'] ?? '') === 'processing') {
            return [
                'processing' => $points > 0 ? $points : null,
                'available' => null,
                'balance_after' => $points > 0 ? $points : null,
            ];
        }

        if ($source === 'believe_points_purchase' || $source === 'believe_points_purchase_bp') {
            return [
                'processing' => ($meta['bp_status'] ?? '') === 'available' ? null : ($points > 0 ? $points : null),
                'available' => ($meta['bp_status'] ?? '') === 'available' ? $points : null,
                'balance_after' => $points > 0 ? $points : null,
            ];
        }

        return [
            'processing' => null,
            'available' => null,
            'balance_after' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveWalletType(Transaction $t, string $module, array $meta): string
    {
        if ($module === 'believe_points') {
            return 'believe_point_wallet';
        }
        if ($module === 'wallet') {
            return 'bridge_wallet';
        }
        if (($meta['source'] ?? '') === 'believe_points_purchase' || str_starts_with((string) ($t->transaction_id ?? ''), 'pi_')) {
            return 'payment_ledger';
        }

        return 'payment_ledger';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $keys
     */
    private function stripeId(array $meta, array $keys, ?string $fallback = null): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($fallback && str_starts_with($fallback, 'pi_')) {
            return $fallback;
        }

        return '';
    }

    private function nullableInt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) (int) $value;
    }

    /**
     * Matches admin ledger table partiesSummary() in ledger.tsx.
     *
     * @param  array<string, mixed>  $unified
     */
    private function partiesSummary(array $unified): string
    {
        $module = (string) ($unified['module'] ?? '');

        if ($module === 'believe_points') {
            $name = trim((string) (($unified['from_name'] ?? '') !== '' ? $unified['from_name'] : ($unified['from_type'] ?? '')));

            return $name !== '' ? 'Purchaser: '.$name : '';
        }

        $from = trim((string) (($unified['from_name'] ?? '') !== '' ? $unified['from_name'] : ($unified['from_type'] ?? '')));
        $to = trim((string) (($unified['to_name'] ?? '') !== '' ? $unified['to_name'] : ($unified['to_type'] ?? '')));

        if ($from === '' && $to === '') {
            return '';
        }

        if ($from === '') {
            return $to;
        }

        if ($to === '') {
            return $from;
        }

        return $from.' → '.$to;
    }

    /** Same labels as moduleTableLabel() in ledger.tsx. */
    private function moduleTableLabel(string $module): string
    {
        return match ($module) {
            'donation' => 'Donation',
            'fundme' => 'Support a project',
            'campaign' => 'Campaign',
            'believe_points' => 'Believe Points',
            'reward' => 'Reward',
            'wallet' => 'Wallet',
            'marketplace' => 'Marketplace',
            'gift_card' => 'Gift card',
            'servicehub' => 'Service Hub',
            'course' => 'Course',
            'connection_hub' => 'Connection Hub',
            'merchant_hub' => 'Merchant Hub',
            'organization_subscription' => 'Org sub',
            'supporter_subscription' => 'Supporter sub',
            'merchant_subscription' => 'Merchant sub',
            'payout' => 'Payout',
            'refund' => 'Refund',
            'adjustment' => 'Adjustment',
            default => str_replace('_', ' ', $module),
        };
    }

    private function humanizeTransactionType(string $type): string
    {
        return str_replace('_', ' ', $this->normalizeTransactionType($type));
    }

    /**
     * Same human-readable label as the admin ledger "Related" column.
     *
     * @param  array{related_kind?: string, related_label?: string, related_display_name?: string}  $related
     * @param  array<string, mixed>  $unified
     */
    private function resolveRelatedName(array $related, array $unified): string
    {
        foreach ([
            (string) ($related['related_display_name'] ?? ''),
            (string) ($unified['related_record'] ?? ''),
            (string) ($related['related_label'] ?? ''),
            (string) ($related['related_kind'] ?? ''),
        ] as $candidate) {
            $value = trim($candidate);
            if ($value !== '' && $value !== '—') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Client vocabulary: map internal-only types to their normalized set where needed.
     */
    private function normalizeTransactionType(string $type): string
    {
        return match ($type) {
            'tax_refund' => 'payment_refund',
            default => $type,
        };
    }

    private function money(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0.00';
        }

        return number_format((float) $v, 2, '.', '');
    }

    private function nullableMoney(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return number_format((float) $v, 2, '.', '');
    }
}
