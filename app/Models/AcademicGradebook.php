<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGradebook extends AcademicRecord
{
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return BelongsTo<AcademicGradePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicGradePolicy::class, 'policy_id');
    }

    /** @return HasMany<AcademicGradebookItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AcademicGradebookItem::class, 'gradebook_id')->orderBy('id');
    }
}
