<?php

namespace App\Services;

/**
 * Closed-loop vs open-loop gift card classification.
 *
 * Closed-loop (Walmart, Amazon, …): buy with Available BP via Phaze.
 * Open-loop (Visa / Mastercard): not sold with Believe Points — supporters use
 * Bridge Wallet → Services → Cards (existing virtual card flow).
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
     * Open-loop network cards must be obtained via Bridge Wallet, not Phaze + BP.
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
        return 'Visa and Mastercard are open-loop cards. They are not purchased with Believe Points. Open Bridge Wallet → Services → Cards to issue a virtual card funded by your Bridge balance.';
    }
}
