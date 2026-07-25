<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Product;
use App\Support\ConnectionHubType;

/**
 * BIU platform fee % for sales modules that charge buyers (marketplace, service hub, courses, raffles, merchant hub),
 * plus the fixed gift-card buyer platform fee.
 *
 * Gift cards: face-value card amount + fixed buyer platform fee (default $0.50) charged in Believe Points.
 * That buyer platform fee is split 50/50 between BIU and the beneficiary organization.
 * BIU also earns a share of provider commission via {@see GiftCardRevenueShareService}.
 *
 * Percent fees are applied to the sale base amount (product subtotal, ticket total, course fee, merchant cash spent — not tax/shipping).
 * For marketplace product orders this fee is added to the buyer-facing order total and should appear on receipts / invoices.
 */
final class BiuPlatformFeeService
{
    public const DEFAULT_SALES_PLATFORM_FEE_PERCENTAGE = 10.0;

    public const DEFAULT_MARKETPLACE_PRINTIFY_ORGANIZATION_FEE_PERCENTAGE = 10.0;

    public const DEFAULT_MARKETPLACE_MERCHANT_POOL_FEE_PERCENTAGE = 3.0;

    /** Fixed USD / BP platform fee added on top of gift card face value. */
    public const DEFAULT_GIFT_CARD_PLATFORM_FEE_USD = 0.50;

    /** BIU share of the buyer-facing gift card platform fee (remainder to organization). */
    public const DEFAULT_GIFT_CARD_PLATFORM_FEE_BIU_SHARE_PERCENTAGE = 50.0;

    /** Canonical admin setting (single knob on /admin/biu-fee). */
    public const SETTING_KEY_SALES = 'biu_sales_platform_fee_percentage';

    /** Marketplace: Printify + organization catalog goods (buyer-paid line subtotals). */
    public const SETTING_KEY_MARKETPLACE_PRINTIFY_ORG = 'biu_marketplace_platform_fee_printify_organization_percentage';

    /** Marketplace: merchant SKUs + org-adopted merchant pool listings (buyer-paid line subtotals). */
    public const SETTING_KEY_MARKETPLACE_MERCHANT_POOL = 'biu_marketplace_platform_fee_merchant_pool_percentage';

    /** @deprecated Fallback only */
    public const SETTING_KEY_MARKETPLACE_LEGACY = 'biu_marketplace_platform_fee_percentage';

    /** @deprecated Fallback only — Service Hub previously used this key */
    public const SETTING_KEY_SERVICE_HUB_LEGACY = 'service_hub_platform_fee_percentage';

    /** Connection Hub learning / companion / earning listings (not meetups). */
    public const SETTING_KEY_COURSE = 'biu_course_platform_fee_percentage';

    /** Connection Hub meetups / events listings. */
    public const SETTING_KEY_EVENT = 'biu_event_platform_fee_percentage';

    /** Gift card fixed buyer platform fee (USD / Believe Points). */
    public const SETTING_KEY_GIFT_CARD = 'biu_gift_card_platform_fee_usd';

    public static function getSalesPlatformFeePercentage(): float
    {
        $v = AdminSetting::get(self::SETTING_KEY_SALES);
        if ($v !== null && $v !== '') {
            return max(0.0, min(100.0, (float) $v));
        }
        $v2 = AdminSetting::get(self::SETTING_KEY_MARKETPLACE_LEGACY);
        if ($v2 !== null && $v2 !== '') {
            return max(0.0, min(100.0, (float) $v2));
        }
        $v3 = AdminSetting::get(self::SETTING_KEY_SERVICE_HUB_LEGACY);
        if ($v3 !== null && $v3 !== '') {
            return max(0.0, min(100.0, (float) $v3));
        }

        return self::DEFAULT_SALES_PLATFORM_FEE_PERCENTAGE;
    }

    public static function getMarketplacePrintifyOrganizationFeePercentage(): float
    {
        $v = AdminSetting::get(self::SETTING_KEY_MARKETPLACE_PRINTIFY_ORG);
        if ($v !== null && $v !== '') {
            return max(0.0, min(100.0, (float) $v));
        }

        return self::DEFAULT_MARKETPLACE_PRINTIFY_ORGANIZATION_FEE_PERCENTAGE;
    }

