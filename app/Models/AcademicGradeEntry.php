<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGradeEntry extends AcademicRecord
{
    protected function casts(): array
    {
        return ['earned_points' => 'decimal:2', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<AcademicGradebook, $this> */
    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebook::class, 'gradebook_id');
    }

    /** @return BelongsTo<AcademicGradebookItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebookItem::class, 'item_id');
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'enrollment_id');
    }

    /** @return HasMany<AcademicGradeEntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AcademicGradeEntryRevision::class, 'entry_id')->orderBy('revision_number');
    }
}
