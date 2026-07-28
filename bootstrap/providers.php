<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\StripeConfigServiceProvider::class,
    NotificationChannels\Twilio\TwilioProvider::class,
    App\Providers\TwilioConfigServiceProvider::class,
];
