<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Academic\AcademicDefaults;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicClass;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCreditPolicy;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AcademicQualificationType;
use App\Models\AcademicSection;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicStructureController extends Controller
{
    public function index(TenantContext $context, AcademicAccess $access): Response
    {
        $institution = Institution::query()->firstOrFail();
        $features = AcademicDefaults::present(AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail())['enabled_features'];

        return Inertia::render('workspace/academics/structure', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'units' => $access->scopeUnits(AcademicUnit::query())->when(! $features['organization'], fn ($query) => $query->whereRaw('1 = 0'))->with(['parentUnit:id,name,code', 'campuses:id,name'])->withCount('childUnits')->orderBy('unit_type')->orderBy('name')->paginate(20, ['*'], 'units_page'),
            'levels' => AcademicLevel::query()->when(! $features['organization'], fn ($query) => $query->whereRaw('1 = 0'))->with('parentLevel:id,name,code')->orderBy('sequence')->orderBy('name')->paginate(20, ['*'], 'levels_page'),
            'qualifications' => AcademicQualificationType::query()->when(! $features['organization'], fn ($query) => $query->whereRaw('1 = 0'))->orderBy('name')->paginate(20, ['*'], 'qualifications_page'),
            'programs' => $access->scopePrograms(AcademicProgram::query())->when(! $features['programs'], fn ($query) => $query->whereRaw('1 = 0'))->with(['level:id,name', 'academicUnit:id,name', 'qualificationType:id,name', 'campuses:id,name', 'creditPolicy:id,name,status'])->orderBy('title')->paginate(20, ['*'], 'programs_page'),
            'classes' => $access->scopeClasses(AcademicClass::query())->when(! $features['classes'], fn ($query) => $query->whereRaw('1 = 0'))->with(['level:id,name', 'academicYear:id,name', 'campus:id,name', 'program:id,title', 'sections'])->withCount('sections')->orderBy('name')->paginate(20, ['*'], 'classes_page'),
            'campuses' => Campus::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($access->isCampusScoped(), fn ($query) => $query->whereKey($access->campusId()))->orderBy('name')->get(['id', 'name', 'code']),
            'campusScope' => $access->campusId(),
            'members' => TenantMembership::query()->with('user:id,name,email')->where('status', 'active')->when($access->isCampusScoped(), fn ($query) => $query->where('campus_id', $access->campusId()))->orderBy('id')->get()->map(fn (TenantMembership $membership) => ['id' => $membership->user_id, 'name' => $membership->user->name, 'email' => $membership->user->email]),
            'years' => $access->scopeYears(AcademicYear::query())->when(! $features['calendar'], fn ($query) => $query->whereRaw('1 = 0'))->where('institution_id', $institution->getKey())->whereIn('status', ['active', 'draft'])->orderByDesc('starts_on')->get(['id', 'name', 'status']),
            'creditPolicies' => AcademicCreditPolicy::query()->when(! $features['credits'], fn ($query) => $query->whereRaw('1 = 0'))->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'maximum_credits']),
        ]);
    }

    public function storeUnit(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->unitRules((int) $institution->getKey(), (int) $context->tenant()->getKey()));
        $campusIds = $data['campus_ids'] ?? [];
        $access->assertCampusAssignments($campusIds);
        if ($access->isCampusScoped() && isset($data['parent_unit_id'])) {
            $access->assertUnit(AcademicUnit::query()->findOrFail($data['parent_unit_id']));
        }
        unset($data['campus_ids']);
        $unit = DB::transaction(function () use ($data, $campusIds, $request, $context, $institution, $audit): AcademicUnit {
            $unit = AcademicUnit::query()->create([...$data, 'institution_id' => $institution->getKey()]);
            $unit->campuses()->syncWithPivotValues($campusIds, ['tenant_id' => $context->tenant()->getKey()]);
            $audit->record('academic.unit.created', $request->user(), (int) $context->tenant()->getKey(), $unit, ['code' => $unit->code, 'campus_count' => count($campusIds)]);

            return $unit;
        });

        return $this->structureRedirect($context, "Academic unit {$unit->name} created.");
    }

    public function updateUnit(Request $request, Tenant $tenant, int $unitId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $unit = AcademicUnit::query()->findOrFail($unitId);
        $access->assertWritableUnit($unit);
        $data = $request->validate($this->unitRules((int) $unit->institution_id, (int) $context->tenant()->getKey(), $unit));
        $campusIds = $data['campus_ids'] ?? [];
        $access->assertCampusAssignments($campusIds);
        if ($access->isCampusScoped() && isset($data['parent_unit_id'])) {
            $access->assertUnit(AcademicUnit::query()->findOrFail($data['parent_unit_id']));
        }
        unset($data['campus_ids']);
        DB::transaction(function () use ($unit, $data, $campusIds, $request, $context, $audit): void {
            Institution::query()->lockForUpdate()->findOrFail($unit->institution_id);
            $this->assertNoUnitCycle($unit, $data['parent_unit_id'] ?? null);
            $unit->fill($data)->save();
            $unit->campuses()->syncWithPivotValues($campusIds, ['tenant_id' => $context->tenant()->getKey()]);
            $audit->record('academic.unit.updated', $request->user(), (int) $context->tenant()->getKey(), $unit, ['fields' => array_keys($data), 'campus_count' => count($campusIds)]);
        });

        return $this->structureRedirect($context, 'Academic unit updated.');
    }

    public function storeLevel(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $access->assertInstitutionWide();
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->levelRules((int) $institution->getKey(), (int) $context->tenant()->getKey()));
        $level = AcademicLevel::query()->create([...$data, 'institution_id' => $institution->getKey()]);
        $audit->record('academic.level.created', $request->user(), (int) $context->tenant()->getKey(), $level, ['code' => $level->code]);

        return $this->structureRedirect($context, "Academic level {$level->name} created.");
    }

    public function updateLevel(Request $request, Tenant $tenant, int $levelId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $access->assertInstitutionWide();
        $level = AcademicLevel::query()->findOrFail($levelId);
        $data = $request->validate($this->levelRules((int) $level->institution_id, (int) $context->tenant()->getKey(), $level));
        DB::transaction(function () use ($level, $data, $request, $context, $audit): void {
            Institution::query()->lockForUpdate()->findOrFail($level->institution_id);
            $this->assertNoLevelCycle($level, $data['parent_level_id'] ?? null);
            $level->fill($data)->save();
            $audit->record('academic.level.updated', $request->user(), (int) $context->tenant()->getKey(), $level, ['fields' => array_keys($data)]);
        });

        return $this->structureRedirect($context, 'Academic level updated.');
    }

    public function storeQualification(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $access->assertInstitutionWide();
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'], 'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_qualification_types', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'award_type' => ['nullable', 'string', 'max:60'], 'framework_reference' => ['nullable', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $qualification = AcademicQualificationType::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $audit->record('academic.qualification.created', $request->user(), (int) $context->tenant()->getKey(), $qualification, ['code' => $qualification->code]);

        return $this->structureRedirect($context, "Qualification type {$qualification->name} created.");
    }

    public function storeProgram(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->programRules((int) $institution->getKey(), (int) $context->tenant()->getKey()));
        $this->assertCreditBearingProgram($data, (int) $institution->getKey());
        $campusIds = $data['campus_ids'] ?? [];
        $access->assertCampusAssignments($campusIds);
        if ($access->isCampusScoped() && ! empty($data['academic_unit_id'])) {
            $access->assertUnit(AcademicUnit::query()->findOrFail($data['academic_unit_id']));
        }
        unset($data['campus_ids']);
        $program = DB::transaction(function () use ($data, $campusIds, $institution, $context, $request, $audit): AcademicProgram {
            $program = AcademicProgram::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'draft', 'created_by' => $request->user()->getKey()]);
            $program->campuses()->syncWithPivotValues($campusIds, ['tenant_id' => $context->tenant()->getKey()]);
            $audit->record('academic.program.created', $request->user(), (int) $context->tenant()->getKey(), $program, ['code' => $program->code, 'campus_count' => count($campusIds)]);

            return $program;
        });

        return $this->structureRedirect($context, "Program {$program->title} created as a draft.");
    }

    public function updateProgram(Request $request, Tenant $tenant, int $programId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $program = AcademicProgram::query()->findOrFail($programId);
        $access->assertWritableProgram($program);
        abort_unless($program->status === 'draft', 409, 'Only draft programs can be edited. Create a new program version to change an active definition.');
        $data = $request->validate($this->programRules((int) $program->institution_id, (int) $context->tenant()->getKey(), $program));
        $this->assertCreditBearingProgram($data, (int) $program->institution_id);
        $campusIds = $data['campus_ids'] ?? [];
        $access->assertCampusAssignments($campusIds);
        if ($access->isCampusScoped() && ! empty($data['academic_unit_id'])) {
            $access->assertUnit(AcademicUnit::query()->findOrFail($data['academic_unit_id']));
        }
        unset($data['campus_ids']);
        DB::transaction(function () use ($program, $data, $campusIds, $context, $request, $audit): void {
            $program->fill([...$data, 'updated_by' => $request->user()->getKey()])->save();
            $program->campuses()->syncWithPivotValues($campusIds, ['tenant_id' => $context->tenant()->getKey()]);
            $audit->record('academic.program.updated', $request->user(), (int) $context->tenant()->getKey(), $program, ['fields' => array_keys($data), 'campus_count' => count($campusIds)]);
        });

        return $this->structureRedirect($context, 'Program updated.');
    }

    public function storeClass(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->classRules((int) $institution->getKey(), (int) $context->tenant()->getKey()));
        $access->assertCampus(isset($data['campus_id']) ? (int) $data['campus_id'] : null);
        if ($access->isCampusScoped() && ! empty($data['academic_year_id'])) {
            $access->assertYear(AcademicYear::query()->findOrFail($data['academic_year_id']));
        }
        if ($access->isCampusScoped() && ! empty($data['academic_program_id'])) {
            $access->assertProgram(AcademicProgram::query()->findOrFail($data['academic_program_id']));
        }
        $class = AcademicClass::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $audit->record('academic.class.created', $request->user(), (int) $context->tenant()->getKey(), $class, ['code' => $class->code, 'group_type' => $class->group_type]);

        return $this->structureRedirect($context, "Academic group {$class->name} created.");
    }

    public function updateClass(Request $request, Tenant $tenant, int $classId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $class = AcademicClass::query()->findOrFail($classId);
        $access->assertClass($class);
        abort_unless($class->status === 'active', 409);
        $data = $request->validate($this->classRules((int) $class->institution_id, (int) $context->tenant()->getKey(), $class));
        $access->assertCampus(isset($data['campus_id']) ? (int) $data['campus_id'] : null);
        if ($access->isCampusScoped() && ! empty($data['academic_year_id'])) {
            $access->assertYear(AcademicYear::query()->findOrFail($data['academic_year_id']));
        }
        if ($access->isCampusScoped() && ! empty($data['academic_program_id'])) {
            $access->assertProgram(AcademicProgram::query()->findOrFail($data['academic_program_id']));
        }
        $class->fill($data)->save();
        $audit->record('academic.class.updated', $request->user(), (int) $context->tenant()->getKey(), $class, ['fields' => array_keys($data)]);

        return $this->structureRedirect($context, 'Academic group updated.');
    }

    public function activateProgram(Request $request, Tenant $tenant, int $programId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $program = AcademicProgram::query()->findOrFail($programId);
        $access->assertWritableProgram($program);
        if ($program->status !== 'draft') {
            throw ValidationException::withMessages(['program' => 'Only draft programs can be activated.']);
        }
        $data = $program->getAttributes();
        $this->assertCreditBearingProgram($data, (int) $program->institution_id);
        $program->forceFill(['status' => 'active', 'updated_by' => $request->user()->getKey()])->save();
        $audit->record('academic.program.activated', $request->user(), (int) $context->tenant()->getKey(), $program, ['code' => $program->code]);

        return $this->structureRedirect($context, "Program {$program->title} activated.");
    }

    public function storeSection(Request $request, Tenant $tenant, int $classId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $class = AcademicClass::query()->findOrFail($classId);
        $access->assertClass($class);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_sections', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('academic_class_id', $class->getKey()))],
            'name' => ['required', 'string', 'max:140'], 'structural_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $section = AcademicSection::query()->create([...$data, 'academic_class_id' => $class->getKey(), 'status' => 'active']);
        $audit->record('academic.section.created', $request->user(), (int) $context->tenant()->getKey(), $section, ['class_id' => $class->getKey(), 'code' => $section->code]);

        return $this->structureRedirect($context, "Section {$section->name} created.");
    }

    /** @return array<string, mixed> */
    private function unitRules(int $institutionId, int $tenantId, ?AcademicUnit $unit = null): array
    {
        return [
            'unit_type' => ['required', Rule::in(['faculty', 'department', 'school', 'division', 'center', 'program_area', 'custom'])],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_units', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))->ignore($unit?->getKey())],
            'parent_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'description' => ['nullable', 'string', 'max:2000'], 'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'manager_user_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'))],
            'campus_ids' => ['sometimes', 'array'], 'campus_ids.*' => ['integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
        ];
    }

    /** @return array<string, mixed> */
    private function levelRules(int $institutionId, int $tenantId, ?AcademicLevel $level = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_levels', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))->ignore($level?->getKey())],
            'category' => ['required', 'string', 'max:40'], 'sequence' => ['required', 'integer', 'min:0', 'max:65535'], 'description' => ['nullable', 'string', 'max:2000'],
            'parent_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))], 'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, mixed> */
    private function programRules(int $institutionId, int $tenantId, ?AcademicProgram $program = null): array
    {
        return [
            'title' => ['required', 'string', 'max:180'], 'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_programs', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))->ignore($program?->getKey())],
            'academic_level_id' => ['required', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'qualification_type_id' => ['nullable', 'integer', Rule::exists('academic_qualification_types', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'credit_policy_id' => ['nullable', 'integer', Rule::exists('academic_credit_policies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'duration_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999.99'], 'duration_unit' => ['nullable', Rule::in(['day', 'week', 'month', 'year', 'term'])],
            'period_model' => ['nullable', 'string', 'max:32'], 'credit_bearing' => ['required', 'boolean'], 'required_credits' => ['nullable', 'numeric', 'gte:0', 'max:999999.999'],
            'delivery_mode' => ['required', Rule::in(['on_campus', 'online', 'hybrid', 'workplace', 'self_paced', 'custom'])], 'award_type' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'], 'entry_requirements_reference' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'campus_ids' => ['sometimes', 'array'], 'campus_ids.*' => ['integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
        ];
    }

    /** @return array<string, mixed> */
    private function classRules(int $institutionId, int $tenantId, ?AcademicClass $class = null): array
    {
        return [
            'group_type' => ['required', Rule::in(['grade', 'class', 'section_group', 'cohort', 'batch', 'training_cycle', 'custom'])], 'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_classes', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->where('group_type', request()->input('group_type')))->ignore($class?->getKey())], 'name' => ['required', 'string', 'max:140'],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'structural_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'capacity_policy' => ['required', Rule::in(['advisory', 'enforced'])], 'schedule_label' => ['nullable', 'string', 'max:40'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertCreditBearingProgram(array $data, int $institutionId): void
    {
        if (! ($data['credit_bearing'] ?? false)) {
            if (! empty($data['credit_policy_id']) || ! empty($data['required_credits'])) {
                throw ValidationException::withMessages(['credit_bearing' => 'Non-credit-bearing programs cannot define a required credit total or credit policy.']);
            }

            return;
        }
        $configuration = AcademicConfiguration::query()->where('institution_id', $institutionId)->firstOrFail();
        if (! (AcademicDefaults::present($configuration)['enabled_features']['credits'] ?? false)) {
            throw ValidationException::withMessages(['credit_bearing' => 'Credit-bearing programs are unavailable until academic credits are enabled in institution settings.']);
        }
        if (empty($data['credit_policy_id']) || empty($data['required_credits'])) {
            throw ValidationException::withMessages(['required_credits' => 'Credit-bearing programs need a required credit total and an active credit policy.']);
        }
        $policy = AcademicCreditPolicy::query()->whereKey($data['credit_policy_id'])->where('status', 'active')->firstOrFail();
        if ($policy->maximum_credits !== null && (float) $data['required_credits'] > (float) $policy->maximum_credits) {
            throw ValidationException::withMessages(['required_credits' => 'The required credits exceed the selected credit policy maximum.']);
        }
    }

    private function assertNoUnitCycle(AcademicUnit $unit, mixed $parentId): void
    {
        $visited = [];
        while ($parentId !== null) {
            $parentId = (int) $parentId;
            if ($parentId === (int) $unit->getKey() || isset($visited[$parentId])) {
                throw ValidationException::withMessages(['parent_unit_id' => 'Academic unit hierarchy cannot contain a cycle.']);
            }
            $visited[$parentId] = true;
            $parentId = AcademicUnit::query()->whereKey($parentId)->value('parent_unit_id');
        }
    }

    private function assertNoLevelCycle(AcademicLevel $level, mixed $parentId): void
    {
        $visited = [];
        while ($parentId !== null) {
            $parentId = (int) $parentId;
            if ($parentId === (int) $level->getKey() || isset($visited[$parentId])) {
                throw ValidationException::withMessages(['parent_level_id' => 'Academic level hierarchy cannot contain a cycle.']);
            }
            $visited[$parentId] = true;
            $parentId = AcademicLevel::query()->whereKey($parentId)->value('parent_level_id');
        }
    }

    private function structureRedirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.academics.structure', ['tenant' => $context->tenant()])->with('status', $message);
    }
}
