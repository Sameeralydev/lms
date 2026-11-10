<?php

namespace App\Models;

class AdmissionAssessment extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['score' => 'decimal:3', 'maximum_score' => 'decimal:3', 'scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'approved_at' => 'datetime'];
    }
}
