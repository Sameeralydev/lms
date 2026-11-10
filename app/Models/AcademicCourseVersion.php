<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcademicCourseVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'credit_bearing' => 'boolean', 'theory_credits' => 'decimal:3',
            'laboratory_credits' => 'decimal:3', 'total_credits' => 'decimal:3',
            'contact_hours' => 'decimal:3', 'duration_amount' => 'decimal:2',
            'delivery_modes' => 'array', 'effective_from' => 'date', 'effective_until' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<AcademicCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(AcademicCourse::class, 'academic_course_id');
    }

    /** @return BelongsTo<AcademicCreditPolicy, $this> */
    public function creditPolicy(): BelongsTo
    {
        return $this->belongsTo(AcademicCreditPolicy::class, 'credit_policy_id');
    }

    /** @return BelongsToMany<AcademicLearningOutcome, $this> */
    public function learningOutcomes(): BelongsToMany
    {
        return $this->belongsToMany(AcademicLearningOutcome::class, 'academic_course_version_outcomes', 'academic_course_version_id', 'learning_outcome_id')->withPivot('tenant_id')->withTimestamps();
    }
}
