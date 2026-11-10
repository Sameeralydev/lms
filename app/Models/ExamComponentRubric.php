<?php

namespace App\Models;

class ExamComponentRubric extends AcademicRecord
{
    protected function casts(): array
    {
        return ['maximum_marks' => 'decimal:2'];
    }
}
