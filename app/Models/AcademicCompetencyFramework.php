<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCompetencyFramework extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'submitted_at' => 'datetime', 'published_at' => 'datetime', 'lock_version' => 'integer', 'review_round' => 'integer', 'is_shared_template' => 'boolean'];
    }

    /** @return HasMany<AcademicCompetency, $this> */
    public function competencies(): HasMany
    {
        return $this->hasMany(AcademicCompetency::class, 'framework_id')->orderBy('sort_order');
    }

    /** @return BelongsTo<self, $this> */
    public function basedOnFramework(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_framework_id');
    }

    /** @return HasMany<AcademicCompetencyFrameworkReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(AcademicCompetencyFrameworkReview::class, 'framework_id')->latest('id');
    }
}
