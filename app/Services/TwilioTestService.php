<?php

namespace App\Services;

use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin Twilio diagnostics: verify account credentials and send sandbox/live test messages.
 */
class TwilioTestService
{
    public function __construct(
        protected NewsletterTwilioSmsService $sms,
    ) {}

    /**
     * @return array{ok: bool, channel: string, title: string, message: string, details?: array<string, mixed>}
     */
    public function run(string $channel, ?string $to = null): array
    {
        TwilioConfigService::applyToConfig(true);

        return match ($channel) {
            'connection' => $this->testConnection(),
            'whatsapp' => $this->testWhatsApp($to),
            'sms' => $this->testSms($to),
            default => [
                'ok' => false,
                'channel' => $channel,
                'title' => 'Unknown test',
                'message' => 'Choose connection, whatsapp, or sms.',
            ],
        };
    }

    /**
     * @return array{ok: bool, channel: string, title: string, message: string, details?: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $creds = TwilioConfigService::resolved();

        if (empty($creds['account_sid']) || empty($creds['auth_token'])) {
            return [
                'ok' => false,
                'channel' => 'connection',
                'title' => 'Credentials missing',
                'message' => 'Save Account SID and Auth Token in Settings → Twilio first. Credentials come from the database only.',
                'details' => [
                    'source' => $creds['source'],
                ],
            ];
        }

        try {
            $client = $this->sms->makeRestClient();
            $account = $client->api->v2010->accounts($creds['account_sid'])->fetch();

            return [
                'ok' => true,
                'channel' => 'connection',
                'title' => 'Credentials valid',
                'message' => 'Twilio accepted the Account SID and Auth Token.',
                'details' => [
                    'source' => $creds['source'],
                    'account_name' => $account->friendlyName ?? null,
                    'account_status' => $account->status ?? null,
                    'account_type' => $account->type ?? null,
                    'whatsapp_from' => $creds['whatsapp_from'],
                    'sms_from' => $creds['sms_from'],
                    'sms_messaging_service_sid' => $creds['sms_messaging_service_sid'],
                    'enabled' => $creds['enabled'],
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Twilio connection test failed', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'channel' => 'connection',
                'title' => 'Connection failed',
                'message' => $e->getMessage(),
                'details' => [
                    'source' => $creds['source'],
                    'hint' => $this->sslHint($e->getMessage()),
                ],
            ];
        }
    }

    /**
     * @return array{ok: bool, channel: string, title: string, message: string, details?: array<string, mixed>}
     */
    public function testWhatsApp(?string $to): array
    {
        $connection = $this->testConnection();
        if (! $connection['ok']) {
            return array_merge($connection, ['channel' => 'whatsapp']);
        }

        $creds = TwilioConfigService::resolved();
        $from = $creds['whatsapp_from'] ?? null;
        if (empty($from)) {
            return [
                'ok' => false,
                'channel' => 'whatsapp',
                'title' => 'WhatsApp From missing',
                'message' => 'Set WhatsApp From (e.g. whatsapp:+14155238886) and save.',
            ];
        }

        $e164 = $this->sms->normalizeToE164($to);
        if (! $e164) {
            return [
                'ok' => false,
                'channel' => 'whatsapp',
                'title' => 'Invalid phone',
                'message' => 'Enter a destination in E.164 format (e.g. +8801714438614).',
            ];
        }

        $body = "Believe In Unity\n"
            ."Twilio WhatsApp test — credentials are working.\n\n"
            ."Open Notifications:\n"
            .ShortChannelNotification::publicNotificationsUrl()
            ."\n\n"
            .'Reply STOP to unsubscribe';

        try {
            $client = $this->sms->makeRestClient();
            $message = $client->messages->create('whatsapp:'.$e164, [
                'from' => $from,
                'body' => $body,
            ]);

            return [
                'ok' => true,
                'channel' => 'whatsapp',
                'title' => 'WhatsApp queued',
                'message' => "Test message sent to {$e164}. Check WhatsApp on that phone.",
                'details' => [
                    'message_sid' => $message->sid ?? null,
                    'status' => $message->status ?? null,
                    'from' => $from,
                    'to' => 'whatsapp:'.$e164,
                    'source' => $creds['source'],
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Twilio WhatsApp test failed', ['error' => $e->getMessage(), 'to' => $e164]);

            return [
                'ok' => false,
                'channel' => 'whatsapp',
                'title' => 'WhatsApp send failed',
                'message' => $e->getMessage(),
                'details' => [
                    'from' => $from,
                    'to' => 'whatsapp:'.$e164,
                    'hint' => str_contains($e->getMessage(), '63007')
                        ? 'WhatsApp channel not found for From — use Live credentials and sandbox/business From.'
                        : (str_contains($e->getMessage(), '63015') || str_contains($e->getMessage(), 'not a valid')
                            ? 'Recipient may not have joined the WhatsApp sandbox.'
                            : $this->sslHint($e->getMessage())),
                ],
            ];
        }
    }

    /**
     * @return array{ok: bool, channel: string, title: string, message: string, details?: array<string, mixed>}
     */
    public function testSms(?string $to): array
    {
        $connection = $this->testConnection();
        if (! $connection['ok']) {
            return array_merge($connection, ['channel' => 'sms']);
        }

        TwilioConfigService::applyToConfig(true);

        if (! $this->sms->isConfigured()) {
            return [
                'ok' => false,
                'channel' => 'sms',
                'title' => 'SMS not configured',
                'message' => 'Set SMS From (E.164) or Messaging Service SID and save.',
            ];
        }

        $e164 = $this->sms->normalizeToE164($to);
        if (! $e164) {
            return [
                'ok' => false,
                'channel' => 'sms',
                'title' => 'Invalid phone',
                'message' => 'Enter a destination in E.164 format (e.g. +15551234567).',
            ];
        }

        try {
            $sid = $this->sms->send($e164, 'Believe In Unity — Twilio SMS test. Credentials are working.');

            return [
                'ok' => true,
                'channel' => 'sms',
                'title' => 'SMS queued',
                'message' => "Test SMS sent to {$e164}.",
                'details' => [
                    'message_sid' => $sid,
                    'to' => $e164,
                    'source' => TwilioConfigService::resolved()['source'],
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Twilio SMS test failed', ['error' => $e->getMessage(), 'to' => $e164]);

            return [
                'ok' => false,
                'channel' => 'sms',
                'title' => 'SMS send failed',
                'message' => $e->getMessage(),
                'details' => [
                    'to' => $e164,
                ],
            ];
        }
    }

    private function sslHint(string $message): ?string
    {
        if (str_contains($message, 'SSL certificate') || str_contains($message, 'local issuer')) {
            return 'SSL issue: set TWILIO_CAFILE to a cacert.pem path, or fix php.ini curl.cainfo.';
        }

        return null;
    }
}
