<?php

namespace App\Services;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Twilio credentials — admin Settings → Twilio only (payment_methods name=twilio).
 * No .env fallback for SID / token / From. Unsaved admin credentials = messaging will not work.
 *
 * Stored columns:
 * - client_id = Account SID
 * - client_secret = Auth Token
 * - mode_environment = sandbox|live (UI hint for WhatsApp sender type)
 * - additional_config.whatsapp_from, sms_from, sms_messaging_service_sid, enabled
 */
class TwilioConfigService
{
    public const METHOD_NAME = 'twilio';

    private static bool $applied = false;

    /**
     * @return array{
     *   account_sid: string|null,
     *   auth_token: string|null,
     *   whatsapp_from: string|null,
     *   sms_from: string|null,
     *   sms_messaging_service_sid: string|null,
     *   mode_environment: string,
     *   enabled: bool,
     *   source: 'database'|'none',
     *   configured: bool
     * }
     */
    public static function resolved(): array
    {
        $db = self::fromDatabase();

        if ($db !== null && ! empty($db['account_sid']) && ! empty($db['auth_token'])) {
            return [
                'account_sid' => $db['account_sid'],
                'auth_token' => $db['auth_token'],
                'whatsapp_from' => $db['whatsapp_from'],
                'sms_from' => $db['sms_from'],
                'sms_messaging_service_sid' => $db['sms_messaging_service_sid'],
                'mode_environment' => $db['mode_environment'],
                'enabled' => $db['enabled'],
                'source' => 'database',
                'configured' => true,
            ];
        }

        return [
            'account_sid' => null,
            'auth_token' => null,
            'whatsapp_from' => null,
            'sms_from' => null,
            'sms_messaging_service_sid' => null,
            'mode_environment' => 'sandbox',
            'enabled' => false,
            'source' => 'none',
            'configured' => false,
        ];
    }

    public static function isConfigured(): bool
    {
        return self::resolved()['configured'] === true;
    }

