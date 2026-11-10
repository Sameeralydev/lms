<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $registration_opens_at
 * @property Carbon|null $registration_closes_at
 * @property Carbon|null $late_registration_closes_at
 * @property Carbon|null $add_deadline_at
 * @property Carbon|null $drop_deadline_at
 * @property Carbon|null $withdrawal_deadline_at
 * @property Carbon|null $archived_at
 * @property string $institution_timezone
 * @property string|null $cancellation_reason
 * @property int $academic_year_id
 * @property int $academic_period_id
 * @property int $academic_course_id
 * @property int $policy_id
 */
class CourseOffering extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'registration_opens_at' => 'immutable_datetime', 'registration_closes_at' => 'immutable_datetime',
            'late_registration_closes_at' => 'immutable_datetime', 'add_deadline_at' => 'immutable_datetime',
            'drop_deadline_at' => 'immutable_datetime', 'withdrawal_deadline_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
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
    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<EnrollmentPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(EnrollmentPolicy::class, 'policy_id');
    }

    /** @return HasMany<CourseSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class, 'offering_id');
    }

    /** @return HasMany<CourseEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'offering_id');
    }
}
