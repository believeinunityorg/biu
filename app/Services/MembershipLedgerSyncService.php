<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SupporterMembership;
use App\Models\Transaction;

/**
 * Links paid {@see SupporterMembership} checkout to wallet {@see Transaction} rows (admin ledger).
 * Connect destination charges settle on the org's Stripe account — no internal balance credit.
 */
final class MembershipLedgerSyncService
{
    public static function membershipHasLedgerReference(int $membershipId): bool
    {
        return Transaction::query()
            ->where(function ($q) use ($membershipId) {
                $q->where(function ($q2) use ($membershipId) {
                    $q2->where('related_type', SupporterMembership::class)
                        ->where('related_id', $membershipId);
                })
                    ->orWhere('meta->supporter_membership_id', $membershipId)
                    ->orWhere('meta->supporter_membership_id', (string) $membershipId);
            })
            ->exists();
    }

    public static function recordMembershipPaymentIfMissing(
        SupporterMembership $membership,
        Organization $recipientOrganization,
        string $paymentIntentId,
    ): void {
        $membership->loadMissing('supporter', 'membershipPlan');
        $supporter = $membership->supporter;
        if ($supporter === null) {
            return;
        }

        self::recordSupporterPaymentIfMissing($membership, $recipientOrganization, $paymentIntentId);
        self::recordOrganizationDepositIfMissing($membership, $recipientOrganization, $paymentIntentId);
    }

    private static function recordSupporterPaymentIfMissing(
        SupporterMembership $membership,
        Organization $recipientOrganization,
        string $paymentIntentId,
    ): void {
        $supporter = $membership->supporter;
        if ($supporter === null) {
            return;
        }

        $exists = Transaction::query()
            ->where('user_id', $supporter->id)
            ->where('related_type', SupporterMembership::class)
            ->where('related_id', $membership->id)
            ->where('meta->ledger_role', 'supporter_membership_payment')
            ->exists();
        if ($exists) {
            return;
        }

        $amountDollars = (float) ($membership->payment_amount ?? 0);
        if ($amountDollars <= 0) {
            return;
        }

        $planName = $membership->membershipPlan?->membership_name ?? 'Membership';
        $meta = [
            'supporter_membership_id' => $membership->id,
            'organization_id' => $recipientOrganization->id,
            'organization_name' => $recipientOrganization->name,
            'payment_purpose' => 'supporter_membership',
            'ledger_role' => 'supporter_membership_payment',
            'source' => 'supporter_membership_checkout',
            'exclude_from_wallet_stats' => true,
            'membership_plan_name' => $planName,
            'stripe_payment_intent' => $paymentIntentId,
            'funds_routed_via_stripe_connect' => true,
            'stripe_connect_account_id' => $recipientOrganization->stripe_connect_account_id,
            'stripe_connect_routing_mode' => 'destination_charge',
        ];

        $supporter->recordTransaction([
            'type' => 'purchase',
            'amount' => $amountDollars,
            'fee' => 0,
            'payment_method' => 'membership',
            'transaction_id' => $paymentIntentId.'-membership-donor',
            'related_type' => SupporterMembership::class,
            'related_id' => $membership->id,
            'meta' => $meta,
        ]);
    }

    private static function recordOrganizationDepositIfMissing(
        SupporterMembership $membership,
        Organization $recipientOrganization,
        string $paymentIntentId,
    ): void
    {
        $recipientOrganization->loadMissing('user');
        $recipient = $recipientOrganization->user;
        if ($recipient === null) {
            return;
        }

        $exists = Transaction::query()
            ->where('user_id', $recipient->id)
            ->where('type', 'deposit')
            ->where(function ($q) use ($membership) {
                $q->where(function ($q2) use ($membership) {
                    $q2->where('related_type', SupporterMembership::class)
                        ->where('related_id', $membership->id);
                })->orWhere(function ($q2) use ($membership) {
                    $q2->where('meta->supporter_membership_id', $membership->id)
                        ->where('meta->source', 'organization_membership');
                });
            })
            ->exists();
        if ($exists) {
            return;
        }

        $amountDollars = (float) ($membership->payment_amount ?? 0);
        if ($amountDollars <= 0) {
            return;
        }

        $planName = $membership->membershipPlan?->membership_name ?? 'Membership';
        $meta = [
            'supporter_membership_id' => $membership->id,
            'organization_id' => $recipientOrganization->id,
            'organization_name' => $recipientOrganization->name,
            'payment_purpose' => 'supporter_membership',
            'source' => 'organization_membership',
            'membership_plan_name' => $planName,
            'stripe_payment_intent' => $paymentIntentId,
            'net_to_organization' => round($amountDollars, 2),
            'gross_amount' => round($amountDollars, 2),
            'funds_routed_via_stripe_connect' => true,
            'stripe_connect_account_id' => $recipientOrganization->stripe_connect_account_id,
            'stripe_connect_routing_mode' => 'destination_charge',
        ];

        $recipient->recordTransaction([
            'type' => 'deposit',
            'amount' => $amountDollars,
            'fee' => 0,
            'payment_method' => 'membership',
            'transaction_id' => $paymentIntentId.'-membership',
            'related_type' => SupporterMembership::class,
            'related_id' => $membership->id,
            'meta' => $meta,
        ]);
    }
}
