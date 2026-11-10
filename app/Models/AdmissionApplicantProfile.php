<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property string|null $given_name @property string|null $middle_name @property string|null $family_name @property Carbon|null $birth_date */
class AdmissionApplicantProfile extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['birth_date' => 'date', 'guardian_consent_at' => 'datetime'];
    }
}
