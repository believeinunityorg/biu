<?php

namespace App\Support;

use App\Services\BiuPlatformFeeService;

/**
 * Canonical registry of BIU platform-fee modules for the admin UI and future module onboarding.
 *
 * To add a new module:
 * 1. Add a getter / setting key on {@see BiuPlatformFeeService} (if it needs its own knob).
 * 2. Register the module here with payer model, fee type, and optional linked_setting_field.
 * 3. Apply the fee in that module's checkout / ledger using BiuPlatformFeeService.
 */
final class BiuPlatformFeeModuleRegistry
{
    /** @return list<array{key: string, label: string}> */
    public static function groups(): array
    {
        return [
            ['key' => 'commerce', 'label' => 'Commerce'],
            ['key' => 'connection_hub', 'label' => 'Connection Hub'],
            ['key' => 'programs', 'label' => 'Programs & services'],
            ['key' => 'gift_cards', 'label' => 'Gift cards'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'id' => 'marketplace_printify_org',
                'group' => 'commerce',
                'name' => 'Marketplace — Printify & organization goods',
                'short_name' => 'Printify / org catalog',
                'description' => 'Organization storefront catalog lines (Printify or manual) that are not merchant-pool listings. Fee is added per line at checkout.',
                'fee_type' => 'percentage',
                'setting_field' => 'marketplace_printify_organization_fee_percentage',
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => true,
                'example_base_usd' => 60.0,
                'sort' => 10,
            ],
            [
                'id' => 'marketplace_merchant_pool',
                'group' => 'commerce',
                'name' => 'Marketplace — Merchant & merchant pool',
                'short_name' => 'Merchant / pool SKUs',
                'description' => 'Direct merchant marketplace SKUs, organization pool adoptions, and catalog rows linked to a merchant pool product.',
                'fee_type' => 'percentage',
                'setting_field' => 'marketplace_merchant_pool_fee_percentage',
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => true,
                'example_base_usd' => 40.0,
                'sort' => 20,
            ],
            [
                'id' => 'marketplace_auctions',
                'group' => 'commerce',
                'name' => 'Marketplace — Auction wins',
                'short_name' => 'Auction wins',
                'description' => 'Winning bid checkout uses the same tier rules as marketplace product lines (Printify/org vs merchant/pool).',
                'fee_type' => 'percentage',
                'setting_field' => null,
                'linked_setting_field' => 'marketplace_merchant_pool_fee_percentage',
                'linked_setting_field_alt' => 'marketplace_printify_organization_fee_percentage',
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => true,
                'example_base_usd' => 100.0,
                'sort' => 25,
            ],
            [
                'id' => 'connection_hub_courses',
                'group' => 'connection_hub',
                'name' => 'Connection Hub — Courses',
                'short_name' => 'Courses',
                'description' => 'Learning, companion, and earning listings. Added on top of the listing fee at checkout; not refunded if the host cancels.',
                'fee_type' => 'percentage',
                'setting_field' => 'course_platform_fee_percentage',
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => false,
                'example_base_usd' => 100.0,
                'sort' => 30,
            ],
            [
                'id' => 'connection_hub_events',
                'group' => 'connection_hub',
                'name' => 'Connection Hub — Meetups & events',
                'short_name' => 'Meetups / events',
                'description' => 'Events hub registrations. Added on top of the registration fee; withheld from BP refunds when the host cancels.',
                'fee_type' => 'percentage',
                'setting_field' => 'event_platform_fee_percentage',
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => false,
                'example_base_usd' => 100.0,
                'sort' => 40,
            ],
            [
                'id' => 'service_hub',
                'group' => 'programs',
                'name' => 'Service Hub',
                'short_name' => 'Service Hub',
                'description' => 'Deducted from seller earnings — not added to the buyer total. Stripe / BP transaction fees are configured separately.',
                'fee_type' => 'percentage',
                'setting_field' => null,
                'linked_setting_field' => 'sales_platform_fee_percentage',
                'payer' => 'seller',
                'checkout' => 'deducted',
                'refundable' => false,
                'example_base_usd' => 100.0,
                'sort' => 50,
                'admin_link' => '/settings/service-hub',
            ],
            [
                'id' => 'raffles',
                'group' => 'programs',
                'name' => 'Raffles',
                'short_name' => 'Raffles',
                'description' => 'Buyer pays ticket face value only. Platform fee is recorded as an administrative fee deducted from the organization payout on fulfillment.',
                'fee_type' => 'percentage',
                'setting_field' => null,
                'linked_setting_field' => 'sales_platform_fee_percentage',
                'payer' => 'organization',
                'checkout' => 'post_sale',
                'refundable' => false,
                'example_base_usd' => 100.0,
                'sort' => 60,
            ],
            [
                'id' => 'merchant_hub_cash',
                'group' => 'programs',
                'name' => 'Merchant Hub — Cash redemptions',
                'short_name' => 'Merchant Hub cash',
                'description' => 'Cash checkouts for merchant hub offers. Uses the same rate as marketplace merchant / pool lines.',
                'fee_type' => 'percentage',
                'setting_field' => null,
                'linked_setting_field' => 'marketplace_merchant_pool_fee_percentage',
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => true,
                'example_base_usd' => 50.0,
                'sort' => 70,
            ],
            [
                'id' => 'global_sales',
                'group' => 'programs',
                'name' => 'Global sales platform fee',
                'short_name' => 'Global sales %',
                'description' => 'Default percentage for modules that share one rate (Service Hub, raffles). Connection Hub and marketplace tiers override when set.',
                'fee_type' => 'percentage',
                'setting_field' => 'sales_platform_fee_percentage',
                'linked_setting_field' => null,
                'payer' => 'mixed',
                'checkout' => 'varies',
                'refundable' => false,
                'example_base_usd' => 100.0,
                'sort' => 5,
                'is_primary_knob' => true,
            ],
            [
                'id' => 'gift_cards_legacy',
                'group' => 'gift_cards',
                'name' => 'Gift cards — legacy fixed fee',
                'short_name' => 'Legacy fixed fee',
                'description' => 'Fixed buyer fee on legacy purchases (50/50 BIU / org split). New purchases use optional supporter tips instead.',
                'fee_type' => 'fixed_usd',
                'setting_field' => 'gift_card_platform_fee_usd',
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'added',
                'refundable' => false,
                'example_base_usd' => 25.0,
                'sort' => 80,
                'deprecated' => true,
                'admin_link' => '/admin/gift-card-revenue',
            ],
            [
                'id' => 'gift_cards_supporter_tip',
                'group' => 'gift_cards',
                'name' => 'Gift cards — supporter tip (current)',
                'short_name' => 'Supporter tip',
                'description' => 'Optional tip at purchase (100% to beneficiary organization). No BIU platform fee on new gift card purchases.',
                'fee_type' => 'none',
                'setting_field' => null,
                'linked_setting_field' => null,
                'payer' => 'buyer',
                'checkout' => 'optional_tip',
                'refundable' => false,
                'example_base_usd' => 25.0,
                'sort' => 90,
                'admin_link' => '/admin/gift-card-revenue',
            ],
        ];
    }

