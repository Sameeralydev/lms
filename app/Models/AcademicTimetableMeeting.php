<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property list<int> $weekdays */
class AcademicTimetableMeeting extends AcademicRecord
{
    protected function casts(): array
    {
        return ['weekdays' => 'array', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<AcademicTimetable, $this> */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(AcademicTimetable::class, 'timetable_id');
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_user_id');
    }

    /** @return BelongsTo<AcademicRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(AcademicRoom::class, 'room_id');
    }
}
