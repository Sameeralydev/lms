<?php

namespace App\Models;

class AdmissionReviewerAssignment extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
