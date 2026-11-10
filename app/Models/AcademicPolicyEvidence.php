<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPolicyEvidence extends AcademicRecord
{
    protected function casts(): array
    {
        return ['source_published_on' => 'date', 'retrieved_on' => 'date'];
    }

    /** @return BelongsTo<AcademicPolicyProfileVersion, $this> */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyProfileVersion::class, 'policy_profile_version_id');
    }
}
