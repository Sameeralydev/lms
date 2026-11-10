<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $student_id
 * @property int $offering_id
 * @property int $section_id
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $dropped_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $withdrawn_effective_at
 * @property Carbon|null $archived_at
 * @property string|null $withdrawal_reason
 * @property string|null $credits_snapshot
 */
class CourseEnrollment extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'dropped_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime', 'withdrawn_effective_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime', 'credits_snapshot' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<CourseOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }
}
