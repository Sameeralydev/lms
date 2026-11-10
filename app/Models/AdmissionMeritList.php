<?php

namespace App\Models;

class AdmissionMeritList extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['formula' => 'array', 'calculated_at' => 'datetime'];
    }
}
