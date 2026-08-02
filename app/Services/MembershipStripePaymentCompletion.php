<?php

namespace App\Services;

use App\Enums\MembershipPaymentStatus;
use App\Models\SupporterMembership;
use Illuminate\Support\Facades\DB;

final class MembershipStripePaymentCompletion
{
    public static function completeSuccessfulPayment(
        SupporterMembership $membership,
        string $sessionId,
        string $paymentIntentId,
    ): SupporterMembership {
        $membership->loadMissing('membershipPlan', 'account');

        if ($membership->payment_status === MembershipPaymentStatus::Paid
            && (string) ($membership->stripe_payment_intent_id ?? '') === $paymentIntentId) {
            return $membership;
        }

        $account = $membership->account;
        if ($account === null) {
            throw new \InvalidArgumentException('Membership account not found.');
        }

        $recipient = app(MembershipAccountService::class)->resolvePaymentOrganizationForMembership($account);
        if ($recipient === null) {
            throw new \InvalidArgumentException('Paid memberships require a payout-ready organization.');
        }

        return DB::transaction(function () use ($membership, $sessionId, $paymentIntentId, $recipient) {
            $membership->update([
                'payment_status' => MembershipPaymentStatus::Paid->value,
                'paid_at' => now(),
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            MembershipLedgerSyncService::recordMembershipPaymentIfMissing(
                $membership->fresh(['supporter', 'membershipPlan']),
                $recipient,
                $paymentIntentId,
            );

            return app(MembershipAccountService::class)->finalizeMembershipAfterPayment($membership->fresh(['membershipPlan']));
        });
    }
}
