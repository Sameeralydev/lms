<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumNodeOutcome extends AcademicRecord
{
    protected $table = 'academic_curriculum_node_outcomes';

    /** @return BelongsTo<AcademicLearningOutcome, $this> */
    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(AcademicLearningOutcome::class);
    }
}
