<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantWorkspace
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        if (! $tenant instanceof Tenant) {
            abort_unless(is_string($tenant) && $tenant !== '', 404);
            $tenant = Tenant::query()->where('slug', $tenant)->firstOrFail();
            $request->route()->setParameter('tenant', $tenant);
        }
        if ($tenant->status !== 'active') {
            abort(404);
        }

        $verifiedDomain = $request->attributes->get('verifiedTenantDomain');
        if ($verifiedDomain && (int) $verifiedDomain->tenant_id !== (int) $tenant->getKey()) {
            abort(404);
        }

        $membership = TenantMembership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $request->user()->getKey())
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            abort(404);
        }

        $this->context->set($tenant, $membership);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
