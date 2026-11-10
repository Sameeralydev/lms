<?php

namespace Tests\Feature;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Grading\GradeCalculationEngine;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class Phase14GradebookFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_weighted_grade_calculation_is_deterministic_and_requires_complete_entries(): void
    {
        $engine = new GradeCalculationEngine;
        $result = $engine->calculate([
            ['weight_basis_points' => 4000, 'items' => [['earned_points' => '18.50', 'maximum_points' => '20.00']]],
            ['weight_basis_points' => 6000, 'items' => [['earned_points' => '42.00', 'maximum_points' => '50.00']]],
        ]);
        self::assertSame(['status' => 'complete', 'percentage' => '87.40', 'percentage_basis_points' => 8740], $result);

        self::assertSame('incomplete', $engine->calculate([
            ['weight_basis_points' => 10000, 'items' => [['earned_points' => null, 'maximum_points' => '100']]],
        ])['status']);
        self::assertSame('80.00', $engine->calculate([
            ['weight_basis_points' => 10000, 'items' => [['earned_points' => '80', 'maximum_points' => '100'], ['earned_points' => null, 'maximum_points' => '10', 'excluded' => true]]],
        ])['percentage']);
        $roundingBoundary = [['weight_basis_points' => 10000, 'items' => [['earned_points' => '1.00', 'maximum_points' => '20000.00']]]];
        self::assertSame(1, $engine->calculate($roundingBoundary, 'half_up')['percentage_basis_points']);
        self::assertSame(0, $engine->calculate($roundingBoundary, 'half_even')['percentage_basis_points']);
        self::assertSame(0, $engine->calculate($roundingBoundary, 'down')['percentage_basis_points']);
        $this->expectException(InvalidArgumentException::class);
        $engine->calculate([
            ['weight_basis_points' => 7000, 'items' => [['earned_points' => '1', 'maximum_points' => '1']]],
            ['weight_basis_points' => 2000, 'items' => [['earned_points' => '1', 'maximum_points' => '1']]],
        ]);
    }

    public function test_assessment_synchronization_is_authorized_and_rejects_an_unverified_source_without_mutation(): void
    {
        [$tenant, $owner, , $student, $period, $section] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/grades";
        $policyData = [
            'name' => 'Source sync policy', 'policy_key' => 'SOURCE-SYNC', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'percentage', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'coursework', 'name' => 'Coursework', 'weight_basis_points' => 10000, 'required' => true]],
        ];
        $this->actingAs($owner)->post("{$base}/policies", $policyData)->assertRedirect();
        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('tenant_id', $tenant->getKey())->where('policy_id', $policyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => 'ASSIGNMENT-1', 'title' => 'Released assignment', 'category_id' => $categoryId,
            'maximum_points' => '100', 'source_type' => 'assignment', 'source_key' => '999999999',
        ])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('tenant_id', $tenant->getKey())->where('gradebook_id', $gradebookId)->value('id');
        $url = "{$base}/gradebooks/{$gradebookId}/items/{$itemId}/synchronize";
        $this->actingAs($student)->post($url)->assertForbidden();
        $this->actingAs($owner)->post($url)->assertNotFound();
        $this->assertDatabaseCount('academic_grade_source_imports', 0);
        $this->assertDatabaseCount('academic_grade_entries', 0);
    }

    public function test_released_assignment_score_sync_is_idempotent_and_preserves_source_amendments(): void
    {
        [$tenant, $owner, , $studentUser, $period, $section] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/grades";
        $student = DB::table('sis_students')->where('user_id', $studentUser->getKey())->firstOrFail();
        $membershipId = (int) DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->value('id');
        $assignmentId = DB::table('assignments')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $student->institution_id, 'offering_id' => $section->offering_id,
            'created_by' => $owner->getKey(), 'title' => 'Released source assignment', 'assignment_type' => 'coursework',
            'collaboration_mode' => 'individual', 'maximum_attempts' => 1, 'maximum_points' => '20.00',
            'timezone' => 'UTC', 'status' => 'published', 'review_required' => false, 'current_version' => 1,
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('assignment_versions')->insert([
            'tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'version_number' => 1,
            'snapshot' => json_encode(['title' => 'Released source assignment'], JSON_THROW_ON_ERROR), 'status' => 'published',
            'created_by' => $owner->getKey(), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $recipientId = DB::table('assignment_recipients')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'membership_id' => $membershipId,
            'student_id' => $student->id, 'section_id' => $section->getKey(), 'eligible_from' => now(),
            'status' => 'eligible', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $submissionId = DB::table('assignment_submissions')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'recipient_id' => $recipientId,
            'status' => 'submitted', 'attempt_count' => 1, 'lock_version' => 1, 'updated_by' => $studentUser->getKey(),
            'submitted_at' => now(), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('assignment_attempts')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'submission_id' => $submissionId, 'attempt_number' => 1, 'status' => 'submitted',
            'receipt_reference' => 'PH14-'.Str::upper(Str::random(12)), 'is_late' => false, 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gradeId = DB::table('assignment_grades')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'attempt_id' => $attemptId, 'score_points' => '15.00', 'maximum_points' => '20.00',
            'status' => 'released', 'marked_by' => $owner->getKey(), 'released_by' => $owner->getKey(),
            'released_at' => now(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $policyData = [
            'name' => 'Assignment sync policy', 'policy_key' => 'ASSIGN-SYNC', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'percentage', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'coursework', 'name' => 'Coursework', 'weight_basis_points' => 10000, 'required' => true]],
        ];
        $this->actingAs($owner)->post("{$base}/policies", $policyData)->assertRedirect();
        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('tenant_id', $tenant->getKey())->where('policy_id', $policyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => 'ASSIGNMENT-1', 'title' => 'Released assignment', 'category_id' => $categoryId,
            'maximum_points' => '100.00', 'source_type' => 'assignment', 'source_key' => (string) $assignmentId,
        ])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('tenant_id', $tenant->getKey())->where('gradebook_id', $gradebookId)->value('id');
        $syncUrl = "{$base}/gradebooks/{$gradebookId}/items/{$itemId}/synchronize";
        $this->actingAs($owner)->post($syncUrl)->assertRedirect();
        $entryId = (int) DB::table('academic_grade_entries')->where('tenant_id', $tenant->getKey())->where('item_id', $itemId)->where('enrollment_id', DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->value('id'))->value('id');
        $this->assertDatabaseHas('academic_grade_entries', ['id' => $entryId, 'earned_points' => '75.00', 'lock_version' => 1]);
        $this->actingAs($owner)->post($syncUrl)->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, '0 new, 0 amended, 1 unchanged'));
        $this->assertDatabaseCount('academic_grade_source_import_revisions', 1);
        DB::table('assignment_grades')->where('id', $gradeId)->update(['score_points' => '17.00', 'lock_version' => 2, 'updated_at' => now()]);
        $this->actingAs($owner)->post($syncUrl)->assertRedirect();
        $this->assertDatabaseHas('academic_grade_entries', ['id' => $entryId, 'earned_points' => '85.00', 'lock_version' => 2]);
        $this->assertDatabaseCount('academic_grade_source_import_revisions', 2);
        $this->assertDatabaseHas('academic_grade_entry_revisions', ['entry_id' => $entryId, 'revision_number' => 2, 'previous_points' => '75.00', 'new_points' => '85.00']);
        $this->assertDatabaseHas('academic_grade_source_imports', ['tenant_id' => $tenant->getKey(), 'item_id' => $itemId, 'enrollment_id' => DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->value('id'), 'source_record_id' => $attemptId, 'source_version' => '2']);
        $enrollmentId = (int) DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", ['earned_points' => '84.00', 'entry_status' => 'graded', 'expected_lock_version' => 2, 'reason' => 'Manual review is preserved.'])->assertRedirect();
        $this->actingAs($owner)->post($syncUrl)->assertStatus(409);
        $this->assertDatabaseHas('academic_grade_entries', ['id' => $entryId, 'earned_points' => '84.00', 'lock_version' => 3]);
    }

    public function test_approved_released_quiz_result_synchronizes_to_the_matching_section_enrollment(): void
    {
        [$tenant, $owner, , $studentUser, $period, $section, $enrollmentId] = $this->fixture();
        $student = DB::table('sis_students')->where('user_id', $studentUser->getKey())->firstOrFail();
        $membershipId = (int) DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->value('id');
        $quizId = DB::table('quizzes')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $student->institution_id, 'offering_id' => $section->offering_id,
            'created_by' => $owner->getKey(), 'status' => 'published', 'current_version' => 1,
            'reviewed_by' => $owner->getKey(), 'reviewed_at' => now(), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('quiz_sections')->insert(['tenant_id' => $tenant->getKey(), 'quiz_id' => $quizId, 'section_id' => $section->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('quiz_versions')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'quiz_id' => $quizId, 'version_number' => 1, 'title' => 'Released quiz',
            'category' => 'formative', 'mode' => 'graded', 'description' => null, 'instructions' => 'Approved quiz.',
            'settings' => json_encode([], JSON_THROW_ON_ERROR), 'total_points' => '20.00', 'status' => 'published',
            'created_by' => $owner->getKey(), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('quiz_attempts')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'quiz_id' => $quizId, 'quiz_version_id' => $versionId, 'student_id' => $student->id,
            'membership_id' => $membershipId, 'attempt_number' => 1, 'status' => 'graded', 'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30), 'graded_at' => now()->subMinutes(15), 'result_released_at' => now(),
            'question_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'lock_version' => 2, 'receipt_reference' => 'PH14Q-'.Str::upper(Str::random(10)),
            'score_points' => '18.00', 'maximum_points' => '20.00', 'percentage' => '90.00', 'manual_review_pending' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$gradebookId, $itemId, $syncUrl] = $this->createSourceGradebook($tenant, $owner, $period, $section, 'quiz', (string) $quizId, 'QUIZ-1');

        $this->actingAs($owner)->post($syncUrl)->assertRedirect();

        $this->assertDatabaseHas('academic_grade_entries', ['tenant_id' => $tenant->getKey(), 'gradebook_id' => $gradebookId, 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'earned_points' => '90.00']);
        $this->assertDatabaseHas('academic_grade_source_imports', ['tenant_id' => $tenant->getKey(), 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'source_type' => 'quiz', 'source_record_id' => $attemptId, 'source_version' => '2']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.grade_source.imported']);
        $this->actingAs($owner)->post($syncUrl)->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, '0 new, 0 amended, 1 unchanged'));
        $this->assertDatabaseCount('academic_grade_source_import_revisions', 1);
    }

    public function test_approved_released_examination_result_synchronizes_only_to_its_matching_academic_course_and_period(): void
    {
        [$tenant, $owner, , $studentUser, $period, $section, $enrollmentId] = $this->fixture();
        $student = DB::table('sis_students')->where('user_id', $studentUser->getKey())->firstOrFail();
        $offering = DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->where('id', $section->offering_id)->firstOrFail();
        $campusId = DB::table('campuses')->where('tenant_id', $tenant->getKey())->where('institution_id', $student->institution_id)->value('id');
        if ($campusId === null) {
            $campusId = DB::table('campuses')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $student->institution_id,
                'code' => 'EXAM-'.Str::upper(Str::random(6)), 'name' => 'Examination campus',
                'status' => 'active', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $campusId = (int) $campusId;
        $now = now();
        $cycleId = DB::table('exam_cycles')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $student->institution_id, 'campus_id' => $campusId,
            'academic_period_id' => $period->getKey(), 'created_by' => $owner->getKey(), 'code' => 'SYNC-'.Str::upper(Str::random(6)),
            'title' => 'Approved assessment period', 'category' => 'final', 'status' => 'published', 'starts_on' => today(), 'ends_on' => today(),
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sessionId = DB::table('exam_sessions')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'cycle_id' => $cycleId, 'campus_id' => $campusId, 'academic_course_id' => $offering->academic_course_id,
            'created_by' => $owner->getKey(), 'code' => 'COURSE-'.Str::upper(Str::random(6)), 'title' => 'Released examination',
            'mode' => 'onsite', 'status' => 'published', 'starts_at_utc' => $now->subDay(), 'ends_at_utc' => $now->subDay()->addHour(),
            'duration_minutes' => 60, 'capacity' => 30, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $candidateId = DB::table('exam_candidates')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'cycle_id' => $cycleId, 'student_id' => $student->id, 'registered_by' => $owner->getKey(),
            'candidate_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'registered', 'registered_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $resultPolicyId = DB::table('exam_result_policies')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'version' => 1, 'written_weight' => '100.00',
            'pass_percent' => '50.00', 'recheck_window_days' => 7, 'effective_at' => $now,
            'configured_by' => $owner->getKey(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $resultId = DB::table('exam_results')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'candidate_id' => $candidateId, 'policy_id' => $resultPolicyId,
            'version' => 1, 'status' => 'released', 'outcome' => 'pass', 'total_marks' => '72.00', 'maximum_marks' => '80.00',
            'percentage' => '90.00', 'source_snapshot' => json_encode(['total_marks' => '72.00'], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'phase14-released-exam'), 'policy_version' => '1', 'compiled_by' => $owner->getKey(),
            'approved_by' => $owner->getKey(), 'approved_at' => $now, 'released_by' => $owner->getKey(), 'released_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        [$gradebookId, $itemId, $syncUrl] = $this->createSourceGradebook($tenant, $owner, $period, $section, 'examination', (string) $sessionId, 'EXAM-1');

        $this->actingAs($owner)->post($syncUrl)->assertRedirect();

        $this->assertDatabaseHas('academic_grade_entries', ['tenant_id' => $tenant->getKey(), 'gradebook_id' => $gradebookId, 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'earned_points' => '90.00']);
        $this->assertDatabaseHas('academic_grade_source_imports', ['tenant_id' => $tenant->getKey(), 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'source_type' => 'examination', 'source_record_id' => $resultId, 'source_version' => '1']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.grade_source.imported']);
        $this->actingAs($owner)->post($syncUrl)->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, '0 new, 0 amended, 1 unchanged'));
        $this->assertDatabaseCount('academic_grade_source_import_revisions', 1);
        DB::table('exam_results')->where('id', $resultId)->update(['status' => 'approval_pending', 'approved_by' => null, 'approved_at' => null, 'released_by' => null, 'released_at' => null]);
        $this->actingAs($owner)->post($syncUrl)->assertStatus(409);
        $this->assertDatabaseHas('academic_grade_entries', ['tenant_id' => $tenant->getKey(), 'gradebook_id' => $gradebookId, 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'earned_points' => '90.00']);
        $this->assertDatabaseCount('academic_grade_source_import_revisions', 1);
    }

    public function test_institution_letter_scale_is_versioned_and_published_with_the_official_grade(): void
    {
        [$tenant, $owner, $reviewer, $studentUser, $period, $section, $enrollmentId] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/grades";
        $this->actingAs($owner)->post("{$base}/policies", [
            'name' => 'Institution letter scale', 'policy_key' => 'LETTER-2026', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'letter', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'coursework', 'name' => 'Coursework', 'weight_basis_points' => 10000, 'required' => true]],
            'scale_bands' => [
                ['band_key' => 'A', 'label' => 'A', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '4.000'],
                ['band_key' => 'B', 'label' => 'B', 'minimum_percentage_basis_points' => 8000, 'grade_points' => '3.000'],
                ['band_key' => 'F', 'label' => 'F', 'minimum_percentage_basis_points' => 0, 'grade_points' => '0.000'],
            ],
        ])->assertRedirect();

        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->where('policy_key', 'LETTER-2026')->value('id');
        $this->assertDatabaseCount('academic_grade_scale_bands', 3);
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('policy_id', $policyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => 'FINAL', 'title' => 'Final exam', 'category_id' => $categoryId,
            'maximum_points' => '100.00', 'source_type' => 'manual', 'source_key' => null,
        ])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('gradebook_id', $gradebookId)->value('id');
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", [
            'earned_points' => '85.00', 'entry_status' => 'graded', 'expected_lock_version' => 0, 'reason' => 'Initial grade.',
        ])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/approve")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publication_entries', [
            'gradebook_id' => $gradebookId, 'enrollment_id' => $enrollmentId,
            'percentage' => '85.00', 'scale_result' => 'B', 'grade_points' => '3.000', 'passed' => null,
        ]);
        $this->actingAs($owner)->post("{$base}/policies", [
            'name' => 'Institution letter scale revised', 'policy_key' => 'LETTER-2026', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'letter', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'coursework', 'name' => 'Coursework', 'weight_basis_points' => 10000, 'required' => true]],
            'scale_bands' => [
                ['band_key' => 'A', 'label' => 'A', 'minimum_percentage_basis_points' => 9200, 'grade_points' => '4.000'],
                ['band_key' => 'F', 'label' => 'F', 'minimum_percentage_basis_points' => 0, 'grade_points' => '0.000'],
            ],
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_policies', ['tenant_id' => $tenant->getKey(), 'policy_key' => 'LETTER-2026', 'version' => 2, 'status' => 'draft']);
        $this->assertDatabaseHas('academic_grade_scale_bands', ['policy_id' => $policyId, 'band_key' => 'A', 'minimum_percentage_basis_points' => 9000]);

        $studentMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->firstOrFail();
        app(TenantContext::class)->set($tenant, $studentMembership);
        $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records/my-grades")
            ->assertOk()->assertInertia(fn ($page) => $page->where('results.0.scale_result', 'B')->where('results.0.grade_points', '3.000'));
    }

    public function test_pass_fail_policy_is_persisted_and_published_as_an_official_result(): void
    {
        [$tenant, $owner, $reviewer, $studentUser, $period, $section, $enrollmentId] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/grades";
        $this->actingAs($owner)->post("{$base}/policies", [
            'name' => 'Institution pass/fail scale', 'policy_key' => 'PASSFAIL-2026', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'pass_fail', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'coursework', 'name' => 'Coursework', 'weight_basis_points' => 10000, 'required' => true]],
            'scale_bands' => [
                ['band_key' => 'PASS', 'label' => 'Pass', 'minimum_percentage_basis_points' => 5000, 'passing' => true],
                ['band_key' => 'FAIL', 'label' => 'Fail', 'minimum_percentage_basis_points' => 0, 'passing' => false],
            ],
        ])->assertRedirect();

        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->where('policy_key', 'PASSFAIL-2026')->value('id');
        $this->assertDatabaseHas('academic_grade_scale_bands', ['policy_id' => $policyId, 'band_key' => 'PASS', 'passing' => true]);
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('policy_id', $policyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => 'FINAL', 'title' => 'Final assessment', 'category_id' => $categoryId,
            'maximum_points' => '100.00', 'source_type' => 'manual', 'source_key' => null,
        ])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('gradebook_id', $gradebookId)->value('id');
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", [
            'earned_points' => '49.99', 'entry_status' => 'graded', 'expected_lock_version' => 0, 'reason' => 'Initial grade.',
        ])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/approve")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publication_entries', [
            'gradebook_id' => $gradebookId, 'enrollment_id' => $enrollmentId,
            'percentage' => '49.99', 'scale_result' => 'Fail', 'grade_points' => null, 'passed' => false,
        ]);

        $studentMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->firstOrFail();
        app(TenantContext::class)->set($tenant, $studentMembership);
        $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records/my-grades")
            ->assertOk()->assertInertia(fn ($page) => $page->where('results.0.scale_result', 'Fail')->where('results.0.passed', false));
    }

    public function test_grading_policy_and_gradebook_are_created_scoped_audited_and_item_creation_is_functional(): void
    {
        [$tenant, $owner, $reviewer, $student, $period, $section, $enrollmentId] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/grades";
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($base)->assertNotFound();
        $this->actingAs($student)->get($base)->assertForbidden();
        $this->assertDatabaseCount('academic_gradebooks', 0);
        $policyData = [
            'name' => 'Term grading policy', 'policy_key' => 'TERM-2026', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'percentage', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'assessment', 'name' => 'Assessment', 'weight_basis_points' => 10000, 'required' => true]],
        ];
        $this->actingAs($owner)->post("{$base}/policies", [...$policyData, 'policy_key' => 'TERM-BAD', 'categories' => [['category_key' => 'assessment', 'name' => 'Assessment', 'weight_basis_points' => 9000, 'required' => true]]])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/policies", [...$policyData, 'policy_key' => 'TERM-PRECISION', 'precision' => 4])->assertSessionHasErrors('precision');
        $invalidPolicyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/policies/{$invalidPolicyId}/activate")->assertSessionHasErrors('categories');
        $this->actingAs($owner)->post("{$base}/policies", $policyData)->assertRedirect();
        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->where('policy_key', 'TERM-2026')->value('id');
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->assertDatabaseHas('academic_grade_policies', ['id' => $policyId, 'status' => 'active']);

        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => 'FINAL', 'title' => 'Final assessment', 'category_id' => DB::table('academic_grade_categories')->where('policy_id', $policyId)->value('id'),
            'maximum_points' => '100.00', 'source_type' => 'manual', 'source_key' => null,
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_gradebook_items', ['tenant_id' => $tenant->getKey(), 'gradebook_id' => $gradebookId, 'item_key' => 'FINAL']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.gradebook_item.created']);
        $this->actingAs($owner)->get("{$base}/gradebooks/{$gradebookId}")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/grades/show')->where('gradebook.status', 'draft')->has('gradebook.items', 1));

        $itemId = (int) DB::table('academic_gradebook_items')->where('gradebook_id', $gradebookId)->value('id');
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", ['earned_points' => '89.50', 'entry_status' => 'graded', 'expected_lock_version' => 0, 'feedback' => 'Strong work.', 'reason' => 'Initial mark.'])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_entry_revisions', ['tenant_id' => $tenant->getKey(), 'revision_number' => 1, 'new_points' => '89.50']);
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", ['earned_points' => '91.00', 'entry_status' => 'graded', 'expected_lock_version' => 0])->assertStatus(409);
        $this->assertDatabaseHas('academic_grade_entries', ['tenant_id' => $tenant->getKey(), 'item_id' => $itemId, 'enrollment_id' => $enrollmentId, 'earned_points' => '89.50', 'lock_version' => 1]);
        $entryId = (int) DB::table('academic_grade_entries')->where('tenant_id', $tenant->getKey())->where('item_id', $itemId)->where('enrollment_id', $enrollmentId)->value('id');
        $overrideUrl = "{$base}/gradebooks/{$gradebookId}/entries/{$entryId}/overrides";
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '93.00', 'reason' => 'Verified rubric total was omitted.'])->assertRedirect();
        $overrideId = (int) DB::table('academic_grade_override_requests')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '94.00', 'reason' => 'Duplicate request attempt.'])->assertStatus(409);
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", ['earned_points' => '90.00', 'entry_status' => 'graded', 'expected_lock_version' => 1, 'reason' => 'A separately verified rubric correction.'])->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'The entry has changed since review.'])->assertStatus(409);
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'reject', 'decision_reason' => 'The source grade changed during review.'])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_override_requests', ['id' => $overrideId, 'status' => 'rejected', 'decided_by' => $reviewer->getKey()]);
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '93.00', 'reason' => 'Verified rubric total was omitted.'])->assertRedirect();
        $overrideId = (int) DB::table('academic_grade_override_requests')->where('tenant_id', $tenant->getKey())->where('status', 'pending')->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/submit")->assertSessionHasErrors('gradebook');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'Requester cannot self approve.'])->assertForbidden();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'Rubric total verified.'])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_override_requests', ['id' => $overrideId, 'status' => 'approved', 'requested_by' => $owner->getKey(), 'decided_by' => $reviewer->getKey()]);
        $this->assertDatabaseHas('academic_grade_entries', ['id' => $entryId, 'earned_points' => '93.00', 'lock_version' => 3]);
        $this->assertDatabaseHas('academic_grade_entry_revisions', ['entry_id' => $entryId, 'revision_number' => 3, 'previous_points' => '90.00', 'new_points' => '93.00', 'actor_id' => $reviewer->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.grade_override.approved', 'subject_id' => (string) $overrideId]);
        $this->actingAs($student)->get("/t/{$tenant->slug}/academic-records/my-grades")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/grades/my-grades')->has('entries', 0)->has('results', 0));
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/submit")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/approve")->assertForbidden();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/approve")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publication_entries', ['gradebook_id' => $gradebookId, 'enrollment_id' => $enrollmentId, 'percentage' => '93.00']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.grade_publication.created']);
        $this->actingAs($student)->get("/t/{$tenant->slug}/academic-records/my-grades")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/grades/my-grades')->has('entries', 1)->where('entries.0.earned_points', '93.00')->has('results', 1)->where('results.0.percentage', '93.00'));
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '95.00', 'reason' => 'Approved published correction after source review.'])->assertRedirect();
        $publishedOverrideId = (int) DB::table('academic_grade_override_requests')->where('tenant_id', $tenant->getKey())->where('status', 'pending')->value('id');
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$publishedOverrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'Source record and rubric are verified.'])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publications', ['gradebook_id' => $gradebookId, 'publication_number' => 2, 'published_by' => $reviewer->getKey()]);
        $this->assertDatabaseHas('academic_grade_publication_entries', ['gradebook_id' => $gradebookId, 'publication_id' => DB::table('academic_grade_publications')->where('gradebook_id', $gradebookId)->where('publication_number', 1)->value('id'), 'enrollment_id' => $enrollmentId, 'percentage' => '93.00']);
        $this->assertDatabaseHas('academic_grade_publication_entries', ['gradebook_id' => $gradebookId, 'publication_id' => DB::table('academic_grade_publications')->where('gradebook_id', $gradebookId)->where('publication_number', 2)->value('id'), 'enrollment_id' => $enrollmentId, 'percentage' => '95.00']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.grade_publication.corrected']);
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/publish")->assertStatus(409);
        $this->actingAs($student)->get("/t/{$tenant->slug}/academic-records/my-grades")->assertOk()->assertInertia(fn ($page) => $page->has('results', 1)->where('results.0.percentage', '95.00')->where('results.0.publication_number', 2));

        $guardian = User::factory()->create();
        $institutionId = (int) DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id');
        $guardianMembershipId = DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institutionId, 'user_id' => $guardian->getKey(),
            'status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $guardianRoleId = (int) DB::table('roles')->where('tenant_id', $tenant->getKey())->where('key', 'parent.guardian')->value('id');
        DB::table('membership_role')->insert(['tenant_id' => $tenant->getKey(), 'membership_id' => $guardianMembershipId, 'role_id' => $guardianRoleId, 'assigned_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $guardianLinkId = DB::table('sis_student_guardians')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'student_id' => DB::table('sis_students')->where('tenant_id', $tenant->getKey())->where('user_id', $student->getKey())->value('id'),
            'guardian_user_id' => $guardian->getKey(), 'guardian_name' => 'Verified grade guardian', 'relationship' => 'parent',
            'authority_status' => 'verified', 'can_view_profile' => true, 'verified_at' => now(), 'verified_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $guardianGradesUrl = "/t/{$tenant->slug}/academic-records/guardian-grades";
        $this->actingAs($guardian)->get($guardianGradesUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/grades/guardian-grades')->has('results', 1)
            ->where('results.0.grade', '95.00%')->where('results.0.publication_number', 2));
        $strangerGuardian = User::factory()->create();
        $this->actingAs($strangerGuardian)->get($guardianGradesUrl)->assertNotFound();
        $foreignCampusId = DB::table('campuses')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institutionId, 'code' => 'GUARDIAN-'.Str::upper(Str::random(5)),
            'name' => 'Out-of-scope campus', 'status' => 'active', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenant_memberships')->where('id', $guardianMembershipId)->update(['campus_id' => $foreignCampusId]);
        $this->actingAs($guardian)->get($guardianGradesUrl)->assertNotFound();
        DB::table('tenant_memberships')->where('id', $guardianMembershipId)->update(['campus_id' => null]);
        DB::table('sis_student_guardians')->where('id', $guardianLinkId)->update(['authority_status' => 'revoked', 'revoked_at' => now(), 'can_view_profile' => false]);
        $this->actingAs($guardian)->get($guardianGradesUrl)->assertOk()->assertInertia(fn ($page) => $page->has('results', 0));

        $otherTenantOwner = User::factory()->create();
        $otherTenant = app(TenantProvisioner::class)->provision([
            'slug' => 'phase14-other-'.Str::lower(Str::random(8)), 'official_name' => 'Other Institute', 'display_name' => 'Other Institute',
            'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], User::factory()->create(), $otherTenantOwner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($otherTenant);
        try {
            $otherStudentMembership = TenantMembership::query()->create(['institution_id' => Institution::query()->value('id'), 'user_id' => $student->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $otherStudentRole = Role::query()->where('tenant_id', $otherTenant->getKey())->where('key', 'student')->firstOrFail();
            $otherStudentMembership->roles()->attach($otherStudentRole->getKey(), ['assigned_by' => $otherTenantOwner->getKey(), 'tenant_id' => $otherTenant->getKey()]);
        } finally {
            $context->clear();
        }
        $this->actingAs($student)->get("/t/{$otherTenant->slug}/academic-records/my-grades")->assertNotFound();
    }

    public function test_gpa_snapshots_use_only_published_points_confirmed_credits_and_preserve_recalculation_history(): void
    {
        [$tenant, $owner, $reviewer, $studentUser, $period, $section, $enrollmentId] = $this->fixture();
        DB::table('course_enrollments')->where('id', $enrollmentId)->update(['credits_snapshot' => '3.000']);
        $base = "/t/{$tenant->slug}/academics/grades";
        $this->actingAs($owner)->post("{$base}/policies", [
            'name' => 'Letter scale for GPA', 'policy_key' => 'GPA-LETTER', 'academic_period_id' => $period->getKey(),
            'scale_type' => 'letter', 'rounding_mode' => 'half_up', 'precision' => 2, 'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'assessment', 'name' => 'Assessment', 'weight_basis_points' => 10000, 'required' => true]],
            'scale_bands' => [
                ['band_key' => 'A', 'label' => 'A', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '4.000'],
                ['band_key' => 'B', 'label' => 'B', 'minimum_percentage_basis_points' => 8000, 'grade_points' => '3.000'],
                ['band_key' => 'C', 'label' => 'C', 'minimum_percentage_basis_points' => 7000, 'grade_points' => '2.000'],
                ['band_key' => 'F', 'label' => 'F', 'minimum_percentage_basis_points' => 0, 'grade_points' => '0.000'],
            ],
        ])->assertRedirect();
        $gradePolicyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->where('policy_key', 'GPA-LETTER')->value('id');
        $this->assertDatabaseHas('academic_grade_scale_bands', ['tenant_id' => $tenant->getKey(), 'policy_id' => $gradePolicyId, 'band_key' => 'C', 'minimum_percentage_basis_points' => 7000, 'grade_points' => '2.000']);
        $this->actingAs($owner)->post("{$base}/policies/{$gradePolicyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $gradePolicyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('policy_id', $gradePolicyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", ['item_key' => 'FINAL', 'title' => 'Final exam', 'category_id' => $categoryId, 'maximum_points' => '100', 'source_type' => 'manual'])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('gradebook_id', $gradebookId)->value('id');
        $this->actingAs($owner)->put("{$base}/gradebooks/{$gradebookId}/items/{$itemId}/enrollments/{$enrollmentId}", ['earned_points' => '95', 'entry_status' => 'graded', 'expected_lock_version' => 0, 'reason' => 'Assessment finalized.'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/transitions/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/approve")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/transitions/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publication_entries', ['gradebook_id' => $gradebookId, 'enrollment_id' => $enrollmentId, 'grade_points' => '4.000']);

        $gpaBase = "/t/{$tenant->slug}/academics/gpa";
        $this->actingAs($owner)->post("{$gpaBase}/policies", ['name' => 'Institution GPA', 'policy_key' => 'GPA-1', 'repeat_rule' => 'highest', 'exclude_pass_fail' => true, 'bands' => [
            ['band_key' => 'good', 'label' => 'Good standing', 'minimum_gpa' => '2.000'], ['band_key' => 'probation', 'label' => 'Probation', 'minimum_gpa' => '0.000'],
        ]])->assertRedirect();
        $gpaPolicyId = (int) DB::table('academic_gpa_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$gpaBase}/policies/{$gpaPolicyId}/activate")->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($studentUser)->post("{$gpaBase}/snapshots", ['student_id' => $studentId, 'policy_id' => $gpaPolicyId])->assertForbidden();
        $this->actingAs($owner)->post("{$gpaBase}/snapshots", ['student_id' => $studentId, 'policy_id' => $gpaPolicyId])->assertRedirect();
        $snapshot = DB::table('academic_gpa_snapshots')->where('tenant_id', $tenant->getKey())->first();
        self::assertEqualsWithDelta(4.0, (float) $snapshot->gpa, 0.0001);
        self::assertEqualsWithDelta(12.0, (float) $snapshot->quality_points, 0.0001);
        self::assertEqualsWithDelta(3.0, (float) $snapshot->attempted_credits, 0.0001);
        self::assertSame('Good standing', $snapshot->academic_standing);
        $this->actingAs($owner)->post("{$gpaBase}/snapshots", ['student_id' => $studentId, 'policy_id' => $gpaPolicyId])->assertRedirect();
        $this->assertDatabaseCount('academic_gpa_snapshots', 1);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.gpa_snapshot.calculated']);
        $entryId = (int) DB::table('academic_grade_entries')->where('tenant_id', $tenant->getKey())->where('gradebook_id', $gradebookId)->value('id');
        $overrideUrl = "{$base}/gradebooks/{$gradebookId}/entries/{$entryId}/overrides";
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '100.00', 'reason' => 'The verified rubric total was corrected.'])->assertRedirect();
        $overrideId = (int) DB::table('academic_grade_override_requests')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'Source rubric correction verified.'])->assertRedirect();
        $this->assertDatabaseHas('academic_grade_publication_entries', ['gradebook_id' => $gradebookId, 'enrollment_id' => $enrollmentId, 'publication_id' => DB::table('academic_grade_publications')->where('gradebook_id', $gradebookId)->max('id'), 'percentage' => '100.00', 'scale_result' => 'A', 'grade_points' => '4.000']);
        $this->actingAs($owner)->post("{$gpaBase}/snapshots", ['student_id' => $studentId, 'policy_id' => $gpaPolicyId])->assertRedirect();
        $latest = DB::table('academic_gpa_snapshots')->where('tenant_id', $tenant->getKey())->orderByDesc('snapshot_number')->first();
        self::assertSame(2, (int) $latest->snapshot_number);
        self::assertSame((int) $snapshot->id, (int) $latest->supersedes_id);
        self::assertEqualsWithDelta(4.0, (float) $latest->gpa, 0.0001);
        $this->actingAs($owner)->post("{$gpaBase}/snapshots", ['student_id' => $studentId, 'policy_id' => $gpaPolicyId, 'academic_period_id' => $period->getKey()])->assertRedirect();
        $this->assertDatabaseHas('academic_gpa_snapshots', ['tenant_id' => $tenant->getKey(), 'calculation_type' => 'term', 'academic_period_id' => $period->getKey()]);
        $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records/my-progress")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/gpa/my-progress')->has('snapshots', 3)->where('snapshots.0.academic_standing', 'Good standing'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/gpa")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/gpa/index')->has('students', 1)->has('policies', 1));

        $recordsBase = "/t/{$tenant->slug}/academics/records";
        $createTemplate = function (string $type, string $key) use ($owner, $recordsBase): int {
            $this->actingAs($owner)->post("{$recordsBase}/templates", ['name' => ucfirst($type).' template', 'template_key' => $key, 'record_type' => $type])->assertRedirect();
            $id = (int) DB::table('academic_record_templates')->where('template_key', $key)->value('id');
            $this->actingAs($owner)->post("{$recordsBase}/templates/{$id}/activate")->assertRedirect();

            return $id;
        };
        $createIssue = function (int $templateId, ?int $academicPeriodId = null) use ($owner, $recordsBase, $studentId): int {
            $this->actingAs($owner)->post("{$recordsBase}/issues", ['student_id' => $studentId, 'template_id' => $templateId, 'academic_period_id' => $academicPeriodId])->assertRedirect();

            return (int) DB::table('academic_record_issues')->where('student_id', $studentId)->orderByDesc('id')->value('id');
        };
        $transcriptTemplateId = $createTemplate('transcript', 'TRANSCRIPT-1');
        $transcriptId = $createIssue($transcriptTemplateId);
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$transcriptId}/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$recordsBase}/issues/{$transcriptId}/decision", ['decision' => 'approve', 'reason' => 'Published academic history verified.'])->assertRedirect();
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$transcriptId}/issue")->assertRedirect();
        $download = $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records/{$transcriptId}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-1.4', $download->getContent());
        $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/records/my-records')->has('records', 1));
        $guardian = User::factory()->create();
        $guardianMembershipId = DB::table('tenant_memberships')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id'), 'user_id' => $guardian->getKey(), 'status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $guardianRoleId = (int) DB::table('roles')->where('tenant_id', $tenant->getKey())->where('key', 'parent.guardian')->value('id');
        DB::table('membership_role')->insert(['tenant_id' => $tenant->getKey(), 'membership_id' => $guardianMembershipId, 'role_id' => $guardianRoleId, 'assigned_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $guardianLinkId = DB::table('sis_student_guardians')->insertGetId(['tenant_id' => $tenant->getKey(), 'student_id' => $studentId, 'guardian_user_id' => $guardian->getKey(), 'guardian_name' => 'Verified guardian', 'relationship' => 'parent', 'authority_status' => 'verified', 'can_view_profile' => true, 'verified_at' => now(), 'verified_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($guardian)->get("/t/{$tenant->slug}/academic-records")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/records/my-records')->has('records', 1));
        $this->actingAs($guardian)->get("/t/{$tenant->slug}/academic-records/{$transcriptId}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        DB::table('sis_student_guardians')->where('id', $guardianLinkId)->update(['revoked_at' => now()]);
        $this->actingAs($guardian)->get("/t/{$tenant->slug}/academic-records")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/records/my-records')->has('records', 0));
        $this->actingAs($guardian)->get("/t/{$tenant->slug}/academic-records/{$transcriptId}/download")->assertNotFound();
        $otherCampusId = DB::table('campuses')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id'),
            'code' => 'RECORDS-'.Str::upper(Str::random(5)), 'name' => 'Separate records campus', 'status' => 'active',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $reviewer->getKey())->update(['campus_id' => $otherCampusId]);
        $this->actingAs($reviewer)->get("/t/{$tenant->slug}/academic-records/{$transcriptId}/download")->assertNotFound();
        DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $reviewer->getKey())->update(['campus_id' => null]);
        $reportTemplateId = $createTemplate('report_card', 'REPORT-CARD-1');
        $teacher = User::factory()->create();
        $teacherMembershipId = DB::table('tenant_memberships')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id'), 'user_id' => $teacher->getKey(), 'status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $teacherRoleId = (int) DB::table('roles')->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->value('id');
        DB::table('membership_role')->insert(['tenant_id' => $tenant->getKey(), 'membership_id' => $teacherMembershipId, 'role_id' => $teacherRoleId, 'assigned_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        $commentsBase = "/t/{$tenant->slug}/academics/report-card-comments";
        $this->actingAs($teacher)->get($commentsBase)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/records/teacher-comments')->has('assignments', 1));
        $this->actingAs($teacher)->post($commentsBase, ['student_id' => $studentId, 'academic_period_id' => $period->getKey(), 'section_id' => $section->getKey(), 'comment' => 'The learner demonstrates steady progress in assessed work.'])->assertRedirect();
        $commentId = (int) DB::table('academic_report_card_comments')->value('id');
        $this->actingAs($teacher)->post("{$commentsBase}/{$commentId}/decision", ['decision' => 'approve', 'reason' => 'Self-review must not be permitted.'])->assertForbidden();
        $this->actingAs($reviewer)->post("{$commentsBase}/{$commentId}/decision", ['decision' => 'approve', 'reason' => 'Comment reviewed for accuracy and appropriateness.'])->assertRedirect();
        $this->assertDatabaseHas('academic_report_card_comments', ['id' => $commentId, 'status' => 'approved', 'reviewed_by' => $reviewer->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.report_card_comment.approved']);
        $reportCardId = $createIssue($reportTemplateId, (int) $period->getKey());
        self::assertSame('The learner demonstrates steady progress in assessed work.', DB::table('academic_record_issues')->where('id', $reportCardId)->value('record_snapshot->teacher_comment[0]->comment'));
        $this->assertDatabaseHas('academic_record_issues', ['id' => $reportCardId, 'record_type' => 'report_card', 'academic_period_id' => $period->getKey(), 'status' => 'draft']);
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$reportCardId}/submit")->assertRedirect();
        $entryId = (int) DB::table('academic_grade_entries')->where('tenant_id', $tenant->getKey())->where('gradebook_id', $gradebookId)->value('id');
        $overrideUrl = "{$base}/gradebooks/{$gradebookId}/entries/{$entryId}/overrides";
        $this->actingAs($owner)->post($overrideUrl, ['proposed_points' => '75.00', 'reason' => 'Verified late rubric correction.'])->assertRedirect();
        $overrideId = (int) DB::table('academic_grade_override_requests')->where('tenant_id', $tenant->getKey())->where('status', 'pending')->value('id');
        $this->actingAs($reviewer)->post("{$base}/gradebooks/{$gradebookId}/overrides/{$overrideId}/decision", ['decision' => 'approve', 'decision_reason' => 'Source rubric correction verified.'])->assertRedirect();
        $this->actingAs($reviewer)->post("{$recordsBase}/issues/{$reportCardId}/decision", ['decision' => 'approve', 'reason' => 'Report card must be re-prepared after correction.'])->assertStatus(409);
        $this->assertDatabaseHas('academic_record_issues', ['id' => $reportCardId, 'status' => 'submitted']);
        $reportsUrl = "/t/{$tenant->slug}/academics/reports";
        $this->actingAs($owner)->get($reportsUrl)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/reporting/index')->where('metrics.record_approvals_pending', 1)->has('metrics.published_results')->has('metrics.gradebook_completion')->has('metrics.grade_distribution'));
        DB::table('sis_students')->where('id', $studentId)->update(['student_number' => '=1+1']);
        $csvResponse = $this->actingAs($owner)->get($reportsUrl.'/records.csv?record_type=report_card&status=submitted')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('private', (string) $csvResponse->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $csvResponse->headers->get('Cache-Control'));
        self::assertStringContainsString("'=1+1", $csvResponse->streamedContent());
        $this->actingAs($owner)->get($reportsUrl.'?status=invalid')->assertSessionHasErrors('status');
        $certificateTemplateId = $createTemplate('certificate', 'CERTIFICATE-1');
        $certificateId = $createIssue($certificateTemplateId);
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$certificateId}/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$recordsBase}/issues/{$certificateId}/decision", ['decision' => 'approve', 'reason' => 'Active enrollment verified.'])->assertRedirect();
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$certificateId}/issue")->assertRedirect();
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.record.issued', 'safe_payload->record_issue_id' => $certificateId]);
        $studentMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->firstOrFail();
        $tenantContext = app(TenantContext::class);
        $tenantContext->set($tenant, $studentMembership);
        try {
            $delivery = app(QuizOutboxProcessor::class)->dispatchAvailable(20);
            $notificationCount = DB::table('quiz_notifications')->where('tenant_id', $tenant->getKey())->count();
            $retryDelivery = app(QuizOutboxProcessor::class)->dispatchAvailable(20);
        } finally {
            $tenantContext->clear();
        }
        self::assertSame(18, $delivery['delivered']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $reviewer->getKey(), 'event_type' => 'academic.grade_override.requested']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $owner->getKey(), 'event_type' => 'academic.grade_override.decided']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $reviewer->getKey(), 'event_type' => 'academic.record.submitted']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $reviewer->getKey(), 'event_type' => 'academic.report_card_comment.submitted']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $teacher->getKey(), 'event_type' => 'academic.report_card_comment.decided']);
        self::assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $owner->getKey(), 'event_type' => 'academic.record.decided']);
        self::assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.record.submitted', 'status' => 'delivered']);
        self::assertSame(0, $retryDelivery['claimed']);
        self::assertSame($notificationCount, DB::table('quiz_notifications')->where('tenant_id', $tenant->getKey())->count());
        self::assertSame(2, DB::table('quiz_notifications')->where('tenant_id', $tenant->getKey())->where('event_type', 'academic.record.issued')->count());
        self::assertSame(3, DB::table('quiz_notifications')->where('tenant_id', $tenant->getKey())->where('event_type', 'academic.grade.published')->count());
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $studentUser->getKey(), 'event_type' => 'academic.record.issued']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $studentUser->getKey(), 'event_type' => 'academic.grade.published']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $tenant->getKey(), 'user_id' => $guardian->getKey(), 'event_type' => 'academic.record.issued']);
        $verificationCode = (string) DB::table('academic_record_issues')->where('id', $certificateId)->value('verification_code');
        $this->post('/academic-records/verify', ['verification_code' => $verificationCode])->assertOk()->assertInertia(fn ($page) => $page->component('public/academic-records/verify')->where('verification.valid', true)->where('verification.record_type', 'certificate'));
        $certificatePdf = $this->actingAs($studentUser)->get("/t/{$tenant->slug}/academic-records/{$certificateId}/download")->assertOk()->getContent();
        $this->assertStringContainsString(' re f', $certificatePdf);
        $this->get("/academic-records/verify/{$verificationCode}")->assertOk()->assertInertia(fn ($page) => $page->where('verification.valid', true)->where('verification.institution', DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('display_name')));
        $this->actingAs($owner)->post("{$recordsBase}/issues/{$certificateId}/revoke", ['reason' => 'Certificate issued in error; retained for audit.'])->assertRedirect();
        $this->post('/academic-records/verify', ['verification_code' => $verificationCode])->assertOk()->assertInertia(fn ($page) => $page->where('verification.valid', false));
        $this->get("/academic-records/verify/{$verificationCode}")->assertOk()->assertInertia(fn ($page) => $page->where('verification.valid', false));
    }

    /** @return array{int,int,string} */
    private function createSourceGradebook(Tenant $tenant, User $owner, AcademicPeriod $period, CourseSection $section, string $sourceType, string $sourceKey, string $suffix): array
    {
        $base = "/t/{$tenant->slug}/academics/grades";
        $this->actingAs($owner)->post("{$base}/policies", [
            'name' => 'Source policy '.$suffix, 'policy_key' => $suffix.'-'.Str::upper(Str::random(5)), 'academic_period_id' => $period->getKey(),
            'scale_type' => 'percentage', 'rounding_mode' => 'half_up', 'precision' => 2,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'categories' => [['category_key' => 'assessment', 'name' => 'Assessment', 'weight_basis_points' => 10000, 'required' => true]],
        ])->assertRedirect();
        $policyId = (int) DB::table('academic_grade_policies')->where('tenant_id', $tenant->getKey())->where('name', 'Source policy '.$suffix)->value('id');
        $this->actingAs($owner)->post("{$base}/policies/{$policyId}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/gradebooks", ['section_id' => $section->getKey(), 'policy_id' => $policyId])->assertRedirect();
        $gradebookId = (int) DB::table('academic_gradebooks')->where('tenant_id', $tenant->getKey())->value('id');
        $categoryId = (int) DB::table('academic_grade_categories')->where('tenant_id', $tenant->getKey())->where('policy_id', $policyId)->value('id');
        $this->actingAs($owner)->post("{$base}/gradebooks/{$gradebookId}/items", [
            'item_key' => $suffix, 'title' => 'Released '.$sourceType, 'category_id' => $categoryId,
            'maximum_points' => '100.00', 'source_type' => $sourceType, 'source_key' => $sourceKey,
        ])->assertRedirect();
        $itemId = (int) DB::table('academic_gradebook_items')->where('tenant_id', $tenant->getKey())->where('gradebook_id', $gradebookId)->value('id');

        return [$gradebookId, $itemId, "{$base}/gradebooks/{$gradebookId}/items/{$itemId}/synchronize"];
    }

    /** @return array{Tenant,User,User,User,AcademicPeriod,CourseSection,int} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $studentUser = User::factory()->create();
        $creator = User::factory()->create();
        $provisioned = app(TenantProvisioner::class)->provision([
            'slug' => 'phase14-grades-'.Str::lower(Str::random(8)), 'official_name' => 'Phase 14 Test Institute', 'display_name' => 'Phase 14 Test Institute',
            'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $tenant = $provisioned->tenant;
        $this->actingAs($owner)->post(URL::temporarySignedRoute('invitations.accept', $provisioned->ownerInvitation->expires_at, ['token' => $provisioned->ownerInvitationToken]))->assertRedirect();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $ownerMembership = TenantMembership::query()->where('user_id', $owner->getKey())->firstOrFail();
            $reviewerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $reviewerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'academic.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($reviewerRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            $studentMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $studentUser->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $studentMembership->roles()->attach($studentRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            $configuration = AcademicConfiguration::query()->firstOrFail();
            $features = AcademicDefaults::present($configuration)['enabled_features'];
            $features['catalog'] = true;
            $configuration->forceFill(['enabled_features' => $features])->save();
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Academic year', 'code' => 'AY-'.Str::upper(Str::random(6)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Term', 'code' => 'T-'.Str::upper(Str::random(6)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'COURSE-'.Str::upper(Str::random(6)), 'title' => 'Academic Records', 'status' => 'active']);
            $enrollmentPolicy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Registration policy', 'maximum_courses' => 5, 'created_by' => $owner->getKey()]);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $enrollmentPolicy->getKey(), 'offering_code' => 'OFF-'.Str::upper(Str::random(6)), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $owner->getKey()]);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'section_code' => 'A', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
            $student = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $studentUser->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(8)), 'registration_reference' => 'REF-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Grade', 'legal_last_name' => 'Learner', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            $enrollmentId = DB::table('course_enrollments')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'credits_snapshot' => '3.000', 'requested_at' => now(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $reviewer, $studentUser, $period, $section, $enrollmentId];
    }
}
