<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPolicyProfileVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'credit_hours_applicable' => 'boolean', 'compulsory_structure' => 'array',
            'document_requirements' => 'array', 'configuration' => 'array',
            'effective_from' => 'date', 'effective_until' => 'date', 'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AcademicPolicyProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyProfile::class, 'academic_policy_profile_id');
    }

    /** @return HasMany<AcademicPolicyEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(AcademicPolicyEvidence::class, 'policy_profile_version_id');
    }

    /** @return HasMany<AcademicPolicyMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(AcademicPolicyMapping::class, 'policy_profile_version_id');
    }

    /** @return HasMany<AcademicPolicyGap, $this> */
    public function gaps(): HasMany
    {
        return $this->hasMany(AcademicPolicyGap::class, 'policy_profile_version_id');
    }
}
