<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentServiceInterface;
use App\Models\Donation;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\BelievePointsDonationSpendService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BelievePointsDonationPaymentService implements PaymentServiceInterface
{
    public function supports(string $paymentMethod): bool
    {
        return $paymentMethod === 'believe_points';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function initiateDonation(
        User $user,
        Organization $organization,
        Donation $donation,
        PaymentTransaction $paymentTransaction,
        array $context = []
    ): Response|array {
        $pointsRequired = (float) $donation->amount;
        $user->refresh();

        // Donations use purchased + processing only (Gift BP is gift-card-only).
        $donateable = round(
            $user->purchasedBelievePointsBalance() + (float) ($user->processing_believe_points ?? 0),
            2
        );

        if ($donateable + 0.000001 < $pointsRequired) {
            $donation->update(['status' => 'failed']);
            $paymentTransaction->update(['status' => PaymentTransaction::STATUS_REJECTED]);

            return redirect()->back()->withErrors([
                'payment_method' => "Insufficient purchased Believe Points. You need {$pointsRequired} points (purchased + processing) but only have {$donateable} (Gift BP can only be used for gift cards).",
            ]);
        }

        try {
            return DB::transaction(function () use ($user, $organization, $donation, $paymentTransaction, $pointsRequired) {
                $spend = BelievePointsDonationSpendService::transferForDonation(
                    $user,
                    $organization,
                    $donation,
                    $pointsRequired
                );

                if ($spend === null) {
                    $donation->update(['status' => 'failed']);
                    $paymentTransaction->update(['status' => PaymentTransaction::STATUS_REJECTED]);

                    return redirect()->back()->withErrors([
                        'payment_method' => 'Failed to deduct Believe Points. Please try again.',
                    ]);
                }

                $ref = 'believe_points_donation_'.$donation->id;

                PaymentTransactionCompletionService::completeDonation(
                    $donation->fresh(),
                    $ref,
                    'believe_points'
                );

                $brp = BelievePointsRewardService::donationBrpAmountForUser($user);

                return redirect(route('donations.success').'?donation_id='.$donation->id)
                    ->with('success', "Donation completed successfully using Believe Points! You earned +{$brp} BRP (Believe Reward Points).");
            });
        } catch (\Throwable $e) {
            $donation->update(['status' => 'failed']);
            Log::error('Believe Points donation failed: '.$e->getMessage());

            return redirect()->back()->withErrors([
                'payment_method' => 'Failed to process donation: '.$e->getMessage(),
            ]);
        }
    }
}
