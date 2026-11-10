<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $student_id @property int $offering_id @property int $section_id @property int $position @property string $status @property Carbon|null $offered_at @property Carbon|null $offer_expires_at @property Carbon|null $accepted_at */
class CourseWaitlistEntry extends AcademicRecord
{
    protected function casts(): array
    {
        return ['offered_at' => 'immutable_datetime', 'offer_expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return BelongsTo<CourseOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }
}
