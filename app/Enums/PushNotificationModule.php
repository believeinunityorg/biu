<?php

namespace App\Enums;

enum PushNotificationModule: string
{
    case Campaigns = 'campaigns';
    case Donations = 'donations';
    case Events = 'events';
    case Email = 'email';
    case Courses = 'courses';
    case Marketplace = 'marketplace';
    case Volunteer = 'volunteer';
    case SocialFeed = 'social_feed';
    case UnityLive = 'unity_live';
    case UnityMeet = 'unity_meet';
    case Chat = 'chat';
    case WalletRewards = 'wallet_rewards';
    case Membership = 'membership';
    case Proximity = 'proximity';
    case DailyEngagement = 'daily_engagement';
    case Projects = 'projects';
    case System = 'system';
    case GiftCard = 'gift_card';
    case MerchantHub = 'merchant_hub';
    case SupportTicket = 'support_ticket';
    case Fundraiser = 'fundraiser';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Campaigns->value => 'Campaign',
            self::Donations->value => 'Donation',
            self::Events->value => 'Event',
            self::Email->value => 'Email',
            self::Courses->value => 'Course',
            self::Marketplace->value => 'Marketplace',
            self::Volunteer->value => 'Volunteer',
            self::SocialFeed->value => 'Social Feed & Groups',
            self::UnityLive->value => 'Unity Live',
            self::UnityMeet->value => 'Unity Meet',
            self::Chat->value => 'Chat',
            self::WalletRewards->value => 'Wallet',
            self::Membership->value => 'Membership Management',
            self::Proximity->value => 'Proximity Alerts',
            self::DailyEngagement->value => 'Daily Engagement',
            self::Projects->value => 'Project Management',
            self::System->value => 'System',
            self::GiftCard->value => 'Gift Card',
            self::MerchantHub->value => 'Merchant Hub',
            self::SupportTicket->value => 'Support Ticket',
            self::Fundraiser->value => 'Fundraiser',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
