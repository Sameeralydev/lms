<?php

namespace App\Models;

class ExamAppealEvidence extends AcademicRecord
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }
}
