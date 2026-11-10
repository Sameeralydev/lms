<?php

namespace App\Events\Admissions;

/**
 * Internal, at-least-once outbox notification. Consumers must de-duplicate by outboxId.
 * The payload is restricted to fields explicitly selected by the workflow producer.
 */
final readonly class AdmissionOutboxDispatched
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
