<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseDeliveryTemplate extends AcademicRecord
{
    /** @return HasMany<CourseDeliveryTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CourseDeliveryTemplateVersion::class, 'template_id')->orderByDesc('version_number');
    }

    /** @return HasMany<CourseDeliveryTemplateShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(CourseDeliveryTemplateShare::class, 'template_id');
    }
}
