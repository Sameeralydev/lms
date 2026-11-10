<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumPrerequisite extends AcademicRecord
{
    protected $table = 'academic_curriculum_prerequisites';

    /** @return BelongsTo<AcademicCurriculumNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumNode::class, 'node_id');
    }

    /** @return BelongsTo<AcademicCurriculumNode, $this> */
    public function prerequisiteNode(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumNode::class, 'prerequisite_node_id');
    }
}
