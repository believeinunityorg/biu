<?php

namespace Tests\Unit\Services;

use App\Services\BiuPlatformFeeService;
use Tests\TestCase;

class BiuGiftCardPlatformFeeTest extends TestCase
{
    public function test_default_gift_card_platform_fee_is_fifty_cents(): void
    {
        $this->assertEqualsWithDelta(0.50, BiuPlatformFeeService::getGiftCardPlatformFeeUsd(), 0.001);
        $this->assertEqualsWithDelta(25.50, BiuPlatformFeeService::giftCardTotalChargedUsd(25.0), 0.001);
    }

    public function test_gift_card_platform_fee_splits_fifty_fifty(): void
    {
        $split = BiuPlatformFeeService::splitGiftCardPlatformFee(0.50);

        $this->assertEqualsWithDelta(0.50, $split['platform_fee'], 0.001);
        $this->assertEqualsWithDelta(0.25, $split['platform_fee_biu_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $split['platform_fee_org_share'], 0.001);
        $this->assertEqualsWithDelta(50.0, $split['platform_fee_biu_share_percentage'], 0.001);
        $this->assertEqualsWithDelta(50.0, $split['platform_fee_org_share_percentage'], 0.001);
    }

    public function test_gift_card_ledger_meta_slice_includes_fee_split_and_total(): void
    {
        $meta = BiuPlatformFeeService::giftCardLedgerMetaSlice(25.0);

        $this->assertEqualsWithDelta(0.50, $meta['platform_fee'], 0.001);
        $this->assertEqualsWithDelta(0.25, $meta['platform_fee_biu_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $meta['platform_fee_org_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $meta['biu_fee'], 0.001);
        $this->assertEqualsWithDelta(25.0, $meta['gift_card_face_value'], 0.001);
        $this->assertEqualsWithDelta(25.50, $meta['gift_card_total_charged'], 0.001);
        $this->assertEqualsWithDelta(25.50, $meta['gross_amount'], 0.001);
    }
}
