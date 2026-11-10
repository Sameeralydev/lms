<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SisPrivateDocumentScanEvent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<SisPrivateDocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SisPrivateDocumentVersion::class, 'document_version_id');
    }
}
