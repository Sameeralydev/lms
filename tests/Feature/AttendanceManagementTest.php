<?php

namespace Tests\Feature;

use App\Domain\Academic\Attendance\AttendanceOutboxDispatcher;
use App\Domain\Academic\Attendance\AttendanceOutboxProcessor;
use App\Domain\Academic\Attendance\LocalAttendanceOutboxDispatcher;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Events\Attendance\AttendanceOutboxDispatched;
use App\Jobs\ScanAttendanceJustificationEvidence;
use App\Listeners\DeliverAttendanceNotification;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableMeeting;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\AttendanceUpdateNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class AttendanceManagementTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_attendance_outbox_retries_failures_and_delivers_idempotent_safe_events(): void
    {
        [$tenant] = $this->fixture();
        $eventId = DB::table('attendance_outbox_events')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'event_key' => 'attendance-test-1',
            'event_type' => 'attendance.register.submitted', 'safe_payload' => json_encode(['register_id' => 42], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(AttendanceOutboxDispatcher::class, new class implements AttendanceOutboxDispatcher
        {
            public function dispatch(int $outboxId, int $tenantId, string $eventType, array $safePayload, int $attempt): void
            {
                throw new \RuntimeException('transient test failure');
            }
        });

        $failedAttempt = app(AttendanceOutboxProcessor::class)->dispatchAvailable(1, (int) $tenant->getKey());
        $this->assertSame(['claimed' => 1, 'delivered' => 0, 'retried' => 1, 'failed' => 0], $failedAttempt);
        $this->assertDatabaseHas('attendance_outbox_events', ['id' => $eventId, 'status' => 'queued', 'attempts' => 1, 'locked_at' => null, 'last_error_code' => 'local_dispatch_failed']);

        Carbon::setTestNow(now()->addSeconds(3));
        Event::fake([AttendanceOutboxDispatched::class]);
        $this->app->bind(AttendanceOutboxDispatcher::class, LocalAttendanceOutboxDispatcher::class);
        $delivered = app(AttendanceOutboxProcessor::class)->dispatchAvailable(1, (int) $tenant->getKey());
        $this->assertSame(['claimed' => 1, 'delivered' => 1, 'retried' => 0, 'failed' => 0], $delivered);
        Event::assertDispatched(AttendanceOutboxDispatched::class, fn (AttendanceOutboxDispatched $event): bool => $event->outboxId === $eventId && $event->tenantId === $tenant->getKey() && $event->safePayload === ['register_id' => 42]);
        $this->assertDatabaseHas('attendance_outbox_events', ['id' => $eventId, 'status' => 'delivered', 'attempts' => 2, 'locked_at' => null, 'last_error_code' => null]);
    }

    public function test_published_occurrence_register_snapshots_confirmed_roster_and_requires_explicit_marking(): void
    {
        [$tenant, $owner, $reviewer, $teacher, $student, $studentTwo, $institution, $section, $meeting, $date] = $this->fixture();
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Standard Attendance',
            'minimum_attendance_percent' => 75, 'grace_period_minutes' => 5, 'late_after_minutes' => 10,
            'correction_deadline_days' => 7, 'independent_correction_review' => true, 'marking_opens_before_minutes' => 10080,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_policies', ['tenant_id' => $tenant->getKey(), 'name' => 'Standard Attendance', 'current_version' => 1]);
        $this->assertDatabaseHas('attendance_status_definitions', ['tenant_id' => $tenant->getKey(), 'code' => 'PRESENT', 'counts_attended' => 1, 'counts_expected' => 1]);
        $this->assertDatabaseHas('attendance_status_definitions', ['tenant_id' => $tenant->getKey(), 'code' => 'EXCUSED', 'counts_attended' => 0, 'counts_expected' => 0]);

        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        $registerId = (int) DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->value('id');
        $this->assertDatabaseHas('attendance_registers', ['id' => $registerId, 'expected_count' => 2, 'status' => 'draft']);
        $entryIds = DB::table('attendance_entries')->where('register_id', $registerId)->orderBy('student_id')->pluck('id')->all();
        $entryId = (int) $entryIds[0];
        $absenceEntryId = (int) $entryIds[1];
        $this->assertSame(2, count($entryIds));
        $this->assertDatabaseHas('attendance_entries', ['id' => $entryId, 'student_id' => DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->value('id'), 'status_definition_id' => null]);
        $this->actingAs($teacher)->post("{$base}/registers/{$registerId}/submit", ['version' => 1])->assertSessionHasErrors('entries');
        $this->assertDatabaseHas('attendance_entries', ['id' => $entryId, 'status_definition_id' => null]);

        $this->actingAs($teacher)->patch("{$base}/registers/{$registerId}/entries", ['version' => 1, 'entries' => [['entry_id' => $entryId, 'status_code' => 'PRESENT'], ['entry_id' => $absenceEntryId, 'status_code' => 'ABSENT']]])->assertRedirect("{$base}/registers/{$registerId}")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_entry_revisions', ['tenant_id' => $tenant->getKey(), 'entry_id' => $entryId, 'previous_status_id' => null, 'change_source' => 'instructor_mark']);
        $this->actingAs($teacher)->post("{$base}/registers/{$registerId}/submit", ['version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_registers', ['id' => $registerId, 'status' => 'submitted', 'version' => 3]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'attendance.register.submitted']);
        $this->assertDatabaseHas('attendance_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'attendance.register.submitted', 'status' => 'queued']);
        Notification::fake();
        app(DeliverAttendanceNotification::class)->handle(new AttendanceOutboxDispatched(1, (int) $tenant->getKey(), 'attendance.register.submitted', ['register_id' => $registerId], 1));
        Notification::assertSentOnDemandTimes(AttendanceUpdateNotification::class, 2);
        $this->actingAs($owner)->post("{$base}/registers/{$registerId}/finalize", ['version' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_registers', ['id' => $registerId, 'status' => 'locked', 'version' => 4]);
        $reportQuery = http_build_query(['from' => $date, 'to' => $date, 'campus_id' => DB::table('campuses')->where('institution_id', $institution->getKey())->value('id'), 'instructor_user_id' => $teacher->getKey()]);
        $this->actingAs($owner)->get("{$base}/reports?{$reportQuery}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/attendance/reports')->where('rows.total', 1)->where('rows.data.0.expected_count', 2)->where('rows.data.0.attended_count', 1)->where('rows.data.0.absent_count', 1));
        $reportExport = $this->actingAs($owner)->get("{$base}/reports.csv?{$reportQuery}");
        $reportExport->assertOk()->assertDownload('attendance-report.csv')->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($student)->get("{$base}/my")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/attendance/student')->where('summary.denominator', 1)->where('summary.numerator', 1)->where('summary.percentage', 100)->where('summary.by_course.0.course', 'Biology')->where('summary.by_month.0.denominator', 1));
        $this->actingAs($student)->get("{$base}/my.csv")->assertDownload('my-attendance.csv')->assertHeader('Cache-Control', 'no-store, private');
        $guardianUser = User::factory()->create();
        $tenantContext = app(TenantContext::class);
        $tenantContext->enterProvisioning($tenant);
        try {
            $guardianMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $guardianUser->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $guardianRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'parent.guardian')->firstOrFail();
            $guardianMembership->roles()->attach($guardianRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            $studentId = (int) DB::table('sis_students')->where('user_id', $student->getKey())->value('id');
            $guardianLink = SisStudentGuardian::query()->create(['student_id' => $studentId, 'guardian_user_id' => $guardianUser->getKey(), 'guardian_name' => 'Authorized Guardian', 'guardian_email' => $guardianUser->email, 'relationship' => 'parent', 'authority_status' => 'verified', 'can_view_profile' => true, 'verified_at' => now(), 'verified_by' => $owner->getKey()]);
        } finally {
            $tenantContext->clear();
        }
        $this->actingAs($guardianUser)->get("{$base}/guardian")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/attendance/guardian-index')
            ->has('students', 1)
            ->where('students.0.id', $studentId)
            ->where('students.0.name', 'Learner One'));
        $this->actingAs($guardianUser)->get("{$base}/guardian/{$studentId}")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/attendance/guardian')->where('summary.student_id', $studentId)->where('summary.student_name', 'Learner One')->where('summary.percentage', 100));
        $tenantContext->enterProvisioning($tenant);
        try {
            $guardianLink->forceFill(['authority_status' => 'revoked', 'revoked_at' => now(), 'can_view_profile' => false])->save();
        } finally {
            $tenantContext->clear();
        }
        $this->actingAs($guardianUser)->get("{$base}/guardian/{$studentId}")->assertNotFound();
        $this->actingAs($guardianUser)->get("{$base}/guardian")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/attendance/guardian-index')
            ->has('students', 0));
        $this->actingAs($studentTwo)->post("{$base}/entries/{$absenceEntryId}/justifications", ['category' => 'illness', 'reason' => 'The learner was ill and could not attend the scheduled class.'])->assertRedirect()->assertSessionHasNoErrors();
        $justificationId = (int) DB::table('attendance_justifications')->where('entry_id', $absenceEntryId)->value('id');
        $this->actingAs($reviewer)->post("{$base}/justifications/{$justificationId}/decision", ['decision' => 'approve', 'reviewer_note' => 'Evidence reviewed under the institution policy.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_entries', ['id' => $absenceEntryId, 'reason_code' => 'illness']);
        $this->assertDatabaseHas('attendance_entry_revisions', ['entry_id' => $absenceEntryId, 'change_source' => 'justification']);
        $this->actingAs($studentTwo)->get("{$base}/my")->assertOk()->assertInertia(fn ($page) => $page->where('summary.denominator', 0)->where('summary.percentage', null));
        $this->assertDatabaseHas('course_enrollments', ['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed']);
        $lateStatusId = (int) DB::table('attendance_status_definitions')->where('tenant_id', $tenant->getKey())->where('policy_version_id', DB::table('attendance_registers')->where('id', $registerId)->value('policy_version_id'))->where('code', 'LATE')->value('id');
        $this->actingAs($teacher)->post("{$base}/entries/{$entryId}/corrections", ['status_code' => 'LATE', 'reason' => 'The instructor confirmed the learner arrived after the scheduled start.'])->assertRedirect()->assertSessionHasNoErrors();
        $correctionId = (int) DB::table('attendance_correction_requests')->where('entry_id', $entryId)->value('id');
        $this->assertDatabaseHas('attendance_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'attendance.correction.requested']);
        $this->actingAs($reviewer)->post("{$base}/corrections/{$correctionId}/decision", ['decision' => 'approve', 'reviewer_note' => 'Instructor records reviewed independently.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_entries', ['id' => $entryId, 'status_definition_id' => $lateStatusId]);
        $this->assertDatabaseHas('attendance_entry_revisions', ['entry_id' => $entryId, 'change_source' => 'correction', 'reason_code' => 'reviewed_correction']);
    }

    public function test_attendance_register_rejects_wrong_tenant_campus_and_nonassigned_instructor(): void
    {
        [$tenant, $owner, , $teacher, , , $institution, , $meeting, $date] = $this->fixture('Attendance Security Academy');
        [$foreignTenant, $foreignOwner] = $this->fixture('Foreign Attendance Academy');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => 999999, 'occurrence_date' => $date])->assertNotFound();
        $this->actingAs($foreignOwner)->post("/t/{$foreignTenant->slug}/attendance/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertNotFound();
        $this->assertSame(0, DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->count());
        $this->actingAs($owner)->post("{$base}/policies", ['institution_id' => $institution->getKey(), 'name' => 'Policy'])->assertRedirect()->assertSessionHasNoErrors();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $otherCampus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'OTHER', 'name' => 'Other Campus', 'status' => 'active']);
            $unassigned = User::factory()->create();
            $scopedMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $unassigned->getKey(), 'campus_id' => $otherCampus->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $teacherRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
            $scopedMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            $unassignedCampusUser = User::factory()->create();
            $unassignedCampusMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $unassignedCampusUser->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $unassignedCampusMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }
        $this->actingAs($unassigned)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertNotFound();
        $this->actingAs($unassignedCampusUser)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertForbidden();
        $this->assertSame(0, DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_register_roster_uses_withdrawal_effective_at_occurrence_date(): void
    {
        [$tenant, $owner, , $teacher, $student, $studentTwo, $institution, $section, $meeting, $date] = $this->fixture('Effective Attendance Roster');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Effective Roster Policy',
            'minimum_attendance_percent' => 75, 'correction_deadline_days' => 7,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $before = CourseEnrollment::query()->where('section_id', $section->getKey())->whereHas('student', fn ($query) => $query->where('user_id', $student->getKey()))->firstOrFail();
        $after = CourseEnrollment::query()->where('section_id', $section->getKey())->whereHas('student', fn ($query) => $query->where('user_id', $studentTwo->getKey()))->firstOrFail();
        $before->forceFill(['status' => 'withdrawn', 'withdrawn_effective_at' => Carbon::parse($date)->subDay()])->save();
        $after->forceFill(['status' => 'withdrawn', 'withdrawn_effective_at' => Carbon::parse($date)->addDay()])->save();

        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        $register = DB::table('attendance_registers')->where('meeting_id', $meeting->getKey())->whereDate('occurrence_date', $date)->first();
        $this->assertNotNull($register);
        $this->assertSame(1, (int) $register->expected_count);
        $this->assertSame([(int) $after->student_id], DB::table('attendance_entries')->where('register_id', $register->id)->pluck('student_id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_approved_section_transfer_changes_future_attendance_roster(): void
    {
        [$tenant, $owner, , $teacher, $student, , $institution, $section, $meeting, $date] = $this->fixture('Transferred Attendance Roster');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Transfer Attendance Policy',
            'minimum_attendance_percent' => 75, 'marking_opens_before_minutes' => 10080,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $destination = CourseSection::query()->create([
                'offering_id' => $section->offering_id, 'campus_id' => $section->campus_id,
                'section_code' => 'B', 'capacity' => 30, 'status' => 'open',
            ]);
        } finally {
            $context->clear();
        }
        $context->enterProvisioning($tenant);
        try {
            $studentMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $student->getKey())->firstOrFail();
            $ownerMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
            $enrollment = CourseEnrollment::query()->where('section_id', $section->getKey())->whereHas('student', fn ($query) => $query->where('user_id', $student->getKey()))->firstOrFail();
        } finally {
            $context->clear();
        }
        $context->set($tenant, $studentMembership);
        try {
            $transferId = app(CourseEnrollmentWorkflow::class)->requestSectionTransfer((int) $enrollment->getKey(), (int) $destination->getKey(), 'Learner requested a section change.', $student);
        } finally {
            $context->clear();
        }
        $context->set($tenant, $ownerMembership);
        try {
            app(CourseEnrollmentWorkflow::class)->decideSectionTransfer($transferId, 'approve', 'Transfer approved by the registrar.', $owner);
        } finally {
            $context->clear();
        }
        $this->assertDatabaseHas('course_enrollments', ['id' => $enrollment->getKey(), 'section_id' => $destination->getKey(), 'status' => 'confirmed']);

        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        $register = DB::table('attendance_registers')->where('meeting_id', $meeting->getKey())->whereDate('occurrence_date', $date)->first();
        $this->assertNotNull($register);
        $this->assertSame(1, (int) $register->expected_count);
        $this->assertSame(1, DB::table('attendance_entries')->where('register_id', $register->id)->count());
        $this->assertDatabaseMissing('attendance_entries', ['register_id' => $register->id, 'student_id' => DB::table('sis_students')->where('user_id', $student->getKey())->value('id')]);
    }

    public function test_cancelled_occurrence_is_denied_and_makeup_uses_authorized_substitute(): void
    {
        [$tenant, $owner, , $teacher, , , $institution, , $meeting, $date] = $this->fixture('Substitute Attendance');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Substitute Attendance Policy',
            'minimum_attendance_percent' => 75, 'correction_deadline_days' => 7,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $substitute = User::factory()->create();
        $tenantContext = app(TenantContext::class);
        $tenantContext->enterProvisioning($tenant);
        try {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $substitute->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $teacherRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
            $membership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $tenantContext->clear();
        }

        $replacementDate = Carbon::parse($date)->addDays(2)->toDateString();
        $change = [
            'tenant_id' => $tenant->getKey(), 'timetable_id' => $meeting->timetable_id, 'meeting_id' => $meeting->getKey(),
            'occurrence_date' => $date, 'replacement_date' => $replacementDate, 'starts_at_local' => '10:00',
            'duration_minutes' => 50, 'room_id' => null, 'reason' => 'Approved schedule exception', 'status' => 'approved',
            'requested_by' => $owner->getKey(), 'reviewed_by' => $owner->getKey(), 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('academic_timetable_changes')->insert([
            ...$change, 'change_type' => 'cancellation', 'instructor_user_id' => null, 'replacement_date' => null,
        ]);
        DB::table('academic_timetable_changes')->insert([
            ...$change, 'change_type' => 'makeup', 'instructor_user_id' => $substitute->getKey(),
        ]);

        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertSessionHasErrors('occurrence_date');
        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $replacementDate])->assertForbidden();
        $this->actingAs($substitute)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $replacementDate])->assertRedirect()->assertSessionHasNoErrors();
        $register = DB::table('attendance_registers')->where('tenant_id', $tenant->getKey())->where('meeting_id', $meeting->getKey())->whereDate('occurrence_date', $replacementDate)->first();
        $this->assertNotNull($register);
        $this->assertSame((int) $substitute->getKey(), (int) $register->instructor_user_id);
        $this->assertSame('draft', $register->status);
    }

    public function test_justification_evidence_stays_quarantined_until_clean_scan_and_authorized_download(): void
    {
        [$tenant, $owner, $reviewer, $teacher, $student, , $institution, , $meeting, $date] = $this->fixture('Attendance Evidence');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Evidence Attendance Policy',
            'minimum_attendance_percent' => 75, 'marking_opens_before_minutes' => 10080, 'correction_deadline_days' => 7,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        $registerId = (int) DB::table('attendance_registers')->where('meeting_id', $meeting->getKey())->value('id');
        $entryIds = DB::table('attendance_entries')->where('register_id', $registerId)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $entryId = $entryIds[0];
        $entries = array_map(static fn (int $id): array => ['entry_id' => $id, 'status_code' => 'ABSENT'], $entryIds);
        $this->actingAs($teacher)->patch("{$base}/registers/{$registerId}/entries", ['version' => 1, 'entries' => $entries])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post("{$base}/registers/{$registerId}/submit", ['version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/registers/{$registerId}/finalize", ['version' => 3])->assertRedirect()->assertSessionHasNoErrors();

        Storage::fake('local');
        Queue::fake();
        $this->actingAs($student)->post("{$base}/entries/{$entryId}/justifications", [
            'category' => 'illness', 'reason' => 'Supporting medical evidence is attached for review.',
            'evidence' => UploadedFile::fake()->create('support.png', 12, 'image/png'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $justificationId = (int) DB::table('attendance_justifications')->where('entry_id', $entryId)->value('id');
        $evidence = DB::table('attendance_justification_evidence')->where('justification_id', $justificationId)->first();
        $this->assertNotNull($evidence);
        $this->assertSame('quarantined', $evidence->scan_status);
        $this->assertStringStartsWith('attendance/'.$tenant->getKey().'/quarantine/', $evidence->private_path);
        Queue::assertPushed(ScanAttendanceJustificationEvidence::class);
        $this->actingAs($reviewer)->post("{$base}/justifications/{$justificationId}/decision", ['decision' => 'approve'])->assertSessionHasErrors('justification');
        $this->actingAs($reviewer)->get("{$base}/justifications/evidence/{$evidence->id}")->assertNotFound();

        $scanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-only-clean-adapter';
            }
        };
        (new ScanAttendanceJustificationEvidence((int) $tenant->getKey(), (int) $evidence->id))->handle($scanner, app(TenantContext::class));
        $this->assertDatabaseHas('attendance_justification_evidence', ['id' => $evidence->id, 'scan_status' => 'clean', 'result_code' => null]);
        $this->actingAs($student)->get("{$base}/justifications/evidence/{$evidence->id}")->assertForbidden();
        $this->actingAs($reviewer)->get("{$base}/justifications/evidence/{$evidence->id}")->assertDownload('attendance-evidence-'.$evidence->id.'.png')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'attendance.justification.evidence.downloaded']);
        $this->actingAs($reviewer)->post("{$base}/justifications/{$justificationId}/decision", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_real_clamav_scans_attendance_evidence_and_keeps_eicar_quarantined(): void
    {
        if (config('admissions.document_scanner') !== 'clamav') {
            $this->markTestSkipped('Real ClamAV attendance evidence verification requires the private scanner-enabled test environment.');
        }

        [$tenant, $owner, $reviewer, $teacher, $student, $studentTwo, $institution, , $meeting, $date] = $this->fixture('Real Scanner Attendance');
        $base = "/t/{$tenant->slug}/attendance";
        $this->actingAs($owner)->post("{$base}/policies", [
            'institution_id' => $institution->getKey(), 'name' => 'Real Scanner Policy',
            'minimum_attendance_percent' => 75, 'marking_opens_before_minutes' => 10080, 'correction_deadline_days' => 7,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post("{$base}/registers", ['meeting_id' => $meeting->getKey(), 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        $registerId = (int) DB::table('attendance_registers')->where('meeting_id', $meeting->getKey())->value('id');
        $entryIds = DB::table('attendance_entries')->where('register_id', $registerId)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->actingAs($teacher)->patch("{$base}/registers/{$registerId}/entries", [
            'version' => 1,
            'entries' => array_map(static fn (int $id): array => ['entry_id' => $id, 'status_code' => 'ABSENT'], $entryIds),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post("{$base}/registers/{$registerId}/submit", ['version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/registers/{$registerId}/finalize", ['version' => 3])->assertRedirect()->assertSessionHasNoErrors();

        Storage::fake('local');
        Queue::fake();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jcycAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        $cleanUpload = UploadedFile::fake()->createWithContent('clean.png', $png);
        $this->actingAs($student)->post("{$base}/entries/{$entryIds[0]}/justifications", [
            'category' => 'illness', 'reason' => 'A real clean evidence scan is required for this review.', 'evidence' => $cleanUpload,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cleanJustificationId = (int) DB::table('attendance_justifications')->where('entry_id', $entryIds[0])->value('id');
        $cleanEvidence = DB::table('attendance_justification_evidence')->where('justification_id', $cleanJustificationId)->first();
        $this->assertNotNull($cleanEvidence);
        $this->assertSame('quarantined', $cleanEvidence->scan_status);
        $this->actingAs($reviewer)->get("{$base}/justifications/evidence/{$cleanEvidence->id}")->assertNotFound();
        (new ScanAttendanceJustificationEvidence((int) $tenant->getKey(), (int) $cleanEvidence->id))->handle(app(AdmissionDocumentScanner::class), app(TenantContext::class));
        $this->assertDatabaseHas('attendance_justification_evidence', ['id' => $cleanEvidence->id, 'scan_status' => 'clean', 'result_code' => null]);
        $this->actingAs($reviewer)->get("{$base}/justifications/evidence/{$cleanEvidence->id}")->assertDownload('attendance-evidence-'.$cleanEvidence->id.'.png');

        $this->actingAs($studentTwo)->post("{$base}/entries/{$entryIds[1]}/justifications", [
            'category' => 'illness', 'reason' => 'A harmless EICAR signature verifies infected evidence rejection.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $eicarJustificationId = (int) DB::table('attendance_justifications')->where('entry_id', $entryIds[1])->value('id');
        $eicarPath = 'attendance/'.$tenant->getKey().'/quarantine/'.Str::uuid().'.png';
        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        Storage::disk('local')->put($eicarPath, $eicar);
        $eicarId = DB::table('attendance_justification_evidence')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'justification_id' => $eicarJustificationId, 'private_path' => $eicarPath,
            'sha256' => hash('sha256', $eicar), 'mime_type' => 'application/octet-stream', 'size_bytes' => strlen($eicar),
            'scan_status' => 'quarantined', 'uploaded_by' => $studentTwo->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('quarantined', DB::table('attendance_justification_evidence')->where('id', $eicarId)->value('scan_status'));
        (new ScanAttendanceJustificationEvidence((int) $tenant->getKey(), $eicarId))->handle(app(AdmissionDocumentScanner::class), app(TenantContext::class));
        $this->assertDatabaseHas('attendance_justification_evidence', ['id' => $eicarId, 'scan_status' => 'rejected', 'result_code' => 'malware_detected']);
        $this->actingAs($reviewer)->get("{$base}/justifications/evidence/{$eicarId}")->assertNotFound();
        $this->actingAs($reviewer)->post("{$base}/justifications/{$eicarJustificationId}/decision", ['decision' => 'approve'])->assertSessionHasErrors('justification');
    }

    /** @return array{Tenant,User,User,User,User,User,Institution,CourseSection,AcademicTimetableMeeting,string} */
    private function fixture(string $name = 'Attendance Academy'): array
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $teacher = User::factory()->create();
        $studentUser = User::factory()->create();
        $studentTwoUser = User::factory()->create();
        $creator = User::factory()->create();
        $tenant = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'official_name' => $name, 'display_name' => $name,
            'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '',
            'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Main Campus', 'status' => 'active', 'is_primary' => true]);
            foreach ([[$owner, 'tenant.owner'], [$reviewer, 'academic.admin'], [$teacher, 'teacher'], [$studentUser, 'student'], [$studentTwoUser, 'student']] as [$user, $roleKey]) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            }
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Academic Year', 'code' => 'AY-'.Str::upper(Str::random(6)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(6), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Term', 'code' => 'T-'.Str::upper(Str::random(6)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'BIO-'.Str::upper(Str::random(6)), 'title' => 'Biology', 'status' => 'active']);
            $enrollmentPolicy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Registration Policy']);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $enrollmentPolicy->getKey(), 'offering_code' => 'O-'.Str::upper(Str::random(6)), 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 30, 'status' => 'open']);
            DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            foreach ([[$studentUser, 'One'], [$studentTwoUser, 'Two']] as [$user, $suffix]) {
                $student = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $user->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(8)), 'registration_reference' => 'REF-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Learner', 'legal_last_name' => $suffix, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
                CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now(), 'confirmed_at' => now()]);
            }

            $date = now()->addDay()->toDateString();
            $timetable = AcademicTimetable::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'campus_id' => $campus->getKey(), 'name' => 'Published schedule', 'version' => 1, 'status' => 'published', 'timezone' => 'UTC', 'independent_review_required' => true, 'created_by' => $owner->getKey(), 'published_by' => $owner->getKey(), 'published_at' => now()]);
            $meeting = AcademicTimetableMeeting::query()->create(['timetable_id' => $timetable->getKey(), 'section_id' => $section->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => null, 'starts_on' => $date, 'ends_on' => $date, 'weekdays' => [(int) Carbon::parse($date)->dayOfWeekIso], 'starts_at_local' => '09:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person', 'status' => 'scheduled', 'created_by' => $owner->getKey()]);
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $reviewer, $teacher, $studentUser, $studentTwoUser, $institution, $section, $meeting, $date];
    }
}
