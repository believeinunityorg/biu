<?php

namespace App\Providers;

use App\Listeners\AwardDailyLoginBrp;
use App\Listeners\AwardInviteRewardPoints;
use App\Listeners\AwardSupporterReferralBrp;
use App\Listeners\CompleteBelievePointPurchaseFromStripeWebhook;
use App\Listeners\GrantAiMediaStudioCreditsOnPlanSubscriptionRenewal;
use App\Listeners\SyncBelievePointSettlementFromStripeWebhook;
use App\Listeners\SyncLedgerTransactionStripeFees;
use App\Listeners\SyncMainDonationFromStripeWebhook;
use App\Listeners\SyncMembershipPaymentFromStripeWebhook;
use App\Models\BelievePointPurchase;
use App\Models\BelievePointWalletTransfer;
use App\Models\Donation;
use App\Models\Enrollment;
use App\Models\FundMeDonation;
use App\Models\JobApplication;
use App\Models\NodeSell;
use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Subscription as AppSubscription;
use App\Models\UserFavoriteOrganization;
use App\Notifications\Channels\FirebaseChannel;
use App\Models\RewardPointLedger;
use App\Observers\BelievePointPurchaseObserver;
use App\Observers\RewardPointLedgerObserver;
use App\Observers\BelievePointWalletTransferObserver;
use App\Observers\DonationObserver;
use App\Observers\EnrollmentObserver;
use App\Observers\FundMeDonationObserver;
use App\Observers\JobApplicationObserver;
use App\Observers\NodeSellObserver;
use App\Observers\OrganizationObserver;
use App\Observers\UserFavoriteOrganizationObserver;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'Organization' => Organization::class,
            'UnityImpactAlliance' => CareAlliance::class,
            'Group' => Group::class,
        ]);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Avoid Chrome "preloaded but not used" warnings: keep stylesheet links, skip CSS preload.
        Vite::usePreloadTagAttributes(function (string $src, string $url, ?array $chunk, ?array $manifest) {
            if (str_ends_with($url, '.css')) {
                return false;
            }

            return [];
        });

        RateLimiter::for('wallet-balance', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        NodeSell::observe(NodeSellObserver::class);
        FundMeDonation::observe(FundMeDonationObserver::class);
        BelievePointPurchase::observe(BelievePointPurchaseObserver::class);
        RewardPointLedger::observe(RewardPointLedgerObserver::class);
        BelievePointWalletTransfer::observe(BelievePointWalletTransferObserver::class);
        Donation::observe(DonationObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
        Organization::observe(OrganizationObserver::class);
        UserFavoriteOrganization::observe(UserFavoriteOrganizationObserver::class);
        JobApplication::observe(JobApplicationObserver::class);
        Cashier::useCustomerModel(User::class);
        Cashier::useSubscriptionModel(AppSubscription::class);
        if ((bool) config('services.stripe.automatic_tax', false)) {
            Cashier::calculateTaxes();
        }

        // Register event listener for email verification
        Event::listen(Login::class, AwardDailyLoginBrp::class);
        Event::listen(Registered::class, \App\Listeners\ClaimBelievePointGiftInvites::class);
        Event::listen(Verified::class, AwardInviteRewardPoints::class);
        Event::listen(Verified::class, AwardSupporterReferralBrp::class);

        // Cashier Stripe webhooks: sync ledger fees from PaymentIntent metadata; Believe Points settlement
        Event::listen(WebhookReceived::class, SyncLedgerTransactionStripeFees::class);
        Event::listen(WebhookReceived::class, CompleteBelievePointPurchaseFromStripeWebhook::class);
        Event::listen(WebhookReceived::class, SyncBelievePointSettlementFromStripeWebhook::class);
        Event::listen(WebhookReceived::class, SyncMainDonationFromStripeWebhook::class);
        Event::listen(WebhookReceived::class, SyncMembershipPaymentFromStripeWebhook::class);
        Event::listen(WebhookReceived::class, GrantAiMediaStudioCreditsOnPlanSubscriptionRenewal::class);

        Inertia::share([
            'auth' => function () {
                $user = Auth::user();

                return [
                    'user' => $user,
                    'roles' => $user?->getRoleNames()->toArray() ?? [],
                    'permissions' => $user?->getAllPermissions()->pluck('name')->toArray() ?? [],
                ];
            },
        ]);
        $this->app->make(ChannelManager::class)->extend('firebase', function ($app) {
            return new FirebaseChannel;
        });
    }
}
