<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveAdmissionsTenant
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
        abort_unless($tenant->status === 'active', 404);

        $this->context->enterApplicant($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
