<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCurriculumVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'published_at' => 'datetime', 'effective_from' => 'date', 'effective_until' => 'date', 'lock_version' => 'integer', 'review_round' => 'integer'];
    }

    /** @return BelongsTo<AcademicCurriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculum::class, 'curriculum_id');
    }

    /** @return HasMany<AcademicCurriculumNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(AcademicCurriculumNode::class, 'curriculum_version_id')->orderBy('sort_order');
    }

    /** @return HasMany<AcademicCurriculumReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(AcademicCurriculumReview::class, 'curriculum_version_id')->latest('id');
    }

    /** @return HasMany<AcademicCurriculumCompletionRule, $this> */
    public function completionRules(): HasMany
    {
        return $this->hasMany(AcademicCurriculumCompletionRule::class, 'curriculum_version_id')->orderBy('sort_order');
    }
}
