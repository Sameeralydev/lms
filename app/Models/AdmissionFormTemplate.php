<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
class AdmissionFormTemplate extends AdmissionsRecord
{
    /** @return HasMany<AdmissionFormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AdmissionFormVersion::class, 'form_template_id')->orderByDesc('version_number');
    }
}
