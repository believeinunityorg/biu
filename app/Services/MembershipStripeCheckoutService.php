<?php

namespace App\Services;

use App\Enums\MembershipPaymentStatus;
use App\Models\Organization;
use App\Models\SupporterMembership;
use App\Models\User;
use Laravel\Cashier\Cashier;

final class MembershipStripeCheckoutService
{
    /**
     * @return object Stripe Checkout Session
     */
    public static function create(
        User $supporter,
        Organization $recipientOrganization,
        SupporterMembership $membership,
        int $amountInCents,
        string $lineItemTitle,
        string $successUrl,
        string $cancelUrl,
    ): object {
        if (! StripeConnectOrganizationService::organizationCanAcceptConnectPayments($recipientOrganization)) {
            throw new \InvalidArgumentException('This organization cannot accept paid memberships until Stripe payout is configured.');
        }

        if ($amountInCents <= 0) {
            throw new \InvalidArgumentException('Membership amount must be positive.');
        }

        StripeConnectOrganizationService::configureStripe();

        $metadata = [
            'membership_id' => (string) $membership->id,
            'supporter_id' => (string) $supporter->id,
            'account_type' => (string) $membership->account_type?->value,
            'account_id' => (string) $membership->account_id,
            'organization_id' => (string) $recipientOrganization->id,
            'payment_purpose' => 'supporter_membership',
            'stripe_connect_destination' => (string) $recipientOrganization->stripe_connect_account_id,
            'platform_routing_mode' => 'destination_charge',
        ];

        $paymentIntentData = [
            'metadata' => $metadata,
            'transfer_data' => [
                'destination' => $recipientOrganization->stripe_connect_account_id,
            ],
            'on_behalf_of' => $recipientOrganization->stripe_connect_account_id,
            'description' => mb_substr($lineItemTitle, 0, 1000),
        ];

        return Cashier::stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $supporter->email,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $amountInCents,
                    'product_data' => [
                        'name' => mb_substr($lineItemTitle, 0, 250),
                    ],
                ],
                'quantity' => 1,
            ]],
            'payment_method_types' => ['card'],
            'billing_address_collection' => 'auto',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'payment_intent_data' => $paymentIntentData,
            'locale' => 'auto',
        ]);
    }
}
