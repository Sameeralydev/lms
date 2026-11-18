<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrStaffAttendance extends AcademicRecord
{
    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<HrStaffRecord, $this> */
    public function staffRecord(): BelongsTo
    {
        return $this->belongsTo(HrStaffRecord::class, 'staff_record_id');
    }
}
