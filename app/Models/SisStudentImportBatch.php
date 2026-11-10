<?php

namespace App\Models;

/** @property array<string, mixed> $safe_report @property int $processed_count @property string $status */
class SisStudentImportBatch extends AcademicRecord
{
    protected function casts(): array
    {
        return ['safe_report' => 'array', 'confirmed_at' => 'datetime'];
    }
}
