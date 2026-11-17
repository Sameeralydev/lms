<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrStaffEmploymentHistory extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    /** @return BelongsTo<HrStaffRecord, $this> */
    public function staffRecord(): BelongsTo
    {
        return $this->belongsTo(HrStaffRecord::class, 'staff_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