    /** @return list<string> */
    public static function editableSettingFields(): array
    {
        $fields = [];
        foreach (self::definitions() as $module) {
            $field = $module['setting_field'] ?? null;
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<string, float|string>  $values  Current form values keyed by setting_field
     * @return list<array<string, mixed>>
     */
    public static function modulesForAdmin(array $values): array
    {
        $modules = [];
        foreach (self::definitions() as $definition) {
            $modules[] = self::hydrateModule($definition, $values);
        }

        usort($modules, fn (array $a, array $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        return $modules;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, float|string>  $values
     * @return array<string, mixed>
     */
    private static function hydrateModule(array $definition, array $values): array
    {
        $feeType = (string) ($definition['fee_type'] ?? 'percentage');
        $settingField = $definition['setting_field'] ?? null;
        $linkedField = $definition['linked_setting_field'] ?? null;

        $effectiveValue = null;
        $effectiveUnit = null;
        $rateSource = null;
        $editable = is_string($settingField) && $settingField !== '';

        if ($feeType === 'percentage') {
            $effectiveUnit = 'percent';
            if ($editable) {
                $effectiveValue = self::floatValue($values[$settingField] ?? 0);
                $rateSource = 'direct';
            } elseif (is_string($linkedField) && $linkedField !== '') {
                $effectiveValue = self::floatValue($values[$linkedField] ?? 0);
                $rateSource = $linkedField;
            }
        } elseif ($feeType === 'fixed_usd') {
            $effectiveUnit = 'usd';
            if ($editable) {
                $effectiveValue = self::floatValue($values[$settingField] ?? 0);
                $rateSource = 'direct';
            }
        }

        $exampleBase = (float) ($definition['example_base_usd'] ?? 100.0);
        $exampleFee = null;
        if ($feeType === 'percentage' && $effectiveValue !== null) {
            $exampleFee = round($exampleBase * $effectiveValue / 100, 2);
        } elseif ($feeType === 'fixed_usd' && $effectiveValue !== null) {
            $exampleFee = round($effectiveValue, 2);
        }

        return array_merge($definition, [
            'editable' => $editable,
            'effective_value' => $effectiveValue,
            'effective_unit' => $effectiveUnit,
            'rate_source' => $rateSource,
            'example_fee_usd' => $exampleFee,
        ]);
    }

    /** @return array<string, float> */
    public static function currentValues(): array
    {
        return [
            'sales_platform_fee_percentage' => BiuPlatformFeeService::getSalesPlatformFeePercentage(),
            'course_platform_fee_percentage' => BiuPlatformFeeService::getCoursePlatformFeePercentage(),
            'event_platform_fee_percentage' => BiuPlatformFeeService::getEventPlatformFeePercentage(),
            'marketplace_printify_organization_fee_percentage' => BiuPlatformFeeService::getMarketplacePrintifyOrganizationFeePercentage(),
            'marketplace_merchant_pool_fee_percentage' => BiuPlatformFeeService::getMarketplaceMerchantPoolFeePercentage(),
            'gift_card_platform_fee_usd' => BiuPlatformFeeService::getGiftCardPlatformFeeUsd(),
        ];
    }

    private static function floatValue(float|string|null $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }
}