    public static function getMarketplaceMerchantPoolFeePercentage(): float
    {
        $v = AdminSetting::get(self::SETTING_KEY_MARKETPLACE_MERCHANT_POOL);
        if ($v !== null && $v !== '') {
            return max(0.0, min(100.0, (float) $v));
        }

        return self::DEFAULT_MARKETPLACE_MERCHANT_POOL_FEE_PERCENTAGE;
    }

    public static function getCoursePlatformFeePercentage(): float
    {
        $v = AdminSetting::get(self::SETTING_KEY_COURSE);
        if ($v !== null && $v !== '') {
            return max(0.0, min(100.0, (float) $v));
        }

        return self::getSalesPlatformFeePercentage();
    }

    public static function getEventPlatformFeePercentage(): float
    {
        $v = AdminSetting::get(self::SETTING_KEY_EVENT);
        if ($v !== null && $v !== '') {
            return max(0.0, min(100.0, (float) $v));
        }

        return self::getSalesPlatformFeePercentage();
    }

    /**
     * Fixed platform fee charged to the buyer on each gift card purchase (Believe Points).
     */
    public static function getGiftCardPlatformFeeUsd(): float
    {
        try {
            $v = AdminSetting::get(self::SETTING_KEY_GIFT_CARD);
        } catch (\Throwable) {
            return self::DEFAULT_GIFT_CARD_PLATFORM_FEE_USD;
        }

        if ($v !== null && $v !== '') {
            return max(0.0, round((float) $v, 2));
        }

        return self::DEFAULT_GIFT_CARD_PLATFORM_FEE_USD;
    }

    /**
     * BIU percentage of the buyer platform fee (organization receives the remainder).
     */
    public static function getGiftCardPlatformFeeBiuSharePercentage(): float
    {
        return self::DEFAULT_GIFT_CARD_PLATFORM_FEE_BIU_SHARE_PERCENTAGE;
    }

    /**
     * Split a buyer platform fee into BIU / organization shares (default 50/50).
     *
     * @return array{
     *   platform_fee: float,
     *   platform_fee_biu_share: float,
     *   platform_fee_org_share: float,
     *   platform_fee_biu_share_percentage: float,
     *   platform_fee_org_share_percentage: float
     * }
     */
    public static function splitGiftCardPlatformFee(?float $platformFeeUsd = null): array
    {
        $fee = round(max(0.0, $platformFeeUsd ?? self::getGiftCardPlatformFeeUsd()), 2);
        $biuPct = self::getGiftCardPlatformFeeBiuSharePercentage();
        $biuShare = round($fee * $biuPct / 100, 2);
        $orgShare = round($fee - $biuShare, 2);

        return [
            'platform_fee' => $fee,
            'platform_fee_biu_share' => $biuShare,
            'platform_fee_org_share' => $orgShare,
            'platform_fee_biu_share_percentage' => $biuPct,
            'platform_fee_org_share_percentage' => round(100.0 - $biuPct, 2),
        ];
    }

    /**
     * Total Believe Points charged for a gift card: face value + fixed platform fee.
     */
    public static function giftCardTotalChargedUsd(float $faceValueUsd): float
    {
        return round(max(0.0, $faceValueUsd) + self::getGiftCardPlatformFeeUsd(), 2);
    }

