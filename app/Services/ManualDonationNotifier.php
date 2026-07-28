<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Jobs\SendManualDonationPendingReviewEmail;
use App\Jobs\SendManualDonationRejectedEmail;
use App\Models\Donation;
use App\Models\User;
use App\Notifications\ManualDonationPendingForDonorNotification;
use App\Notifications\ManualDonationPendingForOrganizationNotification;
use App\Notifications\ManualDonationRejectedForDonorNotification;
use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManualDonationNotifier
{
    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {}

    public function notifyPendingReview(Donation $donation): void
    {
        if ($donation->status !== 'pending') {
            return;
        }

        $donationId = $donation->id;

        DB::afterCommit(function () use ($donationId): void {
            $cacheKey = 'manual_donation_pending_notify:'.$donationId;
            if (! Cache::add($cacheKey, 1, now()->addDays(7))) {
                return;
            }

            $donation = Donation::query()
                ->with(['user', 'organization.user', 'careAlliance:id,name,slug'])
                ->find($donationId);

            if ($donation === null || $donation->status !== 'pending') {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyDonorPending($donation);
            $this->notifyOrganizationPending($donation);
        });
    }

    public function notifyRejected(Donation $donation, ?string $reviewNotes = null): void
    {
        if ($donation->status !== 'rejected') {
            return;
        }

        $donationId = $donation->id;

        DB::afterCommit(function () use ($donationId, $reviewNotes): void {
            $cacheKey = 'manual_donation_rejected_notify:'.$donationId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $donation = Donation::query()
                ->with(['user', 'organization', 'careAlliance:id,name,slug'])
                ->find($donationId);

            if ($donation === null || $donation->status !== 'rejected') {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyDonorRejected($donation, $reviewNotes);
        });
    }

    private function notifyDonorPending(Donation $donation): void
    {
        $donor = $donation->user;
        if (! $donor instanceof User) {
            return;
        }

        $recipientLabel = $this->recipientLabel($donation);
        $amountLabel = $this->formatAmount($donation);
        $donateUrl = route('donate');

        $title = 'Payment under review';
        $body = "Your {$amountLabel} manual payment to {$recipientLabel} is pending verification.";

        try {
            $donor->notify(new ManualDonationPendingForDonorNotification($donation, $recipientLabel, $donateUrl));
        } catch (\Throwable $e) {
            Log::warning('Manual donation pending in-app notification failed', [
                'donation_id' => $donation->id,
                'user_id' => $donor->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush($donor->id, $title, $body, $donateUrl, $donation, 'manual_donation_pending', $donor->id);

        $email = trim((string) $donor->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                SendManualDonationPendingReviewEmail::dispatch($donation->id, $donor->id);
            } catch (\Throwable $e) {
                Log::warning('Manual donation pending email dispatch failed', [
                    'donation_id' => $donation->id,
                    'user_id' => $donor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyOrganizationPending(Donation $donation): void
    {
        $orgUser = $donation->organization?->user;
        if (! $orgUser instanceof User) {
            return;
        }

        $donorName = trim((string) ($donation->user?->name ?? 'A supporter')) ?: 'A supporter';
        $amountLabel = $this->formatAmount($donation);
        $reviewUrl = route('organization.manual-payments.index');

        $title = 'Manual payment to review';
        $body = "{$donorName} submitted a {$amountLabel} manual payment for your review.";

        try {
            $orgUser->notify(new ManualDonationPendingForOrganizationNotification($donation, $donorName, $reviewUrl));
        } catch (\Throwable $e) {
            Log::warning('Manual donation org pending in-app notification failed', [
                'donation_id' => $donation->id,
                'organization_id' => $donation->organization_id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush(
            $orgUser->id,
            $title,
            $body,
            $reviewUrl,
            $donation,
            'manual_donation_pending_review',
            $donation->user_id,
        );
    }

    private function notifyDonorRejected(Donation $donation, ?string $reviewNotes): void
    {
        $donor = $donation->user;
        if (! $donor instanceof User) {
            return;
        }

        $recipientLabel = $this->recipientLabel($donation);
        $amountLabel = $this->formatAmount($donation);
        $donateUrl = route('donate');

        $title = 'Donation not verified';
        $body = "Your {$amountLabel} manual payment to {$recipientLabel} could not be verified.";

        try {
            $donor->notify(new ManualDonationRejectedForDonorNotification(
                $donation,
                $recipientLabel,
                $donateUrl,
                $reviewNotes,
            ));
        } catch (\Throwable $e) {
            Log::warning('Manual donation rejected in-app notification failed', [
                'donation_id' => $donation->id,
                'user_id' => $donor->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush($donor->id, $title, $body, $donateUrl, $donation, 'manual_donation_rejected', $donor->id);

        $email = trim((string) $donor->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                SendManualDonationRejectedEmail::dispatch($donation->id, $donor->id, $reviewNotes);
            } catch (\Throwable $e) {
                Log::warning('Manual donation rejected email dispatch failed', [
                    'donation_id' => $donation->id,
                    'user_id' => $donor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendPush(
        int $userId,
        string $title,
        string $body,
        string $url,
        Donation $donation,
        string $type,
        ?int $createdBy,
    ): void {
        // Short-channel standard: Push teaser only; detailed $title/$body remain for inbox/email callers.
        $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Donations);
        $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Donations);
        $pushLinks = ShortChannelNotification::pushDeepLinkData();

        $pushData = $this->firebaseService->stringifyFcmData([
            'type' => $type,
            'title' => $pushTitle,
            'body' => $pushBody,
            'url' => $pushLinks['url'],
            'click_action' => $pushLinks['click_action'],
            'donation_id' => (string) $donation->id,
            'organization_id' => (string) $donation->organization_id,
            'source_type' => 'donation',
            'source_id' => (string) $donation->id,
            'module_name' => PushNotificationModule::Donations->value,
            'module_record_id' => $donation->id,
            'created_by' => $createdBy,
            'deep_link' => $pushLinks['deep_link'],
        ]);

        try {
            $this->firebaseService->sendToUser($userId, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Manual donation push notification failed', [
                'donation_id' => $donation->id,
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recipientLabel(Donation $donation): string
    {
        if ($donation->care_alliance_id && $donation->careAlliance) {
            return trim((string) $donation->careAlliance->name) ?: 'Unity Impact Alliance';
        }

        return trim((string) ($donation->organization?->name ?? '')) ?: 'Organization';
    }

    private function formatAmount(Donation $donation): string
    {
        return '$'.number_format((float) $donation->amount, 2);
    }
}
