<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon metrics snapshots (queue health dashboard)
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Schedule IRS BMF import every 72 hours (update-only mode)
Schedule::command('irs:bmf:import --update-only --chunk=1000 --skip-if-busy')
    ->cron('0 2 */3 * *')
    ->withoutOverlapping();

Schedule::command('rss:warm-nonprofit')->everyFifteenMinutes();

// Supporters: in-app + push to each favorited nonprofit (owner + board) on the celebrant's local birthday at their local send hour
Schedule::command('supporters:notify-birthdays')
    ->hourly()
    ->withoutOverlapping();

// Daily rotating engagement push for supporters and organization accounts
Schedule::command('engagement:send-daily')
    ->dailyAt('09:30')
    ->withoutOverlapping();

// Warm per-channel YouTube API payloads so /unity-videos stays responsive (hub list itself is always rebuilt live)
Schedule::command('unity-videos:warm-cache')->everyFiveMinutes();

Schedule::command('model:prune', ['--model' => [\App\Models\SendJob::class]])->daily()->at('03:00')->withoutOverlapping();

Schedule::command('drops:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('gift-cards:fulfill-due')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Process scheduled Facebook posts
Schedule::command('facebook:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// Process scheduled newsletters
Schedule::command('newsletter:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// SMS wallet: Cashier off-session auto-recharge when balance is low (also dispatched after each SMS send)
Schedule::command('sms:auto-recharge-scan')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Clean up old send jobs (optional)
Schedule::command('sendJobs:cleanup')
    ->daily()
    ->at('02:00');

// Send daily payment reminders for Form 1023 applications
Schedule::command('form1023:send-payment-reminders')
    ->daily()
    ->at('09:00')
    ->withoutOverlapping();

// Check IRS Form 990 filings monthly and send notifications
Schedule::command('irs:check-form990-filings --notify')
    ->monthly()
    ->at('03:00')
    ->withoutOverlapping();

// Sync IRS Form 990 board/officer members (for org claim matching). Monthly is enough — IRS data updates annually.
// Uses --queue so it only dispatches jobs; a queue worker (--queue=irs-import) must be running to process them.
Schedule::command('irs:sync-board-members --queue')
    ->monthly()
    ->at('04:00')
    ->withoutOverlapping();

// Kiosk: forced AI re-ingest per geo (updated links + new providers). Requires queue worker + OPENAI_API_KEY.
if (config('services.kiosk_provider_ingest.monthly_refresh_enabled', true)) {
    Schedule::command('kiosk:refresh-provider-ingest')
        ->monthlyOn(1, '5:00')
        ->withoutOverlapping();
}

// Clean Laravel log file when it exceeds 10MB (runs hourly)
Schedule::command('log:clean --size=10')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('service-orders:auto-complete')
    ->hourly()
    ->withoutOverlapping();

// Care Alliance: flush pooled general-donation splits to member org owner wallets after weekly / monthly / quarterly windows
Schedule::command('care-alliance:release-pending-distributions')
    ->hourly()
    ->withoutOverlapping();

// Organization directory invites: Believe Points installments 2–24 for referrers (month 1 credits on email verification)
Schedule::command('organizations:process-invite-believe-point-schedule')
    ->dailyAt('06:05')
    ->withoutOverlapping();

// Unity Meet: fail/stop streaming jobs when AWS callbacks or heartbeats are missing
Schedule::command('streaming:reconcile-lifecycle')
    ->everyMinute()
    ->withoutOverlapping();

// Chat audio calls: expire unanswered ringing calls
Schedule::command('believe-points:release-processing')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Local APP_ENV: reconcile-settlement is Stripe-only unless run with --with-bridge
Schedule::command('believe-points:reconcile-settlement')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('believe-points:process-wallet-transfers')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Gift BP invites: refund Holding → Available when invitee does not register in time
Schedule::command('believe-points:expire-gift-invites')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('unity-calls:expire-ringing')
    ->everyThirtySeconds()
    ->withoutOverlapping();
