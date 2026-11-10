<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SisFamilyGroup extends AcademicRecord
{
    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return HasMany<SisFamilyStudent, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(SisFamilyStudent::class, 'family_group_id');
    }
}
