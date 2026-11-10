<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampusController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        $query = Campus::query()->orderByDesc('is_primary')->orderBy('name');
        $membership = app(TenantContext::class)->membership();
        if ($membership->roles()->where('key', 'campus.admin')->exists() && ! $membership->roles()->whereIn('key', ['tenant.owner', 'tenant.admin'])->exists()) {
            $query->whereKey($membership->campus_id ?? 0);
        }
        if ($request->filled('q')) {
            $search = '%'.addcslashes($request->string('q')->toString(), '%_\\').'%';
            $query->where(fn ($where) => $where->where('name', 'like', $search)->orWhere('code', 'like', $search));
        }

        return Inertia::render('workspace/campuses/index', ['campuses' => $query->paginate(15)->withQueryString(), 'filters' => $request->only('q'), 'tenant' => ['slug' => $context->tenant()->slug]]);
    }

    public function store(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->rules((int) $institution->getKey()));
        $campus = DB::transaction(function () use ($data, $context, $institution, $request, $audit): Campus {
            Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            if ($data['is_primary'] ?? false) {
                Campus::query()->where('institution_id', $institution->getKey())->update(['is_primary' => false]);
            }
            $campus = Campus::query()->create([
                ...$data,
                'tenant_id' => $context->tenant()->getKey(),
                'institution_id' => $institution->getKey(),
                'status' => 'active',
            ]);
            $audit->record('campus.created', $request->user(), (int) $context->tenant()->getKey(), $campus, ['code' => $campus->code]);

            return $campus;
        });

        return back()->with('status', "Campus {$campus->name} created.");
    }

    public function update(Request $request, Tenant $tenant, int $campusId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $campus = Campus::query()->findOrFail($campusId);
        $data = $request->validate($this->rules((int) $campus->institution_id, $campus));
        DB::transaction(function () use ($campusId, $data, $context, $request, $audit): void {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $lockedCampus = Campus::query()->lockForUpdate()->findOrFail($campusId);
            $this->assertCampusAssignment($lockedCampus, $context);
            if ($data['is_primary'] ?? false) {
                Campus::query()->where('institution_id', $institution->getKey())->where('id', '!=', $lockedCampus->getKey())->update(['is_primary' => false]);
            }
            $lockedCampus->fill($data)->save();
            $audit->record('campus.updated', $request->user(), (int) $context->tenant()->getKey(), $lockedCampus, ['fields' => array_keys($data)]);
        });

        return back()->with('status', 'Campus updated.');
    }

    public function status(Request $request, Tenant $tenant, int $campusId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        DB::transaction(function () use ($campusId, $data, $request, $context, $audit): void {
            Institution::query()->lockForUpdate()->firstOrFail();
            $campus = Campus::query()->lockForUpdate()->findOrFail($campusId);
            $this->assertCampusAssignment($campus, $context);
            abort_if($data['status'] === 'inactive' && $campus->is_primary, 409, 'Choose another primary campus before deactivating this one.');
            $campus->forceFill(['status' => $data['status']])->save();
            $audit->record('campus.status_changed', $request->user(), (int) $context->tenant()->getKey(), $campus, ['status' => $campus->status]);
        });

        return back()->with('status', 'Campus status updated.');
    }

    /** @return array<string, array<int, string>> */
    private function rules(int $institutionId, ?Campus $campus = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('campuses', 'code')->where(fn ($query) => $query->where('institution_id', $institutionId))->ignore($campus?->getKey())],
            'name' => ['required', 'string', 'max:180'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function assertCampusAssignment(Campus $campus, TenantContext $context): void
    {
        $membership = $context->membership();
        if ($membership->roles()->where('key', 'campus.admin')->exists() && ! $membership->roles()->whereIn('key', ['tenant.owner', 'tenant.admin'])->exists()) {
            abort_unless((int) $membership->campus_id === (int) $campus->getKey(), 404);
        }
    }
}
