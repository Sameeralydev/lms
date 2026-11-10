<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Attendance\AttendanceWorkflow;
use App\Domain\Academic\Timetabling\TimetableOccurrenceProjector;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ScanAttendanceJustificationEvidence;
use App\Models\AcademicTimetableMeeting;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceEntry;
use App\Models\AttendanceJustification;
use App\Models\AttendanceJustificationEvidence;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRegister;
use App\Models\AttendanceStatusDefinition;
use App\Models\Campus;
use App\Models\SisStudentGuardian;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttendanceController
{
    public function __construct(private readonly TenantContext $context, private readonly AttendanceWorkflow $workflow) {}

    public function index(Request $request, Tenant $tenant, TimetableOccurrenceProjector $projector): InertiaResponse
    {
        $membership = $this->context->membership();
        $actor = $request->user();
        $isInstructor = $membership->hasPermission('attendance.register.mark') && ! $membership->hasPermission('attendance.register.override');
        $meetings = AcademicTimetableMeeting::query()->with(['timetable', 'section.offering.course'])
            ->where('status', 'scheduled')->whereHas('timetable', fn ($query) => $query->where('status', 'published'))
            ->when($isInstructor, fn ($query) => $query->where('instructor_user_id', $actor->getKey()))
            ->orderBy('starts_on')->limit(100)->get();
        $today = now()->toDateString();
        $occurrences = $meetings->flatMap(fn (AcademicTimetableMeeting $meeting): array => $projector->project($meeting))
            ->filter(fn (array $event): bool => $event['date'] >= $today && $event['date'] <= now()->addDays(7)->toDateString())
            ->map(fn (array $event): array => [
                'meeting_id' => (int) $event['meeting']->getKey(), 'date' => $event['date'],
                'starts_at' => $event['start']->toIso8601String(), 'course' => $event['meeting']->section->offering->course->title,
                'section' => $event['meeting']->section->section_code, 'instructor' => $event['instructor_name'],
                'register_id' => DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->where('meeting_id', $event['meeting']->getKey())->whereDate('occurrence_date', $event['date'])->value('id'),
            ])->values();
        $canManage = $membership->hasPermission('attendance.policy.manage');
        $metrics = [
            'registers_today' => DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->whereDate('occurrence_date', $today)->count(),
            'missing_registers' => $occurrences->whereNull('register_id')->count(),
            'unsubmitted' => DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->where('status', 'draft')->count(),
            'pending_corrections' => DB::table('attendance_correction_requests')->where('tenant_id', $tenant->getKey())->where('status', 'pending')->count(),
        ];
        $policies = $canManage ? AttendancePolicy::query()->with('versions.statuses')->where('institution_id', $membership->institution_id)->orderBy('name')->get() : collect();

        return Inertia::render('workspace/attendance/index', [
            'tenant' => ['slug' => $tenant->slug], 'metrics' => $metrics, 'occurrences' => $occurrences,
            'policies' => $policies->map(fn (AttendancePolicy $policy): array => [
                'id' => $policy->getKey(), 'name' => $policy->name, 'campus_id' => $policy->campus_id,
                'version' => $policy->current_version, 'statuses' => $policy->versions->first()?->statuses->map(fn (AttendanceStatusDefinition $status): array => ['code' => $status->code, 'label' => $status->label, 'color' => $status->color])->all() ?? [],
                'settings' => $policy->versions->first()->config_snapshot,
            ]),
            'permissions' => ['manage_policy' => $canManage, 'mark' => $membership->hasPermission('attendance.register.mark'), 'view_reports' => $membership->hasPermission('attendance.report.view')],
            'institutionId' => (int) $membership->institution_id,
            'campuses' => Campus::query()->where('institution_id', $membership->institution_id)->orderBy('name')->get(['id', 'name'])->map(fn (Campus $campus): array => ['id' => $campus->getKey(), 'name' => $campus->name]),
            'settings' => ['theme' => $request->attributes->get('theme')],
        ]);
    }

    public function storePolicy(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'institution_id' => ['required', 'integer'], 'campus_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'],
            'minimum_attendance_percent' => ['nullable', 'numeric', 'between:0,100'], 'grace_period_minutes' => ['nullable', 'integer', 'between:0,240'],
            'late_after_minutes' => ['nullable', 'integer', 'between:0,1440'], 'partial_attendance_threshold' => ['nullable', 'integer', 'between:1,1440'],
            'marking_opens_before_minutes' => ['nullable', 'integer', 'between:0,10080'], 'marking_closes_after_minutes' => ['nullable', 'integer', 'between:0,43200'],
            'correction_deadline_days' => ['nullable', 'integer', 'between:0,365'], 'retroactive_marking' => ['sometimes', 'boolean'],
            'independent_correction_review' => ['sometimes', 'boolean'],
            'statuses' => ['sometimes', 'array'], 'statuses.*.label' => ['sometimes', 'string', 'max:60'],
            'statuses.*.color' => ['sometimes', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'], 'statuses.*.requires_reason' => ['sometimes', 'boolean'],
        ]);
        $settings = $data;
        $statuses = $settings['statuses'] ?? [];
        unset($settings['institution_id'], $settings['campus_id'], $settings['name'], $settings['statuses']);
        $this->workflow->createPolicy($request->user(), (int) $data['institution_id'], isset($data['campus_id']) ? (int) $data['campus_id'] : null, $data['name'], $settings, $statuses);

        return to_route('tenant.attendance.index', $tenant)->with('status', 'Attendance policy version created.');
    }

    public function updatePolicy(Request $request, Tenant $tenant, int $policyId): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'minimum_attendance_percent' => ['nullable', 'numeric', 'between:0,100'],
            'grace_period_minutes' => ['nullable', 'integer', 'between:0,240'], 'late_after_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'partial_attendance_threshold' => ['nullable', 'integer', 'between:1,1440'], 'marking_opens_before_minutes' => ['nullable', 'integer', 'between:0,10080'],
            'marking_closes_after_minutes' => ['nullable', 'integer', 'between:0,43200'], 'correction_deadline_days' => ['nullable', 'integer', 'between:0,365'],
            'retroactive_marking' => ['sometimes', 'boolean'], 'independent_correction_review' => ['sometimes', 'boolean'],
            'lock_on_finalize' => ['sometimes', 'boolean'], 'statuses' => ['sometimes', 'array'],
            'statuses.*.label' => ['sometimes', 'string', 'max:60'], 'statuses.*.color' => ['sometimes', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'statuses.*.requires_reason' => ['sometimes', 'boolean'],
        ]);
        $policy = AttendancePolicy::query()->whereKey($policyId)->firstOrFail();
        abort_unless((int) $policy->institution_id === (int) $this->context->membership()->institution_id, 404);
        $name = $data['name'];
        $statusSettings = $data['statuses'] ?? [];
        unset($data['name'], $data['statuses']);
        $this->workflow->revisePolicy($request->user(), $policyId, $name, $data, $statusSettings);

        return to_route('tenant.attendance.index', $tenant)->with('status', 'A new attendance policy version is effective; existing registers retain their prior policy.');
    }

    public function storeRegister(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['meeting_id' => ['required', 'integer'], 'occurrence_date' => ['required', 'date_format:Y-m-d']]);
        $register = $this->workflow->createRegister($request->user(), (int) $data['meeting_id'], $data['occurrence_date']);

        return to_route('tenant.attendance.registers.show', [$tenant, $register->getKey()])->with('status', 'Attendance register is ready. Unmarked learners remain unmarked.');
    }

    public function showRegister(Request $request, Tenant $tenant, int $registerId): InertiaResponse
    {
        $register = AttendanceRegister::query()->with(['entries.student', 'entries.statusDefinition', 'policyVersion.statuses'])->whereKey($registerId)->firstOrFail();
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('attendance.register.view') || $membership->hasPermission('attendance.register.override'), 403);
        if (! $membership->hasPermission('attendance.register.override')) {
            abort_unless((int) $register->instructor_user_id === (int) $request->user()->getKey(), 403);
            if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $register->campus_id) {
                abort(404);
            }
        }

        return Inertia::render('workspace/attendance/register', [
            'tenant' => ['slug' => $tenant->slug],
            'register' => ['id' => $register->getKey(), 'date' => $register->occurrence_date->toDateString(), 'status' => $register->status, 'version' => $register->version, 'expected_count' => $register->expected_count, 'entries' => $register->entries->map(fn ($entry): array => ['id' => $entry->getKey(), 'student_id' => $entry->student_id, 'student' => trim($entry->student->legal_first_name.' '.$entry->student->legal_last_name), 'student_number' => $entry->student->student_number, 'status_code' => $entry->statusDefinition?->code, 'reason_code' => $entry->reason_code, 'minutes_attended' => $entry->minutes_attended])],
            'statuses' => $register->policyVersion->statuses->map(fn (AttendanceStatusDefinition $status): array => ['id' => $status->getKey(), 'code' => $status->code, 'label' => $status->label, 'color' => $status->color, 'requires_reason' => $status->requires_reason]),
            'permissions' => ['mark' => $this->context->membership()->hasPermission('attendance.register.mark'), 'submit' => $this->context->membership()->hasPermission('attendance.register.submit'), 'override' => $this->context->membership()->hasPermission('attendance.register.override')],
        ]);
    }

    public function updateEntries(Request $request, Tenant $tenant, int $registerId): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.entry_id' => ['required', 'integer'], 'entries.*.status_code' => ['required', 'string', 'max:24'],
            'entries.*.reason_code' => ['nullable', 'string', 'max:64'], 'entries.*.minutes_attended' => ['nullable', 'integer', 'between:0,1440'],
            'entries.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->workflow->mark($request->user(), $registerId, (int) $data['version'], $data['entries']);

        return to_route('tenant.attendance.registers.show', [$tenant, $registerId])->with('status', 'Attendance changes saved.');
    }

    public function submitRegister(Request $request, Tenant $tenant, int $registerId): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->workflow->submit($request->user(), $registerId, (int) $data['version']);

        return to_route('tenant.attendance.registers.show', [$tenant, $registerId])->with('status', 'Attendance register submitted.');
    }

    public function finalizeRegister(Request $request, Tenant $tenant, int $registerId): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->workflow->finalize($request->user(), $registerId, (int) $data['version']);

        return to_route('tenant.attendance.registers.show', [$tenant, $registerId])->with('status', 'Attendance register finalized.');
    }

    public function reopenRegister(Request $request, Tenant $tenant, int $registerId): RedirectResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'min:8', 'max:64']]);
        $this->workflow->reopen($request->user(), $registerId, $data['reason_code']);

        return to_route('tenant.attendance.registers.show', [$tenant, $registerId])->with('status', 'Register reopened with its previous revisions preserved.');
    }

    public function student(Request $request, Tenant $tenant): InertiaResponse
    {
        return Inertia::render('workspace/attendance/student', ['tenant' => ['slug' => $tenant->slug], 'summary' => $this->workflow->studentSummary($request->user())]);
    }

    public function guardians(Request $request, Tenant $tenant): InertiaResponse
    {
        $students = SisStudentGuardian::query()
            ->with('student:id,student_number,legal_first_name,legal_middle_name,legal_last_name,preferred_name')
            ->where('guardian_user_id', $request->user()->getKey())
            ->where('authority_status', 'verified')
            ->where('can_view_profile', true)
            ->whereNull('revoked_at')
            ->whereHas('student')
            ->orderBy('id')
            ->get()
            ->map(function (SisStudentGuardian $authorization) use ($tenant): array {
                $student = $authorization->student;
                $legalName = collect([$student?->legal_first_name, $student?->legal_middle_name, $student?->legal_last_name])
                    ->filter(fn (?string $part): bool => filled($part))
                    ->implode(' ');

                return [
                    'id' => (int) $authorization->student_id,
                    'name' => trim((string) ($student?->preferred_name ?: $legalName)) ?: 'Linked learner',
                    'student_number' => (string) ($student->student_number ?? ''),
                    'href' => route('tenant.attendance.guardian', [$tenant, $authorization->student_id]),
                ];
            })->values();

        return Inertia::render('workspace/attendance/guardian-index', [
            'tenant' => ['slug' => $tenant->slug],
            'students' => $students,
        ]);
    }

    public function guardian(Request $request, Tenant $tenant, int $studentId): InertiaResponse
    {
        return Inertia::render('workspace/attendance/guardian', [
            'tenant' => ['slug' => $tenant->slug], 'summary' => $this->workflow->guardianSummary($request->user(), $studentId),
        ]);
    }

    public function reports(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless($this->context->membership()->hasPermission('attendance.report.view'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'campus_id' => ['nullable', 'integer'], 'instructor_user_id' => ['nullable', 'integer'],
        ]);
        $membership = $this->context->membership();
        $campusId = isset($filters['campus_id']) ? (int) $filters['campus_id'] : null;
        if ($campusId !== null) {
            abort_unless(Campus::query()->where('institution_id', $membership->institution_id)->whereKey($campusId)->exists(), 404);
            if ($membership->campus_id !== null) {
                abort_unless((int) $membership->campus_id === $campusId, 404);
            }
        } elseif ($membership->campus_id !== null) {
            $campusId = (int) $membership->campus_id;
        }
        $instructorId = isset($filters['instructor_user_id']) ? (int) $filters['instructor_user_id'] : null;
        if ($instructorId !== null) {
            abort_unless(DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->where('institution_id', $membership->institution_id)
                ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))->where('instructor_user_id', $instructorId)->exists(), 404);
        }
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $rows = DB::table('attendance_registers as r')
            ->join('attendance_entries as e', function ($join): void {
                $join->on('e.register_id', '=', 'r.id')->on('e.tenant_id', '=', 'r.tenant_id');
            })
            ->leftJoin('attendance_status_definitions as s', function ($join): void {
                $join->on('s.id', '=', 'e.status_definition_id')->on('s.tenant_id', '=', 'e.tenant_id');
            })
            ->join('academic_timetable_meetings as m', function ($join): void {
                $join->on('m.id', '=', 'r.meeting_id')->on('m.tenant_id', '=', 'r.tenant_id');
            })
            ->join('course_sections as cs', function ($join): void {
                $join->on('cs.id', '=', 'm.section_id')->on('cs.tenant_id', '=', 'r.tenant_id');
            })
            ->join('course_offerings as o', function ($join): void {
                $join->on('o.id', '=', 'cs.offering_id')->on('o.tenant_id', '=', 'r.tenant_id');
            })
            ->join('academic_courses as c', function ($join): void {
                $join->on('c.id', '=', 'o.academic_course_id')->on('c.tenant_id', '=', 'r.tenant_id');
            })
            ->where('r.tenant_id', $tenant->getKey())->where('r.institution_id', $membership->institution_id)
            ->whereIn('r.status', ['submitted', 'finalized', 'locked'])->whereDate('r.occurrence_date', '>=', $from)->whereDate('r.occurrence_date', '<=', $to)
            ->when($campusId !== null, fn ($query) => $query->where('r.campus_id', $campusId))
            ->when($instructorId !== null, fn ($query) => $query->where('r.instructor_user_id', $instructorId))
            ->selectRaw('r.occurrence_date, r.campus_id, r.meeting_id, c.title as course, cs.section_code, COUNT(e.id) as roster_count, SUM(CASE WHEN e.status_definition_id IS NULL THEN 1 ELSE 0 END) as unmarked_count, SUM(CASE WHEN s.counts_expected = 1 THEN 1 ELSE 0 END) as expected_count, SUM(CASE WHEN s.counts_expected = 1 AND s.counts_attended = 1 THEN 1 ELSE 0 END) as attended_count, SUM(CASE WHEN s.code = ? THEN 1 ELSE 0 END) as absent_count', ['ABSENT'])
            ->groupBy('r.occurrence_date', 'r.campus_id', 'r.meeting_id', 'c.title', 'cs.section_code')
            ->orderByDesc('r.occurrence_date')->orderBy('c.title')->paginate(50)->withQueryString();
        // MySQL may return aggregate expressions as numeric strings while SQLite returns
        // integers. Keep the Inertia contract stable across supported test engines.
        $rows->getCollection()->transform(static function (object $row): object {
            foreach (['roster_count', 'unmarked_count', 'expected_count', 'attended_count', 'absent_count'] as $count) {
                $row->{$count} = (int) $row->{$count};
            }

            return $row;
        });

        return Inertia::render('workspace/attendance/reports', [
            'tenant' => ['slug' => $tenant->slug], 'filters' => ['from' => $from, 'to' => $to, 'campus_id' => $campusId, 'instructor_user_id' => $instructorId],
            'rows' => $rows, 'campuses' => Campus::query()->where('institution_id', $membership->institution_id)->orderBy('name')->get(['id', 'name']),
            'instructors' => DB::table('attendance_registers as r')->join('users as u', 'u.id', '=', 'r.instructor_user_id')
                ->where('r.tenant_id', $tenant->getKey())->where('r.institution_id', $membership->institution_id)
                ->when($campusId !== null, fn ($query) => $query->where('r.campus_id', $campusId))
                ->select('u.id', 'u.name')->distinct()->orderBy('u.name')->get(),
        ]);
    }

    public function exportStudent(Request $request, Tenant $tenant): StreamedResponse
    {
        $summary = $this->workflow->studentSummary($request->user());

        return response()->streamDownload(function () use ($summary): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Course', 'Attendance status']);
            foreach ($summary['entries'] as $entry) {
                fputcsv($output, [$entry['date'], $entry['course'], $entry['status']]);
            }
            fclose($output);
        }, 'my-attendance.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function exportReports(Request $request, Tenant $tenant): StreamedResponse
    {
        abort_unless($this->context->membership()->hasPermission('attendance.report.view'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'campus_id' => ['nullable', 'integer'], 'instructor_user_id' => ['nullable', 'integer'],
        ]);
        $membership = $this->context->membership();
        $campusId = isset($filters['campus_id']) ? (int) $filters['campus_id'] : null;
        if ($campusId !== null) {
            abort_unless(Campus::query()->where('institution_id', $membership->institution_id)->whereKey($campusId)->exists(), 404);
            if ($membership->campus_id !== null) {
                abort_unless((int) $membership->campus_id === $campusId, 404);
            }
        } elseif ($membership->campus_id !== null) {
            $campusId = (int) $membership->campus_id;
        }
        $instructorId = isset($filters['instructor_user_id']) ? (int) $filters['instructor_user_id'] : null;
        if ($instructorId !== null) {
            abort_unless(DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->where('institution_id', $membership->institution_id)
                ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))->where('instructor_user_id', $instructorId)->exists(), 404);
        }
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366, 422, 'Attendance exports are limited to a 12-month date range.');
        $tenantId = (int) $tenant->getKey();
        $institutionId = (int) $membership->institution_id;

        return response()->streamDownload(function () use ($tenantId, $institutionId, $from, $to, $campusId, $instructorId): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Campus ID', 'Meeting ID', 'Course', 'Section', 'Roster', 'Expected', 'Attended', 'Absent', 'Unmarked']);
            $query = DB::table('attendance_registers as r')
                ->join('attendance_entries as e', fn ($join) => $join->on('e.register_id', '=', 'r.id')->on('e.tenant_id', '=', 'r.tenant_id'))
                ->leftJoin('attendance_status_definitions as s', fn ($join) => $join->on('s.id', '=', 'e.status_definition_id')->on('s.tenant_id', '=', 'e.tenant_id'))
                ->join('academic_timetable_meetings as m', fn ($join) => $join->on('m.id', '=', 'r.meeting_id')->on('m.tenant_id', '=', 'r.tenant_id'))
                ->join('course_sections as cs', fn ($join) => $join->on('cs.id', '=', 'm.section_id')->on('cs.tenant_id', '=', 'r.tenant_id'))
                ->join('course_offerings as o', fn ($join) => $join->on('o.id', '=', 'cs.offering_id')->on('o.tenant_id', '=', 'r.tenant_id'))
                ->join('academic_courses as c', fn ($join) => $join->on('c.id', '=', 'o.academic_course_id')->on('c.tenant_id', '=', 'r.tenant_id'))
                ->where('r.tenant_id', $tenantId)->where('r.institution_id', $institutionId)
                ->whereIn('r.status', ['submitted', 'finalized', 'locked'])->whereDate('r.occurrence_date', '>=', $from)->whereDate('r.occurrence_date', '<=', $to)
                ->when($campusId !== null, fn ($query) => $query->where('r.campus_id', $campusId))
                ->when($instructorId !== null, fn ($query) => $query->where('r.instructor_user_id', $instructorId))
                ->selectRaw('r.occurrence_date, r.campus_id, r.meeting_id, c.title as course, cs.section_code, COUNT(e.id) as roster_count, SUM(CASE WHEN e.status_definition_id IS NULL THEN 1 ELSE 0 END) as unmarked_count, SUM(CASE WHEN s.counts_expected = 1 THEN 1 ELSE 0 END) as expected_count, SUM(CASE WHEN s.counts_expected = 1 AND s.counts_attended = 1 THEN 1 ELSE 0 END) as attended_count, SUM(CASE WHEN s.code = ? THEN 1 ELSE 0 END) as absent_count', ['ABSENT'])
                ->groupBy('r.occurrence_date', 'r.campus_id', 'r.meeting_id', 'c.title', 'cs.section_code')
                ->orderBy('r.occurrence_date')->orderBy('c.title')->cursor();
            foreach ($query as $row) {
                fputcsv($output, [$row->occurrence_date, $row->campus_id, $row->meeting_id, $row->course, $row->section_code, (int) $row->roster_count, (int) $row->expected_count, (int) $row->attended_count, (int) $row->absent_count, (int) $row->unmarked_count]);
            }
            fclose($output);
        }, 'attendance-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function storeJustification(Request $request, Tenant $tenant, int $entryId): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['illness', 'family', 'official', 'other'])],
            'reason' => ['required', 'string', 'min:12', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $evidenceId = null;
        $justification = DB::transaction(function () use ($request, $entryId, $data, &$evidenceId): AttendanceJustification {
            $justification = $this->workflow->requestJustification($request->user(), $entryId, $data['category'], $data['reason']);
            if ($request->file('evidence') instanceof UploadedFile) {
                $evidenceId = $this->storeJustificationEvidence($request, $justification, $request->file('evidence'));
            }

            return $justification;
        });
        if ($evidenceId !== null) {
            ScanAttendanceJustificationEvidence::dispatch((int) $this->context->tenant()->getKey(), $evidenceId);
        }

        return back()->with('status', 'Absence justification submitted for review.');
    }

    public function justifications(Tenant $tenant): InertiaResponse
    {
        abort_unless($this->context->membership()->hasPermission('attendance.justification.review'), 403);
        $items = AttendanceJustification::query()->with(['entry.register', 'entry.student', 'entry.statusDefinition', 'evidence'])->where('status', 'pending')->latest()->paginate(30);

        return Inertia::render('workspace/attendance/justifications', ['tenant' => ['slug' => $tenant->slug], 'items' => $items]);
    }

    public function downloadJustificationEvidence(Request $request, Tenant $tenant, int $evidenceId): StreamedResponse
    {
        abort_unless($this->context->membership()->hasPermission('attendance.justification.review'), 403);
        $evidence = AttendanceJustificationEvidence::query()->whereKey($evidenceId)->firstOrFail();
        abort_unless($evidence->scan_status === 'clean', 404);
        $tenantId = (int) $this->context->tenant()->getKey();
        abort_unless(preg_match('/\Aattendance\/'.preg_quote((string) $tenantId, '/').'\/quarantine\/[a-f0-9-]{36}\.(pdf|jpg|png)\z/iD', $evidence->private_path) === 1, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($evidence->private_path), 404);
        $absolutePath = $disk->path($evidence->private_path);
        abort_unless(hash_equals($evidence->sha256, (string) hash_file('sha256', $absolutePath)), 404);
        app(AuditRecorder::class)->record('attendance.justification.evidence.downloaded', $request->user(), $tenantId, $evidence, ['evidence_id' => $evidence->getKey()]);

        return $disk->download($evidence->private_path, 'attendance-evidence-'.$evidence->getKey().'.'.pathinfo($evidence->private_path, PATHINFO_EXTENSION), [
            'Content-Type' => $evidence->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storeJustificationEvidence(Request $request, AttendanceJustification $justification, UploadedFile $file): int
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            default => throw ValidationException::withMessages(['evidence' => 'Upload a PDF, JPEG, or PNG file.']),
        };
        $filename = (string) Str::uuid().'.'.$extension;
        $path = 'attendance/'.$tenantId.'/quarantine/'.$filename;
        $stored = $file->storeAs(dirname($path), basename($path), 'local');
        if ($stored !== $path) {
            throw new \RuntimeException('Attendance evidence could not be stored in private quarantine.');
        }
        $absolutePath = Storage::disk('local')->path($path);
        try {
            $evidence = AttendanceJustificationEvidence::query()->create([
                'justification_id' => $justification->getKey(), 'private_path' => $path,
                'sha256' => hash_file('sha256', $absolutePath) ?: '', 'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(), 'scan_status' => 'quarantined', 'uploaded_by' => $request->user()->getKey(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        app(AuditRecorder::class)->record('attendance.justification.evidence.quarantined', $request->user(), $tenantId, $evidence, ['mime_type' => $evidence->mime_type, 'size_bytes' => $evidence->size_bytes]);

        return (int) $evidence->getKey();
    }

    public function justificationDecision(Request $request, Tenant $tenant, int $justificationId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reviewer_note' => ['nullable', 'string', 'max:1000']]);
        $this->workflow->reviewJustification($request->user(), $justificationId, $data['decision'] === 'approve', $data['reviewer_note'] ?? null);

        return back()->with('status', 'Justification decision recorded.');
    }

    public function requestCorrection(Request $request, Tenant $tenant, int $entryId): RedirectResponse
    {
        $data = $request->validate(['status_code' => ['required', 'string', 'max:24'], 'reason' => ['required', 'string', 'min:12', 'max:2000']]);
        $entry = AttendanceEntry::query()->with('register')->whereKey($entryId)->firstOrFail();
        $status = AttendanceStatusDefinition::query()->where('policy_version_id', $entry->register->policy_version_id)->where('code', $data['status_code'])->firstOrFail();
        $this->workflow->requestCorrection($request->user(), $entryId, (int) $status->getKey(), $data['reason']);

        return back()->with('status', 'Correction request submitted for independent review.');
    }

    public function correctionDecision(Request $request, Tenant $tenant, int $correctionId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reviewer_note' => ['nullable', 'string', 'max:1000']]);
        $this->workflow->reviewCorrection($request->user(), $correctionId, $data['decision'] === 'approve', $data['reviewer_note'] ?? null);

        return back()->with('status', 'Correction decision recorded.');
    }

    public function corrections(Tenant $tenant): InertiaResponse
    {
        $items = AttendanceCorrectionRequest::query()->with(['entry.register', 'entry.student', 'entry.statusDefinition'])->where('status', 'pending')->latest()->paginate(30);
        abort_unless($this->context->membership()->hasPermission('attendance.correction.review'), 403);

        return Inertia::render('workspace/attendance/corrections', ['tenant' => ['slug' => $tenant->slug], 'items' => $items]);
    }
}
