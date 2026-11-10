<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicUnit extends AcademicRecord
{
    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    /** @return HasMany<AcademicUnit, $this> */
    public function childUnits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    /** @return BelongsToMany<Campus, $this> */
    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'academic_unit_campuses')->withPivot('tenant_id')->withTimestamps();
    }
}
