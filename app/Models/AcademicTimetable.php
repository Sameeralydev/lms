<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTimetable extends AcademicRecord
{
    protected function casts(): array
    {
        return ['independent_review_required' => 'boolean', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    /** @return HasMany<AcademicTimetableMeeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(AcademicTimetableMeeting::class, 'timetable_id');
    }
}
