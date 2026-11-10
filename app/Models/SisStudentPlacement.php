<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $student_id @property int $institution_id @property int|null $campus_id @property int|null $academic_year_id @property int|null $academic_period_id @property int|null $academic_program_id @property int|null $academic_class_id @property int|null $academic_section_id @property string $placement_type @property string $status @property Carbon $effective_from @property Carbon|null $effective_until */
class SisStudentPlacement extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<AcademicClass, $this> */
    public function academicClass(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class);
    }

    /** @return BelongsTo<AcademicSection, $this> */
    public function academicSection(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class);
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
