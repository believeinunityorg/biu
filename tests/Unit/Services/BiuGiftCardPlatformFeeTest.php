<?php

namespace Tests\Unit\Services;

use App\Services\BiuPlatformFeeService;
use Tests\TestCase;

class BiuGiftCardPlatformFeeTest extends TestCase
{
    public function test_gift_card_total_charged_is_face_value_without_tip_by_default(): void
    {
        $this->assertEqualsWithDelta(25.0, BiuPlatformFeeService::giftCardTotalChargedUsd(25.0), 0.001);
    }

    public function test_gift_card_total_charged_includes_optional_supporter_tip(): void
    {
        $this->assertEqualsWithDelta(25.25, BiuPlatformFeeService::giftCardTotalChargedUsd(25.0, 0.25), 0.001);
    }

    public function test_gift_card_platform_fee_splits_fifty_fifty_for_legacy_fees(): void
    {
        $split = BiuPlatformFeeService::splitGiftCardPlatformFee(0.50);

        $this->assertEqualsWithDelta(0.50, $split['platform_fee'], 0.001);
        $this->assertEqualsWithDelta(0.25, $split['platform_fee_biu_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $split['platform_fee_org_share'], 0.001);
        $this->assertEqualsWithDelta(50.0, $split['platform_fee_biu_share_percentage'], 0.001);
        $this->assertEqualsWithDelta(50.0, $split['platform_fee_org_share_percentage'], 0.001);
    }

    public function test_gift_card_ledger_meta_slice_tracks_supporter_tip_separately(): void
    {
        $meta = BiuPlatformFeeService::giftCardLedgerMetaSlice(25.0, 0.50);

        $this->assertEqualsWithDelta(0.0, $meta['platform_fee'], 0.001);
        $this->assertEqualsWithDelta(0.50, $meta['supporter_tip'], 0.001);
        $this->assertEqualsWithDelta(0.50, $meta['supporter_tip_org_share'], 0.001);
        $this->assertEqualsWithDelta(0.0, $meta['biu_fee'], 0.001);
        $this->assertEqualsWithDelta(25.0, $meta['gift_card_face_value'], 0.001);
        $this->assertEqualsWithDelta(25.50, $meta['gift_card_total_charged'], 0.001);
        $this->assertEqualsWithDelta(25.50, $meta['gross_amount'], 0.001);
    }

    public function test_supporter_tip_is_capped_at_maximum(): void
    {
        $this->assertEqualsWithDelta(
            100.0,
            BiuPlatformFeeService::normalizeGiftCardSupporterTip(250.0),
            0.001
        );
    }
}
