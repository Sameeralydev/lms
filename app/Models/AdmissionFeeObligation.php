<?php

namespace App\Models;

class AdmissionFeeObligation extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }
}
