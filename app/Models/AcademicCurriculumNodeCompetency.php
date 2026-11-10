<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumNodeCompetency extends AcademicRecord
{
    protected function casts(): array
    {
        return ['mapping_strength' => 'string'];
    }

    /** @return BelongsTo<AcademicCurriculumNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumNode::class, 'node_id');
    }

    /** @return BelongsTo<AcademicCompetency, $this> */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(AcademicCompetency::class, 'competency_id');
    }
}
