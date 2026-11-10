<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantLifecycle;
use App\Domain\Tenancy\TenantProvisioner;
use App\Http\Controllers\Controller;
use App\Mail\TenantInvitationMail;
use App\Models\AuditEvent;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Tenant::query()->with(['institution' => fn ($institution) => $institution->withoutGlobalScope('tenant')])->withCount([
            'campuses' => fn ($campuses) => $campuses->withoutGlobalScope('tenant'),
            'memberships as active_memberships_count' => fn ($memberships) => $memberships->withoutGlobalScope('tenant')->where('status', 'active'),
        ])->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('q')) {
            $search = '%'.addcslashes($request->string('q')->toString(), '%_\\').'%';
            $query->where(fn ($where) => $where->where('name', 'like', $search)->orWhere('slug', 'like', $search));
        }

        return Inertia::render('platform/tenants/index', [
            'tenants' => $query->paginate(15)->withQueryString(),
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/tenants/create');
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $mailIsTestFake = app()->runningUnitTests() && Mail::getFacadeRoot() instanceof MailFake;
        $mailIsLocalE2e = app()->environment('testing') && config('app.e2e_test_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) && ! $mailIsTestFake && ! $mailIsLocalE2e, 503, 'Owner invitation delivery is disabled until a real mail transport is configured.');

        $data = $request->validate([
            ...$this->institutionRules(),
            'owner_email' => ['required', 'email', 'max:255'],
        ]);
        $ownerEmail = mb_strtolower(trim($data['owner_email']));
        if ($ownerEmail === mb_strtolower((string) $request->user()->email)) {
            throw ValidationException::withMessages(['owner_email' => 'Choose an institution owner other than your platform operator account.']);
        }
        $result = $provisioner->provision($data, $request->user(), $ownerEmail);
        $tenant = $result->tenant;
        $invitationUrl = URL::temporarySignedRoute('invitations.show', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        Mail::to($ownerEmail)->send(new TenantInvitationMail($tenant->name, $request->user()->name, $invitationUrl));

        $response = redirect()->route('platform.tenants.show', $tenant)->with('status', 'Institution tenant provisioned. The initial owner invitation is ready for acceptance.');
        if ($mailIsLocalE2e) {
            $response->headers->set('X-E2E-Invitation-Url', $invitationUrl);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function show(Tenant $tenant): Response
    {
        $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->first();

        return Inertia::render('platform/tenants/show', [
            'tenant' => $tenant->loadCount([
                'campuses' => fn ($campuses) => $campuses->withoutGlobalScope('tenant'),
                'memberships' => fn ($memberships) => $memberships->withoutGlobalScope('tenant'),
            ])->load(['domains:id,tenant_id,domain,verified_at,is_primary,status']),
            'institution' => $institution,
            'recentEvents' => AuditEvent::query()->where('tenant_id', $tenant->getKey())->latest('occurred_at')->limit(12)->get(),
            'ownerInvitation' => $tenant->invitations()->where('role_key', 'tenant.owner')->latest()->first(['id', 'email', 'status', 'expires_at', 'accepted_at']),
            'canManageStatus' => $tenant->status !== 'archived',
        ]);
    }

    public function update(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        abort_if($tenant->status === 'archived', 409);
        $data = $request->validate($this->institutionRules(false));

        DB::transaction(function () use ($data, $tenant, $request, $audit): void {
            $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->lockForUpdate()->firstOrFail();
            $institution->fill($data)->save();
            $tenant->forceFill(['name' => $data['display_name'] ?: $data['official_name']])->save();
            $audit->record('institution.updated', $request->user(), (int) $tenant->getKey(), $institution, ['fields' => array_keys($data)]);
        });

        return back()->with('status', 'Institution details updated.');
    }

    public function status(Request $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'archived'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $lifecycle->transition($tenant, $data['status'], $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Tenant status updated.');
    }

    /** @return array<string, array<int, string|Rule>> */
    private function institutionRules(bool $creating = true): array
    {
        return [
            'official_name' => ['required', 'string', 'max:180'],
            'display_name' => ['nullable', 'string', 'max:140'],
            'slug' => $creating ? ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('tenants', 'slug')] : ['sometimes', 'prohibited'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'type' => ['required', Rule::in(['school', 'college', 'university', 'academy', 'corporate', 'online', 'other'])],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'alpha', 'size:2'],
            'region' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
        ];
    }
}
