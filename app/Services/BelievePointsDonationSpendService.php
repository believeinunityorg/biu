<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\UnifiedLedgerTransactionWriter;
use Illuminate\Support\Facades\DB;

final class BelievePointsDonationSpendService
{
    /**
     * Donor pays with Processing BP first, then Available. Org owner receives Processing lots or Available BP.
     *
     * @return array{from_processing: float, from_available: float}|null
     */
    public static function transferForDonation(User $donor, Organization $organization, Donation $donation, float $amount): ?array
    {
        $amount = round(max(0, (float) $amount), 2);
        if ($amount <= 0) {
            return ['from_processing' => 0.0, 'from_available' => 0.0];
        }

        $organization->loadMissing('user');
        $recipient = $organization->user;
        if ($recipient === null) {
            return null;
        }

        return DB::transaction(function () use ($donor, $recipient, $donation, $amount) {
            $donorLocked = User::query()->lockForUpdate()->findOrFail($donor->id);
            $recipientLocked = User::query()->lockForUpdate()->findOrFail($recipient->id);

            $processing = round((float) ($donorLocked->processing_believe_points ?? 0), 2);
            $purchased = $donorLocked->purchasedBelievePointsBalance();
            $total = round($processing + $purchased, 2);

            if ($total + 0.000001 < $amount) {
                return null;
            }

            $fromProcessing = round(min($processing, $amount), 2);
            $fromAvailable = round($amount - $fromProcessing, 2);

            if ($fromProcessing > 0) {
                if (! BelievePointProcessingLotService::transferProcessingPoints(
                    $donorLocked,
                    $recipientLocked,
                    $fromProcessing,
                    [
                        'donation_id' => $donation->id,
                        'organization_id' => $donation->organization_id,
                        'source' => 'believe_points_donation',
                    ]
                )) {
                    return null;
                }
            }

            if ($fromAvailable > 0) {
                if (! $donorLocked->deductBelievePoints($fromAvailable)) {
                    return null;
                }
                $recipientLocked->addBelievePoints($fromAvailable);
            }

            BelievePointsWalletLedgerService::recordDonationSpend(
                $donorLocked->id,
                $donation->id,
                $donation->organization_id,
                $amount,
                $fromProcessing,
                $fromAvailable,
            );

            UnifiedLedgerTransactionWriter::syncBpDonationSpendRow(
                $donorLocked,
                $organization,
                $donation,
                $amount,
                $fromProcessing,
                $fromAvailable,
            );

            return [
                'from_processing' => $fromProcessing,
                'from_available' => $fromAvailable,
            ];
        });
    }
}
