<?php

namespace App\Models;

/** @property list<string>|null $allowed_mime_types */
class AdmissionDocumentRequirement extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['allowed_mime_types' => 'array', 'is_required' => 'boolean'];
    }
}
