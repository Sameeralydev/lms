<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicConfiguration extends AcademicRecord
{
    protected function casts(): array
    {
        return ['enabled_features' => 'array', 'terminology' => 'array'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<AcademicPolicyProfileVersion, $this> */
    public function activePolicyProfileVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyProfileVersion::class, 'active_policy_profile_version_id');
    }
}
