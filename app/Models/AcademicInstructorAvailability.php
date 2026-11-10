<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property list<int> $weekdays @property Carbon $effective_from @property Carbon $effective_until */
class AcademicInstructorAvailability extends AcademicRecord
{
    protected function casts(): array
    {
        return ['weekdays' => 'array', 'effective_from' => 'date', 'effective_until' => 'date'];
    }
}
