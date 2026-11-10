<?php

namespace App\Models;

class AcademicTimetableSnapshot extends AcademicRecord
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
