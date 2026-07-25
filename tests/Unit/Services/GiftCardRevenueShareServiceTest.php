<?php

namespace Tests\Unit\Services;

use App\Services\GiftCardRevenueShareService;
use Tests\TestCase;

class GiftCardRevenueShareServiceTest extends TestCase
{
    public function test_splits_provider_commission_at_ten_percent_biu_share_by_default(): void
    {
        config([
            'services.phaze.gift_card_biu_revenue_share_percentage' => 10,
        ]);

        $split = GiftCardRevenueShareService::splitProviderCommission(100.0, 5.0);

        $this->assertSame(100.0, $split['total_commission']);
        $this->assertEqualsWithDelta(10.0, $split['platform_commission'], 0.0001);
        $this->assertEqualsWithDelta(90.0, $split['nonprofit_commission'], 0.0001);
        $this->assertSame(0.0, $split['merchant_revenue']);
    }

    public function test_resolves_commission_from_phaze_payload(): void
    {
        $resolved = GiftCardRevenueShareService::resolveProviderCommission(
            ['commission' => 4.5],
            50.0
        );

        $this->assertEqualsWithDelta(4.5, $resolved['provider_commission'], 0.0001);
        $this->assertEqualsWithDelta(9.0, $resolved['commission_percentage'], 0.01);
    }

    public function test_ledger_meta_includes_fixed_buyer_platform_fee_split(): void
    {
        $meta = GiftCardRevenueShareService::ledgerMetaSlice(25.0, 2.0, 0.2, 1.8, 0.0);

        $this->assertSame(25.0, $meta['gift_card_sales']);
        $this->assertEqualsWithDelta(0.5, $meta['platform_fee'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $meta['platform_fee_biu_share'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $meta['platform_fee_org_share'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $meta['biu_fee'], 0.0001);
        $this->assertEqualsWithDelta(25.5, $meta['gross_amount'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $meta['provider_commission'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $meta['biu_revenue_share'], 0.0001);
        // BIU: fee share 0.25 + provider share 0.20
        $this->assertEqualsWithDelta(0.45, $meta['platform_payout'], 0.0001);
        $this->assertEqualsWithDelta(1.8, $meta['organization_revenue'], 0.0001);
        // Org: fee share 0.25 + provider share 1.80
        $this->assertEqualsWithDelta(2.05, $meta['organization_payout'], 0.0001);
    }

    public function test_ledger_meta_uses_recorded_fee_split_when_provided(): void
    {
        $meta = GiftCardRevenueShareService::ledgerMetaSlice(
            25.0,
            2.0,
            0.2,
            1.8,
            0.0,
            [
                'platform_fee' => 1.00,
                'platform_fee_biu_share' => 0.50,
                'platform_fee_org_share' => 0.50,
            ],
        );

        $this->assertEqualsWithDelta(1.00, $meta['platform_fee'], 0.0001);
        $this->assertEqualsWithDelta(0.50, $meta['platform_fee_biu_share'], 0.0001);
        $this->assertEqualsWithDelta(0.50, $meta['platform_fee_org_share'], 0.0001);
        $this->assertEqualsWithDelta(26.0, $meta['gross_amount'], 0.0001);
        $this->assertEqualsWithDelta(0.70, $meta['platform_payout'], 0.0001);
        $this->assertEqualsWithDelta(2.30, $meta['organization_payout'], 0.0001);
    }
}
