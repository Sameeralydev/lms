<?php

namespace App\Models;

class AcademicCurriculumChecklistItem extends AcademicRecord
{
    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'requires_note' => 'boolean'];
    }
}
