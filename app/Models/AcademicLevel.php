<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicLevel extends AcademicRecord
{
    /** @return BelongsTo<AcademicLevel, $this> */
    public function parentLevel(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_level_id');
    }

    /** @return HasMany<AcademicLevel, $this> */
    public function childLevels(): HasMany
    {
        return $this->hasMany(self::class, 'parent_level_id');
    }
}
