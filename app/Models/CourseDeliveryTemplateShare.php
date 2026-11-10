<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseDeliveryTemplateShare extends AcademicRecord
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<CourseDeliveryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryTemplate::class, 'template_id');
    }
}
