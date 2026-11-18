<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Internal-only financial notification; external channels are intentionally excluded. */
final class BillingActivityNotification extends Notification
{
    use Queueable;

    /** @param array<string, int|string|null> $details */
    public function __construct(private readonly string $event, private readonly string $message, private readonly array $details = []) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{type:string,message:string,details:array<string,int|string|null>} */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->event, 'message' => $this->message, 'details' => $this->details];
    }
}
