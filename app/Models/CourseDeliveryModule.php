<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseDeliveryModule extends AcademicRecord
{
    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }

    /** @return HasMany<CourseDeliveryLesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(CourseDeliveryLesson::class, 'module_id')->orderBy('sort_order');
    }
}
