<?php

namespace App\Models;

/** @property array<string, string> $rubric_marks @property int $lock_version */
class ExamComponentMarkDraft extends AcademicRecord
{
    protected function casts(): array
    {
        return ['rubric_marks' => 'array', 'private_notes' => 'encrypted'];
    }
}
