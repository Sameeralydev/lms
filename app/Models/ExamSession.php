<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamSession extends AcademicRecord
{
    protected function casts(): array
    {
        return ['starts_at_utc' => 'immutable_datetime', 'ends_at_utc' => 'immutable_datetime', 'reporting_at_utc' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(AcademicCourse::class, 'academic_course_id');
    }

    /** @return BelongsTo<ExamCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ExamCycle::class, 'cycle_id');
    }

    /** @return HasOne<ExamPaper, $this> */
    public function paper(): HasOne
    {
        return $this->hasOne(ExamPaper::class, 'session_id');
    }

    /** @return HasOne<ExamSessionDelivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(ExamSessionDelivery::class, 'session_id');
    }
}
