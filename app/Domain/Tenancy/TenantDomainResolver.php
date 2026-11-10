<?php

namespace App\Domain\Tenancy;

use App\Models\TenantDomain;
use Throwable;

final class TenantDomainResolver
{
    public function resolve(string $host): ?TenantDomain
    {
        $normalizedHost = strtolower(rtrim($host, '.'));

        try {
            return TenantDomain::query()
                ->with('tenant')
                ->where('domain', $normalizedHost)
                ->where('status', 'verified')
                ->whereNotNull('verified_at')
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->first();
        } catch (Throwable) {
            return null;
        }
    }
}
