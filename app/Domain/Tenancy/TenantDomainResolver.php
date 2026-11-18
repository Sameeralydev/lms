<?php

namespace App\Domain\Tenancy;

use App\Models\TenantDomain;
use Throwable;

final class TenantDomainResolver
{
    public function __construct(private readonly TenantEntitlements $entitlements) {}

    public function resolve(string $host): ?TenantDomain
    {
        $normalizedHost = strtolower(rtrim($host, '.'));

        try {
            $domain = TenantDomain::query()
                ->with('tenant')
                ->where('domain', $normalizedHost)
                ->where('status', 'verified')
                ->whereNotNull('verified_at')
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->first();
            if (! $domain || ! $this->entitlements->allows($domain->tenant, 'white_label')) {
                return null;
            }

            return $domain;
        } catch (Throwable) {
            return null;
        }
    }
}
