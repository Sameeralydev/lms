<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AdmissionGuardianInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $invitationUrl,
        public readonly string $institutionName,
        public readonly string $applicantName,
        public readonly string $relationship,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify an admissions guardian request');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admissions.guardian-invitation');
    }
}
