<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Integrations\CaseStatus;
use App\Models\IntegrationApiCredential;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationToken
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $routeTenant = $request->route('tenant');
        $tenantSlug = $routeTenant instanceof Tenant ? $routeTenant->slug : (string) $routeTenant;
        $tenant = Tenant::query()->where('slug', $tenantSlug)->where('status', 'active')->first();
        $bearer = (string) $request->bearerToken();

        if (! $tenant || ! preg_match('/^tmi_[a-f0-9]{64}$/', $bearer)) {
            return CaseStatus::failure(401, 'unauthorised_request', 'The integration request could not be authenticated.');
        }

        $credential = IntegrationApiCredential::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('token_hash', hash('sha256', $bearer))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if (! $credential) {
            return CaseStatus::failure(401, 'unauthorised_request', 'The integration request could not be authenticated.');
        }
        if (! in_array($scope, $credential->scopes ?? [], true)) {
            return CaseStatus::failure(403, 'forbidden', 'The integration credential is not authorized for this operation.');
        }

        // Credential authentication happens on every API request. Persist usage
        // at most once per five minutes to avoid turning read traffic into a
        // write hotspot while retaining useful last-use visibility.
        if (! $credential->last_used_at || $credential->last_used_at->lte(now()->subMinutes(5))) {
            $lastUsedCutoff = now()->subMinutes(5);
            IntegrationApiCredential::query()
                ->whereKey($credential->getKey())
                ->where('tenant_id', $tenant->getKey())
                ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $lastUsedCutoff))
                ->update(['last_used_at' => now(), 'updated_at' => now()]);
        }
        $request->attributes->set('integration_credential', $credential);
        app(TenantContext::class)->enterProvisioning($tenant);

        return $next($request);
    }
}
