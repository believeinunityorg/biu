<?php

namespace App\Enums;

enum SupporterMembershipStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Declined = 'declined';
    case NotOnList = 'not_on_list';
    case Expired = 'expired';

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
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Declined => 'Declined',
            self::NotOnList => 'Not on List',
            self::Expired => 'Expired',
        };
    }
}
