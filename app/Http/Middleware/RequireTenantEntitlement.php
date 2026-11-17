<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantEntitlement
{
    public function __construct(private readonly TenantContext $context, private readonly TenantEntitlements $entitlements) {}

    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $feature ??= $this->featureForRoute((string) $request->route()?->getName());
        if ($feature === null) {
            return $next($request);
        }
        abort_unless($this->context->hasTenant() && $this->entitlements->allows($this->context->tenant(), $feature), 403, 'This feature is not included in the institution\'s active plan.');

        return $next($request);
    }

    private function featureForRoute(string $routeName): ?string
    {
        foreach (config('saas.route_features', []) as $prefix => $feature) {
            if (str_starts_with($routeName, $prefix)) {
                return $feature;
            }
        }

        return null;
    }
}
