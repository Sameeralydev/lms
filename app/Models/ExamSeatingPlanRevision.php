<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSeatingPlanRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'assignment_snapshot' => 'array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ExamSeatingPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ExamSeatingPlan::class, 'plan_id');
    }
}
