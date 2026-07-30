<?php

namespace App\Enums;

enum SupporterOnboardingPurpose: string
{
    case SupportOrganizations = 'support_organizations';
    case Volunteer = 'volunteer';
    case CommunityEvents = 'community_events';
    case Invitation = 'invitation';
    case JoiningOrganization = 'joining_organization';
    case Exploring = 'exploring';

    public function label(): string
    {
        return match ($this) {
            self::SupportOrganizations => 'I want to support organizations',
            self::Volunteer => 'I want to volunteer',
            self::CommunityEvents => 'I\'m looking for community events',
            self::Invitation => 'I received an invitation',
            self::JoiningOrganization => 'I\'m joining an organization',
            self::Exploring => 'I\'m just exploring',
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
