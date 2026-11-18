<?php

namespace App\Notifications;

use App\Models\CommunicationsNotificationPreference;
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
        $tenantId = isset($this->details['tenant_id']) ? (int) $this->details['tenant_id'] : 0;
        $userId = method_exists($notifiable, 'getKey') ? (int) $notifiable->getKey() : 0;

        if ($tenantId > 0 && $userId > 0 && ! CommunicationsNotificationPreference::allows($tenantId, $userId, 'billing')) {
            return [];
        }

        return ['database'];
    }

    /** @return array{type:string,message:string,tenant_id:int|null,details:array<string,int|string|null>} */
    public function toDatabase(object $notifiable): array
    {
        $tenantId = $this->details['tenant_id'] ?? null;

        return [
            'type' => $this->event,
            'message' => $this->message,
            'tenant_id' => is_int($tenantId) ? $tenantId : null,
            'details' => $this->details,
        ];
    }
}
