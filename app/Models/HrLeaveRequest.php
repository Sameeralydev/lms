<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrLeaveRequest extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'start_on' => 'date', 'end_on' => 'date', 'calendar_days' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<HrStaffRecord, $this> */
    public function staffRecord(): BelongsTo
    {
        return $this->belongsTo(HrStaffRecord::class, 'staff_record_id');
    }

    /** @return BelongsTo<HrLeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
