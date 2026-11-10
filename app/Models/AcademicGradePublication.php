<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGradePublication extends AcademicRecord
{
    protected function casts(): array
    {
        return ['publication_number' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicGradebook, $this> */
    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebook::class, 'gradebook_id');
    }

    /** @return HasMany<AcademicGradePublicationEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AcademicGradePublicationEntry::class, 'publication_id')->orderBy('enrollment_id');
    }
}
