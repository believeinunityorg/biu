<?php

namespace App\Support;

/**
 * Canonical product-module slugs + display labels for the admin Transaction Ledger.
 */
final class UnifiedLedgerModule
{
    public const GENERAL = 'general';

    public const DONATION = 'donation';

    public const CAMPAIGN = 'campaign';

    public const FUNDME = 'fundme';

    public const GIFT_CARD = 'gift_card';

    public const MARKETPLACE = 'marketplace';

    public const MERCHANT_HUB = 'merchant_hub';

    public const SERVICE_HUB = 'servicehub';

    public const CONNECTION_HUB = 'connection_hub';

    public const ORGANIZATION_SUBSCRIPTION = 'organization_subscription';

    public const SUPPORTER_SUBSCRIPTION = 'supporter_subscription';

    public const MERCHANT_SUBSCRIPTION = 'merchant_subscription';

    public const REWARD = 'reward';

    public const WALLET = 'wallet';

    public const PAYOUT = 'payout';

    public const REFUND = 'refund';

    public const ADJUSTMENT = 'adjustment';

    /**
     * Filter / option list (no duplicates). Legacy `believe_points` / `course` map via {@see normalize()}.
     *
     * @return list<string>
     */
    public static function filterOptions(): array
    {
        return [
            self::GENERAL,
            self::DONATION,
            self::CAMPAIGN,
            self::FUNDME,
            self::GIFT_CARD,
            self::MARKETPLACE,
            self::MERCHANT_HUB,
            self::SERVICE_HUB,
            self::CONNECTION_HUB,
            self::ORGANIZATION_SUBSCRIPTION,
            self::SUPPORTER_SUBSCRIPTION,
            self::MERCHANT_SUBSCRIPTION,
            self::REWARD,
            self::WALLET,
            self::PAYOUT,
            self::REFUND,
            self::ADJUSTMENT,
        ];
    }

    public static function normalize(string $module): string
    {
        $module = strtolower(trim($module));

        return match ($module) {
            'believe_points' => self::GENERAL,
            'course' => self::CONNECTION_HUB,
            'service_hub' => self::SERVICE_HUB,
            default => $module,
        };
    }

    public static function label(string $module): string
    {
        $module = self::normalize($module);

        return match ($module) {
            self::GENERAL => 'BP Wallet',
            self::DONATION => 'Donation',
            self::CAMPAIGN => 'Campaign',
            self::FUNDME => 'Support a Project',
            self::GIFT_CARD => 'Gift Card',
            self::MARKETPLACE => 'Marketplace',
            self::MERCHANT_HUB => 'Merchant Hub',
            self::SERVICE_HUB => 'Service Hub',
            self::CONNECTION_HUB => 'Connection Hub',
            self::ORGANIZATION_SUBSCRIPTION => 'Organization Subscription',
            self::SUPPORTER_SUBSCRIPTION => 'Supporter Subscription',
            self::MERCHANT_SUBSCRIPTION => 'Merchant Subscription',
            self::REWARD => 'Reward',
            self::WALLET => 'Wallet',
            self::PAYOUT => 'Payout',
            self::REFUND => 'Refund',
            self::ADJUSTMENT => 'Adjustment',
            default => str_replace('_', ' ', ucwords($module, '_')),
        };
    }
}
