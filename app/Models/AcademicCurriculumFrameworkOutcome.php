<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumFrameworkOutcome extends AcademicRecord
{
    protected $table = 'academic_curriculum_framework_outcomes';

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    /** @return BelongsTo<AcademicCurriculumFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumFramework::class, 'framework_id');
    }

    /** @return BelongsTo<AcademicLearningOutcome, $this> */
    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(AcademicLearningOutcome::class);
    }
}
