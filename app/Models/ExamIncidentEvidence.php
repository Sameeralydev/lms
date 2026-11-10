<?php

namespace App\Models;

class ExamIncidentEvidence extends AcademicRecord
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }
}
