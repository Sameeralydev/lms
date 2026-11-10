<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A privacy-preserving notification: personal attendance details remain behind login. */
final class AttendanceUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $kind, private readonly string $tenantSlug) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = match ($this->kind) {
            'register' => ['Attendance updated', 'A class attendance record is available in your institution workspace.'],
            'justification' => ['Absence request reviewed', 'An absence request was reviewed. Sign in to view the decision.'],
            'correction' => ['Attendance correction reviewed', 'An attendance correction was reviewed. Sign in to view the updated record.'],
            default => throw new \InvalidArgumentException('Unsupported attendance notification.'),
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('Open attendance', rtrim((string) config('app.url'), '/').'/t/'.rawurlencode($this->tenantSlug).'/attendance/my');
    }
}
