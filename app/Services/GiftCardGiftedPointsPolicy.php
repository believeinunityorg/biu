<?php

namespace App\Services;

/**
 * Closed-loop vs open-loop gift card classification.
 *
 * Closed-loop (Walmart, Amazon, …): buy with Available BP via Phaze.
 * Open-loop (Visa / Mastercard): pay with BIU Wallet balance (Prime Supporters)
 * into the platform reserve, then delayed Phaze fulfillment.
 */
class GiftCardGiftedPointsPolicy
{
    public static function isClosedLoop(?string $productDisplayName): bool
    {
        return self::isAllowedForGiftedRedemption($productDisplayName);
    }

    public static function isOpenLoop(?string $productDisplayName): bool
    {
        return ! self::isClosedLoop($productDisplayName);
    }

    /**
     * Open-loop network cards must be paid with BIU Wallet, not Believe Points.
     */
    public static function requiresBridgeWallet(?string $productDisplayName): bool
    {
        return self::isOpenLoop($productDisplayName);
    }

    /**
     * True for closed-loop retail cards (BP purchase allowed; Gift BP reporting may decrease).
     * False for Visa/Mastercard open-loop network cards.
     */
    public static function isAllowedForGiftedRedemption(?string $productDisplayName): bool
    {
        if ($productDisplayName === null || trim($productDisplayName) === '') {
            return false;
        }

        $n = mb_strtolower($productDisplayName);

        if (preg_match('/\bvisa\b/u', $n)) {
            return false;
        }

        if (preg_match('/\bmastercard\b/u', $n)) {
            return false;
        }

        if (preg_match('/\bmaster card\b/u', $n)) {
            return false;
        }

        return true;
    }

    public static function openLoopBridgeMessage(): string
    {
        return 'Visa and Mastercard are open-loop cards. Pay with BIU Wallet balance (Prime Supporters) — Believe Points cannot buy them.';
    }

    public static function openLoopPrimeRequiredMessage(): string
    {
        return 'Visa and Mastercard gift cards paid with BIU Wallet are available for Prime Supporters only.';
    }
}
