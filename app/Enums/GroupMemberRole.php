<?php

namespace App\Enums;

enum GroupMemberRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Member = 'member';

    public function canModerate(): bool
    {
        return $this === self::Admin || $this === self::Moderator;
    }

    public function canManageMembers(): bool
    {
        return $this === self::Admin;
    }
}
