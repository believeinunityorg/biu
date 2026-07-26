<?php

namespace App\Notifications;

use App\Models\BelievePointGiftInvite;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Notifications\Notification;

class BelievePointGiftAwaitingClaimNotification extends Notification
{
    public function __construct(public BelievePointGiftInvite $invite) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $amt = BelievePointGiftInviteService::formatAmount((float) $this->invite->amount);
        $senderName = $this->invite->sender?->name ?: 'Someone';

        return [
            'type' => 'gift_awaiting_claim',
            'title' => 'You received a Believe Points gift',
            'body' => "{$senderName} sent you {$amt} BP. Accept it on Gift BP to add it to your Gifted BP wallet.",
            'meta' => [
                'invite_id' => $this->invite->id,
                'amount' => (float) $this->invite->amount,
                'sender_id' => $this->invite->sender_id,
                'url' => route('gift-bp.index'),
            ],
        ];
    }
}
