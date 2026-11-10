<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPolicyMapping extends AcademicRecord
{
    /** @return BelongsTo<AcademicPolicyProfileVersion, $this> */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyProfileVersion::class, 'policy_profile_version_id');
    }

    /** @return BelongsTo<AcademicPolicyEvidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(AcademicPolicyEvidence::class, 'evidence_id');
    }
}
