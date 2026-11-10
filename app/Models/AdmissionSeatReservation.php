<?php

namespace App\Models;

class AdmissionSeatReservation extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['reserved_at' => 'datetime', 'expires_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
