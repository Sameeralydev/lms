<?php

namespace App\Domain\Academic\Attendance;

interface AttendanceOutboxDispatcher
{
    /** @param array<string,mixed> $safePayload */
    public function dispatch(int $outboxId, int $tenantId, string $eventType, array $safePayload, int $attempt): void;
}
