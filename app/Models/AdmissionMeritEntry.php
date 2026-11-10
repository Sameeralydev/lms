<?php

namespace App\Models;

class AdmissionMeritEntry extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['score' => 'decimal:4', 'score_breakdown' => 'array', 'manual_override' => 'boolean'];
    }
}
