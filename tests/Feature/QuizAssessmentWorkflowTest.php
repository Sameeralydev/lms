<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Assessments\QuestionDefinition;
use App\Domain\Assessments\QuizAssessmentQueries;
use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Assessments\QuizAutoScorer;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Examinations\ExamDeliveryWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\ExamAttemptBinding;
use App\Models\ExamCandidate;
use App\Models\ExamCycle;
use App\Models\ExamPaper;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class QuizAssessmentWorkflowTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_formal_online_exam_binds_an_approved_paper_to_one_authoritative_attempt(): void
    {
        $fixture = $this->fixture();
        $context = app(TenantContext::class);
        $quizzes = app(QuizAssessmentWorkflow::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $quizzes->createBank(['code' => 'FORMAL-EXAM', 'name' => 'Formal exam bank', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $quizzes->createQuestion((int) $bank->getKey(), [
            'question_type' => 'single_choice', 'content' => ['prompt' => 'Which is a planet?', 'options' => [['id' => 'earth', 'text' => 'Earth'], ['id' => 'moon', 'text' => 'Moon']]],
            'answer_key' => ['correct_option_ids' => ['earth']], 'default_points' => '2.00', 'difficulty' => 'moderate', 'estimated_seconds' => 60,
        ], $fixture['teacher']);
        $questionVersion = $question->versions->firstOrFail();
        $quizzes->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $quizzes->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $questionVersion->refresh();
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $quiz = $quizzes->createQuiz([
            'offering_id' => $fixture['offering']->getKey(), 'section_ids' => [$fixture['section']->getKey()],
            'questions' => [['question_version_id' => $questionVersion->getKey(), 'points' => '2.00']],
            'title' => 'Formal Biology Examination', 'instructions' => 'Read each question carefully.', 'mode' => 'graded',
            'maximum_attempts' => 1, 'time_limit_minutes' => 60, 'grading_policy' => 'latest', 'feedback_release' => 'manual',
            'result_visibility' => 'score_only', 'navigation' => 'free',
        ], $fixture['teacher']);
        $quizzes->submitQuizForReview((int) $quiz->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $quizzes->reviewQuiz((int) $quiz->getKey(), $fixture['reviewer'], true);
        $quizzes->publishQuiz((int) $quiz->getKey(), $fixture['reviewer']);

        $institutionId = (int) $fixture['campus']->institution_id;
        $startsAt = now()->addMinutes(10)->startOfMinute();
        $cycle = ExamCycle::query()->create([
            'institution_id' => $institutionId, 'campus_id' => $fixture['campus']->getKey(),
            'academic_period_id' => $fixture['offering']->academic_period_id, 'created_by' => $fixture['reviewer']->getKey(),
            'code' => 'EXAM-'.Str::upper(Str::random(6)), 'title' => 'Biology examination cycle', 'category' => 'final',
            'status' => 'published', 'starts_on' => $startsAt->toDateString(), 'ends_on' => $startsAt->toDateString(), 'published_at' => now(),
        ]);
        $session = ExamSession::query()->create([
            'cycle_id' => $cycle->getKey(), 'campus_id' => $fixture['campus']->getKey(), 'academic_course_id' => $fixture['offering']->academic_course_id,
            'created_by' => $fixture['reviewer']->getKey(), 'code' => 'ONLINE-'.Str::upper(Str::random(5)), 'title' => 'Biology online exam',
            'mode' => 'online', 'status' => 'published', 'starts_at_utc' => $startsAt, 'ends_at_utc' => $startsAt->copy()->addHours(2),
            'duration_minutes' => 60, 'capacity' => 20,
        ]);
        $student = SisStudent::query()->where('user_id', $fixture['student']->getKey())->firstOrFail();
        $candidate = ExamCandidate::query()->create([
            'cycle_id' => $cycle->getKey(), 'student_id' => $student->getKey(), 'registered_by' => $fixture['reviewer']->getKey(),
            'candidate_number' => 'C-'.Str::upper(Str::random(6)), 'status' => 'registered', 'registered_at' => now(),
        ]);
        $paper = ExamPaper::query()->create(['session_id' => $session->getKey(), 'created_by' => $fixture['teacher']->getKey(), 'current_version' => 1, 'current_set_code' => 'A', 'status' => 'published', 'published_at' => now()]);
        $paperQuestions = [[
            'question_version_id' => (int) $questionVersion->getKey(), 'position' => 1, 'points' => '2.00',
            'question_type' => 'single_choice', 'topic' => $questionVersion->topic, 'difficulty' => $questionVersion->difficulty, 'content' => $questionVersion->content,
        ]];
        $blueprint = ['title' => 'Biology Online Exam', 'instructions' => 'Read each question carefully.', 'duration_minutes' => 60, 'question_count' => 1, 'set_code' => 'A'];
        $paperVersion = ExamPaperVersion::query()->create([
            'paper_id' => $paper->getKey(), 'version_number' => 1, 'set_code' => 'A', 'title' => $blueprint['title'], 'instructions' => $blueprint['instructions'],
            'blueprint_snapshot' => $blueprint, 'question_snapshot' => $paperQuestions, 'total_marks' => '2.00',
            'duration_minutes' => 60, 'content_hash' => hash('sha256', json_encode(['blueprint' => $blueprint, 'questions' => $paperQuestions, 'total_marks' => '2.00'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'created_by' => $fixture['teacher']->getKey(), 'status' => 'published', 'published_at' => now(), 'approved_at' => now(),
        ]);

        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        app(ExamDeliveryWorkflow::class)->configure((int) $session->getKey(), (int) $quiz->getKey(), (int) $paperVersion->getKey(), $fixture['reviewer']);
        $this->assertDatabaseHas('exam_session_deliveries', ['tenant_id' => $fixture['tenant']->getKey(), 'session_id' => $session->getKey(), 'paper_version_id' => $paperVersion->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.delivery.configured']);

        $this->actingAs($fixture['student'])->get('/t/'.$fixture['tenant']->slug.'/exams')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/examinations/available')->has('exams', 1)->where('exams.0.canStart', false));
        $this->actingAs($fixture['otherStudent'])->get('/t/'.$fixture['tenant']->slug.'/exams')->assertOk()->assertInertia(fn ($page) => $page->has('exams', 0));
        $this->actingAs($fixture['student'])->post('/t/'.$fixture['tenant']->slug.'/exams/sessions/'.$session->getKey().'/start')->assertStatus(409);
        $this->travelTo($startsAt->copy()->addMinute());
        $startUrl = '/t/'.$fixture['tenant']->slug.'/exams/sessions/'.$session->getKey().'/start';
        $enrollment = CourseEnrollment::query()->where('student_id', $student->getKey())->firstOrFail();
        $enrollment->forceFill(['status' => 'dropped', 'dropped_at' => now()])->save();
        $this->actingAs($fixture['student'])->post($startUrl)->assertForbidden();
        $enrollment->forceFill(['status' => 'confirmed', 'dropped_at' => null])->save();
        DB::table('exam_candidate_accommodations')->insert([
            'tenant_id' => $fixture['tenant']->getKey(), 'candidate_id' => $candidate->getKey(), 'accommodation_type' => 'additional_time',
            'revision' => 1, 'extra_time_minutes' => 15, 'authorized_by' => $fixture['reviewer']->getKey(), 'authorized_at' => now(),
            'expires_at' => now()->addDay(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($fixture['student'])->post($startUrl)->assertRedirect();
        $binding = ExamAttemptBinding::query()->where('candidate_id', $candidate->getKey())->firstOrFail();
        $attemptId = (int) $binding->attempt_id;
        self::assertSame($startsAt->copy()->addMinute()->addMinutes(75)->toDateTimeString(), $binding->expires_at->toDateTimeString());
        $this->actingAs($fixture['student'])->post($startUrl)->assertRedirect('/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId);
        self::assertSame(1, ExamAttemptBinding::query()->where('candidate_id', $candidate->getKey())->count());

        $this->actingAs($fixture['student'])->get('/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId)
            ->assertOk()->assertInertia(fn ($page) => $page->where('exam.title', 'Biology online exam')->where('attempt.id', $attemptId));
        $this->actingAs($fixture['student'])->post('/t/'.$fixture['tenant']->slug.'/assessments/quizzes/'.$quiz->getKey().'/attempts', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $responseUrl = '/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId.'/questions/'.$questionVersion->getKey();
        $this->actingAs($fixture['student'])->patch($responseUrl, ['expected_version' => 0, 'response' => ['selected_ids' => ['earth']]])->assertRedirect();
        self::assertSame(['selected_ids' => ['earth']], (array) $binding->attempt->responses()->firstOrFail()->fresh()->response_payload);
        $submitUrl = '/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId.'/submit';
        $this->actingAs($fixture['student'])->post($submitUrl)->assertRedirect();
        $receipt = $binding->fresh();
        self::assertNotNull($receipt->submitted_at);
        self::assertNotEmpty($receipt->receipt_reference);
        $this->actingAs($fixture['student'])->post($submitUrl)->assertRedirect();
        self::assertSame(1, ExamAttemptBinding::query()->where('candidate_id', $candidate->getKey())->count());
        $this->actingAs($fixture['student'])->patch($responseUrl, ['expected_version' => 1, 'response' => ['selected_ids' => ['moon']]])->assertStatus(409);
        self::assertSame(['selected_ids' => ['earth']], (array) $binding->attempt->responses()->firstOrFail()->fresh()->response_payload);
        $this->actingAs($fixture['student'])->get('/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId)->assertInertia(fn ($page) => $page->where('exam.receiptReference', $receipt->receipt_reference));
        $this->actingAs($fixture['otherStudent'])->get('/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$attemptId)->assertNotFound();

        $otherSis = SisStudent::query()->where('user_id', $fixture['otherStudent']->getKey())->firstOrFail();
        $otherCandidate = ExamCandidate::query()->create([
            'cycle_id' => $cycle->getKey(), 'student_id' => $otherSis->getKey(), 'registered_by' => $fixture['reviewer']->getKey(),
            'candidate_number' => 'C-'.Str::upper(Str::random(6)), 'status' => 'registered', 'registered_at' => now(),
        ]);
        $context->set($fixture['tenant'], $fixture['otherStudentMembership']);
        $this->actingAs($fixture['otherStudent'])->post($startUrl)->assertRedirect();
        $timedBinding = ExamAttemptBinding::query()->where('candidate_id', $otherCandidate->getKey())->firstOrFail();
        $timedAttempt = $timedBinding->attempt;
        $timedAttempt->forceFill(['expires_at' => now()->subSecond()])->save();
        $timedBinding->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->actingAs($fixture['otherStudent'])->get('/t/'.$fixture['tenant']->slug.'/assessments/attempts/'.$timedAttempt->getKey())->assertOk();
        self::assertNotNull($timedBinding->fresh()->submitted_at, 'Reading an expired attempt finalizes it on the server.');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'exams.attempt.expired']);
        app(QuizOutboxProcessor::class)->dispatchAvailable();
        $this->assertDatabaseHas('quiz_notifications', [
            'tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['teacher']->getKey(),
            'event_type' => 'exam.attempt_submitted', 'title' => 'Examination attempt submitted',
        ]);
        $this->assertDatabaseHas('quiz_notifications', [
            'tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['teacher']->getKey(),
            'event_type' => 'exam.attempt_expired', 'title' => 'Examination attempt expired',
        ]);
        app(QuizOutboxProcessor::class)->dispatchAvailable();
        self::assertSame(1, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('event_type', 'exam.attempt_submitted')->count());
        self::assertSame(1, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('event_type', 'exam.attempt_expired')->count());
        $context->clear();
    }

    public function test_every_supported_question_format_is_authorable_and_persisted_as_a_version(): void
    {
        $fixture = $this->fixture();
        $context = app(TenantContext::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $workflow = app(QuizAssessmentWorkflow::class);
        $bank = $workflow->createBank([
            'code' => 'FORMATS',
            'name' => 'Question format coverage',
            'campus_id' => $fixture['campus']->getKey(),
            'sharing_scope' => 'campus',
        ], $fixture['teacher']);

        $definitions = [
            'single_choice' => [['options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a']]],
            'multiple_choice' => [['options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a', 'b']]],
            'true_false' => [[], ['value' => true]],
            'matching' => [['pairs' => [['id' => 'one', 'left' => 'Left one', 'right' => 'Right one'], ['id' => 'two', 'left' => 'Left two', 'right' => 'Right two']]], ['matches' => ['one' => 'two', 'two' => 'one']]],
            'ordering' => [['items' => [['id' => 'one', 'text' => 'First'], ['id' => 'two', 'text' => 'Second']]], ['order' => ['two', 'one']]],
            'fill_blank' => [['blank_keys' => ['answer']], ['values' => ['answer' => ['accepted']]]],
            'short_text' => [[], ['accepted_answers' => ['accepted']]],
            'long_response' => [[], null],
            'numeric' => [[], ['value' => '-1.5']],
            'multiple_blanks' => [['blank_keys' => ['first', 'second']], ['values' => ['first' => ['one'], 'second' => ['two']]]],
            'case_passage' => [['passage' => 'A case description.'], null],
            'image_based' => [['image_alt' => 'A described diagram', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a']]],
            'file_response' => [[], null],
            'instructor_review' => [[], null],
        ];
        $gradingCoverage = [
            'single_choice' => [['selected_ids' => ['a']], 100],
            'multiple_choice' => [['selected_ids' => ['a', 'b']], 100],
            'true_false' => [['value' => true], 100],
            'matching' => [['matches' => ['one' => 'two', 'two' => 'one']], 100],
            'ordering' => [['order' => ['two', 'one']], 100],
            'fill_blank' => [['values' => ['answer' => 'ACCEPTED']], 100],
            'short_text' => [['text' => 'accepted'], 100],
            'long_response' => [['text' => 'A response that needs a person.'], null],
            'numeric' => [['value' => '-1.5'], 100],
            'multiple_blanks' => [['values' => ['first' => 'one', 'second' => 'two']], 100],
            'case_passage' => [['text' => 'A case analysis for human review.'], null],
            'image_based' => [['selected_ids' => ['a']], 100],
            'file_response' => [['artifact_id' => 1], null],
            'instructor_review' => [['text' => 'Instructor assessed response.'], null],
        ];

        self::assertSame(QuestionDefinition::TYPES, array_keys($definitions));
        self::assertSame(QuestionDefinition::TYPES, array_keys($gradingCoverage));

        foreach ($definitions as $type => [$typeContent, $answerKey]) {
            $question = $workflow->createQuestion((int) $bank->getKey(), [
                'question_type' => $type,
                'content' => ['prompt' => 'Prompt for '.$type, ...$typeContent],
                'answer_key' => $answerKey,
                'default_points' => '1.00',
                'difficulty' => 'moderate',
                'estimated_seconds' => 60,
            ], $fixture['teacher']);
            $version = $question->versions->first();

            self::assertSame($type, $version->question_type, $type.' persisted its type');
            self::assertSame('Prompt for '.$type, $version->content['prompt'], $type.' persisted its prompt');
            self::assertSame($answerKey, $version->answer_key, $type.' persisted the validated answer key');
            self::assertSame('draft', $version->status, $type.' remains a draft until independent review');
            [$response, $expectedScore] = $gradingCoverage[$type];
            self::assertSame(
                $expectedScore,
                app(QuizAutoScorer::class)->score($version, $response, '1.00'),
                $type.' uses the intended objective or human grading path',
            );
        }
    }

    public function test_quiz_assets_and_file_responses_are_scanned_private_and_authorized(): void
    {
        Storage::fake('local');
        $scanner = new class implements AdmissionDocumentScanner
        {
            public string $result = 'clean';

            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return match ($this->result) {
                    'clean' => AdmissionDocumentScanResult::clean(),
                    'rejected' => AdmissionDocumentScanResult::rejected(),
                    default => AdmissionDocumentScanResult::unavailable(),
                };
            }

            public function name(): string
            {
                return 'test-clamav-signature-v1';
            }
        };
        $this->app->instance(AdmissionDocumentScanner::class, $scanner);
        $fixture = $this->fixture();
        $base = '/t/'.$fixture['tenant']->slug.'/assessments';
        $context = app(TenantContext::class);
        $workflow = app(QuizAssessmentWorkflow::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $workflow->createBank(['code' => 'FILES', 'name' => 'Scanned question bank', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'file_response', 'content' => ['prompt' => 'Upload the completed worksheet.'],
            'answer_key' => null, 'default_points' => '2.00', 'difficulty' => 'moderate', 'estimated_seconds' => 120,
        ], $fixture['teacher']);
        $version = $question->versions->firstOrFail();

        $this->actingAs($fixture['teacher'])->post($base.'/question-versions/'.$version->getKey().'/assets', [
            'file' => UploadedFile::fake()->createWithContent('worksheet.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertRedirect();
        $asset = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())->where('purpose', 'question_asset')->first();
        self::assertNotNull($asset);
        self::assertSame('clean', $asset->scan_status);
        self::assertSame(1, count($version->fresh()->content['asset_ids']));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.artifact.quarantined']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.artifact.scanned']);

        $workflow->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $version = $question->fresh('versions')->versions->firstOrFail();
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $quiz = $workflow->createQuiz([
            'offering_id' => $fixture['offering']->getKey(), 'section_ids' => [$fixture['section']->getKey()],
            'questions' => [['question_version_id' => $version->getKey(), 'points' => '2.00']],
            'title' => 'File response quiz', 'instructions' => 'Upload one scanned file.', 'mode' => 'graded',
            'maximum_attempts' => 1, 'time_limit_minutes' => 10, 'grading_policy' => 'latest',
            'feedback_release' => 'manual', 'result_visibility' => 'score_only', 'navigation' => 'free',
        ], $fixture['teacher']);
        $workflow->submitQuizForReview((int) $quiz->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuiz((int) $quiz->getKey(), $fixture['reviewer'], true);
        $workflow->publishQuiz((int) $quiz->getKey(), $fixture['reviewer']);
        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $attempt = $workflow->startAttempt((int) $quiz->getKey(), (string) Str::uuid(), $fixture['student']);

        $this->actingAs($fixture['student'])->post($base.'/attempts/'.$attempt->getKey().'/questions/'.$version->getKey().'/file', [
            'expected_version' => 0, 'file' => UploadedFile::fake()->createWithContent('answer.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertRedirect();
        $response = $attempt->responses()->where('question_version_id', $version->getKey())->firstOrFail();
        self::assertSame('1', (string) $response->lock_version);
        $responseArtifactId = (int) $response->response_payload['artifact_id'];
        $responseArtifact = DB::table('quiz_file_artifacts')->where('id', $responseArtifactId)->first();
        self::assertSame('clean', $responseArtifact->scan_status);

        $this->actingAs($fixture['student'])->get($base.'/attempts/'.$attempt->getKey())
            ->assertOk()->assertInertia(fn ($page) => $page->where('questionAssets.'.$version->getKey().'.0.name', 'worksheet.pdf'));
        $this->actingAs($fixture['student'])->get($base.'/artifacts/'.$responseArtifactId)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($fixture['otherStudent'])->get($base.'/artifacts/'.$responseArtifactId)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.artifact.downloaded']);
        Storage::disk('local')->assertExists($responseArtifact->private_path);

        $scanner->result = 'rejected';
        $this->actingAs($fixture['student'])->post($base.'/attempts/'.$attempt->getKey().'/questions/'.$version->getKey().'/file', [
            'expected_version' => 1, 'file' => UploadedFile::fake()->createWithContent('infected.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertRedirect();
        $rejected = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())->where('purpose', 'learner_response')->where('scan_status', 'infected')->first();
        self::assertNotNull($rejected);
        $this->actingAs($fixture['student'])->get($base.'/artifacts/'.$rejected->id)->assertNotFound();
        self::assertSame(1, (int) $response->fresh()->lock_version, 'infected replacement must not overwrite the clean saved response');

        $scanner->result = 'unavailable';
        $this->actingAs($fixture['student'])->post($base.'/attempts/'.$attempt->getKey().'/questions/'.$version->getKey().'/file', [
            'expected_version' => 1, 'file' => UploadedFile::fake()->createWithContent('unscanned.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertRedirect();
        $unavailable = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())->where('purpose', 'learner_response')->where('scan_status', 'unavailable')->first();
        self::assertNotNull($unavailable);
        $this->actingAs($fixture['student'])->get($base.'/artifacts/'.$unavailable->id)->assertNotFound();
        self::assertSame(1, (int) $response->fresh()->lock_version, 'unavailable scanner must not save or release the new file');
    }

    public function test_real_clamav_classifies_quiz_assets_and_learner_responses_and_rejects_the_standard_test_signature(): void
    {
        if (config('admissions.document_scanner') !== 'clamav') {
            $this->markTestSkipped('The isolated test environment has not configured the real ClamAV adapter.');
        }
        Storage::fake('local');
        $fixture = $this->fixture();
        $base = '/t/'.$fixture['tenant']->slug.'/assessments';
        $context = app(TenantContext::class);
        $workflow = app(QuizAssessmentWorkflow::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $workflow->createBank(['code' => 'CLAMAV', 'name' => 'Real scanner acceptance', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'image_based', 'content' => ['prompt' => 'Review this scanned prompt image.', 'image_alt' => 'A test diagram', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'options_layout' => 'vertical'],
            'answer_key' => ['correct_option_ids' => ['a']], 'default_points' => '1.00', 'difficulty' => 'easy', 'estimated_seconds' => 30,
        ], $fixture['teacher']);
        $version = $question->versions->firstOrFail();

        $this->actingAs($fixture['teacher'])->post($base.'/question-versions/'.$version->getKey().'/assets', [
            'file' => UploadedFile::fake()->createWithContent('clamav-clean.pdf', "%PDF-1.4\nThis is a harmless scanner integration fixture.\n%%EOF"),
        ])->assertRedirect();
        $clean = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())->where('purpose', 'question_asset')->where('scan_status', 'clean')->first();
        self::assertNotNull($clean, 'the real scanner should approve the clean quiz attachment');
        self::assertStringStartsWith('clamav/', $clean->signature_version);
        self::assertNotNull($clean->scanned_at);

        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->actingAs($fixture['teacher'])->post($base.'/question-versions/'.$version->getKey().'/assets', [
            'file' => UploadedFile::fake()->createWithContent('clamav-eicar.txt', $eicar),
        ])->assertRedirect();
        $rejected = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())->where('purpose', 'question_asset')->where('scan_status', 'infected')->first();
        self::assertNotNull($rejected, 'the real scanner should reject the standard harmless EICAR test signature');
        self::assertSame('malware_detected', $rejected->result_code);
        $this->actingAs($fixture['teacher'])->get($base.'/artifacts/'.$rejected->id)->assertNotFound();

        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $responseQuestion = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'file_response', 'content' => ['prompt' => 'Upload the completed assessment.'],
            'answer_key' => null, 'default_points' => '2.00', 'difficulty' => 'moderate', 'estimated_seconds' => 120,
        ], $fixture['teacher']);
        $responseVersion = $responseQuestion->versions->firstOrFail();
        $workflow->submitQuestionForReview((int) $responseQuestion->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $responseQuestion->getKey(), $fixture['reviewer'], true);
        $responseVersion = $responseQuestion->fresh('versions')->versions->firstOrFail();
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $quiz = $workflow->createQuiz([
            'offering_id' => $fixture['offering']->getKey(), 'section_ids' => [$fixture['section']->getKey()],
            'questions' => [['question_version_id' => $responseVersion->getKey(), 'points' => '2.00']],
            'title' => 'Real scanner file response quiz', 'instructions' => 'Upload a scanned answer.', 'mode' => 'graded',
            'maximum_attempts' => 1, 'time_limit_minutes' => 10, 'grading_policy' => 'latest',
            'feedback_release' => 'manual', 'result_visibility' => 'score_only', 'navigation' => 'free',
        ], $fixture['teacher']);
        $workflow->submitQuizForReview((int) $quiz->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuiz((int) $quiz->getKey(), $fixture['reviewer'], true);
        $workflow->publishQuiz((int) $quiz->getKey(), $fixture['reviewer']);
        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $attempt = $workflow->startAttempt((int) $quiz->getKey(), (string) Str::uuid(), $fixture['student']);

        $this->actingAs($fixture['student'])->post($base.'/attempts/'.$attempt->getKey().'/questions/'.$responseVersion->getKey().'/file', [
            'expected_version' => 0,
            'file' => UploadedFile::fake()->createWithContent('real-clean-answer.pdf', "%PDF-1.4\nA clean learner response.\n%%EOF"),
        ])->assertRedirect();
        $savedResponse = $attempt->responses()->where('question_version_id', $responseVersion->getKey())->firstOrFail()->fresh();
        self::assertSame(1, (int) $savedResponse->lock_version);
        $responseArtifactId = (int) $savedResponse->response_payload['artifact_id'];
        $cleanResponse = DB::table('quiz_file_artifacts')->where('id', $responseArtifactId)->first();
        self::assertSame('learner_response', $cleanResponse->purpose);
        self::assertSame('clean', $cleanResponse->scan_status);
        self::assertStringStartsWith('clamav/', $cleanResponse->signature_version);
        $this->actingAs($fixture['student'])->get($base.'/artifacts/'.$responseArtifactId)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($fixture['otherStudent'])->get($base.'/artifacts/'.$responseArtifactId)->assertNotFound();

        $this->actingAs($fixture['student'])->post($base.'/attempts/'.$attempt->getKey().'/questions/'.$responseVersion->getKey().'/file', [
            'expected_version' => 1, 'file' => UploadedFile::fake()->createWithContent('real-eicar-answer.txt', $eicar),
        ])->assertRedirect();
        $infectedResponse = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())
            ->where('purpose', 'learner_response')->where('scan_status', 'infected')->first();
        $responseScanEvidence = DB::table('quiz_file_artifacts')->where('tenant_id', $fixture['tenant']->getKey())
            ->where('purpose', 'learner_response')->get(['scan_status', 'result_code', 'signature_version'])
            ->map(static fn (object $artifact): array => ['status' => $artifact->scan_status, 'result_code' => $artifact->result_code, 'signature_version' => $artifact->signature_version])
            ->all();
        self::assertNotNull($infectedResponse, 'the real scanner must reject EICAR learner responses; safe scan evidence: '.json_encode($responseScanEvidence, JSON_THROW_ON_ERROR));
        $this->actingAs($fixture['student'])->get($base.'/artifacts/'.$infectedResponse->id)->assertNotFound();
        self::assertSame(1, (int) $savedResponse->fresh()->lock_version, 'an infected replacement must not replace the clean response');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.artifact.scanned']);
    }

    public function test_question_review_publication_attempt_autosave_grading_and_result_release_are_scoped(): void
    {
        $fixture = $this->fixture();
        $base = '/t/'.$fixture['tenant']->slug.'/assessments';
        $this->actingAs($fixture['teacher'])->get($base)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/index')->where('mode', 'instructor'));
        $this->actingAs($fixture['teacher'])->get($base.'/grading')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/grading'));
        $this->actingAs($fixture['teacher'])->get($base.'/analytics')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/analytics')->where('report.sections.0.id', $fixture['section']->getKey()));
        $this->actingAs($fixture['student'])->get($base)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/index')->where('mode', 'student'));
        $context = app(TenantContext::class);
        $workflow = app(QuizAssessmentWorkflow::class);

        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $workflow->createBank(['code' => 'SCIENCE', 'name' => 'Science Questions', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'single_choice', 'content' => ['prompt' => 'Which is correct?', 'options' => [['id' => 'a', 'text' => 'Incorrect'], ['id' => 'b', 'text' => 'Correct']]],
            'answer_key' => ['correct_option_ids' => ['b']], 'default_points' => '1.00', 'difficulty' => 'moderate', 'estimated_seconds' => 60,
        ], $fixture['teacher']);
        $workflow->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $version = $question->fresh('versions')->versions->first();
        $this->assertSame('active', $version->status);
        $this->assertArrayNotHasKey('answer_key', $version->toArray());

        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $poolQuestion = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'multiple_choice', 'content' => ['prompt' => 'Select all correct choices.', 'options' => [['id' => 'x', 'text' => 'First choice'], ['id' => 'y', 'text' => 'Second choice']]],
            'answer_key' => ['correct_option_ids' => ['x', 'y']], 'default_points' => '1.00', 'difficulty' => 'moderate', 'estimated_seconds' => 60,
            'topic' => 'biology',
        ], $fixture['teacher']);
        $workflow->submitQuestionForReview((int) $poolQuestion->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $poolQuestion->getKey(), $fixture['reviewer'], true);
        $poolVersion = $poolQuestion->fresh('versions')->versions->first();
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $quiz = $workflow->createQuiz([
            'offering_id' => $fixture['offering']->getKey(), 'section_ids' => [$fixture['section']->getKey()],
            'questions' => [['question_version_id' => $version->getKey(), 'points' => '2.00']],
            'title' => 'Foundations Quiz', 'instructions' => 'Choose the best answer.', 'mode' => 'graded',
            'maximum_attempts' => 2, 'time_limit_minutes' => 15, 'grading_policy' => 'best',
            'feedback_release' => 'manual', 'result_visibility' => 'score_only', 'navigation' => 'free',
        ], $fixture['teacher']);
        try {
            $workflow->addQuestionPool((int) $quiz->getKey(), [(int) $poolVersion->getKey()], 'exhausted-pool', 2, '1.00', 'biology', 'moderate', true, $fixture['teacher']);
            self::fail('A required pool cannot request more questions than its approved candidates.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('pool', $exception->errors());
        }
        $workflow->addQuestionPool((int) $quiz->getKey(), [(int) $poolVersion->getKey()], 'balanced-review', 1, '1.00', 'biology', 'moderate', true, $fixture['teacher']);
        $workflow->submitQuizForReview((int) $quiz->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuiz((int) $quiz->getKey(), $fixture['reviewer'], true);
        $workflow->publishQuiz((int) $quiz->getKey(), $fixture['reviewer']);
        $accommodation = $workflow->saveAccommodation((int) $quiz->getKey(), (int) $fixture['studentMembership']->getKey(), ['extra_time_seconds' => 300, 'response_format' => 'text_only', 'confidential_reason' => 'Approved confidential accommodation'], $fixture['reviewer']);
        $this->assertSame(300, $accommodation->extra_time_seconds);
        $this->assertStringNotContainsString('Approved confidential accommodation', (string) DB::table('quiz_learner_accommodations')->where('id', $accommodation->getKey())->value('confidential_reason'));
        $outbox = app(QuizOutboxProcessor::class);
        $failNotificationInsert = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failNotificationInsert): void {
            $sql = strtolower(ltrim($query));
            if ($failNotificationInsert && str_starts_with($sql, 'insert') && str_contains($sql, 'quiz_notifications')) {
                $failNotificationInsert = false;
                throw new \RuntimeException('Simulated notification transport persistence failure.');
            }
        });
        $firstDelivery = $outbox->dispatchAvailable();
        $this->assertSame(1, $firstDelivery['retried']);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'quiz.published', 'status' => 'retry', 'attempts' => 1, 'last_error_code' => 'delivery_failed']);
        $this->travel(31)->seconds();
        $this->assertSame(1, $outbox->dispatchAvailable()['delivered']);
        $this->assertSame(0, $outbox->dispatchAvailable()['claimed']);
        $this->assertSame(2, DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant']->getKey())->where('event_type', 'quiz.published')->count());

        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $attemptKey = (string) Str::uuid();
        $attempt = $workflow->startAttempt((int) $quiz->getKey(), $attemptKey, $fixture['student']);
        $this->assertSame('in_progress', $attempt->status);
        $this->assertCount(2, $attempt->question_snapshot);
        $this->assertSame('3.00', $attempt->maximum_points);
        $retriedAttempt = $workflow->startAttempt((int) $quiz->getKey(), $attemptKey, $fixture['student']);
        $this->assertSame($attempt->getKey(), $retriedAttempt->getKey());
        $this->assertEquals($attempt->question_snapshot, $retriedAttempt->question_snapshot, 'refresh/retry must preserve the frozen randomized selection');
        $this->assertEquals(1200, abs($attempt->expires_at->diffInSeconds($attempt->started_at)));
        $this->assertArrayNotHasKey('answer_key', $attempt->responses->first()->questionVersion->toArray());
        $this->actingAs($fixture['student'])->get($base.'/attempts/'.$attempt->getKey())->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/attempt')->where('tenant.slug', $fixture['tenant']->slug)->where('attempt.id', $attempt->getKey()));
        $workflow->markForReview((int) $attempt->getKey(), (int) $version->getKey(), true, $fixture['student']);
        $this->assertDatabaseHas('quiz_attempt_responses', ['tenant_id' => $fixture['tenant']->getKey(), 'attempt_id' => $attempt->getKey(), 'question_version_id' => $version->getKey(), 'marked_for_review' => true]);
        $workflow->saveResponse((int) $attempt->getKey(), (int) $version->getKey(), ['selected_ids' => ['b']], 0, $fixture['student']);
        $submitted = $workflow->submitAttempt((int) $attempt->getKey(), $fixture['student']);
        $this->assertSame('graded', $submitted->status);
        $this->assertSame('2.00', $submitted->score_points);
        $this->assertSame('3.00', $submitted->maximum_points);
        $this->assertNull($submitted->result_released_at);
        $this->assertDatabaseHas('quiz_response_revisions', ['tenant_id' => $fixture['tenant']->getKey(), 'response_id' => $submitted->responses->first()->getKey(), 'revision_number' => 1]);
        $history = $workflow->studentQuizzes($fixture['student'])['completed']->getCollection()->first();
        $this->assertFalse($history['released']);
        $this->assertArrayNotHasKey('scorePoints', $history);

        $expiredAttempt = $workflow->startAttempt((int) $quiz->getKey(), (string) Str::uuid(), $fixture['student']);
        DB::table('quiz_attempts')->where('id', $expiredAttempt->getKey())->update(['expires_at' => now()->subSecond()]);
        $expiredAttempt = $workflow->submitAttempt((int) $expiredAttempt->getKey(), $fixture['student'], true);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.attempt.expired', 'subject_id' => (string) $expiredAttempt->getKey()]);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'quiz.attempt.expired', 'event_key' => 'attempt:'.$expiredAttempt->getKey().':expired']);

        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $this->assertSame(2, $workflow->releaseResults((int) $quiz->getKey(), $fixture['reviewer']));
        $this->assertNotNull($submitted->fresh()->result_released_at);
        $this->assertDatabaseHas('quiz_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'quiz.result_released']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.attempt.submitted']);
        $this->assertSame(4, $outbox->dispatchAvailable()['delivered']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->getKey(), 'event_type' => 'quiz.result_released']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['teacher']->getKey(), 'event_type' => 'quiz.attempt.expired']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->getKey(), 'event_type' => 'quiz.attempt.expired']);

        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $regrade = $workflow->requestRegrade((int) $attempt->getKey(), 'Please review the saved response and scoring outcome.', $fixture['student']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->decideRegrade((int) $regrade->getKey(), true, 'Approved for instructor review.', $fixture['reviewer']);
        $this->assertSame('needs_manual_review', $submitted->fresh()->status);
        $this->assertSame(2, $outbox->dispatchAvailable()['delivered']);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $gradeRows = app(QuizAssessmentQueries::class)->gradingQueue($fixture['teacher']);
        $this->assertGreaterThan(0, $gradeRows->total());
        $this->actingAs($fixture['teacher'])->get($base.'/grading')->assertOk()->assertInertia(fn ($page) => $page->where('responses.data.0.attempt.status', 'needs_manual_review'));
        $this->actingAs($fixture['student'])->get($base.'/notifications')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assessments/notifications'));

        $context->set($fixture['tenant'], $fixture['otherStudentMembership']);
        $this->expectException(ModelNotFoundException::class);
        $workflow->learnerAttempt((int) $attempt->getKey(), $fixture['otherStudent']);
    }

    public function test_question_revisions_preserve_the_active_version_until_independent_approval(): void
    {
        $fixture = $this->fixture();
        $context = app(TenantContext::class);
        $workflow = app(QuizAssessmentWorkflow::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $workflow->createBank(['code' => 'REVISION', 'name' => 'Revision bank', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'single_choice',
            'content' => ['prompt' => 'Original prompt', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]],
            'answer_key' => ['correct_option_ids' => ['a']],
            'default_points' => '1.00',
            'difficulty' => 'moderate',
            'estimated_seconds' => 60,
        ], $fixture['teacher']);
        $workflow->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $active = $question->fresh('versions')->versions->first();
        self::assertSame('active', $active->status);

        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $workflow->reviseQuestion((int) $question->getKey(), [
            'question_type' => 'single_choice',
            'content' => ['prompt' => 'Corrected prompt', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]],
        ], $fixture['teacher']);
        $revised = $question->fresh('versions');
        self::assertSame('Original prompt', $revised->versions->firstWhere('version_number', 1)->content['prompt']);
        self::assertSame('active', $revised->versions->firstWhere('version_number', 1)->status);
        self::assertSame('draft', $revised->versions->firstWhere('version_number', 2)->status);
        self::assertSame(1, (int) $revised->current_version);
        $this->actingAs($fixture['teacher'])->get('/t/'.$fixture['tenant']->slug.'/assessments')->assertInertia(fn ($page) => $page
            ->where('banks.0.questions.0.status', 'active')
            ->where('banks.0.questions.0.answerKey.correct_option_ids.0', 'a')
            ->where('banks.0.questions.0.latestStatus', 'draft')
            ->where('banks.0.questions.0.latestPrompt', 'Corrected prompt'));

        $workflow->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $this->actingAs($fixture['reviewer'])->get('/t/'.$fixture['tenant']->slug.'/assessments')->assertInertia(fn ($page) => $page
            ->where('banks.0.questions.0.latestStatus', 'under_review')
            ->where('banks.0.questions.0.latestPrompt', 'Corrected prompt'));
        $workflow->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $approved = $question->fresh('versions');
        self::assertSame('Corrected prompt', $approved->versions->firstWhere('version_number', 2)->content['prompt']);
        self::assertSame('active', $approved->versions->firstWhere('version_number', 1)->status);
        self::assertSame('active', $approved->versions->firstWhere('version_number', 2)->status);
        self::assertSame(2, (int) $approved->current_version);
    }

    public function test_quiz_close_archive_lifecycle_waits_for_attempts_and_result_release(): void
    {
        $fixture = $this->fixture();
        $context = app(TenantContext::class);
        $workflow = app(QuizAssessmentWorkflow::class);
        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $bank = $workflow->createBank(['code' => 'LIFECYCLE', 'name' => 'Lifecycle bank', 'campus_id' => $fixture['campus']->getKey(), 'sharing_scope' => 'campus'], $fixture['teacher']);
        $question = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'true_false',
            'content' => ['prompt' => 'The statement is true.'],
            'answer_key' => ['value' => true],
            'default_points' => '1.00',
            'difficulty' => 'easy',
            'estimated_seconds' => 30,
        ], $fixture['teacher']);
        $workflow->submitQuestionForReview((int) $question->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuestion((int) $question->getKey(), $fixture['reviewer'], true);
        $questionVersion = $question->fresh('versions')->versions->first();

        $context->set($fixture['tenant'], $fixture['teacherMembership']);
        $quiz = $workflow->createQuiz([
            'offering_id' => $fixture['offering']->getKey(),
            'section_ids' => [$fixture['section']->getKey()],
            'questions' => [['question_version_id' => $questionVersion->getKey(), 'points' => '1.00']],
            'title' => 'Lifecycle quiz',
            'instructions' => 'Complete this quiz.',
            'mode' => 'graded',
            'maximum_attempts' => 1,
            'time_limit_minutes' => 10,
            'grading_policy' => 'best',
            'feedback_release' => 'manual',
            'result_visibility' => 'score_only',
            'navigation' => 'free',
        ], $fixture['teacher']);
        $workflow->submitQuizForReview((int) $quiz->getKey(), $fixture['teacher']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->reviewQuiz((int) $quiz->getKey(), $fixture['reviewer'], true);
        $workflow->publishQuiz((int) $quiz->getKey(), $fixture['reviewer']);

        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $attempt = $workflow->startAttempt((int) $quiz->getKey(), (string) Str::uuid(), $fixture['student']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        self::assertSame('closed', $workflow->closeQuiz((int) $quiz->getKey(), $fixture['reviewer'])->status);
        try {
            $workflow->archiveQuiz((int) $quiz->getKey(), $fixture['reviewer']);
            self::fail('An open attempt must prevent archiving.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('quiz', $exception->errors());
        }

        $context->set($fixture['tenant'], $fixture['studentMembership']);
        $workflow->saveResponse((int) $attempt->getKey(), (int) $questionVersion->getKey(), ['value' => true], 0, $fixture['student']);
        $workflow->submitAttempt((int) $attempt->getKey(), $fixture['student']);
        $context->set($fixture['tenant'], $fixture['reviewerMembership']);
        $workflow->releaseResults((int) $quiz->getKey(), $fixture['reviewer']);
        $archived = $workflow->archiveQuiz((int) $quiz->getKey(), $fixture['reviewer']);

        self::assertSame('archived', $archived->status);
        self::assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.quiz.closed']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assessment.quiz.archived']);
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        // Faker can repeat its deterministic email sequence across guarded runs,
        // while older runner versions may have left committed probe fixtures.
        // Give this fixture a fresh namespace so an authorized rerun never
        // depends on deleting rows from the owned test schema.
        $user = static fn (string $role): User => User::factory()->create([
            'email' => 'phase13-quiz-'.$role.'-'.Str::uuid().'@example.test',
        ]);
        $creator = $user('creator');
        $owner = $user('owner');
        $teacher = $user('teacher');
        $reviewer = $user('reviewer');
        $student = $user('student');
        $otherStudent = $user('other-student');
        $name = 'Quiz Academy '.Str::random(6);
        $tenant = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($name), 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Main Campus', 'status' => 'active', 'is_primary' => true]);
            $memberships = [];
            foreach ([[$teacher, 'teacher'], [$reviewer, 'academic.admin'], [$student, 'student'], [$otherStudent, 'student']] as [$user, $roleKey]) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
                $memberships[$roleKey.':'.$user->getKey()] = $membership;
            }
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Year', 'code' => 'AY-'.Str::upper(Str::random(6)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Term', 'code' => 'TERM-'.Str::upper(Str::random(6)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'QZ-'.Str::upper(Str::random(6)), 'title' => 'Science', 'status' => 'active']);
            $enrollmentPolicy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Quiz Policy']);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $enrollmentPolicy->getKey(), 'offering_code' => 'QZ-'.Str::upper(Str::random(6)), 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'status' => 'open']);
            DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            foreach ([[$student, 'One'], [$otherStudent, 'Two']] as [$user, $suffix]) {
                $sis = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $user->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(8)), 'registration_reference' => 'REF-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Learner', 'legal_last_name' => $suffix, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
                CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $sis->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
            }
        } finally {
            $context->clear();
        }

        return ['tenant' => $tenant, 'campus' => $campus, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'reviewer' => $reviewer, 'student' => $student, 'otherStudent' => $otherStudent, 'teacherMembership' => $memberships['teacher:'.$teacher->getKey()], 'reviewerMembership' => $memberships['academic.admin:'.$reviewer->getKey()], 'studentMembership' => $memberships['student:'.$student->getKey()], 'otherStudentMembership' => $memberships['student:'.$otherStudent->getKey()]];
    }
}
