<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicCreditPolicy;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AcademicSubject;
use App\Models\AcademicUnit;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicCatalogController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();

        return Inertia::render('workspace/academics/catalog', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'subjects' => AcademicSubject::query()->with(['level:id,name', 'academicUnit:id,name'])->orderBy('name')->paginate(20, ['*'], 'subjects_page'),
            'courses' => AcademicCourse::query()->with(['subject:id,name', 'level:id,name', 'academicUnit:id,name', 'versions' => fn ($query) => $query->orderByDesc('version_number')])->orderBy('title')->paginate(20, ['*'], 'courses_page'),
            'levels' => AcademicLevel::query()->where('status', 'active')->orderBy('sequence')->orderBy('name')->get(['id', 'name', 'code']),
            'units' => AcademicUnit::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'programs' => AcademicProgram::query()->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'creditPolicies' => AcademicCreditPolicy::query()->when(! AcademicDefaults::present(AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail())['enabled_features']['credits'], fn ($query) => $query->whereRaw('1 = 0'))->whereIn('status', ['active', 'draft'])->orderBy('name')->get(['id', 'name', 'status', 'academic_program_id', 'allowed_increment', 'maximum_credits', 'separate_theory_lab']),
            'configuration' => AcademicConfiguration::query()->where('institution_id', $institution->getKey())->first(['id', 'enabled_features']),
            'outcomes' => AcademicLearningOutcome::query()->where('status', 'active')->orderBy('code')->limit(100)->get(['id', 'code', 'name', 'competency_category']),
        ]);
    }

    public function storeSubject(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_subjects', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'name' => ['required', 'string', 'max:160'], 'category' => ['required', 'string', 'max:48'], 'description' => ['nullable', 'string', 'max:3000'],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
        ]);
        $subject = AcademicSubject::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $audit->record('academic.subject.created', $request->user(), (int) $context->tenant()->getKey(), $subject, ['code' => $subject->code]);

        return $this->catalogRedirect($context, "Subject {$subject->name} created.");
    }

    public function retireSubject(Request $request, Tenant $tenant, int $subjectId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $subject = DB::transaction(function () use ($request, $context, $audit, $subjectId): AcademicSubject {
            $subject = AcademicSubject::query()->lockForUpdate()->findOrFail($subjectId);
            abort_unless($subject->status === 'active', 409, 'Only active subjects can be retired.');
            $subject->forceFill(['status' => 'retired'])->save();
            $audit->record('academic.subject.retired', $request->user(), (int) $context->tenant()->getKey(), $subject, ['code' => $subject->code]);

            return $subject;
        });

        return $this->catalogRedirect($context, "Subject {$subject->name} retired.");
    }

    public function storeCourse(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_courses', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_subject_id' => ['nullable', 'integer', Rule::exists('academic_subjects', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
        ]);
        $course = AcademicCourse::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $audit->record('academic.course.created', $request->user(), (int) $context->tenant()->getKey(), $course, ['code' => $course->code]);

        return $this->catalogRedirect($context, "Course definition {$course->title} created.");
    }

    public function retireCourse(Request $request, Tenant $tenant, int $courseId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $course = DB::transaction(function () use ($request, $context, $audit, $courseId): AcademicCourse {
            $course = AcademicCourse::query()->lockForUpdate()->findOrFail($courseId);
            abort_unless($course->status === 'active', 409, 'Only active course definitions can be retired.');
            $course->forceFill(['status' => 'retired'])->save();
            $audit->record('academic.course.retired', $request->user(), (int) $context->tenant()->getKey(), $course, ['code' => $course->code]);

            return $course;
        });

        return $this->catalogRedirect($context, "Course {$course->title} retired.");
    }

    public function storeCreditPolicy(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'policy_key' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => ['required', 'string', 'max:140'],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'credit_hours_enabled' => ['required', 'boolean'], 'separate_theory_lab' => ['required', 'boolean'],
            'allowed_increment' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'], 'maximum_credits' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'theory_contact_hours_per_credit' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'], 'lab_contact_hours_per_credit' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'definition' => ['nullable', 'string', 'max:500'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $policy = DB::transaction(function () use ($data, $institution, $request, $context, $audit): AcademicCreditPolicy {
            Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            $version = (int) AcademicCreditPolicy::query()->where('institution_id', $institution->getKey())->where('policy_key', $data['policy_key'])->max('version') + 1;
            $policy = AcademicCreditPolicy::query()->create([...$data, 'institution_id' => $institution->getKey(), 'version' => $version, 'status' => 'draft']);
            $audit->record('academic.credit_policy.created', $request->user(), (int) $context->tenant()->getKey(), $policy, ['policy_key' => $policy->policy_key, 'version' => $version]);

            return $policy;
        });

        return $this->catalogRedirect($context, "Credit policy version {$policy->version} created as a draft.");
    }

    public function activateCreditPolicy(Request $request, Tenant $tenant, int $policyId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $policy = AcademicCreditPolicy::query()->findOrFail($policyId);
        abort_unless((int) $policy->tenant_id === (int) $context->tenant()->getKey(), 404);
        DB::transaction(function () use ($policy, $request, $context, $audit): void {
            $policy = AcademicCreditPolicy::query()->lockForUpdate()->findOrFail($policy->getKey());
            if ($policy->status !== 'draft') {
                throw ValidationException::withMessages(['policy' => 'Only draft credit policies can be activated. Create a new version to change an active policy.']);
            }
            if (! $policy->credit_hours_enabled && ($policy->allowed_increment !== null || $policy->maximum_credits !== null || $policy->separate_theory_lab)) {
                throw ValidationException::withMessages(['policy' => 'A policy with credit hours disabled cannot define credit limits or separate theory/laboratory credits.']);
            }
            $policy->forceFill(['status' => 'active', 'approved_by' => $request->user()->getKey(), 'approved_at' => now()])->save();
            $audit->record('academic.credit_policy.activated', $request->user(), (int) $context->tenant()->getKey(), $policy, ['policy_key' => $policy->policy_key, 'version' => $policy->version]);
        });

        return $this->catalogRedirect($context, 'Credit policy activated. Create a new version for future policy changes.');
    }

    public function storeCourseVersion(Request $request, Tenant $tenant, int $courseId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $course = AcademicCourse::query()->findOrFail($courseId);
        abort_unless($course->status === 'active', 409, 'Retired course definitions cannot receive new versions.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'credit_bearing' => ['required', 'boolean'],
            'theory_credits' => ['required', 'numeric', 'gte:0', 'decimal:0,3'], 'laboratory_credits' => ['required', 'numeric', 'gte:0', 'decimal:0,3'],
            'credit_policy_id' => ['nullable', 'integer', Rule::exists('academic_credit_policies', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $course->institution_id)->where('status', 'active'))],
            'contact_hours' => ['nullable', 'numeric', 'gte:0', 'decimal:0,3'], 'duration_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'], 'duration_unit' => ['nullable', Rule::in(['day', 'week', 'month', 'year', 'term'])],
            'delivery_modes' => ['required', 'array', 'min:1'], 'delivery_modes.*' => ['required', Rule::in(['on_campus', 'online', 'hybrid', 'workplace', 'self_paced'])],
            'description' => ['nullable', 'string', 'max:5000'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $configuration = AcademicConfiguration::query()->where('institution_id', $course->institution_id)->firstOrFail();
        if ($data['credit_bearing'] && ! (AcademicDefaults::present($configuration)['enabled_features']['credits'] ?? false)) {
            throw ValidationException::withMessages(['credit_bearing' => 'Credit-bearing course versions are unavailable until academic credits are enabled in institution settings.']);
        }
        $theory = self::milliUnits((string) $data['theory_credits']);
        $laboratory = self::milliUnits((string) $data['laboratory_credits']);
        $total = $data['credit_bearing'] ? $theory + $laboratory : 0;
        if (! $data['credit_bearing'] && ($theory !== 0 || $laboratory !== 0 || isset($data['credit_policy_id']))) {
            throw ValidationException::withMessages(['credit_bearing' => 'Non-credit-bearing course versions must have zero credits and no credit policy.']);
        }
        if ($data['credit_bearing'] && ! isset($data['credit_policy_id'])) {
            throw ValidationException::withMessages(['credit_policy_id' => 'Select an active policy for a credit-bearing course version.']);
        }
        if ($data['credit_bearing']) {
            $policy = AcademicCreditPolicy::query()->findOrFail($data['credit_policy_id']);
            if ($policy->maximum_credits !== null && $total > self::milliUnits((string) $policy->maximum_credits)) {
                throw ValidationException::withMessages(['theory_credits' => 'Total credits exceed the selected policy maximum.']);
            }
            if ($policy->allowed_increment !== null) {
                $step = self::milliUnits((string) $policy->allowed_increment);
                if (($step > 0 && $theory % $step !== 0) || ($step > 0 && $laboratory % $step !== 0)) {
                    throw ValidationException::withMessages(['theory_credits' => 'Theory and laboratory credits must follow the selected policy increment.']);
                }
            }
            if (! $policy->separate_theory_lab && $laboratory > 0) {
                throw ValidationException::withMessages(['laboratory_credits' => 'The selected policy does not permit separate laboratory credits.']);
            }
        }
        $version = DB::transaction(function () use ($course, $data, $total, $request, $context, $audit): AcademicCourseVersion {
            $lockedCourse = AcademicCourse::query()->lockForUpdate()->findOrFail($course->getKey());
            abort_unless($lockedCourse->status === 'active', 409, 'Retired course definitions cannot receive new versions.');
            $number = (int) AcademicCourseVersion::query()->where('academic_course_id', $course->getKey())->lockForUpdate()->max('version_number') + 1;
            $version = AcademicCourseVersion::query()->create([
                ...$data, 'academic_course_id' => $course->getKey(), 'version_number' => $number,
                'theory_credits' => number_format(self::fromMilliUnits(self::milliUnits((string) $data['theory_credits'])), 3, '.', ''),
                'laboratory_credits' => number_format(self::fromMilliUnits(self::milliUnits((string) $data['laboratory_credits'])), 3, '.', ''),
                'total_credits' => number_format(self::fromMilliUnits($total), 3, '.', ''), 'status' => 'draft', 'is_current' => false, 'created_by' => $request->user()->getKey(),
            ]);
            $audit->record('academic.course_version.created', $request->user(), (int) $context->tenant()->getKey(), $version, ['course_id' => $course->getKey(), 'version' => $number, 'credit_bearing' => $version->credit_bearing]);

            return $version;
        });

        return $this->catalogRedirect($context, "Course version {$version->version_number} created as a draft.");
    }

    public function publishCourseVersion(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicCourseVersion::query()->findOrFail($versionId);
        DB::transaction(function () use ($version, $request, $context, $audit): void {
            $course = AcademicCourse::query()->lockForUpdate()->findOrFail($version->academic_course_id);
            abort_unless($course->status === 'active', 409, 'Versions of retired course definitions cannot be published.');
            $locked = AcademicCourseVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['version' => 'Only draft course versions can be published. Create a new version for changes.']);
            }
            if ($locked->credit_bearing && ! (AcademicDefaults::present(AcademicConfiguration::query()->where('institution_id', $course->institution_id)->firstOrFail())['enabled_features']['credits'] ?? false)) {
                throw ValidationException::withMessages(['credit_bearing' => 'Credit-bearing course versions cannot be published while academic credits are disabled.']);
            }
            if ($locked->credit_bearing && (! $locked->credit_policy_id || ! AcademicCreditPolicy::query()->whereKey($locked->credit_policy_id)->where('status', 'active')->exists())) {
                throw ValidationException::withMessages(['credit_policy_id' => 'A credit-bearing version requires an active credit policy.']);
            }
            AcademicCourseVersion::query()->where('academic_course_id', $locked->academic_course_id)->where('is_current', true)->update(['is_current' => false]);
            $locked->forceFill(['status' => 'published', 'is_current' => true])->save();
            $audit->record('academic.course_version.published', $request->user(), (int) $context->tenant()->getKey(), $locked, ['course_id' => $locked->academic_course_id, 'version' => $locked->version_number]);
        });

        return $this->catalogRedirect($context, 'Course version published and set current.');
    }

    public function storeOutcome(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:5000'],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'competency_category' => ['nullable', 'string', 'max:80'], 'framework_reference' => ['nullable', 'string', 'max:180'], 'framework_version' => ['nullable', 'string', 'max:80'], 'source_organization' => ['nullable', 'string', 'max:160'],
        ]);
        $outcome = DB::transaction(function () use ($data, $institution, $request, $context, $audit): AcademicLearningOutcome {
            Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            $number = (int) AcademicLearningOutcome::query()->where('institution_id', $institution->getKey())->where('code', $data['code'])->max('version_number') + 1;
            $outcome = AcademicLearningOutcome::query()->create([...$data, 'institution_id' => $institution->getKey(), 'version_number' => $number, 'public_id' => (string) Str::uuid(), 'status' => 'active']);
            $audit->record('academic.outcome.created', $request->user(), (int) $context->tenant()->getKey(), $outcome, ['code' => $outcome->code, 'version' => $number]);

            return $outcome;
        });

        return $this->catalogRedirect($context, "Learning outcome version {$outcome->version_number} created.");
    }

    public function attachOutcome(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicCourseVersion::query()->findOrFail($versionId);
        $data = $request->validate(['learning_outcome_id' => ['required', 'integer', Rule::exists('academic_learning_outcomes', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))]]);
        DB::table('academic_course_version_outcomes')->updateOrInsert(['tenant_id' => $context->tenant()->getKey(), 'academic_course_version_id' => $version->getKey(), 'learning_outcome_id' => $data['learning_outcome_id']], ['created_at' => now(), 'updated_at' => now()]);
        $audit->record('academic.course_outcome.linked', $request->user(), (int) $context->tenant()->getKey(), $version, ['outcome_id' => $data['learning_outcome_id']]);

        return $this->catalogRedirect($context, 'Learning outcome linked to course version.');
    }

    private function catalogRedirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.academics.catalog', ['tenant' => $context->tenant()])->with('status', $message);
    }

    private static function milliUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private static function fromMilliUnits(int $value): float
    {
        return $value / 1000;
    }
}
