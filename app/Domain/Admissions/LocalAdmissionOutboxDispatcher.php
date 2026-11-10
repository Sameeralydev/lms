<?php

namespace App\Domain\Admissions;

use App\Events\Admissions\AdmissionOutboxDispatched;
use App\Models\AdmissionOutboxEvent;
use RuntimeException;

final class LocalAdmissionOutboxDispatcher implements AdmissionOutboxDispatcher
{
    public function dispatch(AdmissionOutboxEvent $outbox): void
    {
        if ($outbox->channel !== 'local' || ! is_array($outbox->safe_payload)) {
            throw new RuntimeException('Unsupported or malformed admission outbox message.');
        }

        event(new AdmissionOutboxDispatched(
            (int) $outbox->getKey(),
            (int) $outbox->tenant_id,
            (string) $outbox->event_type,
            $outbox->safe_payload,
            (int) $outbox->attempts,
        ));
    }
}
