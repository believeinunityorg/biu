<?php

namespace App\Services;

use App\Rules\TurnstileToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileService
{
    public const FIELD = 'cf_turnstile_response';

    public function isEnabled(): bool
    {
        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        $key = config('services.turnstile.site_key');

        return filled($key) ? (string) $key : null;
    }

    /**
     * Validation rules for Inertia/API form posts. Empty when Turnstile is not configured.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public static function rules(): array
    {
        $service = app(self::class);

        if (! $service->isEnabled()) {
            return [];
        }

        return [
            self::FIELD => ['required', 'string', new TurnstileToken],
        ];
    }

    /**
     * Verify a Turnstile response token with Cloudflare.
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $secret = (string) config('services.turnstile.secret_key');
        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];

        if (filled($remoteIp)) {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', $payload);

            $json = $response->json();
            $success = (bool) ($json['success'] ?? false);

            if (! $success) {
                Log::info('Turnstile verification failed', [
                    'error_codes' => $json['error-codes'] ?? [],
                    'status' => $response->status(),
                ]);
            }

            return $success;
        } catch (ConnectionException $e) {
            Log::warning('Turnstile siteverify connection error', [
                'error' => $e->getMessage(),
            ]);

            // Fail closed when configured — bots must not bypass on network blips.
            return false;
        } catch (\Throwable $e) {
            Log::warning('Turnstile siteverify error', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Shared Inertia props for the frontend widget.
     *
     * @return array{enabled: bool, siteKey: string|null}
     */
    public function sharedProps(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'siteKey' => $this->siteKey(),
        ];
    }
}
