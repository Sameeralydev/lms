<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCurriculumFramework extends AcademicRecord
{
    protected function casts(): array
    {
        return ['terminology' => 'array', 'required_learning_areas' => 'array', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    /** @return HasMany<AcademicCurriculumFrameworkOutcome, $this> */
    public function requiredOutcomes(): HasMany
    {
        return $this->hasMany(AcademicCurriculumFrameworkOutcome::class, 'framework_id')->where('required', true);
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<AcademicCurriculumFrameworkAdministrator, $this> */
    public function administrators(): HasMany
    {
        return $this->hasMany(AcademicCurriculumFrameworkAdministrator::class, 'framework_id')->orderByDesc('created_at');
    }
}
