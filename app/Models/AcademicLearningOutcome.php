<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcademicLearningOutcome extends AcademicRecord
{
    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<AcademicLearningOutcome, $this> */
    public function parentOutcome(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_outcome_id');
    }

    /** @return BelongsToMany<AcademicProgram, $this> */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(AcademicProgram::class, 'academic_program_outcomes', 'learning_outcome_id', 'academic_program_id')->withPivot('tenant_id')->withTimestamps();
    }

    /** @return BelongsToMany<AcademicCourseVersion, $this> */
    public function courseVersions(): BelongsToMany
    {
        return $this->belongsToMany(AcademicCourseVersion::class, 'academic_course_version_outcomes', 'learning_outcome_id', 'academic_course_version_id')->withPivot('tenant_id')->withTimestamps();
    }
}
