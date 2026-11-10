<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantDomainResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTrustedHost
{
    public function __construct(private readonly TenantDomainResolver $domains) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));
        $trustedHosts = array_map('strtolower', config('app.trusted_hosts', []));
        $platformHosts = array_map('strtolower', config('app.platform_hosts', []));

        if (in_array($host, $platformHosts, true)) {
            return $next($request);
        }

        $domain = $this->domains->resolve($host);
        if ($domain) {
            $request->attributes->set('verifiedTenantDomain', $domain);

            return $next($request);
        }

        abort_unless(in_array($host, $trustedHosts, true), 400, 'Untrusted host.');

        return $next($request);
    }
}
