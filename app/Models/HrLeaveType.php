<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class HrLeaveType extends AcademicRecord
{
    protected function casts(): array
    {
        return ['max_calendar_days' => 'integer', 'requires_approval' => 'boolean'];
    }

    /** @return HasMany<HrLeaveRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'leave_type_id');
    }
}
