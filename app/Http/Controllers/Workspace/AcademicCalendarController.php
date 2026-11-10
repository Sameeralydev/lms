<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Academic\AcademicCalendar;
use App\Domain\Academic\AcademicDefaults;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicCalendarController extends Controller
{
    public function index(Request $request, TenantContext $context, AcademicAccess $access): Response
    {
        $institution = Institution::query()->firstOrFail();
        $configuration = AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail();
        $calendarEnabled = AcademicDefaults::present($configuration)['enabled_features']['calendar'];
        $years = $access->scopeYears(AcademicYear::query())
            ->when(! $calendarEnabled, fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['campus:id,name', 'periods' => fn ($query) => $query->orderBy('sequence')])
            ->withCount('periods')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search->where('name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('starts_on')->paginate(15)->withQueryString();

        return Inertia::render('workspace/academics/calendar', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'configuration' => AcademicDefaults::present($configuration),
            'years' => $years,
            'campuses' => Campus::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($access->isCampusScoped(), fn ($query) => $query->whereKey($access->campusId()))->orderBy('name')->get(['id', 'name', 'code']),
            'campusScope' => $access->campusId(),
            'periodTypes' => ['semester', 'term', 'trimester', 'quarter', 'module', 'annual', 'custom'],
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function updateConfiguration(Request $request, Tenant $tenant, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $access->assertInstitutionWide();
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'operating_model' => ['required', Rule::in(['k12', 'higher_education', 'academy', 'corporate_learning', 'custom'])],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'terminology' => ['required', 'array'],
            'terminology.*' => ['required', 'string', 'max:48'],
            'enabled_features' => ['required', 'array'],
            'enabled_features.*' => ['boolean'],
        ]);
        $allowedFeatures = ['calendar', 'organization', 'programs', 'classes', 'catalog', 'credits', 'quality', 'student_information'];
        if (array_diff(array_keys($data['enabled_features']), $allowedFeatures)) {
            throw ValidationException::withMessages(['enabled_features' => 'One or more academic features are not supported.']);
        }
        $configuration = AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail();
        $configuration->fill([...$data, 'updated_by' => $request->user()->getKey()])->save();
        $audit->record('academic.configuration.updated', $request->user(), (int) $context->tenant()->getKey(), $configuration, ['operating_model' => $configuration->operating_model, 'fields' => array_keys($data)]);

        return $this->calendarRedirect($context, 'Academic configuration updated.');
    }

    public function storeYear(Request $request, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate($this->yearRules((int) $institution->getKey(), (int) $context->tenant()->getKey()));
        $access->assertCampus(isset($data['campus_id']) ? (int) $data['campus_id'] : null);
        $year = AcademicYear::query()->create([...$data, 'institution_id' => $institution->getKey(), 'timezone' => $data['timezone'] ?? $institution->timezone, 'locale' => $data['locale'] ?? $institution->locale, 'status' => 'draft', 'is_current' => false, 'publication_status' => 'draft', 'created_by' => $request->user()->getKey()]);
        $audit->record('academic.year.created', $request->user(), (int) $context->tenant()->getKey(), $year, ['code' => $year->code]);

        return $this->calendarRedirect($context, "Academic year {$year->name} created as a draft.");
    }

    public function updateYear(Request $request, Tenant $tenant, int $yearId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $year = AcademicYear::query()->findOrFail($yearId);
        $access->assertYear($year);
        abort_unless($year->status !== 'archived', 409, 'Archived academic years are immutable.');
        $data = $request->validate($this->yearRules((int) $year->institution_id, (int) $context->tenant()->getKey(), $year));
        $access->assertCampus(isset($data['campus_id']) ? (int) $data['campus_id'] : null);
        DB::transaction(function () use ($year, $data, $request, $context, $audit): void {
            Institution::query()->lockForUpdate()->findOrFail($year->institution_id);
            $locked = AcademicYear::query()->lockForUpdate()->findOrFail($year->getKey());
            abort_unless($locked->status !== 'archived', 409, 'Archived academic years are immutable.');
            $before = $locked->only(['campus_id', 'name', 'code', 'description', 'academic_model', 'starts_on', 'ends_on', 'timezone', 'locale']);
            $newStartsOn = Carbon::parse($data['starts_on']);
            $newEndsOn = Carbon::parse($data['ends_on']);
            foreach ($locked->periods as $period) {
                if (Carbon::parse((string) $period->starts_on)->lt($newStartsOn) || Carbon::parse((string) $period->ends_on)->gt($newEndsOn)) {
                    throw ValidationException::withMessages(['starts_on' => 'The updated year dates must continue to contain all configured periods.']);
                }
            }
            if ($locked->status === 'active') {
                $conflict = AcademicYear::query()->where('institution_id', $locked->institution_id)->where('status', 'active')
                    ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])
                    ->when($locked->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $locked->campus_id)))
                    ->where('id', '!=', $locked->getKey())->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['starts_on' => 'An active academic year overlaps this date range for the selected institution or campus.']);
                }
            }
            $locked->fill([...$data, 'updated_by' => $request->user()->getKey()])->save();
            $audit->record('academic.year.updated', $request->user(), (int) $context->tenant()->getKey(), $locked, ['before' => $before, 'after' => $locked->only(array_keys($before))]);
        });

        return $this->calendarRedirect($context, 'Academic year updated.');
    }

    public function transitionYear(Request $request, Tenant $tenant, int $yearId, AcademicCalendar $calendar, TenantContext $context, AcademicAccess $access): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'completed', 'archived'])]]);
        $year = AcademicYear::query()->findOrFail($yearId);
        $access->assertYear($year);
        $calendar->transitionYear($year, $data['status'], $request->user());

        return $this->calendarRedirect($context, "Academic year marked {$data['status']}.");
    }

    public function currentYear(Request $request, Tenant $tenant, int $yearId, AcademicCalendar $calendar, TenantContext $context, AcademicAccess $access): RedirectResponse
    {
        $year = AcademicYear::query()->findOrFail($yearId);
        $access->assertYear($year);
        $calendar->setCurrentYear($year, $request->user());

        return $this->calendarRedirect($context, 'Current academic year updated.');
    }

    public function duplicateYear(Request $request, Tenant $tenant, int $yearId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $source = AcademicYear::query()->with('periods')->findOrFail($yearId);
        $access->assertYear($source);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_years', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $source->institution_id))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $newYear = DB::transaction(function () use ($source, $data, $request, $context, $audit): AcademicYear {
            $start = Carbon::parse($data['starts_on']);
            $offsetDays = Carbon::parse((string) $source->starts_on)->diffInDays($start, false);
            $copy = $source->replicate(['status', 'is_current', 'publication_status', 'created_by', 'updated_by']);
            $copy->fill(['name' => $data['name'], 'code' => $data['code'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'status' => 'draft', 'is_current' => false, 'publication_status' => 'draft', 'created_by' => $request->user()->getKey(), 'updated_by' => null]);
            $copy->save();
            foreach ($source->periods as $period) {
                $periodCopy = $period->replicate(['status', 'is_current', 'publication_status', 'created_by', 'updated_by']);
                $periodCopy->academic_year_id = $copy->getKey();
                $periodCopy->starts_on = Carbon::parse((string) $period->starts_on)->addDays($offsetDays);
                $periodCopy->ends_on = Carbon::parse((string) $period->ends_on)->addDays($offsetDays);
                foreach (['teaching_starts_on', 'teaching_ends_on', 'assessment_starts_on', 'assessment_ends_on'] as $column) {
                    if ($period->{$column}) {
                        $periodCopy->{$column} = Carbon::parse((string) $period->{$column})->addDays($offsetDays);
                    }
                }
                if (Carbon::parse((string) $periodCopy->starts_on)->lt($start) || Carbon::parse((string) $periodCopy->ends_on)->gt(Carbon::parse($data['ends_on']))) {
                    throw ValidationException::withMessages(['ends_on' => 'The copied year date range must contain all shifted periods.']);
                }
                $periodCopy->fill(['status' => 'draft', 'is_current' => false, 'publication_status' => 'draft', 'created_by' => $request->user()->getKey(), 'updated_by' => null]);
                $periodCopy->save();
            }
            $audit->record('academic.year.copied', $request->user(), (int) $context->tenant()->getKey(), $copy, ['source_year_id' => $source->getKey(), 'period_count' => $source->periods->count()]);

            return $copy;
        });

        return $this->calendarRedirect($context, "Academic year copied to {$newYear->name}.");
    }

    public function storePeriod(Request $request, Tenant $tenant, int $yearId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $year = AcademicYear::query()->findOrFail($yearId);
        $access->assertYear($year);
        $data = $request->validate($this->periodRules($year, (int) $context->tenant()->getKey()));
        $period = DB::transaction(function () use ($year, $data, $request, $context, $audit): AcademicPeriod {
            Institution::query()->lockForUpdate()->findOrFail($year->institution_id);
            AcademicYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $this->assertPeriodNoOverlap($year, $data);
            $period = AcademicPeriod::query()->create([...$data, 'academic_year_id' => $year->getKey(), 'status' => 'draft', 'is_current' => false, 'publication_status' => 'draft', 'created_by' => $request->user()->getKey()]);
            $audit->record('academic.period.created', $request->user(), (int) $context->tenant()->getKey(), $period, ['academic_year_id' => $year->getKey(), 'code' => $period->code]);

            return $period;
        });

        return $this->calendarRedirect($context, "Period {$period->name} created as a draft.");
    }

    public function updatePeriod(Request $request, Tenant $tenant, int $periodId, TenantContext $context, AuditRecorder $audit, AcademicAccess $access): RedirectResponse
    {
        $period = AcademicPeriod::query()->findOrFail($periodId);
        $access->assertPeriod($period);
        abort_unless(in_array($period->status, ['draft', 'active'], true), 409, 'Completed or archived periods are immutable.');
        $year = AcademicYear::query()->findOrFail($period->academic_year_id);
        $data = $request->validate($this->periodRules($year, (int) $context->tenant()->getKey(), $period));

        DB::transaction(function () use ($period, $year, $data, $request, $context, $audit): void {
            Institution::query()->lockForUpdate()->findOrFail($year->institution_id);
            AcademicYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $locked = AcademicPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            abort_unless(in_array($locked->status, ['draft', 'active'], true), 409, 'Completed or archived periods are immutable.');
            $before = $locked->only(['name', 'code', 'period_type', 'sequence', 'starts_on', 'ends_on', 'teaching_starts_on', 'teaching_ends_on', 'assessment_starts_on', 'assessment_ends_on']);
            $this->assertPeriodNoOverlap($year, $data, $locked);
            $locked->fill([...$data, 'updated_by' => $request->user()->getKey()])->save();
            $audit->record('academic.period.updated', $request->user(), (int) $context->tenant()->getKey(), $locked, [
                'before' => $before,
                'after' => $locked->only(array_keys($before)),
            ]);
        });

        return $this->calendarRedirect($context, 'Academic period updated.');
    }

    public function transitionPeriod(Request $request, Tenant $tenant, int $periodId, AcademicCalendar $calendar, TenantContext $context, AcademicAccess $access): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'completed', 'archived'])]]);
        $period = AcademicPeriod::query()->findOrFail($periodId);
        $access->assertPeriod($period);
        $calendar->transitionPeriod($period, $data['status'], $request->user());

        return $this->calendarRedirect($context, "Academic period marked {$data['status']}.");
    }

    public function currentPeriod(Request $request, Tenant $tenant, int $periodId, AcademicCalendar $calendar, TenantContext $context, AcademicAccess $access): RedirectResponse
    {
        $period = AcademicPeriod::query()->findOrFail($periodId);
        $access->assertPeriod($period);
        $calendar->setCurrentPeriod($period, $request->user());

        return $this->calendarRedirect($context, 'Current academic period updated.');
    }

    /** @return array<string, mixed> */
    private function yearRules(int $institutionId, int $tenantId, ?AcademicYear $year = null): array
    {
        return [
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'name' => ['required', 'string', 'max:140'],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_years', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))->ignore($year?->getKey())],
            'description' => ['nullable', 'string', 'max:2000'],
            'academic_model' => ['nullable', Rule::in(['k12', 'higher_education', 'academy', 'corporate_learning', 'custom'])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertPeriodNoOverlap(AcademicYear $year, array $data, ?AcademicPeriod $except = null): void
    {
        $overlaps = AcademicPeriod::query()->where('academic_year_id', $year->getKey())->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->when($except, fn ($query) => $query->where('id', '!=', $except->getKey()))->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => 'Teaching periods within an academic year may not overlap.']);
        }
    }

    /** @return array<string, mixed> */
    private function periodRules(AcademicYear $year, int $tenantId, ?AcademicPeriod $period = null): array
    {
        $yearStart = Carbon::parse((string) $year->starts_on)->toDateString();
        $yearEnd = Carbon::parse((string) $year->ends_on)->toDateString();

        return [
            'name' => ['required', 'string', 'max:140'],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_periods', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('academic_year_id', $year->getKey()))->ignore($period?->getKey())],
            'period_type' => ['required', Rule::in(['semester', 'term', 'trimester', 'quarter', 'module', 'annual', 'custom'])],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
            'starts_on' => ['required', 'date', 'after_or_equal:'.$yearStart],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.$yearEnd],
            'teaching_starts_on' => ['nullable', 'date', 'after_or_equal:starts_on', 'before_or_equal:ends_on'],
            'teaching_ends_on' => ['nullable', 'date', 'after_or_equal:teaching_starts_on', 'before_or_equal:ends_on'],
            'assessment_starts_on' => ['nullable', 'date', 'after_or_equal:starts_on', 'before_or_equal:ends_on'],
            'assessment_ends_on' => ['nullable', 'date', 'after_or_equal:assessment_starts_on', 'before_or_equal:ends_on'],
        ];
    }

    private function calendarRedirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.academics.calendar', ['tenant' => $context->tenant()])->with('status', $message);
    }
}
