<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property Carbon $starts_on @property Carbon $ends_on */
class AcademicCalendarException extends AcademicRecord
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
