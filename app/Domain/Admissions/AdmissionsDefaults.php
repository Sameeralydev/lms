<?php

namespace App\Domain\Admissions;

use App\Models\AdmissionSetting;
use App\Models\Institution;
use App\Models\Tenant;

final class AdmissionsDefaults
{
    public static function provision(Tenant $tenant, Institution $institution, int $actorId): AdmissionSetting
    {
        return AdmissionSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey()],
            [
                'enabled' => false,
                'inquiries_enabled' => false,
                'public_applications_enabled' => false,
                'registration_mode' => 'existing_account',
                'timezone' => $institution->timezone ?: 'UTC',
                'locale' => $institution->locale ?: 'en',
                'policy' => [],
                'updated_by' => $actorId,
            ],
        );
    }
}
