<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCourse;
use App\Models\AcademicLevel;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseRegistrationHold;
use App\Models\CourseSection;
use App\Models\CourseWaitlistEntry;
use App\Models\EnrollmentPolicy;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CourseEnrollmentController
{
    public function __construct(private readonly CourseEnrollmentWorkflow $workflow, private readonly TenantContext $context) {}

    public function studentIndex(Request $request, Tenant $tenant): Response
    {
        $student = SisStudent::query()->where('user_id', auth()->id())->where('status', 'active')->first();
        $availableOfferings = CourseOffering::query()->with(['course:id,tenant_id,code,title', 'policy:id,tenant_id,waitlist_enabled', 'period:id,tenant_id,name,starts_on,ends_on', 'sections' => fn ($query) => $query->withCount(['enrollments as occupied_count' => fn ($enrollments) => $enrollments->whereIn('status', ['confirmed', 'offer_pending'])])])
            ->where('status', 'open')->where('institution_id', $this->context->membership()->institution_id)
            ->whereHas('period', fn ($query) => $query->where('status', 'active')->whereDate('starts_on', '<=', now())->whereDate('ends_on', '>=', now()))
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($nested) => $nested->where('offering_code', 'like', $term)->orWhereHas('course', fn ($course) => $course->where('code', 'like', $term)->orWhere('title', 'like', $term)));
            })->orderBy('offering_code')->limit(200)->get();
        $prerequisiteRows = DB::table('course_prerequisite_rules as rules')->join('academic_courses as prerequisite_courses', function ($join): void {
            $join->on('prerequisite_courses.id', '=', 'rules.prerequisite_course_id')->on('prerequisite_courses.tenant_id', '=', 'rules.tenant_id');
        })->where('rules.tenant_id', $this->context->tenant()->getKey())->where('rules.is_required', true)
            ->whereIn('rules.course_id', $availableOfferings->pluck('academic_course_id')->unique()->all())
            ->get(['rules.course_id', 'rules.prerequisite_course_id', 'rules.relation_type', 'prerequisite_courses.code', 'prerequisite_courses.title'])->groupBy('course_id');
        $offerings = $availableOfferings->map(fn (CourseOffering $offering): array => [
            'id' => $offering->getKey(), 'code' => $offering->offering_code, 'courseCode' => $offering->course->code,
            'title' => $offering->course->title, 'period' => $offering->period->name,
            'courseId' => (int) $offering->academic_course_id,
            'prerequisites' => ($prerequisiteRows[$offering->academic_course_id] ?? collect())->map(fn ($row): array => ['id' => (int) $row->prerequisite_course_id, 'code' => $row->code, 'title' => $row->title, 'relation' => $row->relation_type])->values(),
            'sections' => $offering->sections->where('status', 'open')->map(fn (CourseSection $section): array => [
                'id' => $section->getKey(), 'code' => $section->section_code,
                'capacity' => $section->capacity, 'reserved' => $section->reserved_seats,
                'occupied' => (int) $section->occupied_count,
                'waitlistEnabled' => (bool) $offering->policy->waitlist_enabled,
            ])->values(),
        ]);
        $myEnrollments = CourseEnrollment::query()->with(['offering.course:id,tenant_id,code,title', 'section:id,tenant_id,section_code'])
            ->where('student_id', $student?->getKey() ?? 0)->orderByDesc('requested_at')->paginate(50)->through(fn (CourseEnrollment $enrollment): array => [
                'id' => $enrollment->getKey(), 'status' => $enrollment->status, 'course' => $enrollment->offering->course->title,
                'code' => $enrollment->offering->course->code, 'section' => $enrollment->section->section_code,
                'requestedAt' => Carbon::parse($enrollment->requested_at)->toIso8601String(), 'canDrop' => in_array($enrollment->status, ['confirmed', 'pending', 'waitlisted', 'offer_pending'], true),
                'waitlistEntryId' => CourseWaitlistEntry::query()->where('student_id', $enrollment->student_id)->where('section_id', $enrollment->section_id)->where('status', 'offered')->value('id'),
            ]);

        return Inertia::render('workspace/registration/index', ['tenant' => ['slug' => $tenant->slug], 'studentAvailable' => $student !== null, 'offerings' => $offerings, 'myEnrollments' => $myEnrollments]);
    }

    public function register(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['section_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'string', 'min:20', 'max:80']]);
        $enrollment = $this->workflow->register((int) $data['section_id'], $data['idempotency_key'], $request->user());

        return to_route('tenant.registration.index', $tenant)->with('status', 'Registration status: '.$enrollment->status.'.');
    }

    public function previewRegistration(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['section_id' => ['required', 'integer']]);

        return response()->json(['preview' => $this->workflow->previewRegistration((int) $data['section_id'], $request->user())]);
    }

    public function drop(Request $request, Tenant $tenant, int $enrollmentId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $enrollment = CourseEnrollment::query()->findOrFail($enrollmentId);
        $this->workflow->drop($enrollment, $request->user(), $data['reason'] ?? null);

        return to_route('tenant.registration.index', $tenant)->with('status', 'Course registration dropped.');
    }

    public function withdraw(Request $request, Tenant $tenant, int $enrollmentId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $this->workflow->requestWithdrawal($enrollmentId, $data['reason'], $request->user());

        return to_route('tenant.registration.index', $tenant)->with('status', 'Withdrawal request recorded.');
    }

    public function transfer(Request $request, Tenant $tenant, int $enrollmentId): RedirectResponse
    {
        $data = $request->validate(['section_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        $this->workflow->requestSectionTransfer($enrollmentId, (int) $data['section_id'], $data['reason'], $request->user());

        return to_route('tenant.registration.index', $tenant)->with('status', 'Section transfer request recorded.');
    }

    public function prerequisiteException(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer'], 'prerequisite_course_id' => ['required', 'integer', 'different:course_id'],
            'exception_type' => ['required', Rule::in(['waiver', 'equivalency'])], 'reason' => ['required', 'string', 'min:12', 'max:1000'],
            'evidence_reference' => ['nullable', 'string', 'max:180'],
        ]);
        $this->workflow->requestPrerequisiteException((int) $data['course_id'], (int) $data['prerequisite_course_id'], $data['exception_type'], $data['reason'], $data['evidence_reference'] ?? null, $request->user());

        return back()->with('status', 'Prerequisite exception request recorded for independent review.');
    }

    public function prerequisiteDecision(Request $request, Tenant $tenant, int $exceptionId): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:8', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $this->workflow->decidePrerequisiteException($exceptionId, $data['decision'], $data['reason'], isset($data['expires_at']) ? Carbon::parse($data['expires_at'], 'UTC') : null, $request->user());

        return back()->with('status', 'Prerequisite exception review recorded.');
    }

    public function storePrerequisiteRule(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer'], 'prerequisite_course_id' => ['required', 'integer', 'different:course_id'],
            'relation_type' => ['required', Rule::in(['prerequisite', 'corequisite'])], 'is_required' => ['required', 'boolean'],
        ]);
        $this->workflow->addPrerequisiteRule((int) $data['course_id'], (int) $data['prerequisite_course_id'], $data['relation_type'], (bool) $data['is_required'], $request->user());

        return back()->with('status', 'Prerequisite rule saved.');
    }

    public function acceptWaitlistOffer(Request $request, Tenant $tenant, int $entryId): RedirectResponse
    {
        $this->workflow->acceptWaitlistOffer($entryId, $request->user());

        return to_route('tenant.registration.index', $tenant)->with('status', 'Your waitlist seat is confirmed.');
    }

    public function registrarIndex(Request $request, Tenant $tenant): Response
    {
        $scope = $this->workflow->scope();
        $filters = $request->validate(['campus_id' => ['nullable', 'integer'], 'course_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['draft', 'open', 'closed', 'completed', 'cancelled'])]]);
        $offerings = CourseOffering::query()->with(['course:id,tenant_id,code,title', 'period:id,tenant_id,name', 'sections' => fn ($query) => $query->withCount([
            'enrollments as confirmed_count' => fn ($enrollments) => $enrollments->where('status', 'confirmed'),
            'waitlistEntries as waitlist_count' => fn ($waitlist) => $waitlist->whereIn('status', ['waiting', 'offered']),
        ])])
            ->where('institution_id', $scope['institutionId'])
            ->when(isset($filters['campus_id']), fn ($query) => $query->where('campus_id', (int) $filters['campus_id']))
            ->when(isset($filters['course_id']), fn ($query) => $query->where('academic_course_id', (int) $filters['course_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')->limit(250)->get()->map(fn (CourseOffering $offering): array => [
                'id' => $offering->getKey(), 'code' => $offering->offering_code, 'status' => $offering->status,
                'courseId' => $offering->academic_course_id, 'course' => $offering->course->title,
                'period' => $offering->period->name, 'policyId' => $offering->policy_id, 'campusId' => $offering->campus_id,
                'deliveryMode' => $offering->delivery_mode,
                'registrationOpensAt' => $offering->registration_opens_at ? Carbon::parse($offering->registration_opens_at)->timezone($offering->institution_timezone ?: 'UTC')->format('Y-m-d\\TH:i') : null,
                'registrationClosesAt' => $offering->registration_closes_at ? Carbon::parse($offering->registration_closes_at)->timezone($offering->institution_timezone ?: 'UTC')->format('Y-m-d\\TH:i') : null,
                'sections' => $offering->sections->map(fn (CourseSection $section): array => [
                    'id' => $section->getKey(), 'code' => $section->section_code, 'capacity' => $section->capacity,
                    'reserved' => $section->reserved_seats,
                    'confirmed' => (int) data_get($section, 'confirmed_count', 0),
                    'waitlist' => (int) data_get($section, 'waitlist_count', 0),
                    'campusId' => $section->campus_id, 'status' => $section->status,
                ])->values(),
            ]);
        $offeringIds = $offerings->pluck('id')->all();
        $enrollmentStats = CourseEnrollment::query()->where('institution_id', $scope['institutionId'])->whereIn('offering_id', $offeringIds)
            ->selectRaw("COUNT(*) as total, SUM(status = 'confirmed') as confirmed, SUM(status = 'waitlisted') as waitlisted, SUM(status = 'pending') as pending, SUM(status = 'pending_withdrawal') as pending_withdrawals, SUM(status = 'dropped') as dropped, SUM(status = 'withdrawn') as withdrawn")
            ->first();
        $openSectionIds = CourseSection::query()->where('status', 'open')->whereIn('offering_id', $offeringIds)->pluck('id');
        $seatCapacity = (int) CourseSection::query()->whereIn('id', $openSectionIds)->sum('capacity');
        $confirmedSeats = (int) CourseEnrollment::query()->whereIn('section_id', $openSectionIds)->where('status', 'confirmed')->count();
        $analytics = [
            'total' => (int) ($enrollmentStats->total ?? 0), 'confirmed' => (int) ($enrollmentStats->confirmed ?? 0),
            'waitlisted' => (int) ($enrollmentStats->waitlisted ?? 0), 'pending' => (int) ($enrollmentStats->pending ?? 0),
            'pendingWithdrawals' => (int) ($enrollmentStats->pending_withdrawals ?? 0), 'dropped' => (int) ($enrollmentStats->dropped ?? 0),
            'withdrawn' => (int) ($enrollmentStats->withdrawn ?? 0), 'seatCapacity' => $seatCapacity,
            'openConfirmed' => $confirmedSeats, 'seatUtilizationPercent' => $seatCapacity > 0 ? round(min(100, ($confirmedSeats / $seatCapacity) * 100), 1) : 0,
        ];
        $courses = AcademicCourse::query()->where('institution_id', $scope['institutionId'])->where('status', 'active')->orderBy('code')->get(['id', 'code', 'title']);
        $levels = AcademicLevel::query()->where('institution_id', $scope['institutionId'])->where('status', 'active')->orderBy('sequence')->get(['id', 'code', 'name']);
        $years = AcademicYear::query()->where('institution_id', $scope['institutionId'])->where('status', 'active')->orderByDesc('starts_on')->get(['id', 'name', 'status']);
        $periods = AcademicPeriod::query()->where('status', 'active')->orderBy('starts_on')->get(['id', 'academic_year_id', 'name', 'starts_on', 'ends_on']);
        $policies = EnrollmentPolicy::query()->where('institution_id', $scope['institutionId'])->orderBy('name')->get([
            'id', 'name', 'minimum_courses', 'maximum_courses', 'maximum_credits', 'requires_approval', 'waitlist_enabled',
            'waitlist_offer_hours', 'require_prerequisites', 'require_withdrawal_approval', 'allow_section_transfer',
            'allow_late_registration', 'status',
        ])->map(fn (EnrollmentPolicy $policy): array => [
            'id' => $policy->getKey(), 'name' => $policy->name, 'minimum_courses' => $policy->minimum_courses,
            'maximum_courses' => $policy->maximum_courses, 'maximum_credits' => $policy->maximum_credits,
            'requires_approval' => (bool) $policy->requires_approval, 'waitlist_enabled' => (bool) $policy->waitlist_enabled,
            'waitlist_offer_hours' => $policy->waitlist_offer_hours, 'require_prerequisites' => (bool) $policy->require_prerequisites,
            'require_withdrawal_approval' => (bool) $policy->require_withdrawal_approval,
            'allow_section_transfer' => (bool) $policy->allow_section_transfer, 'allow_late_registration' => (bool) $policy->allow_late_registration,
            'status' => $policy->status,
            'academic_level_ids' => DB::table('enrollment_policy_academic_levels')->where('tenant_id', $scope['tenantId'])->where('policy_id', $policy->getKey())->pluck('academic_level_id')->map(fn ($id): string => (string) $id)->values()->all(),
        ]);
        $campuses = Campus::query()->where('institution_id', $scope['institutionId'])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
        $instructors = TenantMembership::query()->with('user:id,name,email')->where('institution_id', $scope['institutionId'])->where('status', 'active')
            ->whereHas('roles.permissions', fn ($query) => $query->where('key', 'enrollment.instructor.roster.view'))
            ->orderBy('id')->get()->map(fn (TenantMembership $membership): array => ['user_id' => $membership->user_id, 'name' => $membership->user->name, 'email' => $membership->user->email]);
        $students = SisStudent::query()->where('institution_id', $scope['institutionId'])->where('status', 'active')->orderBy('student_number')->limit(500)
            ->get(['id', 'student_number', 'legal_first_name', 'legal_last_name'])->map(fn ($student): array => ['id' => $student->getKey(), 'student_number' => $student->student_number, 'name' => trim($student->legal_first_name.' '.$student->legal_last_name)]);
        $activeHolds = CourseRegistrationHold::query()->with('student:id,tenant_id,student_number,legal_first_name,legal_last_name')->whereNull('revoked_at')->whereHas('student', fn ($query) => $query->where('institution_id', $scope['institutionId']))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->limit(50)->get()->map(fn (CourseRegistrationHold $hold): array => [
                'id' => $hold->getKey(), 'student_id' => $hold->student_id, 'student_number' => $hold->student->student_number,
                'student_name' => trim($hold->student->legal_first_name.' '.$hold->student->legal_last_name), 'type' => $hold->hold_type,
                'reason_code' => $hold->reason_code, 'reason' => $hold->reason, 'effective_at' => Carbon::parse($hold->effective_at)->toIso8601String(),
                'expires_at' => $hold->expires_at ? Carbon::parse($hold->expires_at)->toIso8601String() : null,
            ]);
        $pending = CourseEnrollment::query()->with(['student:id,tenant_id,student_number,legal_first_name,legal_last_name', 'offering.course:id,tenant_id,code,title', 'section:id,tenant_id,section_code'])
            ->where('institution_id', $scope['institutionId'])->where('status', 'pending')->orderBy('requested_at')->get()->map(fn (CourseEnrollment $enrollment): array => [
                'id' => $enrollment->getKey(), 'studentNumber' => $enrollment->student->student_number,
                'studentName' => trim($enrollment->student->legal_first_name.' '.$enrollment->student->legal_last_name),
                'course' => $enrollment->offering->course->title, 'section' => $enrollment->section->section_code,
            ]);
        $pendingWithdrawals = CourseEnrollment::query()->with(['student:id,tenant_id,student_number,legal_first_name,legal_last_name', 'offering.course:id,tenant_id,title'])
            ->where('institution_id', $scope['institutionId'])->where('status', 'pending_withdrawal')->orderBy('updated_at')->limit(100)->get()
            ->map(fn (CourseEnrollment $enrollment): array => ['id' => $enrollment->getKey(), 'student' => trim($enrollment->student->legal_first_name.' '.$enrollment->student->legal_last_name), 'number' => $enrollment->student->student_number, 'course' => $enrollment->offering->course->title, 'reason' => $enrollment->withdrawal_reason]);
        $pendingTransfers = DB::table('course_section_transfers as transfers')->join('course_enrollments as enrollments', function ($join): void {
            $join->on('enrollments.id', '=', 'transfers.enrollment_id')->on('enrollments.tenant_id', '=', 'transfers.tenant_id');
        })
            ->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'enrollments.student_id')->on('students.tenant_id', '=', 'enrollments.tenant_id');
            })
            ->join('course_sections as destinations', function ($join): void {
                $join->on('destinations.id', '=', 'transfers.destination_section_id')->on('destinations.tenant_id', '=', 'transfers.tenant_id');
            })
            ->where('transfers.tenant_id', $scope['tenantId'])->where('transfers.status', 'pending')->orderBy('transfers.created_at')->limit(100)
            ->get(['transfers.id', 'students.student_number', 'students.legal_first_name', 'students.legal_last_name', 'destinations.section_code', 'transfers.reason']);
        $pendingExceptions = DB::table('course_prerequisite_exceptions as exceptions')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'exceptions.student_id')->on('students.tenant_id', '=', 'exceptions.tenant_id');
        })
            ->join('academic_courses as courses', function ($join): void {
                $join->on('courses.id', '=', 'exceptions.course_id')->on('courses.tenant_id', '=', 'exceptions.tenant_id');
            })
            ->where('exceptions.tenant_id', $scope['tenantId'])->where('exceptions.status', 'pending')->orderBy('exceptions.created_at')->limit(100)
            ->get(['exceptions.id', 'exceptions.exception_type', 'exceptions.reason', 'exceptions.evidence_reference', 'exceptions.requested_by', 'students.student_number', 'students.legal_first_name', 'students.legal_last_name', 'courses.title']);
        $waitlistEntries = CourseWaitlistEntry::query()->with([
            'student:id,tenant_id,student_number,legal_first_name,legal_last_name',
            'section:id,tenant_id,offering_id,section_code',
            'offering:id,tenant_id,offering_code,academic_course_id',
            'offering.course:id,tenant_id,title',
        ])->where('tenant_id', $scope['tenantId'])->whereIn('status', ['waiting', 'offered'])
            ->whereHas('offering', fn ($query) => $query->where('institution_id', $scope['institutionId']))
            ->orderBy('offering_id')->orderBy('section_id')->orderBy('position')->limit(250)->get()
            ->map(function (CourseWaitlistEntry $entry): array {
                $expiresAt = data_get($entry, 'offer_expires_at');

                return [
                    'id' => $entry->getKey(), 'position' => $entry->position, 'status' => $entry->status,
                    'student_number' => (string) data_get($entry, 'student.student_number'),
                    'student_name' => trim((string) data_get($entry, 'student.legal_first_name').' '.(string) data_get($entry, 'student.legal_last_name')),
                    'offering_code' => (string) data_get($entry, 'offering.offering_code'),
                    'course' => (string) data_get($entry, 'offering.course.title'),
                    'section' => (string) data_get($entry, 'section.section_code'),
                    'offer_expires_at' => $expiresAt ? Carbon::parse($expiresAt)->toIso8601String() : null,
                ];
            });
        $previewBatchId = (int) session()->get('course_enrollment_batch_id', 0);
        $bulkPreview = $previewBatchId > 0 ? DB::table('course_enrollment_batches')->where('tenant_id', $scope['tenantId'])->where('institution_id', $scope['institutionId'])->where('id', $previewBatchId)->first() : null;
        $bulkPreviewRows = $bulkPreview ? DB::table('course_enrollment_batch_rows')->where('tenant_id', $scope['tenantId'])->where('batch_id', $previewBatchId)->orderBy('row_number')->limit(500)->get(['row_number', 'student_number', 'offering_code', 'section_code', 'status', 'error_code']) : collect();

        return Inertia::render('workspace/registration/registrar', compact('tenant', 'offerings', 'analytics', 'filters', 'courses', 'levels', 'years', 'periods', 'policies', 'campuses', 'instructors', 'students', 'pending', 'pendingWithdrawals', 'pendingTransfers', 'pendingExceptions', 'waitlistEntries', 'activeHolds', 'bulkPreview', 'bulkPreviewRows'));
    }

    public function previewBulk(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'idempotency_key' => ['required', 'uuid']]);
        $contents = $data['file']->get();
        if (! is_string($contents)) {
            return back()->withErrors(['file' => 'The uploaded CSV could not be read.']);
        }
        $result = $this->workflow->previewBulkCsv($contents, $data['idempotency_key'], $request->user());

        return to_route('tenant.enrollment.registrar.index', $tenant)->with('course_enrollment_batch_id', $result['batch_id'])->with('status', "Preview created: {$result['valid']} valid rows and {$result['errors']} errors.");
    }

    public function confirmBulk(Request $request, Tenant $tenant, int $batchId): RedirectResponse
    {
        $result = $this->workflow->confirmBulkBatch($batchId, $request->user());

        return back()->with('status', "Bulk registration complete: {$result['imported']} imported, {$result['errors']} errors.");
    }

    public function storePolicy(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'], 'maximum_courses' => ['required', 'integer', 'min:1', 'max:40'],
            'minimum_courses' => ['nullable', 'integer', 'min:0', 'lte:maximum_courses'], 'maximum_credits' => ['nullable', 'numeric', 'min:0.001', 'max:99999.999'],
            'requires_approval' => ['sometimes', 'boolean'], 'waitlist_enabled' => ['sometimes', 'boolean'], 'waitlist_offer_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'require_prerequisites' => ['sometimes', 'boolean'], 'require_withdrawal_approval' => ['sometimes', 'boolean'],
            'allow_section_transfer' => ['sometimes', 'boolean'], 'allow_late_registration' => ['sometimes', 'boolean'],
            'waitlist_ordering' => ['sometimes', Rule::in(['fifo'])],
            'academic_level_ids' => ['sometimes', 'array', 'max:50'], 'academic_level_ids.*' => ['integer', 'distinct'],
        ]);
        $this->workflow->createPolicy($data, $request->user());

        return back()->with('status', 'Registration policy created.');
    }

    public function updatePolicy(Request $request, Tenant $tenant, int $policyId): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'], 'maximum_courses' => ['required', 'integer', 'min:1', 'max:40'],
            'minimum_courses' => ['nullable', 'integer', 'min:0', 'lte:maximum_courses'], 'maximum_credits' => ['nullable', 'numeric', 'min:0.001', 'max:99999.999'],
            'requires_approval' => ['sometimes', 'boolean'], 'waitlist_enabled' => ['sometimes', 'boolean'], 'waitlist_offer_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'require_prerequisites' => ['sometimes', 'boolean'], 'require_withdrawal_approval' => ['sometimes', 'boolean'],
            'allow_section_transfer' => ['sometimes', 'boolean'], 'allow_late_registration' => ['sometimes', 'boolean'],
            'waitlist_ordering' => ['sometimes', Rule::in(['fifo'])], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'academic_level_ids' => ['sometimes', 'array', 'max:50'], 'academic_level_ids.*' => ['integer', 'distinct'],
        ]);
        $this->workflow->updatePolicy(EnrollmentPolicy::query()->findOrFail($policyId), $data, $request->user());

        return back()->with('status', 'Registration policy updated.');
    }

    public function storeOffering(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'offering_code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'],
            'academic_year_id' => ['required', 'integer'], 'academic_period_id' => ['required', 'integer'],
            'academic_course_id' => ['required', 'integer'], 'academic_course_version_id' => ['nullable', 'integer'],
            'academic_program_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'], 'policy_id' => ['required', 'integer'],
            'delivery_workspace_id' => ['nullable', 'integer'], 'delivery_mode' => ['required', Rule::in(['in_person', 'online', 'hybrid'])],
            'registration_opens_at' => ['nullable', 'date'], 'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'late_registration_closes_at' => ['nullable', 'date', 'after:registration_closes_at'], 'add_deadline_at' => ['nullable', 'date'],
            'drop_deadline_at' => ['nullable', 'date'], 'withdrawal_deadline_at' => ['nullable', 'date'],
        ]);
        $this->workflow->createOffering($data, $request->user());

        return back()->with('status', 'Course offering created in draft status.');
    }

    public function updateOffering(Request $request, Tenant $tenant, int $offeringId): RedirectResponse
    {
        $data = $request->validate([
            'policy_id' => ['required', 'integer'], 'academic_program_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'delivery_mode' => ['required', Rule::in(['in_person', 'online', 'hybrid'])],
            'registration_opens_at' => ['sometimes', 'nullable', 'date'], 'registration_closes_at' => ['sometimes', 'nullable', 'date', 'after:registration_opens_at'],
            'late_registration_closes_at' => ['sometimes', 'nullable', 'date', 'after:registration_closes_at'],
            'add_deadline_at' => ['sometimes', 'nullable', 'date'], 'drop_deadline_at' => ['sometimes', 'nullable', 'date'],
            'withdrawal_deadline_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $this->workflow->updateOffering(CourseOffering::query()->findOrFail($offeringId), $data, $request->user());

        return back()->with('status', 'Course offering settings updated.');
    }

    public function storeSection(Request $request, Tenant $tenant, int $offeringId): RedirectResponse
    {
        $data = $request->validate(['section_code' => ['required', 'string', 'max:32'], 'capacity' => ['required', 'integer', 'min:1', 'max:10000'], 'reserved_seats' => ['nullable', 'integer', 'min:0'], 'campus_id' => ['nullable', 'integer']]);
        $offering = CourseOffering::query()->findOrFail($offeringId);
        $this->workflow->createSection($offering, $data['section_code'], $data['capacity'], (int) ($data['reserved_seats'] ?? 0), isset($data['campus_id']) ? (int) $data['campus_id'] : null, $request->user());

        return back()->with('status', 'Section created.');
    }

    public function updateSection(Request $request, Tenant $tenant, int $sectionId): RedirectResponse
    {
        $data = $request->validate([
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'], 'reserved_seats' => ['nullable', 'integer', 'min:0'],
            'campus_id' => ['nullable', 'integer'], 'status' => ['required', Rule::in(['open', 'closed'])],
        ]);
        $this->workflow->updateSection(CourseSection::query()->findOrFail($sectionId), (int) $data['capacity'], (int) ($data['reserved_seats'] ?? 0), isset($data['campus_id']) ? (int) $data['campus_id'] : null, $data['status'], $request->user());

        return back()->with('status', 'Section settings updated.');
    }

    public function offeringStatus(Request $request, Tenant $tenant, int $offeringId): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed', 'cancelled', 'completed'])], 'reason' => ['required_if:status,cancelled', 'nullable', 'string', 'min:8', 'max:1000']]);
        $offering = CourseOffering::query()->findOrFail($offeringId);
        $this->workflow->transitionOffering($offering, $data['status'], $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Offering status updated.');
    }

    public function decision(Request $request, Tenant $tenant, int $enrollmentId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->workflow->decide($enrollmentId, $data['decision'], $data['reason'], $request->user());

        return back()->with('status', 'Registration request reviewed.');
    }

    public function withdrawalDecision(Request $request, Tenant $tenant, int $enrollmentId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->workflow->decideWithdrawal($enrollmentId, $data['decision'], $data['reason'], $request->user());

        return back()->with('status', 'Withdrawal request reviewed.');
    }

    public function transferDecision(Request $request, Tenant $tenant, int $transferId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->workflow->decideSectionTransfer($transferId, $data['decision'], $data['reason'], $request->user());

        return back()->with('status', 'Section transfer reviewed.');
    }

    public function assignInstructor(Request $request, Tenant $tenant, int $sectionId): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $this->workflow->assignInstructor(CourseSection::query()->findOrFail($sectionId), (int) $data['user_id'], $request->user());

        return back()->with('status', 'Instructor assigned to section.');
    }

    public function storeHold(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'], 'hold_type' => ['required', Rule::in(['academic', 'administrative'])],
            'reason_code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'], 'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'effective_at' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after:effective_at'],
        ]);
        $this->workflow->placeHold((int) $data['student_id'], $data['hold_type'], $data['reason_code'], $data['reason'], Carbon::parse($data['effective_at'], 'UTC'), isset($data['expires_at']) ? Carbon::parse($data['expires_at'], 'UTC') : null, $request->user());

        return back()->with('status', 'Registration hold recorded.');
    }

    public function revokeHold(Request $request, Tenant $tenant, int $holdId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $this->workflow->revokeHold($holdId, $data['reason'], $request->user());

        return back()->with('status', 'Registration hold revoked.');
    }

    public function instructorSections(Tenant $tenant): Response
    {
        $member = $this->context->membership();
        $assignedSectionIds = DB::table('course_section_instructors')->where('tenant_id', $member->tenant_id)
            ->where('user_id', auth()->id())->select('section_id');
        $sections = CourseSection::query()->with(['offering.course:id,tenant_id,code,title'])
            ->whereIn('id', $assignedSectionIds)
            ->when($member->campus_id, fn ($query) => $query->where(function ($scope) use ($member): void {
                $scope->where('campus_id', $member->campus_id)->orWhere(function ($inherit) use ($member): void {
                    $inherit->whereNull('campus_id')->whereHas('offering', fn ($offering) => $offering->whereNull('campus_id')->orWhere('campus_id', $member->campus_id));
                });
            }))
            ->orderBy('offering_id')->orderBy('section_code')->paginate(25)->through(fn (CourseSection $section): array => [
                'id' => $section->getKey(), 'code' => $section->section_code,
                'course' => $section->offering->course->title, 'offering' => $section->offering->offering_code,
                'status' => $section->status, 'confirmed' => $section->confirmedCount(),
            ]);

        return Inertia::render('workspace/registration/instructor-sections', ['tenant' => ['slug' => $tenant->slug], 'sections' => $sections]);
    }

    public function instructorRoster(Request $request, Tenant $tenant, int $sectionId): Response
    {
        $section = CourseSection::query()->with('offering.course')->findOrFail($sectionId);
        $member = $this->context->membership();
        $effectiveCampusId = $section->campus_id ?? $section->offering->campus_id;
        if ($member->campus_id && $effectiveCampusId && (int) $member->campus_id !== (int) $effectiveCampusId) {
            abort(404);
        }
        $assigned = DB::table('course_section_instructors')->where('tenant_id', $member->tenant_id)->where('section_id', $section->getKey())->where('user_id', auth()->id())->exists();
        if (! $assigned && ! $member->hasPermission('enrollment.registrar.view')) {
            abort(403);
        }
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($filters['q'] ?? ''));
        $roster = CourseEnrollment::query()->with('student:id,tenant_id,student_number,legal_first_name,legal_last_name')->where('section_id', $section->getKey())->where('status', 'confirmed')
            ->when($term !== '', fn ($query) => $query->whereHas('student', fn ($student) => $student->where('student_number', 'like', '%'.$term.'%')->orWhere('legal_first_name', 'like', '%'.$term.'%')->orWhere('legal_last_name', 'like', '%'.$term.'%')))
            ->orderBy('id')->paginate(50)->withQueryString()->through(fn (CourseEnrollment $enrollment): array => [
                'studentNumber' => $enrollment->student->student_number,
                'name' => trim($enrollment->student->legal_first_name.' '.$enrollment->student->legal_last_name),
                'status' => $enrollment->status,
            ]);

        return Inertia::render('workspace/registration/roster', ['tenant' => ['slug' => $tenant->slug], 'section' => ['code' => $section->section_code, 'course' => $section->offering->course->title, 'id' => $section->getKey()], 'roster' => $roster, 'filters' => ['q' => $term]]);
    }

    public function exportRoster(Tenant $tenant, int $sectionId): StreamedResponse
    {
        $section = CourseSection::query()->with('offering')->findOrFail($sectionId);
        $member = $this->context->membership();
        abort_unless($member->status === 'active', 403);
        $effectiveCampusId = $section->campus_id ?? $section->offering->campus_id;
        if ($member->campus_id && $effectiveCampusId && (int) $member->campus_id !== (int) $effectiveCampusId) {
            abort(404);
        }
        $assigned = DB::table('course_section_instructors')->where('tenant_id', $member->tenant_id)->where('section_id', $section->getKey())->where('user_id', auth()->id())->exists();
        abort_unless($assigned || $member->hasPermission('enrollment.registrar.view'), 403);

        return response()->streamDownload(function () use ($section): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['student_number', 'student_name', 'enrollment_status']);
            CourseEnrollment::query()->with('student:id,tenant_id,student_number,legal_first_name,legal_last_name')->where('section_id', $section->getKey())
                ->where('status', 'confirmed')->orderBy('id')->chunk(200, function ($enrollments) use ($output): void {
                    foreach ($enrollments as $enrollment) {
                        $name = trim($enrollment->student->legal_first_name.' '.$enrollment->student->legal_last_name);
                        fputcsv($output, [$this->safeCsvCell($enrollment->student->student_number), $this->safeCsvCell($name), 'confirmed']);
                    }
                });
            fclose($output);
        }, 'section-roster-'.$section->section_code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
