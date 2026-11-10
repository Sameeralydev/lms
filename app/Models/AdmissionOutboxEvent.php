<?php

namespace App\Models;

/** @property array<string, mixed>|null $safe_payload */
class AdmissionOutboxEvent extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['safe_payload' => 'array', 'available_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
