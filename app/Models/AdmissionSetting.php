<?php

namespace App\Models;

/** @property array<string, mixed>|null $policy */
class AdmissionSetting extends AdmissionsRecord
{
    protected $table = 'admissions_settings';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'inquiries_enabled' => 'boolean', 'public_applications_enabled' => 'boolean', 'policy' => 'array'];
    }
}
