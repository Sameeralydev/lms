<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttendanceRecord extends AcademicRecord
{
    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ExamCandidate::class, 'candidate_id');
    }
}
