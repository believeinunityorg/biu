<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Transaction;
use App\Services\Admin\UnifiedLedgerWalletAmountResolver;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerType;
use Tests\TestCase;

class UnifiedLedgerWalletAmountResolverTest extends TestCase
{
    public function test_bp_purchase_is_positive_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 25,
            'meta' => ['source' => 'believe_points_purchase_bp'],
        ]);

        $this->assertSame(25.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_bp_redemption_is_negative_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'bp_redemption',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => -10,
            'meta' => ['source' => 'bp_redemption'],
        ]);

        $this->assertSame(-10.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_wallet_transfer_refund_is_positive_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'refund',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 15,
            'meta' => [
                'source' => 'believe_points_wallet_transfer',
                'bp_wallet_delta' => 15,
            ],
        ]);

        $this->assertSame(15.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_bp_settlement_has_zero_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'bp_settlement',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 50,
            'meta' => ['source' => 'bp_settlement'],
        ]);

        $this->assertSame(0.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_bp_donation_spend_is_negative_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'donation',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => -5,
            'meta' => ['source' => 'believe_points_donation'],
        ]);

        $this->assertSame(-5.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_money_row_has_null_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 100,
            'meta' => [],
        ]);

        $this->assertNull(UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_legacy_positive_donation_amount_resolves_negative(): void
    {
        $t = new Transaction([
            'type' => 'donation',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 8,
            'meta' => ['source' => 'believe_points_donation', 'bp_status' => UnifiedLedgerBpStatus::AVAILABLE],
        ]);

        $this->assertSame(-8.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_gift_card_believe_points_purchase_is_negative_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 5.10,
            'payment_method' => 'believe_points',
            'related_type' => \App\Models\GiftCard::class,
            'meta' => [
                'gift_card_id' => 1,
                'believe_points_used' => 5.10,
                'platform_fee' => 0.10,
            ],
        ]);

        $this->assertSame(-5.10, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_marketplace_believe_points_purchase_is_negative_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 42.00,
            'payment_method' => 'believe_points',
            'meta' => ['believe_points_used' => 42.00],
        ]);

        $this->assertSame(-42.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_enrollment_believe_points_purchase_is_negative_wallet_amount(): void
    {
        $t = new Transaction([
            'type' => 'enrollment',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_method' => 'believe_points',
            'meta' => ['believe_points_used' => 25.00],
        ]);

        $this->assertSame(-25.0, UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_believe_points_deposit_stays_positive_wallet_amount_null_for_money(): void
    {
        $t = new Transaction([
            'type' => 'deposit',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 10.00,
            'payment_method' => 'believe_points',
            'meta' => ['source' => 'organization_donation'],
        ]);

        $this->assertNull(UnifiedLedgerWalletAmountResolver::resolve($t));
    }

    public function test_believe_points_refund_is_not_treated_as_spend(): void
    {
        $t = new Transaction([
            'type' => 'refund',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 5.10,
            'payment_method' => 'believe_points',
            'meta' => ['believe_points_refunded' => 5.10],
        ]);

        $this->assertNull(UnifiedLedgerWalletAmountResolver::resolve($t));
    }
}
