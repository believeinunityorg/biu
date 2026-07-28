<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Jobs\SendDonationConfirmationEmail;
use App\Jobs\SendDonationReceivedEmail;
use App\Models\Donation;
use App\Models\User;
use App\Notifications\DonationConfirmedForDonorNotification;
use App\Notifications\DonationReceivedForOrganizationNotification;
use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DonationCompletionNotifier
{
    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {}

    public function notify(Donation $donation): void
    {
        if (! in_array($donation->status, ['completed', 'active'], true)) {
            return;
        }

        if (! $donation->organization_id) {
            return;
        }

        $donationId = $donation->id;

        DB::afterCommit(function () use ($donationId): void {
            $cacheKey = 'donation_completion_notify:'.$donationId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $donation = Donation::query()
                ->with(['user', 'organization.user', 'careAlliance:id,name,slug'])
                ->find($donationId);

            if ($donation === null || ! in_array($donation->status, ['completed', 'active'], true)) {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyDonor($donation);
            $this->notifyOrganization($donation);
        });
    }

    private function notifyDonor(Donation $donation): void
    {
        $donor = $donation->user;
        if (! $donor instanceof User) {
            return;
        }

        $recipientLabel = $this->recipientLabel($donation);
        $successUrl = route('donations.success', ['donation_id' => $donation->id]);

        try {
            $donor->notify(new DonationConfirmedForDonorNotification($donation, $recipientLabel, $successUrl));
        } catch (\Throwable $e) {
            Log::warning('Donation donor in-app notification failed', [
                'donation_id' => $donation->id,
                'user_id' => $donor->id,
                'error' => $e->getMessage(),
            ]);
        }

        $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Donations);
        $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Donations);
        $pushLinks = ShortChannelNotification::pushDeepLinkData();

        $pushData = $this->firebaseService->stringifyFcmData([
            'type' => 'donation_confirmed',
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
            'created_by' => $donor->id,
            'deep_link' => $pushLinks['deep_link'],
        ]);

        try {
            $this->firebaseService->sendToUser($donor->id, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Donation donor push notification failed', [
                'donation_id' => $donation->id,
                'user_id' => $donor->id,
                'error' => $e->getMessage(),
            ]);
        }

        $email = trim((string) $donor->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                SendDonationConfirmationEmail::dispatch($donation->id, $donor->id);
            } catch (\Throwable $e) {
                Log::warning('Donation donor confirmation email job dispatch failed', [
                    'donation_id' => $donation->id,
                    'user_id' => $donor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyOrganization(Donation $donation): void
    {
        $organization = $donation->organization;
        $orgUser = $organization?->user;
        if (! $orgUser instanceof User) {
            return;
        }

        $donorName = trim((string) ($donation->user?->name ?? 'A supporter')) ?: 'A supporter';
        $donationsUrl = route('donations.index');

        try {
            $orgUser->notify(new DonationReceivedForOrganizationNotification($donation, $donorName, $donationsUrl));
        } catch (\Throwable $e) {
            Log::warning('Donation organization in-app notification failed', [
                'donation_id' => $donation->id,
                'organization_id' => $donation->organization_id,
                'user_id' => $orgUser->id,
                'error' => $e->getMessage(),
            ]);
        }

        $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Donations);
        $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Donations);
        $pushLinks = ShortChannelNotification::pushDeepLinkData();

        $pushData = $this->firebaseService->stringifyFcmData([
            'type' => 'donation_received',
            'title' => $pushTitle,
            'body' => $pushBody,
            'url' => $pushLinks['url'],
            'click_action' => $pushLinks['click_action'],
            'donation_id' => (string) $donation->id,
            'organization_id' => (string) $donation->organization_id,
            'donor_user_id' => $donation->user_id ? (string) $donation->user_id : '',
            'source_type' => 'donation',
            'source_id' => (string) $donation->id,
            'module_name' => PushNotificationModule::Donations->value,
            'module_record_id' => $donation->id,
            'created_by' => $donation->user_id,
            'deep_link' => $pushLinks['deep_link'],
        ]);

        try {
            $this->firebaseService->sendToUser($orgUser->id, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Donation organization push notification failed', [
                'donation_id' => $donation->id,
                'organization_id' => $donation->organization_id,
                'user_id' => $orgUser->id,
                'error' => $e->getMessage(),
            ]);
        }

        $email = trim((string) $orgUser->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                SendDonationReceivedEmail::dispatch($donation->id, $orgUser->id);
            } catch (\Throwable $e) {
                Log::warning('Donation organization received email job dispatch failed', [
                    'donation_id' => $donation->id,
                    'organization_id' => $donation->organization_id,
                    'user_id' => $orgUser->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function recipientLabel(Donation $donation): string
    {
        if ($donation->care_alliance_id && $donation->careAlliance) {
            return trim((string) $donation->careAlliance->name) ?: 'Unity Impact Alliance';
        }

        return trim((string) ($donation->organization?->name ?? '')) ?: 'Organization';
    }
}
