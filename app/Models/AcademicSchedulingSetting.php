<?php

namespace App\Models;

/** @property list<int> $teaching_days */
class AcademicSchedulingSetting extends AcademicRecord
{
    protected function casts(): array
    {
        return ['teaching_days' => 'array', 'break_windows' => 'array'];
    }
}
