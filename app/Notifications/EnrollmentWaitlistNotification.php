<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EnrollmentWaitlistNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $transition,
        private readonly int $sectionId,
        private readonly ?string $expiresAt,
        private readonly string $tenantSlug,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = match ($this->transition) {
            'offer_created' => (new MailMessage)->subject('A course seat is available')->line('A place is available from the course waitlist. Sign in to review and accept it.')->line('The offer expires at '.($this->expiresAt ?? 'the time shown in your registration workspace').'.'),
            'offer_accepted' => (new MailMessage)->subject('Course registration confirmed')->line('Your waitlist offer was accepted and your course registration is confirmed.'),
            'offer_expired' => (new MailMessage)->subject('Course waitlist offer expired')->line('Your course waitlist offer expired. You can review current registration options in your workspace.'),
            default => throw new \InvalidArgumentException('Unsupported waitlist notification transition.'),
        };

        return $message->action('Open course registration', rtrim((string) config('app.url'), '/').'/t/'.rawurlencode($this->tenantSlug).'/registration?section='.$this->sectionId);
    }
}
