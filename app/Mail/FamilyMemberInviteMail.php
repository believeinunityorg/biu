<?php

namespace App\Mail;

use App\Models\FamilyMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FamilyMemberInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public FamilyMember $member,
        public ?User $inviter,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are invited to the '.$this->organization->name.' family reunion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.family-member-invite',
            with: [
                'organizationName' => $this->organization->name,
                'inviterName' => $this->inviter?->name ?? 'A family member',
                'memberName' => $this->member->full_name,
                'relationshipLabel' => $this->member->relationship_label,
                'acceptUrl' => $this->acceptUrl,
                'appName' => config('app.name', 'Believe In Unity'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
