<?php

namespace App\Support;

/**
 * Client-facing BP movement model: BP Wallet → destination module on spend.
 * Presentation only — does not create separate per-module balances.
 */
final class UnifiedLedgerBpModule
{
    public const GENERAL = 'general';

    public const GIFT_CARD = 'gift_card';

    public const MARKETPLACE = 'marketplace';

    public const LEARNING_HUB = 'learning_hub';

    public const EVENTS = 'events';

    public const BRIDGE_WALLET = 'bridge_wallet';

    public const SERVICE_HUB = 'service_hub';

    public const RAFFLE = 'raffle';

    public static function generalLabel(): string
    {
        return 'BP Wallet';
    }

    public static function destinationLabel(string $destination): string
    {
        return match ($destination) {
            self::GIFT_CARD => 'Gift Card Module',
            self::MARKETPLACE => 'Marketplace Module',
            self::LEARNING_HUB => 'Connection Hub',
            self::EVENTS => 'Events',
            self::BRIDGE_WALLET => 'Believe Cash',
            self::SERVICE_HUB => 'Service Hub Module',
            self::RAFFLE => 'Raffle Module',
            self::GENERAL => self::generalLabel(),
            default => 'Module',
        };
    }

    /** Short module column label (client-facing). */
    public static function moduleColumnLabel(string $destination): string
    {
        return match ($destination) {
            self::GIFT_CARD => 'Gift Card',
            self::MARKETPLACE => 'Marketplace',
            self::LEARNING_HUB => 'Connection Hub',
            self::EVENTS => 'Events',
            self::BRIDGE_WALLET => 'Believe Cash',
            self::SERVICE_HUB => 'Service Hub',
            self::RAFFLE => 'Raffle',
            self::GENERAL => 'BP Wallet',
            default => 'BP Wallet',
        };
    }

    public static function transferEventName(string $destination): string
    {
        return match ($destination) {
            self::BRIDGE_WALLET => 'Transfer to Believe Cash',
            self::LEARNING_HUB => 'Transfer BP to Connection Hub',
            self::EVENTS => 'Transfer BP to Events',
            default => 'Transfer BP to '.self::destinationLabel($destination),
        };
    }

    /**
     * Map presenter module (+ optional hub type) to a BP destination key.
     */
    public static function destinationFromPresenterModule(string $module, ?string $connectionHubType = null): ?string
    {
        $module = strtolower(trim($module));
        $hub = strtolower(trim((string) $connectionHubType));

        if ($module === 'gift_card') {
            return self::GIFT_CARD;
        }
        if ($module === 'marketplace') {
            return self::MARKETPLACE;
        }
        if ($module === 'service_hub' || $module === 'servicehub') {
            return self::SERVICE_HUB;
        }
        if ($module === 'raffle') {
            return self::RAFFLE;
        }
        if (in_array($module, ['connection_hub', 'course'], true)) {
            if ($hub === ConnectionHubType::EVENTS || $hub === 'events') {
                return self::EVENTS;
            }

            return self::LEARNING_HUB;
        }

        return null;
    }
}
