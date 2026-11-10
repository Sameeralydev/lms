<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicProgramCompetencyRequirement extends AcademicRecord
{
    protected function casts(): array
    {
        return ['evidence_requirements' => 'array', 'is_required' => 'boolean'];
    }

    /** @return BelongsTo<AcademicCompetency, $this> */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(AcademicCompetency::class, 'competency_id');
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }
}
