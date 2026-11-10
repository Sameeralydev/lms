<?php

namespace App\Listeners;

use App\Events\Admissions\AdmissionOutboxDispatched;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\EnrollmentWaitlistNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/** Synchronous outbox consumer: mail failures bubble to the durable retry processor. */
final class DeliverEnrollmentWaitlistNotification
{
    public function handle(AdmissionOutboxDispatched $event): void
    {
        if (! str_starts_with($event->eventType, 'enrollment.waitlist.')) {
            return;
        }

        $transition = substr($event->eventType, strlen('enrollment.waitlist.'));
        if (! in_array($transition, ['offer_created', 'offer_accepted', 'offer_expired'], true)
            || (int) ($event->safePayload['recipient_user_id'] ?? 0) < 1
            || (int) ($event->safePayload['section_id'] ?? 0) < 1
            || (int) ($event->safePayload['waitlist_entry_id'] ?? 0) < 1) {
            throw new RuntimeException('The waitlist notification outbox payload is invalid.');
        }

        $user = User::query()->find((int) $event->safePayload['recipient_user_id']);
        if (! $user) {
            throw new RuntimeException('The waitlist notification recipient could not be resolved.');
        }

        $activeMembership = TenantMembership::query()
            ->where('tenant_id', $event->tenantId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();
        if (! $activeMembership) {
            return;
        }

        Notification::route('mail', $user->email)->notify(new EnrollmentWaitlistNotification(
            $transition,
            (int) $event->safePayload['section_id'],
            isset($event->safePayload['offer_expires_at']) ? (string) $event->safePayload['offer_expires_at'] : null,
        ));
    }
}
