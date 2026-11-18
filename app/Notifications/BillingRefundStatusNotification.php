<?php

namespace App\Notifications;

use App\Models\BillingPayment;
use App\Models\BillingPaymentRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class BillingRefundStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BillingPayment $payment,
        private readonly BillingPaymentRefund $refund,
        private readonly string $event,
        private readonly string $message,
    ) {}

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
            'refund_number' => $this->refund->refund_number,
            'refund_status' => $this->refund->status,
            'payment_number' => $this->payment->payment_number,
            'currency' => $this->refund->currency,
            'amount' => (string) $this->refund->amount,
            'tenant_id' => (int) $this->payment->tenant_id,
        ];
    }
}
