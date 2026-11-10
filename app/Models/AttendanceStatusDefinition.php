<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceStatusDefinition extends AcademicRecord
{
    protected function casts(): array
    {
        return ['counts_attended' => 'boolean', 'counts_expected' => 'boolean', 'requires_reason' => 'boolean', 'requires_evidence' => 'boolean', 'system_controlled' => 'boolean'];
    }

    /** @return BelongsTo<AttendancePolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyVersion::class, 'policy_version_id');
    }
}
