<?php

namespace App\Models;

/** @property int $tenant_id @property int $application_id @property int $offer_id @property string $status @property array<string, mixed> $approved_snapshot */
class AdmissionSisHandoff extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['approved_snapshot' => 'array', 'processed_at' => 'datetime'];
    }
}
