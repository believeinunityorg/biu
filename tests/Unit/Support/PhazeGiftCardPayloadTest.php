<?php

namespace Tests\Unit\Support;

use App\Models\GiftCard;
use App\Support\PhazeGiftCardPayload;
use Tests\TestCase;

class PhazeGiftCardPayloadTest extends TestCase
{
    public function test_extracts_pin_and_card_number_aliases(): void
    {
        $credentials = PhazeGiftCardPayload::extractCredentials([
            'id' => 1234,
            'orderId' => 'ord-1',
            'status' => 'completed',
            'cardNumber' => '6010562000017685',
            'cardPin' => '4821',
            'howToUse' => 'Enter card number and PIN online',
            'claimUrl' => 'https://example.com/redeem/abc',
            'barcodeUrl' => 'https://example.com/barcode.png',
            'expiresAt' => '2027-12-31',
        ]);

        $this->assertSame('4821', $credentials['pin']);
        $this->assertSame('6010562000017685', $credentials['card_number']);
        $this->assertSame('https://example.com/redeem/abc', $credentials['claim_url']);
        $this->assertSame('https://example.com/barcode.png', $credentials['barcode_url']);
        $this->assertSame('Enter card number and PIN online', $credentials['redemption_instructions']);
        $this->assertNotNull($credentials['expires_at']);
        $this->assertSame('2027-12-31', $credentials['expires_at']->toDateString());
    }

    public function test_extracts_nested_voucher_details(): void
    {
        $credentials = PhazeGiftCardPayload::extractCredentials([
            'id' => 99,
            'status' => 'completed',
            'voucherDetails' => [
                'voucher' => 'WMT-ABC-123',
                'pin' => '998877',
                'card_number' => '4111111111111111',
            ],
        ]);

        $this->assertSame('998877', $credentials['pin']);
        $this->assertSame('4111111111111111', $credentials['card_number']);
        $this->assertSame('WMT-ABC-123', $credentials['voucher']);
    }

    public function test_build_credential_updates_persists_pin_without_clearing_existing_meta(): void
    {
        $giftCard = new GiftCard([
            'meta' => [
                'orderId' => 'keep-me',
                'productId' => '103188010383',
            ],
            'card_number' => '0000111122223333',
        ]);

        $updates = PhazeGiftCardPayload::buildCredentialUpdates($giftCard, [
            'id' => 555,
            'status' => 'completed',
            'cardNumber' => '6010562000017685',
            'pin' => '1234',
            'voucher' => 'VOUCHER-1',
        ], [
            'phaze_status' => 'completed',
        ]);

        $this->assertSame('1234', $updates['pin']);
        $this->assertSame('6010562000017685', $updates['card_number']);
        $this->assertSame('VOUCHER-1', $updates['voucher']);
        $this->assertSame('keep-me', $updates['meta']['orderId']);
        $this->assertSame('103188010383', $updates['meta']['productId']);
        $this->assertSame('1234', $updates['meta']['phaze_credentials']['pin']);
        $this->assertSame('completed', $updates['meta']['phaze_status']);
    }

    public function test_resolve_for_display_prefers_column_then_meta_then_payload(): void
    {
        $giftCard = new GiftCard([
            'pin' => '1111',
            'card_number' => null,
            'voucher' => null,
            'meta' => [
                'phaze_credentials' => [
                    'card_number' => '2222333344445555',
                ],
                'phaze_purchase' => [
                    'voucher' => 'FROM-PAYLOAD',
                    'pin' => '9999',
                ],
            ],
        ]);

        $resolved = PhazeGiftCardPayload::resolveForDisplay($giftCard);

        $this->assertSame('1111', $resolved['pin']);
        $this->assertSame('2222333344445555', $resolved['card_number']);
        $this->assertSame('FROM-PAYLOAD', $resolved['voucher']);
    }

    public function test_flatten_display_fields_hides_empty_values(): void
    {
        $flat = PhazeGiftCardPayload::flattenDisplayFields([
            'id' => 12,
            'status' => 'completed',
            'pin' => '1234',
            'howToUse' => '',
            'nested' => ['a' => 1],
            'commission' => 0,
        ]);

        $this->assertSame(12, $flat['id']);
        $this->assertSame('completed', $flat['status']);
        $this->assertSame('1234', $flat['pin']);
        $this->assertSame(0, $flat['commission']);
        $this->assertArrayNotHasKey('howToUse', $flat);
        $this->assertArrayNotHasKey('nested', $flat);
    }

    public function test_extracts_phaze_vouchers_array_and_processed_status(): void
    {
        $credentials = PhazeGiftCardPayload::extractCredentials([
            'id' => 6929,
            'orderId' => '5e6931f2-d5d5-474e-b4fa-437a45c1543d',
            'status' => 'processed',
            'productName' => 'Walmart',
            'vouchers' => [
                [
                    'url' => 'https://sandbox.rewardsevolved.com/redeem/?securityKey=abc',
                    'code' => '121106',
                    'validityDate' => '2026-09-17T14:53:04.595Z',
                    'faceValue' => 5,
                ],
            ],
        ]);

        $this->assertSame('processed', $credentials['status']);
        $this->assertTrue(PhazeGiftCardPayload::isProviderSuccessStatus($credentials['status']));
        $this->assertSame('Processed', PhazeGiftCardPayload::providerStatusLabel($credentials['status']));
        $this->assertSame('121106', $credentials['voucher']);
        $this->assertSame('121106', $credentials['pin']);
        $this->assertSame('https://sandbox.rewardsevolved.com/redeem/?securityKey=abc', $credentials['claim_url']);
        $this->assertNotNull($credentials['expires_at']);
        $this->assertSame('2026-09-17', $credentials['expires_at']->toDateString());
    }
}
