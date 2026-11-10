<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property int $id @property int $tenant_id @property int $document_version_id @property string $status @property Carbon|null $started_at @property Carbon|null $completed_at */
class AdmissionDocumentScanEvent extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'attempt_number' => 'integer'];
    }
}
