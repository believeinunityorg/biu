<?php

namespace Tests\Unit\Services;

use App\Services\GiftCardService;
use ReflectionMethod;
use Tests\TestCase;

class GiftCardServicePhazeSignatureTest extends TestCase
{
    public function test_normalize_phaze_price_avoids_float_noise_for_cents(): void
    {
        $service = new GiftCardService;
        $method = new ReflectionMethod(GiftCardService::class, 'normalizePhazePrice');
        $method->setAccessible(true);

        $this->assertSame(0.49, $method->invoke($service, 0.49000000000000005));
        $this->assertSame(5, $method->invoke($service, 5.0));
        $this->assertSame(5, $method->invoke($service, '5.00'));
    }

    public function test_signature_uses_exact_preencoded_body_string(): void
    {
        config([
            'services.phaze.api_secret' => 'test-secret',
        ]);

        $service = new GiftCardService;

        $encode = new ReflectionMethod(GiftCardService::class, 'encodeJsonBody');
        $encode->setAccessible(true);

        $sign = new ReflectionMethod(GiftCardService::class, 'generateSignature');
        $sign->setAccessible(true);

        $payload = [
            'orderId' => '92e38516-4fa1-4eaf-a166-4eb2074abbfc',
            'productId' => 10111329033,
            'price' => 0.49,
            'externalUserId' => '42',
        ];

        $rawBody = $encode->invoke($service, $payload);
        $fromString = $sign->invoke($service, 'POST', '/purchase', $rawBody);
        $fromArray = $sign->invoke($service, 'POST', '/purchase', $payload);

        $this->assertSame($fromString, $fromArray);
        $this->assertSame(
            hash('sha256', 'POST/purchase'.'test-secret'.$rawBody),
            $fromString
        );
        $this->assertStringContainsString('"price":0.49', $rawBody);
        $this->assertStringNotContainsString('000000', $rawBody);
    }

    public function test_encode_json_body_emits_clean_decimal_prices(): void
    {
        $service = new GiftCardService;

        $encode = new ReflectionMethod(GiftCardService::class, 'encodeJsonBody');
        $encode->setAccessible(true);
        $normalize = new ReflectionMethod(GiftCardService::class, 'normalizePhazePrice');
        $normalize->setAccessible(true);

        $rawBody = $encode->invoke($service, [
            'orderId' => 'db70cc45-8fb0-47e8-88c9-85aae1383b02',
            'productId' => 103113635416,
            'price' => $normalize->invoke($service, 9.90),
            'externalUserId' => '1',
            'baseCurrency' => 'USD',
        ]);

        $this->assertSame(
            '{"orderId":"db70cc45-8fb0-47e8-88c9-85aae1383b02","productId":103113635416,"price":9.9,"externalUserId":"1","baseCurrency":"USD"}',
            $rawBody
        );
    }

    public function test_humanize_signature_and_balance_errors(): void
    {
        $service = new GiftCardService;

        $this->assertStringContainsString(
            'signature mismatch',
            strtolower($service->humanizePhazeErrorForAdmin([
                'httpStatusCode' => 400,
                'error' => 'Signature did not match',
            ]))
        );

        $this->assertStringContainsString(
            'prefund',
            strtolower($service->humanizePhazeErrorForAdmin([
                'httpStatusCode' => 402,
                'error' => 'Account Balance too low. Account must be pre-funded.',
            ]))
        );

        $this->assertStringContainsString(
            'temporarily unavailable',
            strtolower($service->humanizePhazeErrorForUser([
                'httpStatusCode' => 402,
                'error' => 'Account Balance too low.',
            ]))
        );
    }
}
