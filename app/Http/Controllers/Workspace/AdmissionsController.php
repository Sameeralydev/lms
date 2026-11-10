<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Admissions\AdmissionApplicationWorkflow;
use App\Domain\Admissions\AdmissionFormDefinition;
use App\Domain\Admissions\AdmissionsAccess;
use App\Domain\Admissions\GuardianAuthorizationWorkflow;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionAssessment;
use App\Models\AdmissionDecision;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\AdmissionDocumentReview;
use App\Models\AdmissionDocumentVersion;
use App\Models\AdmissionEligibilityEvaluation;
use App\Models\AdmissionFeeObligation;
use App\Models\AdmissionFormTemplate;
use App\Models\AdmissionFormVersion;
use App\Models\AdmissionGuardianAuthorization;
use App\Models\AdmissionInquiry;
use App\Models\AdmissionIntake;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Models\AdmissionOffer;
use App\Models\AdmissionOutboxEvent;
use App\Models\AdmissionReviewerAssignment;
use App\Models\AdmissionSetting;
use App\Models\AdmissionSisHandoff;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdmissionsController extends Controller
{
    public function index(Request $request, TenantContext $context, AdmissionsAccess $access): Response
    {
        $institution = Institution::query()->firstOrFail();
        $settings = AdmissionSetting::query()->firstOrFail();
        $intakes = $access->scopeIntakes(AdmissionIntake::query())
            ->with(['campus:id,name', 'program:id,title', 'formTemplates.versions' => fn ($query) => $query->orderByDesc('version_number')])
            ->withCount(['applications', 'applications as submitted_count' => fn (Builder $query) => $query->whereNotNull('submitted_at')])
            ->when($request->filled('intake_q'), fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->where('code', 'like', '%'.$request->string('intake_q').'%')
                ->orWhere('name', 'like', '%'.$request->string('intake_q').'%')))
            ->when($request->filled('intake_status'), fn (Builder $query) => $query->where('status', $request->string('intake_status')))
            ->orderByDesc('created_at')->paginate(15, ['*'], 'intakes_page')->withQueryString();
        $applicationStatus = $request->string('status')->toString();
        $applications = $access->scopeApplications(AdmissionApplication::query())
            ->with(['intake:id,name,code,campus_id', 'profile:id,given_name,family_name', 'applicant:id,name,email'])
            ->when($request->filled('q'), fn (Builder $query) => $query->where('application_number', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $applicationStatus))
            ->when($applicationStatus === 'waitlisted', fn (Builder $query) => $query->orderBy('submitted_at')->orderBy('id'), fn (Builder $query) => $query->orderByDesc('created_at'))
            ->paginate(15)->withQueryString();
        $waitlistFirstPosition = $applications->firstItem() ?? 1;
        $canManageInquiries = $context->membership()->hasPermission('admissions.inquiries.manage');
        $inquiriesQuery = AdmissionInquiry::query()
            ->when($request->filled('inquiry_q'), fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', '%'.$request->string('inquiry_q').'%')
                ->orWhere('name', 'like', '%'.$request->string('inquiry_q').'%')
                ->orWhere('email', 'like', '%'.$request->string('inquiry_q').'%')))
            ->when($request->filled('inquiry_status'), fn (Builder $query) => $query->where('status', $request->string('inquiry_status')))
            ->orderByDesc('created_at');
        $inquiries = $canManageInquiries
            ? $inquiriesQuery->with('intake:id,name')->paginate(15, ['*'], 'inquiries_page')->withQueryString()
            : AdmissionInquiry::query()->whereRaw('1 = 0')->paginate(15, ['*'], 'inquiries_page');
        $base = $access->scopeApplications(AdmissionApplication::query());
        $applicationCount = (clone $base)->whereNotNull('submitted_at')->count();
        $acceptedOffers = (clone $base)->where('status', 'offer_accepted')->count();
        $issuedOffers = (clone $base)->whereIn('status', ['offered', 'offer_accepted', 'offer_declined', 'offer_expired'])->count();

        return Inertia::render('workspace/admissions/index', [
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $institution->display_name],
            'settings' => $settings->only(['enabled', 'inquiries_enabled', 'public_applications_enabled', 'registration_mode', 'timezone', 'locale', 'policy']),
            'metrics' => [
                'intakes' => $access->scopeIntakes(AdmissionIntake::query())->count(),
                'openIntakes' => $access->scopeIntakes(AdmissionIntake::query())->where('status', 'open')->count(),
                'applicationsStarted' => (clone $base)->count(),
                'applicationsSubmitted' => $applicationCount,
                'underReview' => (clone $base)->whereIn('status', ['under_review', 'documents_required', 'assessment_pending', 'interview_pending'])->count(),
                'decisionsPending' => (clone $base)->where('status', 'decision_pending')->count(),
                'offersAccepted' => $acceptedOffers,
                'offersIssued' => $issuedOffers,
                'offerAcceptanceRate' => $issuedOffers > 0 ? round(($acceptedOffers / $issuedOffers) * 100, 1) : null,
                'handoffsPending' => AdmissionSisHandoff::query()->where('status', 'pending')->count(),
                'queuedCommunications' => AdmissionOutboxEvent::query()->where('status', 'queued')->count(),
                'inquiries' => $canManageInquiries ? AdmissionInquiry::query()->count() : null,
            ],
            'intakes' => $intakes->through(fn (AdmissionIntake $intake): array => [
                'id' => $intake->getKey(), 'code' => $intake->code, 'name' => $intake->name, 'status' => $intake->status,
                'campus' => $intake->campus?->name, 'program' => $intake->program?->title,
                'deadline' => $intake->application_deadline, 'capacity' => $intake->capacity,
                'cycle' => $intake->cycle, 'academicYearId' => $intake->academic_year_id,
                'campusId' => $intake->campus_id, 'programId' => $intake->academic_program_id,
                'deliveryMode' => $intake->delivery_mode, 'locale' => $intake->locale, 'timezone' => $intake->timezone,
                'applicationsOpenAt' => $intake->applications_open_at, 'documentsDeadline' => $intake->documents_deadline,
                'offerDeadline' => $intake->offer_deadline, 'reservedCapacity' => $intake->reserved_capacity,
                'applicationFeeAmount' => $intake->application_fee_amount, 'applicationFeeCurrency' => $intake->application_fee_currency,
                'publicDescription' => $intake->public_description, 'isPublic' => $intake->is_public,
                'eligibilityRules' => $intake->eligibility_rules ?? [],
                'applications' => $intake->applications_count, 'submitted' => $intake->submitted_count,
                'form' => $intake->formTemplates->first()?->versions->first()?->definition,
                'formVersionId' => $intake->formTemplates->first()?->versions->first()?->getKey(),
                'formStatus' => $intake->formTemplates->first()?->versions->first()?->status,
                'draftForm' => $intake->formTemplates->first()?->versions->first()?->status === 'draft',
                'publishedForm' => $intake->formTemplates->first()?->versions->contains('status', 'published') ?? false,
            ]),
            'applications' => $applications->through(fn (AdmissionApplication $application, int $index): array => [
                'id' => $application->getKey(), 'number' => $application->application_number, 'status' => $application->status,
                'eligibility' => $application->eligibility_status, 'createdAt' => $application->created_at,
                'submittedAt' => $application->submitted_at, 'intake' => $application->intake?->name,
                'applicant' => trim(($application->profile->given_name ?? '').' '.($application->profile->family_name ?? '')),
                'waitlistPosition' => $applicationStatus === 'waitlisted' ? $waitlistFirstPosition + $index : null,
            ]),
            'canManageInquiries' => $canManageInquiries,
            'inquiries' => $inquiries->through(fn (AdmissionInquiry $inquiry): array => [
                'id' => $inquiry->getKey(), 'reference' => $inquiry->reference, 'name' => $inquiry->name,
                'email' => $inquiry->email, 'phone' => $inquiry->phone, 'status' => $inquiry->status,
                'source' => $inquiry->source, 'message' => $inquiry->message, 'intake' => $inquiry->intake?->name,
                'createdAt' => $inquiry->created_at,
            ]),
            'campuses' => Campus::query()->where('status', 'active')->when($context->membership()->campus_id, fn ($query) => $query->whereKey($context->membership()->campus_id))->orderBy('name')->get(['id', 'name', 'code']),
            'years' => AcademicYear::query()->whereIn('status', ['active', 'draft'])->orderByDesc('starts_on')->get(['id', 'name', 'code', 'campus_id']),
            'programs' => AcademicProgram::query()->whereIn('status', ['active', 'draft'])->orderBy('title')->get(['id', 'title', 'code']),
            'reviewers' => $this->eligibleReviewers($context),
            'filters' => $request->only('q', 'status', 'intake_q', 'intake_status', 'inquiry_q', 'inquiry_status'),
            'academicFeatures' => AcademicDefaults::present(AcademicConfiguration::query()->firstOrFail())['enabled_features'],
            'timezone' => $institution->timezone,
        ]);
    }

    public function updateSettings(Request $request, Tenant $tenant, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'inquiries_enabled' => ['required', 'boolean'],
            'public_applications_enabled' => ['required', 'boolean'], 'registration_mode' => ['required', Rule::in(['existing_account', 'open_registration'])],
            'timezone' => ['required', 'timezone'], 'locale' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'guardian_age_threshold' => ['nullable', 'integer', 'min:1', 'max:21'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);
        if ((! $data['enabled']) && ($data['inquiries_enabled'] || $data['public_applications_enabled'])) {
            throw ValidationException::withMessages(['enabled' => 'Turn admissions on before enabling public applications or inquiries.']);
        }
        $settings = AdmissionSetting::query()->firstOrFail();
        $before = $settings->only(['enabled', 'inquiries_enabled', 'public_applications_enabled', 'registration_mode', 'timezone', 'locale', 'policy']);
        $policy = array_filter(['guardian_age_threshold' => $data['guardian_age_threshold'] ?? null, 'retention_months' => $data['retention_months'] ?? null], static fn (mixed $value): bool => $value !== null);
        $settingsFields = array_diff_key($data, array_flip(['guardian_age_threshold', 'retention_months']));
        $settings->fill([...$settingsFields, 'policy' => $policy, 'updated_by' => $request->user()->getKey()])->save();
        $audit->record('admissions.settings.updated', $request->user(), (int) $context->tenant()->getKey(), $settings, ['before' => $before, 'fields' => array_keys($data)]);

        return $this->redirect($context, 'Admissions settings updated.');
    }

    public function updateInquiryStatus(Request $request, Tenant $tenant, int $inquiryId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['contacted', 'qualified', 'closed'])]]);
        $inquiry = DB::transaction(function () use ($inquiryId, $data, $request, $context, $audit): AdmissionInquiry {
            $locked = AdmissionInquiry::query()->lockForUpdate()->findOrFail($inquiryId);
            $transitions = [
                'new' => ['contacted', 'qualified', 'closed'],
                'contacted' => ['qualified', 'closed'],
                'qualified' => ['closed'],
                'closed' => [],
            ];
            if (! in_array($data['status'], $transitions[$locked->status] ?? [], true)) {
                abort(409, 'This inquiry status transition is not allowed.');
            }
            $locked->forceFill([
                'status' => $data['status'],
                'qualified_by' => $data['status'] === 'qualified' ? $request->user()->getKey() : $locked->qualified_by,
                'qualified_at' => $data['status'] === 'qualified' ? now() : $locked->qualified_at,
            ])->save();
            $audit->record('admissions.inquiry.'.$data['status'], $request->user(), (int) $context->tenant()->getKey(), $locked, [
                'reference' => $locked->reference, 'status' => $locked->status,
            ]);

            return $locked;
        });

        return back()->with('status', 'Inquiry '.$inquiry->reference.' is now '.$inquiry->status.'.');
    }

    public function storeIntake(Request $request, Tenant $tenant, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('admission_intakes', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'name' => ['required', 'string', 'max:160'], 'cycle' => ['nullable', 'string', 'max:48'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey())->where('status', 'active'))],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey())->where('status', 'active'))],
            'delivery_mode' => ['required', Rule::in(['on_campus', 'online', 'hybrid', 'distance', 'custom'])],
            'locale' => ['required', 'string', 'max:16'], 'timezone' => ['required', 'timezone'],
            'applications_open_at' => ['nullable', 'date'], 'application_deadline' => ['nullable', 'date'],
            'documents_deadline' => ['nullable', 'date'], 'offer_deadline' => ['nullable', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'reserved_capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'application_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'application_fee_currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'public_description' => ['nullable', 'string', 'max:5000'], 'is_public' => ['required', 'boolean'],
            'eligibility_rules' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.required_answers' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.required_answers.*' => ['string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'eligibility_rules.minimum_values' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.minimum_values.*' => ['numeric', 'min:0', 'max:1000000000'],
        ]);
        if ($context->membership()->campus_id !== null && (int) ($data['campus_id'] ?? 0) !== (int) $context->membership()->campus_id) {
            abort(404);
        }
        if (isset($data['capacity'], $data['reserved_capacity']) && $data['reserved_capacity'] > $data['capacity']) {
            throw ValidationException::withMessages(['reserved_capacity' => 'Reserved capacity cannot exceed the intake capacity.']);
        }
        $this->assertIntakeDeadlines($data);
        $features = AcademicDefaults::present(AcademicConfiguration::query()->firstOrFail())['enabled_features'];
        if (! ($features['calendar'] ?? false) && ! empty($data['academic_year_id'])) {
            throw ValidationException::withMessages(['academic_year_id' => 'Enable the academic calendar feature before binding an academic year.']);
        }
        if (! ($features['programs'] ?? false) && ! empty($data['academic_program_id'])) {
            throw ValidationException::withMessages(['academic_program_id' => 'Enable the academic programs feature before binding a program.']);
        }
        $year = isset($data['academic_year_id']) ? AcademicYear::query()->findOrFail($data['academic_year_id']) : null;
        if ($year && $year->campus_id && (int) $year->campus_id !== (int) ($data['campus_id'] ?? 0)) {
            throw ValidationException::withMessages(['academic_year_id' => 'The academic year belongs to a different campus.']);
        }
        $program = isset($data['academic_program_id']) ? AcademicProgram::query()->findOrFail($data['academic_program_id']) : null;
        if ($program) {
            $programCampusIds = DB::table('academic_program_campuses')->where('tenant_id', $context->tenant()->getKey())->where('academic_program_id', $program->getKey())->pluck('campus_id');
            if ($programCampusIds->isNotEmpty() && (! isset($data['campus_id']) || ! $programCampusIds->contains((int) $data['campus_id']))) {
                throw ValidationException::withMessages(['campus_id' => 'The selected program is not offered at this campus.']);
            }
        }
        $excluded = array_flip(['applications_open_at', 'application_deadline', 'documents_deadline', 'offer_deadline', 'eligibility_rules']);
        $intakeAttributes = array_diff_key($data, $excluded);
        $intake = DB::transaction(function () use ($data, $intakeAttributes, $institution, $request, $context, $audit): AdmissionIntake {
            $intake = AdmissionIntake::query()->create([
                ...$intakeAttributes,
                'institution_id' => $institution->getKey(), 'academic_year_id' => $data['academic_year_id'] ?? null,
                'campus_id' => $data['campus_id'] ?? null, 'academic_program_id' => $data['academic_program_id'] ?? null,
                'eligibility_rules' => $data['eligibility_rules'] ?? [], 'status' => 'draft',
                'applications_open_at' => $this->localDate($data['applications_open_at'] ?? null, $data['timezone']),
                'application_deadline' => $this->localDate($data['application_deadline'] ?? null, $data['timezone']),
                'documents_deadline' => $this->localDate($data['documents_deadline'] ?? null, $data['timezone']),
                'offer_deadline' => $this->localDate($data['offer_deadline'] ?? null, $data['timezone']),
                'created_by' => $request->user()->getKey(),
            ]);
            $template = AdmissionFormTemplate::query()->create([
                'institution_id' => $institution->getKey(), 'intake_id' => $intake->getKey(), 'code' => 'application',
                'name' => $intake->name.' application', 'status' => 'draft', 'created_by' => $request->user()->getKey(),
            ]);
            AdmissionFormVersion::query()->create([
                'form_template_id' => $template->getKey(), 'version_number' => 1,
                'definition' => $this->defaultFormDefinition($intake->name), 'status' => 'draft', 'created_by' => $request->user()->getKey(),
            ]);
            $audit->record('admissions.intake.created', $request->user(), (int) $context->tenant()->getKey(), $intake, ['code' => $intake->code]);

            return $intake;
        });

        return $this->redirect($context, "Intake {$intake->name} created as a draft with a versioned application form.");
    }

    public function updateIntake(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'cycle' => ['nullable', 'string', 'max:48'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey())->where('status', 'active'))],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey())->where('status', 'active'))],
            'delivery_mode' => ['required', Rule::in(['on_campus', 'online', 'hybrid', 'distance', 'custom'])],
            'locale' => ['required', 'string', 'max:16'], 'timezone' => ['required', 'timezone'],
            'applications_open_at' => ['nullable', 'date'], 'application_deadline' => ['nullable', 'date'],
            'documents_deadline' => ['nullable', 'date'], 'offer_deadline' => ['nullable', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'reserved_capacity' => ['required', 'integer', 'min:0', 'max:100000'],
            'application_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'application_fee_currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'public_description' => ['nullable', 'string', 'max:5000'], 'is_public' => ['required', 'boolean'],
            'eligibility_rules' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.required_answers' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.required_answers.*' => ['string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'eligibility_rules.minimum_values' => ['nullable', 'array', 'max:20'],
            'eligibility_rules.minimum_values.*' => ['numeric', 'min:0', 'max:1000000000'],
        ]);

        if ($context->membership()->campus_id !== null && (int) ($data['campus_id'] ?? 0) !== (int) $context->membership()->campus_id) {
            abort(404);
        }
        if (isset($data['capacity'], $data['reserved_capacity']) && $data['reserved_capacity'] > $data['capacity']) {
            throw ValidationException::withMessages(['reserved_capacity' => 'Reserved capacity cannot exceed the intake capacity.']);
        }
        $features = AcademicDefaults::present(AcademicConfiguration::query()->firstOrFail())['enabled_features'];
        if (! ($features['calendar'] ?? false) && ! empty($data['academic_year_id'])) {
            throw ValidationException::withMessages(['academic_year_id' => 'Enable the academic calendar feature before binding an academic year.']);
        }
        if (! ($features['programs'] ?? false) && ! empty($data['academic_program_id'])) {
            throw ValidationException::withMessages(['academic_program_id' => 'Enable the academic programs feature before binding a program.']);
        }
        $year = isset($data['academic_year_id']) ? AcademicYear::query()->findOrFail($data['academic_year_id']) : null;
        if ($year && $year->campus_id && (int) $year->campus_id !== (int) ($data['campus_id'] ?? 0)) {
            throw ValidationException::withMessages(['academic_year_id' => 'The academic year belongs to a different campus.']);
        }
        $program = isset($data['academic_program_id']) ? AcademicProgram::query()->findOrFail($data['academic_program_id']) : null;
        if ($program) {
            $programCampusIds = DB::table('academic_program_campuses')->where('tenant_id', $context->tenant()->getKey())->where('academic_program_id', $program->getKey())->pluck('campus_id');
            if ($programCampusIds->isNotEmpty() && (! isset($data['campus_id']) || ! $programCampusIds->contains((int) $data['campus_id']))) {
                throw ValidationException::withMessages(['campus_id' => 'The selected program is not offered at this campus.']);
            }
        }
        $this->assertIntakeDeadlines($data);
        $intakeName = DB::transaction(function () use ($intake, $data, $request, $context, $audit): string {
            $lockedIntake = AdmissionIntake::query()->lockForUpdate()->findOrFail($intake->getKey());
            if (isset($data['capacity'])) {
                $activeSeats = DB::table('admission_seat_reservations')->where('tenant_id', $context->tenant()->getKey())
                    ->where('intake_id', $lockedIntake->getKey())->whereIn('status', ['pending', 'accepted'])
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
                if ($activeSeats > $data['capacity']) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than active seat reservations.']);
                }
            }

            $before = $lockedIntake->only(['name', 'campus_id', 'academic_year_id', 'academic_program_id', 'status', 'capacity', 'application_deadline']);
            $lockedIntake->forceFill([
                'name' => $data['name'], 'cycle' => $data['cycle'] ?? null,
                'academic_year_id' => $data['academic_year_id'] ?? null, 'campus_id' => $data['campus_id'] ?? null,
                'academic_program_id' => $data['academic_program_id'] ?? null, 'delivery_mode' => $data['delivery_mode'],
                'locale' => $data['locale'], 'timezone' => $data['timezone'],
                'applications_open_at' => $this->localDate($data['applications_open_at'] ?? null, $data['timezone']),
                'application_deadline' => $this->localDate($data['application_deadline'] ?? null, $data['timezone']),
                'documents_deadline' => $this->localDate($data['documents_deadline'] ?? null, $data['timezone']),
                'offer_deadline' => $this->localDate($data['offer_deadline'] ?? null, $data['timezone']),
                'capacity' => $data['capacity'] ?? null, 'reserved_capacity' => $data['reserved_capacity'],
                'application_fee_amount' => $data['application_fee_amount'] ?? null,
                'application_fee_currency' => $data['application_fee_currency'] ?? null,
                'public_description' => $data['public_description'] ?? null, 'is_public' => $data['is_public'],
                'eligibility_rules' => $data['eligibility_rules'] ?? $lockedIntake->eligibility_rules ?? [], 'updated_by' => $request->user()->getKey(),
            ])->save();
            $audit->record('admissions.intake.updated', $request->user(), (int) $context->tenant()->getKey(), $lockedIntake, [
                'before' => $before, 'fields' => array_keys($data),
            ]);

            return (string) $lockedIntake->name;
        });

        return $this->redirect($context, "Intake {$intakeName} updated.");
    }

    public function transitionIntake(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['scheduled', 'open', 'paused', 'closed', 'archived'])]]);
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $allowed = ['draft' => ['scheduled', 'open'], 'scheduled' => ['open', 'paused', 'closed'], 'open' => ['paused', 'closed'], 'paused' => ['open', 'closed'], 'closed' => ['archived']];
        if (! in_array($data['status'], $allowed[$intake->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This intake lifecycle transition is not allowed.']);
        }
        if ($data['status'] === 'open') {
            $settings = AdmissionSetting::query()->firstOrFail();
            abort_unless($settings->enabled && $settings->public_applications_enabled, 409, 'Admissions and public applications must be enabled first.');
            $published = $intake->formTemplates()->whereHas('versions', fn ($query) => $query->where('status', 'published'))->exists();
            abort_unless($published, 409, 'Publish an application form before opening the intake.');
            if ($intake->application_deadline && $intake->application_deadline->isPast()) {
                throw ValidationException::withMessages(['status' => 'The application deadline has already passed.']);
            }
        }
        $before = $intake->status;
        $intake->forceFill(['status' => $data['status']])->save();
        $audit->record('admissions.intake.status_changed', $request->user(), (int) $context->tenant()->getKey(), $intake, ['from' => $before, 'to' => $intake->status]);

        return $this->redirect($context, "Intake {$intake->code} marked {$intake->status}.");
    }

    public function saveForm(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AdmissionFormDefinition $forms, AuditRecorder $audit): RedirectResponse
    {
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $definition = $forms->normalize($request->validate(['definition' => ['required', 'array']])['definition']);
        DB::transaction(function () use ($intake, $definition, $request): void {
            $template = $intake->formTemplates()->firstOrFail();
            $draft = AdmissionFormVersion::query()->where('form_template_id', $template->getKey())->where('status', 'draft')->orderByDesc('version_number')->lockForUpdate()->first();
            if ($draft) {
                $draft->fill(['definition' => $definition, 'created_by' => $request->user()->getKey()])->save();

                return;
            }
            $version = (int) AdmissionFormVersion::query()->where('form_template_id', $template->getKey())->max('version_number') + 1;
            AdmissionFormVersion::query()->create(['form_template_id' => $template->getKey(), 'version_number' => $version, 'definition' => $definition, 'status' => 'draft', 'created_by' => $request->user()->getKey()]);
        });
        $audit->record('admissions.form.draft_saved', $request->user(), (int) $context->tenant()->getKey(), $intake, ['intake_id' => $intake->getKey(), 'field_count' => $this->countFields($definition)]);

        return $this->redirect($context, 'The draft application form was saved. Published form versions remain unchanged.');
    }

    public function publishForm(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AdmissionFormDefinition $forms, AuditRecorder $audit): RedirectResponse
    {
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $version = $intake->formTemplates()->firstOrFail()->versions()->where('status', 'draft')->firstOrFail();
        $definition = $forms->normalize($version->definition);
        $requiredCodes = $forms->documentRequirementCodes($definition);
        $existingCodes = AdmissionDocumentRequirement::query()->where('intake_id', $intake->getKey())->whereIn('code', $requiredCodes)->pluck('code')->all();
        if (array_diff($requiredCodes, $existingCodes) !== []) {
            throw ValidationException::withMessages(['definition' => 'Every upload field must reference a document requirement configured for this intake.']);
        }
        $version->forceFill(['definition' => $definition, 'status' => 'published', 'published_by' => $request->user()->getKey(), 'published_at' => now(), 'effective_from' => now()])->save();
        $audit->record('admissions.form.published', $request->user(), (int) $context->tenant()->getKey(), $version, ['intake_id' => $intake->getKey(), 'version' => $version->version_number]);

        return $this->redirect($context, "Application form version {$version->version_number} published.");
    }

    public function storeDocumentRequirement(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('admission_document_requirements', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('intake_id', $intake->getKey()))],
            'name' => ['required', 'string', 'max:160'], 'instructions' => ['nullable', 'string', 'max:1000'],
            'is_required' => ['required', 'boolean'], 'allowed_mime_types' => ['required', 'array', 'min:1', 'max:3'],
            'allowed_mime_types.*' => ['required', Rule::in(['application/pdf', 'image/jpeg', 'image/png'])],
            'maximum_bytes' => ['required', 'integer', 'min:1024', 'max:10485760'],
        ]);
        $institution = Institution::query()->firstOrFail();
        $requirement = AdmissionDocumentRequirement::query()->create([...$data, 'institution_id' => $institution->getKey(), 'intake_id' => $intake->getKey(), 'maximum_files' => 1]);
        $audit->record('admissions.document_requirement.created', $request->user(), (int) $context->tenant()->getKey(), $requirement, ['intake_id' => $intake->getKey(), 'code' => $requirement->code]);

        return $this->redirect($context, 'Document requirement created.');
    }

    public function showApplication(Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access): Response
    {
        $application = $this->application($applicationId, $access)->load(['intake', 'profile', 'formVersion']);
        $access->assertAssignedOrAdministrator($application);
        $documents = AdmissionDocument::query()->where('application_id', $application->getKey())->with(['versions', 'requirement'])->orderBy('created_at')->get();
        $events = DB::table('admission_lifecycle_events')->where('tenant_id', $application->tenant_id)->where('application_id', $application->getKey())->orderBy('occurred_at')->get();
        $assessments = AdmissionAssessment::query()->where('application_id', $application->getKey())->orderByDesc('created_at')->get();
        $decisions = AdmissionDecision::query()->where('application_id', $application->getKey())->orderByDesc('version_number')->get();
        $offers = AdmissionOffer::query()->where('application_id', $application->getKey())->orderByDesc('version_number')->get();
        $guardians = AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())->orderByDesc('id')->get();

        return Inertia::render('workspace/admissions/application', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'application' => [
                'id' => $application->getKey(), 'number' => $application->application_number, 'status' => $application->status,
                'eligibility' => $application->eligibility_status, 'submittedAt' => $application->submitted_at,
                'intake' => ['id' => $application->intake?->getKey(), 'name' => $application->intake?->name, 'code' => $application->intake?->code],
                'profile' => $application->profile?->only(['given_name', 'middle_name', 'family_name', 'birth_date', 'phone', 'country', 'region', 'city']),
                'answers' => $application->answers ?? [], 'form' => $application->formVersion?->definition,
            ],
            'documents' => $documents->map(fn (AdmissionDocument $document): array => [
                'id' => $document->getKey(), 'name' => $document->requirement->name ?? 'Supporting document',
                'status' => $document->status, 'reviewNote' => $document->review_note, 'version' => $document->current_version,
                'scanStatus' => $document->scan_status,
                'downloadUrl' => $document->scan_status === 'clean' ? route('tenant.admissions.documents.download', ['tenant' => $context->tenant()->slug, 'documentId' => $document->getKey()]) : null,
            ]),
            'guardians' => $guardians->map(fn (AdmissionGuardianAuthorization $authorization): array => [
                'id' => $authorization->getKey(), 'name' => $authorization->guardian_name,
                'email' => $authorization->guardian_email, 'relationship' => $authorization->relationship,
                'status' => $authorization->status, 'verificationMethod' => $authorization->verification_method,
            ]),
            'events' => $events, 'assessments' => $assessments, 'decisions' => $decisions, 'offers' => $offers,
            'fee' => AdmissionFeeObligation::query()->where('application_id', $application->getKey())->first()?->only(['id', 'amount', 'currency', 'status', 'payment_reference', 'waiver_reason']),
            'handoffRecord' => AdmissionSisHandoff::query()->where('application_id', $application->getKey())->first()?->only(['correlation_id', 'status', 'created_at']),
            'reviewers' => $this->eligibleReviewers($context),
        ]);
    }

    public function transitionApplication(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['under_review', 'documents_required', 'assessment_pending', 'interview_pending', 'decision_pending', 'withdrawn'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $workflow->transition($application, $data['status'], $request->user(), $data['reason'] ?? null);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Application status updated.');
    }

    public function assignReviewer(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'reviewer_user_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))],
            'assignment_type' => ['required', Rule::in(['review', 'document', 'assessment'])],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'due_at' => ['nullable', 'date'],
        ]);
        $application = $this->application($applicationId, $access);
        $access->assertApplication($application);
        $member = TenantMembership::query()->where('user_id', $data['reviewer_user_id'])->firstOrFail();
        abort_unless($member->hasPermission('admissions.applications.review'), 422, 'The selected reviewer lacks admissions review permission.');
        $campusId = $application->intake()->value('campus_id');
        if ($member->campus_id !== null && (int) $member->campus_id !== (int) $campusId) {
            throw ValidationException::withMessages(['reviewer_user_id' => 'A campus-scoped reviewer can only be assigned applications for that campus.']);
        }
        $assignment = AdmissionReviewerAssignment::query()->updateOrCreate(
            ['application_id' => $application->getKey(), 'reviewer_user_id' => $member->user_id, 'assignment_type' => $data['assignment_type']],
            ['status' => 'assigned', 'priority' => $data['priority'], 'due_at' => $data['due_at'] ?? null, 'assigned_by' => $request->user()->getKey()],
        );
        $audit->record('admissions.reviewer.assigned', $request->user(), (int) $context->tenant()->getKey(), $assignment, ['application_id' => $application->getKey(), 'assignment_type' => $assignment->assignment_type]);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Reviewer assigned.');
    }

    public function verifyGuardian(Request $request, Tenant $tenant, int $applicationId, int $authorizationId, TenantContext $context, AdmissionsAccess $access, GuardianAuthorizationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'verification_method' => ['required', Rule::in(['in_person', 'document_review', 'approved_provider'])],
        ]);
        $application = $this->application($applicationId, $access);
        $access->assertApplication($application);
        $authorization = AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())
            ->where('tenant_id', $context->tenant()->getKey())->findOrFail($authorizationId);
        $workflow->verifyRelationship($authorization, $request->user(), $data['verification_method']);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])
            ->with('status', 'Guardian relationship verification recorded. The applicant may now submit their application.');
    }

    public function reviewDocument(Request $request, Tenant $tenant, int $applicationId, int $documentId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected', 'resubmission_required'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        DB::transaction(function () use ($application, $documentId, $data, $request, $context, $audit): void {
            AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $document = AdmissionDocument::query()->where('application_id', $application->getKey())->lockForUpdate()->findOrFail($documentId);
            abort_unless($document->scan_status === 'clean', 409, 'This document is unavailable until an approved scanner marks it clean.');
            $version = AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->firstOrFail();
            $document->forceFill(['status' => $data['decision'], 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now()])->save();
            AdmissionDocumentReview::query()->create(['document_id' => $document->getKey(), 'document_version' => $version->version_number, 'reviewer_user_id' => $request->user()->getKey(), 'decision' => $data['decision'], 'note' => $data['note'] ?? null, 'reviewed_at' => now()]);
            $audit->record('admissions.document.reviewed', $request->user(), (int) $context->tenant()->getKey(), $document, ['document_id' => $document->getKey(), 'version' => $version->version_number, 'decision' => $data['decision']]);
        });

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Document review recorded.');
    }

    public function evaluateEligibility(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['human_review_note' => ['nullable', 'string', 'max:1000']]);
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $intake = $application->intake;
        $rules = $intake->eligibility_rules ?? [];
        $results = [];
        foreach ($rules['required_answers'] ?? [] as $field) {
            $value = data_get($application->answers, $field);
            $results[] = ['rule' => 'required_answer', 'field' => $field, 'result' => filled($value) ? 'pass' : 'review'];
        }
        foreach ($rules['minimum_values'] ?? [] as $field => $minimum) {
            $value = data_get($application->answers, $field);
            $results[] = ['rule' => 'minimum_value', 'field' => $field, 'result' => is_numeric($value) && (float) $value >= (float) $minimum ? 'pass' : 'review'];
        }
        $result = $results !== [] && collect($results)->every(fn (array $row): bool => $row['result'] === 'pass') ? 'eligible' : 'human_review_required';
        $version = (int) AdmissionEligibilityEvaluation::query()->where('application_id', $application->getKey())->max('version_number') + 1;
        $evaluation = AdmissionEligibilityEvaluation::query()->create([
            'application_id' => $application->getKey(), 'version_number' => $version, 'result' => $result,
            'rule_results' => $results, 'human_review_note' => $data['human_review_note'] ?? null,
            'evaluated_by' => $request->user()->getKey(), 'evaluated_at' => now(),
        ]);
        $application->forceFill(['eligibility_status' => $result === 'eligible' ? 'eligible' : 'review_required'])->save();
        $audit->record('admissions.eligibility.evaluated', $request->user(), (int) $context->tenant()->getKey(), $evaluation, ['application_id' => $application->getKey(), 'result' => $result, 'version' => $version]);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Eligibility review recorded; the automated check does not make an adverse decision.');
    }

    public function recordAssessment(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'assessment_type' => ['required', Rule::in(['entrance_test', 'interview', 'portfolio', 'other'])],
            'assessor_user_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))],
            'scheduled_at' => ['nullable', 'date'], 'score' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,3'],
            'maximum_score' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'], 'private_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['score'], $data['maximum_score']) && (float) $data['score'] > (float) $data['maximum_score']) {
            throw ValidationException::withMessages(['score' => 'The assessment score cannot exceed its maximum.']);
        }
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $assessment = AdmissionAssessment::query()->create([
            'application_id' => $application->getKey(), 'assessor_user_id' => $data['assessor_user_id'] ?? $request->user()->getKey(),
            'assessment_type' => $data['assessment_type'], 'status' => isset($data['score']) ? 'completed' : 'scheduled',
            'scheduled_at' => $data['scheduled_at'] ?? null, 'completed_at' => isset($data['score']) ? now() : null,
            'score' => $data['score'] ?? null, 'maximum_score' => $data['maximum_score'] ?? null, 'private_notes' => $data['private_notes'] ?? null,
        ]);
        $audit->record('admissions.assessment.recorded', $request->user(), (int) $context->tenant()->getKey(), $assessment, ['application_id' => $application->getKey(), 'assessment_type' => $assessment->assessment_type, 'status' => $assessment->status]);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Assessment record saved.');
    }

    public function recommendDecision(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['admit', 'waitlist', 'reject'])], 'applicant_reason' => ['nullable', 'string', 'max:2000'], 'private_reason' => ['nullable', 'string', 'max:2000']]);
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $workflow->recommendDecision($application, $data['decision'], $request->user(), $data['applicant_reason'] ?? null, $data['private_reason'] ?? null);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Decision recommendation submitted for independent approval.');
    }

    public function approveDecision(Request $request, Tenant $tenant, int $applicationId, int $decisionId, TenantContext $context, AdmissionsAccess $access, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $isAssignedReviewer = AdmissionReviewerAssignment::query()->where('application_id', $application->getKey())->where('reviewer_user_id', $request->user()->getKey())->where('status', 'assigned')->exists();
        if ($isAssignedReviewer) {
            throw ValidationException::withMessages(['decision' => 'The assigned reviewer cannot approve the final decision.']);
        }
        $decision = AdmissionDecision::query()->where('application_id', $application->getKey())->findOrFail($decisionId);
        $workflow->approveDecision($application, $decision, $request->user());

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Admissions decision approved. Applicants see only the published reason.');
    }

    public function issueOffer(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['conditions' => ['nullable', 'string', 'max:4000'], 'accept_by' => ['nullable', 'date', 'after:now']]);
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $acceptBy = isset($data['accept_by']) ? Carbon::parse($data['accept_by'], $application->intake?->timezone ?: 'UTC')->utc() : null;
        $workflow->issueOffer($application, $request->user(), $data['conditions'] ?? null, $acceptBy);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Offer issued and the seat reserved transactionally. No external delivery is claimed.');
    }

    public function createHandoff(Request $request, Tenant $tenant, int $applicationId, TenantContext $context, AdmissionsAccess $access, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $application = $this->application($applicationId, $access);
        $access->assertAssignedOrAdministrator($application);
        $handoff = $workflow->createSisHandoff($application, $request->user());

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'SIS handoff '.$handoff->correlation_id.' is queued for Phase 05. No student record was created.');
    }

    public function verifyFee(Request $request, Tenant $tenant, int $feeId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['paid', 'waived'])], 'payment_reference' => ['nullable', 'string', 'max:120'], 'waiver_reason' => ['nullable', 'string', 'max:500']]);
        if ($data['status'] === 'paid' && empty($data['payment_reference'])) {
            throw ValidationException::withMessages(['payment_reference' => 'A reference is required for manual payment verification.']);
        }
        if ($data['status'] === 'waived' && empty($data['waiver_reason'])) {
            throw ValidationException::withMessages(['waiver_reason' => 'Record a reason when waiving the fee.']);
        }
        $fee = AdmissionFeeObligation::query()->findOrFail($feeId);
        $application = $this->application((int) $fee->application_id, $access);
        $access->assertAssignedOrAdministrator($application);
        abort_unless($fee->status === 'due', 409, 'This fee obligation is already resolved.');
        $fee->forceFill(['status' => $data['status'], 'payment_reference' => $data['payment_reference'] ?? null, 'waiver_reason' => $data['waiver_reason'] ?? null, 'verified_by' => $request->user()->getKey(), 'verified_at' => now()])->save();
        $audit->record('admissions.fee.'.$data['status'], $request->user(), (int) $context->tenant()->getKey(), $fee, ['application_id' => $application->getKey(), 'amount' => $fee->amount, 'currency' => $fee->currency]);

        return to_route('tenant.admissions.applications.show', ['tenant' => $context->tenant()->slug, 'applicationId' => $application->getKey()])->with('status', 'Fee status recorded as '.$fee->status.'. No payment provider was involved.');
    }

    public function calculateMerit(Request $request, Tenant $tenant, int $intakeId, TenantContext $context, AdmissionsAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $intake = $access->scopeIntakes(AdmissionIntake::query())->findOrFail($intakeId);
        $data = $request->validate(['components' => ['required', 'array', 'min:1', 'max:12'], 'components.*.field' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'], 'components.*.weight' => ['required', 'integer', 'min:1', 'max:100']]);
        if (array_sum(array_column($data['components'], 'weight')) !== 100) {
            throw ValidationException::withMessages(['components' => 'Merit component weights must total 100.']);
        }
        $candidates = AdmissionApplication::query()->where('intake_id', $intake->getKey())->whereNotNull('submitted_at')->whereIn('eligibility_status', ['eligible', 'pending', 'review_required'])->orderBy('application_number')->get();
        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages(['intake' => 'No submitted applications are available to rank.']);
        }
        $scores = $candidates->map(function (AdmissionApplication $application) use ($data): array {
            $weightedMilli = 0;
            $breakdown = [];
            foreach ($data['components'] as $component) {
                $raw = data_get($application->answers, $component['field']);
                if (! is_numeric($raw)) {
                    throw ValidationException::withMessages(['components' => "Application {$application->application_number} has no numeric value for {$component['field']}."]);
                }
                $milli = (int) round((float) $raw * 1000);
                $weighted = (int) round($milli * (int) $component['weight'] / 100);
                $weightedMilli += $weighted;
                $breakdown[$component['field']] = ['score_milli' => $milli, 'weight_percent' => (int) $component['weight'], 'weighted_milli' => $weighted];
            }

            return ['application' => $application, 'score_milli' => $weightedMilli, 'breakdown' => $breakdown];
        })->sort(function (array $left, array $right): int {
            $score = $right['score_milli'] <=> $left['score_milli'];
            if ($score !== 0) {
                return $score;
            }
            $submitted = $left['application']->submitted_at <=> $right['application']->submitted_at;

            return $submitted !== 0 ? $submitted : strcmp($left['application']->application_number, $right['application']->application_number);
        })->values();

        $list = DB::transaction(function () use ($intake, $data, $scores, $request): AdmissionMeritList {
            $version = (int) DB::table('admission_merit_lists')->where('tenant_id', $intake->tenant_id)->where('intake_id', $intake->getKey())->max('version_number') + 1;
            $list = AdmissionMeritList::query()->create(['intake_id' => $intake->getKey(), 'version_number' => $version, 'formula' => $data['components'], 'status' => 'calculated', 'calculated_by' => $request->user()->getKey(), 'calculated_at' => now()]);
            foreach ($scores as $index => $row) {
                AdmissionMeritEntry::query()->create([
                    'merit_list_id' => $list->getKey(), 'application_id' => $row['application']->getKey(), 'rank' => $index + 1,
                    'score' => number_format($row['score_milli'] / 1000, 4, '.', ''), 'score_breakdown' => $row['breakdown'],
                ]);
            }

            return $list;
        });
        $audit->record('admissions.merit.calculated', $request->user(), (int) $context->tenant()->getKey(), $list, ['intake_id' => $intake->getKey(), 'version' => $list->version_number, 'candidate_count' => $scores->count(), 'tie_break' => 'submitted_at_then_application_number']);

        return $this->redirect($context, "Merit list version {$list->version_number} calculated for {$scores->count()} applications.");
    }

    public function downloadDocument(int $documentId, TenantContext $context, AdmissionsAccess $access): StreamedResponse
    {
        $document = AdmissionDocument::query()->findOrFail($documentId);
        abort_unless($document->scan_status === 'clean', 404);
        $application = $this->application((int) $document->application_id, $access);
        $access->assertApplication($application);
        $access->assertAssignedOrAdministrator($application);
        $version = AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($version->private_path), 404);

        return Storage::disk('local')->download($version->private_path, basename($version->original_name));
    }

    private function application(int $id, AdmissionsAccess $access): AdmissionApplication
    {
        $application = $access->scopeApplications(AdmissionApplication::query())->findOrFail($id);
        $access->assertApplication($application);

        return $application;
    }

    /** @return array<int, array<string, mixed>> */
    private function eligibleReviewers(TenantContext $context): array
    {
        return TenantMembership::query()->with(['user:id,name,email', 'roles.permissions'])->where('status', 'active')->get()
            ->filter(fn (TenantMembership $membership): bool => $membership->hasPermission('admissions.applications.review'))
            ->filter(fn (TenantMembership $membership): bool => $context->membership()->campus_id === null || (int) $membership->campus_id === (int) $context->membership()->campus_id)
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->user_id, 'name' => $membership->user?->name, 'campusId' => $membership->campus_id])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function defaultFormDefinition(string $intakeName): array
    {
        return [
            'title' => $intakeName.' application',
            'instructions' => 'Complete the required information and review it before submitting.',
            'sections' => [[
                'key' => 'application_details', 'title' => 'Application details',
                'fields' => [
                    ['key' => 'previous_result', 'type' => 'number', 'label' => 'Most recent academic result', 'help' => 'Enter a numeric result only if applicable.', 'required' => false],
                    ['key' => 'motivation', 'type' => 'long_text', 'label' => 'Why are you interested in this program?', 'required' => false, 'max_length' => 2000],
                    ['key' => 'information_consent', 'type' => 'consent', 'label' => 'I confirm the information supplied is accurate.', 'required' => true],
                ],
            ]],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function countFields(array $definition): int
    {
        $total = 0;
        foreach (($definition['sections'] ?? []) as $section) {
            if (is_array($section) && is_array($section['fields'] ?? null)) {
                $total += count($section['fields']);
            }
        }

        return $total;
    }

    private function localDate(?string $value, string $timezone): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value, $timezone)->utc();
    }

    /** @param array<string, mixed> $data */
    private function assertIntakeDeadlines(array $data): void
    {
        $timezone = (string) $data['timezone'];
        $openAt = isset($data['applications_open_at']) ? Carbon::parse((string) $data['applications_open_at'], $timezone) : null;
        $applicationDeadline = isset($data['application_deadline']) ? Carbon::parse((string) $data['application_deadline'], $timezone) : null;
        $documentsDeadline = isset($data['documents_deadline']) ? Carbon::parse((string) $data['documents_deadline'], $timezone) : null;
        $offerDeadline = isset($data['offer_deadline']) ? Carbon::parse((string) $data['offer_deadline'], $timezone) : null;

        if ($openAt && $applicationDeadline && $openAt->gt($applicationDeadline)) {
            throw ValidationException::withMessages(['application_deadline' => 'The application deadline must be after opening.']);
        }
        if ($applicationDeadline && $documentsDeadline && $documentsDeadline->lt($applicationDeadline)) {
            throw ValidationException::withMessages(['documents_deadline' => 'The document deadline cannot precede the application deadline.']);
        }
        if ($applicationDeadline && $offerDeadline && $offerDeadline->lt($applicationDeadline)) {
            throw ValidationException::withMessages(['offer_deadline' => 'The offer deadline cannot precede the application deadline.']);
        }
    }

    private function redirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.admissions.index', ['tenant' => $context->tenant()->slug])->with('status', $message);
    }
}
