<?php

namespace App\Domain\Academic\Attendance;

use App\Events\Attendance\AttendanceOutboxDispatched;

final class LocalAttendanceOutboxDispatcher implements AttendanceOutboxDispatcher
{
    public function dispatch(int $outboxId, int $tenantId, string $eventType, array $safePayload, int $attempt): void
    {
        event(new AttendanceOutboxDispatched($outboxId, $tenantId, $eventType, $safePayload, $attempt));
    }
}
