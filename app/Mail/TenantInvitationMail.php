<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $tenantName, public string $inviterName, public string $acceptanceUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation to join {$this->tenantName}");
    }

    public function content(): Content
    {
        return new Content(text: 'emails.tenant-invitation');
    }
}
