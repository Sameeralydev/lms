<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGradebookItem extends AcademicRecord
{
    protected function casts(): array
    {
        return ['maximum_points' => 'decimal:2'];
    }

    /** @return BelongsTo<AcademicGradebook, $this> */
    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebook::class, 'gradebook_id');
    }

    /** @return BelongsTo<AcademicGradeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AcademicGradeCategory::class, 'category_id');
    }

    /** @return HasMany<AcademicGradeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AcademicGradeEntry::class, 'item_id');
    }
}
