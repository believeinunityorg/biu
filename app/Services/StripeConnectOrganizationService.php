<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

class StripeConnectOrganizationService
{
    /**
     * Configure Stripe credentials. Tries the DB-backed PaymentMethod row first; falls back to
     * Cashier's existing config (i.e. the .env STRIPE_SECRET / STRIPE_KEY pair) so that orgs can
     * still onboard when the admin hasn't filled the in-app payment_methods row yet.
     */
    public static function configureStripe(): bool
    {
        if (StripeConfigService::configureStripe()) {
            return true;
        }

        $envSecret = (string) (config('cashier.secret') ?? '');

        return $envSecret !== '';
    }

    /**
     * Pull charges/payouts flags and account type from Stripe. Returns null on success or a
     * short error string when Stripe rejects the call (e.g. wrong API mode, missing Connect setup).
     */
    public static function syncAccountStatusFromStripe(Organization $organization): ?string
    {
        if ($organization->stripe_connect_account_id === null || $organization->stripe_connect_account_id === '') {
            return null;
        }
        if (! self::configureStripe()) {
            return 'Stripe credentials are not configured for this application.';
        }

        try {
            $acct = Cashier::stripe()->accounts->retrieve($organization->stripe_connect_account_id);
            $organization->forceFill([
                'stripe_connect_charges_enabled' => (bool) ($acct->charges_enabled ?? false),
                'stripe_connect_payouts_enabled' => (bool) ($acct->payouts_enabled ?? false),
                'stripe_connect_account_type' => isset($acct->type) ? (string) $acct->type : null,
            ])->save();

            return null;
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect account retrieve failed', [
                'organization_id' => $organization->id,
                'stripe_connect_account_id' => $organization->stripe_connect_account_id,
                'error' => $e->getMessage(),
            ]);

            return self::humanizeStripeError($e);
        }
    }

    /**
     * Create (or reuse) a Standard connected account for the nonprofit.
     *
     * @return non-empty-string
     */
    public static function createStandardAccountIfMissing(Organization $organization): string
    {
        if (! self::configureStripe()) {
            throw new \RuntimeException('Stripe credentials are not configured for this application.');
        }

        $organization->loadMissing('user');
        $stripe = Cashier::stripe();

        $email = trim((string) ($organization->email ?: $organization->platform_email ?: $organization->user?->email ?: ''));
        if ($email === '') {
            throw new \RuntimeException('Organization must have an email address on file before connecting Stripe payouts.');
        }

        if ($organization->stripe_connect_account_id !== null && $organization->stripe_connect_account_id !== '') {
            self::syncAccountStatusFromStripe($organization);
            $organization->refresh();

            if (self::isLegacyExpressAccount($organization)) {
                throw new \RuntimeException(
                    'This organization is linked to a legacy Express Stripe account. Disconnect it first, then connect again to create a Standard account with the full Stripe Dashboard.'
                );
            }

            return $organization->stripe_connect_account_id;
        }

        $country = strtoupper((string) config('donations.stripe_connect_default_country', 'US'));
        if ($country === '' || strlen($country) !== 2) {
            $country = 'US';
        }

        try {
            $account = $stripe->accounts->create([
                'type' => 'standard',
                'country' => $country,
                'email' => $email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'non_profit',
                'metadata' => [
                    'organization_id' => (string) $organization->id,
                    'organization_name' => mb_substr((string) $organization->name, 0, 250),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect Standard account create failed', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(self::humanizeStripeError($e), 0, $e);
        }

        $organization->forceFill([
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_account_type' => 'standard',
        ])->save();

        self::syncAccountStatusFromStripe($organization);

        return $account->id;
    }

    /**
     * Stripe Connect Onboarding link for a Standard account (no OAuth client ID required).
     *
     * @return non-empty-string
     */
    public static function createAccountOnboardingLink(Organization $organization): string
    {
        if (! self::configureStripe()) {
            throw new \RuntimeException('Stripe credentials are not configured for this application.');
        }

        $accountId = self::createStandardAccountIfMissing($organization);

        try {
            $link = Cashier::stripe()->accountLinks->create([
                'account' => $accountId,
                'refresh_url' => route('integrations.stripe-connect.refresh'),
                'return_url' => route('integrations.stripe-connect.return'),
                'type' => 'account_onboarding',
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect account link create failed', [
                'organization_id' => $organization->id,
                'stripe_connect_account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(self::humanizeStripeError($e), 0, $e);
        }

        return $link->url;
    }

    /**
     * Remove the local Connect link. The Stripe account may still exist on Stripe's side.
     */
    public static function disconnectAccount(Organization $organization): void
    {
        $organization->forceFill([
            'stripe_connect_account_id' => null,
            'stripe_connect_charges_enabled' => false,
            'stripe_connect_payouts_enabled' => false,
            'stripe_connect_account_type' => null,
        ])->save();
    }

    public static function isLegacyExpressAccount(Organization $organization): bool
    {
        $type = strtolower(trim((string) ($organization->stripe_connect_account_type ?? '')));

        return $type === 'express' || $type === 'custom';
    }

    /**
     * Convert raw Stripe SDK exceptions into a sentence the org admin can act on.
     */
    public static function humanizeStripeError(\Throwable $e): string
    {
        $msg = (string) $e->getMessage();
        $lower = strtolower($msg);

        if (str_contains($lower, 'signed up for connect') || str_contains($lower, 'enable connect')) {
            return 'Stripe Connect is not enabled on this platform’s Stripe account. The platform admin must open Settings → Payment Methods → Stripe, follow the Connect setup link for your active mode, complete the one-time Stripe Connect platform profile, then click Save Settings again.';
        }

        if (str_contains($lower, 'no such account') || str_contains($lower, 'does not exist')) {
            return 'The previously stored Stripe Connect account was not found in this Stripe environment (test vs live mismatch is the most common cause).';
        }

        if (str_contains($lower, 'invalid api key') || str_contains($lower, 'no api key provided')) {
            return 'The Stripe API key is invalid or missing. Check the Stripe configuration in Admin → Settings → Payment Methods.';
        }

        return 'Stripe error: '.$msg;
    }

    public static function organizationCanAcceptDirectDonations(Organization $organization): bool
    {
        return self::organizationStripeConnectReady($organization);
    }

    /**
     * Whether the org's Standard Connect account can receive destination charges (donations, memberships, etc.).
     * Optionally refreshes charges/payouts flags from Stripe when a connected account id exists.
     */
    public static function organizationCanAcceptConnectPayments(Organization $organization, bool $syncFromStripe = true): bool
    {
        if (! self::configureStripe()) {
            return false;
        }

        if ($syncFromStripe
            && $organization->stripe_connect_account_id !== null
            && $organization->stripe_connect_account_id !== '') {
            self::syncAccountStatusFromStripe($organization);
            $organization->refresh();
        }

        return self::organizationStripeConnectReady($organization);
    }

    private static function organizationStripeConnectReady(Organization $organization): bool
    {
        if ($organization->stripe_connect_account_id === null || $organization->stripe_connect_account_id === '') {
            return false;
        }

        if (self::isLegacyExpressAccount($organization)) {
            return false;
        }

        return $organization->stripe_connect_charges_enabled && $organization->stripe_connect_payouts_enabled;
    }
}
