<?php

namespace App\Models;

class SisRegistrySetting extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'number_width' => 'integer',
            'next_sequence' => 'integer',
            'document_retention_days' => 'integer',
            'document_archive_after_days' => 'integer',
            'document_purge_after_days' => 'integer',
            'suspend_membership_on_student_suspension' => 'boolean',
            'promotion_requires_approval' => 'boolean',
            'lifecycle_requires_approval' => 'boolean',
        ];
    }
}
