<?php

namespace App\Enums;

enum FamilyMemberStatus: string
{
    case Active = 'active';
    case Unclaimed = 'unclaimed';
    case Merged = 'merged';
    case Deceased = 'deceased';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Unclaimed => 'Unclaimed',
            self::Merged => 'Merged',
            self::Deceased => 'Deceased',
            self::Archived => 'Archived',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