    /**
     * Ledger / transaction meta slice for gift card buyer platform fee (50/50 BIU / org).
     *
     * @return array{
     *   platform_fee: float,
     *   platform_fee_biu_share: float,
     *   platform_fee_org_share: float,
     *   platform_fee_biu_share_percentage: float,
     *   platform_fee_org_share_percentage: float,
     *   biu_fee: float,
     *   believe_biu_fee: float,
     *   gift_card_face_value: float,
     *   gift_card_total_charged: float,
     *   amount_gross: float,
     *   gross_amount: float,
     *   subtotal: float
     * }
     */
    public static function giftCardLedgerMetaSlice(float $faceValueUsd): array
    {
        $face = round(max(0.0, $faceValueUsd), 2);
        $split = self::splitGiftCardPlatformFee();
        $fee = $split['platform_fee'];
        $total = round($face + $fee, 2);

        return [
            'platform_fee' => $fee,
            'platform_fee_biu_share' => $split['platform_fee_biu_share'],
            'platform_fee_org_share' => $split['platform_fee_org_share'],
            'platform_fee_biu_share_percentage' => $split['platform_fee_biu_share_percentage'],
            'platform_fee_org_share_percentage' => $split['platform_fee_org_share_percentage'],
            // BIU keeps only its share of the buyer platform fee.
            'biu_fee' => $split['platform_fee_biu_share'],
            'believe_biu_fee' => $split['platform_fee_biu_share'],
            'gift_card_face_value' => $face,
            'gift_card_total_charged' => $total,
            'amount_gross' => $total,
            'gross_amount' => $total,
            'subtotal' => $face,
        ];
    }

    public static function getConnectionHubPlatformFeePercentage(Course|string|null $courseOrType): float
    {
        $type = $courseOrType instanceof Course
            ? (string) ($courseOrType->type ?? '')
            : (string) ($courseOrType ?? '');

        if (ConnectionHubType::usesEventSemantics($type)) {
            return self::getEventPlatformFeePercentage();
        }

        return self::getCoursePlatformFeePercentage();
    }

    public static function connectionHubPlatformFeeFromAmount(Course|string|null $courseOrType, float $saleBaseUsd): float
    {
        $pct = self::getConnectionHubPlatformFeePercentage($courseOrType);

        return round(max(0.0, $saleBaseUsd) * ($pct / 100), 2);
    }

    /**
     * Cart line uses the lower merchant / pool rate (merchant marketplace SKU, org pool listing, or org catalog row tied to a pool product).
     */
    public static function cartItemUsesMerchantPoolLineRate(CartItem $item): bool
    {
        $item->loadMissing(['product', 'marketplaceProduct', 'organizationProduct']);

        if ($item->marketplace_product_id) {
            return true;
        }
        if ($item->organization_product_id) {
            return true;
        }
        if ($item->product_id && $item->product) {
            return (int) ($item->product->marketplace_product_id ?? 0) > 0;
        }

        return false;
    }

    public static function productUsesMerchantPoolLineRate(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        return (int) ($product->marketplace_product_id ?? 0) > 0;
    }

    public static function getMarketplaceFeePercentageForProduct(?Product $product): float
    {
        return self::productUsesMerchantPoolLineRate($product)
            ? self::getMarketplaceMerchantPoolFeePercentage()
            : self::getMarketplacePrintifyOrganizationFeePercentage();
    }

    /**
     * Buyer-facing marketplace platform fee: per-line rate, summed.
     *
     * @return array{
     *   fee_usd: float,
     *   lines: list<array{key: string, label: string, percent: float, base_usd: float, fee_usd: float}>,
     *   effective_percent: float,
     *   printify_org_percent: float,
     *   merchant_pool_percent: float
     * }
     */
    public static function marketplaceCartBuyerPlatformFeeDetail(Cart $cart): array
    {
        $cart->loadMissing([
            'items.product',
            'items.marketplaceProduct',
            'items.organizationProduct',
        ]);

        $printifyOrgBase = 0.0;
        $merchantPoolBase = 0.0;
        foreach ($cart->items as $row) {
            $line = max(0.0, (float) $row->unit_price * (int) $row->quantity);
            if (self::cartItemUsesMerchantPoolLineRate($row)) {
                $merchantPoolBase += $line;
            } else {
                $printifyOrgBase += $line;
            }
        }

        $pctPo = self::getMarketplacePrintifyOrganizationFeePercentage();
        $pctM = self::getMarketplaceMerchantPoolFeePercentage();
        $feePo = round($printifyOrgBase * ($pctPo / 100), 2);
        $feeM = round($merchantPoolBase * ($pctM / 100), 2);
        $total = round($feePo + $feeM, 2);
        $sub = round($printifyOrgBase + $merchantPoolBase, 2);

        $lines = [];
        if ($printifyOrgBase > 0.0001) {
            $lines[] = [
                'key' => 'printify_organization',
                'label' => 'Printify & organization goods',
                'percent' => $pctPo,
                'base_usd' => round($printifyOrgBase, 2),
                'fee_usd' => $feePo,
            ];
        }
        if ($merchantPoolBase > 0.0001) {
            $lines[] = [
                'key' => 'merchant_pool',
                'label' => 'Merchant & pool goods',
                'percent' => $pctM,
                'base_usd' => round($merchantPoolBase, 2),
                'fee_usd' => $feeM,
            ];
        }

        $effective = $sub > 0.0001 ? round($total / $sub * 100, 2) : 0.0;

        return [
            'fee_usd' => $total,
            'lines' => $lines,
            'effective_percent' => $effective,
            'printify_org_percent' => $pctPo,
            'merchant_pool_percent' => $pctM,
        ];
    }

