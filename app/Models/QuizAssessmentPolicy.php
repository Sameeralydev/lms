<?php

namespace App\Models;

/** @property array<string, mixed> $settings */
class QuizAssessmentPolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
