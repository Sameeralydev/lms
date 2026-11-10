<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseDeliveryWorkspace extends AcademicRecord
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'archived_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
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

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'academic_curriculum_version_id');
    }

    /** @return HasMany<CourseDeliveryModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseDeliveryModule::class, 'workspace_id')->orderBy('sort_order');
    }

    /** @return HasMany<CourseDeliveryAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CourseDeliveryAssignment::class, 'workspace_id');
    }

    /** @return HasMany<CourseDeliveryLiveSession, $this> */
    public function liveSessions(): HasMany
    {
        return $this->hasMany(CourseDeliveryLiveSession::class, 'workspace_id')->orderBy('starts_at');
    }
}
