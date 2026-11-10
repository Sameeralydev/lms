<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPolicyProfile extends AcademicRecord
{
    /** @return HasMany<AcademicPolicyProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AcademicPolicyProfileVersion::class, 'academic_policy_profile_id')->orderByDesc('version_number');
    }
}
