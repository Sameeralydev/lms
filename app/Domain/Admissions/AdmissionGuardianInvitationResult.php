<?php

namespace App\Domain\Admissions;

use App\Models\AdmissionGuardianAuthorization;

final readonly class AdmissionGuardianInvitationResult
{
    public function __construct(
        public AdmissionGuardianAuthorization $authorization,
        public string $url,
    ) {}
}
