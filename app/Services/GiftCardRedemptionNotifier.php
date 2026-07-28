<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Mail\GiftCardPurchaseReceipt;
use App\Models\GiftCard;
use App\Models\User;
use App\Notifications\GiftCardRedemptionDelayedNotification;
use App\Notifications\GiftCardRedemptionReadyNotification;
use App\Notifications\GiftCardRedemptionSubmittedNotification;
use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GiftCardRedemptionNotifier
{
    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {}

    public function notifySubmitted(GiftCard $giftCard): void
    {
        $giftCardId = $giftCard->id;

        DB::afterCommit(function () use ($giftCardId): void {
            $cacheKey = 'gift_card_submitted_notify:'.$giftCardId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $giftCard = GiftCard::query()->with(['user', 'organization'])->find($giftCardId);
            if (! $giftCard || ! $giftCard->user) {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyUserSubmitted($giftCard);
        });
    }

    public function notifyReady(GiftCard $giftCard): void
    {
        $giftCardId = $giftCard->id;

        DB::afterCommit(function () use ($giftCardId): void {
            $cacheKey = 'gift_card_ready_notify:'.$giftCardId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $giftCard = GiftCard::query()->with(['user', 'organization'])->find($giftCardId);
            if (! $giftCard || ! $giftCard->user) {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyUserReady($giftCard);
        });
    }

    public function notifyDelayed(GiftCard $giftCard): void
    {
        $giftCardId = $giftCard->id;

        DB::afterCommit(function () use ($giftCardId): void {
            $cacheKey = 'gift_card_delayed_notify:'.$giftCardId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $giftCard = GiftCard::query()->with(['user', 'organization'])->find($giftCardId);
            if (! $giftCard || ! $giftCard->user) {
                Cache::forget($cacheKey);

                return;
            }

            $this->notifyUserDelayed($giftCard);
        });
    }

    private function notifyUserSubmitted(GiftCard $giftCard): void
    {
        $user = $giftCard->user;
        if (! $user instanceof User) {
            return;
        }

        $url = $this->giftCardUrl($giftCard);
        $delayHours = max(1, (int) config('services.gift_cards.fulfillment_delay_hours', 72));
        $brand = $giftCard->brand_name ?? 'Gift card';
        $amount = number_format((float) $giftCard->amount, 2);
        $title = 'Gift card purchase received';
        $body = "Your {$brand} gift card for \${$amount} is being prepared and will be available within {$delayHours} hours.";

        try {
            $user->notify(new GiftCardRedemptionSubmittedNotification($giftCard, $url, $delayHours));
        } catch (\Throwable $e) {
            Log::warning('Gift card submitted in-app notification failed', [
                'gift_card_id' => $giftCard->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush(
            $user->id,
            $title,
            $body,
            $url,
            $giftCard,
            'gift_card_submitted',
        );

        if ($user->email) {
            try {
                Mail::to($user->email)->send(new GiftCardPurchaseReceipt(
                    $giftCard,
                    null,
                    readyNotification: false,
                    pendingFulfillment: true,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to send gift card purchase receipt email', [
                    'gift_card_id' => $giftCard->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyUserReady(GiftCard $giftCard): void
    {
        $user = $giftCard->user;
        if (! $user instanceof User) {
            return;
        }

        $url = $this->giftCardUrl($giftCard);
        $brand = $giftCard->brand_name ?? 'Gift card';
        $amount = number_format((float) $giftCard->amount, 2);
        $title = 'Your gift card is ready';
        $body = "Your {$brand} gift card for \${$amount} is ready to use.";

        try {
            $user->notify(new GiftCardRedemptionReadyNotification($giftCard, $url));
        } catch (\Throwable $e) {
            Log::warning('Gift card ready in-app notification failed', [
                'gift_card_id' => $giftCard->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush(
            $user->id,
            $title,
            $body,
            $url,
            $giftCard,
            'gift_card_ready',
        );

        if ($user->email) {
            try {
                Mail::to($user->email)->send(new GiftCardPurchaseReceipt(
                    $giftCard,
                    null,
                    readyNotification: true,
                ));
                $giftCard->update(['is_sent' => true]);
            } catch (\Throwable $e) {
                Log::error('Failed to send gift card ready email', [
                    'gift_card_id' => $giftCard->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyUserDelayed(GiftCard $giftCard): void
    {
        $user = $giftCard->user;
        if (! $user instanceof User) {
            return;
        }

        $url = $this->giftCardUrl($giftCard);
        $brand = $giftCard->brand_name ?? 'Gift card';
        $amount = number_format((float) $giftCard->amount, 2);
        $title = 'Gift card still processing';
        $body = "Your {$brand} gift card for \${$amount} is still being prepared. We will notify you as soon as it is ready.";

        try {
            $user->notify(new GiftCardRedemptionDelayedNotification($giftCard, $url));
        } catch (\Throwable $e) {
            Log::warning('Gift card delayed in-app notification failed', [
                'gift_card_id' => $giftCard->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendPush(
            $user->id,
            $title,
            $body,
            $url,
            $giftCard,
            'gift_card_delayed',
        );
    }

    private function giftCardUrl(GiftCard $giftCard): string
    {
        return route('gift-cards.show.id', $giftCard);
    }

    private function sendPush(
        int $userId,
        string $title,
        string $body,
        string $url,
        GiftCard $giftCard,
        string $type,
    ): void {
        $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::GiftCard);
        $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::GiftCard);
        $pushLinks = ShortChannelNotification::pushDeepLinkData();

        $pushData = $this->firebaseService->stringifyFcmData([
            'type' => $type,
            'title' => $pushTitle,
            'body' => $pushBody,
            'url' => $pushLinks['url'],
            'click_action' => $pushLinks['click_action'],
            'gift_card_id' => (string) $giftCard->id,
            'source_type' => 'gift_card',
            'source_id' => (string) $giftCard->id,
            'module_name' => PushNotificationModule::GiftCard->value,
            'module_record_id' => $giftCard->id,
            'created_by' => $giftCard->user_id,
            'deep_link' => $pushLinks['deep_link'],
        ]);

        try {
            $this->firebaseService->sendToUser($userId, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Gift card push notification failed', [
                'gift_card_id' => $giftCard->id,
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
