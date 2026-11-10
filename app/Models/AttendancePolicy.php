<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return ['current_version' => 'integer'];
    }

    /** @return HasMany<AttendancePolicyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AttendancePolicyVersion::class, 'policy_id')->orderByDesc('version');
    }
}
