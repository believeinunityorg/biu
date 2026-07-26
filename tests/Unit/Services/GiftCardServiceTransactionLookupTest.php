<?php

namespace Tests\Unit\Services;

use App\Services\GiftCardService;
use App\Support\PhazeGiftCardPayload;
use Tests\TestCase;

class GiftCardServiceTransactionLookupTest extends TestCase
{
    public function test_is_phaze_error_response_detection(): void
    {
        $service = new GiftCardService;
        $ref = new \ReflectionClass(GiftCardService::class);
        $method = $ref->getMethod('isPhazeErrorResponse');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, [
            'httpStatusCode' => 404,
            'error' => 'not found',
        ]));

        $this->assertFalse($method->invoke($service, [
            'id' => 6929,
            'status' => 'processed',
            'orderId' => 'abc',
        ]));
    }

    public function test_provider_success_status_includes_processed(): void
    {
        $this->assertTrue(PhazeGiftCardPayload::isProviderSuccessStatus('processed'));
        $this->assertTrue(PhazeGiftCardPayload::isProviderSuccessStatus('Processed'));
        $this->assertSame('Processed', PhazeGiftCardPayload::providerStatusLabel('processed'));
        $this->assertFalse(PhazeGiftCardPayload::isProviderSuccessStatus('pending'));
    }
}
