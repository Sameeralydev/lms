<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumCompletionRule extends AcademicRecord
{
    protected function casts(): array
    {
        return ['target_value' => 'decimal:3', 'is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'curriculum_version_id');
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }
}
