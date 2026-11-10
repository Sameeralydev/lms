<?php

namespace App\Domain\Examinations;

use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamAttemptBinding;
use App\Models\ExamCandidate;
use App\Models\ExamCandidateAccommodation;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\ExamSessionDelivery;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExamDeliveryWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly QuizAssessmentWorkflow $quizzes,
        private readonly ExamCandidateGovernanceWorkflow $eligibility,
    ) {}

    public function configure(int $sessionId, int $quizId, int $paperVersionId, User $actor): ExamSessionDelivery
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.manage') || $membership->hasPermission('exams.paper.publish'), 403);
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($sessionId, $quizId, $paperVersionId, $actor, $tenantId): ExamSessionDelivery {
            $session = ExamSession::query()->with('cycle')->lockForUpdate()->findOrFail($sessionId);
            abort_unless($session->mode === 'online' && $session->status === 'published' && $session->cycle->status === 'published', 409, 'Online delivery requires a published examination session and cycle.');
            $paper = ExamPaperVersion::query()->with('paper')->whereKey($paperVersionId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $paper->paper->session_id === $sessionId && $paper->status === 'published'
                && (int) $paper->paper->current_version === (int) $paper->version_number
                && $paper->paper->current_set_code === $paper->set_code, 409, 'Select the current published paper version for this session.');
            $this->assertPaperIntegrity($paper);

            $quiz = Quiz::query()->with('offering')->whereKey($quizId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $quiz->offering->academic_course_id === (int) $session->academic_course_id, 422, 'The quiz course must match the examination session course.');
            abort_unless(in_array($quiz->status, ['published', 'scheduled'], true), 422, 'Only a published quiz can deliver an examination.');
            $version = QuizVersion::query()->where('quiz_id', $quizId)->where('version_number', $quiz->current_version)->lockForUpdate()->firstOrFail();
            abort_unless($version->status === 'published', 422, 'The current quiz version must be published.');
            abort_unless($version->pools()->doesntExist(), 422, 'Formal exam delivery requires a fixed, approved question set; randomized pools are not supported in this batch.');
            $settings = (array) $version->settings;
            abort_unless(! ($settings['shuffle_questions'] ?? false) && ! ($settings['shuffle_options'] ?? false), 422, 'Formal exam delivery requires stable approved question and option order.');
            $availableAt = isset($settings['available_at']) ? CarbonImmutable::parse($settings['available_at'])->utc() : null;
            $closesAt = isset($settings['closes_at']) ? CarbonImmutable::parse($settings['closes_at'])->utc() : null;
            if (($availableAt && $availableAt->greaterThan(CarbonImmutable::parse($session->starts_at_utc)->utc()))
                || ($closesAt && $closesAt->lessThan(CarbonImmutable::parse($session->ends_at_utc)->utc()))) {
                throw ValidationException::withMessages(['quiz_id' => 'The published quiz availability must cover the entire examination session.']);
            }
            $sections = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', $quizId)->pluck('section_id');
            $eligibleSection = DB::table('course_sections')->where('tenant_id', $tenantId)->whereIn('id', $sections)
                ->where('campus_id', $session->campus_id)->where('offering_id', $quiz->offering_id)->exists();
            abort_unless($eligibleSection, 422, 'The quiz must target a section in the examination course and campus.');
            $this->assertQuestionSetMatches($paper, $version);
            abort_unless(! ExamAttemptBinding::query()->where('session_id', $sessionId)->exists(), 409, 'A session with started attempts cannot change its delivery binding.');
            abort_unless(! ExamSessionDelivery::query()->where('session_id', $sessionId)->exists(), 409, 'An exam delivery is already configured for this session.');
            abort_unless(! $quiz->attempts()->exists(), 409, 'A quiz with existing learner attempts cannot be bound to a formal examination.');

            $delivery = ExamSessionDelivery::query()->create([
                'session_id' => $sessionId, 'quiz_id' => $quizId, 'quiz_version_id' => $version->getKey(),
                'paper_version_id' => $paper->getKey(), 'configured_by' => $actor->getKey(), 'configured_at' => now(),
            ]);
            $this->audit->record('exams.delivery.configured', $actor, $tenantId, $delivery, [
                'session_id' => $sessionId, 'quiz_id' => $quizId, 'quiz_version_id' => (int) $version->getKey(),
                'paper_version_id' => $paperVersionId, 'content_hash' => $paper->content_hash,
            ]);

            return $delivery;
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function learnerExams(User $actor): array
    {
        $membership = $this->context->membership();
        $student = DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if (! $student) {
            return [];
        }
        $rows = ExamSessionDelivery::query()->with(['session.course', 'session.cycle', 'paperVersion'])
            ->whereHas('session.cycle.candidates', fn ($query) => $query->where('student_id', $student->id)->where('status', 'registered')->whereNull('withdrawn_at'))
            ->whereHas('session', fn ($query) => $query->where('status', 'published')->where('mode', 'online'))
            ->orderBy('session_id')->get();

        $exams = [];
        foreach ($rows as $delivery) {
            $session = $delivery->session;
            $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->where('student_id', $student->id)->where('status', 'registered')->whereNull('withdrawn_at')->first();
            $binding = $candidate ? ExamAttemptBinding::query()->where('session_id', $session->getKey())->where('candidate_id', $candidate->getKey())->with('attempt')->first() : null;
            $eligible = $candidate && $this->eligibility->evaluate($candidate, $session)['eligible'];

            $exams[] = [
                'sessionId' => (int) $session->getKey(), 'title' => $session->title, 'course' => $session->course->title,
                'paperTitle' => $delivery->paperVersion->title, 'instructions' => $delivery->paperVersion->instructions,
                'startsAt' => CarbonImmutable::parse($session->starts_at_utc)->toIso8601String(), 'endsAt' => CarbonImmutable::parse($session->ends_at_utc)->toIso8601String(),
                'durationMinutes' => (int) $delivery->paperVersion->duration_minutes,
                'canStart' => (bool) $eligible && ! $binding && now()->greaterThanOrEqualTo($session->starts_at_utc) && now()->lessThan($session->ends_at_utc),
                'attemptId' => $binding?->attempt_id, 'attemptStatus' => $binding?->attempt?->status,
                'receiptReference' => $binding?->receipt_reference,
            ];
        }

        return $exams;
    }

    public function start(int $sessionId, User $actor): QuizAttempt
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assessment.attempt.start'), 403);
        $student = DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if ($student === null) {
            abort(404);
        }
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($sessionId, $actor, $student, $tenantId): QuizAttempt {
            $session = ExamSession::query()->with('cycle')->lockForUpdate()->findOrFail($sessionId);
            $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->where('student_id', $student->id)->lockForUpdate()->firstOrFail();
            $existingBinding = ExamAttemptBinding::query()->where('session_id', $sessionId)->where('candidate_id', $candidate->getKey())->lockForUpdate()->first();
            if ($existingBinding) {
                return QuizAttempt::query()->with('responses')->findOrFail($existingBinding->attempt_id);
            }
            abort_unless($candidate->status === 'registered' && $candidate->withdrawn_at === null, 403, 'Candidate registration is not active.');
            abort_unless($session->status === 'published' && $session->cycle->status === 'published' && $session->mode === 'online', 409, 'This examination is not open for online delivery.');
            $now = CarbonImmutable::now('UTC');
            $startsAt = CarbonImmutable::parse($session->starts_at_utc)->utc();
            $endsAt = CarbonImmutable::parse($session->ends_at_utc)->utc();
            abort_unless(! $now->lessThan($startsAt) && $now->lessThan($endsAt), 409, 'The examination entry window is not open.');
            abort_unless($this->eligibility->evaluate($candidate, $session)['eligible'], 403, 'Candidate does not meet the examination eligibility policy.');
            $delivery = ExamSessionDelivery::query()->with(['paperVersion.paper', 'quizVersion'])->where('session_id', $sessionId)->lockForUpdate()->firstOrFail();
            abort_unless($delivery->paperVersion->status === 'published'
                && (int) $delivery->paperVersion->paper->current_version === (int) $delivery->paperVersion->version_number
                && $delivery->paperVersion->paper->current_set_code === $delivery->paperVersion->set_code, 409, 'The approved examination paper is no longer the current published version.');
            $this->assertPaperIntegrity($delivery->paperVersion);
            $quiz = Quiz::query()->whereKey($delivery->quiz_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($quiz->status, ['published', 'scheduled'], true)
                && (int) $quiz->current_version === (int) $delivery->quizVersion->version_number
                && $delivery->quizVersion->status === 'published', 409, 'The quiz version bound to this examination is no longer current and published.');
            $this->assertQuestionSetMatches($delivery->paperVersion, $delivery->quizVersion);
            $accommodation = ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))->orderByDesc('authorized_at')->first();
            $attempt = $this->quizzes->startAttempt((int) $delivery->quiz_id, (string) Str::uuid(), $actor, $sessionId);
            $paperMinutes = (int) $delivery->paperVersion->duration_minutes;
            $extraMinutes = $accommodation === null ? 0 : (int) $accommodation->extra_time_minutes;
            $extraSeconds = $extraMinutes * 60;
            $examExpiry = $now->addMinutes($paperMinutes)->addSeconds($extraSeconds);
            $expiresAt = $examExpiry->lessThan($endsAt) ? $examExpiry : $endsAt;
            $attempt->forceFill(['expires_at' => $expiresAt])->save();
            $binding = ExamAttemptBinding::query()->create([
                'session_id' => $sessionId, 'candidate_id' => $candidate->getKey(), 'attempt_id' => $attempt->getKey(),
                'started_at' => $attempt->started_at, 'expires_at' => $expiresAt,
            ]);
            $this->audit->record('exams.attempt.started', $actor, $tenantId, $binding, [
                'session_id' => $sessionId, 'candidate_id' => (int) $candidate->getKey(), 'attempt_id' => (int) $attempt->getKey(),
                'paper_version_id' => (int) $delivery->paper_version_id, 'accommodation_extra_minutes' => $extraMinutes,
            ]);

            return $attempt->load('responses');
        }, 3);
    }

    public function bindingForAttempt(int $attemptId): ?ExamAttemptBinding
    {
        return ExamAttemptBinding::query()->with('session.course', 'session.cycle', 'session.delivery.paperVersion', 'candidate')->where('attempt_id', $attemptId)->first();
    }

    private function assertPaperIntegrity(ExamPaperVersion $paper): void
    {
        try {
            $canonical = ['blueprint' => $paper->decryptedBlueprint(), 'questions' => $paper->decryptedQuestions(), 'total_marks' => (string) $paper->total_marks];
            $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            abort(409, 'The published examination paper snapshot cannot be verified.');
        }
        abort_unless(hash_equals((string) $paper->content_hash, $hash), 409, 'The published examination paper fingerprint is invalid.');
    }

    private function assertQuestionSetMatches(ExamPaperVersion $paper, QuizVersion $version): void
    {
        $quizQuestions = $version->questionVersions()->get()->values()->map(fn ($question, $index): array => [
            'question_version_id' => (int) $question->getKey(), 'position' => (int) $question->pivot->position,
            'points' => number_format((float) $question->pivot->points, 2, '.', ''),
        ])->all();
        $paperQuestions = collect($paper->decryptedQuestions())->map(fn (array $question, $index): array => [
            'question_version_id' => (int) ($question['question_version_id'] ?? 0),
            'position' => (int) ($question['position'] ?? ($index + 1)), 'points' => number_format((float) ($question['points'] ?? 0), 2, '.', ''),
        ])->all();
        abort_unless($quizQuestions !== [] && $quizQuestions === $paperQuestions, 422, 'The published quiz question versions and marks must exactly match the independently approved paper snapshot.');
    }
}
