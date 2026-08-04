<?php

namespace Tests\Unit\Services;

use App\Services\TurnstileService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileServiceTest extends TestCase
{
    public function test_disabled_when_keys_missing(): void
    {
        config([
            'services.turnstile.site_key' => null,
            'services.turnstile.secret_key' => null,
        ]);

        $service = app(TurnstileService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertTrue($service->verify(null));
        $this->assertSame([], TurnstileService::rules());
    }

    public function test_verify_success_when_enabled(): void
    {
        config([
            'services.turnstile.site_key' => 'site-test',
            'services.turnstile.secret_key' => 'secret-test',
        ]);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $service = app(TurnstileService::class);

        $this->assertTrue($service->isEnabled());
        $this->assertTrue($service->verify('token-abc', '127.0.0.1'));
        $this->assertArrayHasKey(TurnstileService::FIELD, TurnstileService::rules());
    }

    public function test_verify_fails_when_cloudflare_rejects(): void
    {
        config([
            'services.turnstile.site_key' => 'site-test',
            'services.turnstile.secret_key' => 'secret-test',
        ]);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $this->assertFalse(app(TurnstileService::class)->verify('bad-token'));
    }
}
