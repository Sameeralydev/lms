<?php

namespace App\Models;

class AdmissionEligibilityEvaluation extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['rule_results' => 'array', 'evaluated_at' => 'datetime'];
    }
}
