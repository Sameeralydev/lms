<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCreditPolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'credit_hours_enabled' => 'boolean', 'separate_theory_lab' => 'boolean',
            'allowed_increment' => 'decimal:3', 'maximum_credits' => 'decimal:3',
            'theory_contact_hours_per_credit' => 'decimal:3', 'lab_contact_hours_per_credit' => 'decimal:3',
            'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<AcademicProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AcademicProgram::class, 'credit_policy_id');
    }

    /** @return HasMany<AcademicCourseVersion, $this> */
    public function courseVersions(): HasMany
    {
        return $this->hasMany(AcademicCourseVersion::class, 'credit_policy_id');
    }
}
