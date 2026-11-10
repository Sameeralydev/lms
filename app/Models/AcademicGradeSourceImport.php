<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGradeSourceImport extends AcademicRecord
{
    protected $fillable = [
        'gradebook_id', 'item_id', 'enrollment_id', 'source_type', 'source_record_id', 'source_version',
        'source_fingerprint', 'imported_points', 'imported_by', 'imported_at',
    ];

    protected function casts(): array
    {
        return ['imported_points' => 'decimal:2', 'imported_at' => 'immutable_datetime'];
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

    /** @return HasMany<AcademicGradeSourceImportRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AcademicGradeSourceImportRevision::class, 'import_id')->orderBy('revision_number');
    }
}
