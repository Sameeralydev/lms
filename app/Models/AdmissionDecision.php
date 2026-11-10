<?php

namespace App\Models;

class AdmissionDecision extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
