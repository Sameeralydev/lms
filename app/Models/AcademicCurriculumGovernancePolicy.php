<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumGovernancePolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'minimum_reviewers' => 'integer',
            'require_independent_reviewer' => 'boolean',
            'require_separate_publisher' => 'boolean',
            'require_changes_note' => 'boolean',
            'require_rejection_note' => 'boolean',
            'require_checklist_completion' => 'boolean',
            'allow_resubmission' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<AcademicCurriculumFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumFramework::class, 'framework_id');
    }

    /** @return BelongsTo<AcademicCurriculumChecklistTemplate, $this> */
    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return BelongsTo<AcademicCompetencyFramework, $this> */
    public function competencyFramework(): BelongsTo
    {
        return $this->belongsTo(AcademicCompetencyFramework::class, 'competency_framework_id');
    }
}
