<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicTimetableChange extends AcademicRecord
{
    protected function casts(): array
    {
        return ['occurrence_date' => 'date', 'replacement_date' => 'date', 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicTimetable, $this> */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(AcademicTimetable::class, 'timetable_id');
    }

    /** @return BelongsTo<AcademicTimetableMeeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(AcademicTimetableMeeting::class, 'meeting_id');
    }
}
