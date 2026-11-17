<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;

final class TenantEntitlements
{
    /** Tenants without an explicit plan retain legacy access until an operator assigns a plan. */
    public function allows(Tenant $tenant, string $feature): bool
    {
        $subscription = $this->subscription($tenant);
        if (! $subscription) {
            return true;
        }
        if ($subscription->status !== 'active' || ! $subscription->plan) {
            return false;
        }

        return ($subscription->plan->entitlements[$feature] ?? false) === true;
    }

    /**
     * A null result means no limit was configured. Limits are exposed for creation workflows to
     * enforce at their domain boundary; this service does not silently alter existing records.
     */
    public function limit(Tenant $tenant, string $key): ?int
    {
        $subscription = $this->subscription($tenant);
        $value = $subscription?->status === 'active' ? ($subscription->plan?->limits[$key] ?? null) : null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function subscription(Tenant $tenant): ?\App\Models\TenantSubscription
    {
        $tenant->loadMissing('subscription.plan');

        return $tenant->subscription;
    }
}