    /**
     * @return array{
     *   account_sid: string|null,
     *   auth_token: string|null,
     *   whatsapp_from: string|null,
     *   sms_from: string|null,
     *   sms_messaging_service_sid: string|null,
     *   mode_environment: string,
     *   enabled: bool
     * }|null
     */
    public static function fromDatabase(): ?array
    {
        try {
            if (! Schema::hasTable('payment_methods')) {
                return null;
            }

            $row = PaymentMethod::getConfig(self::METHOD_NAME);
            if (! $row) {
                return null;
            }

            $extra = is_array($row->additional_config) ? $row->additional_config : [];

            return [
                'account_sid' => self::nullableString($row->client_id),
                'auth_token' => self::nullableString($row->client_secret),
                'whatsapp_from' => self::nullableString($extra['whatsapp_from'] ?? null),
                'sms_from' => self::nullableString($extra['sms_from'] ?? null),
                'sms_messaging_service_sid' => self::nullableString($extra['sms_messaging_service_sid'] ?? null),
                'mode_environment' => in_array(($row->mode_environment ?? ''), ['sandbox', 'live'], true)
                    ? $row->mode_environment
                    : 'sandbox',
                'enabled' => array_key_exists('enabled', $extra)
                    ? (bool) $extra['enabled']
                    : true,
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to load Twilio credentials from database', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Push DB credentials into runtime config. Clears SID/token/From when not saved in admin
     * so leftover .env values in config/services.php cannot send messages.
     */
    public static function applyToConfig(bool $force = false): void
    {
        if (self::$applied && ! $force) {
            return;
        }
        self::$applied = true;

        try {
            $creds = self::resolved();

            if ($creds['configured'] && ($creds['enabled'] ?? true)) {
                Config::set('services.twilio.sid', $creds['account_sid']);
                Config::set('services.twilio.token', $creds['auth_token']);
                Config::set('services.twilio.whatsapp_from', $creds['whatsapp_from']);
                Config::set('services.twilio.sms_from', $creds['sms_from']);
                Config::set('services.twilio.sms_messaging_service_sid', $creds['sms_messaging_service_sid']);

                Config::set('twilio-notification-channel.account_sid', $creds['account_sid']);
                Config::set('twilio-notification-channel.auth_token', $creds['auth_token']);
                Config::set('twilio-notification-channel.from', $creds['whatsapp_from']);
                Config::set('twilio-notification-channel.sms_service_sid', $creds['sms_messaging_service_sid']);
                Config::set('twilio-notification-channel.enabled', true);
            } else {
                // Block .env / cached config — admin must save credentials first
                Config::set('services.twilio.sid', null);
                Config::set('services.twilio.token', null);
                Config::set('services.twilio.whatsapp_from', null);
                Config::set('services.twilio.sms_from', null);
                Config::set('services.twilio.sms_messaging_service_sid', null);

                Config::set('twilio-notification-channel.account_sid', null);
                Config::set('twilio-notification-channel.auth_token', null);
                Config::set('twilio-notification-channel.from', null);
                Config::set('twilio-notification-channel.sms_service_sid', null);
                Config::set('twilio-notification-channel.enabled', false);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to apply Twilio credentials to config', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin form payload (never expose full auth token — only whether set).
     *
     * @return array<string, mixed>
     */
    public static function adminSettingsPayload(): array
    {
        $db = self::fromDatabase();
        $resolved = self::resolved();

        $sid = $db['account_sid'] ?? null;
        $token = $db['auth_token'] ?? null;
        $tokenSet = ! empty($token);

        return [
            'account_sid' => $sid,
            'auth_token' => $token,
            'auth_token_set' => $tokenSet,
            'whatsapp_from' => $db['whatsapp_from'] ?? 'whatsapp:+14155238886',
            'sms_from' => $db['sms_from'] ?? null,
            'sms_messaging_service_sid' => $db['sms_messaging_service_sid'] ?? null,
            'mode_environment' => $db['mode_environment'] ?? 'sandbox',
            'enabled' => $db['enabled'] ?? true,
            'has_database_credentials' => $resolved['configured'],
            'active_source' => $resolved['source'],
        ];
    }

    public static function save(array $data): PaymentMethod
    {
        $existing = PaymentMethod::getConfig(self::METHOD_NAME);
        $extra = is_array($existing?->additional_config) ? $existing->additional_config : [];

        $extra['whatsapp_from'] = self::nullableString($data['whatsapp_from'] ?? null);
        if ($extra['whatsapp_from'] !== null) {
            $number = preg_replace('/^whatsapp:/i', '', $extra['whatsapp_from']) ?? '';
            $number = trim($number);
            $extra['whatsapp_from'] = $number !== '' ? 'whatsapp:'.$number : 'whatsapp:+14155238886';
        } else {
            $extra['whatsapp_from'] = 'whatsapp:+14155238886';
        }
        $extra['sms_from'] = self::nullableString($data['sms_from'] ?? null);
        $extra['sms_messaging_service_sid'] = self::nullableString($data['sms_messaging_service_sid'] ?? null);
        $extra['enabled'] = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : true;

        $payload = [
            'mode' => 'automatic',
            'mode_environment' => in_array(($data['mode_environment'] ?? ''), ['sandbox', 'live'], true)
                ? $data['mode_environment']
                : 'sandbox',
            'additional_config' => $extra,
        ];

        $sid = self::nullableString($data['account_sid'] ?? null);
        if ($sid !== null) {
            $payload['client_id'] = $sid;
        } elseif ($existing?->client_id) {
            $payload['client_id'] = $existing->client_id;
        }

        $token = self::nullableString($data['auth_token'] ?? null);
        if ($token !== null) {
            $payload['client_secret'] = $token;
        } elseif ($existing?->client_secret) {
            $payload['client_secret'] = $existing->client_secret;
        }

        $row = PaymentMethod::setConfig(self::METHOD_NAME, $payload);
        self::applyToConfig(true);

        return $row;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
