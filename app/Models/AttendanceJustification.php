<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceJustification extends AcademicRecord
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AttendanceEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(AttendanceEntry::class, 'entry_id');
    }

    /** @return HasMany<AttendanceJustificationEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(AttendanceJustificationEvidence::class, 'justification_id');
    }
}
