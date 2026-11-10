<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionRequest extends AcademicRecord
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'proposed_minutes_attended' => 'integer'];
    }

    /** @return BelongsTo<AttendanceEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(AttendanceEntry::class, 'entry_id');
    }

    /** @return BelongsTo<AttendanceStatusDefinition, $this> */
    public function proposedStatus(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatusDefinition::class, 'proposed_status_id');
    }
}
