<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumReview extends AcademicRecord
{
    protected $table = 'academic_curriculum_reviews';

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'curriculum_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'decided_at' => 'datetime', 'checklist_snapshot' => 'array', 'checklist_responses' => 'array'];
    }
}
