<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function set(Tenant $tenant, TenantMembership $membership): void
    {
        if ($membership->tenant_id !== $tenant->id) {
            throw new LogicException('Tenant context membership does not belong to the tenant.');
        }

        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function enterProvisioning(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->membership = null;
    }

    /** Establishes tenant query scoping for an authenticated applicant without granting staff membership. */
    public function enterApplicant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->membership = null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function hasMembership(): bool
    {
        return $this->membership !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('Tenant context is not established.');
    }

    public function membership(): TenantMembership
    {
        return $this->membership ?? throw new LogicException('Tenant context is not established.');
    }
}
