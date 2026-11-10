<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPolicyGap extends AcademicRecord
{
    protected function casts(): array
    {
        return ['due_on' => 'date', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicPolicyProfileVersion, $this> */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyProfileVersion::class, 'policy_profile_version_id');
    }
}
