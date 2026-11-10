<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCurriculumChecklistTemplate extends AcademicRecord
{
    /** @return HasMany<AcademicCurriculumChecklistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AcademicCurriculumChecklistItem::class, 'template_id')->orderBy('sort_order');
    }
}
