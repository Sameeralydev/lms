<?php

namespace App\Notifications;

use App\Models\BillingPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class BillingPaymentStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly BillingPayment $payment, private readonly string $event, private readonly string $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->event,
            'title' => $this->message,
            'payment_number' => $this->payment->payment_number,
            'status' => $this->payment->status,
            'currency' => $this->payment->currency,
            'amount' => (string) $this->payment->amount,
            'tenant_id' => (int) $this->payment->tenant_id,
        ];
    }
}
