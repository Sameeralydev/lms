<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCompetency extends AcademicRecord
{
    protected function casts(): array
    {
        return ['mastery_levels' => 'array', 'evidence_requirements' => 'array', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<AcademicCompetencyFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCompetencyFramework::class, 'framework_id');
    }
}
