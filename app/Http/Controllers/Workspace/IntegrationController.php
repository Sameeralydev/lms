<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Integrations\CaseV1p1Provider;
use App\Domain\Integrations\CaseStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\IntegrationApiCredential;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class IntegrationController
{
    public function index(Tenant $tenant, TenantContext $context): InertiaResponse
    {
        $credentials = IntegrationApiCredential::query()->where('tenant_id', $context->tenant()->getKey())->latest('id')->get([
            'id', 'name', 'token_prefix', 'scopes', 'last_used_at', 'expires_at', 'revoked_at', 'created_at',
        ]);

        return Inertia::render('workspace/integrations/index', [
            'tenant' => ['slug' => $tenant->slug],
            'credentials' => $credentials,
            'newToken' => session()->pull('newIntegrationToken'),
        ]);
    }

    public function store(Request $request, Tenant $tenant, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $plainToken = 'tmi_'.bin2hex(random_bytes(32));
        $credential = IntegrationApiCredential::query()->create([
            'tenant_id' => $context->tenant()->getKey(),
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => substr($plainToken, 0, 12),
            'scopes' => ['case.read'],
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $request->user()->getKey(),
        ]);
        $audit->record('integration.credential.created', $request->user(), (int) $context->tenant()->getKey(), $credential, ['credential_id' => $credential->getKey(), 'scope' => 'case.read']);
        session()->flash('newIntegrationToken', $plainToken);

        return to_route('tenant.integrations.index', $tenant)->with('status', 'Read-only CASE API credential created. Copy it now; it will not be shown again.');
    }

    public function revoke(Request $request, Tenant $tenant, int $credentialId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $credential = IntegrationApiCredential::query()->where('tenant_id', $context->tenant()->getKey())->whereKey($credentialId)->whereNull('revoked_at')->firstOrFail();
        $credential->forceFill(['revoked_at' => now()])->save();
        $audit->record('integration.credential.revoked', $request->user(), (int) $context->tenant()->getKey(), $credential, ['credential_id' => $credential->getKey()]);

        return to_route('tenant.integrations.index', $tenant)->with('status', 'Integration credential revoked.');
    }

    public function caseDocuments(Request $request, Tenant $tenant, CaseV1p1Provider $provider, AuditRecorder $audit): JsonResponse
    {
        $this->recordAccess($request, $tenant, 'case.documents.list', $audit);
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $limit = (int) ($validated['limit'] ?? 100);
        $offset = (int) ($validated['offset'] ?? 0);
        $page = $provider->documents($tenant, $limit, $offset);
        $headers = ['X-Total-Count' => (string) $page['total']];
        $base = url('/api/tenants/'.$tenant->slug.'/ims/case/v1p1/CFDocuments');
        $links = [];
        if ($offset > 0) {
            $previousOffset = max(0, $offset - $limit);
            $links[] = '<'.$base.'?'.http_build_query(['limit' => $limit, 'offset' => $previousOffset]).'>; rel="prev"';
        }
        if ($offset + $limit < $page['total']) {
            $links[] = '<'.$base.'?'.http_build_query(['limit' => $limit, 'offset' => $offset + $limit]).'>; rel="next"';
        }
        if ($links !== []) {
            $headers['Link'] = implode(', ', $links);
        }

        return response()->json(['CFDocuments' => $page['items']])->withHeaders($headers)->header('Cache-Control', 'private, no-store');
    }

    public function caseDocument(Request $request, Tenant $tenant, string $sourcedId, CaseV1p1Provider $provider, AuditRecorder $audit): JsonResponse
    {
        $this->recordAccess($request, $tenant, 'case.documents.read', $audit);

        return $this->caseRead(fn (): array => $provider->documentBySourceId($tenant, $sourcedId));
    }

    public function caseItem(Request $request, Tenant $tenant, string $sourcedId, CaseV1p1Provider $provider, AuditRecorder $audit): JsonResponse
    {
        $this->recordAccess($request, $tenant, 'case.items.read', $audit);

        return $this->caseRead(fn (): array => $provider->itemBySourceId($tenant, $sourcedId));
    }

    public function casePackage(Request $request, Tenant $tenant, string $sourcedId, CaseV1p1Provider $provider, AuditRecorder $audit): JsonResponse
    {
        $this->recordAccess($request, $tenant, 'case.packages.read', $audit);

        return $this->caseRead(fn (): array => $provider->packageBySourceId($tenant, $sourcedId));
    }

    private function recordAccess(Request $request, Tenant $tenant, string $operation, AuditRecorder $audit): void
    {
        $credential = $request->attributes->get('integration_credential');
        $audit->record('integration.'.$operation, null, (int) $tenant->getKey(), $credential, ['credential_id' => $credential->getKey()]);
    }

    /** @param callable(): array<string, mixed> $read */
    private function caseRead(callable $read): JsonResponse
    {
        try {
            return response()->json($read())->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return CaseStatus::failure(404, 'unknownobject', 'Unknown Object');
            }

            throw $exception;
        }
    }
}
