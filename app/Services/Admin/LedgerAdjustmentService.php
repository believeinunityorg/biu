<?php

namespace App\Services\Admin;

use App\Models\Transaction;
use App\Models\User;
use App\Support\UnifiedLedgerModule;
use App\Support\UnifiedLedgerType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Permanent ledger corrections: never delete; create a linked Adjustment / Reversal / Correction.
 */
final class LedgerAdjustmentService
{
    public const SOURCE = 'admin_ledger_adjustment';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPE_CORRECTION = 'correction';

    /**
     * @return list<string>
     */
    public static function adjustmentTypes(): array
    {
        return [
            self::TYPE_ADJUSTMENT,
            self::TYPE_REVERSAL,
            self::TYPE_CORRECTION,
        ];
    }

    /**
     * @param  array{
     *     adjustment_type: string,
     *     amount_adjusted: float|int|string,
     *     previous_value: float|int|string,
     *     new_value: float|int|string,
     *     reason: string,
     *     notes?: string|null,
     *     supporting_reference?: string|null,
     *     original_status?: string|null
     * }  $input
     */
    public function create(Transaction $original, User $admin, array $input): Transaction
    {
        $adjustmentType = strtolower(trim((string) ($input['adjustment_type'] ?? '')));
        if (! in_array($adjustmentType, self::adjustmentTypes(), true)) {
            throw ValidationException::withMessages([
                'adjustment_type' => 'Adjustment type must be adjustment, reversal, or correction.',
            ]);
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason for the adjustment is required.',
            ]);
        }

        $amountAdjusted = round((float) $input['amount_adjusted'], 2);
        $previousValue = round((float) $input['previous_value'], 2);
        $newValue = round((float) $input['new_value'], 2);
        $notes = trim((string) ($input['notes'] ?? ''));
        $supportingReference = trim((string) ($input['supporting_reference'] ?? ''));
        $originalStatus = strtolower(trim((string) ($input['original_status'] ?? 'unchanged')));

        $adjustedAt = now();

        return DB::transaction(function () use (
            $original,
            $admin,
            $adjustmentType,
            $amountAdjusted,
            $previousValue,
            $newValue,
            $reason,
            $notes,
            $supportingReference,
            $originalStatus,
            $adjustedAt,
        ) {
            $original->refresh();

            $ledgerType = $original->ledger_type;
            if (! in_array($ledgerType, UnifiedLedgerType::all(), true)) {
                $ledgerType = UnifiedLedgerType::MONEY;
            }

            $adjustmentNumber = 'ADJ-'.$original->id.'-'.strtoupper(substr(uniqid(), -6));

            $meta = [
                'source' => self::SOURCE,
                'module' => UnifiedLedgerModule::ADJUSTMENT,
                'adjustment_type' => $adjustmentType,
                'adjustment_reason' => $reason,
                'adjustment_notes' => $notes !== '' ? $notes : null,
                'supporting_reference' => $supportingReference !== '' ? $supportingReference : null,
                'original_transaction_id' => $original->id,
                'original_transaction_number' => $original->transaction_id,
                'adjustment_transaction_number' => $adjustmentNumber,
                'amount_adjusted' => $amountAdjusted,
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'adjusted_by_admin_id' => $admin->id,
                'adjusted_by_admin_name' => $admin->name,
                'adjusted_by_admin_email' => $admin->email,
                'adjusted_at' => $adjustedAt->toIso8601String(),
                'description' => $this->description($adjustmentType, $original->transaction_id, $reason),
                'event_name' => $this->eventName($adjustmentType),
                'from_type' => 'admin',
                'from_name' => $admin->name,
                'from_id' => $admin->id,
                'to_type' => 'ledger',
                'to_name' => 'Transaction History',
            ];

            $adjustment = Transaction::query()->create([
                'user_id' => $original->user_id,
                'related_id' => $original->id,
                'related_type' => Transaction::class,
                'type' => 'adjustment',
                'ledger_type' => $ledgerType,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => $amountAdjusted,
                'fee' => 0,
                'currency' => $original->currency ?: 'USD',
                'payment_method' => 'admin_adjustment',
                'transaction_id' => $adjustmentNumber,
                'meta' => $meta,
                'processed_at' => $adjustedAt,
            ]);

            $originalMeta = is_array($original->meta) ? $original->meta : [];
            $linked = is_array($originalMeta['ledger_adjustment_ids'] ?? null)
                ? $originalMeta['ledger_adjustment_ids']
                : [];
            $linked[] = $adjustment->id;
            $originalMeta['ledger_adjustment_ids'] = array_values(array_unique(array_map('intval', $linked)));
            $originalMeta['has_ledger_adjustments'] = true;
            $originalMeta['last_ledger_adjustment_id'] = $adjustment->id;
            $originalMeta['last_ledger_adjustment_at'] = $adjustedAt->toIso8601String();

            $original->meta = $originalMeta;

            if ($originalStatus !== 'unchanged') {
                $statusMap = [
                    'adjusted' => Transaction::STATUS_ADJUSTED,
                    'reversed' => Transaction::STATUS_REVERSED,
                    'cancelled' => Transaction::STATUS_CANCELLED,
                    'refund' => Transaction::STATUS_REFUND,
                    'refunded' => Transaction::STATUS_REFUND,
                ];
                if (isset($statusMap[$originalStatus])) {
                    $original->status = $statusMap[$originalStatus];
                } elseif ($adjustmentType === self::TYPE_REVERSAL) {
                    $original->status = Transaction::STATUS_REVERSED;
                } else {
                    $original->status = Transaction::STATUS_ADJUSTED;
                }
            }

            $original->save();

            return $adjustment;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adjustmentsFor(Transaction $original): array
    {
        return Transaction::query()
            ->where(function ($q) use ($original) {
                $q->where(function ($link) use ($original) {
                    $link->where('related_type', Transaction::class)
                        ->where('related_id', $original->id);
                })->orWhere('meta->original_transaction_id', $original->id);
            })
            ->where(function ($q) {
                $q->where('type', 'adjustment')
                    ->orWhere('meta->source', self::SOURCE);
            })
            ->orderByDesc('id')
            ->get()
            ->map(fn (Transaction $t) => $this->serializeAdjustmentRow($t))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function originalSummary(?Transaction $original): ?array
    {
        if (! $original) {
            return null;
        }

        return [
            'id' => $original->id,
            'transaction_id' => $original->transaction_id,
            'type' => $original->type,
            'status' => $original->status,
            'amount' => (float) $original->amount,
            'currency' => $original->currency,
            'created_at' => $original->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAdjustmentRow(Transaction $t): array
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        return [
            'id' => $t->id,
            'transaction_id' => $t->transaction_id,
            'adjustment_type' => (string) ($meta['adjustment_type'] ?? 'adjustment'),
            'amount_adjusted' => isset($meta['amount_adjusted'])
                ? (float) $meta['amount_adjusted']
                : (float) $t->amount,
            'previous_value' => isset($meta['previous_value']) ? (float) $meta['previous_value'] : null,
            'new_value' => isset($meta['new_value']) ? (float) $meta['new_value'] : null,
            'reason' => (string) ($meta['adjustment_reason'] ?? ''),
            'notes' => $meta['adjustment_notes'] ?? null,
            'supporting_reference' => $meta['supporting_reference'] ?? null,
            'original_transaction_id' => isset($meta['original_transaction_id'])
                ? (int) $meta['original_transaction_id']
                : (int) ($t->related_id ?? 0),
            'original_transaction_number' => (string) ($meta['original_transaction_number'] ?? ''),
            'adjusted_by_admin_id' => isset($meta['adjusted_by_admin_id']) ? (int) $meta['adjusted_by_admin_id'] : null,
            'adjusted_by_admin_name' => (string) ($meta['adjusted_by_admin_name'] ?? ''),
            'adjusted_by_admin_email' => (string) ($meta['adjusted_by_admin_email'] ?? ''),
            'adjusted_at' => (string) ($meta['adjusted_at'] ?? $t->processed_at?->toIso8601String() ?? $t->created_at?->toIso8601String() ?? ''),
            'currency' => $t->currency,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function eventName(string $adjustmentType): string
    {
        return match ($adjustmentType) {
            self::TYPE_REVERSAL => 'Ledger Reversal',
            self::TYPE_CORRECTION => 'Ledger Correction',
            default => 'Ledger Adjustment',
        };
    }

    private function description(string $adjustmentType, string $originalNumber, string $reason): string
    {
        $label = match ($adjustmentType) {
            self::TYPE_REVERSAL => 'Reversal',
            self::TYPE_CORRECTION => 'Correction',
            default => 'Adjustment',
        };

        return sprintf('%s of %s — %s', $label, $originalNumber, $reason);
    }
}
