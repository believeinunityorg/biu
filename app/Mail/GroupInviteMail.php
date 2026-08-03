<?php

namespace App\Mail;

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GroupInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Group $group,
        public GroupInvite $invite,
        public User $inviter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviter->name} invited you to join {$this->group->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.group-invite',
            with: [
                'groupName' => $this->group->name,
                'inviterName' => $this->inviter->name,
                'acceptUrl' => route('groups.invites.accept', $this->invite->token),
                'appName' => config('app.name', 'Believe In Unity'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
