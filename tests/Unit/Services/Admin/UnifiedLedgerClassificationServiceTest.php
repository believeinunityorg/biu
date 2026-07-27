<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Transaction;
use App\Services\Admin\UnifiedLedgerClassificationService;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerBrpActivity;
use App\Support\UnifiedLedgerOwner;
use App\Support\UnifiedLedgerType;
use Tests\TestCase;

class UnifiedLedgerClassificationServiceTest extends TestCase
{
    public function test_money_bp_purchase_row_classifies_as_money(): void
    {
        $transaction = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'bp_status' => UnifiedLedgerBpStatus::NA,
            'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
            'meta' => ['source' => 'believe_points_purchase'],
        ]);

        $classified = UnifiedLedgerClassificationService::classify($transaction);

        $this->assertSame(UnifiedLedgerType::MONEY, $classified['ledger_type']);
        $this->assertSame(UnifiedLedgerBpStatus::NA, $classified['bp_status']);
    }

    public function test_bp_credit_row_classifies_with_processing_status(): void
    {
        $transaction = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 5,
            'bp_status' => UnifiedLedgerBpStatus::PROCESSING,
            'current_owner' => 'Kenneth Matthews',
            'meta' => ['source' => 'believe_points_purchase_bp', 'owner_type' => 'supporter'],
        ]);

        $present = UnifiedLedgerClassificationService::presentForTransaction($transaction);

        $this->assertSame('Believe Points (BP)', $present['ledger_type_label']);
        $this->assertSame('Processing', $present['bp_status_label']);
        $this->assertSame('N/A', $present['brp_activity_label']);
        $this->assertSame('Kenneth Matthews', $present['current_owner']);
        $this->assertNull($present['available_at']);
    }

    public function test_bp_settlement_row_classifies_as_available(): void
    {
        $transaction = new Transaction([
            'type' => 'bp_settlement',
            'ledger_type' => UnifiedLedgerType::BP,
            'currency' => 'BP',
            'amount' => 5,
            'meta' => ['source' => 'bp_settlement'],
        ]);

        $classified = UnifiedLedgerClassificationService::classify($transaction);

        $this->assertSame(UnifiedLedgerBpStatus::AVAILABLE, $classified['bp_status']);
    }

    public function test_legacy_settled_bp_status_normalizes_to_available(): void
    {
        $transaction = new Transaction([
            'type' => 'bp_settlement',
            'ledger_type' => UnifiedLedgerType::BP,
            'bp_status' => 'settled',
            'meta' => ['source' => 'bp_settlement'],
        ]);

        $present = UnifiedLedgerClassificationService::presentForTransaction($transaction);

        $this->assertSame('Available', $present['bp_status_label']);
    }

    public function test_infers_brp_redeemed_from_debit_amount(): void
    {
        $transaction = new Transaction([
            'type' => 'redemption',
            'ledger_type' => UnifiedLedgerType::BRP,
            'currency' => 'BRP',
            'amount' => -5,
            'brp_activity_type' => UnifiedLedgerBrpActivity::REDEEMED,
            'meta' => ['source' => 'reward_point_ledger'],
        ]);

        $classified = UnifiedLedgerClassificationService::classify($transaction);

        $this->assertSame(UnifiedLedgerType::BRP, $classified['ledger_type']);
        $this->assertSame(UnifiedLedgerBrpActivity::REDEEMED, $classified['brp_activity_type']);
    }

    public function test_gift_card_believe_points_spend_classifies_as_bp_even_when_stored_as_money(): void
    {
        $transaction = new Transaction([
            'type' => 'purchase',
            'ledger_type' => UnifiedLedgerType::MONEY,
            'currency' => 'USD',
            'amount' => 5.10,
            'payment_method' => 'believe_points',
            'related_type' => \App\Models\GiftCard::class,
            'meta' => [
                'gift_card_id' => 1,
                'believe_points_used' => 5.10,
            ],
        ]);

        $present = UnifiedLedgerClassificationService::presentForTransaction($transaction);

        $this->assertSame(UnifiedLedgerType::BP, $present['ledger_type']);
        $this->assertSame('Believe Points (BP)', $present['ledger_type_label']);
        $this->assertSame('Available', $present['bp_status_label']);
    }

    public function test_marketplace_believe_points_spend_infers_bp_when_ledger_type_null(): void
    {
        $transaction = new Transaction([
            'type' => 'purchase',
            'currency' => 'USD',
            'amount' => 20,
            'payment_method' => 'believe_points',
            'meta' => ['believe_points_used' => 20],
        ]);

        $classified = UnifiedLedgerClassificationService::classify($transaction);

        $this->assertSame(UnifiedLedgerType::BP, $classified['ledger_type']);
        $this->assertSame(UnifiedLedgerBpStatus::AVAILABLE, $classified['bp_status']);
    }

    public function test_believe_points_deposit_stays_money(): void
    {
        $transaction = new Transaction([
            'type' => 'deposit',
            'currency' => 'USD',
            'amount' => 10,
            'payment_method' => 'believe_points',
            'meta' => ['source' => 'organization_donation'],
        ]);

        $classified = UnifiedLedgerClassificationService::classify($transaction);

        $this->assertSame(UnifiedLedgerType::MONEY, $classified['ledger_type']);
    }
}
