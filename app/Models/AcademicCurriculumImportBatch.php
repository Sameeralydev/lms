<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $payload */
class AcademicCurriculumImportBatch extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expected_lock_version' => 'integer',
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'imported_count' => 'integer',
            'attempt_count' => 'integer',
            'manual_retry_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'curriculum_version_id');
    }
}
