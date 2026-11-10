<?php

namespace App\Domain\Sis;

use App\Models\TenantInvitation;

final readonly class SisStudentIdentityInvitationResult
{
    public function __construct(
        public TenantInvitation $invitation,
        public string $rawToken,
    ) {}
}
