<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Hr\StaffLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicUnit;
use App\Models\Campus;
use App\Models\HrStaffRecord;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class HrStaffController
{
    /** @var list<string> */
    private const STAFF_ROLES = ['tenant.owner', 'tenant.admin', 'campus.admin', 'academic.admin', 'teacher', 'sis.registrar', 'hr.manager', 'admissions.officer', 'exam.controller', 'exam.invigilator', 'examiner', 'exam.moderator'];

    public function __construct(private readonly TenantContext $context, private readonly StaffLifecycle $lifecycle) {}

    public function index(Request $request, Tenant $tenant): Response|RedirectResponse
    {
        $membership = $this->context->membership();
        if (! $membership->hasPermission('hr.staff.view')) {
            abort_unless($membership->hasPermission('hr.staff.view-own'), 403);
            $own = HrStaffRecord::query()->where('membership_id', $membership->getKey())->firstOrFail();

            return to_route('tenant.hr.staff.show', [$tenant, $own->getKey()]);
        }

        $query = $this->scopedRecords($membership)->with(['membership.user:id,name,email', 'campus:id,name', 'academicUnit:id,name']);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended', 'separated'])],
            'campus_id' => ['nullable', 'integer'],
        ]);
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('employee_number', 'like', '%'.$term.'%')
                    ->orWhere('designation', 'like', '%'.$term.'%')
                    ->orWhereHas('membership.user', fn (Builder $user) => $user->where('name', 'like', '%'.$term.'%'));
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['campus_id']) && $membership->campus_id === null) {
            $query->where('campus_id', $filters['campus_id']);
        }
        $canSensitive = $membership->hasPermission('hr.staff.sensitive.view');
        $stats = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'inactive_or_suspended' => (clone $query)->whereIn('status', ['inactive', 'suspended'])->count(),
        ];

        return Inertia::render('workspace/hr/staff/index', [
            'tenant' => ['slug' => $tenant->slug],
            'staff' => $query->orderBy('employee_number')->paginate(25)->withQueryString()->through(fn (HrStaffRecord $record): array => $this->summary($record, $canSensitive)),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'campus_id' => $filters['campus_id'] ?? ''],
            'canManage' => $membership->hasPermission('hr.staff.manage'),
            'campuses' => $membership->campus_id === null
                ? Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(static fn (Campus $campus): array => ['id' => (int) $campus->getKey(), 'name' => $campus->name])->all()
                : [],
            'stats' => $stats,
        ]);
    }

    public function create(Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('hr.staff.manage'), 403);
        $existing = HrStaffRecord::query()->select('membership_id');
        $canSensitive = $membership->hasPermission('hr.staff.sensitive.view');
        $members = TenantMembership::query()->where('status', 'active')
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('roles.key', self::STAFF_ROLES))
            ->whereNotIn('id', $existing)
            ->when($membership->campus_id !== null, fn (Builder $query) => $query->where('campus_id', $membership->campus_id))
            ->with('user:id,name,email')->orderBy('id')->limit(250)->get()
            ->map(static fn (TenantMembership $member): array => ['id' => (int) $member->getKey(), 'name' => $member->user?->name ?? 'Staff account', 'email' => $canSensitive ? $member->user?->email : null]);

        return Inertia::render('workspace/hr/staff/create', [
            'tenant' => ['slug' => $tenant->slug],
            'members' => $members,
            'campuses' => $this->campusOptions($membership),
            'departments' => $this->unitOptions($membership),
            'managers' => $this->managerOptions($membership),
            'canManageSensitive' => $membership->hasPermission('hr.staff.sensitive.view'),
            'fixedCampusId' => $membership->campus_id,
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('hr.staff.manage'), 403);
        $data = $request->validate([
            'membership_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
            'employee_number' => ['required', 'string', 'max:48', 'regex:/\A[A-Za-z0-9._-]+\z/', Rule::unique('hr_staff_records', 'employee_number')->where('tenant_id', $tenant->getKey())],
            'designation' => ['required', 'string', 'max:120'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'joined_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where('tenant_id', $tenant->getKey())->where('institution_id', $membership->institution_id)->where('status', 'active')],
            'manager_id' => ['nullable', 'integer', Rule::exists('hr_staff_records', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
            ...$this->sensitiveRules($membership->hasPermission('hr.staff.sensitive.view')),
        ]);
        $staffMembership = TenantMembership::query()->whereKey($data['membership_id'])->where('status', 'active')->firstOrFail();
        abort_unless($staffMembership->hasPermission('academic.view') || $staffMembership->hasPermission('enrollment.instructor.roster.view') || $staffMembership->hasPermission('exams.view') || $staffMembership->hasPermission('sis.view') || $staffMembership->hasPermission('admissions.view') || $staffMembership->hasPermission('hr.staff.view'), 422);
        $campusId = $membership->campus_id ?? ($data['campus_id'] ?? null);
        $this->assertUnitScope($data['academic_unit_id'] ?? null, $campusId, $membership->institution_id);
        $manager = isset($data['manager_id']) ? HrStaffRecord::query()->whereKey($data['manager_id'])->firstOrFail() : null;
        $this->assertCampus($membership, $manager);
        $record = $this->lifecycle->hire($request->user(), $staffMembership, [
            ...$data,
            'campus_id' => $campusId,
            'institution_id' => $membership->institution_id,
        ]);

        return to_route('tenant.hr.staff.show', [$tenant, $record->getKey()])->with('status', 'Staff employment record created.');
    }

    public function show(Request $request, Tenant $tenant, int $staffId): Response
    {
        $membership = $this->context->membership();
        $record = HrStaffRecord::query()->with(['membership.user:id,name,email', 'campus:id,name', 'academicUnit:id,name', 'manager.membership.user:id,name', 'employmentHistory.recorder:id,name'])->whereKey($staffId)->firstOrFail();
        $own = (int) $record->membership_id === (int) $membership->getKey();
        abort_unless($membership->hasPermission('hr.staff.view') || ($own && $membership->hasPermission('hr.staff.view-own')), 403);
        if (! $own) {
            $this->assertCampus($membership, $record);
        }
        $sensitive = $membership->hasPermission('hr.staff.sensitive.view') || $own;

        return Inertia::render('workspace/hr/staff/show', [
            'tenant' => ['slug' => $tenant->slug],
            'staff' => [
                ...$this->summary($record, $sensitive),
                'membership_id' => (int) $record->membership_id,
                'institution_id' => (int) $record->institution_id,
                'academic_unit_id' => $record->academic_unit_id,
                'manager_id' => $record->manager_id,
                'manager_name' => $record->manager?->membership?->user?->name,
                'separated_on' => $record->separated_on?->toDateString(),
                'personal_email' => $sensitive ? $record->personal_email : null,
                'personal_phone' => $sensitive ? $record->personal_phone : null,
                'emergency_contact_name' => $sensitive ? $record->emergency_contact_name : null,
                'emergency_contact_phone' => $sensitive ? $record->emergency_contact_phone : null,
                'history' => $record->employmentHistory->map(static fn ($history): array => [
                    'id' => (int) $history->getKey(), 'event_type' => $history->event_type,
                    'from_status' => $history->from_status, 'to_status' => $history->to_status,
                    'from_campus_id' => $history->from_campus_id, 'to_campus_id' => $history->to_campus_id,
                    'from_academic_unit_id' => $history->from_academic_unit_id, 'to_academic_unit_id' => $history->to_academic_unit_id,
                    'from_designation' => $history->from_designation, 'to_designation' => $history->to_designation,
                    'from_employment_type' => $history->from_employment_type, 'to_employment_type' => $history->to_employment_type,
                    'from_manager_id' => $history->from_manager_id, 'to_manager_id' => $history->to_manager_id,
                    'effective_on' => $history->effective_on?->toDateString(), 'reason' => $sensitive ? $history->reason : null,
                    'recorded_by' => $history->recorder?->name,
                ])->all(),
            ],
            'canManage' => $membership->hasPermission('hr.staff.manage') && ! $own,
            'canManageStatus' => $membership->hasPermission('hr.staff.status.manage') && ! $own,
            'canManageSensitive' => $membership->hasPermission('hr.staff.sensitive.view'),
            'campuses' => $this->campusOptions($membership),
            'departments' => $this->unitOptions($membership),
            'managers' => $this->managerOptions($membership, $record->getKey()),
        ]);
    }

    public function update(Request $request, Tenant $tenant, int $staffId): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('hr.staff.manage'), 403);
        $record = HrStaffRecord::query()->whereKey($staffId)->firstOrFail();
        abort_if((int) $record->membership_id === (int) $membership->getKey(), 403, 'Staff members cannot edit their own HR record.');
        $this->assertCampus($membership, $record);
        $data = $request->validate([
            'designation' => ['required', 'string', 'max:120'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where('tenant_id', $tenant->getKey())->where('institution_id', $membership->institution_id)->where('status', 'active')],
            'manager_id' => ['nullable', 'integer', Rule::exists('hr_staff_records', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')->where('id', '!=', $record->getKey())],
            ...$this->sensitiveRules($membership->hasPermission('hr.staff.sensitive.view'), false),
        ]);
        $campusId = $membership->campus_id ?? ($data['campus_id'] ?? null);
        $this->assertUnitScope($data['academic_unit_id'] ?? null, $campusId, $membership->institution_id);
        $manager = isset($data['manager_id']) ? HrStaffRecord::query()->whereKey($data['manager_id'])->firstOrFail() : null;
        $this->assertCampus($membership, $manager);
        $this->lifecycle->update($request->user(), $record, [...$data, 'campus_id' => $campusId]);

        return back()->with('status', 'Staff record updated.');
    }

    public function changeStatus(Request $request, Tenant $tenant, int $staffId): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('hr.staff.status.manage'), 403);
        $record = HrStaffRecord::query()->whereKey($staffId)->firstOrFail();
        abort_if((int) $record->membership_id === (int) $membership->getKey(), 403, 'Staff members cannot change their own employment status.');
        $this->assertCampus($membership, $record);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended', 'separated'])],
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->lifecycle->changeStatus($request->user(), $record, $data['status'], $data['effective_on'], $data['reason'] ?? null);

        return back()->with('status', 'Employment status updated and recorded in staff history.');
    }

    /** @return Builder<HrStaffRecord> */
    private function scopedRecords(TenantMembership $membership): Builder
    {
        return HrStaffRecord::query()->when($membership->campus_id !== null, fn (Builder $query) => $query->where('campus_id', $membership->campus_id));
    }

    /** @return array<string, mixed> */
    private function summary(HrStaffRecord $record, bool $sensitive): array
    {
        return [
            'id' => (int) $record->getKey(),
            'employee_number' => $record->employee_number,
            'name' => $record->membership?->user?->name,
            'email' => $sensitive ? $record->membership?->user?->email : null,
            'designation' => $record->designation,
            'employment_type' => $record->employment_type,
            'status' => $record->status,
            'joined_on' => $record->joined_on?->toDateString(),
            'campus_id' => $record->campus_id,
            'campus' => $record->campus?->name,
            'academic_unit_id' => $record->academic_unit_id,
            'department' => $record->academicUnit?->name,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function campusOptions(TenantMembership $membership): array
    {
        $campuses = Campus::query()->where('status', 'active')->when($membership->campus_id !== null, fn (Builder $query) => $query->whereKey($membership->campus_id))
            ->orderBy('name')->get(['id', 'name']);

        return $campuses->map(static fn (Campus $campus): array => ['id' => (int) $campus->getKey(), 'name' => $campus->name])->all();
    }

    /** @return list<array{id: int, name: string}> */
    private function unitOptions(TenantMembership $membership): array
    {
        return AcademicUnit::query()->where('institution_id', $membership->institution_id)->where('status', 'active')
            ->when($membership->campus_id !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereDoesntHave('campuses')->orWhereHas('campuses', fn (Builder $campuses) => $campuses->whereKey($membership->campus_id))))
            ->orderBy('name')->get(['id', 'name'])->map(static fn (AcademicUnit $unit): array => ['id' => (int) $unit->getKey(), 'name' => $unit->name])->all();
    }

    /** @return list<array{id: int, name: string}> */
    private function managerOptions(TenantMembership $membership, ?int $except = null): array
    {
        return $this->scopedRecords($membership)->where('status', 'active')->when($except !== null, fn (Builder $query) => $query->where('id', '!=', $except))
            ->with('membership.user:id,name')->orderBy('employee_number')->get()->map(static fn (HrStaffRecord $staff): array => [
                'id' => (int) $staff->getKey(),
                'name' => trim(($staff->membership?->user?->name ?? 'Staff member').' · '.$staff->employee_number),
            ])->all();
    }

    /** @return array<string, array<int, string>> */
    private function sensitiveRules(bool $allowed, bool $requiredKeys = true): array
    {
        if (! $allowed) {
            return [];
        }

        $presence = $requiredKeys ? 'sometimes' : 'nullable';

        return [
            'personal_email' => [$presence, 'nullable', 'email', 'max:254'],
            'personal_phone' => [$presence, 'nullable', 'string', 'max:40'],
            'emergency_contact_name' => [$presence, 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => [$presence, 'nullable', 'string', 'max:40'],
        ];
    }

    private function assertCampus(TenantMembership $actor, ?HrStaffRecord $record): void
    {
        if ($record !== null && $actor->campus_id !== null && (int) $actor->campus_id !== (int) $record->campus_id) {
            abort(404);
        }
    }

    private function assertUnitScope(?int $unitId, ?int $campusId, int $institutionId): void
    {
        if ($unitId === null || $campusId === null) {
            return;
        }
        $unit = AcademicUnit::query()->where('institution_id', $institutionId)->whereKey($unitId)->firstOrFail();
        abort_unless($unit->campuses()->whereKey($campusId)->exists() || ! $unit->campuses()->exists(), 422);
    }
}