    public static function marketplaceCartBuyerPlatformFeeUsd(Cart $cart): float
    {
        return self::marketplaceCartBuyerPlatformFeeDetail($cart)['fee_usd'];
    }

    /**
     * Single product line (e.g. auction win) using marketplace tier rules.
     */
    public static function marketplaceBuyerPlatformFeeForProductAmount(float $saleBaseUsd, ?Product $product): float
    {
        $pct = self::getMarketplaceFeePercentageForProduct($product);

        return round(max(0.0, $saleBaseUsd) * ($pct / 100), 2);
    }

    public static function platformFeeFromAmount(float $saleBaseUsd): float
    {
        $pct = self::getSalesPlatformFeePercentage();

        return round(max(0.0, $saleBaseUsd) * ($pct / 100), 2);
    }

    /**
     * Merchant Hub offer cash purchases (and related catalog flows): same % as marketplace merchant SKUs & pool lines.
     */
    public static function merchantHubCatalogPlatformFeeFromAmount(float $saleBaseUsd): float
    {
        $pct = self::getMarketplaceMerchantPoolFeePercentage();

        return round(max(0.0, $saleBaseUsd) * ($pct / 100), 2);
    }

    /**
     * Merge into wallet {@see Transaction} meta for ledger / exports.
     *
     * @return array<string, float>
     */
    public static function ledgerMetaSlice(float $saleBaseUsd): array
    {
        $pf = self::platformFeeFromAmount($saleBaseUsd);
        $g = round(max(0.0, $saleBaseUsd), 2);

        return [
            'gross_amount' => $g,
            'subtotal' => $g,
            'amount_gross' => $g,
            'biu_fee' => $pf,
            'believe_biu_fee' => $pf,
            'platform_fee' => $pf,
        ];
    }

    /**
     * Ledger slice for Connection Hub course / event enrollments.
     *
     * @return array<string, float|string>
     */
    public static function connectionHubLedgerMetaSlice(Course $course, float $saleBaseUsd): array
    {
        $pf = self::connectionHubPlatformFeeFromAmount($course, $saleBaseUsd);
        $g = round(max(0.0, $saleBaseUsd), 2);
        $pct = self::getConnectionHubPlatformFeePercentage($course);

        return [
            'gross_amount' => $g,
            'subtotal' => $g,
            'amount_gross' => $g,
            'biu_fee' => $pf,
            'believe_biu_fee' => $pf,
            'platform_fee' => $pf,
            'platform_fee_percentage' => $pct,
            'connection_hub_fee_module' => ConnectionHubType::usesEventSemantics((string) ($course->type ?? ''))
                ? 'event'
                : 'course',
        ];
    }

    /**
     * Ledger slice for Merchant Hub offer redemptions — BIU fee % matches marketplace merchant/pool tier.
     *
     * @return array<string, float>
     */
    public static function merchantHubCatalogLedgerMetaSlice(float $merchandiseSubtotalUsd): array
    {
        $pf = self::merchantHubCatalogPlatformFeeFromAmount($merchandiseSubtotalUsd);
        $g = round(max(0.0, $merchandiseSubtotalUsd), 2);

        return [
            'gross_amount' => $g,
            'subtotal' => $g,
            'amount_gross' => $g,
            'biu_fee' => $pf,
            'believe_biu_fee' => $pf,
            'platform_fee' => $pf,
        ];
    }
}
