<?php

return [
    // Credentials applied at runtime from admin DB (TwilioConfigService). Do not use .env for SID/token/From.
    'enabled' => false,
    'username' => env('TWILIO_USERNAME'),
    'password' => env('TWILIO_PASSWORD'),
    'auth_token' => null,
    'account_sid' => null,

    'from' => null,
    'alphanumeric_sender' => env('TWILIO_ALPHA_SENDER'),
    'shorten_urls' => (bool) env('TWILIO_SHORTEN_URLS', false),

    'sms_service_sid' => null,

    'debug_to' => env('TWILIO_DEBUG_TO'),

    'ignored_error_codes' => [
        21608,
        21211,
        21614,
        21408,
    ],
];
