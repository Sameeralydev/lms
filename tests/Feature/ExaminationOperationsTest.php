<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\ExamAnswerScript;
use App\Models\ExamCandidate;
use App\Models\ExamCandidateAccommodation;
use App\Models\ExamComponent;
use App\Models\ExamComponentAllocation;
use App\Models\ExamCycle;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class ExaminationOperationsTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_campus_scoped_administrator_can_transition_same_campus_cycle_but_not_foreign_campus_cycle(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        $cycleCode = 'CAMPUS-SCOPE-'.Str::upper(Str::random(8));
        $this->actingAs($fixture['owner'])->post($root.'/cycles', [
            'academic_period_id' => $fixture['period']->getKey(),
            'campus_id' => $fixture['campus']->getKey(),
            'code' => $cycleCode,
            'title' => 'Campus-scoped authorization cycle',
            'category' => 'final',
            'registration_opens_on' => today()->toDateString(),
            'registration_closes_on' => today()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cycle = ExamCycle::query()->where('code', $cycleCode)->firstOrFail();

        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $foreignCampus = Campus::query()->create([
                'institution_id' => $fixture['institution']->getKey(),
                'code' => 'REMOTE-'.Str::upper(Str::random(5)),
                'name' => 'Foreign examination campus',
                'status' => 'active',
                'is_primary' => false,
            ]);
            $campusAdministrator = User::factory()->create([
                'email' => 'phase13-campus-admin-'.Str::uuid().'@example.test',
            ]);
            $membership = TenantMembership::query()->create([
                'institution_id' => $fixture['institution']->getKey(),
                'user_id' => $campusAdministrator->getKey(),
                'campus_id' => $foreignCampus->getKey(),
                'status' => 'active',
                'activated_at' => now(),
            ]);
            $ownerRole = Role::query()->where('tenant_id', $fixture['tenant']->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $membership->roles()->attach($ownerRole->getKey(), [
                'assigned_by' => $fixture['owner']->getKey(),
                'tenant_id' => $fixture['tenant']->getKey(),
            ]);
        } finally {
            $context->clear();
        }

        $auditCount = DB::table('audit_events')->where('tenant_id', $fixture['tenant']->getKey())->count();
        $this->actingAs($campusAdministrator)->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => 'review'])
            ->assertNotFound();
        self::assertSame('draft', $cycle->fresh()->status);
        self::assertSame($auditCount, DB::table('audit_events')->where('tenant_id', $fixture['tenant']->getKey())->count());

        $sameCampusAdministrator = User::factory()->create([
            'email' => 'phase13-same-campus-admin-'.Str::uuid().'@example.test',
        ]);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $sameCampusMembership = TenantMembership::query()->create([
                'institution_id' => $fixture['institution']->getKey(),
                'user_id' => $sameCampusAdministrator->getKey(),
                'campus_id' => $fixture['campus']->getKey(),
                'status' => 'active',
                'activated_at' => now(),
            ]);
            $sameCampusMembership->roles()->attach($ownerRole->getKey(), [
                'assigned_by' => $fixture['owner']->getKey(),
                'tenant_id' => $fixture['tenant']->getKey(),
            ]);
        } finally {
            $context->clear();
        }

        $scopedCycleCode = 'SAME-CAMPUS-'.Str::upper(Str::random(8));
        $scopedCycleData = [
            'academic_period_id' => $fixture['period']->getKey(),
            'campus_id' => $fixture['campus']->getKey(),
            'code' => $scopedCycleCode,
            'title' => 'Scoped administrator cycle',
            'category' => 'final',
            'registration_opens_on' => today()->toDateString(),
            'registration_closes_on' => today()->addDay()->toDateString(),
        ];
        $this->actingAs($sameCampusAdministrator)->post($root.'/cycles', $scopedCycleData)
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertDatabaseHas('exam_cycles', [
            'tenant_id' => $fixture['tenant']->getKey(),
            'campus_id' => $fixture['campus']->getKey(),
            'code' => $scopedCycleCode,
            'status' => 'draft',
        ]);

        $foreignCampusCode = 'WRONG-CAMPUS-'.Str::upper(Str::random(8));
        $scopedCycleData['campus_id'] = $foreignCampus->getKey();
        $scopedCycleData['code'] = $foreignCampusCode;
        $auditBeforeForeignCampusCreate = DB::table('audit_events')->where('tenant_id', $fixture['tenant']->getKey())->count();
        $this->actingAs($sameCampusAdministrator)->post($root.'/cycles', $scopedCycleData)->assertNotFound();
        self::assertDatabaseMissing('exam_cycles', [
            'tenant_id' => $fixture['tenant']->getKey(),
            'code' => $foreignCampusCode,
        ]);
        self::assertSame($auditBeforeForeignCampusCreate, DB::table('audit_events')->where('tenant_id', $fixture['tenant']->getKey())->count());

        $this->actingAs($sameCampusAdministrator)->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => 'review'])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('review', $cycle->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $fixture['tenant']->getKey(),
            'event_type' => 'exams.cycle.transitioned',
            'subject_id' => $cycle->getKey(),
        ]);
    }

    public function test_institution_owner_can_create_review_and_publish_a_cycle_and_register_eligible_students_idempotently(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        $this->actingAs($fixture['owner'])->get($root)->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace/academics/examinations/index')->has('cycles.data')->has('periods'));
        $cycleData = [
            'academic_period_id' => $fixture['period']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
            'code' => 'FINAL-'.Str::upper(Str::random(5)), 'title' => 'Semester Final Examination', 'category' => 'final',
            'registration_opens_on' => today()->subDay()->toDateString(), 'registration_closes_on' => today()->addDay()->toDateString(),
        ];

        $this->actingAs($fixture['owner'])->post($root.'/cycles', $cycleData)->assertRedirect();
        $cycle = ExamCycle::query()->where('code', $cycleData['code'])->firstOrFail();
        self::assertSame('draft', $cycle->status);
        self::assertSame($fixture['tenant']->getKey(), $cycle->tenant_id);

        $startsAt = now()->addDays(10)->startOfHour();
        $sessionData = [
            'campus_id' => $fixture['campus']->getKey(), 'academic_course_id' => $fixture['course']->getKey(), 'room_id' => $fixture['room']->getKey(),
            'code' => 'SCI-'.Str::upper(Str::random(5)), 'title' => 'Science Written Paper', 'mode' => 'onsite',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'), 'ends_at' => $startsAt->copy()->addHour()->format('Y-m-d\TH:i'),
            'reporting_at' => $startsAt->copy()->subMinutes(30)->format('Y-m-d\TH:i'), 'duration_minutes' => 60, 'capacity' => 1,
        ];
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycle->getKey().'/sessions', $sessionData)->assertRedirect();
        $session = DB::table('exam_sessions')->where('tenant_id', $fixture['tenant']->getKey())->where('cycle_id', $cycle->getKey())->first();
        self::assertNotNull($session);

        foreach (['review', 'approved', 'published'] as $status) {
            $this->actingAs($fixture['owner'])->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => $status])->assertRedirect();
        }
        $this->assertDatabaseHas('exam_cycles', ['id' => $cycle->getKey(), 'status' => 'published']);
        $this->assertDatabaseHas('exam_sessions', ['id' => $session->id, 'status' => 'published']);

        $payload = ['student_id' => $fixture['student']->getKey()];
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycle->getKey().'/candidates', $payload)->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycle->getKey().'/candidates', $payload)->assertRedirect();
        $candidate = ExamCandidate::query()->where('cycle_id', $cycle->getKey())->where('student_id', $fixture['student']->getKey())->firstOrFail();
        $seatPayload = ['candidate_id' => $candidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-01'];
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seats', $seatPayload)->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seats', $seatPayload)->assertRedirect();
        $otherStudent = SisStudent::query()->where('tenant_id', $fixture['tenant']->getKey())->whereKey($fixture['otherStudent']->getKey())->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycle->getKey().'/candidates', ['student_id' => $otherStudent->getKey()])->assertRedirect();
        $otherCandidate = ExamCandidate::query()->where('cycle_id', $cycle->getKey())->where('student_id', $otherStudent->getKey())->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seats', ['candidate_id' => $otherCandidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-02'])->assertSessionHasErrors('candidate_id');
        self::assertSame(1, ExamCandidate::query()->where('cycle_id', $cycle->getKey())->where('student_id', $fixture['student']->getKey())->count());
        self::assertSame(1, DB::table('exam_seat_allocations')->where('session_id', $session->id)->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.cycle.created']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.cycle.transitioned']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.candidate.registered']);
    }

    public function test_cycle_transition_rejects_skipped_states_and_cross_tenant_cycle_ids(): void
    {
        $fixture = $this->fixture();
        $other = $this->fixture();
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            $cycle = ExamCycle::query()->create([
                'institution_id' => $fixture['institution']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
                'academic_period_id' => $fixture['period']->getKey(), 'created_by' => $fixture['owner']->getKey(),
                'code' => 'CYCLE-'.Str::upper(Str::random(5)), 'title' => 'Draft cycle', 'category' => 'midterm', 'status' => 'draft',
            ]);
        } finally {
            app(TenantContext::class)->clear();
        }
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        $this->actingAs($fixture['owner'])->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => 'published'])->assertSessionHasErrors('to');
        app(TenantContext::class)->enterProvisioning($other['tenant']);
        try {
            $foreignCycle = ExamCycle::query()->create([
                'institution_id' => $other['institution']->getKey(), 'campus_id' => $other['campus']->getKey(),
                'academic_period_id' => $other['period']->getKey(), 'created_by' => $other['owner']->getKey(),
                'code' => 'FOREIGN-'.Str::upper(Str::random(5)), 'title' => 'Other tenant cycle', 'category' => 'midterm', 'status' => 'draft',
            ]);
        } finally {
            app(TenantContext::class)->clear();
        }
        $otherRoot = '/t/'.$other['tenant']->slug.'/academics/examinations';
        $this->actingAs($other['owner'])->patch($otherRoot.'/cycles/'.$cycle->getKey().'/status', ['to' => 'review'])->assertNotFound();
        self::assertSame('draft', ExamCycle::query()->withoutGlobalScopes()->findOrFail($foreignCycle->getKey())->status);
    }

    public function test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session, $questionVersionId] = $this->paperQuestionFixture($fixture, $root);

        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/papers', [
            'set_code' => 'A', 'title' => 'Confidential Biology Examination', 'instructions' => 'Answer every question.',
            'duration_minutes' => 45, 'questions' => [['question_version_id' => $questionVersionId, 'points' => '5.00']],
        ])->assertRedirect();

        $version = ExamPaperVersion::query()->firstOrFail();
        self::assertSame('draft', $version->status);
        self::assertSame('5.00', $version->total_marks);
        self::assertStringNotContainsString('secret-answer-key', (string) DB::table('exam_paper_versions')->where('id', $version->getKey())->value('question_snapshot'));
        self::assertStringNotContainsString('secret-answer-key', (string) $version->getRawOriginal('question_snapshot'));
        self::assertStringNotContainsString('Answer every question.', (string) $version->getRawOriginal('instructions'));
        $snapshotBefore = $version->getRawOriginal('question_snapshot');

        $this->actingAs($fixture['student']->user)->get($root.'/paper-versions/'.$version->getKey().'/confidential-preview')->assertNotFound();
        $author = $fixture['owner'];
        $reviewer = User::factory()->create();
        $publisher = User::factory()->create();
        $this->grantExamPermission($fixture, $reviewer, 'exams.paper.review');
        $this->grantExamPermission($fixture, $publisher, ['exams.paper.publish', 'exams.paper.confidential.view']);

        $this->actingAs($author)->post($root.'/paper-versions/'.$version->getKey().'/submit-review')->assertRedirect();
        $this->actingAs($author)->post($root.'/paper-versions/'.$version->getKey().'/review', ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($reviewer)->post($root.'/paper-versions/'.$version->getKey().'/review', ['decision' => 'approved', 'notes' => 'Content verified.'])->assertRedirect();
        $reviewRow = DB::table('exam_paper_reviews')->where('paper_version_id', $version->getKey())->firstOrFail();
        self::assertStringNotContainsString('Content verified.', (string) $reviewRow->notes);
        $this->actingAs($reviewer)->post($root.'/paper-versions/'.$version->getKey().'/publish')->assertForbidden();
        $this->actingAs($publisher)->post($root.'/paper-versions/'.$version->getKey().'/publish')->assertRedirect();

        $preview = $this->actingAs($publisher)->getJson($root.'/paper-versions/'.$version->getKey().'/confidential-preview')->assertOk();
        self::assertStringContainsString('private', (string) $preview->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $preview->assertJsonPath('paper.status', 'published')->assertJsonPath('paper.total_marks', '5.00');
        self::assertStringNotContainsString('secret-answer-key', $preview->getContent());
        self::assertSame($snapshotBefore, $version->fresh()->getRawOriginal('question_snapshot'));
        $this->assertDatabaseHas('exam_papers', ['id' => $version->paper_id, 'current_version' => 1, 'current_set_code' => 'A', 'status' => 'published']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.paper.version.published']);

        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/papers', [
            'set_code' => 'A', 'title' => 'Revised Biology Examination', 'instructions' => 'Revised instructions.',
            'duration_minutes' => 45, 'questions' => [['question_version_id' => $questionVersionId, 'points' => '6.00']],
        ])->assertRedirect();
        self::assertSame(2, ExamPaperVersion::query()->count());
        self::assertSame('Confidential Biology Examination', $version->fresh()->title);
        self::assertSame($snapshotBefore, $version->fresh()->getRawOriginal('question_snapshot'));

        DB::table('exam_paper_versions')->where('id', $version->getKey())->update(['content_hash' => str_repeat('0', 64)]);
        $this->actingAs($publisher)->getJson($root.'/paper-versions/'.$version->getKey().'/confidential-preview')->assertStatus(409);
    }

    public function test_exam_paper_rejects_questions_from_another_institution(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        [, $foreignQuestionVersionId] = $this->paperQuestionFixture($foreign, '/t/'.$foreign['tenant']->slug.'/academics/examinations');

        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/papers', [
            'set_code' => 'A', 'title' => 'Invalid foreign paper', 'duration_minutes' => 30,
            'questions' => [['question_version_id' => $foreignQuestionVersionId, 'points' => '2.00']],
        ])->assertSessionHasErrors('questions.0.question_version_id');
        self::assertSame(0, ExamPaperVersion::query()->count());
    }

    public function test_invigilator_assignments_attendance_revisions_and_confidential_accommodations_are_scoped_and_audited(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $cycleId = (int) DB::table('exam_sessions')->where('id', $session->id)->value('cycle_id');
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect();
        $candidate = ExamCandidate::query()->where('cycle_id', $cycleId)->where('student_id', $fixture['student']->getKey())->firstOrFail();

        $invigilator = User::factory()->create();
        $this->grantExamPermission($fixture, $invigilator, 'exams.attendance.record');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/invigilators', [
            'user_id' => $invigilator->getKey(), 'duty_role' => 'chief_invigilator',
        ])->assertRedirect();
        $assignment = DB::table('exam_invigilator_assignments')->where('session_id', $session->id)->where('user_id', $invigilator->getKey())->firstOrFail();
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.invigilator.assigned:'.$assignment->id, 'event_type' => 'exam.invigilator_assigned', 'status' => 'queued']);
        $this->actingAs($fixture['owner'])->post($root.'/invigilator-assignments/'.$assignment->id.'/response', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($invigilator)->post($root.'/invigilator-assignments/'.$assignment->id.'/response', ['decision' => 'accept'])->assertRedirect();
        $acceptedAt = DB::table('exam_invigilator_assignments')->where('id', $assignment->id)->value('accepted_at');
        self::assertNotNull($acceptedAt);
        $this->actingAs($invigilator)->post($root.'/invigilator-assignments/'.$assignment->id.'/response', ['decision' => 'accept'])->assertRedirect();
        self::assertSame($acceptedAt, DB::table('exam_invigilator_assignments')->where('id', $assignment->id)->value('accepted_at'));
        $this->actingAs($invigilator)->post($root.'/sessions/'.$session->id.'/attendance', [
            'candidate_id' => $candidate->getKey(), 'attendance_status' => 'present',
        ])->assertRedirect();
        $this->actingAs($invigilator)->post($root.'/sessions/'.$session->id.'/attendance', [
            'candidate_id' => $candidate->getKey(), 'attendance_status' => 'late', 'safe_note' => 'Corrected after verification.',
        ])->assertRedirect();

        $attendance = DB::table('exam_attendance_records')->where('session_id', $session->id)->where('candidate_id', $candidate->getKey())->first();
        self::assertSame('late', $attendance->attendance_status);
        self::assertSame(2, (int) $attendance->revision);
        self::assertSame(2, DB::table('exam_attendance_revisions')->where('attendance_record_id', $attendance->id)->count());

        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycleId.'/candidates/'.$candidate->getKey().'/accommodations', [
            'accommodation_type' => 'reader', 'extra_time_minutes' => 20, 'confidential_details' => 'Confidential medical accommodation details.',
        ])->assertRedirect();
        $accommodation = ExamCandidateAccommodation::query()->firstOrFail();
        self::assertSame('Confidential medical accommodation details.', $accommodation->confidential_details);
        self::assertStringNotContainsString('Confidential medical accommodation details.', (string) $accommodation->getRawOriginal('confidential_details'));
        $auditPayload = DB::table('audit_events')->where('event_type', 'exams.candidate.accommodation.authorized')->value('metadata');
        self::assertStringNotContainsString('Confidential medical accommodation details.', (string) $auditPayload);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.candidate.attendance.recorded']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.invigilator.duty_accept']);
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(2, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $invigilator->getKey(), 'event_type' => 'exam.invigilator_assigned']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['owner']->getKey(), 'event_type' => 'exam.invigilator_assigned']);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.invigilator_duty_accept:assignment:'.$assignment->id, 'event_type' => 'exam.invigilator_duty_accept', 'status' => 'delivered']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['owner']->getKey(), 'event_type' => 'exam.invigilator_duty_accept']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $invigilator->getKey(), 'event_type' => 'exam.invigilator_duty_accept']);
        $decliningInvigilator = User::factory()->create();
        $this->grantExamPermission($fixture, $decliningInvigilator, 'exams.attendance.record');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/invigilators', [
            'user_id' => $decliningInvigilator->getKey(), 'duty_role' => 'invigilator',
        ])->assertRedirect();
        $declinedAssignment = DB::table('exam_invigilator_assignments')->where('session_id', $session->id)->where('user_id', $decliningInvigilator->getKey())->firstOrFail();
        $this->actingAs($decliningInvigilator)->post($root.'/invigilator-assignments/'.$declinedAssignment->id.'/response', ['decision' => 'decline'])->assertRedirect();
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(2, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['owner']->getKey(), 'event_type' => 'exam.invigilator_duty_decline']);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.invigilator_duty_decline:assignment:'.$declinedAssignment->id, 'event_type' => 'exam.invigilator_duty_decline', 'status' => 'delivered']);
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycleId.'/candidates/'.$candidate->getKey().'/accommodations', [
            'accommodation_type' => 'reader', 'extra_time_minutes' => 25, 'confidential_details' => 'Updated authorization details.',
        ])->assertRedirect();
        self::assertSame(2, ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->count());
        self::assertSame(1, ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('status', 'active')->count());
        self::assertDatabaseHas('exam_candidate_accommodations', ['id' => $accommodation->getKey(), 'status' => 'superseded']);
    }

    public function test_seeded_invigilator_role_can_reach_the_examination_workspace_and_respond_to_its_own_duty(): void
    {
        $fixture = $this->fixture();
        $invigilator = User::factory()->create();
        $this->actingAs($fixture['owner'])->post('/t/'.$fixture['tenant']->slug.'/academics/examinations/cycles', [
            'academic_period_id' => $fixture['period']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
            'code' => 'DUTY-'.Str::upper(Str::random(5)), 'title' => 'Invigilator workspace test', 'category' => 'final',
        ])->assertRedirect();

        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            $membership = TenantMembership::query()->create(['institution_id' => $fixture['institution']->getKey(), 'user_id' => $invigilator->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $fixture['tenant']->getKey())->where('key', 'exam.invigilator')->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $fixture['owner']->getKey(), 'tenant_id' => $fixture['tenant']->getKey()]);
            self::assertTrue($membership->fresh()->hasPermission('academic.view'));
            self::assertTrue($membership->fresh()->hasPermission('exams.view'));
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->actingAs($invigilator)->get('/t/'.$fixture['tenant']->slug.'/academics/examinations')->assertOk();
    }

    public function test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $cycleId = (int) $session->cycle_id;
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect();
        $candidate = ExamCandidate::query()->where('cycle_id', $cycleId)->where('student_id', $fixture['student']->getKey())->firstOrFail();
        $firstExaminer = User::factory()->create();
        $secondExaminer = User::factory()->create();
        $moderator = User::factory()->create();
        $this->grantExamPermission($fixture, $firstExaminer, 'exams.mark');
        $this->grantExamPermission($fixture, $secondExaminer, 'exams.mark');
        $this->grantExamPermission($fixture, $moderator, 'exams.moderate');

        $payload = [
            'component_type' => 'practical', 'code' => 'LAB-'.Str::upper(Str::random(5)), 'title' => 'Chemistry laboratory practical',
            'instructions' => 'Complete the assigned laboratory method and explain the result.', 'room_id' => $fixture['room']->getKey(),
            'maximum_marks' => '20.00', 'second_marking_required' => true, 'blind_second_marking' => true, 'moderation_difference_threshold' => '2.00',
            'rubric' => [
                ['key' => 'method', 'title' => 'Method and safety', 'maximum_marks' => '12.00'],
                ['key' => 'analysis', 'title' => 'Analysis and explanation', 'maximum_marks' => '8.00'],
            ],
        ];
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/components', $payload)->assertRedirect();
        $component = ExamComponent::query()->where('code', $payload['code'])->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/components/'.$component->getKey().'/publish')->assertRedirect();
        foreach ([[$firstExaminer, 'primary'], [$secondExaminer, 'second'], [$moderator, 'moderator']] as [$user, $role]) {
            $this->actingAs($fixture['owner'])->post($root.'/components/'.$component->getKey().'/examiners', ['user_id' => $user->getKey(), 'role' => $role])->assertRedirect();
        }
        foreach ([[$firstExaminer, 1], [$secondExaminer, 2]] as [$user, $round]) {
            $this->actingAs($fixture['owner'])->post($root.'/components/'.$component->getKey().'/allocations', ['candidate_id' => $candidate->getKey(), 'examiner_id' => $user->getKey(), 'round' => $round, 'reason' => 'Independent practical assessment'])->assertRedirect();
        }
        $firstAllocation = ExamComponentAllocation::query()->where('component_id', $component->getKey())->where('marking_round', 1)->firstOrFail();
        $secondAllocation = ExamComponentAllocation::query()->where('component_id', $component->getKey())->where('marking_round', 2)->firstOrFail();
        $this->actingAs($firstExaminer)->get($root.'/marking')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/examinations/marking')->where('workspace.allocations.data.0.id', $firstAllocation->getKey())->where('workspace.allocations.data.0.component', $component->title));
        $markUrl = $root.'/marking/allocations/';
        $firstDraft = ['marks' => ['method' => '10.50', 'analysis' => '5.00'], 'feedback' => 'Good laboratory technique.', 'private_notes' => 'Review calibration notes.', 'expected_version' => 0];
        $this->actingAs($firstExaminer)->patch($markUrl.$firstAllocation->getKey().'/draft', $firstDraft)->assertRedirect();
        $this->actingAs($firstExaminer)->post($markUrl.$firstAllocation->getKey().'/submit', ['expected_version' => 1])->assertRedirect();
        $this->assertDatabaseHas('exam_component_markings', ['allocation_id' => $firstAllocation->getKey(), 'revision' => 1, 'total_marks' => '15.50']);
        $secondDraft = ['marks' => ['method' => '9.00', 'analysis' => '4.50'], 'feedback' => 'Repeat measurement.', 'private_notes' => 'Second marker private note.', 'expected_version' => 0];
        $this->actingAs($secondExaminer)->patch($markUrl.$secondAllocation->getKey().'/draft', $secondDraft)->assertRedirect();
        $this->actingAs($secondExaminer)->post($markUrl.$secondAllocation->getKey().'/submit', ['expected_version' => 1])->assertRedirect();
        $unassignedModerator = User::factory()->create();
        $this->grantExamPermission($fixture, $unassignedModerator, 'exams.moderate');
        $this->actingAs($unassignedModerator)->get($root.'/marking')->assertOk()->assertInertia(fn ($page) => $page->where('workspace.moderation', []));
        $this->actingAs($moderator)->get($root.'/marking')->assertOk()->assertInertia(fn ($page) => $page->where('workspace.moderation.0.firstMarks', '15.50')->where('workspace.moderation.0.secondMarks', '13.50')->where('workspace.moderation.0.difference', '2.00'));
        $this->actingAs($firstExaminer)->post($root.'/components/'.$component->getKey().'/candidates/'.$candidate->getKey().'/moderate', ['final_marks' => '15.50', 'decision' => 'adjusted', 'reason' => 'Reviewed independent rubric evidence.'])->assertForbidden();
        $this->actingAs($moderator)->post($root.'/components/'.$component->getKey().'/candidates/'.$candidate->getKey().'/moderate', ['final_marks' => '14.75', 'decision' => 'adjusted', 'reason' => 'Resolved the material criterion difference using the retained evidence.'])->assertRedirect();
        $this->assertDatabaseHas('exam_component_moderation_decisions', ['component_id' => $component->getKey(), 'candidate_id' => $candidate->getKey(), 'first_marks' => '15.50', 'second_marks' => '13.50', 'final_marks' => '14.75', 'decision' => 'adjusted']);
        $this->assertDatabaseHas('exam_component_results', ['component_id' => $component->getKey(), 'candidate_id' => $candidate->getKey(), 'version' => 1, 'final_marks' => '14.75', 'result_status' => 'approved', 'policy_version' => 'exam-marking-v1']);
        self::assertSame(2, DB::table('exam_component_markings')->where('tenant_id', $fixture['tenant']->getKey())->whereIn('allocation_id', [$firstAllocation->getKey(), $secondAllocation->getKey()])->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.marks.moderated']);

        $singlePayload = $payload;
        $singlePayload['code'] = 'SINGLE-'.Str::upper(Str::random(5));
        $singlePayload['title'] = 'Single-mark practical assessment';
        $singlePayload['second_marking_required'] = false;
        $singlePayload['blind_second_marking'] = false;
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/components', $singlePayload)->assertRedirect();
        $singleComponent = ExamComponent::query()->where('code', $singlePayload['code'])->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/components/'.$singleComponent->getKey().'/publish')->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/components/'.$singleComponent->getKey().'/examiners', ['user_id' => $firstExaminer->getKey(), 'role' => 'primary'])->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/components/'.$singleComponent->getKey().'/allocations', ['candidate_id' => $candidate->getKey(), 'examiner_id' => $firstExaminer->getKey(), 'round' => 1, 'reason' => 'Single marker practical assessment'])->assertRedirect();
        $singleAllocation = ExamComponentAllocation::query()->where('component_id', $singleComponent->getKey())->firstOrFail();
        $singleDraft = ['marks' => ['method' => '10.00', 'analysis' => '6.00'], 'feedback' => 'Assessment completed.', 'private_notes' => '', 'expected_version' => 0];
        $this->actingAs($firstExaminer)->patch($markUrl.$singleAllocation->getKey().'/draft', $singleDraft)->assertRedirect();
        $this->actingAs($firstExaminer)->post($markUrl.$singleAllocation->getKey().'/submit', ['expected_version' => 1])->assertRedirect();
        $singleMarking = DB::table('exam_component_markings')->where('allocation_id', $singleAllocation->getKey())->firstOrFail();
        $this->assertDatabaseHas('exam_component_results', ['component_id' => $singleComponent->getKey(), 'candidate_id' => $candidate->getKey(), 'final_marks' => '16.00', 'result_status' => 'pending_approval', 'approved_by' => null]);
        $this->actingAs($fixture['owner'])->post($root.'/markings/'.$singleMarking->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('exam_component_results', ['component_id' => $singleComponent->getKey(), 'candidate_id' => $candidate->getKey(), 'final_marks' => '16.00', 'result_status' => 'approved', 'approved_by' => $fixture['owner']->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.component.result_versioned', 'subject_id' => $singleAllocation->getKey()]);
        $this->assertDatabaseHas('exam_component_allocation_history', ['allocation_id' => $firstAllocation->getKey(), 'previous_examiner_id' => null, 'new_examiner_id' => $firstExaminer->getKey()]);
    }

    public function test_answer_scripts_are_quarantined_scanned_and_authorized_download_is_private(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$session->cycle_id.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect();
        $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->where('student_id', $fixture['student']->getKey())->firstOrFail();
        $scanner = new class implements AdmissionDocumentScanner
        {
            /** @var list<string> */
            public array $scanned = [];

            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                $this->scanned[] = $absolutePath;

                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-clamav-signature-v1';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $scanner);
        $file = UploadedFile::fake()->createWithContent('candidate-script.txt', 'private practical exam script');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/scripts', ['candidate_id' => $candidate->getKey(), 'file' => $file])->assertRedirect();
        $script = ExamAnswerScript::query()->where('candidate_id', $candidate->getKey())->firstOrFail();
        self::assertSame('clean', $script->scan_status);
        self::assertSame(1, count($scanner->scanned));
        self::assertStringStartsWith('examinations/'.$fixture['tenant']->getKey().'/quarantine/', $script->private_path);
        self::assertStringNotContainsString('private_path', json_encode($script->only(['id', 'original_name', 'scan_status']), JSON_THROW_ON_ERROR));
        $download = $this->actingAs($fixture['owner'])->get($root.'/scripts/'.$script->getKey().'/download')->assertOk();
        $cacheControl = strtolower((string) $download->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        $unassignedExaminer = User::factory()->create();
        $this->grantExamPermission($fixture, $unassignedExaminer, 'exams.scripts.view');
        $this->actingAs($unassignedExaminer)->get($root.'/scripts/'.$script->getKey().'/download')->assertNotFound();

        $infectedScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::rejected('malware_detected');
            }

            public function name(): string
            {
                return 'test-clamav-signature-v1';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $infectedScanner);
        $infected = UploadedFile::fake()->createWithContent('infected-script.txt', 'harmless test fixture content');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/scripts', ['candidate_id' => $candidate->getKey(), 'file' => $infected])->assertRedirect();
        $infectedScript = ExamAnswerScript::query()->where('candidate_id', $candidate->getKey())->orderByDesc('id')->firstOrFail();
        self::assertSame('infected', $infectedScript->scan_status);
        $this->actingAs($fixture['owner'])->get($root.'/scripts/'.$infectedScript->getKey().'/download')->assertNotFound();

        $unavailableScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::unavailable('scanner_unavailable');
            }

            public function name(): string
            {
                return 'unavailable';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $unavailableScanner);
        $unscanned = UploadedFile::fake()->createWithContent('scanner-unavailable.txt', 'script remains quarantined');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/scripts', ['candidate_id' => $candidate->getKey(), 'file' => $unscanned])->assertRedirect();
        $unscannedScript = ExamAnswerScript::query()->where('candidate_id', $candidate->getKey())->orderByDesc('id')->firstOrFail();
        self::assertSame('unavailable', $unscannedScript->scan_status);
        $this->actingAs($fixture['owner'])->get($root.'/scripts/'.$unscannedScript->getKey().'/download')->assertNotFound();
    }

    public function test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$session->cycle_id.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect();
        $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->where('student_id', $fixture['student']->getKey())->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/attendance', ['candidate_id' => $candidate->getKey(), 'attendance_status' => 'present'])->assertRedirect();

        $now = now();
        $componentId = DB::table('exam_components')->insertGetId([
            'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'component_type' => 'oral', 'code' => 'ORAL-'.Str::upper(Str::random(5)),
            'title' => 'Oral assessment', 'maximum_marks' => '20.00', 'status' => 'published', 'second_marking_required' => false,
            'blind_second_marking' => false, 'created_by' => $fixture['owner']->getKey(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('exam_component_results')->insert([
            'tenant_id' => $fixture['tenant']->getKey(), 'component_id' => $componentId, 'candidate_id' => $candidate->getKey(), 'version' => 1,
            'final_marks' => '18.00', 'maximum_marks' => '20.00', 'result_status' => 'approved', 'policy_version' => 'exam-marking-v1',
            'approved_by' => $fixture['owner']->getKey(), 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->actingAs($fixture['owner'])->put($root.'/sessions/'.$session->id.'/result-policy', ['written_weight' => '0', 'pass_percent' => '50', 'recheck_window_days' => 14, 'components' => [['component_id' => $componentId, 'weight_percent' => '100']]])->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/results/compile')->assertRedirect();
        $result = DB::table('exam_results')->where('tenant_id', $fixture['tenant']->getKey())->where('session_id', $session->id)->where('candidate_id', $candidate->getKey())->firstOrFail();
        self::assertSame('approval_pending', $result->status);
        self::assertEquals(90, (float) $result->total_marks);
        self::assertSame(1, DB::table('exam_result_versions')->where('tenant_id', $fixture['tenant']->getKey())->where('result_id', $result->id)->count());
        $this->actingAs($fixture['owner'])->post($root.'/results/'.$result->id.'/approve', ['checklist' => ['scores_verified' => '1', 'attendance_verified' => '1', 'paper_version_verified' => '1'], 'reason' => 'Independent checklist'])->assertForbidden();

        $approver = User::factory()->create();
        $publisher = User::factory()->create();
        $this->grantExamPermission($fixture, $approver, 'exams.results.approve');
        $this->grantExamPermission($fixture, $publisher, 'exams.results.release');
        $this->actingAs($approver)->post($root.'/results/'.$result->id.'/approve', ['checklist' => ['scores_verified' => '1', 'attendance_verified' => '1', 'paper_version_verified' => '1'], 'reason' => 'Verified sources, attendance and paper version'])->assertRedirect();
        $this->actingAs($publisher)->post($root.'/results/'.$result->id.'/release')->assertRedirect();
        $this->assertDatabaseHas('exam_results', ['id' => $result->id, 'status' => 'released', 'outcome' => 'pass']);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.result.released:'.$result->id.':v1', 'event_type' => 'exam.result_released', 'status' => 'queued']);
        $this->actingAs($fixture['owner'])->get($root.'/results')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.analytics.subjectPerformance.0.campus', 'Main Campus')
            ->where('workspace.analytics.subjectPerformance.0.courseCode', $fixture['course']->code)
            ->where('workspace.analytics.subjectPerformance.0.releasedCandidates', 1)
            ->where('workspace.analytics.subjectPerformance.0.averagePercentage', 90)
            ->where('workspace.analytics.subjectPerformance.0.passRatePercent', 100));
        $csv = $this->actingAs($fixture['owner'])->get($root.'/results.csv?session_id='.$session->id)->assertOk();
        self::assertStringContainsString('"Candidate number","Examination session",Status,Outcome', $csv->streamedContent());
        self::assertStringContainsString($candidate->candidate_number, $csv->streamedContent());
        $contract = $this->actingAs($fixture['owner'])->getJson($root.'/results/'.$result->id.'/grade-contract')->assertOk();
        $contract->assertJsonPath('data.status', 'released')->assertJsonPath('data.examination.session_id', $session->id)->assertJsonPath('data.score.outcome', 'pass')->assertJsonPath('data.version', 1);
        self::assertStringContainsString('no-store', (string) $contract->headers->get('Cache-Control'));

        $this->grantExamPermission($fixture, $fixture['student']->user, ['exams.student.view-own', 'exams.recheck.request']);
        $this->grantExamPermission($fixture, $fixture['otherStudent']->user, ['exams.student.view-own', 'exams.recheck.request']);
        $studentRoot = '/t/'.$fixture['tenant']->slug.'/examinations/results';
        $this->actingAs($fixture['student']->user)->get('/t/'.$fixture['tenant']->slug.'/examinations/my')->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/student-components')->where('items.0.title', 'Oral assessment')->where('items.0.status', 'completed'));
        $this->actingAs($fixture['student']->user)->get($studentRoot)->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/my-results')->where('workspace.released.0.marks', fn ($marks): bool => (float) $marks === 90.0)->where('workspace.pendingCount', 0));
        $this->actingAs($fixture['otherStudent']->user)->get($studentRoot)->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.released', []));
        $this->actingAs($fixture['student']->user)->post($studentRoot.'/'.$result->id.'/rechecks', ['request_type' => 'appeal', 'reason' => 'Please review the published examination outcome and supporting evidence.'])->assertRedirect();
        $this->assertDatabaseHas('exam_recheck_requests', ['tenant_id' => $fixture['tenant']->getKey(), 'result_id' => $result->id, 'student_user_id' => $fixture['student']->user->getKey(), 'request_type' => 'appeal', 'status' => 'submitted']);
        $appealId = (int) DB::table('exam_recheck_requests')->where('tenant_id', $fixture['tenant']->getKey())->where('result_id', $result->id)->value('id');
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.appeal.requested:'.$appealId, 'event_type' => 'exam.appeal_requested', 'status' => 'queued']);
        $this->actingAs($fixture['otherStudent']->user)->post($root.'/appeals/'.$appealId.'/transition', ['action' => 'escalate', 'reason' => 'Unauthorized escalation attempt.'])->assertForbidden();
        $cleanScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-clamav-signature-v1';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $cleanScanner);
        $appealFile = UploadedFile::fake()->createWithContent('appeal-evidence.pdf', "%PDF-1.4\nverified evidence fixture");
        $this->actingAs($fixture['otherStudent']->user)->post($root.'/appeals/'.$appealId.'/evidence', ['evidence' => $appealFile])->assertForbidden();
        $this->actingAs($fixture['student']->user)->post('/t/'.$fixture['tenant']->slug.'/examinations/appeals/'.$appealId.'/evidence', ['evidence' => $appealFile])->assertRedirect();
        $appealEvidence = DB::table('exam_appeal_evidence')->where('tenant_id', $fixture['tenant']->getKey())->where('appeal_id', $appealId)->firstOrFail();
        self::assertSame('clean', $appealEvidence->scan_status);
        self::assertStringStartsWith('examinations/'.$fixture['tenant']->getKey().'/appeals/'.$appealId.'/quarantine/', $appealEvidence->private_path);
        $this->actingAs($fixture['student']->user)->get('/t/'.$fixture['tenant']->slug.'/examinations/appeal-evidence/'.$appealEvidence->id.'/download')->assertOk();
        $this->actingAs($fixture['otherStudent']->user)->get('/t/'.$fixture['tenant']->slug.'/examinations/appeal-evidence/'.$appealEvidence->id.'/download')->assertForbidden();
        $this->assertDatabaseHas('exam_appeal_history', ['tenant_id' => $fixture['tenant']->getKey(), 'appeal_id' => $appealId, 'action' => 'evidence_quarantined']);
        $this->assertDatabaseHas('exam_appeal_history', ['tenant_id' => $fixture['tenant']->getKey(), 'appeal_id' => $appealId, 'action' => 'evidence_accessed']);
        $this->actingAs($fixture['owner'])->get($root.'/results')->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.rechecks.0.evidence.0.scanStatus', 'clean'));
        $this->actingAs($fixture['student']->user)->get($studentRoot)->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.released.0.recheck.evidence.0.scanStatus', 'clean'));
        $foreignTenant = $this->fixture();
        $this->actingAs($foreignTenant['owner'])->get('/t/'.$foreignTenant['tenant']->slug.'/examinations/appeal-evidence/'.$appealEvidence->id.'/download')->assertNotFound();
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(2, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user->getKey(), 'event_type' => 'exam.result_released']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['owner']->getKey(), 'event_type' => 'exam.appeal_requested']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.result.compiled']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.result.released']);

        $reviewer = User::factory()->create();
        $amendmentApprover = User::factory()->create();
        $this->grantExamPermission($fixture, $reviewer, 'exams.recheck.review');
        $this->grantExamPermission($fixture, $amendmentApprover, 'exams.results.approve');
        $reviewerEvidence = UploadedFile::fake()->createWithContent('reviewer-context.pdf', "%PDF-1.4\nReview context attached by the authorized examiner.");
        $this->actingAs($reviewer)->post($root.'/appeals/'.$appealId.'/evidence', ['evidence' => $reviewerEvidence])->assertRedirect();
        $this->assertDatabaseHas('exam_appeal_evidence', ['tenant_id' => $fixture['tenant']->getKey(), 'appeal_id' => $appealId, 'uploaded_by' => $reviewer->getKey(), 'scan_status' => 'clean']);
        $this->assertDatabaseHas('exam_appeal_history', ['tenant_id' => $fixture['tenant']->getKey(), 'appeal_id' => $appealId, 'action' => 'evidence_quarantined', 'actor_user_id' => $reviewer->getKey(), 'to_status' => 'submitted']);
        $recheck = DB::table('exam_recheck_requests')->where('tenant_id', $fixture['tenant']->getKey())->where('result_id', $result->id)->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/rechecks/'.$recheck->id.'/assign', ['reviewer_id' => $reviewer->getKey()])->assertRedirect();
        $this->actingAs($reviewer)->post($root.'/appeals/'.$recheck->id.'/transition', ['action' => 'escalate', 'reason' => 'A second independent review is required.'])->assertRedirect();
        $this->assertDatabaseHas('exam_recheck_requests', ['id' => $recheck->id, 'status' => 'escalated']);
        $this->actingAs($fixture['owner'])->post($root.'/rechecks/'.$recheck->id.'/assign', ['reviewer_id' => $reviewer->getKey()])->assertRedirect();
        $this->actingAs($reviewer)->post($root.'/rechecks/'.$recheck->id.'/decision', ['decision' => 'adjusted', 'findings' => 'A clerical transcription discrepancy was verified.', 'proposed_marks' => '48'])->assertRedirect();
        $this->actingAs($amendmentApprover)->post($root.'/rechecks/'.$recheck->id.'/approve-amendment', ['reason' => 'Independent evidence review confirms the corrected score.'])->assertRedirect();
        $amended = DB::table('exam_results')->where('tenant_id', $fixture['tenant']->getKey())->where('id', $result->id)->firstOrFail();
        self::assertSame(2, (int) $amended->version);
        self::assertSame('fail', $amended->outcome);
        self::assertEquals(48, (float) $amended->total_marks);
        self::assertSame(2, DB::table('exam_result_versions')->where('tenant_id', $fixture['tenant']->getKey())->where('result_id', $result->id)->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.amendment_approved']);
        $oldVersion = json_decode((string) DB::table('exam_result_versions')->where('tenant_id', $fixture['tenant']->getKey())->where('result_id', $result->id)->where('version', 1)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(90, (float) $oldVersion['total_marks']);
        $this->actingAs($publisher)->post($root.'/results/'.$result->id.'/release')->assertRedirect();
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.result.released:'.$result->id.':v2', 'event_type' => 'exam.result_released', 'status' => 'queued']);
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(3, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => 'exam.appeal.decided:'.$appealId, 'event_type' => 'exam.appeal_decided', 'status' => 'delivered']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user->getKey(), 'event_type' => 'exam.appeal_decided']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.submitted']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.decided']);
        $this->actingAs($fixture['student']->user)->get($studentRoot)->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.released.0.version', 2)->where('workspace.released.0.outcome', 'fail'));
        $this->actingAs($fixture['owner'])->post($root.'/appeals/'.$recheck->id.'/transition', ['action' => 'close', 'reason' => 'The released decision and remedy are complete.'])->assertRedirect();
        $this->assertDatabaseHas('exam_recheck_requests', ['id' => $recheck->id, 'status' => 'closed']);
        $closedUpload = UploadedFile::fake()->createWithContent('late-evidence.pdf', "%PDF-1.4\nnot accepted while closed");
        $this->actingAs($fixture['owner'])->post($root.'/appeals/'.$recheck->id.'/evidence', ['evidence' => $closedUpload])->assertStatus(409);
        $this->actingAs($fixture['owner'])->post($root.'/appeals/'.$recheck->id.'/transition', ['action' => 'reopen', 'reason' => 'Additional evidence was submitted within the permitted window.'])->assertRedirect();
        $this->assertDatabaseHas('exam_recheck_requests', ['id' => $recheck->id, 'status' => 'reopened']);
        $infectedScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::rejected('malware_detected');
            }

            public function name(): string
            {
                return 'test-clamav-signature-v1';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $infectedScanner);
        $infectedFile = UploadedFile::fake()->createWithContent('infected-appeal.pdf', "%PDF-1.4\nrejected malware fixture");
        $this->actingAs($fixture['student']->user)->post('/t/'.$fixture['tenant']->slug.'/examinations/appeals/'.$recheck->id.'/evidence', ['evidence' => $infectedFile])->assertRedirect();
        $infectedEvidence = DB::table('exam_appeal_evidence')->where('tenant_id', $fixture['tenant']->getKey())->where('appeal_id', $appealId)->orderByDesc('id')->firstOrFail();
        self::assertSame('infected', $infectedEvidence->scan_status);
        $this->actingAs($fixture['student']->user)->get('/t/'.$fixture['tenant']->slug.'/examinations/appeal-evidence/'.$infectedEvidence->id.'/download')->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.escalate']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.close']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.appeal.reopen']);
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(2, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user->getKey(), 'event_type' => 'exam.appeal_closed']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user->getKey(), 'event_type' => 'exam.appeal_reopened']);
        self::assertSame(
            ['submitted', 'evidence_quarantined', 'evidence_scanned', 'evidence_accessed', 'evidence_quarantined', 'evidence_scanned', 'assigned', 'escalate', 'assigned', 'decision_recorded', 'remedy_approved', 'close', 'reopen', 'evidence_quarantined', 'evidence_scanned'],
            DB::table('exam_appeal_history')->where('tenant_id', $fixture['tenant']->getKey())->where('appeal_id', $appealId)->orderBy('id')->pluck('action')->all(),
        );
        $this->actingAs($fixture['owner'])->get($root.'/results')->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.rechecks.0.history.12.action', 'reopen'));
        DB::table('tenant_memberships')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['student']->user->getKey())->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($fixture['student']->user)->get('/t/'.$fixture['tenant']->slug.'/examinations/appeal-evidence/'.$appealEvidence->id.'/download')->assertNotFound();
    }

    public function test_result_policy_rejects_non_session_components_and_student_result_is_not_visible_before_release(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $candidate = ExamCandidate::query()->create([
                'cycle_id' => $session->cycle_id, 'student_id' => $fixture['student']->getKey(), 'registered_by' => $fixture['owner']->getKey(),
                'candidate_number' => $fixture['student']->student_number, 'status' => 'registered', 'registered_at' => now(),
            ]);
            DB::table('exam_attendance_records')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'candidate_id' => $candidate->getKey(),
                'attendance_status' => 'present', 'recording_method' => 'manual', 'recorded_by' => $fixture['owner']->getKey(), 'recorded_at' => now(),
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $now = now();
            DB::table('exam_seat_allocations')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'candidate_id' => $candidate->getKey(),
                'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-01', 'allocated_by' => $fixture['owner']->getKey(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('exam_invigilator_assignments')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'user_id' => $fixture['owner']->getKey(),
                'room_id' => $fixture['room']->getKey(), 'duty_role' => 'chief', 'assigned_by' => $fixture['owner']->getKey(),
                'status' => 'accepted', 'accepted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('exam_eligibility_exceptions')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'candidate_id' => $candidate->getKey(), 'cycle_id' => $session->cycle_id,
                'rule_key' => 'confirmed_course_enrollment', 'student_reason' => 'Enrollment is under review.', 'status' => 'pending',
                'requested_by' => $fixture['student']->user_id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('exam_accommodation_requests')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'candidate_id' => $candidate->getKey(), 'accommodation_type' => 'extra_time',
                'extra_time_minutes' => 10, 'status' => 'approved', 'requested_by' => $fixture['student']->user_id,
                'reviewed_by' => $fixture['owner']->getKey(), 'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('exam_admit_documents')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'candidate_id' => $candidate->getKey(), 'session_id' => $session->id,
                'revision' => 1, 'verification_hash' => hash('sha256', Str::random(40)), 'verification_code_encrypted' => encrypt(Str::random(40)),
                'safe_snapshot' => json_encode(['session' => 'Paper session'], JSON_THROW_ON_ERROR), 'issued_by' => $fixture['owner']->getKey(),
                'issued_at' => $now, 'expires_at' => now()->addDay(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('exam_date_sheets')->insert([
                'tenant_id' => $fixture['tenant']->getKey(), 'cycle_id' => $session->cycle_id, 'current_revision' => 1,
                'status' => 'published', 'created_by' => $fixture['owner']->getKey(), 'published_by' => $fixture['owner']->getKey(),
                'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } finally {
            $context->clear();
        }
        $this->actingAs($fixture['owner'])->get($root.'/results')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace/examinations/results')->has('workspace.metrics')
            ->where('workspace.operationalReports.0.registered', 1)->where('workspace.operationalReports.0.present', 1)
            ->where('workspace.operationalReports.0.dateSheetStatus', 'published')
            ->where('workspace.operationalReports.0.seatsAllocated', 1)->where('workspace.operationalReports.0.seatCapacity', 1)
            ->where('workspace.operationalReports.0.seatUtilizationPercent', 100)
            ->where('workspace.operationalReports.0.invigilatorsAssigned', 1)->where('workspace.operationalReports.0.invigilatorsAccepted', 1)
            ->where('workspace.operationalReports.0.eligibilityExceptionsPending', 1)
            ->where('workspace.operationalReports.0.approvedAccommodations', 1)
            ->where('workspace.operationalReports.0.activeAdmitDocuments', 1)
            ->where('workspace.operationalReports.0.campus', 'Main Campus')->where('workspace.operationalReports.0.subject', $fixture['course']->code.' '.$fixture['course']->title));
        $operationsCsv = $this->actingAs($fixture['owner'])->get($root.'/operations.csv')->assertOk();
        self::assertStringContainsString('Session,Campus,Subject,Status,"Starts at UTC"', $operationsCsv->streamedContent());
        self::assertStringContainsString('Paper session', $operationsCsv->streamedContent());
        self::assertStringContainsString('"Open appeals","Open incidents","Date sheet status","Seats allocated","Seat capacity"', $operationsCsv->streamedContent());
        self::assertStringContainsString($fixture['course']->title, $operationsCsv->streamedContent());
        self::assertStringContainsString('no-store', (string) $operationsCsv->headers->get('Cache-Control'));
        $filteredOperationsCsv = $this->actingAs($fixture['owner'])->get($root.'/operations.csv?session_id='.$session->id)->assertOk();
        self::assertStringContainsString('Paper session', $filteredOperationsCsv->streamedContent());
        self::assertSame(2, substr_count($filteredOperationsCsv->streamedContent(), "\n"));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.operations_report.exported']);
        $unauthorized = User::factory()->create();
        $this->grantExamPermission($fixture, $unauthorized, 'exams.view');
        $this->actingAs($unauthorized)->get($root.'/results.csv')->assertForbidden();
        $this->grantExamPermission($fixture, $fixture['student']->user, ['exams.student.view-own', 'exams.recheck.request']);
        $studentRoot = '/t/'.$fixture['tenant']->slug.'/examinations/results';
        $this->actingAs($fixture['student']->user)->get($studentRoot)->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/my-results')->where('workspace.released', []));
        $other = $this->fixture();
        $this->actingAs($fixture['owner'])->get($root.'/operations.csv?session_id=999999999')->assertNotFound();
        $this->actingAs($other['owner'])->put('/t/'.$other['tenant']->slug.'/academics/examinations/sessions/'.$session->id.'/result-policy', ['written_weight' => '100', 'pass_percent' => '50', 'recheck_window_days' => 14, 'components' => []])->assertNotFound();
    }

    public function test_date_sheet_revision_requires_independent_review_and_preserves_published_snapshot(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $candidate = ExamCandidate::query()->create([
                'cycle_id' => $session->cycle_id, 'student_id' => $fixture['student']->getKey(), 'registered_by' => $fixture['owner']->getKey(),
                'candidate_number' => $fixture['student']->student_number, 'status' => 'registered', 'registered_at' => now(),
            ]);
            TenantMembership::query()->create(['institution_id' => $fixture['institution']->getKey(), 'user_id' => $fixture['student']->user_id, 'status' => 'active', 'activated_at' => now()]);
        } finally {
            $context->clear();
        }
        $approver = User::factory()->create();
        $publisher = User::factory()->create();
        $this->grantExamPermission($fixture, $approver, 'exams.approve');
        $this->grantExamPermission($fixture, $publisher, 'exams.publish');

        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$session->cycle_id.'/date-sheet/revisions', ['session_ids' => [$session->id]])->assertRedirect();
        $sheet = DB::table('exam_date_sheets')->where('tenant_id', $fixture['tenant']->getKey())->where('cycle_id', $session->cycle_id)->firstOrFail();
        $revision = DB::table('exam_date_sheet_revisions')->where('tenant_id', $fixture['tenant']->getKey())->where('date_sheet_id', $sheet->id)->firstOrFail();
        self::assertSame('draft', $revision->status);
        $snapshot = json_decode((string) $revision->schedule_snapshot, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $session->id, $snapshot[0]['session_id']);
        self::assertSame('Paper session', $snapshot[0]['title']);

        $this->actingAs($fixture['owner'])->post($root.'/date-sheet-revisions/'.$revision->id.'/submit')->assertRedirect();
        $this->actingAs($fixture['owner'])->post($root.'/date-sheet-revisions/'.$revision->id.'/decision', ['decision' => 'approved', 'notes' => 'Self-review is forbidden.'])->assertForbidden();
        $this->actingAs($approver)->post($root.'/date-sheet-revisions/'.$revision->id.'/decision', ['decision' => 'approved', 'notes' => 'Schedule independently checked.'])->assertRedirect();
        $this->actingAs($approver)->post($root.'/date-sheet-revisions/'.$revision->id.'/publish')->assertForbidden();
        $this->actingAs($publisher)->post($root.'/date-sheet-revisions/'.$revision->id.'/publish')->assertRedirect();

        $this->assertDatabaseHas('exam_date_sheets', ['id' => $sheet->id, 'current_revision' => 1, 'status' => 'published', 'published_by' => $publisher->getKey()]);
        $this->assertDatabaseHas('exam_date_sheet_revisions', ['id' => $revision->id, 'status' => 'published', 'reviewed_by' => $approver->getKey()]);
        self::assertNotNull(DB::table('exam_date_sheet_revisions')->where('id', $revision->id)->value('published_at'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.date_sheet.revision_created']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.date_sheet.published']);
        $noticeKey = 'exam.date_sheet.published:revision:'.$revision->id.':candidate:'.$candidate->getKey();
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_key' => $noticeKey, 'event_type' => 'exam.date_sheet_published', 'status' => 'queued']);
        $context->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(1, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
            self::assertSame(0, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            $context->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user_id, 'event_type' => 'exam.date_sheet_published']);
        self::assertSame(0, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['owner']->getKey())->where('event_type', 'exam.date_sheet_published')->count());
        self::assertSame(1, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['student']->user_id)->where('event_type', 'exam.date_sheet_published')->count());

        $now = now();
        $secondSessionId = DB::table('exam_sessions')->insertGetId([
            'tenant_id' => $fixture['tenant']->getKey(), 'cycle_id' => $session->cycle_id, 'campus_id' => $fixture['campus']->getKey(),
            'academic_course_id' => $fixture['course']->getKey(), 'room_id' => $fixture['room']->getKey(), 'created_by' => $fixture['owner']->getKey(),
            'code' => 'PAPER-SECOND-'.Str::upper(Str::random(5)), 'title' => 'Second scheduled session', 'mode' => 'onsite', 'status' => 'published',
            'starts_at_utc' => $now->addDays(12), 'ends_at_utc' => $now->addDays(12)->addHour(), 'duration_minutes' => 60,
            'capacity' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('exam_sessions')->where('tenant_id', $fixture['tenant']->getKey())->where('id', $session->id)->update(['starts_at_utc' => $now->addDays(9), 'ends_at_utc' => $now->addDays(9)->addHour()]);
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$session->cycle_id.'/date-sheet/revisions', ['session_ids' => [$session->id, $secondSessionId]])->assertRedirect();
        $revisionTwo = DB::table('exam_date_sheet_revisions')->where('tenant_id', $fixture['tenant']->getKey())->where('date_sheet_id', $sheet->id)->where('revision', 2)->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/date-sheet-revisions/'.$revisionTwo->id.'/submit')->assertRedirect();
        $this->actingAs($approver)->post($root.'/date-sheet-revisions/'.$revisionTwo->id.'/decision', ['decision' => 'approved', 'notes' => 'Rescheduled session verified.'])->assertRedirect();
        $this->actingAs($publisher)->post($root.'/date-sheet-revisions/'.$revisionTwo->id.'/publish')->assertRedirect();
        $changeKey = 'exam.schedule.changed:revision:'.$revisionTwo->id.':candidate:'.$candidate->getKey();
        $changeEvent = DB::table('quiz_outbox_events')->where('event_key', $changeKey)->firstOrFail();
        self::assertSame('exam.schedule_changed', $changeEvent->event_type);
        self::assertSame('rescheduled', json_decode((string) $changeEvent->safe_payload, true, 512, JSON_THROW_ON_ERROR)['change_type']);
        $context->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(1, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
            self::assertSame(0, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            $context->clear();
        }
        self::assertSame(1, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['student']->user_id)->where('event_type', 'exam.schedule_changed')->count());
        self::assertSame(0, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['owner']->getKey())->where('event_type', 'exam.schedule_changed')->count());

        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$session->cycle_id.'/date-sheet/revisions', ['session_ids' => [$session->id]])->assertRedirect();
        $revisionThree = DB::table('exam_date_sheet_revisions')->where('tenant_id', $fixture['tenant']->getKey())->where('date_sheet_id', $sheet->id)->where('revision', 3)->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/date-sheet-revisions/'.$revisionThree->id.'/submit')->assertRedirect();
        $this->actingAs($approver)->post($root.'/date-sheet-revisions/'.$revisionThree->id.'/decision', ['decision' => 'approved', 'notes' => 'Removed session cancellation verified.'])->assertRedirect();
        $this->actingAs($publisher)->post($root.'/date-sheet-revisions/'.$revisionThree->id.'/publish')->assertRedirect();
        $cancellationEvent = DB::table('quiz_outbox_events')->where('event_key', 'exam.schedule.changed:revision:'.$revisionThree->id.':candidate:'.$candidate->getKey())->firstOrFail();
        self::assertSame('cancelled', json_decode((string) $cancellationEvent->safe_payload, true, 512, JSON_THROW_ON_ERROR)['change_type']);
        $context->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(1, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
            self::assertSame(0, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            $context->clear();
        }
        self::assertSame(2, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('user_id', $fixture['student']->user_id)->where('event_type', 'exam.schedule_changed')->where('title', 'Examination schedule changed')->count());
    }

    public function test_retake_and_makeup_cycles_require_matching_released_source_outcomes_and_notify_only_the_registered_student(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $sourceCycle = ExamCycle::query()->create([
                'institution_id' => $fixture['institution']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
                'academic_period_id' => $fixture['period']->getKey(), 'created_by' => $fixture['owner']->getKey(),
                'code' => 'SOURCE-'.Str::upper(Str::random(5)), 'title' => 'Completed source examination', 'category' => 'final',
                'status' => 'closed', 'starts_on' => today()->subDays(5), 'ends_on' => today()->subDays(1), 'closed_at' => now(),
            ]);
            $sourceSession = ExamSession::query()->create([
                'cycle_id' => $sourceCycle->getKey(), 'campus_id' => $fixture['campus']->getKey(), 'academic_course_id' => $fixture['course']->getKey(),
                'room_id' => $fixture['room']->getKey(), 'created_by' => $fixture['owner']->getKey(), 'code' => 'SOURCE-SESSION',
                'title' => 'Source examination session', 'mode' => 'onsite', 'status' => 'completed',
                'starts_at_utc' => now()->subDays(2), 'ends_at_utc' => now()->subDays(2)->addHour(), 'duration_minutes' => 60,
            ]);
            $policyId = DB::table('exam_result_policies')->insertGetId([
                'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $sourceSession->getKey(), 'version' => 1,
                'written_weight' => 0, 'pass_percent' => 50, 'recheck_window_days' => 14, 'effective_at' => now()->subDay(),
                'configured_by' => $fixture['owner']->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$fixture['student'], 'fail'], [$fixture['otherStudent'], 'absent']] as [$student, $outcome]) {
                $candidate = ExamCandidate::query()->create([
                    'cycle_id' => $sourceCycle->getKey(), 'student_id' => $student->getKey(), 'registered_by' => $fixture['owner']->getKey(),
                    'candidate_number' => 'SRC-'.$student->student_number, 'status' => 'registered', 'registered_at' => now()->subDays(3),
                ]);
                $user = $student->user;
                $member = TenantMembership::query()->create(['institution_id' => $fixture['institution']->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $studentRole = Role::query()->where('tenant_id', $fixture['tenant']->getKey())->where('key', 'student')->firstOrFail();
                $member->roles()->attach($studentRole->getKey(), ['assigned_by' => $fixture['owner']->getKey(), 'tenant_id' => $fixture['tenant']->getKey()]);
                DB::table('exam_results')->insert([
                    'tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $sourceSession->getKey(), 'candidate_id' => $candidate->getKey(),
                    'policy_id' => $policyId, 'version' => 1, 'status' => 'released', 'outcome' => $outcome,
                    'total_marks' => $outcome === 'fail' ? '35.00' : null, 'maximum_marks' => '100.00', 'percentage' => $outcome === 'fail' ? '35.00' : null,
                    'source_snapshot' => json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR), 'source_hash' => hash('sha256', $outcome),
                    'policy_version' => 'exam-result-policy-1', 'compiled_by' => $fixture['owner']->getKey(), 'approved_by' => $fixture['owner']->getKey(),
                    'approved_at' => now()->subDay(), 'released_by' => $fixture['owner']->getKey(), 'released_at' => now()->subDay(),
                    'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
                ]);
            }
        } finally {
            $context->clear();
        }

        $createAndPublishCycle = function (string $category, string $suffix) use ($fixture, $root, $sourceCycle): int {
            $this->actingAs($fixture['owner'])->post($root.'/cycles', [
                'academic_period_id' => $fixture['period']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
                'code' => $suffix.'-'.Str::upper(Str::random(5)), 'title' => $suffix.' examination', 'category' => $category,
                'source_cycle_id' => $sourceCycle->getKey(),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $cycle = ExamCycle::query()->where('source_cycle_id', $sourceCycle->getKey())->where('category', $category)->latest('id')->firstOrFail();
            foreach (['review', 'approved', 'published'] as $status) {
                $this->actingAs($fixture['owner'])->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => $status])->assertRedirect()->assertSessionHasNoErrors();
            }

            return (int) $cycle->getKey();
        };

        $retakeCycleId = $createAndPublishCycle('retake', 'Retake');
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$retakeCycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$retakeCycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(1, ExamCandidate::query()->where('cycle_id', $retakeCycleId)->where('student_id', $fixture['student']->getKey())->count());
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$retakeCycleId.'/candidates', ['student_id' => $fixture['otherStudent']->getKey()])->assertSessionHasErrors('student_id');

        $makeupCycleId = $createAndPublishCycle('makeup', 'Makeup');
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$makeupCycleId.'/candidates', ['student_id' => $fixture['otherStudent']->getKey()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$makeupCycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertSessionHasErrors('student_id');
        $this->assertDatabaseHas('exam_cycles', ['id' => $retakeCycleId, 'source_cycle_id' => $sourceCycle->getKey(), 'category' => 'retake']);
        $this->assertDatabaseHas('exam_cycles', ['id' => $makeupCycleId, 'source_cycle_id' => $sourceCycle->getKey(), 'category' => 'makeup']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.cycle.created']);
        self::assertSame(1, DB::table('quiz_outbox_events')->where('event_key', 'exam.retake_registered:candidate:'.ExamCandidate::query()->where('cycle_id', $retakeCycleId)->value('id'))->count());
        self::assertSame(1, DB::table('quiz_outbox_events')->where('event_key', 'exam.makeup_registered:candidate:'.ExamCandidate::query()->where('cycle_id', $makeupCycleId)->value('id'))->count());

        $context->enterProvisioning($fixture['tenant']);
        try {
            self::assertSame(2, app(QuizOutboxProcessor::class)->dispatchAvailable()['delivered']);
        } finally {
            $context->clear();
        }
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->user->getKey(), 'event_type' => 'exam.retake_registered']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['otherStudent']->user->getKey(), 'event_type' => 'exam.makeup_registered']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['owner']->getKey(), 'event_type' => 'exam.retake_registered']);
    }

    public function test_seating_plan_is_capacity_checked_independently_reviewed_and_published_as_immutable_history(): void
    {
        $fixture = $this->fixture();
        $root = '/t/'.$fixture['tenant']->slug.'/academics/examinations';
        [$session] = $this->paperQuestionFixture($fixture, $root);
        $cycleId = (int) $session->cycle_id;
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycleId.'/candidates', ['student_id' => $fixture['student']->getKey()])->assertRedirect();
        $candidate = ExamCandidate::query()->withoutGlobalScopes()->where('tenant_id', $fixture['tenant']->getKey())->where('cycle_id', $cycleId)->where('student_id', $fixture['student']->getKey())->firstOrFail();

        $reviewer = User::factory()->create();
        $publisher = User::factory()->create();
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            $ownerRole = Role::query()->where('tenant_id', $fixture['tenant']->getKey())->where('key', 'tenant.owner')->firstOrFail();
            foreach ([$reviewer, $publisher] as $user) {
                $membership = TenantMembership::query()->create(['institution_id' => $fixture['institution']->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $membership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $fixture['owner']->getKey(), 'tenant_id' => $fixture['tenant']->getKey()]);
            }
            $secondRoom = AcademicRoom::query()->create(['institution_id' => $fixture['institution']->getKey(), 'campus_id' => $fixture['campus']->getKey(), 'code' => 'EXAM-2', 'name' => 'Second Examination Hall', 'room_type' => 'classroom', 'capacity' => 1, 'status' => 'active']);
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seating-plans', ['assignments' => [
            ['candidate_id' => $candidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-01'],
            ['candidate_id' => $candidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-02'],
        ]])->assertSessionHasErrors('assignments.0.candidate_id');
        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seating-plans', ['assignments' => [
            ['candidate_id' => $candidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-01'],
        ]])->assertRedirect();
        $revisionOne = DB::table('exam_seating_plan_revisions')->where('tenant_id', $fixture['tenant']->getKey())->where('status', 'draft')->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/seating-plan-revisions/'.$revisionOne->id.'/submit')->assertRedirect();
        $this->actingAs($reviewer)->post($root.'/seating-plan-revisions/'.$revisionOne->id.'/decision', ['decision' => 'approved', 'notes' => 'Capacity and campus reviewed.'])->assertRedirect();
        $this->actingAs($publisher)->post($root.'/seating-plan-revisions/'.$revisionOne->id.'/publish')->assertRedirect();
        $this->assertDatabaseHas('exam_seat_allocations', ['tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'candidate_id' => $candidate->getKey(), 'room_id' => $fixture['room']->getKey(), 'seat_number' => 'A-01']);

        $this->actingAs($fixture['owner'])->post($root.'/sessions/'.$session->id.'/seating-plans', ['assignments' => [
            ['candidate_id' => $candidate->getKey(), 'room_id' => $secondRoom->getKey(), 'seat_number' => 'B-01'],
        ]])->assertRedirect();
        $revisionTwo = DB::table('exam_seating_plan_revisions')->where('tenant_id', $fixture['tenant']->getKey())->where('revision', 2)->firstOrFail();
        $this->actingAs($fixture['owner'])->post($root.'/seating-plan-revisions/'.$revisionTwo->id.'/submit')->assertRedirect();
        $this->actingAs($reviewer)->post($root.'/seating-plan-revisions/'.$revisionTwo->id.'/decision', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($publisher)->post($root.'/seating-plan-revisions/'.$revisionTwo->id.'/publish')->assertRedirect();

        $this->assertDatabaseHas('exam_seating_plan_revisions', ['id' => $revisionOne->id, 'status' => 'superseded']);
        $historicalAssignments = json_decode((string) DB::table('exam_seating_plan_revisions')->where('id', $revisionOne->id)->value('assignment_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('A-01', $historicalAssignments[0]['seat_number']);
        $this->assertDatabaseHas('exam_seat_allocations', ['tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->id, 'candidate_id' => $candidate->getKey(), 'room_id' => $secondRoom->getKey(), 'seat_number' => 'B-01']);
        self::assertSame(2, DB::table('exam_seating_plan_revisions')->where('plan_id', $revisionOne->plan_id)->count());
        self::assertSame(2, DB::table('audit_events')->where('tenant_id', $fixture['tenant']->getKey())->where('event_type', 'exams.seating.published')->count());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $creator = User::factory()->create();
        $owner = User::factory()->create();
        $label = 'Exam Academy '.Str::random(6);
        $tenant = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($label), 'official_name' => $label, 'display_name' => $label, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Main Campus', 'status' => 'active', 'is_primary' => true]);
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $ownerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $membership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Exam Year', 'code' => 'YEAR-'.Str::upper(Str::random(5)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Exam Term', 'code' => 'TERM-'.Str::upper(Str::random(5)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'EX-'.Str::upper(Str::random(5)), 'title' => 'Examination Science', 'status' => 'active']);
            $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Exam eligibility policy']);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'EX-'.Str::upper(Str::random(5)), 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'status' => 'open']);
            $studentUser = User::factory()->create();
            $student = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $studentUser->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(7)), 'registration_reference' => 'REF-'.Str::upper(Str::random(7)), 'legal_first_name' => 'Test', 'legal_last_name' => 'Student', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            $otherStudentUser = User::factory()->create();
            $otherStudent = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $otherStudentUser->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(7)), 'registration_reference' => 'REF-'.Str::upper(Str::random(7)), 'legal_first_name' => 'Other', 'legal_last_name' => 'Student', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
            $room = AcademicRoom::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'code' => 'EXAM-1', 'name' => 'Examination Hall', 'room_type' => 'classroom', 'capacity' => 1, 'status' => 'active']);
        } finally {
            $context->clear();
        }

        return compact('tenant', 'institution', 'campus', 'year', 'period', 'course', 'room', 'owner', 'student', 'otherStudent');
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{0: object, 1: int}
     */
    private function paperQuestionFixture(array $fixture, string $root): array
    {
        $cycleCode = 'PAPER-'.Str::upper(Str::random(6));
        $this->actingAs($fixture['owner'])->post($root.'/cycles', [
            'academic_period_id' => $fixture['period']->getKey(), 'campus_id' => $fixture['campus']->getKey(),
            'code' => $cycleCode, 'title' => 'Paper governance cycle', 'category' => 'final',
        ])->assertRedirect();
        $cycle = ExamCycle::query()->where('code', $cycleCode)->firstOrFail();
        $startsAt = now()->addDays(10)->startOfHour();
        $this->actingAs($fixture['owner'])->post($root.'/cycles/'.$cycle->getKey().'/sessions', [
            'campus_id' => $fixture['campus']->getKey(), 'academic_course_id' => $fixture['course']->getKey(),
            'room_id' => $fixture['room']->getKey(), 'code' => 'PAPER-SESSION-'.Str::upper(Str::random(5)), 'title' => 'Paper session',
            'mode' => 'onsite', 'starts_at' => $startsAt->format('Y-m-d\TH:i'), 'ends_at' => $startsAt->copy()->addMinutes(60)->format('Y-m-d\TH:i'),
            'duration_minutes' => 60, 'capacity' => 1,
        ])->assertRedirect();
        $session = DB::table('exam_sessions')->where('tenant_id', $fixture['tenant']->getKey())->where('cycle_id', $cycle->getKey())->first();
        self::assertNotNull($session);
        foreach (['review', 'approved', 'published'] as $state) {
            $this->actingAs($fixture['owner'])->patch($root.'/cycles/'.$cycle->getKey().'/status', ['to' => $state])->assertRedirect();
        }
        $session = DB::table('exam_sessions')->where('id', $session->id)->first();
        self::assertSame('published', $session->status);

        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            $now = now();
            $bankId = DB::table('quiz_question_banks')->insertGetId([
                'tenant_id' => $fixture['tenant']->getKey(), 'institution_id' => $fixture['institution']->getKey(),
                'campus_id' => $fixture['campus']->getKey(), 'academic_course_id' => $fixture['course']->getKey(),
                'created_by' => $fixture['owner']->getKey(), 'code' => 'BANK-'.Str::upper(Str::random(5)),
                'name' => 'Approved exam question bank', 'sharing_scope' => 'campus', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $questionId = DB::table('quiz_questions')->insertGetId([
                'tenant_id' => $fixture['tenant']->getKey(), 'bank_id' => $bankId, 'status' => 'active', 'current_version' => 1,
                'created_by' => $fixture['owner']->getKey(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $questionVersionId = DB::table('quiz_question_versions')->insertGetId([
                'tenant_id' => $fixture['tenant']->getKey(), 'question_id' => $questionId, 'version_number' => 1,
                'question_type' => 'single_choice', 'difficulty' => 'moderate', 'estimated_seconds' => 60, 'default_points' => '5.00',
                'content' => json_encode(['prompt' => 'Which is a living organism?', 'options' => ['Oak', 'Stone']], JSON_THROW_ON_ERROR),
                'answer_key' => encrypt(json_encode(['correct' => 'Oak'], JSON_THROW_ON_ERROR)), 'status' => 'active',
                'created_by' => $fixture['owner']->getKey(), 'reviewed_by' => $fixture['owner']->getKey(), 'reviewed_at' => $now,
                'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } finally {
            app(TenantContext::class)->clear();
        }

        return [$session, (int) $questionVersionId];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string>|string  $permissions
     */
    private function grantExamPermission(array $fixture, User $user, string|array $permissions): void
    {
        app(TenantContext::class)->enterProvisioning($fixture['tenant']);
        try {
            $newMembership = TenantMembership::query()->create(['institution_id' => $fixture['institution']->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $label = implode('.', (array) $permissions);
            $role = Role::query()->create(['key' => 'test.'.Str::slug($label).'.'.Str::random(6), 'name' => 'Test '.$label, 'scope' => 'tenant', 'tenant_id' => $fixture['tenant']->getKey(), 'is_system' => false]);
            foreach (array_unique(['academic.view', 'exams.view', ...((array) $permissions)]) as $key) {
                $permissionId = DB::table('permissions')->where('key', $key)->value('id');
                self::assertNotNull($permissionId, 'Expected permission '.$key.' is seeded.');
                DB::table('permission_role')->insert(['role_id' => $role->getKey(), 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $newMembership->roles()->attach($role->getKey(), ['assigned_by' => $fixture['owner']->getKey(), 'tenant_id' => $fixture['tenant']->getKey()]);
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
