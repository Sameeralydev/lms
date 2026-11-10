<?php

namespace App\Listeners;

use App\Events\Attendance\AttendanceOutboxDispatched;
use App\Models\User;
use App\Notifications\AttendanceUpdateNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/** Resolves recipients from current tenant-scoped records; payloads never contain addresses. */
final class DeliverAttendanceNotification
{
    public function handle(AttendanceOutboxDispatched $event): void
    {
        $kind = match ($event->eventType) {
            'attendance.register.submitted' => 'register',
            'attendance.justification.reviewed' => 'justification',
            'attendance.correction.reviewed' => 'correction',
            default => null,
        };
        if ($kind === null) {
            return;
        }

        $tenantSlug = DB::table('tenants')->where('id', $event->tenantId)->value('slug');
        if (! is_string($tenantSlug) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $tenantSlug) !== 1) {
            throw new RuntimeException('The attendance notification tenant could not be resolved.');
        }

        $recipientIds = match ($kind) {
            'register' => $this->registerRecipients($event),
            'justification' => $this->reviewedJustificationRecipient($event),
            'correction' => $this->reviewedCorrectionRecipient($event),
        };
        foreach ($recipientIds as $recipientId) {
            $user = User::query()->find((int) $recipientId);
            if ($user !== null) {
                Notification::route('mail', $user->email)->notify(new AttendanceUpdateNotification($kind, $tenantSlug));
            }
        }
    }

    /** @return list<int> */
    private function registerRecipients(AttendanceOutboxDispatched $event): array
    {
        $registerId = filter_var($event->safePayload['register_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $registerId || $registerId < 1) {
            throw new RuntimeException('The attendance register notification payload is invalid.');
        }

        return DB::table('attendance_registers as r')
            ->join('attendance_entries as e', fn ($join) => $join->on('e.register_id', '=', 'r.id')->on('e.tenant_id', '=', 'r.tenant_id'))
            ->join('sis_students as s', fn ($join) => $join->on('s.id', '=', 'e.student_id')->on('s.tenant_id', '=', 'e.tenant_id'))
            ->join('tenant_memberships as tm', fn ($join) => $join->on('tm.user_id', '=', 's.user_id')->on('tm.tenant_id', '=', 'r.tenant_id'))
            ->where('r.tenant_id', $event->tenantId)->where('r.id', $registerId)->where('r.status', 'submitted')
            ->where('tm.status', 'active')->where('s.status', 'active')->distinct()->pluck('s.user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function reviewedJustificationRecipient(AttendanceOutboxDispatched $event): array
    {
        $id = filter_var($event->safePayload['justification_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $id || $id < 1) {
            throw new RuntimeException('The attendance justification notification payload is invalid.');
        }

        return DB::table('attendance_justifications as j')
            ->join('tenant_memberships as tm', fn ($join) => $join->on('tm.user_id', '=', 'j.student_user_id')->on('tm.tenant_id', '=', 'j.tenant_id'))
            ->where('j.tenant_id', $event->tenantId)->where('j.id', $id)->whereIn('j.status', ['approved', 'rejected'])
            ->where('tm.status', 'active')->distinct()->pluck('j.student_user_id')->map(static fn ($userId): int => (int) $userId)->all();
    }

    /** @return list<int> */
    private function reviewedCorrectionRecipient(AttendanceOutboxDispatched $event): array
    {
        $id = filter_var($event->safePayload['correction_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $id || $id < 1) {
            throw new RuntimeException('The attendance correction notification payload is invalid.');
        }

        return DB::table('attendance_correction_requests as c')
            ->join('attendance_entries as e', fn ($join) => $join->on('e.id', '=', 'c.entry_id')->on('e.tenant_id', '=', 'c.tenant_id'))
            ->join('sis_students as s', fn ($join) => $join->on('s.id', '=', 'e.student_id')->on('s.tenant_id', '=', 'e.tenant_id'))
            ->join('tenant_memberships as tm', fn ($join) => $join->on('tm.user_id', '=', 's.user_id')->on('tm.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $event->tenantId)->where('c.id', $id)->whereIn('c.status', ['approved', 'rejected'])
            ->where('tm.status', 'active')->where('s.status', 'active')->distinct()->pluck('s.user_id')->map(static fn ($userId): int => (int) $userId)->all();
    }
}
