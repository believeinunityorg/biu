<?php

namespace App\Enums;

enum OrganizationAnnouncementStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
            self::Hidden => 'Hidden',
        };
    }

    public function isPubliclyListable(): bool
    {
        return $this === self::Published;
    }
}
