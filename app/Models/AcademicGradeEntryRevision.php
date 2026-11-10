<?php

namespace App\Models;

class AcademicGradeEntryRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return ['previous_points' => 'decimal:2', 'new_points' => 'decimal:2', 'revision_number' => 'integer'];
    }
}
