<?php

namespace App\Listeners;

use App\Models\SupporterMembership;
use App\Services\MembershipStripePaymentCompletion;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Completes paid supporter membership Checkout sessions from Stripe webhooks.
 */
class SyncMembershipPaymentFromStripeWebhook
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload ?? [];
        $type = $payload['type'] ?? '';

        if ($type === 'checkout.session.completed') {
            $session = $payload['data']['object'] ?? [];
            if (($session['payment_status'] ?? '') !== 'paid' || ($session['mode'] ?? '') !== 'payment') {
                return;
            }

            $meta = $session['metadata'] ?? [];
            if (($meta['payment_purpose'] ?? '') !== 'supporter_membership') {
                return;
            }

            $membershipId = $meta['membership_id'] ?? null;
            $piRef = $session['payment_intent'] ?? null;
            if ($membershipId === null || $membershipId === '' || ! $piRef) {
                return;
            }

            $piId = is_string($piRef) ? $piRef : ($piRef['id'] ?? null);
            if (! is_string($piId) || $piId === '') {
                return;
            }

            $this->finalizeMembership((int) $membershipId, (string) ($session['id'] ?? ''), $piId);

            return;
        }

        if ($type === 'payment_intent.succeeded') {
            $pi = $payload['data']['object'] ?? [];
            $meta = $pi['metadata'] ?? [];
            if (($meta['payment_purpose'] ?? '') !== 'supporter_membership') {
                return;
            }

            $membershipId = $meta['membership_id'] ?? null;
            $piId = $pi['id'] ?? null;
            if ($membershipId === null || $membershipId === '' || ! is_string($piId) || $piId === '') {
                return;
            }

            $this->finalizeMembership((int) $membershipId, '', $piId);
        }
    }

    private function finalizeMembership(int $membershipId, string $sessionId, string $paymentIntentId): void
    {
        $membership = SupporterMembership::query()->find($membershipId);
        if ($membership === null) {
            return;
        }

        if ($sessionId === '' && filled($membership->stripe_checkout_session_id)) {
            $sessionId = (string) $membership->stripe_checkout_session_id;
        }

        try {
            MembershipStripePaymentCompletion::completeSuccessfulPayment($membership, $sessionId, $paymentIntentId);
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook membership payment settlement skipped', [
                'membership_id' => $membershipId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
