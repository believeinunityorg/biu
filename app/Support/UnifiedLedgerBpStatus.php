<?php

namespace App\Support;

final class UnifiedLedgerBpStatus
{
    public const PROCESSING = 'processing';

    /** Gift BP held pending claim (distinct from purchase settlement "Processing"). */
    public const HOLDING = 'holding';

    public const AVAILABLE = 'available';

    public const REVERSED = 'reversed';

    public const NA = 'n/a';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PROCESSING,
            self::HOLDING,
            self::AVAILABLE,
            self::REVERSED,
            self::NA,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PROCESSING => 'Processing',
            self::HOLDING => 'Holding',
            self::AVAILABLE, 'settled', 'settlement_available' => 'Available',
            self::REVERSED => 'Reversed',
            default => 'N/A',
        };
    }

    /** Normalize legacy stored values to the client-facing BP status set. */
    public static function normalize(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $raw = strtolower(trim($status));

        return match ($raw) {
            self::PROCESSING => self::PROCESSING,
            self::HOLDING => self::HOLDING,
            self::AVAILABLE, 'settled', 'settlement_available' => self::AVAILABLE,
            self::REVERSED, 'refunded' => self::REVERSED,
            self::NA, 'na', 'n/a' => self::NA,
            default => in_array($raw, self::all(), true) ? $raw : null,
        };
    }
}
