<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Sis\AdmissionToSisHandoff;
use App\Domain\Sis\SisBulkPromotionWorkflow;
use App\Domain\Sis\SisGuardianIdentityInvitationWorkflow;
use App\Domain\Sis\SisPromotionApprovalWorkflow;
use App\Domain\Sis\SisStudentCsvImport;
use App\Domain\Sis\SisStudentIdentityInvitationWorkflow;
use App\Domain\Sis\SisStudentIdentityReconciliationWorkflow;
use App\Domain\Sis\SisStudentLifecycleApprovalWorkflow;
use App\Domain\Sis\SisStudentRegistry;
use App\Domain\Sis\SisStudentTransferWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSisStudentImportBatch;
use App\Jobs\ScanSisStudentDocument;
use App\Mail\TenantInvitationMail;
use App\Models\AcademicClass;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AcademicSection;
use App\Models\AcademicYear;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentVersion;
use App\Models\AdmissionSisHandoff;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisCustomFieldDefinition;
use App\Models\SisFamilyGroup;
use App\Models\SisFamilyStudent;
use App\Models\SisIdentityReconciliationCase;
use App\Models\SisPrivateDocument;
use App\Models\SisPrivateDocumentScanEvent;
use App\Models\SisPrivateDocumentVersion;
use App\Models\SisRegistrySetting;
use App\Models\SisStudent;
use App\Models\SisStudentCustomValue;
use App\Models\SisStudentDocument;
use App\Models\SisStudentGuardian;
use App\Models\SisStudentImportBatch;
use App\Models\SisStudentLifecycleRequest;
use App\Models\SisStudentPlacement;
use App\Models\SisStudentPromotionBatch;
use App\Models\SisStudentPromotionHold;
use App\Models\SisStudentTransferCase;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SisStudentController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AcademicAccess $academicAccess) {}

    public function dashboard(Request $request): Response
    {
        $institution = Institution::query()->firstOrFail();
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query
                ->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
        ]);
        $registrySettings = SisRegistrySetting::query()->firstOrNew(
            ['institution_id' => $institution->getKey()],
            ['student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6, 'next_sequence' => 1, 'suspend_membership_on_student_suspension' => true],
        );
        $query = SisStudent::query()->where('institution_id', $institution->getKey());
        $this->applyCampusScope($query);
        $query->when($filters['from'] ?? null, fn (Builder $students, string $from) => $students->whereDate('admission_date', '>=', $from));
        $query->when($filters['until'] ?? null, fn (Builder $students, string $until) => $students->whereDate('admission_date', '<=', $until));
        $query->when($filters['academic_year_id'] ?? null, function (Builder $students, int $yearId): void {
            $students->whereHas('placements', function (Builder $placements) use ($yearId): void {
                $placements->where('academic_year_id', $yearId)->whereNull('effective_until');
                if ($this->academicAccess->isCampusScoped()) {
                    $campusId = $this->academicAccess->campusId();
                    $placements->where(fn (Builder $scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $campusId));
                }
            });
        });
        $stats = [
            'total' => (clone $query)->count(),
            'admitted' => (clone $query)->where('status', 'admitted')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'onLeave' => (clone $query)->where('status', 'on_leave')->count(),
            'suspended' => (clone $query)->where('status', 'suspended')->count(),
            'graduated' => (clone $query)->where('status', 'graduated')->count(),
        ];
        $campusCounts = (clone $query)->select('home_campus_id')->selectRaw('COUNT(*) AS student_count')
            ->groupBy('home_campus_id')->get();
        $campusIds = $campusCounts->pluck('home_campus_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $campusNames = $campusIds->isEmpty() ? collect() : Campus::query()
            ->where('institution_id', $institution->getKey())->whereIn('id', $campusIds)->pluck('name', 'id');
        $campusDistribution = $campusCounts->map(fn ($row): array => [
            'name' => $row->getAttribute('home_campus_id') === null
                ? 'Unassigned campus'
                : (string) ($campusNames[$row->getAttribute('home_campus_id')] ?? 'Unavailable campus'),
            'students' => (int) $row->getAttribute('student_count'),
        ])->values();

        $currentPlacements = SisStudentPlacement::query()->where('institution_id', $institution->getKey())
            ->where('status', 'active')->whereNull('effective_until')->whereIn('student_id', (clone $query)->select('id'));
        if (isset($filters['academic_year_id'])) {
            $currentPlacements->where('academic_year_id', $filters['academic_year_id']);
        }
        if ($this->academicAccess->isCampusScoped()) {
            $campusId = $this->academicAccess->campusId();
            $currentPlacements->where(fn (Builder $placements) => $placements->whereNull('campus_id')->orWhere('campus_id', $campusId));
        }
        $programCounts = (clone $currentPlacements)->whereNotNull('academic_program_id')
            ->select('academic_program_id')->selectRaw('COUNT(DISTINCT student_id) AS student_count')
            ->groupBy('academic_program_id')->orderByDesc('student_count')->limit(8)->get();
        $programIds = $programCounts->pluck('academic_program_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $programNames = $programIds->isEmpty() ? collect() : AcademicProgram::query()
            ->where('institution_id', $institution->getKey())->whereIn('id', $programIds)->pluck('title', 'id');
        $programDistribution = $programCounts->map(fn ($row): array => [
            'name' => (string) ($programNames[$row->getAttribute('academic_program_id')] ?? 'Unavailable program'),
            'students' => (int) $row->getAttribute('student_count'),
        ])->values();

        $transferQuery = SisStudentTransferCase::query()
            ->where('tenant_id', $this->context->tenant()->getKey())
            ->whereIn('student_id', (clone $query)->select('id'));
        $transferQuery->when($filters['from'] ?? null, fn (Builder $transfers, string $from) => $transfers->whereDate('created_at', '>=', $from));
        $transferQuery->when($filters['until'] ?? null, fn (Builder $transfers, string $until) => $transfers->whereDate('created_at', '<=', $until));
        $transferCounts = $transferQuery->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        $promotionQuery = SisStudentPlacement::query()->where('institution_id', $institution->getKey())
            ->where('placement_type', 'promotion')->whereIn('student_id', (clone $query)->select('id'));
        $promotionQuery->when($filters['from'] ?? null, fn (Builder $promotions, string $from) => $promotions->whereDate('effective_from', '>=', $from));
        $promotionQuery->when($filters['until'] ?? null, fn (Builder $promotions, string $until) => $promotions->whereDate('effective_from', '<=', $until));

        $guardianQuery = SisStudentGuardian::query()->whereIn('student_id', (clone $query)->select('id'));
        $guardianQuery->when($filters['from'] ?? null, fn (Builder $guardians, string $from) => $guardians->whereDate('created_at', '>=', $from));
        $guardianQuery->when($filters['until'] ?? null, fn (Builder $guardians, string $until) => $guardians->whereDate('created_at', '<=', $until));
        $guardianCounts = $guardianQuery->select('authority_status')->selectRaw('COUNT(*) AS total')->groupBy('authority_status')->pluck('total', 'authority_status');
        $recent = (clone $query)->with('homeCampus:id,name')->orderByDesc('created_at')->limit(8)->get([
            'id', 'student_number', 'legal_first_name', 'legal_last_name', 'preferred_name', 'status', 'home_campus_id', 'admission_date',
        ])->map(fn (SisStudent $student): array => $this->studentSummary($student));

        return Inertia::render('workspace/sis/index', [
            'tenant' => ['slug' => $this->context->tenant()->slug], 'institution' => ['name' => $institution->display_name, 'studentLabel' => $registrySettings->student_label],
            'registrySettings' => [
                'studentLabel' => $registrySettings->student_label,
                'numberPrefix' => $registrySettings->number_prefix,
                'numberWidth' => $registrySettings->number_width,
                'documentRetentionDays' => $registrySettings->document_retention_days,
                'documentArchiveAfterDays' => $registrySettings->document_archive_after_days,
                'documentPurgeAfterDays' => $registrySettings->document_purge_after_days,
                'suspendMembershipOnStudentSuspension' => $registrySettings->suspend_membership_on_student_suspension ?? true,
                'promotionRequiresApproval' => (bool) ($registrySettings->promotion_requires_approval ?? false),
                'lifecycleRequiresApproval' => (bool) ($registrySettings->lifecycle_requires_approval ?? false),
                'hasStudents' => SisStudent::query()->where('institution_id', $institution->getKey())->exists(),
            ],
            'filters' => $filters,
            'academicOptions' => $this->academicOptions($institution),
            'stats' => $stats, 'recentStudents' => $recent,
            'analytics' => [
                'campuses' => $campusDistribution,
                'programs' => $programDistribution,
                'transfers' => [
                    'pendingReview' => (int) ($transferCounts['pending_review'] ?? 0),
                    'approved' => (int) ($transferCounts['approved'] ?? 0),
                    'completed' => (int) ($transferCounts['completed'] ?? 0),
                    'rejected' => (int) ($transferCounts['rejected'] ?? 0),
                ],
                'promotions' => $promotionQuery->count(),
                'guardians' => [
                    'pending' => (int) ($guardianCounts['pending'] ?? 0),
                    'verified' => (int) ($guardianCounts['verified'] ?? 0),
                    'revoked' => (int) ($guardianCounts['revoked'] ?? 0),
                ],
            ],
            'permissions' => ['create' => $this->can('sis.students.create'), 'export' => $this->can('sis.export'), 'import' => $this->can('sis.import'), 'settings' => $this->can('sis.settings.manage')],
            'lifecycleApproval' => $this->lifecycleApprovalQueue($institution),
            'canReviewLifecycle' => $this->can('sis.lifecycle.approve'),
        ]);
    }

    public function updateRegistrySettings(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'student_label' => ['required', 'string', 'max:32'],
            'number_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
            'number_width' => ['required', 'integer', 'between:4,12'],
            'document_retention_days' => ['nullable', 'integer', 'between:1,36500'],
            'document_archive_after_days' => ['nullable', 'integer', 'between:1,36500'],
            'document_purge_after_days' => ['nullable', 'integer', 'between:1,36500'],
            'suspend_membership_on_student_suspension' => ['sometimes', 'boolean'],
            'promotion_requires_approval' => ['sometimes', 'boolean'],
            'lifecycle_requires_approval' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['document_archive_after_days'], $data['document_purge_after_days'])
            && (int) $data['document_purge_after_days'] < (int) $data['document_archive_after_days']) {
            throw ValidationException::withMessages(['document_purge_after_days' => 'Purge must be scheduled no earlier than archival.']);
        }
        if (isset($data['document_purge_after_days']) && ! isset($data['document_archive_after_days'])) {
            throw ValidationException::withMessages(['document_archive_after_days' => 'Configure archival before enabling automatic purge.']);
        }
        $institution = Institution::query()->firstOrFail();

        DB::transaction(function () use ($data, $institution, $request): void {
            $lockedInstitution = Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            $settings = SisRegistrySetting::query()->firstOrCreate(
                ['institution_id' => $lockedInstitution->getKey()],
                ['student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6, 'next_sequence' => 1, 'suspend_membership_on_student_suspension' => true],
            );
            $numberingChanged = Str::upper($data['number_prefix']) !== $settings->number_prefix || (int) $data['number_width'] !== (int) $settings->number_width;
            if ($numberingChanged && SisStudent::query()->where('institution_id', $lockedInstitution->getKey())->exists()) {
                throw ValidationException::withMessages(['number_prefix' => 'Student numbering can only be changed before the first student record is created.']);
            }
            $settings->forceFill([
                'student_label' => trim($data['student_label']),
                'number_prefix' => Str::upper($data['number_prefix']),
                'number_width' => (int) $data['number_width'],
                'document_retention_days' => isset($data['document_retention_days']) ? (int) $data['document_retention_days'] : null,
                'document_archive_after_days' => isset($data['document_archive_after_days']) ? (int) $data['document_archive_after_days'] : null,
                'document_purge_after_days' => isset($data['document_purge_after_days']) ? (int) $data['document_purge_after_days'] : null,
                'suspend_membership_on_student_suspension' => array_key_exists('suspend_membership_on_student_suspension', $data)
                    ? (bool) $data['suspend_membership_on_student_suspension']
                    : (bool) ($settings->suspend_membership_on_student_suspension ?? true),
                'promotion_requires_approval' => array_key_exists('promotion_requires_approval', $data)
                    ? (bool) $data['promotion_requires_approval']
                    : (bool) ($settings->promotion_requires_approval ?? false),
                'lifecycle_requires_approval' => array_key_exists('lifecycle_requires_approval', $data)
                    ? (bool) $data['lifecycle_requires_approval']
                    : (bool) ($settings->lifecycle_requires_approval ?? false),
            ])->save();
            app(AuditRecorder::class)->record('sis.registry.settings_updated', $request->user(), (int) $this->context->tenant()->getKey(), $settings, [
                'student_label' => $settings->student_label,
                'number_prefix' => $settings->number_prefix,
                'number_width' => $settings->number_width,
                'document_retention_days' => $settings->document_retention_days,
                'document_archive_after_days' => $settings->document_archive_after_days,
                'document_purge_after_days' => $settings->document_purge_after_days,
                'suspend_membership_on_student_suspension' => (bool) $settings->suspend_membership_on_student_suspension,
                'promotion_requires_approval' => (bool) $settings->promotion_requires_approval,
                'lifecycle_requires_approval' => (bool) $settings->lifecycle_requires_approval,
            ]);
        }, 3);

        return to_route('tenant.sis.index', ['tenant' => $this->context->tenant()->slug])->with('status', 'Student registry settings saved.');
    }

    public function directory(Request $request): Response
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['pre_admitted', 'admitted', 'active', 'on_leave', 'suspended', 'transferred', 'withdrawn', 'graduated', 'alumni', 'archived'])],
            'campus_id' => ['nullable', 'integer'], 'student_type' => ['nullable', 'string', 'max:48'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_class_id' => ['nullable', 'integer', Rule::exists('academic_classes', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'academic_section_id' => ['nullable', 'integer', Rule::exists('academic_sections', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey()))],
            'custom_field_id' => ['nullable', 'integer', Rule::exists('sis_custom_field_definitions', 'id')->where(fn ($query) => $query
                ->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey())
                ->where('is_sensitive', false)->where('status', 'active'))],
            'custom_field_value' => ['nullable', 'string', 'max:180', 'required_with:custom_field_id'],
            'sort' => ['nullable', Rule::in(['name', 'admission_date', 'student_number'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = SisStudent::query()->where('institution_id', $institution->getKey())->with(['homeCampus:id,name,code']);
        $this->applyCampusScope($query);
        $query->when($data['q'] ?? null, function (Builder $students, string $term): void {
            $students->where(function (Builder $search) use ($term): void {
                $search->where('student_number', 'like', '%'.$term.'%')->orWhere('registration_reference', 'like', '%'.$term.'%')
                    ->orWhere('legal_first_name', 'like', '%'.$term.'%')->orWhere('legal_middle_name', 'like', '%'.$term.'%')->orWhere('legal_last_name', 'like', '%'.$term.'%')->orWhere('preferred_name', 'like', '%'.$term.'%');
            });
        });
        $query->when($data['status'] ?? null, fn (Builder $students, string $status) => $students->where('status', $status));
        $query->when($data['campus_id'] ?? null, fn (Builder $students, int $campusId) => $students->where('home_campus_id', $campusId));
        $query->when($data['student_type'] ?? null, fn (Builder $students, string $type) => $students->where('student_type', $type));
        $hasPlacementFilter = collect(['academic_year_id', 'academic_program_id', 'academic_level_id', 'academic_class_id', 'academic_section_id'])
            ->contains(fn (string $key): bool => isset($data[$key]));
        if ($hasPlacementFilter) {
            $query->whereHas('placements', function (Builder $placements) use ($data): void {
                $placements->whereNull('effective_until');
                foreach (['academic_year_id', 'academic_program_id', 'academic_level_id', 'academic_class_id', 'academic_section_id'] as $key) {
                    if (isset($data[$key])) {
                        $placements->where($key, (int) $data[$key]);
                    }
                }
                if ($this->academicAccess->isCampusScoped()) {
                    $campusId = $this->academicAccess->campusId();
                    $placements->where(fn (Builder $scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $campusId));
                }
            });
        }
        $query->when($data['custom_field_id'] ?? null, function (Builder $students, int $fieldId) use ($data): void {
            $students->whereHas('customValues', fn (Builder $values) => $values
                ->where('field_definition_id', $fieldId)->where('value->value', $data['custom_field_value'])
                ->whereHas('definition', fn (Builder $definition) => $definition->where('is_sensitive', false)->where('status', 'active')));
        });
        $sort = $data['sort'] ?? 'name';
        if ($sort === 'name') {
            $query->orderBy('legal_last_name', $data['direction'] ?? 'asc')->orderBy('legal_first_name');
        } else {
            $query->orderBy($sort, $data['direction'] ?? 'asc');
        }
        $students = $query->paginate(25)->withQueryString()->through(fn (SisStudent $student): array => $this->studentSummary($student));
        $promotionPreview = $request->session()->get($this->bulkPromotionSessionKey());
        if (! is_array($promotionPreview) || (int) ($promotionPreview['expires_at'] ?? 0) < now()->timestamp) {
            $promotionPreview = null;
        }
        $promotionApprovals = $this->can('sis.promotion.approve')
            ? SisStudentPromotionBatch::query()->where('institution_id', $institution->getKey())->where('status', 'pending')
                ->with(['requester:id,name', 'destinationClass:id,name', 'outcomes.student:id,student_number,legal_first_name,legal_last_name'])
                ->orderBy('requested_at')->limit(25)->get()->map(fn (SisStudentPromotionBatch $batch): array => [
                    'id' => (int) $batch->getKey(), 'status' => $batch->status,
                    'requestedBy' => ['id' => (int) $batch->requested_by, 'name' => $batch->requester?->name],
                    'requestedAt' => $batch->requested_at->toIso8601String(),
                    'destinationClass' => $batch->destinationClass?->name,
                    'effectiveFrom' => $batch->effective_from->toDateString(),
                    'reason' => $batch->reason, 'reasonCode' => $batch->reason_code,
                    'students' => $batch->outcomes->map(fn ($outcome): array => [
                        'id' => (int) $outcome->student_id,
                        'number' => $outcome->student?->student_number,
                        'name' => trim($outcome->student->legal_first_name.' '.$outcome->student->legal_last_name),
                    ])->values(),
                    'canDecide' => (int) $batch->requested_by !== (int) auth()->id(),
                ])->values()
            : collect();

        return Inertia::render('workspace/sis/students/index', [
            'tenant' => ['slug' => $this->context->tenant()->slug], 'students' => $students,
            'filters' => $data, 'campuses' => $this->campusOptions($institution),
            'academicOptions' => $this->academicOptions($institution),
            'filterCustomFields' => SisCustomFieldDefinition::query()->where('institution_id', $institution->getKey())
                ->where('status', 'active')->where('is_sensitive', false)->orderBy('label')->get(['id', 'field_key', 'label', 'field_type'])
                ->map(fn (SisCustomFieldDefinition $field): array => ['id' => (int) $field->getKey(), 'key' => $field->field_key, 'label' => $field->label, 'type' => $field->field_type])->all(),
            'importBatches' => $this->can('sis.import')
                ? SisStudentImportBatch::query()->where('institution_id', $institution->getKey())->orderByDesc('id')->limit(5)->get()
                    ->map(function (SisStudentImportBatch $batch): array {
                        $report = $batch->safe_report;
                        $hasErrors = ! empty($report['errors'] ?? []);

                        return [
                            'reference' => $batch->batch_reference, 'status' => $batch->status,
                            'rowCount' => (int) $batch->row_count, 'processedCount' => (int) $batch->processed_count,
                            'invalidCount' => (int) $batch->invalid_count,
                            'errorsUrl' => $hasErrors ? route('tenant.sis.students.import-errors', ['tenant' => $this->context->tenant()->slug, 'batchReference' => $batch->batch_reference]) : null,
                        ];
                    })->values()
                : [],
            'bulkPromotionPreview' => $promotionPreview === null ? null : [
                'token' => $promotionPreview['token'], 'count' => $promotionPreview['count'], 'expires_at' => $promotionPreview['expires_at'],
            ],
            'promotionApprovals' => $promotionApprovals,
            'promotionApprovalRequired' => (bool) SisRegistrySetting::query()->where('institution_id', $institution->getKey())->value('promotion_requires_approval'),
            'permissions' => [
                'create' => $this->can('sis.students.create'), 'export' => $this->can('sis.export'),
                'import' => $this->can('sis.import'), 'promotion' => $this->can('sis.placement.manage'),
                'approvePromotions' => $this->can('sis.promotion.approve'),
            ],
        ]);
    }

    public function store(Request $request, SisStudentRegistry $registry): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'home_campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'registration_reference' => ['nullable', 'string', 'max:80', Rule::unique('sis_students', 'registration_reference')->where(fn ($query) => $query->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'legal_first_name' => ['required', 'string', 'max:100'], 'legal_middle_name' => ['nullable', 'string', 'max:100'], 'legal_last_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:120'], 'localized_names' => ['nullable', 'array', 'max:8'], 'localized_names.*' => ['nullable', 'string', 'max:180'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'], 'admission_date' => ['required', 'date'], 'student_type' => ['nullable', 'string', 'max:48'],
            'previous_institution_name' => ['nullable', 'string', 'max:180'], 'previous_institution_reference' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:180'], 'emergency_contact_phone' => ['nullable', 'string', 'max:40'], 'emergency_contact_relationship' => ['nullable', 'string', 'max:48'],
        ]);
        $student = $registry->create($data, $request->user());

        return to_route('tenant.sis.students.show', ['tenant' => $this->context->tenant()->slug, 'studentId' => $student->getKey()])->with('status', 'Student registered.');
    }

    public function show(Tenant $tenant, int $studentId): Response
    {
        $student = $this->student($studentId)->load(['homeCampus:id,name,code', 'user:id,name,email']);
        $canManagePromotionHolds = $this->can('sis.promotion.holds.manage');
        $promotionHolds = SisStudentPromotionHold::query()->where('student_id', $student->getKey())
            ->when(! $canManagePromotionHolds, fn (Builder $holds) => $holds->whereRaw('1 = 0'))
            ->orderByDesc('created_at')->limit(20)->get();
        $placements = $student->placements()->with(['academicClass:id,name,code', 'academicSection:id,name,code', 'academicProgram:id,title,code', 'academicYear:id,name,code'])->get();
        $guardians = $student->guardians()->with('guardian:id,name,email')->get();
        $customValues = $student->customValues()->with('definition')->get();
        $documents = $this->can('sis.documents.view')
            ? $student->documents()->with('admissionDocument.requirement')->get()
            : collect();
        $privateDocuments = $this->can('sis.documents.view')
            ? SisPrivateDocument::query()->where('student_id', $student->getKey())->with('versions')->orderByDesc('updated_at')->get()
            : collect();
        $transferEvidenceOptions = $this->can('sis.transfers.manage') && $this->can('sis.documents.view')
            ? $privateDocuments->filter(fn (SisPrivateDocument $document): bool => $document->category === 'transfer_certificate'
                && $document->scan_status === 'clean'
                && $document->versions->contains(fn (SisPrivateDocumentVersion $version): bool => (int) $version->version_number === (int) $document->current_version))
                ->map(fn (SisPrivateDocument $document): array => [
                    'id' => (int) $document->getKey(),
                    'name' => $document->versions->firstWhere('version_number', $document->current_version)->original_name,
                ])->values()
            : collect();
        $events = $student->lifecycleEvents()->orderByDesc('effective_at')->limit(100)->get();
        $studentAccountMembership = $student->user_id === null
            ? null
            : TenantMembership::query()->where('tenant_id', $this->context->tenant()->getKey())
                ->where('institution_id', $student->institution_id)->where('user_id', $student->user_id)->first();
        $studentAccountAccessStatus = $student->user_id === null
            ? 'not_linked'
            : ($studentAccountMembership?->sis_student_suspension_student_id !== null
                && (int) $studentAccountMembership->sis_student_suspension_student_id === (int) $student->getKey()
                && $studentAccountMembership->status === 'suspended'
                ? 'suspended_by_student_lifecycle'
                : ($studentAccountMembership->status ?? 'no_institution_membership'));
        $familyGroups = $this->visibleFamilyGroupsQuery((int) $student->institution_id)
            ->whereHas('students', fn (Builder $members) => $members->where('student_id', $student->getKey())->whereNull('effective_until'))
            ->with(['students' => fn (HasMany $members) => $members->whereNull('effective_until')->with('student:id,student_number,legal_first_name,legal_last_name,home_campus_id')])
            ->get();
        $handoff = $student->admissionHandoff()->first();
        $transfers = $this->can('sis.transfers.manage') || $this->can('sis.transfers.approve')
            ? SisStudentTransferCase::query()->where('student_id', $student->getKey())->orderByDesc('requested_at')->get()
            : collect();
        $tenantId = (int) $this->context->tenant()->getKey();
        $guardianInvitations = $this->can('sis.guardians.verify') && $guardians->isNotEmpty()
            ? TenantInvitation::query()->where('tenant_id', $tenantId)
                ->whereIn('sis_student_guardian_id', $guardians->modelKeys())
                ->where('status', 'pending')->where('expires_at', '>', now())->get()->keyBy('sis_student_guardian_id')
            : collect();
        $identityCandidates = $this->can('sis.students.update')
            ? TenantMembership::query()->with('user:id,name,email')->where('tenant_id', $tenantId)
                ->where('institution_id', $student->institution_id)->where('status', 'active')
                ->when($this->academicAccess->isCampusScoped(), fn (Builder $members) => $members->where('campus_id', $this->academicAccess->campusId()))
                ->whereNotExists(fn ($linked) => $linked->selectRaw('1')->from('sis_students')
                    ->whereColumn('sis_students.user_id', 'tenant_memberships.user_id')
                    ->where('sis_students.tenant_id', $tenantId))
                ->orderBy('id')->limit(100)->get()
                ->map(fn (TenantMembership $membership): array => ['id' => $membership->user_id, 'name' => $membership->user?->name, 'email' => $membership->user?->email])
            : collect();
        $identityInvitation = $this->can('sis.students.update') && $student->user_id === null
            ? TenantInvitation::query()->where('tenant_id', $tenantId)->where('sis_student_id', $student->getKey())
                ->where('status', 'pending')->where('expires_at', '>', now())->latest()->first()
            : null;
        $identityReconciliationCases = $this->can('sis.identity.reconcile.request') || $this->can('sis.identity.reconcile.approve')
            ? SisIdentityReconciliationCase::query()->where('student_id', $student->getKey())
                ->with(['currentUser:id,name,email', 'proposedUser:id,name,email', 'requester:id,name', 'reviewer:id,name'])
                ->orderByDesc('requested_at')->limit(20)->get()
            : collect();
        $currentActorId = (int) auth()->id();

        $guardianEmails = $guardians->filter(fn (SisStudentGuardian $guardian): bool => in_array($guardian->authority_status, ['pending', 'verified'], true)
            && $guardian->revoked_at === null && $guardian->guardian_user_id === null)->pluck('guardian_email')->filter()
            ->map(fn ($email): string => Str::lower(trim((string) $email)))->unique()->values()->all();
        $guardianAccountCandidates = $this->can('sis.guardians.verify') && $guardianEmails !== []
            ? TenantMembership::query()->with('user:id,name,email,email_verified_at')->where('tenant_id', $tenantId)
                ->where('institution_id', $student->institution_id)->where('status', 'active')
                ->when($this->academicAccess->isCampusScoped(), fn (Builder $members) => $members->where('campus_id', $this->academicAccess->campusId()))
                ->whereHas('user', fn (Builder $users) => $users->whereNotNull('email_verified_at')->whereIn(DB::raw('LOWER(email)'), $guardianEmails))
                ->get()->map(fn (TenantMembership $membership): array => [
                    'id' => (int) $membership->user_id, 'name' => $membership->user?->name,
                    'email' => $membership->user?->email, 'normalizedEmail' => Str::lower(trim((string) $membership->user?->email)),
                ])->groupBy('normalizedEmail')
            : collect();
        $placements = $placements->map(fn ($placement): array => [
            'id' => $placement->getKey(), 'type' => $placement->placement_type, 'status' => $placement->status,
            'campusId' => $placement->campus_id, 'year' => $placement->academicYear?->name,
            'program' => $placement->academicProgram?->title, 'class' => $placement->academicClass?->name,
            'section' => $placement->academicSection?->name, 'effectiveFrom' => Carbon::parse($placement->effective_from)->toDateString(),
            'effectiveUntil' => $placement->effective_until ? Carbon::parse($placement->effective_until)->toDateString() : null, 'reason' => $placement->reason,
        ]);

        return Inertia::render('workspace/sis/students/show', [
            'tenant' => ['slug' => $this->context->tenant()->slug],
            'student' => [...$this->studentSummary($student), 'institutionId' => $student->institution_id, 'preferredName' => $student->preferred_name,
                'legalMiddleName' => $student->legal_middle_name, 'localizedNames' => $student->localized_names,
                'dateOfBirth' => $student->date_of_birth ? Carbon::parse($student->date_of_birth)->toDateString() : null, 'studentType' => $student->student_type,
                'emergencyContact' => ['name' => $student->emergency_contact_name, 'phone' => $student->emergency_contact_phone, 'relationship' => $student->emergency_contact_relationship],
                'previousInstitution' => ['name' => $student->previous_institution_name, 'reference' => $student->previous_institution_reference],
                'user' => $student->user?->only(['id', 'name', 'email']), 'handoff' => $handoff?->only(['id', 'status', 'processed_at']),
                'accountAccessStatus' => $studentAccountAccessStatus,
                'guardians' => $guardians->map(fn (SisStudentGuardian $guardian): array => [
                    'id' => $guardian->getKey(), 'name' => $guardian->guardian_name, 'email' => $guardian->guardian_email,
                    'identityUser' => $guardian->guardian?->only(['id', 'name', 'email']),
                    'identityInvitation' => $guardianInvitations->get($guardian->getKey()) ? [
                        'email' => $guardianInvitations->get($guardian->getKey())->email,
                        'expiresAt' => $guardianInvitations->get($guardian->getKey())->expires_at->toIso8601String(),
                    ] : null,
                    'phone' => $guardian->guardian_phone, 'relationship' => $guardian->relationship, 'authorityStatus' => $guardian->authority_status,
                    'primary' => $guardian->is_primary_contact, 'emergency' => $guardian->is_emergency_contact,
                    'canViewProfile' => $guardian->can_view_profile, 'verifiedAt' => $guardian->verified_at ? Carbon::parse($guardian->verified_at)->toIso8601String() : null, 'revokedAt' => $guardian->revoked_at ? Carbon::parse($guardian->revoked_at)->toIso8601String() : null,
                    'identityCandidates' => in_array($guardian->authority_status, ['pending', 'verified'], true)
                        && $guardian->revoked_at === null && $guardian->guardian_user_id === null
                        ? $guardianAccountCandidates->get(Str::lower(trim((string) $guardian->guardian_email)), collect())
                            ->map(fn (array $candidate): array => ['id' => $candidate['id'], 'name' => $candidate['name'], 'email' => $candidate['email']])->values()->all()
                        : [],
                ]),
                'placements' => $placements,
                'promotionHolds' => $promotionHolds->map(fn ($hold): array => [
                    'id' => (int) $hold->getKey(), 'status' => $hold->status, 'reasonCode' => $hold->reason_code,
                    'reason' => $hold->reason, 'evidenceReference' => $hold->evidence_reference,
                    'createdAt' => $hold->created_at ? Carbon::parse($hold->created_at)->toIso8601String() : null,
                    'releasedAt' => $hold->released_at ? Carbon::parse($hold->released_at)->toIso8601String() : null,
                    'releaseReason' => $hold->release_reason,
                ])->values(),
                'transfers' => $transfers->map(fn (SisStudentTransferCase $transfer): array => [
                    'id' => (int) $transfer->getKey(), 'direction' => $transfer->direction, 'status' => $transfer->status,
                    'externalInstitutionName' => $transfer->external_institution_name,
                    'externalInstitutionCode' => $transfer->external_institution_code,
                    'externalCountryCode' => $transfer->external_country_code,
                    'effectiveOn' => Carbon::parse($transfer->requested_effective_on)->toDateString(),
                    'reason' => $transfer->request_reason, 'reviewDecision' => $transfer->review_decision,
                    'reviewNote' => $transfer->review_note, 'completionNote' => $transfer->completion_note,
                    'requestedBy' => (int) $transfer->requested_by,
                    'canReview' => $this->can('sis.transfers.approve') && (int) $transfer->requested_by !== (int) auth()->id(),
                    'canComplete' => $this->can('sis.transfers.approve') && $transfer->status === 'approved'
                        && Carbon::parse($transfer->requested_effective_on)->startOfDay()->lte(today()),
                ])->values(),
                'transferEvidenceOptions' => $transferEvidenceOptions,
                'customFields' => $customValues->filter(fn (SisStudentCustomValue $value): bool => ! $value->definition?->is_sensitive || $this->can('sis.custom-fields.sensitive'))->map(function (SisStudentCustomValue $value): array {
                    $storedValue = $value->getAttribute('value');
                    $stored = is_array($storedValue) ? $storedValue : [];
                    $payload = $stored['value'] ?? null;
                    if ($value->definition?->is_sensitive && $this->can('sis.custom-fields.sensitive') && isset($stored['encrypted_payload'])) {
                        try {
                            $payload = json_decode(Crypt::decryptString($stored['encrypted_payload']), true, flags: JSON_THROW_ON_ERROR)['value'] ?? null;
                        } catch (\Throwable) {
                            $payload = null;
                        }
                    }

                    return ['key' => $value->definition?->field_key, 'label' => $value->definition?->label, 'value' => $payload, 'sensitive' => $value->definition?->is_sensitive];
                })->values(),
                'customFieldDefinitions' => SisCustomFieldDefinition::query()->where('institution_id', $student->institution_id)
                    ->when(! $this->can('sis.custom-fields.sensitive'), fn (Builder $fields) => $fields->where('is_sensitive', false))
                    ->orderBy('label')->get(['id', 'field_key', 'label', 'field_type', 'is_required', 'is_sensitive', 'options']),
                'documents' => $documents->map(fn (SisStudentDocument $link): array => [
                    'id' => $link->getKey(), 'source' => 'admissions', 'category' => $link->category,
                    'name' => $link->admissionDocument->requirement->name,
                    'scanStatus' => $link->admissionDocument->scan_status,
                    'downloadUrl' => $link->admissionDocument->scan_status === 'clean' ? route('tenant.sis.documents.download', ['tenant' => $this->context->tenant()->slug, 'documentLinkId' => $link->getKey()]) : null,
                ])->concat($privateDocuments->map(function (SisPrivateDocument $document): array {
                    $version = $document->versions->firstWhere('version_number', $document->current_version);
                    $expiresAt = $version?->expires_at === null ? null : Carbon::parse($version->expires_at);
                    $isExpired = $expiresAt !== null && ! $expiresAt->isFuture();

                    return [
                        'id' => $document->getKey(), 'source' => 'sis', 'category' => $document->category,
                        'name' => $version->original_name ?? 'Student document', 'scanStatus' => $document->scan_status,
                        'version' => (int) $document->current_version,
                        'expiresAt' => $expiresAt?->toIso8601String(),
                        'expired' => $isExpired,
                        'downloadUrl' => $document->scan_status === 'clean' && $version && ! $isExpired
                            ? route('tenant.sis.private-documents.download', ['tenant' => $this->context->tenant()->slug, 'documentId' => $document->getKey()])
                            : null,
                    ];
                }))->values(),
            ],
            'events' => $events->map(fn ($event): array => ['id' => $event->getKey(), 'type' => $event->event_type, 'from' => $event->from_status, 'to' => $event->to_status, 'effectiveAt' => Carbon::parse($event->effective_at)->toIso8601String(), 'reasonCode' => $event->reason_code, 'reason' => $event->reason]),
            'familyGroups' => $familyGroups->map(function (SisFamilyGroup $group) use ($student): array {
                $members = $group->students->filter(fn (SisFamilyStudent $member): bool => $member->student !== null);
                $currentMembership = $members->first(fn (SisFamilyStudent $member): bool => (int) $member->student_id === (int) $student->getKey());

                return [
                    'id' => $group->getKey(), 'reference' => $group->family_reference, 'label' => $group->label,
                    'currentRelationshipLabel' => $currentMembership?->relationship_label,
                    'students' => $members->map(fn (SisFamilyStudent $member): array => [
                        'id' => $member->student->getKey(), 'number' => $member->student->student_number,
                        'name' => trim($member->student->legal_first_name.' '.$member->student->legal_last_name),
                    ])->values(),
                ];
            }),
            'options' => $this->academicOptions(Institution::query()->firstOrFail()),
            'identityCandidates' => $identityCandidates,
            'identityInvitation' => $identityInvitation ? [
                'email' => $identityInvitation->email,
                'expiresAt' => $identityInvitation->expires_at->toIso8601String(),
            ] : null,
            'identityReconciliations' => $identityReconciliationCases->map(fn (SisIdentityReconciliationCase $case): array => [
                'id' => (int) $case->getKey(), 'status' => $case->status,
                'currentIdentity' => $case->currentUser?->only(['id', 'name', 'email']),
                'proposedIdentity' => $case->proposedUser?->only(['id', 'name', 'email']),
                'requestedBy' => $case->requester?->name,
                'requestedAt' => $case->requested_at->toIso8601String(),
                'reasonCode' => $case->reason_code, 'requestReason' => $case->request_reason,
                'reviewedBy' => $case->reviewer?->name,
                'reviewedAt' => $case->reviewed_at?->toIso8601String(),
                'decisionReason' => $case->decision_reason,
                'canReview' => $this->can('sis.identity.reconcile.approve')
                    && $case->status === 'pending'
                    && (int) $case->requested_by !== $currentActorId
                    && (int) $case->current_user_id !== $currentActorId
                    && (int) $case->proposed_user_id !== $currentActorId,
            ])->values(),
            'permissions' => [
                'update' => $this->can('sis.students.update'), 'status' => $this->can('sis.students.status'), 'placement' => $this->can('sis.placement.manage'),
                'guardians' => $this->can('sis.guardians.manage'), 'verifyGuardian' => $this->can('sis.guardians.verify'), 'family' => $this->can('sis.family.manage'),
                'documents' => $this->can('sis.documents.view'), 'manageDocuments' => $this->can('sis.documents.manage'),
                'import' => $this->can('sis.import'), 'audit' => $this->can('sis.audit.view'),
                'customFields' => $this->can('sis.custom-fields.manage'),
                'sensitiveCustomFields' => $this->can('sis.custom-fields.sensitive'),
                'manageTransfers' => $this->can('sis.transfers.manage') && $this->can('sis.documents.view'),
                'approveTransfers' => $this->can('sis.transfers.approve'),
                'requestIdentityReconciliation' => $this->can('sis.identity.reconcile.request'),
                'managePromotionHolds' => $this->can('sis.promotion.holds.manage'),
                'correctPromotion' => $this->can('sis.promotion.correct'),
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate([
            'legal_first_name' => ['sometimes', 'required', 'string', 'max:100'], 'legal_middle_name' => ['nullable', 'string', 'max:100'],
            'legal_last_name' => ['sometimes', 'required', 'string', 'max:100'], 'preferred_name' => ['nullable', 'string', 'max:120'],
            'localized_names' => ['nullable', 'array', 'max:8'], 'localized_names.*' => ['nullable', 'string', 'max:180'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'], 'student_type' => ['sometimes', 'required', 'string', 'max:48'],
            'home_campus_id' => ['nullable', 'integer'], 'previous_institution_name' => ['nullable', 'string', 'max:180'],
            'previous_institution_reference' => ['nullable', 'string', 'max:120'], 'emergency_contact_name' => ['nullable', 'string', 'max:180'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'], 'emergency_contact_relationship' => ['nullable', 'string', 'max:48'],
        ]);
        if (array_key_exists('home_campus_id', $data)) {
            $campus = $data['home_campus_id'] === null ? null : Institution::query()->firstOrFail()->campuses()->whereKey($data['home_campus_id'])->firstOrFail();
            $this->academicAccess->assertCampus($campus?->getKey() === null ? null : (int) $campus->getKey());
        }
        $student->fill($data);
        $student->forceFill(['updated_by' => $request->user()->getKey()])->save();
        app(AuditRecorder::class)->record('sis.student.updated', $request->user(), (int) $this->context->tenant()->getKey(), $student, ['changed_fields' => array_values(array_intersect(array_keys($data), ['legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'date_of_birth', 'student_type', 'home_campus_id']))]);

        return $this->studentProfileRedirect((int) $student->getKey())->with('status', 'Student profile updated.');
    }

    public function status(Request $request, Tenant $tenant, int $studentId, SisStudentLifecycleApprovalWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pre_admitted', 'admitted', 'active', 'on_leave', 'suspended', 'transferred', 'withdrawn', 'graduated', 'alumni', 'archived'])], 'effective_at' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:48'], 'reason' => ['nullable', 'string', 'max:500']]);
        $pendingReview = $workflow->request($this->student($studentId), $data['status'], now()->parse($data['effective_at']), $data['reason_code'], $data['reason'] ?? null, $request->user());

        return $this->studentProfileRedirect($studentId)->with('status', $pendingReview
            ? 'The lifecycle transition is awaiting independent review.'
            : 'Student status updated with history.');
    }

    public function reviewLifecycleRequest(Request $request, Tenant $tenant, int $requestId, SisStudentLifecycleApprovalWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $institution = Institution::query()->firstOrFail();
        $lifecycleRequest = SisStudentLifecycleRequest::query()->with('student')
            ->where('institution_id', $institution->getKey())->findOrFail($requestId);
        if ($this->academicAccess->isCampusScoped()) {
            abort_unless((int) $lifecycleRequest->student->home_campus_id === (int) $this->academicAccess->campusId(), 404);
        }
        $result = $workflow->review($lifecycleRequest, $request->user(), $data['decision'], $data['reason'] ?? null);

        return to_route('tenant.sis.index', ['tenant' => $this->context->tenant()->slug])->with('status', match ($result) {
            'approved' => 'The lifecycle transition was approved and recorded.',
            'rejected' => 'The lifecycle transition request was rejected.',
            default => 'The lifecycle request became stale because the student status changed; submit a new request if needed.',
        });
    }

    public function placement(Request $request, Tenant $tenant, int $studentId, SisStudentRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['placement', 'transfer', 'retention'])], 'campus_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'], 'academic_period_id' => ['nullable', 'integer'], 'academic_program_id' => ['nullable', 'integer'],
            'academic_class_id' => ['nullable', 'integer'], 'academic_section_id' => ['nullable', 'integer'], 'effective_from' => ['required', 'date'],
            'reason_code' => ['required', 'string', 'max:48'], 'reason' => ['nullable', 'string', 'max:500'],
            'confirm' => ['sometimes', 'boolean'], 'preview_token' => ['nullable', 'string', 'max:80'],
        ]);
        $student = $this->student($studentId);
        $confirm = (bool) ($data['confirm'] ?? false);
        $previewPayload = array_diff_key($data, ['confirm' => true, 'preview_token' => true]);
        ksort($previewPayload);
        $payloadHash = hash('sha256', json_encode($previewPayload, JSON_THROW_ON_ERROR));
        if ($confirm) {
            $preview = $request->session()->get('sis.placement_preview.'.$student->getKey());
            abort_unless(is_array($preview) && hash_equals((string) ($preview['hash'] ?? ''), $payloadHash)
                && hash_equals((string) ($preview['token'] ?? ''), (string) ($data['preview_token'] ?? ''))
                && (int) ($preview['expires_at'] ?? 0) >= now()->timestamp, 409, 'Create a new placement preview before confirming this change.');
            $request->session()->forget('sis.placement_preview.'.$student->getKey());
            $registry->place($student, $previewPayload, $data['effective_from'], $data['reason_code'], $data['reason'] ?? null, $request->user(), $data['type']);

            return $this->studentProfileRedirect($studentId)->with('sisPlacementPreview', null)->with('status', ucfirst($data['type']).' saved with effective-dated history.');
        }

        $registry->place($student, $previewPayload, $data['effective_from'], $data['reason_code'], $data['reason'] ?? null, $request->user(), $data['type'], true);
        $token = Str::random(48);
        $request->session()->put('sis.placement_preview.'.$student->getKey(), ['hash' => $payloadHash, 'token' => $token, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return $this->studentProfileRedirect($studentId)->with('sisPlacementPreview', ['token' => $token, 'type' => $data['type'], 'effectiveFrom' => $data['effective_from']])
            ->with('status', 'Review the placement change, then confirm it within 10 minutes. No record was changed during preview.');
    }

    public function requestTransfer(Request $request, Tenant $tenant, int $studentId, SisStudentTransferWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'external_institution_name' => ['required', 'string', 'max:180'],
            'external_institution_code' => ['nullable', 'string', 'max:80'],
            'external_country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'requested_effective_on' => ['required', 'date'],
            'request_reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence_document_id' => ['required', 'integer'],
        ]);

        $workflow->request($this->student($studentId), $data, $request->user());

        return $this->studentProfileRedirect($studentId)->with('status', 'Transfer request submitted for independent review.');
    }

    public function reviewTransfer(Request $request, Tenant $tenant, int $transferId, SisStudentTransferWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $transfer = SisStudentTransferCase::query()->findOrFail($transferId);
        $workflow->decide($transfer, $data['decision'], $data['review_note'], $request->user());

        return $this->studentProfileRedirect((int) $transfer->student_id)->with('status', 'Transfer decision recorded.');
    }

    public function completeTransfer(Request $request, Tenant $tenant, int $transferId, SisStudentTransferWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['completion_note' => ['required', 'string', 'min:10', 'max:500']]);
        $transfer = SisStudentTransferCase::query()->findOrFail($transferId);
        $workflow->complete($transfer, $data['completion_note'], $request->user());

        return $this->studentProfileRedirect((int) $transfer->student_id)->with('status', 'Approved transfer completed and recorded in student history.');
    }

    public function bulkPromotion(Request $request, Tenant $tenant, SisBulkPromotionWorkflow $workflow, SisPromotionApprovalWorkflow $approvals): RedirectResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:50'], 'student_ids.*' => ['required', 'integer', 'distinct'],
            'campus_id' => ['nullable', 'integer'], 'academic_year_id' => ['nullable', 'integer'],
            'academic_period_id' => ['nullable', 'integer'], 'academic_program_id' => ['nullable', 'integer'],
            'academic_class_id' => ['required', 'integer'], 'academic_section_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:48'],
            'reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['sometimes', 'boolean'],
            'preview_token' => ['nullable', 'string', 'max:80'],
        ]);
        $payload = array_intersect_key($data, array_flip([
            'student_ids', 'campus_id', 'academic_year_id', 'academic_period_id', 'academic_program_id',
            'academic_class_id', 'academic_section_id', 'effective_from', 'reason_code', 'reason',
        ]));
        $payload['student_ids'] = array_values(array_unique(array_map('intval', $payload['student_ids'])));
        sort($payload['student_ids']);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $sessionKey = $this->bulkPromotionSessionKey();

        if ((bool) ($data['confirm'] ?? false)) {
            $preview = $request->session()->get($sessionKey);
            abort_unless(is_array($preview)
                && hash_equals((string) ($preview['hash'] ?? ''), $payloadHash)
                && hash_equals((string) ($preview['token'] ?? ''), (string) ($data['preview_token'] ?? ''))
                && (int) ($preview['expires_at'] ?? 0) >= now()->timestamp,
                409, 'Create a new promotion preview before confirming this change.');

            $approvalRequired = (bool) SisRegistrySetting::query()->where('institution_id', Institution::query()->value('id'))
                ->value('promotion_requires_approval');
            if ($approvalRequired) {
                $batch = $approvals->submit($payload, $request->user());
                $count = $batch->outcomes()->count();
                $request->session()->forget($sessionKey);

                return to_route('tenant.sis.students.index', ['tenant' => $tenant->slug])
                    ->with('status', "Promotion request #{$batch->getKey()} was submitted for independent approval for {$count} students.");
            }

            $count = $workflow->execute($payload, $request->user());
            $request->session()->forget($sessionKey);

            return to_route('tenant.sis.students.index', ['tenant' => $tenant->slug])
                ->with('status', "{$count} student promotions were recorded with effective-dated history.");
        }

        $result = $workflow->preview($payload, $request->user());
        $request->session()->put($sessionKey, [
            'hash' => $payloadHash, 'token' => Str::random(48), 'count' => $result['count'],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return to_route('tenant.sis.students.index', ['tenant' => $tenant->slug])
            ->with('status', "Promotion preview ready for {$result['count']} students. No records changed; confirm within 10 minutes.");
    }

    public function decidePromotionBatch(Request $request, Tenant $tenant, int $batchId, SisPromotionApprovalWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $batch = SisStudentPromotionBatch::query()->where('institution_id', Institution::query()->value('id'))->findOrFail($batchId);
        $count = $workflow->decide($batch, $data['decision'], $data['reason'], $request->user());

        return to_route('tenant.sis.students.index', ['tenant' => $tenant->slug])->with('status',
            $data['decision'] === 'approved' ? "Promotion request approved for {$count} students." : "Promotion request rejected for {$count} students.");
    }

    public function placePromotionHold(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence_reference' => ['nullable', 'string', 'max:160'],
        ]);
        $student = $this->student($studentId);

        DB::transaction(function () use ($student, $data, $request): void {
            $lockedStudent = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            $active = SisStudentPromotionHold::query()->where('student_id', $lockedStudent->getKey())
                ->where('status', 'active')->lockForUpdate()->exists();
            if ($active) {
                throw ValidationException::withMessages(['reason' => 'This student already has an active progression hold. Release it before creating another.']);
            }
            $hold = SisStudentPromotionHold::query()->create([
                'institution_id' => $lockedStudent->institution_id,
                'student_id' => $lockedStudent->getKey(), 'status' => 'active',
                'reason_code' => $data['reason_code'], 'reason' => trim($data['reason']),
                'evidence_reference' => isset($data['evidence_reference']) ? trim($data['evidence_reference']) : null,
                'created_by' => $request->user()->getKey(),
            ]);
            app(AuditRecorder::class)->record('sis.student.promotion_hold.created', $request->user(),
                (int) $this->context->tenant()->getKey(), $hold, [
                    'student_id' => (int) $lockedStudent->getKey(), 'reason_code' => $hold->reason_code,
                    'evidence_reference_present' => $hold->evidence_reference !== null,
                ]);
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Progression hold recorded. Promotion is now blocked until an authorized release.');
    }

    public function releasePromotionHold(Request $request, Tenant $tenant, int $studentId, int $holdId): RedirectResponse
    {
        $data = $request->validate(['release_reason' => ['required', 'string', 'min:10', 'max:500']]);
        $student = $this->student($studentId);

        DB::transaction(function () use ($student, $holdId, $data, $request): void {
            $lockedStudent = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            $hold = SisStudentPromotionHold::query()->where('student_id', $lockedStudent->getKey())
                ->lockForUpdate()->findOrFail($holdId);
            if ($hold->status !== 'active') {
                throw ValidationException::withMessages(['release_reason' => 'This progression hold has already been released.']);
            }
            $hold->forceFill([
                'status' => 'released', 'released_by' => $request->user()->getKey(),
                'released_at' => now(), 'release_reason' => trim($data['release_reason']),
            ])->save();
            app(AuditRecorder::class)->record('sis.student.promotion_hold.released', $request->user(),
                (int) $this->context->tenant()->getKey(), $hold, [
                    'student_id' => (int) $lockedStudent->getKey(), 'reason_code' => $hold->reason_code,
                ]);
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Progression hold released with an auditable reason.');
    }

    public function correctPromotion(Request $request, Tenant $tenant, int $studentId, int $placementId, SisStudentRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'campus_id' => ['nullable', 'integer'], 'academic_year_id' => ['nullable', 'integer'],
            'academic_period_id' => ['nullable', 'integer'], 'academic_program_id' => ['nullable', 'integer'],
            'academic_class_id' => ['required', 'integer'], 'academic_section_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:48'],
            'reason' => ['required', 'string', 'min:10', 'max:500'], 'confirm' => ['sometimes', 'boolean'],
            'preview_token' => ['nullable', 'string', 'max:80'],
        ]);
        $student = $this->student($studentId);
        $currentPromotion = SisStudentPlacement::query()->where('student_id', $student->getKey())
            ->where('placement_type', 'promotion')->whereNull('effective_until')->findOrFail($placementId);
        abort_if(Carbon::parse($data['effective_from'])->lte(Carbon::parse($currentPromotion->effective_from)), 422,
            'A compensating correction must take effect after the current promotion date so the original record remains in the timeline.');
        $payload = array_diff_key($data, ['confirm' => true, 'preview_token' => true]);
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $sessionKey = 'sis.promotion_correction_preview.'.$this->context->tenant()->getKey().'.'.auth()->id().'.'.$student->getKey();

        if ((bool) ($data['confirm'] ?? false)) {
            $preview = $request->session()->get($sessionKey);
            abort_unless(is_array($preview) && hash_equals((string) ($preview['hash'] ?? ''), $hash)
                && hash_equals((string) ($preview['token'] ?? ''), (string) ($data['preview_token'] ?? ''))
                && (int) ($preview['expires_at'] ?? 0) >= now()->timestamp,
                409, 'Create a new correction preview before confirming this change.');
            $request->session()->forget($sessionKey);
            $registry->place($student, $payload, $data['effective_from'], $data['reason_code'], $data['reason'], $request->user(), 'correction');

            return $this->studentProfileRedirect($studentId)->with('sisCorrectionPreview', null)->with('status', 'Compensating promotion correction recorded; prior history is retained.');
        }

        $registry->place($student, $payload, $data['effective_from'], $data['reason_code'], $data['reason'], $request->user(), 'correction', true);
        $token = Str::random(48);
        $request->session()->put($sessionKey, ['hash' => $hash, 'token' => $token, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return $this->studentProfileRedirect($studentId)->with('sisCorrectionPreview', ['token' => $token, 'placementId' => $placementId]);
    }

    public function processHandoff(Request $request, Tenant $tenant, int $handoffId, AdmissionToSisHandoff $workflow): RedirectResponse
    {
        $handoff = AdmissionSisHandoff::query()->findOrFail($handoffId);
        $student = $workflow->process($handoff, $request->user());

        return to_route('tenant.sis.students.show', ['tenant' => $this->context->tenant()->slug, 'studentId' => $student->getKey()])->with('status', 'Approved applicant converted to a student record.');
    }

    public function linkIdentity(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query
                ->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $student->institution_id)->where('status', 'active'))],
        ]);
        DB::transaction(function () use ($data, $student, $request): void {
            $lockedStudent = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            $membership = TenantMembership::query()->where('user_id', $data['user_id'])->where('institution_id', $lockedStudent->institution_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->academicAccess->assertCampus($membership->campus_id === null ? null : (int) $membership->campus_id);
            $alreadyLinked = SisStudent::query()->where('user_id', $data['user_id'])->whereKeyNot($lockedStudent->getKey())->exists();
            if ($alreadyLinked || ($lockedStudent->user_id !== null && (int) $lockedStudent->user_id !== (int) $data['user_id'])) {
                throw ValidationException::withMessages(['user_id' => 'This identity is already linked or the existing link requires controlled reconciliation.']);
            }
            $lockedStudent->forceFill(['user_id' => $data['user_id'], 'updated_by' => $request->user()->getKey()])->save();
            $studentRole = Role::query()->where('tenant_id', $this->context->tenant()->getKey())->where('key', 'student')->where('scope', 'tenant')->firstOrFail();
            $membership->roles()->syncWithoutDetaching([$studentRole->getKey() => ['assigned_by' => $request->user()->getKey(), 'tenant_id' => $this->context->tenant()->getKey()]]);
            app(AuditRecorder::class)->record('sis.student.identity_linked', $request->user(), (int) $this->context->tenant()->getKey(), $lockedStudent, ['user_id' => (int) $data['user_id'], 'student_role_assigned' => true]);
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Existing institution identity linked.');
    }

    public function requestIdentityReconciliation(Request $request, Tenant $tenant, int $studentId, SisStudentIdentityReconciliationWorkflow $workflow): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate([
            'proposed_user_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query
                ->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $student->institution_id)->where('status', 'active'))],
            'reason_code' => ['required', Rule::in(['corrected_link', 'duplicate_record_review', 'verified_identity_update', 'other'])],
            'request_reason' => ['required', 'string', 'min:20', 'max:500'],
        ]);
        $workflow->request($student, (int) $data['proposed_user_id'], $data['reason_code'], $data['request_reason'], $request->user());

        return $this->studentProfileRedirect($studentId)->with('status', 'Identity correction request recorded for independent review.');
    }

    public function decideIdentityReconciliation(Request $request, Tenant $tenant, int $studentId, int $caseId, SisStudentIdentityReconciliationWorkflow $workflow): RedirectResponse
    {
        $this->student($studentId);
        $case = SisIdentityReconciliationCase::query()->where('student_id', $studentId)->findOrFail($caseId);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $result = $workflow->decide($case, $data['decision'], $data['decision_reason'], $request->user());

        $message = match ($result->status) {
            'approved' => 'Identity correction was independently approved and applied.',
            'rejected' => 'Identity correction request was rejected with a recorded reason.',
            'stale' => 'Identity correction could not be applied because its eligibility changed; submit a new reviewed request.',
            default => 'Identity correction review was recorded.',
        };

        return $this->studentProfileRedirect($studentId)->with('status', $message);
    }

    public function inviteIdentity(Request $request, Tenant $tenant, int $studentId, SisStudentIdentityInvitationWorkflow $workflow): RedirectResponse
    {
        $student = $this->student($studentId);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
        $data = $request->validate(['identity_email' => ['required', 'email', 'max:254']]);

        $mailIsTestFake = app()->runningUnitTests() && Mail::getFacadeRoot() instanceof MailFake;
        $mailIsLocalE2e = app()->environment('testing') && config('app.e2e_test_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) && ! $mailIsTestFake && ! $mailIsLocalE2e, 503, 'Invitation delivery is disabled until a real mail transport is configured.');

        $result = $workflow->issue($student, $data['identity_email'], $request->user());
        $url = URL::temporarySignedRoute('invitations.show', $result->invitation->expires_at, ['token' => $result->rawToken]);
        Mail::to($result->invitation->email)->send(new TenantInvitationMail($this->context->tenant()->name, $request->user()->name, $url));

        $response = $this->studentProfileRedirect($studentId)->with('status', 'Student account invitation sent. The signed link expires in seven days.');
        if ($mailIsLocalE2e) {
            $response->headers->set('X-E2E-Invitation-Url', $url);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function inviteGuardianIdentity(Request $request, Tenant $tenant, int $guardianId, SisGuardianIdentityInvitationWorkflow $workflow): RedirectResponse
    {
        $guardian = SisStudentGuardian::query()->findOrFail($guardianId);
        $student = $this->student((int) $guardian->student_id);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);

        $mailIsTestFake = app()->runningUnitTests() && Mail::getFacadeRoot() instanceof MailFake;
        $mailIsLocalE2e = app()->environment('testing') && config('app.e2e_test_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) && ! $mailIsTestFake && ! $mailIsLocalE2e, 503, 'Invitation delivery is disabled until a real mail transport is configured.');

        $result = $workflow->issue($guardian, $request->user());
        $url = URL::temporarySignedRoute('invitations.show', $result->invitation->expires_at, ['token' => $result->rawToken]);
        Mail::to($result->invitation->email)->send(new TenantInvitationMail($this->context->tenant()->name, $request->user()->name, $url));

        $response = $this->studentProfileRedirect((int) $student->getKey())
            ->with('status', 'Guardian account invitation sent. Student profile access remains disabled.');
        if ($mailIsLocalE2e) {
            $response->headers->set('X-E2E-Invitation-Url', $url);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function linkGuardianIdentity(Request $request, Tenant $tenant, int $guardianId): RedirectResponse
    {
        $data = $request->validate(['guardian_user_id' => ['required', 'integer', 'min:1']]);
        $guardian = DB::transaction(function () use ($data, $guardianId, $request): SisStudentGuardian {
            $lockedGuardian = SisStudentGuardian::query()->lockForUpdate()->findOrFail($guardianId);
            abort_unless($lockedGuardian->authority_status === 'verified' && $lockedGuardian->revoked_at === null, 409);
            $student = $this->student((int) $lockedGuardian->student_id);
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
            $tenantId = (int) $this->context->tenant()->getKey();
            $membership = TenantMembership::query()->where('tenant_id', $tenantId)
                ->where('user_id', $data['guardian_user_id'])->where('institution_id', $student->institution_id)->where('status', 'active')
                ->when($this->academicAccess->isCampusScoped(), fn (Builder $members) => $members->where('campus_id', $this->academicAccess->campusId()))
                ->lockForUpdate()->firstOrFail();
            $account = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            $guardianEmail = Str::lower(trim((string) $lockedGuardian->guardian_email));
            if ($guardianEmail === '' || $account->email_verified_at === null || $guardianEmail !== Str::lower(trim((string) $account->email))) {
                throw ValidationException::withMessages(['guardian_user_id' => 'The selected account must have a verified email matching the guardian contact.']);
            }
            abort_if($lockedGuardian->guardian_user_id !== null && (int) $lockedGuardian->guardian_user_id !== (int) $account->getKey(), 409);
            $conflictingIdentity = SisStudentGuardian::query()->where('tenant_id', $tenantId)
                ->where('guardian_user_id', $account->getKey())->whereKeyNot($lockedGuardian->getKey())
                ->whereRaw('LOWER(TRIM(guardian_email)) <> ?', [$guardianEmail])->exists();
            abort_if($conflictingIdentity, 409);

            $lockedGuardian->forceFill(['guardian_user_id' => $account->getKey(), 'can_view_profile' => false])->save();
            $parentRole = Role::query()->where('tenant_id', $tenantId)->where('key', 'parent.guardian')->where('scope', 'tenant')->firstOrFail();
            $membership->roles()->syncWithoutDetaching([$parentRole->getKey() => ['assigned_by' => $request->user()->getKey(), 'tenant_id' => $tenantId]]);
            app(AuditRecorder::class)->record('sis.guardian.identity_linked', $request->user(), $tenantId, $lockedGuardian, [
                'student_id' => $student->getKey(), 'user_id' => $account->getKey(), 'profile_access_granted' => false,
            ]);

            return $lockedGuardian;
        }, 3);

        return $this->studentProfileRedirect((int) $guardian->student_id)->with('status', 'Verified guardian account linked. Student profile access remains disabled.');
    }

    public function storeGuardian(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate(['guardian_name' => ['required', 'string', 'max:180'], 'guardian_email' => ['nullable', 'email', 'max:254'], 'guardian_phone' => ['nullable', 'string', 'max:40'], 'relationship' => ['required', 'string', 'max:48'], 'is_primary_contact' => ['sometimes', 'boolean'], 'is_emergency_contact' => ['sometimes', 'boolean']]);
        $guardian = DB::transaction(function () use ($data, $student, $request): SisStudentGuardian {
            SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            if (($data['is_primary_contact'] ?? false) === true) {
                SisStudentGuardian::query()->where('student_id', $student->getKey())->update(['is_primary_contact' => false]);
            }

            $guardian = SisStudentGuardian::query()->create([...$data, 'student_id' => $student->getKey(), 'authority_status' => 'pending', 'can_view_profile' => false]);
            app(AuditRecorder::class)->record('sis.guardian.created', $request->user(), (int) $this->context->tenant()->getKey(), $guardian, ['student_id' => $student->getKey(), 'authority_status' => 'pending']);

            return $guardian;
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Guardian relationship added in pending status.');
    }

    public function verifyGuardian(Request $request, Tenant $tenant, int $guardianId): RedirectResponse
    {
        $data = $request->validate(['verification_method' => ['required', Rule::in(['in_person', 'document_review', 'institution_record'])], 'guardian_user_id' => ['nullable', 'integer']]);
        $guardian = DB::transaction(function () use ($data, $guardianId, $request): SisStudentGuardian {
            $guardian = SisStudentGuardian::query()->lockForUpdate()->findOrFail($guardianId);
            abort_unless($guardian->authority_status === 'pending', 409);
            if (isset($data['guardian_user_id'])) {
                $student = $this->student((int) $guardian->student_id);
                $membership = TenantMembership::query()->where('tenant_id', $this->context->tenant()->getKey())
                    ->where('user_id', $data['guardian_user_id'])->where('institution_id', $student->institution_id)->where('status', 'active')
                    ->when($this->academicAccess->isCampusScoped(), fn (Builder $members) => $members->where('campus_id', $this->academicAccess->campusId()))
                    ->lockForUpdate()->firstOrFail();
                $account = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
                $guardianEmail = Str::lower(trim((string) $guardian->guardian_email));
                $accountEmail = Str::lower(trim((string) $account->email));
                if ($guardianEmail === '' || $account->email_verified_at === null || ! hash_equals($guardianEmail, $accountEmail)) {
                    throw ValidationException::withMessages(['guardian_user_id' => 'The selected account must have a verified email matching the guardian contact.']);
                }
                $guardian->guardian_user_id = $membership->user_id;
                $parentRole = Role::query()->where('tenant_id', $this->context->tenant()->getKey())->where('key', 'parent.guardian')->where('scope', 'tenant')->firstOrFail();
                $membership->roles()->syncWithoutDetaching([$parentRole->getKey() => ['assigned_by' => $request->user()->getKey(), 'tenant_id' => $this->context->tenant()->getKey()]]);
            }
            $guardian->forceFill(['authority_status' => 'verified', 'verified_by' => $request->user()->getKey(), 'verified_at' => now()])->save();
            app(AuditRecorder::class)->record('sis.guardian.verified', $request->user(), (int) $this->context->tenant()->getKey(), $guardian, [
                'student_id' => $guardian->student_id, 'verification_method' => $data['verification_method'], 'account_linked' => isset($data['guardian_user_id']),
            ]);

            return $guardian;
        }, 3);

        return $this->studentProfileRedirect((int) $guardian->student_id)->with('status', 'Guardian relationship verified. Profile access remains disabled unless separately authorized by policy.');
    }

    public function revokeGuardian(Request $request, Tenant $tenant, int $guardianId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $studentId = DB::transaction(function () use ($guardianId, $request, $data): int {
            $guardian = SisStudentGuardian::query()->lockForUpdate()->findOrFail($guardianId);
            if ($guardian->authority_status !== 'revoked') {
                $guardian->forceFill(['authority_status' => 'revoked', 'can_view_profile' => false, 'revoked_by' => $request->user()->getKey(), 'revoked_at' => now(), 'revocation_reason' => $data['reason']])->save();
                app(AuditRecorder::class)->record('sis.guardian.revoked', $request->user(), (int) $this->context->tenant()->getKey(), $guardian, ['student_id' => $guardian->student_id, 'reason' => $data['reason']]);
            }

            return (int) $guardian->student_id;
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Guardian authority revoked.');
    }

    public function storeFamily(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'relationship_label' => ['required', 'string', 'max:48'],
            'verification_method' => ['required', Rule::in(['institution_record', 'document_review', 'guardian_confirmation'])],
        ]);
        DB::transaction(function () use ($student, $data, $request): void {
            $group = SisFamilyGroup::query()->create(['institution_id' => $student->institution_id, 'family_reference' => 'FAM-'.Str::upper(Str::random(12)), 'label' => $data['label'] ?? null, 'created_by' => $request->user()->getKey()]);
            SisFamilyStudent::query()->create(['family_group_id' => $group->getKey(), 'student_id' => $student->getKey(), 'relationship_label' => $data['relationship_label'] ?? null, 'effective_from' => now()->toDateString(), 'verified_by' => $request->user()->getKey(), 'verified_at' => now()]);
            app(AuditRecorder::class)->record('sis.family.created', $request->user(), (int) $this->context->tenant()->getKey(), $group, [
                'student_id' => $student->getKey(), 'relationship_label' => $data['relationship_label'],
                'verification_method' => $data['verification_method'],
            ]);
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Institution family group created. Membership does not grant sibling record access.');
    }

    public function updateFamilyMembership(Request $request, Tenant $tenant, int $studentId, int $familyId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'relationship_label' => ['required', 'string', 'max:48'],
            'verification_method' => ['required', Rule::in(['institution_record', 'document_review', 'guardian_confirmation'])],
        ]);
        $group = $this->visibleFamilyGroupsQuery((int) $student->institution_id)->findOrFail($familyId);
        abort_unless((int) $group->institution_id === (int) $student->institution_id, 404);

        DB::transaction(function () use ($group, $student, $data, $request): void {
            $lockedGroup = SisFamilyGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            $membership = SisFamilyStudent::query()->where('family_group_id', $lockedGroup->getKey())
                ->where('student_id', $student->getKey())->whereNull('effective_until')->lockForUpdate()->firstOrFail();
            $audit = app(AuditRecorder::class);
            $actor = $request->user();
            $tenantId = (int) $this->context->tenant()->getKey();
            $newLabel = trim((string) ($data['label'] ?? '')) ?: null;
            $oldLabel = $lockedGroup->label;
            if ($oldLabel !== $newLabel) {
                $lockedGroup->forceFill(['label' => $newLabel])->save();
                $audit->record('sis.family.updated', $actor, $tenantId, $lockedGroup, ['previous_label' => $oldLabel, 'new_label' => $newLabel]);
            }

            $oldRelationship = $membership->relationship_label;
            $newRelationship = trim($data['relationship_label']);
            $membership->forceFill([
                'relationship_label' => $newRelationship,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ])->save();
            $audit->record('sis.family.relationship_verified', $actor, $tenantId, $lockedGroup, [
                'student_id' => $student->getKey(), 'previous_relationship_label' => $oldRelationship,
                'relationship_label' => $newRelationship, 'verification_method' => $data['verification_method'],
            ]);
        }, 3);

        return $this->studentProfileRedirect($studentId)->with('status', 'Family details updated and relationship verification recorded.');
    }

    public function addFamilyStudent(Request $request, Tenant $tenant, int $familyId): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'relationship_label' => ['required', 'string', 'max:48'],
            'verification_method' => ['required', Rule::in(['institution_record', 'document_review', 'guardian_confirmation'])],
        ]);
        $group = $this->visibleFamilyGroupsQuery((int) Institution::query()->value('id'))->findOrFail($familyId);
        $student = $this->student((int) $data['student_id']);
        if ((int) $student->institution_id !== (int) $group->institution_id) {
            abort(404);
        }
        DB::transaction(function () use ($group, $student, $data, $request): void {
            $lockedGroup = SisFamilyGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            $lockedStudent = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            abort_unless((int) $lockedStudent->institution_id === (int) $lockedGroup->institution_id, 404);
            $link = SisFamilyStudent::query()->where('family_group_id', $lockedGroup->getKey())
                ->where('student_id', $lockedStudent->getKey())->lockForUpdate()->first();
            abort_if($link?->effective_until === null && $link !== null, 409, 'This student is already an active member of the family group.');

            $values = [
                'relationship_label' => trim($data['relationship_label']), 'effective_from' => now()->toDateString(),
                'effective_until' => null, 'verified_by' => $request->user()->getKey(), 'verified_at' => now(),
            ];
            if ($link) {
                $rawEffectiveUntil = $link->getRawOriginal('effective_until');
                $previousEffectiveUntil = is_string($rawEffectiveUntil) ? Carbon::parse($rawEffectiveUntil)->toDateString() : null;
                $link->forceFill($values)->save();
                $event = 'sis.family.student_relinked';
                $metadata = ['student_id' => $lockedStudent->getKey(), 'previous_effective_until' => $previousEffectiveUntil];
            } else {
                $link = SisFamilyStudent::query()->create(['family_group_id' => $lockedGroup->getKey(), 'student_id' => $lockedStudent->getKey(), ...$values]);
                $event = 'sis.family.student_linked';
                $metadata = ['student_id' => $lockedStudent->getKey()];
            }
            app(AuditRecorder::class)->record($event, $request->user(), (int) $this->context->tenant()->getKey(), $lockedGroup, [
                ...$metadata, 'relationship_label' => $values['relationship_label'], 'verification_method' => $data['verification_method'],
            ]);
        }, 3);

        return $this->studentProfileRedirect((int) $student->getKey())->with('status', 'Student linked to this institution family group.');
    }

    public function familyStudentOptions(Request $request, Tenant $tenant, int $familyId): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = trim($data['q']);
        if (mb_strlen($term) < 2) {
            throw ValidationException::withMessages(['q' => 'Enter at least two non-space characters to search.']);
        }

        $group = $this->visibleFamilyGroupsQuery((int) Institution::query()->value('id'))->findOrFail($familyId);
        $query = SisStudent::query()->where('institution_id', $group->institution_id);
        $this->applyCampusScope($query);
        $query->whereNotIn('id', SisFamilyStudent::query()->select('student_id')->where('family_group_id', $group->getKey())->whereNull('effective_until'));

        foreach (preg_split('/\s+/u', mb_strtolower($term), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $like = strtr($token, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(function (Builder $matches) use ($like): void {
                foreach (['student_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name'] as $column) {
                    $matches->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        $students = $query->orderBy('legal_last_name')->orderBy('legal_first_name')->limit(20)
            ->get(['id', 'student_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name']);

        return response()->json(['data' => $students->map(fn (SisStudent $candidate): array => [
            'id' => (int) $candidate->getKey(), 'studentNumber' => $candidate->student_number,
            'name' => trim(implode(' ', array_filter([$candidate->preferred_name ?: $candidate->legal_first_name, $candidate->legal_middle_name, $candidate->legal_last_name]))),
        ])->values()]);
    }

    public function storeCustomField(Request $request): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate(['field_key' => ['required', 'alpha_dash:ascii', 'max:48'], 'label' => ['required', 'string', 'max:120'], 'field_type' => ['required', Rule::in(['text', 'number', 'date', 'select', 'multiselect', 'boolean'])], 'is_required' => ['required', 'boolean'], 'is_sensitive' => ['required', 'boolean'], 'options' => ['nullable', 'array', 'max:100'], 'options.*' => ['string', 'max:120']]);
        abort_if($data['is_sensitive'] && ! $this->can('sis.custom-fields.sensitive'), 403);
        if (in_array($data['field_type'], ['select', 'multiselect'], true) && empty($data['options'])) {
            throw ValidationException::withMessages(['options' => 'Select fields require at least one allowed option.']);
        }
        $field = SisCustomFieldDefinition::query()->create([...$data, 'institution_id' => $institution->getKey(), 'created_by' => $request->user()->getKey()]);
        app(AuditRecorder::class)->record('sis.custom_field.created', $request->user(), (int) $this->context->tenant()->getKey(), $field, ['field_key' => $field->field_key, 'field_type' => $field->field_type, 'is_sensitive' => $field->is_sensitive]);

        return back()->with('status', 'Student custom field created.');
    }

    public function setCustomValue(Request $request, Tenant $tenant, int $studentId, int $fieldId): RedirectResponse
    {
        $student = $this->student($studentId);
        $field = SisCustomFieldDefinition::query()->where('institution_id', $student->institution_id)->findOrFail($fieldId);
        abort_if($field->is_sensitive && ! $this->can('sis.custom-fields.sensitive'), 403);
        $data = $request->validate(['value' => [$field->is_required ? 'required' : 'nullable']]);
        $value = $data['value'] ?? null;
        $this->validateCustomValue($field->field_type, $field->options ?? [], $value);
        $storedValue = $field->is_sensitive ? ['encrypted_payload' => Crypt::encryptString(json_encode(['value' => $value], JSON_THROW_ON_ERROR))] : ['value' => $value];
        SisStudentCustomValue::query()->updateOrCreate(['student_id' => $student->getKey(), 'field_definition_id' => $field->getKey()], ['value' => $storedValue, 'updated_by' => $request->user()->getKey()]);
        app(AuditRecorder::class)->record('sis.custom_field.value_updated', $request->user(), (int) $this->context->tenant()->getKey(), $student, ['student_id' => $student->getKey(), 'field_key' => $field->field_key]);

        return $this->studentProfileRedirect($studentId)->with('status', 'Student custom field updated.');
    }

    public function attachAdmissionDocument(Request $request, Tenant $tenant, int $studentId): RedirectResponse
    {
        $student = $this->student($studentId);
        $data = $request->validate(['admission_document_id' => ['required', 'integer'], 'category' => ['required', 'string', 'max:48']]);
        $document = AdmissionDocument::query()->whereKey($data['admission_document_id'])->where('application_id', $student->admission_application_id)->where('scan_status', 'clean')->firstOrFail();
        AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->firstOrFail();
        $link = SisStudentDocument::query()->firstOrCreate(['student_id' => $student->getKey(), 'admission_document_id' => $document->getKey()], ['category' => $data['category'], 'linked_by' => $request->user()->getKey()]);
        app(AuditRecorder::class)->record('sis.student.document_linked', $request->user(), (int) $this->context->tenant()->getKey(), $link, ['student_id' => $student->getKey(), 'admission_document_id' => $document->getKey(), 'scan_status' => 'clean']);

        return $this->studentProfileRedirect($studentId)->with('status', 'Clean, current admissions document linked to this student.');
    }

    public function uploadPrivateDocument(Request $request, Tenant $tenant, int $studentId, AuditRecorder $audit): RedirectResponse
    {
        $student = $this->student($studentId);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
        $tenantId = (int) $this->context->tenant()->getKey();
        $data = $request->validate([
            'category' => ['required', Rule::in(['identity', 'previous_academic_record', 'transfer_certificate', 'guardian_consent', 'other'])],
            'replace_document_id' => ['nullable', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
        $file = $data['file'];
        if (! $file instanceof UploadedFile || ! in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            abort(422, 'The file content does not match a supported document format.');
        }
        $mime = (string) $file->getMimeType();
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
        $this->assertSisFileSignature($file, $mime);
        $path = 'sis/'.$tenantId.'/'.$student->getKey().'/'.Str::uuid().'.'.$extension;
        $storedPath = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        abort_unless(is_string($storedPath), 500, 'The private student document could not be stored.');

        try {
            [$document, $version, $scanEvent] = DB::transaction(function () use ($data, $file, $mime, $extension, $storedPath, $student, $request): array {
                if (! empty($data['replace_document_id'])) {
                    $document = SisPrivateDocument::query()->where('student_id', $student->getKey())->lockForUpdate()->findOrFail($data['replace_document_id']);
                } else {
                    $document = SisPrivateDocument::query()->create([
                        'student_id' => $student->getKey(), 'category' => $data['category'],
                        'scan_status' => 'pending', 'current_version' => 0,
                        'uploaded_by' => $request->user()->getKey(),
                    ]);
                }

                $number = (int) $document->current_version + 1;
                $retentionDays = SisRegistrySetting::query()
                    ->where('institution_id', $student->institution_id)
                    ->lockForUpdate()
                    ->value('document_retention_days');
                $version = SisPrivateDocumentVersion::query()->create([
                    'document_id' => $document->getKey(), 'version_number' => $number,
                    'private_path' => $storedPath, 'original_name' => $this->safeSisOriginalName((string) $file->getClientOriginalName(), $extension),
                    'mime_type' => $mime, 'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()), 'uploaded_by' => $request->user()->getKey(),
                    'expires_at' => $retentionDays ? now()->addDays((int) $retentionDays) : null,
                ]);
                $document->forceFill([
                    'category' => $data['category'], 'scan_status' => 'pending', 'current_version' => $number,
                    'uploaded_by' => $request->user()->getKey(),
                ])->save();
                $scanEvent = SisPrivateDocumentScanEvent::query()->create([
                    'document_version_id' => $version->getKey(), 'attempt_number' => 1,
                    'status' => 'pending', 'scanner' => (string) config('admissions.document_scanner'),
                ]);

                return [$document, $version, $scanEvent];
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        ScanSisStudentDocument::dispatch(
            $tenantId, (int) $document->getKey(), (int) $version->getKey(), (int) $scanEvent->getKey(),
        )->afterCommit();
        $audit->record('sis.student.document.uploaded', $request->user(), $tenantId, $document, [
            'student_id' => $student->getKey(), 'document_id' => $document->getKey(), 'version' => $version->version_number,
            'category' => $document->category, 'mime_type' => $version->mime_type, 'size_bytes' => $version->size_bytes,
            'scan_status' => 'pending', 'expires_at' => $version->expires_at === null ? null : Carbon::parse($version->expires_at)->toIso8601String(),
        ]);

        return to_route('tenant.sis.students.show', ['tenant' => $tenant->slug, 'studentId' => $studentId])
            ->with('status', 'Document uploaded to private quarantine. It is unavailable until the current version passes scanning.');
    }

    public function downloadDocument(Tenant $tenant, int $documentLinkId): StreamedResponse
    {
        $link = SisStudentDocument::query()->findOrFail($documentLinkId);
        $student = $this->student((int) $link->student_id);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
        $document = AdmissionDocument::query()->whereKey($link->admission_document_id)->where('application_id', $student->admission_application_id)->where('scan_status', 'clean')->firstOrFail();
        $version = AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($version->private_path), 404);
        app(AuditRecorder::class)->record('sis.student.document_downloaded', request()->user(), (int) $this->context->tenant()->getKey(), $link, ['student_id' => $student->getKey(), 'document_id' => $document->getKey(), 'version' => $version->version_number]);

        return Storage::disk('local')->download($version->private_path, basename($version->original_name));
    }

    public function downloadPrivateDocument(Tenant $tenant, int $documentId): StreamedResponse
    {
        $document = SisPrivateDocument::query()->findOrFail($documentId);
        abort_unless($document->scan_status === 'clean' && $document->current_version > 0, 404);
        $student = $this->student((int) $document->student_id);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
        $version = SisPrivateDocumentVersion::query()->where('document_id', $document->getKey())
            ->where('version_number', $document->current_version)->firstOrFail();
        if ($version->expires_at !== null && ! Carbon::parse($version->expires_at)->isFuture()) {
            app(AuditRecorder::class)->record('sis.student.document.expired_download_denied', request()->user(), (int) $this->context->tenant()->getKey(), $document, [
                'student_id' => $student->getKey(), 'document_id' => $document->getKey(), 'version' => $version->version_number,
            ]);

            abort(404);
        }
        abort_unless((int) $version->tenant_id === (int) $document->tenant_id && $version->storage_status !== 'purged' && $version->storage_status !== 'purging', 404);
        $downloadDisk = $version->storage_status === 'archived' ? Storage::disk('local_archive') : Storage::disk('local');
        $downloadPath = $version->storage_status === 'archived' ? (string) $version->archived_path : (string) $version->private_path;
        $expectedPrefix = $version->storage_status === 'archived'
            ? 'sis-archive/'.$document->tenant_id.'/'.$student->getKey().'/'.$version->getKey().'-'
            : 'sis/'.$document->tenant_id.'/'.$student->getKey().'/';
        abort_unless(str_starts_with($downloadPath, $expectedPrefix) && $downloadDisk->exists($downloadPath)
            && hash_equals((string) $version->sha256, (string) hash_file('sha256', $downloadDisk->path($downloadPath))), 404);

        app(AuditRecorder::class)->record('sis.student.document_downloaded', request()->user(), (int) $this->context->tenant()->getKey(), $document, [
            'student_id' => $student->getKey(), 'document_id' => $document->getKey(), 'version' => $version->version_number,
        ]);

        return $downloadDisk->download($downloadPath, basename($version->original_name));
    }

    public function import(Request $request, SisStudentCsvImport $csvImport): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:25600'],
            'confirm' => ['sometimes', 'boolean'],
            'column_map' => ['sometimes', 'array', 'max:8'],
            'column_map.*' => ['nullable', 'string', 'max:100'],
        ]);
        $file = $data['file'];
        $digest = hash_file('sha256', $file->getRealPath());
        $institution = Institution::query()->firstOrFail();
        $headers = $csvImport->headers($file->getRealPath());
        $mapping = $csvImport->mapping($headers, $data['column_map'] ?? []);
        if (array_diff(SisStudentCsvImport::REQUIRED_FIELDS, array_keys($mapping)) !== []) {
            return back()->with('status', 'Map the required student fields to CSV columns before reviewing the import.')
                ->with('sisImportMapping', [
                    'headers' => $headers,
                    'fields' => SisStudentCsvImport::FIELD_LABELS,
                    'required' => SisStudentCsvImport::REQUIRED_FIELDS,
                    'mapping' => $mapping,
                    'missing' => array_values(array_diff(SisStudentCsvImport::REQUIRED_FIELDS, array_keys($mapping))),
                ]);
        }

        $existing = SisStudentImportBatch::query()->where('institution_id', $institution->getKey())->where('file_sha256', $digest)->first();
        if (in_array($existing?->status, ['completed', 'completed_with_errors'], true)) {
            return back()->with('status', 'This exact import file has already been completed; no duplicate students were created.');
        }
        if (in_array($existing?->status, ['queued', 'processing'], true)) {
            return back()->with('status', 'This exact import file is already being processed. Refresh the directory for its latest status.');
        }
        $existingReport = $existing?->getAttribute('safe_report');
        $existingMapping = is_array($existingReport) ? ($existingReport['mapping'] ?? null) : null;
        if (($data['confirm'] ?? false) && $existing && ! $this->sameImportMapping($existingMapping, $mapping)) {
            throw ValidationException::withMessages(['file' => 'The column mapping changed after preview. Review the file again before confirming.']);
        }

        $report = $csvImport->preview($file->getRealPath(), $headers, $mapping, (int) $institution->getKey());
        $safeReport = ['mapping' => $mapping, 'errors' => $report['errors']];
        $batch = $existing ?? new SisStudentImportBatch([
            'institution_id' => $institution->getKey(), 'batch_reference' => (string) Str::uuid(), 'file_sha256' => $digest,
            'status' => 'previewed', 'requested_by' => $request->user()->getKey(),
        ]);
        $batch->forceFill([
            'status' => 'previewed', 'row_count' => $report['row_count'], 'valid_count' => $report['valid'],
            'invalid_count' => $report['invalid'], 'processed_count' => 0, 'source_path' => null,
            'safe_report' => $safeReport,
        ])->save();
        if (! ($data['confirm'] ?? false)) {
            return back()->with('status', "Import preview {$batch->batch_reference}: {$report['valid']} valid rows, {$report['invalid']} invalid rows. Review the row summary, then reselect the same file and confirm.")
                ->with('sisImportPreview', [
                    'batchReference' => $batch->batch_reference, 'valid' => $report['valid'],
                    'invalid' => $report['invalid'], 'errors' => $report['errors'],
                    'errorsTruncated' => $report['errors_truncated'],
                    'mappedFields' => $mapping,
                    'errorReportUrl' => $report['invalid'] > 0 ? route('tenant.sis.students.import-errors', ['tenant' => $this->context->tenant()->slug, 'batchReference' => $batch->batch_reference]) : null,
                ]);
        }
        if ($report['valid'] === 0) {
            throw ValidationException::withMessages(['file' => 'This import has no valid rows to process. Download the row-error report to review it.']);
        }
        if ($batch->status !== 'previewed') {
            throw ValidationException::withMessages(['file' => 'This import batch cannot be confirmed in its current state.']);
        }
        $privatePath = Storage::disk('local')->putFileAs('sis-imports/'.$this->context->tenant()->getKey(), $file, $batch->batch_reference.'.csv');
        if (! is_string($privatePath) || $privatePath === '') {
            throw ValidationException::withMessages(['file' => 'The import file could not be placed in private processing storage.']);
        }

        $queuedBatchId = DB::transaction(function () use ($batch, $privatePath, $request): ?int {
            $lockedBatch = SisStudentImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedBatch->status !== 'previewed') {
                return null;
            }
            $lockedBatch->forceFill(['status' => 'queued', 'source_path' => $privatePath, 'processed_count' => 0, 'confirmed_at' => now()])->save();
            app(AuditRecorder::class)->record('sis.student.import_queued', $request->user(), (int) $this->context->tenant()->getKey(), $lockedBatch, [
                'row_count' => (int) $lockedBatch->row_count, 'valid_count' => (int) $lockedBatch->valid_count,
                'invalid_count' => (int) $lockedBatch->invalid_count, 'file_sha256' => $lockedBatch->file_sha256,
            ]);

            return (int) $lockedBatch->getKey();
        }, 3);

        if ($queuedBatchId === null) {
            Storage::disk('local')->delete($privatePath);

            return back()->with('status', 'This import batch was already confirmed; no duplicate job was created. Refresh the directory for its latest status.');
        }

        try {
            if (config('queue.default') === 'sync') {
                ProcessSisStudentImportBatch::dispatchSync($queuedBatchId);
            } else {
                ProcessSisStudentImportBatch::dispatch($queuedBatchId)->afterCommit();
            }
        } catch (\Throwable) {
            $batch->forceFill(['status' => 'failed', 'source_path' => null])->save();
            Storage::disk('local')->delete($privatePath);

            return back()->withErrors(['file' => 'The import could not be queued. No student rows were processed.']);
        }

        $batch->refresh();
        $message = match ($batch->status) {
            'completed' => $batch->processed_count.' student rows imported.',
            'completed_with_errors' => $batch->processed_count.' student rows imported; '.$batch->invalid_count.' rows were skipped. Download the row-error report.',
            'failed' => 'The import could not be completed. Review the batch status or contact an administrator.',
            default => 'The import was queued. Refresh the directory to check its status.',
        };

        return back()->with('status', $message);
    }

    public function downloadImportErrors(Request $request, Tenant $tenant, string $batchReference): StreamedResponse
    {
        $institution = Institution::query()->firstOrFail();
        abort_unless((int) $tenant->getKey() === (int) $this->context->tenant()->getKey(), 404);
        $batch = SisStudentImportBatch::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())
            ->where('institution_id', $institution->getKey())
            ->where('batch_reference', $batchReference)->firstOrFail();
        $report = $batch->safe_report;
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        $rowErrors = DB::table('sis_student_import_row_errors')->where('tenant_id', $batch->tenant_id)
            ->where('batch_id', $batch->getKey())->orderBy('source_row')->cursor();
        $hasStoredErrors = DB::table('sis_student_import_row_errors')->where('tenant_id', $batch->tenant_id)
            ->where('batch_id', $batch->getKey())->exists();
        abort_if(! $hasStoredErrors && $errors === [], 404);

        app(AuditRecorder::class)->record('sis.student.import_errors_downloaded', $request->user(), (int) $this->context->tenant()->getKey(), $batch, [
            'batch_reference' => $batch->batch_reference, 'error_count' => count($errors),
        ]);

        return response()->streamDownload(function () use ($errors, $rowErrors, $hasStoredErrors): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'errors']);
            if ($hasStoredErrors) {
                foreach ($rowErrors as $error) {
                    $messages = json_decode((string) $error->errors, true);
                    fputcsv($out, [$this->spreadsheetSafeCell($error->source_row), $this->spreadsheetSafeCell(implode('; ', is_array($messages) ? $messages : []))]);
                }
            } else {
                foreach ($errors as $error) {
                    fputcsv($out, [$this->spreadsheetSafeCell($error['row'] ?? ''), $this->spreadsheetSafeCell(implode('; ', $error['errors'] ?? []))]);
                }
            }
            fclose($out);
        }, 'student-import-errors-'.$batch->batch_reference.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function export(Request $request): StreamedResponse
    {
        $institution = Institution::query()->firstOrFail();
        $query = SisStudent::query()->where('institution_id', $institution->getKey())->orderBy('legal_last_name')->orderBy('legal_first_name');
        $this->applyCampusScope($query);
        if ($request->filled('q')) {
            $term = mb_substr((string) $request->query('q'), 0, 120);
            $query->where(fn (Builder $search) => $search->where('student_number', 'like', '%'.$term.'%')->orWhere('legal_first_name', 'like', '%'.$term.'%')->orWhere('legal_last_name', 'like', '%'.$term.'%'));
        }
        $this->auditExport($request, $query);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['student_number', 'registration_reference', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'status', 'student_type', 'admission_date']);
            $query->select(['id', 'student_number', 'registration_reference', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'status', 'student_type', 'admission_date'])->chunkById(500, function ($students) use ($out): void {
                foreach ($students as $student) {
                    fputcsv($out, array_map($this->spreadsheetSafeCell(...), [$student->student_number, $student->registration_reference, $student->legal_first_name, $student->legal_middle_name, $student->legal_last_name, $student->preferred_name, $student->status, $student->student_type, $student->admission_date->toDateString()]));
                }
            });
            fclose($out);
        }, 'students-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['legal_first_name', 'legal_middle_name', 'legal_last_name', 'preferred_name', 'registration_reference', 'date_of_birth', 'admission_date', 'student_type']);
            fclose($out);
        }, 'sis-student-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** @param array<string, string> $submitted MySQL JSON columns canonicalize key order; compare mapping content only. */
    private function sameImportMapping(mixed $stored, array $submitted): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        ksort($stored);
        ksort($submitted);

        return $stored === $submitted;
    }

    /** @param list<string> $options */
    private function validateCustomValue(string $type, array $options, mixed $value): void
    {
        $valid = match ($type) {
            'text' => is_string($value) && mb_strlen($value) <= 2000,
            'number' => is_numeric($value),
            'date' => is_string($value) && strtotime($value) !== false,
            'boolean' => is_bool($value),
            'select' => is_string($value) && in_array($value, $options, true),
            'multiselect' => is_array($value) && array_diff($value, $options) === [],
            default => false,
        };
        if (! $valid && $value !== null) {
            throw ValidationException::withMessages(['value' => 'The value does not match the configured student field type.']);
        }
    }

    private function student(int $id): SisStudent
    {
        $student = SisStudent::query()->where('institution_id', Institution::query()->value('id'))->findOrFail($id);
        $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);

        return $student;
    }

    /** @return Builder<SisFamilyGroup> */
    private function visibleFamilyGroupsQuery(int $institutionId): Builder
    {
        $query = SisFamilyGroup::query()->where('institution_id', $institutionId)->where('status', 'active')
            ->whereHas('students', function (Builder $members): void {
                $members->whereNull('effective_until')->whereHas('student', fn (Builder $students) => $this->applyCampusScope($students));
            });

        if ($this->academicAccess->isCampusScoped()) {
            $campusId = $this->academicAccess->campusId() ?? 0;
            $query->whereDoesntHave('students', function (Builder $members) use ($campusId): void {
                $members->whereNull('effective_until')->whereHas('student', fn (Builder $students) => $students->where(function (Builder $outOfScope) use ($campusId): void {
                    $outOfScope->whereNull('home_campus_id')->orWhere('home_campus_id', '<>', $campusId);
                }));
            });
        }

        return $query;
    }

    /** @param Builder<SisStudent> $query */
    private function applyCampusScope(Builder $query): void
    {
        if ($this->academicAccess->isCampusScoped()) {
            $query->where('home_campus_id', $this->academicAccess->campusId());
        }
    }

    private function spreadsheetSafeCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1 ? "'".$cell : $cell;
    }

    private function studentProfileRedirect(int $studentId): RedirectResponse
    {
        return to_route('tenant.sis.students.show', ['tenant' => $this->context->tenant()->slug, 'studentId' => $studentId]);
    }

    private function bulkPromotionSessionKey(): string
    {
        return 'sis.bulk_promotion_preview.'.$this->context->tenant()->getKey().'.'.auth()->id();
    }

    private function assertSisFileSignature(UploadedFile $file, string $mime): void
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be verified.']);
        }

        try {
            $header = fread($handle, 8);
        } finally {
            fclose($handle);
        }

        $valid = match ($mime) {
            'application/pdf' => str_starts_with((string) $header, '%PDF-'),
            'image/jpeg' => str_starts_with((string) $header, "\xFF\xD8\xFF"),
            'image/png' => $header === "\x89PNG\r\n\x1A\n",
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['file' => 'The file signature does not match its declared document format.']);
        }
    }

    private function safeSisOriginalName(string $name, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?: 'student-document';
        $base = trim(substr($base, 0, 170), " ._-\t\n\r\0\x0B");

        return ($base === '' ? 'student-document' : $base).'.'.$extension;
    }

    /** @return array<string, mixed> */
    private function studentSummary(SisStudent $student): array
    {
        return [
            'id' => $student->getKey(), 'studentNumber' => $student->student_number, 'registrationReference' => $student->registration_reference,
            'name' => trim(implode(' ', array_filter([$student->preferred_name ?: $student->legal_first_name, $student->legal_middle_name, $student->legal_last_name]))),
            'legalFirstName' => $student->legal_first_name, 'legalLastName' => $student->legal_last_name,
            'status' => $student->status, 'studentType' => $student->student_type, 'admissionDate' => $student->admission_date->toDateString(),
            'homeCampus' => $student->homeCampus?->only(['id', 'name', 'code']),
        ];
    }

    /** @return list<array{id:int,name:string,code:string}> */
    private function campusOptions(Institution $institution): array
    {
        $query = $institution->campuses()->where('status', 'active')->orderBy('name');
        if ($this->academicAccess->isCampusScoped()) {
            $query->whereKey($this->academicAccess->campusId());
        }

        return $query->get(['id', 'name', 'code'])->map(fn ($campus): array => ['id' => $campus->getKey(), 'name' => $campus->name, 'code' => $campus->code])->all();
    }

    /** @return array<string, list<array<string, int|string|null>>> */
    private function academicOptions(Institution $institution): array
    {
        $years = AcademicYear::query()->where('institution_id', $institution->getKey())->where('status', 'active');
        $classes = AcademicClass::query()->where('institution_id', $institution->getKey())->where('status', 'active');
        if ($this->academicAccess->isCampusScoped()) {
            $years->where(fn (Builder $query) => $query->whereNull('campus_id')->orWhere('campus_id', $this->academicAccess->campusId()));
            $classes->where('campus_id', $this->academicAccess->campusId());
        }
        $classRows = $classes->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'academic_program_id', 'academic_level_id', 'campus_id']);
        $programs = AcademicProgram::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('title');
        if ($this->academicAccess->isCampusScoped()) {
            $programs->whereHas('campuses', fn (Builder $campuses) => $campuses->whereKey($this->academicAccess->campusId()));
        }
        $levels = AcademicLevel::query()->where('institution_id', $institution->getKey())->where('status', 'active')
            ->whereIn('id', $classRows->pluck('academic_level_id')->filter()->unique()->values());

        return [
            'years' => $years->orderByDesc('starts_on')->get(['id', 'name', 'code', 'campus_id'])->map(fn ($year): array => ['id' => $year->getKey(), 'name' => $year->name, 'code' => $year->code, 'campusId' => $year->campus_id])->all(),
            'programs' => $programs->get(['id', 'title', 'code'])->map(fn ($program): array => ['id' => $program->getKey(), 'name' => $program->title, 'code' => $program->code])->all(),
            'levels' => $levels->orderBy('sequence')->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($level): array => ['id' => $level->getKey(), 'name' => $level->name, 'code' => $level->code])->all(),
            'classes' => $classRows->map(fn ($class): array => ['id' => $class->getKey(), 'name' => $class->name, 'code' => $class->code, 'yearId' => $class->academic_year_id, 'programId' => $class->academic_program_id, 'levelId' => $class->academic_level_id, 'campusId' => $class->campus_id])->all(),
            'sections' => AcademicSection::query()->whereIn('academic_class_id', $classRows->modelKeys())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'academic_class_id'])->map(fn ($section): array => ['id' => $section->getKey(), 'name' => $section->name, 'code' => $section->code, 'classId' => $section->academic_class_id])->all(),
            'campuses' => $this->campusOptions($institution),
        ];
    }

    private function can(string $permission): bool
    {
        return $this->context->hasMembership() && $this->context->membership()->hasPermission($permission);
    }

    /** @return list<array{id:int,student_id:int,student_number:string,name:string,from_status:string,to_status:string,reason_code:string,reason:?string,requested_at:string,requester:string}> */
    private function lifecycleApprovalQueue(Institution $institution): array
    {
        if (! $this->can('sis.lifecycle.approve')) {
            return [];
        }
        $requests = SisStudentLifecycleRequest::query()->with(['student', 'requestedBy'])
            ->where('institution_id', $institution->getKey())->where('status', 'pending')
            ->when($this->academicAccess->isCampusScoped(), fn (Builder $query) => $query->whereHas(
                'student', fn (Builder $students) => $students->where('home_campus_id', $this->academicAccess->campusId()),
            ))
            ->orderBy('created_at')->limit(50)->get();

        return $requests->map(fn (SisStudentLifecycleRequest $request): array => [
            'id' => (int) $request->getKey(),
            'student_id' => (int) $request->student_id,
            'student_number' => (string) $request->student->student_number,
            'name' => trim($request->student->legal_first_name.' '.$request->student->legal_last_name),
            'from_status' => (string) $request->from_status,
            'to_status' => (string) $request->to_status,
            'reason_code' => (string) $request->reason_code,
            'reason' => $request->reason === null ? null : (string) $request->reason,
            'requested_at' => $request->created_at->toIso8601String(),
            'requester' => (string) $request->requestedBy->name,
        ])->all();
    }

    /** @param Builder<SisStudent> $query */
    private function auditExport(Request $request, Builder $query): void
    {
        app(AuditRecorder::class)->record('sis.student.exported', $request->user(), (int) $this->context->tenant()->getKey(), null, ['matched_count' => (clone $query)->count(), 'fields' => ['student_number', 'registration_reference', 'names', 'status', 'student_type', 'admission_date']]);
    }
}
