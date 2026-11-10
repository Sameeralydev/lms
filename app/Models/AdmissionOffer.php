<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property Carbon|null $accept_by */
class AdmissionOffer extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['accept_by' => 'datetime', 'issued_at' => 'datetime', 'responded_at' => 'datetime'];
    }
}
