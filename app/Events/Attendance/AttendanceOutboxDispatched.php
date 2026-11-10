<?php

namespace App\Events\Attendance;

/** Internal at-least-once event; consumers must de-duplicate by outbox ID. */
final readonly class AttendanceOutboxDispatched
{
    /** @param array<string, mixed> $safePayload */
    public function __construct(
        public int $outboxId,
        public int $tenantId,
        public string $eventType,
        public array $safePayload,
        public int $attempt,
    ) {}
}
