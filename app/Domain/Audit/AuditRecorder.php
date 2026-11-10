<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditRecorder
{
    /** @param array<string, mixed> $metadata Safe, explicitly selected audit metadata only. */
    public function record(string $event, ?User $actor = null, ?int $tenantId = null, ?Model $subject = null, array $metadata = []): AuditEvent
    {
        $request = app()->bound('request') ? app(Request::class) : null;

        return AuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor?->getKey(),
            'event_type' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'request_id' => $request?->header('X-Request-Id') ? substr((string) $request->header('X-Request-Id'), 0, 80) : null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
