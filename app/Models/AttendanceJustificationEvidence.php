<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceJustificationEvidence extends AcademicRecord
{
    protected function casts(): array
    {
        return ['scanned_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<AttendanceJustification, $this> */
    public function justification(): BelongsTo
    {
        return $this->belongsTo(AttendanceJustification::class, 'justification_id');
    }
}
