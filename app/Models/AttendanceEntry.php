<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceEntry extends AcademicRecord
{
    protected function casts(): array
    {
        return ['marked_at' => 'immutable_datetime', 'minutes_attended' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<AttendanceRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class, 'register_id');
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<AttendanceStatusDefinition, $this> */
    public function statusDefinition(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatusDefinition::class, 'status_definition_id');
    }

    /** @return HasMany<AttendanceCorrectionRequest, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class, 'entry_id')->latest();
    }
}
