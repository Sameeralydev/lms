<?php

namespace App\Models;

class AdmissionDocumentReview extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
