<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $canManage = $context->membership()->hasPermission('domains.manage');
        $domains = TenantDomain::query()->where('tenant_id', $context->tenant()->getKey())->orderByDesc('is_primary')->orderBy('domain')->get()
            ->map(fn (TenantDomain $domain): array => [
                'id' => $domain->getKey(),
                'domain' => $domain->domain,
                'status' => $domain->status,
                'verified_at' => $domain->verified_at,
                'is_primary' => $domain->is_primary,
                'verification_token' => $canManage ? $domain->verification_token : null,
            ]);

        return Inertia::render('workspace/domains/index', [
            'domains' => $domains,
            'canManage' => $canManage,
            'tenant' => ['slug' => $context->tenant()->slug],
        ]);
    }

    public function store(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $request->merge(['domain' => Str::lower(trim((string) $request->input('domain')))]);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::notIn(config('app.platform_hosts', [])), 'unique:tenant_domains,domain']]);
        $domain = Str::lower(rtrim($data['domain'], '.'));
        $token = Str::random(40);
        $record = DB::transaction(function () use ($context, $domain, $token, $request, $audit): TenantDomain {
            Tenant::query()->lockForUpdate()->findOrFail($context->tenant()->getKey());
            $record = TenantDomain::query()->create([
                'tenant_id' => $context->tenant()->getKey(),
                'domain' => $domain,
                'verification_token' => $token,
                'status' => 'pending',
            ]);
            $audit->record('tenant.domain_added', $request->user(), (int) $context->tenant()->getKey(), $record, ['domain' => $domain]);

            return $record;
        });

        return back()->with('status', 'Add a DNS TXT record named _techmentors-verification.'.$domain.' with value techmentors-verification='.$token.'. Then choose Verify.');
    }

    public function verify(Request $request, Tenant $tenant, int $domainId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $domain = TenantDomain::query()->where('tenant_id', $context->tenant()->getKey())->findOrFail($domainId);
        $records = @dns_get_record('_techmentors-verification.'.$domain->domain, DNS_TXT);
        $expected = 'techmentors-verification='.$domain->verification_token;
        $verified = collect($records ?: [])->contains(fn (array $record) => ($record['txt'] ?? '') === $expected || ($record['entries'][0] ?? '') === $expected);
        abort_unless($verified, 422, 'The expected domain verification TXT record was not found.');
        DB::transaction(function () use ($domainId, $request, $context, $audit): void {
            Tenant::query()->lockForUpdate()->findOrFail($context->tenant()->getKey());
            $domain = TenantDomain::query()->where('tenant_id', $context->tenant()->getKey())->lockForUpdate()->findOrFail($domainId);
            $domain->forceFill(['verified_at' => now(), 'status' => 'verified'])->save();
            $audit->record('tenant.domain_verified', $request->user(), (int) $context->tenant()->getKey(), $domain, ['domain' => $domain->domain]);
        });

        return back()->with('status', 'Domain ownership verified.');
    }

    public function primary(Request $request, Tenant $tenant, int $domainId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        DB::transaction(function () use ($domainId, $request, $context, $audit): void {
            Tenant::query()->lockForUpdate()->findOrFail($context->tenant()->getKey());
            $domain = TenantDomain::query()->where('tenant_id', $context->tenant()->getKey())->lockForUpdate()->findOrFail($domainId);
            abort_unless($domain->verified_at !== null, 409, 'Verify domain ownership before making it primary.');
            TenantDomain::query()->where('tenant_id', $context->tenant()->getKey())->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
            $audit->record('tenant.primary_domain_changed', $request->user(), (int) $context->tenant()->getKey(), $domain, ['domain' => $domain->domain]);
        });

        return back()->with('status', 'Primary domain updated.');
    }
}
