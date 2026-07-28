<?php

namespace App\Support;

use App\Enums\PushNotificationModule;

/**
 * Short-channel notification copy (Push / Twilio SMS / Twilio WhatsApp).
 *
 * Product contract:
 * - Email = full details (do not use this helper for mail bodies)
 * - Database inbox = keep detailed title/body
 * - Push / SMS = short "{Module}: Check Notifications" + deep link to /notifications
 * - WhatsApp = short branded teaser + Notifications link (still no full email-style body)
 *
 * WhatsApp transport is Twilio only. Credentials: admin Settings → Twilio (database only — must Save).
 * Links use APP_URL. SSL helpers may still use TWILIO_CAFILE / TWILIO_VERIFY_SSL in .env.
 *
 * Future modules (Merchant Hub, Support Ticket, Fundraiser): when adding outbound Push/SMS/WhatsApp,
 * use this helper for short copy; WhatsApp must go through TwilioChannel — never Meta Cloud API.
 */
class ShortChannelNotification
{
    /** Client-facing module labels (exact product wording). */
    public const LABEL_DONATION = 'Donation';

    public const LABEL_EVENT = 'Event';

    public const LABEL_GIFT_CARD = 'Gift Card';

    public const LABEL_MARKETPLACE = 'Marketplace';

    public const LABEL_MERCHANT_HUB = 'Merchant Hub';

    public const LABEL_VOLUNTEER = 'Volunteer';

    public const LABEL_COURSE = 'Course';

    public const LABEL_SUPPORT_TICKET = 'Support Ticket';

    public const LABEL_FUNDRAISER = 'Fundraiser';

    public const LABEL_WALLET = 'Wallet';

    public const LABEL_CAMPAIGN = 'Campaign';

    /**
     * Map PushNotificationModule (and common aliases) to client teaser labels.
     *
     * @var array<string, string>
     */
    private const MODULE_TO_CLIENT_LABEL = [
        'donations' => self::LABEL_DONATION,
        'donation' => self::LABEL_DONATION,
        'events' => self::LABEL_EVENT,
        'event' => self::LABEL_EVENT,
        'gift_card' => self::LABEL_GIFT_CARD,
        'gift_cards' => self::LABEL_GIFT_CARD,
        'marketplace' => self::LABEL_MARKETPLACE,
        'merchant_hub' => self::LABEL_MERCHANT_HUB,
        'merchant' => self::LABEL_MERCHANT_HUB,
        'volunteer' => self::LABEL_VOLUNTEER,
        'courses' => self::LABEL_COURSE,
        'course' => self::LABEL_COURSE,
        'support_ticket' => self::LABEL_SUPPORT_TICKET,
        'fundraiser' => self::LABEL_FUNDRAISER,
        'fund_me' => self::LABEL_FUNDRAISER,
        'wallet_rewards' => self::LABEL_WALLET,
        'wallet' => self::LABEL_WALLET,
        'campaigns' => self::LABEL_CAMPAIGN,
        'campaign' => self::LABEL_CAMPAIGN,
    ];

    public static function moduleLabel(PushNotificationModule|string $module): string
    {
        if ($module instanceof PushNotificationModule) {
            $key = $module->value;
        } else {
            $key = strtolower(trim($module));
        }

        if (isset(self::MODULE_TO_CLIENT_LABEL[$key])) {
            return self::MODULE_TO_CLIENT_LABEL[$key];
        }

        // Already a client label?
        $known = array_unique(array_values(self::MODULE_TO_CLIENT_LABEL));
        foreach ($known as $label) {
            if (strcasecmp($label, (string) $module) === 0) {
                return $label;
            }
        }

        if ($module instanceof PushNotificationModule) {
            return $module->label();
        }

        return (string) $module;
    }

    public static function teaser(PushNotificationModule|string $module): string
    {
        return self::moduleLabel($module).': Check Notifications';
    }

    /**
     * Absolute Notifications inbox URL (always APP_URL from env / config).
     */
    public static function notificationsUrl(): string
    {
        $base = rtrim((string) (config('app.url') ?: env('APP_URL', '')), '/');

        return $base.'/notifications';
    }

    /**
     * Notifications URL for SMS / WhatsApp — same as APP_URL (no hard-coded production host).
     */
    public static function publicNotificationsUrl(): string
    {
        return self::notificationsUrl();
    }

    public static function notificationsPath(): string
    {
        return '/notifications';
    }

    public static function pushTitle(PushNotificationModule|string $module): string
    {
        return self::teaser($module);
    }

    public static function pushBody(PushNotificationModule|string $module): string
    {
        return self::teaser($module);
    }

    /**
     * @return array{click_action: string, url: string, deep_link: string}
     */
    public static function pushDeepLinkData(): array
    {
        $url = self::notificationsUrl();

        return [
            'click_action' => $url,
            'url' => $url,
            'deep_link' => self::notificationsPath(),
        ];
    }

    /**
     * Twilio WhatsApp body: branded short teaser + Notifications URL + STOP.
     * Keeps details out of WhatsApp (full copy stays in email / Notification Center).
     */
    public static function whatsAppBody(PushNotificationModule|string $module): string
    {
        $label = self::moduleLabel($module);

        return "Believe In Unity\n"
            ."You have a new {$label} update.\n\n"
            ."Open Notifications:\n"
            .self::publicNotificationsUrl()
            ."\n\n"
            .'Reply STOP to unsubscribe';
    }

    /**
     * Twilio SMS body: teaser + URL, kept short when possible (~160 chars).
     */
    public static function smsBody(PushNotificationModule|string $module): string
    {
        $teaser = self::teaser($module);
        $url = self::publicNotificationsUrl();
        $full = $teaser."\n".$url;

        if (mb_strlen($full) <= 160) {
            return $full;
        }

        // Prefer teaser + truncated host path if needed
        return mb_substr($teaser.' '.$url, 0, 160);
    }
}
