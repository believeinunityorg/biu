<?php

namespace App\Services;

use App\Exceptions\TwilioNewsletterSmsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\RestException;
use Twilio\Http\CurlClient;
use Twilio\Rest\Client;

class NewsletterTwilioSmsService
{
    public function isConfigured(): bool
    {
        TwilioConfigService::applyToConfig();

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.sms_from');
        $messagingServiceSid = config('services.twilio.sms_messaging_service_sid');

        return ! empty($sid) && ! empty($token)
            && (! empty($from) || ! empty($messagingServiceSid));
    }

    /**
     * Normalize a stored phone value to E.164 for Twilio SMS.
     */
    public function normalizeToE164(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $s = trim($raw);
        if (str_starts_with(strtolower($s), 'whatsapp:')) {
            $s = trim(substr($s, strlen('whatsapp:')));
        }

        $digits = preg_replace('/\D+/', '', $s);
        if ($digits === '' || $digits === null) {
            return null;
        }

        $hadPlus = str_starts_with(trim($raw), '+');

        if ($hadPlus) {
            return '+'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * trial = free trial / sandbox restrictions; full = upgraded live project.
     */
    public function getAccountMode(): string
    {
        $forced = strtolower((string) config('services.twilio.account_mode', 'auto'));
        if ($forced === 'trial' || $forced === 'full') {
            return $forced;
        }

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        if (empty($sid) || empty($token)) {
            return 'unknown';
        }

        return Cache::remember('twilio.account_mode.'.$sid, 3600, function () use ($sid) {
            try {
                $client = $this->makeRestClient();
                $account = $client->api->v2010->accounts($sid)->fetch();
                $type = strtolower((string) ($account->type ?? ''));

                return $type === 'trial' ? 'trial' : ($type === 'full' ? 'full' : 'unknown');
            } catch (\Throwable $e) {
                Log::warning('Could not fetch Twilio account type', ['error' => $e->getMessage()]);

                return 'unknown';
            }
        });
    }

    public function accountModeLabel(): string
    {
        return match ($this->getAccountMode()) {
            'trial' => 'trial/sandbox',
            'full' => 'live',
            default => 'unknown',
        };
    }

    /**
     * @return string Twilio message SID
     */
    public function send(string $toE164, string $body): string
    {
        TwilioConfigService::applyToConfig();

        $mode = $this->getAccountMode();
        $modeLabel = $this->accountModeLabel();

        $client = $this->makeRestClient();

        $payload = [
            'body' => mb_substr($body, 0, 1600),
        ];

        $messagingServiceSid = config('services.twilio.sms_messaging_service_sid');
        if (! empty($messagingServiceSid)) {
            $payload['messagingServiceSid'] = $messagingServiceSid;
        } else {
            $payload['from'] = config('services.twilio.sms_from');
        }

        try {
            $message = $client->messages->create($toE164, $payload);

            return $message->sid;
        } catch (RestException $e) {
            $hint = $this->hintForTwilioError($e->getCode(), $mode);
            $msg = sprintf(
                '[Twilio %s] SMS failed (Twilio error %d): %s.%s',
                $modeLabel,
                $e->getCode(),
                $this->stripHttpPrefixFromTwilioMessage($e->getMessage()),
                $hint !== '' ? ' '.$hint : ''
            );

            throw new TwilioNewsletterSmsException($msg, $mode, $e->getCode(), $e);
        } catch (\Throwable $e) {
            $msg = sprintf(
                '[Twilio %s] SMS failed: %s',
                $modeLabel,
                $e->getMessage()
            );

            throw new TwilioNewsletterSmsException($msg, $mode, null, $e);
        }
    }

    protected function stripHttpPrefixFromTwilioMessage(string $message): string
    {
        return preg_replace('/^\[HTTP \d+\]\s*/', '', $message) ?? $message;
    }

    protected function hintForTwilioError(int $code, string $accountMode): string
    {
        $trialNote = 'On a trial account you can only text numbers verified in Twilio Console → Phone Numbers → Manage → Verified Caller IDs.';

        return match ($code) {
            21608 => $trialNote.' Upgrade to a full account to text arbitrary numbers.',
            63038 => 'Trial daily SMS limit reached (Twilio resets per day, often UTC). Wait until tomorrow or upgrade to a paid Twilio project for higher limits.',
            21211 => 'The destination number is invalid. Use E.164 (e.g. +15551234567).',
            21614 => 'Twilio reports this is not a valid mobile number for SMS.',
            21408 => 'SMS to this region may be blocked on your Twilio project; check Geo permissions.',
            21606 => 'The From number is not valid for SMS or is not on this account.',
            default => $accountMode === 'trial'
                ? $trialNote
                : '',
        };
    }

    /**
     * Twilio REST client with SSL options for Windows / missing CA bundle.
     */
    public function makeRestClient(): Client
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $curlOpts = $this->curlSslOptions();

        $httpClient = new CurlClient($curlOpts);

        return new Client($sid, $token, $sid, null, $httpClient);
    }

    /**
     * @return array<int, mixed>
     */
    protected function curlSslOptions(): array
    {
        $opts = [];
        $cafile = config('services.twilio.cafile');
        if (! empty($cafile) && is_readable($cafile)) {
            $opts[CURLOPT_CAINFO] = $cafile;
        }

        if (config('services.twilio.verify_ssl', true) === false) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        return $opts;
    }
}
