<?php

namespace App\Mail;

use App\Models\BelievePointGiftInvite;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BelievePointGiftAwaitingClaimMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BelievePointGiftInvite $invite) {}

    public function envelope(): Envelope
    {
        $senderName = $this->invite->sender?->name ?: 'Someone';

        return new Envelope(
            subject: "{$senderName} sent you Believe Points — accept your gift",
        );
    }

    public function content(): Content
    {
        $this->invite->loadMissing('sender');

        return new Content(
            markdown: 'emails.believe-point-gift-awaiting-claim',
            with: [
                'invite' => $this->invite,
                'senderName' => $this->invite->sender?->name ?: 'Someone',
                'amountLabel' => BelievePointGiftInviteService::formatAmount((float) $this->invite->amount),
                'holdDays' => BelievePointGiftInviteService::holdDays(),
                'claimUrl' => route('gift-bp.index', [], true),
                'occasion' => $this->invite->occasion,
                'message' => $this->invite->message,
            ],
        );
    }
}
