<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $tenant_id @property int $institution_id @property int $student_id @property int $academic_period_id @property int $section_id @property int $teacher_id @property string $comment @property string $status */
final class AcademicReportCardComment extends AcademicRecord
{
    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }
}
