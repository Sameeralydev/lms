<?php

namespace App\Domain\Academic\Attendance;

use App\Domain\Academic\Timetabling\LocalScheduleTime;
use App\Domain\Academic\Timetabling\TimetableOccurrenceProjector;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicTimetableMeeting;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceEntry;
use App\Models\AttendanceEntryRevision;
use App\Models\AttendanceJustification;
use App\Models\AttendanceJustificationEvidence;
use App\Models\AttendancePolicy;
use App\Models\AttendancePolicyVersion;
use App\Models\AttendanceRegister;
use App\Models\AttendanceStatusDefinition;
use App\Models\CourseEnrollment;
use App\Models\Institution;
use App\Models\SisStudentGuardian;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Attendance decisions are owned here; timetable occurrences remain owned by Phase 09. */
final class AttendanceWorkflow
{
    private const STATUSES = [
        'PRESENT' => ['Present', '#15803d', true, true, false],
        'ABSENT' => ['Absent', '#b91c1c', false, true, false],
        'LATE' => ['Late', '#b45309', true, true, false],
        'EXCUSED' => ['Excused absence', '#64748b', false, false, true],
        'AUTHORIZED_LEAVE' => ['Authorized leave', '#475569', false, false, true],
        'PARTIAL' => ['Partially attended', '#0369a1', true, true, false],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TimetableOccurrenceProjector $projector,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $settings
     * @param  array<string,array{label?:string,color?:string,requires_reason?:bool}>  $statusSettings
     */
    public function createPolicy(User $actor, int $institutionId, ?int $campusId, string $name, array $settings = [], array $statusSettings = []): AttendancePolicy
    {
        $membership = $this->context->membership();
        $this->requirePermission('attendance.policy.manage');
        $institution = Institution::query()->whereKey($institutionId)->firstOrFail();
        abort_unless((int) $membership->institution_id === (int) $institution->getKey(), 404);
        if ($campusId !== null && ! $institution->campuses()->whereKey($campusId)->exists()) {
            throw ValidationException::withMessages(['campus_id' => 'Select a campus belonging to this institution.']);
        }
        $this->assertActor($actor);
        $config = $this->normalizeSettings($settings);

        return DB::transaction(function () use ($actor, $institution, $campusId, $name, $config, $statusSettings, $membership): AttendancePolicy {
            $policy = AttendancePolicy::query()->create([
                'institution_id' => $institution->getKey(), 'campus_id' => $campusId, 'name' => $name,
                'campus_scope_key' => $campusId === null ? 'institution' : 'campus:'.$campusId,
                'status' => 'active', 'current_version' => 1, 'created_by' => $actor->getKey(),
            ]);
            $version = AttendancePolicyVersion::query()->create([
                'policy_id' => $policy->getKey(), 'version' => 1, 'effective_from' => now(),
                'config_snapshot' => $config, 'created_by' => $actor->getKey(),
            ]);
            $this->createStatuses($version, $statusSettings);
            $this->audit->record('attendance.policy.created', $actor, (int) $membership->tenant_id, $policy, ['version' => 1, 'campus_id' => $campusId]);

            return $policy->load('versions.statuses');
        });
    }

    /**
     * Policy changes append a new semantic version; existing registers retain their original version.
     *
     * @param  array<string,mixed>  $settings
     * @param  array<string,array{label?:string,color?:string,requires_reason?:bool}>  $statusSettings
     */
    public function revisePolicy(User $actor, int $policyId, string $name, array $settings = [], array $statusSettings = []): AttendancePolicy
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.policy.manage');

        return DB::transaction(function () use ($actor, $policyId, $name, $settings, $statusSettings): AttendancePolicy {
            $policy = AttendancePolicy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $policy->institution_id === (int) $this->context->membership()->institution_id, 404);
            $versionNumber = (int) $policy->current_version + 1;
            $version = AttendancePolicyVersion::query()->create([
                'policy_id' => $policy->getKey(), 'version' => $versionNumber, 'effective_from' => now(),
                'config_snapshot' => $this->normalizeSettings($settings), 'created_by' => $actor->getKey(),
            ]);
            $this->createStatuses($version, $statusSettings);
            $policy->forceFill(['name' => $name, 'current_version' => $versionNumber])->save();
            $this->audit->record('attendance.policy.versioned', $actor, (int) $policy->tenant_id, $policy, ['version' => $versionNumber]);

            return $policy->fresh()->load('versions.statuses');
        }, 3);
    }

    /** Creates a register exactly once for a projected, published Phase 09 occurrence. */
    public function createRegister(User $actor, int $meetingId, string $date): AttendanceRegister
    {
        $this->assertActor($actor);
        $meeting = AcademicTimetableMeeting::query()->with(['timetable', 'section.offering'])->whereKey($meetingId)->firstOrFail();
        $date = Carbon::parse($date)->toDateString();
        $this->assertCampusAccess((int) $meeting->section->campus_id);
        if ($meeting->status !== 'scheduled' || $meeting->timetable->status !== 'published' || $meeting->section->status !== 'open') {
            throw ValidationException::withMessages(['occurrence' => 'Attendance is available only for a scheduled occurrence in an active section and published timetable.']);
        }
        $projection = collect($this->projector->project($meeting))->firstWhere('date', $date);
        if ($projection === null) {
            throw ValidationException::withMessages(['occurrence_date' => 'The selected date is not an active occurrence in the published timetable.']);
        }
        $tenantId = (int) $this->context->tenant()->getKey();
        $institutionId = (int) $meeting->section->offering->institution_id;
        $campusId = $meeting->section->campus_id === null ? null : (int) $meeting->section->campus_id;
        $closure = DB::table('academic_calendar_exceptions')->where('tenant_id', $tenantId)->where('institution_id', $institutionId)
            ->where(function ($query) use ($campusId): void {
                $query->whereNull('campus_id')->orWhere('campus_id', $campusId);
            })
            ->where('exception_type', 'closure')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists();
        if ($closure) {
            throw ValidationException::withMessages(['occurrence_date' => 'No attendance register can be created for an institution or campus closure.']);
        }
        $instructorChange = DB::table('academic_timetable_changes')->where('tenant_id', $tenantId)->where('meeting_id', $meetingId)
            ->where('status', 'approved')
            ->where(function ($query) use ($date): void {
                $query->where(function ($substitution) use ($date): void {
                    $substitution->where('change_type', 'substitution')->whereDate('occurrence_date', $date);
                })->orWhere(function ($replacement) use ($date): void {
                    $replacement->whereIn('change_type', ['makeup', 'reschedule'])->whereDate('replacement_date', $date);
                });
            })->orderByDesc('id')->value('instructor_user_id');
        $assignedInstructorId = $instructorChange === null ? (int) $meeting->instructor_user_id : (int) $instructorChange;
        $this->authorizeRegisterAction($actor, $assignedInstructorId, $campusId, 'attendance.register.mark');
        $policyVersion = $this->effectivePolicyVersion($institutionId, $campusId, $date);
        if ($policyVersion === null) {
            throw ValidationException::withMessages(['policy' => 'Configure an active attendance policy before opening this class register.']);
        }

        try {
            return DB::transaction(function () use ($actor, $meetingId, $date, $tenantId, $institutionId, $campusId, $meeting, $assignedInstructorId, $policyVersion): AttendanceRegister {
                AcademicTimetableMeeting::query()->whereKey($meetingId)->lockForUpdate()->firstOrFail();
                $existing = AttendanceRegister::query()->where('meeting_id', $meetingId)->whereDate('occurrence_date', $date)->first();
                if ($existing !== null) {
                    return $existing->load('entries.student');
                }
                $at = Carbon::parse($date)->endOfDay();
                $enrollments = CourseEnrollment::query()->with('student')->where('section_id', $meeting->section_id)
                    ->whereIn('status', ['confirmed', 'withdrawn'])
                    ->where(function ($query) use ($at): void {
                        $query->whereNull('confirmed_at')->orWhere('confirmed_at', '<=', $at);
                    })
                    ->get()->filter(static function (CourseEnrollment $enrollment) use ($at): bool {
                        if ($enrollment->status === 'withdrawn' && $enrollment->withdrawn_effective_at !== null && $enrollment->withdrawn_effective_at->lte($at)) {
                            return false;
                        }

                        return $enrollment->student?->status === 'active';
                    })->values();
                $register = AttendanceRegister::query()->create([
                    'institution_id' => $institutionId, 'campus_id' => $campusId, 'meeting_id' => $meetingId,
                    'occurrence_date' => $date, 'policy_version_id' => $policyVersion->getKey(),
                    'instructor_user_id' => $assignedInstructorId, 'delivery_mode' => $meeting->delivery_mode,
                    'status' => 'draft', 'expected_count' => $enrollments->count(), 'version' => 1,
                    'roster_snapshotted_at' => now(),
                ]);
                foreach ($enrollments as $enrollment) {
                    AttendanceEntry::query()->create([
                        'register_id' => $register->getKey(), 'student_id' => $enrollment->student_id,
                        'enrollment_id' => $enrollment->getKey(), 'status_definition_id' => null,
                        'version' => 1,
                    ]);
                }
                $this->recordOutbox($tenantId, 'register-created-'.$register->getKey(), 'attendance.register.created', ['register_id' => $register->getKey()]);
                $this->audit->record('attendance.register.created', $actor, $tenantId, $register, ['meeting_id' => $meetingId, 'occurrence_date' => $date, 'expected_count' => $enrollments->count()]);

                return $register->load('entries.student');
            }, 3);
        } catch (QueryException $exception) {
            // This is a current read: under MySQL REPEATABLE READ, the unique-key
            // loser may have established its snapshot before the winner commits.
            $existing = AttendanceRegister::query()->where('meeting_id', $meetingId)->whereDate('occurrence_date', $date)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing->load('entries.student');
            }

            throw $exception;
        }
    }

    /** @param list<array{entry_id:int,status_code:string,reason_code?:?string,minutes_attended?:?int,note?:?string}> $items */
    public function mark(User $actor, int $registerId, int $expectedVersion, array $items): AttendanceRegister
    {
        return DB::transaction(function () use ($actor, $registerId, $expectedVersion, $items): AttendanceRegister {
            $register = AttendanceRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $this->authorizeRegisterAction($actor, (int) $register->instructor_user_id, $register->campus_id === null ? null : (int) $register->campus_id, 'attendance.register.mark');
            if (! in_array($register->status, ['draft', 'reopened'], true)) {
                throw ValidationException::withMessages(['register' => 'This register is not editable in its current state.']);
            }
            if ((int) $register->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'This register changed in another session. Reload before editing.']);
            }
            $this->assertMarkingWindow($register, $actor);
            $statuses = AttendanceStatusDefinition::query()->where('policy_version_id', $register->policy_version_id)->get()->keyBy('code');
            foreach ($items as $item) {
                $entry = AttendanceEntry::query()->where('register_id', $registerId)->whereKey($item['entry_id'])->lockForUpdate()->firstOrFail();
                $status = $statuses->get($item['status_code']);
                if ($status === null || $status->code === 'NOT_MARKED') {
                    throw ValidationException::withMessages(['entries' => 'Choose an attendance status from the register’s effective policy.']);
                }
                $reason = trim((string) ($item['reason_code'] ?? ''));
                if ($status->requires_reason && $reason === '') {
                    throw ValidationException::withMessages(['entries' => "A reason is required for {$status->label}."]);
                }
                $oldStatusId = $entry->status_definition_id === null ? null : (int) $entry->status_definition_id;
                $oldMinutes = $entry->minutes_attended === null ? null : (int) $entry->minutes_attended;
                $newStatusId = (int) $status->getKey();
                $minutes = isset($item['minutes_attended']) ? (int) $item['minutes_attended'] : null;
                if ($status->code === 'PARTIAL' && ($minutes === null || $minutes < 1 || $minutes > 1440)) {
                    throw ValidationException::withMessages(['entries' => 'Record valid attended minutes for a partial attendance entry.']);
                }
                if ($oldStatusId !== $newStatusId || $entry->minutes_attended !== $minutes || $entry->reason_code !== ($reason ?: null)) {
                    $entry->forceFill(['status_definition_id' => $newStatusId, 'minutes_attended' => $minutes, 'reason_code' => $reason ?: null,
                        'instructor_note' => $item['note'] ?? null, 'marked_by' => $actor->getKey(), 'marked_at' => now(), 'version' => (int) $entry->version + 1])->save();
                    AttendanceEntryRevision::query()->create([
                        'entry_id' => $entry->getKey(), 'previous_status_id' => $oldStatusId, 'new_status_id' => $newStatusId,
                        'previous_minutes_attended' => $oldMinutes, 'new_minutes_attended' => $minutes,
                        'reason_code' => $reason ?: null, 'actor_user_id' => $actor->getKey(), 'change_source' => 'instructor_mark', 'created_at' => now(),
                    ]);
                }
            }
            $register->forceFill(['version' => (int) $register->version + 1])->save();
            $this->audit->record('attendance.register.marked', $actor, (int) $register->tenant_id, $register, ['entry_count' => count($items), 'version' => $register->version]);

            return $register->fresh()->load('entries.student', 'entries.statusDefinition');
        }, 3);
    }

    public function submit(User $actor, int $registerId, int $expectedVersion): AttendanceRegister
    {
        return DB::transaction(function () use ($actor, $registerId, $expectedVersion): AttendanceRegister {
            $register = AttendanceRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $this->authorizeRegisterAction($actor, (int) $register->instructor_user_id, $register->campus_id === null ? null : (int) $register->campus_id, 'attendance.register.submit');
            if ($register->status !== 'draft' && $register->status !== 'reopened') {
                throw ValidationException::withMessages(['register' => 'Only a draft or reopened register can be submitted.']);
            }
            if ((int) $register->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'This register changed in another session. Reload before submitting.']);
            }
            if ($register->entries()->whereNull('status_definition_id')->exists()) {
                throw ValidationException::withMessages(['entries' => 'Mark every expected learner explicitly before submitting. Unmarked learners are not automatically recorded absent.']);
            }
            $register->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->getKey(), 'version' => (int) $register->version + 1])->save();
            $this->audit->record('attendance.register.submitted', $actor, (int) $register->tenant_id, $register, ['version' => $register->version, 'expected_count' => $register->expected_count]);
            $this->recordOutbox((int) $register->tenant_id, 'register-submitted-'.$register->getKey().'-'.$register->version, 'attendance.register.submitted', ['register_id' => $register->getKey()]);

            return $register->fresh()->load('entries.student', 'entries.statusDefinition');
        }, 3);
    }

    public function finalize(User $actor, int $registerId, int $expectedVersion): AttendanceRegister
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.register.override');

        return DB::transaction(function () use ($actor, $registerId, $expectedVersion): AttendanceRegister {
            $register = AttendanceRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $this->assertCampusAccess($register->campus_id === null ? null : (int) $register->campus_id);
            if ($register->status !== 'submitted' || (int) $register->version !== $expectedVersion) {
                throw ValidationException::withMessages(['register' => 'Only the current submitted register version can be finalized.']);
            }
            $policy = AttendancePolicyVersion::query()->findOrFail($register->policy_version_id);
            if (($policy->config_snapshot['independent_correction_review'] ?? true) && (int) $register->submitted_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['register' => 'A register submitter cannot finalize their own register under the active review policy.']);
            }
            $lock = (bool) ($policy->config_snapshot['lock_on_finalize'] ?? true);
            $register->forceFill([
                'status' => $lock ? 'locked' : 'finalized', 'finalized_at' => now(), 'finalized_by' => $actor->getKey(),
                'locked_at' => $lock ? now() : null, 'locked_by' => $lock ? $actor->getKey() : null,
                'version' => (int) $register->version + 1,
            ])->save();
            $this->audit->record('attendance.register.finalized', $actor, (int) $register->tenant_id, $register, ['locked' => $lock, 'version' => $register->version]);
            $this->recordOutbox((int) $register->tenant_id, 'register-finalized-'.$register->getKey().'-'.$register->version, 'attendance.register.finalized', ['register_id' => $register->getKey()]);

            return $register->fresh();
        }, 3);
    }

    public function reopen(User $actor, int $registerId, string $reasonCode): AttendanceRegister
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.register.override');

        return DB::transaction(function () use ($actor, $registerId, $reasonCode): AttendanceRegister {
            $register = AttendanceRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $this->assertCampusAccess($register->campus_id === null ? null : (int) $register->campus_id);
            if (! in_array($register->status, ['locked', 'finalized'], true)) {
                throw ValidationException::withMessages(['register' => 'Only a finalized or locked register can be reopened.']);
            }
            $prior = ['status' => $register->status, 'locked_at' => $register->locked_at?->toIso8601String(), 'locked_by' => $register->locked_by];
            $register->forceFill(['status' => 'reopened', 'reopened_at' => now(), 'reopened_by' => $actor->getKey(), 'reopen_reason_code' => $reasonCode, 'version' => (int) $register->version + 1])->save();
            $this->audit->record('attendance.register.reopened', $actor, (int) $register->tenant_id, $register, ['reason_code' => $reasonCode, 'prior_state' => $prior]);
            $this->recordOutbox((int) $register->tenant_id, 'register-reopened-'.$register->getKey().'-'.$register->version, 'attendance.register.reopened', ['register_id' => $register->getKey()]);

            return $register->fresh();
        }, 3);
    }

    public function requestJustification(User $actor, int $entryId, string $category, string $reason): AttendanceJustification
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.justification.create');

        return DB::transaction(function () use ($actor, $entryId, $category, $reason): AttendanceJustification {
            $entry = AttendanceEntry::query()->with(['student', 'register', 'statusDefinition'])->whereKey($entryId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $entry->student->user_id === (int) $actor->getKey(), 404);
            if (! in_array($entry->statusDefinition?->code, ['ABSENT', 'LATE'], true)) {
                throw ValidationException::withMessages(['justification' => 'A justification can be requested only for a recorded absence or late arrival.']);
            }
            $version = AttendancePolicyVersion::query()->findOrFail($entry->register->policy_version_id);
            $deadline = $entry->register->occurrence_date->copy()->addDays((int) ($version->config_snapshot['correction_deadline_days'] ?? 0))->endOfDay();
            if (now()->gt($deadline)) {
                throw ValidationException::withMessages(['justification' => 'The institution’s justification deadline has passed.']);
            }
            if (AttendanceJustification::query()->where('entry_id', $entryId)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['justification' => 'A justification request is already pending for this occurrence.']);
            }
            $justification = AttendanceJustification::query()->create([
                'entry_id' => $entryId, 'student_user_id' => $actor->getKey(), 'open_key' => 'entry:'.$entryId,
                'category' => $category, 'reason' => $reason, 'status' => 'pending',
            ]);
            $this->audit->record('attendance.justification.submitted', $actor, (int) $entry->tenant_id, $justification, ['entry_id' => $entryId, 'category' => $category]);
            $this->recordOutbox((int) $entry->tenant_id, 'justification-submitted-'.$justification->getKey(), 'attendance.justification.submitted', ['justification_id' => $justification->getKey()]);

            return $justification;
        }, 3);
    }

    public function reviewJustification(User $actor, int $justificationId, bool $approve, ?string $reviewerNote = null): AttendanceJustification
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.justification.review');

        return DB::transaction(function () use ($actor, $justificationId, $approve, $reviewerNote): AttendanceJustification {
            $justification = AttendanceJustification::query()->with(['entry.register'])->whereKey($justificationId)->lockForUpdate()->firstOrFail();
            $this->assertCampusAccess($justification->entry->register->campus_id === null ? null : (int) $justification->entry->register->campus_id);
            if ($justification->status !== 'pending') {
                throw ValidationException::withMessages(['justification' => 'This justification has already been reviewed.']);
            }
            if ($approve && AttendanceJustificationEvidence::query()->where('justification_id', $justification->getKey())->where('scan_status', '!=', 'clean')->exists()) {
                throw ValidationException::withMessages(['justification' => 'Supporting evidence must finish security scanning before the request can be approved.']);
            }
            if ((int) $justification->student_user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['justification' => 'A learner cannot review their own justification.']);
            }
            $justification->forceFill(['status' => $approve ? 'approved' : 'rejected', 'open_key' => null, 'reviewed_by' => $actor->getKey(), 'reviewer_note' => $reviewerNote, 'reviewed_at' => now()])->save();
            if ($approve) {
                $entry = AttendanceEntry::query()->whereKey($justification->entry_id)->lockForUpdate()->firstOrFail();
                $status = AttendanceStatusDefinition::query()->where('policy_version_id', $entry->register->policy_version_id)->where('code', 'EXCUSED')->firstOrFail();
                $previousStatusId = $entry->status_definition_id;
                $previousMinutes = $entry->minutes_attended;
                $entry->forceFill(['status_definition_id' => $status->getKey(), 'reason_code' => $justification->category, 'marked_by' => $actor->getKey(), 'marked_at' => now(), 'version' => (int) $entry->version + 1])->save();
                AttendanceEntryRevision::query()->create(['entry_id' => $entry->getKey(), 'previous_status_id' => $previousStatusId, 'new_status_id' => $status->getKey(), 'previous_minutes_attended' => $previousMinutes, 'new_minutes_attended' => $entry->minutes_attended, 'reason_code' => 'approved_justification', 'actor_user_id' => $actor->getKey(), 'change_source' => 'justification', 'created_at' => now()]);
                $register = AttendanceRegister::query()->whereKey($entry->register_id)->firstOrFail();
                $register->forceFill(['version' => (int) $register->version + 1])->save();
            }
            $this->audit->record('attendance.justification.'.($approve ? 'approved' : 'rejected'), $actor, (int) $justification->tenant_id, $justification, ['entry_id' => $justification->entry_id]);
            $this->recordOutbox((int) $justification->tenant_id, 'justification-reviewed-'.$justification->getKey(), 'attendance.justification.reviewed', ['justification_id' => $justification->getKey(), 'decision' => $justification->status]);

            return $justification->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function studentSummary(User $actor): array
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.student.view');
        $student = DB::table('sis_students')->where('tenant_id', $this->context->tenant()->getKey())->where('user_id', $actor->getKey())->whereNull('archived_at')->first();
        if ($student === null) {
            abort(404);
        }

        return $this->summaryForStudentId((int) $student->id);
    }

    /** @return array{student_id:int,denominator:int,numerator:int,percentage:?float,by_status:array<string,int>,by_course:list<array{course:string,denominator:int,numerator:int,percentage:?float}>,by_month:list<array{month:string,denominator:int,numerator:int}>,entries:list<array<string,mixed>>} */
    private function summaryForStudentId(int $studentId): array
    {
        $entries = AttendanceEntry::query()->with(['statusDefinition', 'register'])
            ->where('student_id', $studentId)->whereHas('register', fn ($query) => $query->whereIn('status', ['submitted', 'finalized', 'locked']))
            ->orderByDesc('id')->limit(500)->get();
        $entryIds = $entries->modelKeys();
        $meetingIds = $entries->pluck('register.meeting_id')->unique()->values()->all();
        $justificationStatuses = DB::table('attendance_justifications')->whereIn('entry_id', $entryIds)->orderBy('id')->get(['entry_id', 'status'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->entry_id => (string) $row->status]);
        $courseTitles = DB::table('academic_timetable_meetings as m')->join('course_sections as s', 's.id', '=', 'm.section_id')
            ->join('course_offerings as o', 'o.id', '=', 's.offering_id')->join('academic_courses as c', 'c.id', '=', 'o.academic_course_id')
            ->whereIn('m.id', $meetingIds)->pluck('c.title', 'm.id');
        $denominator = 0;
        $numerator = 0;
        $byStatus = [];
        $byCourse = [];
        $byMonth = [];
        $rows = [];
        foreach ($entries as $entry) {
            $status = $entry->statusDefinition;
            if ($status === null) {
                continue;
            }
            $byStatus[$status->code] = ($byStatus[$status->code] ?? 0) + 1;
            if ($status->counts_expected) {
                $denominator++;
                if ($status->counts_attended) {
                    $numerator++;
                }
            }
            $course = (string) ($courseTitles[$entry->register->meeting_id] ?? 'Course');
            $month = $entry->register->occurrence_date->format('Y-m');
            $byCourse[$course] ??= ['course' => $course, 'denominator' => 0, 'numerator' => 0];
            $byMonth[$month] ??= ['month' => $month, 'denominator' => 0, 'numerator' => 0];
            if ($status->counts_expected) {
                $byCourse[$course]['denominator']++;
                $byMonth[$month]['denominator']++;
                if ($status->counts_attended) {
                    $byCourse[$course]['numerator']++;
                    $byMonth[$month]['numerator']++;
                }
            }
            $rows[] = ['id' => $entry->getKey(), 'date' => $entry->register->occurrence_date->toDateString(), 'course' => $course,
                'status' => $status->label, 'status_code' => $status->code, 'justification_status' => $justificationStatuses[$entry->getKey()] ?? null];
        }

        foreach ($byCourse as &$courseSummary) {
            $courseSummary['percentage'] = $courseSummary['denominator'] === 0 ? null : round($courseSummary['numerator'] / $courseSummary['denominator'] * 100, 2);
        }
        unset($courseSummary);
        ksort($byMonth);

        return ['student_id' => $studentId, 'denominator' => $denominator, 'numerator' => $numerator,
            'percentage' => $denominator === 0 ? null : round($numerator / $denominator * 100, 2), 'by_status' => $byStatus,
            'by_course' => array_values($byCourse), 'by_month' => array_values($byMonth), 'entries' => $rows];
    }

    /** @return array{student_id:int,denominator:int,numerator:int,percentage:?float,by_status:array<string,int>,entries:list<array<string,mixed>>} */
    public function guardianSummary(User $actor, int $studentId): array
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.guardian.view');
        $guardian = SisStudentGuardian::query()->where('student_id', $studentId)
            ->where('guardian_user_id', $actor->getKey())->where('authority_status', 'verified')
            ->whereNull('revoked_at')->where('can_view_profile', true)->firstOrFail();

        $student = $guardian->student;
        $legalName = collect([$student?->legal_first_name, $student?->legal_middle_name, $student?->legal_last_name])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');
        $summary = $this->summaryForStudentId((int) $guardian->student_id);
        $summary['student_name'] = trim((string) ($student?->preferred_name ?: $legalName)) ?: 'Linked learner';

        return $summary;
    }

    public function requestCorrection(User $actor, int $entryId, int $statusId, string $reason): AttendanceCorrectionRequest
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.correction.create');

        return DB::transaction(function () use ($actor, $entryId, $statusId, $reason): AttendanceCorrectionRequest {
            $entry = AttendanceEntry::query()->with('register')->whereKey($entryId)->lockForUpdate()->firstOrFail();
            $register = AttendanceRegister::query()->whereKey($entry->register_id)->lockForUpdate()->firstOrFail();
            $this->authorizeRegisterAction($actor, (int) $register->instructor_user_id, $register->campus_id === null ? null : (int) $register->campus_id, 'attendance.correction.create');
            if (! in_array($register->status, ['submitted', 'finalized', 'locked'], true)) {
                throw ValidationException::withMessages(['correction' => 'Corrections can be requested only after a register has been submitted.']);
            }
            $policy = AttendancePolicyVersion::query()->findOrFail($register->policy_version_id);
            $deadline = $register->occurrence_date->copy()->addDays((int) ($policy->config_snapshot['correction_deadline_days'] ?? 0))->endOfDay();
            if (now()->gt($deadline)) {
                throw ValidationException::withMessages(['correction' => 'The institution’s correction deadline has passed.']);
            }
            $status = AttendanceStatusDefinition::query()->where('policy_version_id', $register->policy_version_id)->whereKey($statusId)->firstOrFail();
            $correction = AttendanceCorrectionRequest::query()->create(['entry_id' => $entry->getKey(), 'proposed_status_id' => $status->getKey(), 'reason' => $reason, 'status' => 'pending', 'requested_by' => $actor->getKey()]);
            $this->audit->record('attendance.correction.requested', $actor, (int) $entry->tenant_id, $correction, ['entry_id' => $entry->getKey(), 'proposed_status' => $status->code]);
            $this->recordOutbox((int) $entry->tenant_id, 'correction-requested-'.$correction->getKey(), 'attendance.correction.requested', ['correction_id' => $correction->getKey()]);

            return $correction;
        });
    }

    public function reviewCorrection(User $actor, int $correctionId, bool $approve, ?string $reviewerNote = null): AttendanceCorrectionRequest
    {
        $this->assertActor($actor);
        $this->requirePermission('attendance.correction.review');

        return DB::transaction(function () use ($actor, $correctionId, $approve, $reviewerNote): AttendanceCorrectionRequest {
            $correction = AttendanceCorrectionRequest::query()->with(['entry.register', 'entry.statusDefinition'])->whereKey($correctionId)->lockForUpdate()->firstOrFail();
            if ($correction->status !== 'pending') {
                throw ValidationException::withMessages(['correction' => 'This correction was already reviewed.']);
            }
            $this->assertCampusAccess($correction->entry->register->campus_id === null ? null : (int) $correction->entry->register->campus_id);
            if ((int) $correction->requested_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['correction' => 'A correction requester cannot review their own request.']);
            }
            $register = AttendanceRegister::query()->whereKey($correction->entry->register_id)->lockForUpdate()->firstOrFail();
            if (! in_array($register->status, ['submitted', 'finalized', 'locked'], true)) {
                throw ValidationException::withMessages(['correction' => 'The register is not in a reviewable state.']);
            }
            $correction->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewer_note' => $reviewerNote, 'reviewed_at' => now()])->save();
            if ($approve) {
                $entry = AttendanceEntry::query()->whereKey($correction->entry_id)->lockForUpdate()->firstOrFail();
                $old = $entry->status_definition_id;
                $entry->forceFill(['status_definition_id' => $correction->proposed_status_id, 'marked_by' => $actor->getKey(), 'marked_at' => now(), 'version' => (int) $entry->version + 1])->save();
                AttendanceEntryRevision::query()->create(['entry_id' => $entry->getKey(), 'previous_status_id' => $old, 'new_status_id' => $correction->proposed_status_id, 'reason_code' => 'reviewed_correction', 'actor_user_id' => $actor->getKey(), 'change_source' => 'correction', 'created_at' => now()]);
                $register->forceFill(['version' => (int) $register->version + 1])->save();
            }
            $this->audit->record('attendance.correction.'.($approve ? 'approved' : 'rejected'), $actor, (int) $correction->tenant_id, $correction, ['entry_id' => $correction->entry_id]);
            $this->recordOutbox((int) $correction->tenant_id, 'correction-reviewed-'.$correction->getKey(), 'attendance.correction.reviewed', ['correction_id' => $correction->getKey(), 'decision' => $correction->status]);

            return $correction->fresh();
        }, 3);
    }

    private function effectivePolicyVersion(int $institutionId, ?int $campusId, string $date): ?AttendancePolicyVersion
    {
        return AttendancePolicyVersion::query()->with('statuses')->whereDate('effective_from', '<=', $date)
            ->whereHas('policy', function ($query) use ($institutionId, $campusId): void {
                $query->where('institution_id', $institutionId)->where('status', 'active')
                    ->where(function ($scope) use ($campusId): void {
                        $scope->whereNull('campus_id')->orWhere('campus_id', $campusId);
                    });
            })->orderByRaw('CASE WHEN (SELECT campus_id FROM attendance_policies WHERE attendance_policies.id = attendance_policy_versions.policy_id) IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')->first();
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        return [
            'minimum_attendance_percent' => isset($settings['minimum_attendance_percent']) ? (float) $settings['minimum_attendance_percent'] : null,
            'grace_period_minutes' => (int) ($settings['grace_period_minutes'] ?? 0),
            'late_after_minutes' => (int) ($settings['late_after_minutes'] ?? 10),
            'partial_after_minutes' => (int) ($settings['partial_after_minutes'] ?? 1),
            'marking_opens_before_minutes' => (int) ($settings['marking_opens_before_minutes'] ?? 1440),
            'marking_closes_after_minutes' => (int) ($settings['marking_closes_after_minutes'] ?? 10080),
            'correction_deadline_days' => (int) ($settings['correction_deadline_days'] ?? 7),
            'retroactive_marking' => (bool) ($settings['retroactive_marking'] ?? false),
            'independent_correction_review' => (bool) ($settings['independent_correction_review'] ?? true),
            'lock_on_finalize' => (bool) ($settings['lock_on_finalize'] ?? true),
        ];
    }

    /** @param array<string,array{label?:string,color?:string,requires_reason?:bool}> $custom */
    private function createStatuses(AttendancePolicyVersion $version, array $custom): void
    {
        foreach (self::STATUSES as $code => [$label, $color, $countsAttended, $countsExpected, $systemControlled]) {
            $config = $custom[$code] ?? [];
            AttendanceStatusDefinition::query()->create([
                'policy_version_id' => $version->getKey(), 'code' => $code,
                'label' => $config['label'] ?? $label, 'color' => $config['color'] ?? $color,
                'counts_attended' => $countsAttended, 'counts_expected' => $countsExpected,
                'requires_reason' => (bool) ($config['requires_reason'] ?? $systemControlled),
                'requires_evidence' => false, 'system_controlled' => $systemControlled,
            ]);
        }
    }

    private function authorizeRegisterAction(User $actor, int $assignedInstructorId, ?int $campusId, string $permission): void
    {
        $this->assertActor($actor);
        $this->assertCampusAccess($campusId);
        $membership = $this->context->membership();
        if (! $membership->hasPermission('attendance.register.override')) {
            if (! $membership->hasPermission($permission) || (int) $membership->user_id !== $assignedInstructorId) {
                abort(403);
            }
        }
    }

    private function assertActor(User $actor): void
    {
        $membership = $this->context->membership();
        abort_unless((int) $membership->user_id === (int) $actor->getKey() && (int) $membership->tenant_id === (int) $this->context->tenant()->getKey(), 403);
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->context->membership()->hasPermission($permission), 403);
    }

    private function assertCampusAccess(?int $campusId): void
    {
        $membership = $this->context->membership();
        if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $campusId && ! $membership->hasPermission('attendance.register.override')) {
            abort(404);
        }
    }

    private function assertMarkingWindow(AttendanceRegister $register, User $actor): void
    {
        if ($this->context->membership()->hasPermission('attendance.register.override')) {
            return;
        }
        $version = AttendancePolicyVersion::query()->findOrFail($register->policy_version_id);
        $meeting = AcademicTimetableMeeting::query()->with('timetable')->findOrFail($register->meeting_id);
        $start = Carbon::instance(LocalScheduleTime::toUtc($register->occurrence_date->toDateString(), substr((string) $meeting->starts_at_local, 0, 5), $meeting->timetable->timezone));
        $now = now();
        $config = $version->config_snapshot;
        if ($now->lt($start->copy()->subMinutes((int) $config['marking_opens_before_minutes'])) || $now->gt($start->copy()->addMinutes((int) $config['marking_closes_after_minutes']))) {
            throw ValidationException::withMessages(['register' => 'The attendance marking window for this occurrence is closed.']);
        }
    }

    /** @param array<string,mixed> $payload */
    private function recordOutbox(int $tenantId, string $eventKey, string $eventType, array $payload): void
    {
        DB::table('attendance_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId, 'event_key' => $eventKey, 'event_type' => $eventType,
            'safe_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0,
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
