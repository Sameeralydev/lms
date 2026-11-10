<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamCandidate extends AcademicRecord
{
    protected function casts(): array
    {
        return ['accommodations' => 'array', 'registered_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<ExamCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ExamCycle::class, 'cycle_id');
    }
}
