<?php

namespace App\Models;

class AcademicGradeSourceImportRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return ['previous_points' => 'decimal:2', 'imported_points' => 'decimal:2', 'revision_number' => 'integer', 'imported_at' => 'immutable_datetime'];
    }
}
