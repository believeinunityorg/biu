<?php

namespace App\Providers;

use App\Services\TwilioConfigService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class TwilioConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        try {
            if (! Schema::hasTable('payment_methods')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        // Apply for HTTP and console/queues so campaign WhatsApp + newsletter SMS use DB keys.
        TwilioConfigService::applyToConfig();
    }
}
