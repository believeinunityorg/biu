<?php

namespace App\Mail;

use App\Models\BelievePointGiftInvite;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BelievePointGiftInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BelievePointGiftInvite $invite)
    {
        $this->invite->loadMissing('sender');
    }

    public function envelope(): Envelope
    {
        $senderName = $this->invite->sender?->name ?? 'Someone';

        return new Envelope(
            subject: "🎁 {$senderName} sent you a GiftBP gift!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.believe-point-gift-invite',
            with: [
                'invite' => $this->invite,
                'senderName' => $this->invite->sender?->name ?? 'A friend',
                'amountLabel' => BelievePointGiftInviteService::formatAmount((float) $this->invite->amount),
                'holdDays' => BelievePointGiftInviteService::holdDays(),
                'registerUrl' => $this->invite->registerUrl(),
                'expiresAt' => $this->invite->expires_at?->timezone(config('app.timezone'))->format('F j, Y'),
                'messageText' => $this->invite->message,
                'occasion' => $this->invite->occasion,
            ],
        );
    }
}
