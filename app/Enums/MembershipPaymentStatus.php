<?php

namespace App\Enums;

enum MembershipPaymentStatus: string
{
    case Waived = 'waived';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Refunded = 'refunded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Waived => 'Waived',
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
        };
    }
}
