<?php

namespace App\Enums;

enum MembershipType: string
{
    case Free = 'free';
    case Paid = 'paid';

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
            self::Free => 'Free',
            self::Paid => 'Paid',
        };
    }
}
