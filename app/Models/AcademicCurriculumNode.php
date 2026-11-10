<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCurriculumNode extends AcademicRecord
{
    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'estimated_minutes' => 'integer', 'sort_order' => 'integer', 'planned_starts_on' => 'date', 'planned_ends_on' => 'date', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'curriculum_version_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parentNode(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<AcademicCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(AcademicCourse::class, 'academic_course_id');
    }

    /** @return BelongsTo<AcademicCourseVersion, $this> */
    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicCourseVersion::class, 'academic_course_version_id');
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<AcademicCurriculumNodeOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(AcademicCurriculumNodeOutcome::class, 'node_id')->with('learningOutcome');
    }

    /** @return HasMany<AcademicCurriculumPrerequisite, $this> */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(AcademicCurriculumPrerequisite::class, 'node_id');
    }

    /** @return HasMany<AcademicCurriculumNodeCompetency, $this> */
    public function competencies(): HasMany
    {
        return $this->hasMany(AcademicCurriculumNodeCompetency::class, 'node_id')->with('competency');
    }
}
