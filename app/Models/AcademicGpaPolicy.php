<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicGpaPolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'exclude_pass_fail' => 'boolean'];
    }

    /** @return HasMany<AcademicGpaStandingBand, $this> */
    public function standingBands(): HasMany
    {
        return $this->hasMany(AcademicGpaStandingBand::class, 'policy_id')->orderByDesc('minimum_gpa');
    }
}
