<?php

namespace App\Services;

/**
 * Closed-loop vs open-loop gift card classification.
 *
 * Closed-loop: buy with Available BP; Gift BP reporting decreases with the spend.
 * Open-loop (Visa/Mastercard): Believe Points cannot purchase — use Bridge Wallet.
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
     * True for closed-loop retail cards (Gift BP reporting may decrease on purchase).
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
}
