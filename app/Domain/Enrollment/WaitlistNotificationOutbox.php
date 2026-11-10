<?php

namespace App\Domain\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\CourseWaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Writes enrollment notifications atomically to the existing durable admission outbox. */
final class WaitlistNotificationOutbox
{
    /** Return false when the same transition already has an outbox record. */
    public function record(CourseWaitlistEntry $entry, CourseEnrollment $enrollment, string $transition): bool
    {
        if (! in_array($transition, ['offer_created', 'offer_accepted', 'offer_expired'], true)) {
            throw new \InvalidArgumentException('Unsupported waitlist notification transition.');
        }

        $tenantId = (int) $entry->tenant_id;
        $tenantSlug = (string) DB::table('tenants')->where('id', $tenantId)->value('slug');
        $eventType = 'enrollment.waitlist.'.$transition;
        $eventKey = $eventType.':'.$entry->getKey();
        $exists = DB::table('admission_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('safe_payload->event_key', $eventKey)
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('admission_outbox_events')->insert([
            'tenant_id' => $tenantId,
            'application_id' => null,
            'event_type' => $eventType,
            'channel' => 'local',
            'safe_payload' => json_encode([
                'event_key' => $eventKey,
                'recipient_user_id' => (int) $entry->student()->value('user_id'),
                'tenant_slug' => $tenantSlug,
                'student_id' => (int) $entry->student_id,
                'enrollment_id' => (int) $enrollment->getKey(),
                'waitlist_entry_id' => (int) $entry->getKey(),
                'section_id' => (int) $entry->section_id,
                'offer_expires_at' => $entry->offer_expires_at ? Carbon::parse($entry->offer_expires_at)->toIso8601String() : null,
            ], JSON_THROW_ON_ERROR),
            'status' => 'queued',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
