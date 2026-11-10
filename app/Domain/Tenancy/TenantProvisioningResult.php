<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use App\Models\TenantInvitation;

final readonly class TenantProvisioningResult
{
    public function __construct(
        public Tenant $tenant,
        public TenantInvitation $ownerInvitation,
        public string $ownerInvitationToken,
    ) {}
}
