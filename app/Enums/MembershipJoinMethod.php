<?php

namespace App\Enums;

enum MembershipJoinMethod: string
{
    case OpenEnrollment = 'open_enrollment';
    case RequestToJoin = 'request_to_join';
    case InvitationOnly = 'invitation_only';

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
            self::OpenEnrollment => 'Open Enrollment',
            self::RequestToJoin => 'Request to Join',
            self::InvitationOnly => 'Invitation Only',
        };
    }
}
