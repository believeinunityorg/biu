<?php

namespace App\Mail;

use App\Models\SupporterBelievePointGift;
use App\Models\User;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BelievePointGiftReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $sender,
        public User $recipient,
        public SupporterBelievePointGift $gift,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🎁 {$this->sender->name} sent you a GiftBP gift!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.believe-point-gift-received',
            with: [
                'senderName' => $this->sender->name,
                'recipientName' => $this->recipient->name,
                'amountLabel' => BelievePointGiftInviteService::formatAmount((float) $this->gift->amount),
                'occasion' => $this->gift->occasion,
                'messageText' => $this->gift->message,
                'giftUrl' => route('believe-points.index', [], true),
            ],
        );
    }
}
