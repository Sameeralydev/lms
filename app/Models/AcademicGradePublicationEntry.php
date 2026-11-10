<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGradePublicationEntry extends AcademicRecord
{
    protected function casts(): array
    {
        return ['percentage_basis_points' => 'integer', 'percentage' => 'decimal:2', 'grade_points' => 'decimal:3', 'passed' => 'boolean'];
    }

    /** @return BelongsTo<AcademicGradePublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(AcademicGradePublication::class, 'publication_id');
    }

    /** @return BelongsTo<AcademicGradebook, $this> */
    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebook::class, 'gradebook_id');
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'enrollment_id');
    }
}
