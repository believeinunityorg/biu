<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\StripeConfigServiceProvider::class,
    App\Providers\TwilioConfigServiceProvider::class,
    NotificationChannels\Twilio\TwilioProvider::class,
];
