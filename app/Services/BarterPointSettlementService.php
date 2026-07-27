<?php

namespace App\Services;

use App\Models\NonprofitBarterListing;
use App\Models\NonprofitBarterSettlement;
use App\Models\NonprofitBarterTransaction;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Barter point settlement: compute delta, enforce non-negative balance, write ledger.
 * Points are stored on User (org->user); ledger is nonprofit_barter_settlements for audit.
 */
class BarterPointSettlementService
{
    /**
     * Compute points delta: requested_listing.points_value - return_listing.points_value.
     * Positive => requesting (A) pays responding (B). Negative => B pays A.
     */
    public function computeDelta(NonprofitBarterListing $requestedListing, NonprofitBarterListing $returnListing): int
    {
        return (int) $requestedListing->points_value - (int) $returnListing->points_value;
    }

    /**
     * Check if the payer organization has enough Believe Points (via its user).
     * Payer is A if delta > 0, B if delta < 0.
     */
    public function canSettle(NonprofitBarterTransaction $transaction): bool
    {
        $delta = $transaction->points_delta;
        if ($delta === 0) {
            return true;
        }

        $payerOrg = $delta > 0
            ? $transaction->requestingNonprofit
            : $transaction->respondingNonprofit;

        $user = $payerOrg->user;
        if (!$user) {
            return false;
        }

        return $user->purchasedBelievePointsBalance() >= abs($delta);
    }

    /**
     * Execute point settlement for an accepted transaction: debit payer, credit payee, write ledger.
     * No Stripe. Call only after acceptance; optionally can be run at completion (per Ken).
     *
     * @throws \RuntimeException if payer has insufficient points
     */
    public function settle(NonprofitBarterTransaction $transaction): void
    {
        $delta = $transaction->points_delta;
        if ($delta === 0) {
            return;
        }

        if (!$this->canSettle($transaction)) {
            throw new \RuntimeException('Payer nonprofit has insufficient Believe Points to complete settlement.');
        }

        $fromOrg = $delta > 0 ? $transaction->requestingNonprofit : $transaction->respondingNonprofit;
        $toOrg = $delta > 0 ? $transaction->respondingNonprofit : $transaction->requestingNonprofit;
        $points = abs($delta);

        $fromUser = $fromOrg->user;
        $toUser = $toOrg->user;
        if (!$fromUser || !$toUser) {
            throw new \RuntimeException('Organization missing linked user for point settlement.');
        }

        DB::transaction(function () use ($transaction, $fromOrg, $toOrg, $points, $fromUser, $toUser) {
            $fromUser->deductBelievePoints($points);
            $toUser->addBelievePoints($points);

            NonprofitBarterSettlement::create([
                'transaction_id' => $transaction->id,
                'from_organization_id' => $fromOrg->id,
                'to_organization_id' => $toOrg->id,
                'points' => $points,
            ]);
        });
    }
}
