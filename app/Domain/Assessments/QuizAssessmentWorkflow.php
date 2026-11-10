<?php

namespace App\Domain\Assessments;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCourse;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAssessmentPolicy;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptResponse;
use App\Models\QuizFileArtifact;
use App\Models\QuizGradingReview;
use App\Models\QuizLearnerAccommodation;
use App\Models\QuizQuestion;
use App\Models\QuizQuestionBank;
use App\Models\QuizQuestionPool;
use App\Models\QuizQuestionTag;
use App\Models\QuizQuestionVersion;
use App\Models\QuizRegradeRequest;
use App\Models\QuizResponseRevision;
use App\Models\QuizVersion;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QuizAssessmentWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly QuizAutoScorer $scorer,
    ) {}

    /** @param array<string,mixed> $data */
    public function createBank(array $data, User $actor): QuizQuestionBank
    {
        $this->authorize('assessment.bank.manage');
        $tenantId = $this->tenantId();
        $membership = $this->context->membership();
        $institutionId = (int) $membership->institution_id;
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $institutionId)->findOrFail($campusId);
        } elseif ($membership->campus_id !== null && ! $membership->hasPermission('assessment.bank.share')) {
            throw ValidationException::withMessages(['campus_id' => 'Choose your assigned campus.']);
        }
        $sharingScope = (string) ($data['sharing_scope'] ?? 'campus');
        if ($sharingScope === 'institution') {
            $this->authorize('assessment.bank.share');
        }
        if ($campusId === null && $sharingScope === 'campus' && ! $membership->hasPermission('assessment.bank.share')) {
            $campuses = DB::table('course_section_instructors as instructor')->join('course_sections as section', function ($join): void {
                $join->on('section.id', '=', 'instructor.section_id')->whereColumn('section.tenant_id', '=', 'instructor.tenant_id');
            })->where('instructor.tenant_id', $membership->tenant_id)->where('instructor.user_id', $actor->getKey())->distinct()->pluck('section.campus_id');
            if ($campuses->count() !== 1) {
                throw ValidationException::withMessages(['campus_id' => 'Select an assigned campus to create a campus-scoped question bank.']);
            }
            $campusId = (int) $campuses->first();
        }
        $courseId = isset($data['academic_course_id']) ? (int) $data['academic_course_id'] : null;
        if ($courseId !== null) {
            AcademicCourse::query()->where('institution_id', $institutionId)->findOrFail($courseId);
        }

        return DB::transaction(function () use ($data, $actor, $tenantId, $institutionId, $campusId, $courseId, $sharingScope): QuizQuestionBank {
            $bank = QuizQuestionBank::query()->create([
                'institution_id' => $institutionId,
                'campus_id' => $campusId,
                'academic_course_id' => $courseId,
                'created_by' => $actor->getKey(),
                'code' => Str::upper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'description' => QuestionDefinition::plainText((string) ($data['description'] ?? ''), 4000),
                'sharing_scope' => $sharingScope,
                'status' => 'active',
            ]);
            $this->audit->record('assessment.question_bank.created', $actor, $tenantId, $bank, ['bank_id' => $bank->getKey(), 'sharing_scope' => $sharingScope]);

            return $bank;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createQuestion(int $bankId, array $data, User $actor): QuizQuestion
    {
        $this->authorize('assessment.bank.manage');
        $bank = $this->visibleBank($bankId);
        $normalized = QuestionDefinition::normalize((string) $data['question_type'], (array) $data['content'], isset($data['answer_key']) ? (array) $data['answer_key'] : null, (array) ($data['scoring_rules'] ?? []));
        $defaultPoints = QuizAutoScorer::toCents((string) ($data['default_points'] ?? '1.00'));
        if ($defaultPoints < 1 || $defaultPoints > 1_000_000_000) {
            throw ValidationException::withMessages(['default_points' => 'Set default points greater than zero and within the supported range.']);
        }

        return DB::transaction(function () use ($bank, $data, $actor, $normalized, $defaultPoints): QuizQuestion {
            $question = QuizQuestion::query()->create(['bank_id' => $bank->getKey(), 'status' => 'draft', 'current_version' => 0, 'created_by' => $actor->getKey()]);
            $version = QuizQuestionVersion::query()->create([
                'question_id' => $question->getKey(),
                'version_number' => 1,
                'question_type' => (string) $data['question_type'],
                'topic' => filled($data['topic'] ?? null) ? QuestionDefinition::plainText((string) $data['topic'], 120) : null,
                'subtopic' => filled($data['subtopic'] ?? null) ? QuestionDefinition::plainText((string) $data['subtopic'], 120) : null,
                'difficulty' => (string) ($data['difficulty'] ?? 'moderate'),
                'estimated_seconds' => (int) ($data['estimated_seconds'] ?? 60),
                'default_points' => self::fromCents($defaultPoints),
                'content' => $normalized['content'],
                'answer_key' => $normalized['answer_key'],
                'scoring_rules' => $normalized['scoring_rules'],
                'hints' => array_values(array_map(static fn (mixed $hint): string => QuestionDefinition::plainText((string) $hint, 500), (array) ($data['hints'] ?? []))),
                'explanation' => filled($data['explanation'] ?? null) ? QuestionDefinition::plainText((string) $data['explanation'], 4000) : null,
                'language' => (string) ($data['language'] ?? 'en'),
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            $question->setAttribute('draft_version_id', $version->getKey());
            foreach (array_unique(array_map('strval', (array) ($data['tags'] ?? []))) as $tagName) {
                $tagName = mb_substr(trim(QuestionDefinition::plainText($tagName, 48)), 0, 48);
                if ($tagName === '') {
                    continue;
                }
                $tag = QuizQuestionTag::query()->firstOrCreate(['name' => $tagName]);
                DB::table('quiz_question_tag_links')->insertOrIgnore(['tenant_id' => $question->tenant_id, 'question_id' => $question->getKey(), 'tag_id' => $tag->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('assessment.question.created', $actor, $this->tenantId(), $question, ['question_id' => $question->getKey(), 'version' => 1, 'question_type' => $version->question_type]);

            return $question->load('versions');
        }, 3);
    }

    /** @param list<int> $questionVersionIds */
    public function addQuestionPool(int $quizId, array $questionVersionIds, string $poolKey, int $selectCount, string $pointsPerQuestion, ?string $topic, ?string $difficulty, bool $required, User $actor): QuizQuestionPool
    {
        $this->authorize('assessment.quiz.manage');
        $tenantId = $this->tenantId();
        $questionVersionIds = array_values(array_unique(array_map('intval', $questionVersionIds)));
        $pointsCents = QuizAutoScorer::toCents($pointsPerQuestion);
        if (trim($poolKey) === '' || $selectCount < 1 || $selectCount > 50 || count($questionVersionIds) < $selectCount || $pointsCents < 1 || $pointsCents > 1_000_000 || ! in_array($difficulty, [null, 'easy', 'moderate', 'challenging'], true)) {
            throw ValidationException::withMessages(['pool' => 'Pool selection, count, difficulty, or points are invalid.']);
        }
        $quiz = Quiz::query()->with('sections')->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);
        $version = QuizVersion::query()->where('quiz_id', $quizId)->where('version_number', $quiz->current_version ?: 1)->firstOrFail();
        if ($quiz->status !== 'draft' || $version->status !== 'draft') {
            throw ValidationException::withMessages(['quiz' => 'Question pools can be configured only on a draft quiz.']);
        }
        $candidates = QuizQuestionVersion::query()->with('question.bank')->where('status', 'active')->whereIn('id', $questionVersionIds)->get();
        if ($candidates->count() !== count($questionVersionIds)) {
            throw ValidationException::withMessages(['question_versions' => 'Every pool item must be an active approved question.']);
        }
        foreach ($candidates as $candidate) {
            $this->assertBankMatchesSections($candidate->question->bank, $quiz->sections);
            if (($topic !== null && $candidate->topic !== $topic) || ($difficulty !== null && $candidate->difficulty !== $difficulty)) {
                throw ValidationException::withMessages(['question_versions' => 'Pool candidates must match the configured topic and difficulty filters.']);
            }
        }
        $fixedIds = DB::table('quiz_version_questions')->where('tenant_id', $tenantId)->where('quiz_version_id', $version->getKey())->pluck('question_version_id')->map(static fn ($id): int => (int) $id)->all();
        $existingPoolIds = DB::table('quiz_question_pools')->where('tenant_id', $tenantId)->where('quiz_version_id', $version->getKey())->pluck('id');
        $existingCandidateIds = DB::table('quiz_pool_question_versions')->where('tenant_id', $tenantId)->whereIn('pool_id', $existingPoolIds)->pluck('question_version_id')->map(static fn ($id): int => (int) $id)->all();
        if (array_intersect($questionVersionIds, [...$fixedIds, ...$existingCandidateIds]) !== []) {
            throw ValidationException::withMessages(['question_versions' => 'A question version can appear only once in a quiz blueprint.']);
        }

        return DB::transaction(function () use ($version, $questionVersionIds, $poolKey, $selectCount, $pointsCents, $topic, $difficulty, $required, $actor, $tenantId): QuizQuestionPool {
            $locked = QuizVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('The quiz draft changed before the pool was saved.');
            }
            $pool = QuizQuestionPool::query()->create([
                'quiz_version_id' => $locked->getKey(), 'pool_key' => QuestionDefinition::plainText($poolKey, 64),
                'topic' => $topic, 'difficulty' => $difficulty, 'select_count' => $selectCount,
                'points_per_question' => self::fromCents($pointsCents), 'required' => $required,
            ]);
            foreach ($questionVersionIds as $questionVersionId) {
                DB::table('quiz_pool_question_versions')->insert(['tenant_id' => $tenantId, 'pool_id' => $pool->getKey(), 'question_version_id' => $questionVersionId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $locked->forceFill(['total_points' => self::fromCents(QuizAutoScorer::toCents((string) $locked->total_points) + ($selectCount * $pointsCents))])->save();
            $this->audit->record('assessment.quiz.pool.created', $actor, $tenantId, $pool, ['quiz_id' => $locked->quiz_id, 'pool_key' => $pool->pool_key, 'candidate_count' => count($questionVersionIds), 'select_count' => $selectCount]);

            return $pool->load('questionVersions');
        }, 3);
    }

    public function markForReview(int $attemptId, int $questionVersionId, bool $marked, User $actor): QuizAttemptResponse
    {
        $this->authorize('assessment.attempt.respond');

        return DB::transaction(function () use ($attemptId, $questionVersionId, $marked, $actor): QuizAttemptResponse {
            $attempt = QuizAttempt::query()->where('membership_id', $this->context->membership()->getKey())->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttemptOwner($attempt, $actor);
            if ($attempt->status !== 'in_progress' || ($attempt->expires_at && $attempt->expires_at->isPast())) {
                throw new ConflictHttpException('This attempt no longer accepts review marks.');
            }
            $response = QuizAttemptResponse::query()->where('attempt_id', $attemptId)->where('question_version_id', $questionVersionId)->lockForUpdate()->firstOrFail();
            $response->forceFill(['marked_for_review' => $marked])->save();

            return $response->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveAccommodation(int $quizId, int $membershipId, array $data, User $actor): QuizLearnerAccommodation
    {
        $this->authorize('assessment.accommodation.manage');
        $quiz = Quiz::query()->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);
        $membership = TenantMembership::query()->where('institution_id', $this->context->membership()->institution_id)->findOrFail($membershipId);
        if ($this->context->membership()->campus_id !== null && (int) $this->context->membership()->campus_id !== (int) $membership->campus_id) {
            abort(404);
        }
        $extraTime = max(0, min(86_400, (int) ($data['extra_time_seconds'] ?? 0)));
        $availability = $this->localDate($data['availability_ends_at'] ?? null, (string) (Institution::query()->findOrFail($membership->institution_id)->timezone ?: 'UTC'));
        $format = $data['response_format'] ?? null;
        if ($format !== null && ! in_array($format, ['standard', 'text_only', 'large_text'], true)) {
            throw ValidationException::withMessages(['response_format' => 'Choose a supported response presentation.']);
        }

        return DB::transaction(function () use ($quiz, $membership, $extraTime, $availability, $format, $data, $actor): QuizLearnerAccommodation {
            $accommodation = QuizLearnerAccommodation::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->lockForUpdate()->first();
            $attributes = ['extra_time_seconds' => $extraTime, 'availability_ends_at' => $availability, 'response_format' => $format, 'confidential_reason' => QuestionDefinition::plainText((string) ($data['confidential_reason'] ?? ''), 1000), 'authorized_by' => $actor->getKey()];
            if ($accommodation) {
                $accommodation->forceFill($attributes)->save();
            } else {
                $accommodation = QuizLearnerAccommodation::query()->create(['quiz_id' => $quiz->getKey(), 'membership_id' => $membership->getKey(), ...$attributes]);
            }
            $this->audit->record('assessment.accommodation.updated', $actor, $this->tenantId(), $quiz, ['quiz_id' => $quiz->getKey(), 'membership_id' => $membership->getKey(), 'extra_time_seconds' => $extraTime, 'has_availability_extension' => $availability !== null]);

            return $accommodation;
        }, 3);
    }

    /** Creates an immutable next draft; the active version remains untouched until review. */
    /** @param array<string, mixed> $data */
    public function reviseQuestion(int $questionId, array $data, User $actor): QuizQuestion
    {
        $this->authorize('assessment.bank.manage');
        $question = QuizQuestion::query()->with('bank', 'versions')->findOrFail($questionId);
        $this->assertQuestionBankScope($question);
        $source = $question->versions->firstWhere('version_number', $question->current_version);
        if (! $source || $source->status !== 'active') {
            throw ValidationException::withMessages(['question' => 'Only an active question can be revised.']);
        }
        $normalized = QuestionDefinition::normalize((string) ($data['question_type'] ?? $source->question_type), (array) ($data['content'] ?? $source->content), isset($data['answer_key']) ? (array) $data['answer_key'] : (array) ($source->answer_key ?? []), (array) ($data['scoring_rules'] ?? $source->scoring_rules));

        return DB::transaction(function () use ($question, $source, $data, $normalized, $actor): QuizQuestion {
            $locked = QuizQuestion::query()->lockForUpdate()->findOrFail($question->getKey());
            $latest = QuizQuestionVersion::query()->where('question_id', $locked->getKey())->orderByDesc('version_number')->lockForUpdate()->firstOrFail();
            if ((int) $latest->getKey() !== (int) $source->getKey() || $latest->status !== 'active') {
                throw new ConflictHttpException('The question changed while it was being revised.');
            }
            QuizQuestionVersion::query()->create([
                'question_id' => $locked->getKey(), 'version_number' => (int) $latest->version_number + 1,
                'question_type' => (string) ($data['question_type'] ?? $source->question_type),
                'topic' => $data['topic'] ?? $source->topic, 'subtopic' => $data['subtopic'] ?? $source->subtopic,
                'difficulty' => $data['difficulty'] ?? $source->difficulty, 'estimated_seconds' => (int) ($data['estimated_seconds'] ?? $source->estimated_seconds),
                'default_points' => $data['default_points'] ?? $source->default_points, 'content' => $normalized['content'],
                'answer_key' => $normalized['answer_key'], 'scoring_rules' => $normalized['scoring_rules'],
                'hints' => (array) ($data['hints'] ?? $source->hints), 'explanation' => $data['explanation'] ?? $source->explanation,
                'language' => $data['language'] ?? $source->language, 'status' => 'draft', 'created_by' => $actor->getKey(),
            ]);
            $locked->forceFill(['status' => 'draft'])->save();
            $this->audit->record('assessment.question.version.created', $actor, $this->tenantId(), $locked, ['question_id' => $locked->getKey(), 'version' => (int) $latest->version_number + 1]);

            return $locked->fresh('versions');
        }, 3);
    }

    /** @param array<string,mixed> $settings */
    public function savePolicy(array $settings, ?int $campusId, int $expectedVersion, User $actor): QuizAssessmentPolicy
    {
        $this->authorize('assessment.policy.manage');
        $membership = $this->context->membership();
        $institutionId = (int) $membership->institution_id;
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $institutionId)->findOrFail($campusId);
            if ($membership->campus_id !== null && $membership->hasPermission('assessment.quiz.manage-all') && (int) $membership->campus_id !== $campusId) {
                abort(404);
            }
        } elseif ($membership->campus_id !== null) {
            abort(403);
        }
        $maximumAttempts = (int) ($settings['maximum_attempts'] ?? 3);
        $cooldown = (int) ($settings['maximum_cooldown_minutes'] ?? 0);
        if ($maximumAttempts < 1 || $maximumAttempts > 10 || $cooldown < 0 || $cooldown > 43_200) {
            throw ValidationException::withMessages(['settings' => 'Attempt and cooldown policy values are outside the supported limits.']);
        }
        $clean = [
            'maximum_attempts' => $maximumAttempts,
            'maximum_cooldown_minutes' => $cooldown,
            'require_independent_review' => (bool) ($settings['require_independent_review'] ?? true),
            'require_independent_publisher' => (bool) ($settings['require_independent_publisher'] ?? true),
            'default_feedback_release' => in_array($settings['default_feedback_release'] ?? 'manual', ['immediate', 'after_manual', 'after_close', 'manual'], true) ? $settings['default_feedback_release'] : 'manual',
            'default_result_visibility' => in_array($settings['default_result_visibility'] ?? 'score_only', ['score_only', 'score_and_feedback'], true) ? $settings['default_result_visibility'] : 'score_only',
        ];
        $scopeKey = $campusId === null ? 'institution' : 'campus:'.$campusId;

        return DB::transaction(function () use ($institutionId, $campusId, $scopeKey, $clean, $expectedVersion, $actor): QuizAssessmentPolicy {
            Institution::query()->whereKey($institutionId)->lockForUpdate()->firstOrFail();
            $policy = QuizAssessmentPolicy::query()->where('institution_id', $institutionId)->where('scope_key', $scopeKey)->lockForUpdate()->first();
            if ($policy && (int) $policy->lock_version !== $expectedVersion) {
                throw new ConflictHttpException('Assessment policy changed; reload before saving.');
            }
            if (! $policy && $expectedVersion !== 0) {
                throw new ConflictHttpException('Assessment policy no longer exists.');
            }
            if (! $policy) {
                $policy = QuizAssessmentPolicy::query()->create(['institution_id' => $institutionId, 'campus_id' => $campusId, 'scope_key' => $scopeKey, 'settings' => $clean, 'lock_version' => 1, 'updated_by' => $actor->getKey()]);
            } else {
                $policy->forceFill(['settings' => $clean, 'lock_version' => $expectedVersion + 1, 'updated_by' => $actor->getKey()])->save();
            }
            $this->audit->record('assessment.policy.updated', $actor, $this->tenantId(), $policy, ['institution_id' => $institutionId, 'campus_id' => $campusId, 'version' => $policy->lock_version]);

            return $policy->fresh();
        }, 3);
    }

    public function submitQuestionForReview(int $questionId, User $actor): QuizQuestion
    {
        $this->authorize('assessment.bank.manage');
        $question = QuizQuestion::query()->with('versions')->findOrFail($questionId);
        $this->assertQuestionBankScope($question);
        $version = $question->versions->first();
        if (! $version || $version->status !== 'draft') {
            throw ValidationException::withMessages(['question' => 'Only a draft question version can be submitted for review.']);
        }
        DB::transaction(function () use ($question, $actor): void {
            $lockedQuestion = QuizQuestion::query()->lockForUpdate()->findOrFail($question->getKey());
            $this->assertQuestionBankScope($lockedQuestion);
            $lockedVersion = QuizQuestionVersion::query()->where('question_id', $lockedQuestion->getKey())->orderByDesc('version_number')->lockForUpdate()->firstOrFail();
            if ($lockedVersion->status !== 'draft') {
                throw new ConflictHttpException('The question version is no longer a draft.');
            }
            $lockedQuestion->forceFill(['status' => 'under_review'])->save();
            $lockedVersion->forceFill(['status' => 'under_review'])->save();
            $this->audit->record('assessment.question.review_requested', $actor, $this->tenantId(), $lockedQuestion, ['question_id' => $lockedQuestion->getKey(), 'version' => $lockedVersion->version_number]);
        }, 3);

        return $question->fresh('versions');
    }

    public function reviewQuestion(int $questionId, User $actor, bool $approve): QuizQuestion
    {
        $this->authorize('assessment.bank.review');
        $question = QuizQuestion::query()->with('versions')->findOrFail($questionId);
        $this->assertQuestionBankScope($question);
        $version = $question->versions->first();
        if (! $version || $version->status !== 'under_review' || (int) $version->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['question' => 'An independent reviewer must decide a submitted question version.']);
        }
        DB::transaction(function () use ($question, $version, $actor, $approve): void {
            $lockedQuestion = QuizQuestion::query()->lockForUpdate()->findOrFail($question->getKey());
            $this->assertQuestionBankScope($lockedQuestion);
            $lockedVersion = QuizQuestionVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ($lockedVersion->status !== 'under_review' || (int) $lockedVersion->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['question' => 'The question is no longer awaiting your independent review.']);
            }
            $lockedVersion->forceFill(['status' => $approve ? 'active' : 'draft', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'activated_at' => $approve ? now() : null])->save();
            $lockedQuestion->forceFill(['status' => $approve ? 'active' : 'draft', 'current_version' => $approve ? $lockedVersion->version_number : $lockedQuestion->current_version])->save();
            $this->audit->record($approve ? 'assessment.question.activated' : 'assessment.question.rejected', $actor, $this->tenantId(), $lockedQuestion, ['question_id' => $lockedQuestion->getKey(), 'version' => $lockedVersion->version_number]);
        }, 3);

        return $question->fresh('versions');
    }

    /** @param array<string,mixed> $data */
    public function createQuiz(array $data, User $actor): Quiz
    {
        $this->authorize('assessment.quiz.manage');
        $membership = $this->context->membership();
        $tenantId = $this->tenantId();
        $offering = CourseOffering::query()->where('institution_id', $membership->institution_id)->find($data['offering_id'] ?? null);
        if (! $offering || in_array($offering->status, ['cancelled', 'completed'], true)) {
            throw ValidationException::withMessages(['offering_id' => 'Select an active course offering in this institution.']);
        }
        $sectionIds = array_values(array_unique(array_map('intval', (array) ($data['section_ids'] ?? []))));
        if ($sectionIds === []) {
            throw ValidationException::withMessages(['section_ids' => 'Select at least one course section.']);
        }
        $sections = CourseSection::query()->where('offering_id', $offering->getKey())->whereIn('id', $sectionIds)->get();
        if ($sections->count() !== count($sectionIds)) {
            throw ValidationException::withMessages(['section_ids' => 'Every selected section must belong to the selected offering.']);
        }
        $this->assertInstructorSections($sectionIds, $actor);
        $questionRows = (array) ($data['questions'] ?? []);
        if ($questionRows === [] || count($questionRows) > 100) {
            throw ValidationException::withMessages(['questions' => 'Choose between one and 100 approved question versions.']);
        }
        $questionIds = array_values(array_unique(array_map(static fn ($row): int => (int) ($row['question_version_id'] ?? 0), $questionRows)));
        if (count($questionIds) !== count($questionRows) || in_array(0, $questionIds, true)) {
            throw ValidationException::withMessages(['questions' => 'Question versions must be unique and valid.']);
        }
        $versions = QuizQuestionVersion::query()->with('question.bank')->where('status', 'active')->whereIn('id', $questionIds)->get();
        if ($versions->count() !== count($questionIds)) {
            throw ValidationException::withMessages(['questions' => 'Only active, institution-scoped question versions can be added.']);
        }
        foreach ($versions as $version) {
            $this->assertBankMatchesSections($version->question->bank, $sections);
        }
        $timezone = (string) (Institution::query()->findOrFail($membership->institution_id)->timezone ?: 'UTC');
        $availableAt = $this->localDate($data['available_at'] ?? null, $timezone);
        $closesAt = $this->localDate($data['closes_at'] ?? null, $timezone);
        if ($availableAt && $closesAt && $closesAt->lessThanOrEqualTo($availableAt)) {
            throw ValidationException::withMessages(['closes_at' => 'The close time must be after the opening time.']);
        }
        $timeLimit = (int) ($data['time_limit_minutes'] ?? 0) * 60;
        $maxAttempts = (int) ($data['maximum_attempts'] ?? 1);
        if ($timeLimit < 0 || $timeLimit > 14_400 || $maxAttempts < 1 || $maxAttempts > 10) {
            throw ValidationException::withMessages(['settings' => 'Time limit or attempt count is outside the supported policy range.']);
        }
        $campusIds = $sections->pluck('campus_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values();
        $policy = $campusIds->count() === 1
            ? QuizAssessmentPolicy::query()->where('institution_id', $membership->institution_id)->where('scope_key', 'campus:'.$campusIds->first())->first()
            : null;
        $policy ??= QuizAssessmentPolicy::query()->where('institution_id', $membership->institution_id)->where('scope_key', 'institution')->first();
        $policySettings = $policy === null ? [] : $policy->settings;
        $mode = (string) ($data['mode'] ?? 'graded');
        if (! in_array($mode, ['practice', 'self_check', 'graded', 'instructor_led', 'lesson_check'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose a supported quiz mode.']);
        }
        $settings = [
            'available_at' => $availableAt?->toIso8601String(),
            'closes_at' => $closesAt?->toIso8601String(),
            'time_limit_seconds' => $timeLimit,
            'maximum_attempts' => min($maxAttempts, (int) ($policySettings['maximum_attempts'] ?? 10)),
            'cooldown_minutes' => max(0, min((int) ($data['cooldown_minutes'] ?? 0), (int) ($policySettings['maximum_cooldown_minutes'] ?? 10080))),
            'pass_threshold' => isset($data['pass_threshold']) ? (int) $data['pass_threshold'] : null,
            'grading_policy' => in_array($data['grading_policy'] ?? 'best', ['best', 'latest', 'average'], true) ? $data['grading_policy'] : 'best',
            'feedback_release' => in_array($data['feedback_release'] ?? 'manual', ['immediate', 'after_manual', 'after_close', 'manual'], true) ? $data['feedback_release'] : 'manual',
            'result_visibility' => in_array($data['result_visibility'] ?? 'score_only', ['score_only', 'score_and_feedback'], true) ? $data['result_visibility'] : 'score_only',
            'shuffle_questions' => (bool) ($data['shuffle_questions'] ?? false),
            'shuffle_options' => (bool) ($data['shuffle_options'] ?? false),
            'navigation' => in_array($data['navigation'] ?? 'free', ['free', 'sequential'], true) ? $data['navigation'] : 'free',
            'mode' => $mode,
            'institution_timezone' => $timezone,
        ];
        $totalCents = 0;
        $normalizedRows = [];
        foreach ($questionRows as $position => $row) {
            $points = (string) ($row['points'] ?? '0');
            $pointsCents = QuizAutoScorer::toCents($points);
            if ($pointsCents < 1 || $pointsCents > 1_000_000) {
                throw ValidationException::withMessages(['questions' => 'Each question needs a positive point value within the supported limit.']);
            }
            $totalCents += $pointsCents;
            $normalizedRows[] = ['question_version_id' => (int) $row['question_version_id'], 'position' => $position + 1, 'points' => self::fromCents($pointsCents)];
        }
        if ($totalCents > 1_000_000_000) {
            throw ValidationException::withMessages(['questions' => 'The quiz total exceeds the supported point limit.']);
        }

        return DB::transaction(function () use ($data, $actor, $tenantId, $membership, $offering, $sectionIds, $settings, $normalizedRows, $totalCents, $mode): Quiz {
            $quiz = Quiz::query()->create(['institution_id' => $membership->institution_id, 'offering_id' => $offering->getKey(), 'created_by' => $actor->getKey(), 'status' => 'draft', 'current_version' => 0]);
            foreach ($sectionIds as $sectionId) {
                DB::table('quiz_sections')->insert(['tenant_id' => $tenantId, 'quiz_id' => $quiz->getKey(), 'section_id' => $sectionId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $version = QuizVersion::query()->create([
                'quiz_id' => $quiz->getKey(), 'version_number' => 1,
                'title' => QuestionDefinition::plainText((string) $data['title'], 200),
                'category' => QuestionDefinition::plainText((string) ($data['category'] ?? 'formative'), 48),
                'mode' => $mode,
                'description' => QuestionDefinition::plainText((string) ($data['description'] ?? ''), 4000),
                'instructions' => QuestionDefinition::plainText((string) $data['instructions'], 8000),
                'settings' => $settings,
                'total_points' => self::fromCents($totalCents),
                'status' => 'draft', 'created_by' => $actor->getKey(),
            ]);
            foreach ($normalizedRows as $row) {
                DB::table('quiz_version_questions')->insert(['tenant_id' => $tenantId, 'quiz_version_id' => $version->getKey(), ...$row, 'group_key' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('assessment.quiz.draft.created', $actor, $tenantId, $quiz, ['quiz_id' => $quiz->getKey(), 'version' => 1, 'question_count' => count($normalizedRows)]);

            return $quiz->load('versions');
        }, 3);
    }

    public function submitQuizForReview(int $quizId, User $actor): Quiz
    {
        $this->authorize('assessment.quiz.manage');
        $quiz = Quiz::query()->with('versions')->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);
        $version = $quiz->versions->first();
        if ($quiz->status !== 'draft' || ! $version || (float) $version->total_points <= 0 || ! DB::table('quiz_sections')->where('tenant_id', $this->tenantId())->where('quiz_id', $quizId)->exists()) {
            throw ValidationException::withMessages(['quiz' => 'The quiz must have approved questions, sections, and positive total points before review.']);
        }
        DB::transaction(function () use ($quiz, $actor): void {
            $locked = Quiz::query()->lockForUpdate()->findOrFail($quiz->getKey());
            $this->assertQuizScope($locked);
            $this->assertInstructorQuizScope($locked, $actor);
            $lockedVersion = QuizVersion::query()->where('quiz_id', $locked->getKey())->orderByDesc('version_number')->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || $lockedVersion->status !== 'draft' || (float) $lockedVersion->total_points <= 0) {
                throw new ConflictHttpException('The quiz is no longer an eligible draft for review.');
            }
            $locked->forceFill(['status' => 'under_review'])->save();
            $lockedVersion->forceFill(['status' => 'under_review'])->save();
            $this->audit->record('assessment.quiz.review_requested', $actor, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'version' => $lockedVersion->version_number]);
        }, 3);

        return $quiz->fresh('versions');
    }

    public function reviewQuiz(int $quizId, User $actor, bool $approve): Quiz
    {
        $this->authorize('assessment.quiz.review');
        $quiz = Quiz::query()->with('versions')->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);
        $version = $quiz->versions->first();
        if (! $version || $quiz->status !== 'under_review' || (int) $quiz->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['quiz' => 'An independent reviewer must decide this quiz.']);
        }
        DB::transaction(function () use ($quiz, $version, $actor, $approve): void {
            $locked = Quiz::query()->lockForUpdate()->findOrFail($quiz->getKey());
            $this->assertQuizScope($locked);
            $this->assertInstructorQuizScope($locked, $actor);
            if ($locked->status !== 'under_review' || (int) $locked->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['quiz' => 'The quiz is no longer awaiting your independent review.']);
            }
            $lockedVersion = QuizVersion::query()->where('quiz_id', $locked->getKey())->where('version_number', $version->version_number)->lockForUpdate()->firstOrFail();
            if ($lockedVersion->status !== 'under_review') {
                throw new ConflictHttpException('The quiz version is no longer awaiting review.');
            }
            $locked->forceFill(['status' => $approve ? 'approved' : 'draft', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()])->save();
            $lockedVersion->forceFill(['status' => $approve ? 'approved' : 'draft'])->save();
            $this->audit->record($approve ? 'assessment.quiz.approved' : 'assessment.quiz.rejected', $actor, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'version' => $lockedVersion->version_number]);
        }, 3);

        return $quiz->fresh('versions');
    }

    public function publishQuiz(int $quizId, User $actor): Quiz
    {
        $this->authorize('assessment.quiz.publish');
        $quiz = Quiz::query()->with('versions')->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);
        $version = $quiz->versions->first();
        if (! $version || $quiz->status !== 'approved' || $version->status !== 'approved' || (int) $quiz->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['quiz' => 'Only an independently approved quiz can be published by a separate publisher.']);
        }
        $availableAt = isset($version->settings['available_at']) ? CarbonImmutable::parse($version->settings['available_at'])->utc() : null;
        $status = $availableAt && $availableAt->isFuture() ? 'scheduled' : 'published';
        DB::transaction(function () use ($quiz, $version, $actor, $status): void {
            $locked = Quiz::query()->lockForUpdate()->findOrFail($quiz->getKey());
            $this->assertQuizScope($locked);
            $this->assertInstructorQuizScope($locked, $actor);
            if ($locked->status !== 'approved' || (int) $locked->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['quiz' => 'The quiz publication state changed; refresh and review it again.']);
            }
            $lockedVersion = QuizVersion::query()->where('quiz_id', $locked->getKey())->where('version_number', $version->version_number)->lockForUpdate()->firstOrFail();
            if ($lockedVersion->status !== 'approved') {
                throw new ConflictHttpException('The approved quiz version changed before publication.');
            }
            $lockedVersion->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $locked->forceFill(['status' => $status, 'current_version' => $lockedVersion->version_number, 'published_at' => now()])->save();
            if ($status === 'published') {
                $this->queueEvent($locked, 'quiz.published', 'quiz:'.$locked->getKey().':version:'.$lockedVersion->version_number.':published', ['quiz_id' => $locked->getKey(), 'version' => $lockedVersion->version_number]);
            }
            $this->audit->record('assessment.quiz.published', $actor, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'version' => $lockedVersion->version_number, 'state' => $status]);
        }, 3);

        return $quiz->fresh('versions');
    }

    public function closeQuiz(int $quizId, User $actor): Quiz
    {
        $this->authorize('assessment.quiz.publish');
        $quiz = Quiz::query()->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);

        return DB::transaction(function () use ($quiz, $actor): Quiz {
            $locked = Quiz::query()->lockForUpdate()->findOrFail($quiz->getKey());
            $this->assertQuizScope($locked);
            $this->assertInstructorQuizScope($locked, $actor);
            if (! in_array($locked->status, ['scheduled', 'published'], true)) {
                throw ValidationException::withMessages(['quiz' => 'Only a scheduled or published quiz can be closed.']);
            }
            $locked->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $this->audit->record('assessment.quiz.closed', $actor, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'trigger' => 'instructor']);

            return $locked->fresh('versions');
        }, 3);
    }

    public function archiveQuiz(int $quizId, User $actor): Quiz
    {
        $this->authorize('assessment.quiz.publish');
        $quiz = Quiz::query()->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);

        return DB::transaction(function () use ($quiz, $actor): Quiz {
            $locked = Quiz::query()->lockForUpdate()->findOrFail($quiz->getKey());
            $this->assertQuizScope($locked);
            $this->assertInstructorQuizScope($locked, $actor);
            if ($locked->status !== 'closed') {
                throw ValidationException::withMessages(['quiz' => 'Close a quiz before archiving it.']);
            }
            $hasOpenAttempts = QuizAttempt::query()->where('quiz_id', $locked->getKey())->where('status', 'in_progress')->exists();
            $hasPendingWork = QuizAttempt::query()->where('quiz_id', $locked->getKey())
                ->where(fn ($query) => $query->where('status', 'needs_manual_review')->orWhere(fn ($graded) => $graded->where('status', 'graded')->whereNull('result_released_at')))
                ->exists();
            if ($hasOpenAttempts || $hasPendingWork) {
                throw ValidationException::withMessages(['quiz' => 'Resolve active attempts, grading, and unreleased results before archiving.']);
            }
            $locked->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
            $this->audit->record('assessment.quiz.archived', $actor, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey()]);

            return $locked->fresh('versions');
        }, 3);
    }

    /** @return array{published:int,closed:int} */
    public function processAvailabilityTransitions(): array
    {
        $now = CarbonImmutable::now('UTC');
        $published = 0;
        $closed = 0;
        Quiz::query()->whereIn('status', ['scheduled', 'published'])->orderBy('id')->chunkById(100, function ($quizzes) use ($now, &$published, &$closed): void {
            foreach ($quizzes as $quiz) {
                $version = QuizVersion::query()->where('quiz_id', $quiz->getKey())->where('version_number', $quiz->current_version)->first();
                if (! $version) {
                    continue;
                }
                $opens = isset($version->settings['available_at']) ? CarbonImmutable::parse($version->settings['available_at'])->utc() : null;
                $closes = isset($version->settings['closes_at']) ? CarbonImmutable::parse($version->settings['closes_at'])->utc() : null;
                DB::transaction(function () use ($quiz, $opens, $closes, $now, &$published, &$closed): void {
                    $locked = Quiz::query()->lockForUpdate()->find($quiz->getKey());
                    if (! $locked) {
                        return;
                    }
                    if ($locked->status === 'scheduled' && (! $opens || $opens->lessThanOrEqualTo($now))) {
                        $locked->forceFill(['status' => 'published'])->save();
                        $this->queueEvent($locked, 'quiz.published', 'quiz:'.$locked->getKey().':version:'.$locked->current_version.':published', ['quiz_id' => $locked->getKey(), 'version' => $locked->current_version]);
                        $this->audit->record('assessment.quiz.published', null, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'version' => $locked->current_version, 'state' => 'published', 'trigger' => 'scheduled']);
                        $published++;
                    } elseif ($locked->status === 'published' && $closes && $closes->lessThanOrEqualTo($now)) {
                        $locked->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
                        $this->audit->record('assessment.quiz.closed', null, $this->tenantId(), $locked, ['quiz_id' => $locked->getKey(), 'version' => $locked->current_version, 'trigger' => 'schedule']);
                        $closed++;
                    }
                }, 3);
            }
        });

        return ['published' => $published, 'closed' => $closed];
    }

    /** @return array{upcoming:list<array<string,mixed>>,available:list<array<string,mixed>>,completed:LengthAwarePaginator<int, mixed>} */
    public function studentQuizzes(User $actor): array
    {
        $this->authorize('assessment.quiz.view');
        $membership = $this->context->membership();
        $student = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if (! $student) {
            return ['upcoming' => [], 'available' => [], 'completed' => new LengthAwarePaginator([], 0, 20)];
        }
        $enrollments = CourseEnrollment::query()->where('student_id', $student->getKey())->where('status', 'confirmed')
            ->when($membership->campus_id !== null, fn ($query) => $query->whereHas('section', fn ($sections) => $sections->where('campus_id', $membership->campus_id)));
        $sectionIds = $enrollments->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        $quizIds = DB::table('quiz_sections')->where('tenant_id', $this->tenantId())->whereIn('section_id', $sectionIds)->pluck('quiz_id')->unique()->all();
        $quizzes = Quiz::query()->with('offering.course')->whereIn('id', $quizIds)->whereIn('status', ['published', 'scheduled', 'closed'])
            ->whereNotIn('id', DB::table('exam_session_deliveries')->where('tenant_id', $this->tenantId())->select('quiz_id'))
            ->orderBy('id')->get()->map(function (Quiz $quiz) use ($membership): ?array {
                $version = QuizVersion::query()->where('quiz_id', $quiz->getKey())->where('version_number', $quiz->current_version)->first();
                if (! $version || $version->status !== 'published') {
                    return null;
                }
                $availableAt = isset($version->settings['available_at']) ? CarbonImmutable::parse($version->settings['available_at'])->utc() : null;
                $closesAt = isset($version->settings['closes_at']) ? CarbonImmutable::parse($version->settings['closes_at'])->utc() : null;
                $open = in_array($quiz->status, ['published', 'scheduled'], true)
                    && (! $availableAt || $availableAt->lessThanOrEqualTo(now()->utc()))
                    && (! $closesAt || $closesAt->greaterThan(now()->utc()));
                $attempts = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->orderByDesc('attempt_number')->get(['id', 'attempt_number', 'status', 'submitted_at']);
                $activeAttempt = $attempts->firstWhere('status', 'in_progress');
                $latestAttempt = $attempts->first();
                $maxAttempts = (int) ($version->settings['maximum_attempts'] ?? 1);
                $cooldownMinutes = (int) ($version->settings['cooldown_minutes'] ?? 0);
                $cooldownReady = $latestAttempt === null || $latestAttempt->submitted_at === null || CarbonImmutable::parse($latestAttempt->submitted_at)->addMinutes($cooldownMinutes)->lessThanOrEqualTo(now()->utc());
                $eligibleToStart = $open && ($activeAttempt !== null || ($attempts->count() < $maxAttempts && $cooldownReady));

                return [
                    'id' => (int) $quiz->getKey(), 'title' => $version->title,
                    'course' => $quiz->offering->course->title ?? 'Course assessment',
                    'mode' => $version->mode, 'category' => $version->category,
                    'availableAt' => $availableAt?->toIso8601String(), 'closesAt' => $closesAt?->toIso8601String(),
                    'timeLimitSeconds' => (int) ($version->settings['time_limit_seconds'] ?? 0),
                    'maximumAttempts' => $maxAttempts,
                    'maximumPoints' => (string) $version->total_points,
                    'canStart' => $eligibleToStart, 'hasAttempt' => $attempts->isNotEmpty(),
                    'activeAttemptId' => $activeAttempt ? (int) $activeAttempt->getKey() : null,
                    'attemptsRemaining' => max(0, $maxAttempts - $attempts->count()),
                ];
            })->filter()->values();
        $history = QuizAttempt::query()->with(['quizVersion.quiz.offering.course'])
            ->where('membership_id', $membership->getKey())->whereIn('status', ['in_progress', 'submitted', 'needs_manual_review', 'graded'])->orderByDesc('started_at')->paginate(20);
        $history->setCollection($history->getCollection()->map(static function (QuizAttempt $attempt): array {
            $released = $attempt->result_released_at !== null;
            $row = [
                'id' => (int) $attempt->getKey(), 'title' => $attempt->quizVersion->title,
                'course' => $attempt->quizVersion->quiz->offering->course->title ?? 'Course assessment',
                'status' => (string) $attempt->status, 'startedAt' => $attempt->started_at->toIso8601String(),
                'submittedAt' => $attempt->submitted_at?->toIso8601String(), 'released' => $released,
            ];
            if ($released) {
                $row += ['scorePoints' => (string) $attempt->score_points, 'maximumPoints' => (string) $attempt->maximum_points, 'percentage' => (string) $attempt->percentage];
            }

            return $row;
        }));

        return [
            'upcoming' => array_values($quizzes->filter(fn (array $quiz): bool => ! $quiz['canStart'])->values()->all()),
            'available' => array_values($quizzes->filter(fn (array $quiz): bool => $quiz['canStart'])->values()->all()),
            'completed' => $history,
        ];
    }

    public function startAttempt(int $quizId, string $idempotencyKey, User $actor, ?int $examSessionId = null): QuizAttempt
    {
        $this->authorize('assessment.attempt.start');
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Provide a valid request idempotency key.']);
        }
        $membership = $this->context->membership();
        $tenantId = $this->tenantId();
        $student = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if (! $student) {
            abort(404);
        }

        return DB::transaction(function () use ($quizId, $idempotencyKey, $actor, $membership, $student, $tenantId, $examSessionId): QuizAttempt {
            $quiz = Quiz::query()->lockForUpdate()->findOrFail($quizId);
            $this->assertQuizScope($quiz);
            $examDelivery = DB::table('exam_session_deliveries')->where('tenant_id', $tenantId)->where('quiz_id', $quiz->getKey())->first();
            if ($examDelivery && (int) $examDelivery->session_id !== $examSessionId) {
                abort(403, 'This quiz is reserved for its formal examination session.');
            }
            if ($examSessionId !== null && (! $examDelivery || (int) $examDelivery->session_id !== $examSessionId)) {
                abort(403, 'The requested formal examination delivery is not configured for this quiz.');
            }
            $version = QuizVersion::query()->where('quiz_id', $quiz->getKey())->where('version_number', $quiz->current_version)->firstOrFail();
            $settings = (array) $version->settings;
            $availableAt = isset($settings['available_at']) ? CarbonImmutable::parse($settings['available_at'])->utc() : null;
            $closesAt = isset($settings['closes_at']) ? CarbonImmutable::parse($settings['closes_at'])->utc() : null;
            $now = CarbonImmutable::now('UTC');
            $accommodation = QuizLearnerAccommodation::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->first();
            $effectiveClose = $accommodation?->availability_ends_at && (! $closesAt || $accommodation->availability_ends_at->greaterThan($closesAt)) ? $accommodation->availability_ends_at : $closesAt;
            if (! in_array($quiz->status, ['published', 'scheduled'], true) || $version->status !== 'published' || ($availableAt && $availableAt->greaterThan($now)) || ($effectiveClose && $effectiveClose->lessThanOrEqualTo($now))) {
                throw ValidationException::withMessages(['quiz' => 'This quiz is not currently available.']);
            }
            $targetSections = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', $quiz->getKey())->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
            $eligible = CourseEnrollment::query()->where('student_id', $student->getKey())->where('status', 'confirmed')->whereIn('section_id', $targetSections)
                ->when($membership->campus_id !== null, fn ($query) => $query->whereHas('section', fn ($sections) => $sections->where('campus_id', $membership->campus_id)))->exists();
            if (! $eligible) {
                abort(404);
            }
            $existing = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('responses');
            }
            $inProgress = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->where('status', 'in_progress')->first();
            if ($inProgress) {
                return $inProgress->load('responses');
            }
            $attemptCount = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->count();
            if ($attemptCount >= (int) ($settings['maximum_attempts'] ?? 1)) {
                throw ValidationException::withMessages(['quiz' => 'The permitted number of attempts has been reached.']);
            }
            $lastAttempt = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('membership_id', $membership->getKey())->orderByDesc('attempt_number')->first();
            $cooldown = (int) ($settings['cooldown_minutes'] ?? 0);
            if ($cooldown > 0 && $lastAttempt?->submitted_at && CarbonImmutable::parse($lastAttempt->submitted_at)->addMinutes($cooldown)->greaterThan($now)) {
                throw ValidationException::withMessages(['quiz' => 'Wait until the configured cooldown has elapsed before retrying.']);
            }
            $snapshot = $this->assembleQuestionSnapshot($version);
            if ($snapshot === []) {
                throw ValidationException::withMessages(['quiz' => 'The published quiz contains no usable questions.']);
            }
            $extraTime = $accommodation === null ? 0 : $accommodation->extra_time_seconds;
            $timeLimit = (int) ($settings['time_limit_seconds'] ?? 0) + (int) $extraTime;
            $attempt = QuizAttempt::query()->create([
                'quiz_id' => $quiz->getKey(), 'quiz_version_id' => $version->getKey(),
                'student_id' => $student->getKey(), 'membership_id' => $membership->getKey(),
                'attempt_number' => $attemptCount + 1, 'status' => 'in_progress', 'started_at' => $now,
                'expires_at' => $timeLimit > 0 ? $now->addSeconds($timeLimit) : null,
                'question_snapshot' => $snapshot, 'lock_version' => 1,
                'idempotency_key' => $idempotencyKey, 'maximum_points' => self::fromCents(array_sum(array_map(static fn (array $item): int => QuizAutoScorer::toCents($item['points']), $snapshot))),
            ]);
            foreach ($snapshot as $item) {
                QuizAttemptResponse::query()->create(['attempt_id' => $attempt->getKey(), 'question_version_id' => $item['question_version_id'], 'response_payload' => null, 'lock_version' => 0, 'grading_status' => 'ungraded']);
            }
            $this->audit->record('assessment.attempt.started', $actor, $tenantId, $attempt, ['quiz_id' => $quiz->getKey(), 'attempt_id' => $attempt->getKey(), 'attempt_number' => $attempt->attempt_number]);

            return $attempt->load('responses');
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    public function saveResponse(int $attemptId, int $questionVersionId, array $payload, int $expectedVersion, User $actor): QuizAttemptResponse
    {
        $this->authorize('assessment.attempt.respond');
        $attempt = QuizAttempt::query()->where('membership_id', $this->context->membership()->getKey())->findOrFail($attemptId);
        $this->assertAttemptOwner($attempt, $actor);
        if ($attempt->expires_at && CarbonImmutable::parse($attempt->expires_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $this->submitAttempt($attemptId, $actor, true);
            throw new ConflictHttpException('The server-authoritative quiz deadline has passed.');
        }
        if ($attempt->status !== 'in_progress') {
            throw new ConflictHttpException('This attempt no longer accepts answers.');
        }
        $snapshotItem = collect($attempt->question_snapshot)->firstWhere('question_version_id', $questionVersionId);
        if (! $snapshotItem) {
            abort(404);
        }
        $questionType = (string) $snapshotItem['question_type'];
        if ($questionType === 'file_response') {
            $artifactId = filter_var($payload['artifact_id'] ?? null, FILTER_VALIDATE_INT);
            $artifact = $artifactId === false ? null : QuizFileArtifact::query()->whereKey($artifactId)
                ->where('tenant_id', $this->tenantId())->where('attempt_id', $attemptId)
                ->where('question_version_id', $questionVersionId)->where('purpose', 'learner_response')
                ->where('scan_status', 'clean')->first();
            if (! $artifact) {
                throw ValidationException::withMessages(['response' => 'A current, scanned clean file is required for this response.']);
            }
            $cleanPayload = ['artifact_id' => (int) $artifact->getKey()];
        } else {
            $cleanPayload = $this->normalizeResponse($questionType, (array) $snapshotItem['content'], $payload);
        }

        return DB::transaction(function () use ($attemptId, $questionVersionId, $questionType, $cleanPayload, $expectedVersion, $actor): QuizAttemptResponse {
            $lockedAttempt = QuizAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttemptOwner($lockedAttempt, $actor);
            if ($lockedAttempt->status !== 'in_progress' || ($lockedAttempt->expires_at && CarbonImmutable::parse($lockedAttempt->expires_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC')))) {
                throw new ConflictHttpException('This attempt no longer accepts answers.');
            }
            $response = QuizAttemptResponse::query()->where('attempt_id', $attemptId)->where('question_version_id', $questionVersionId)->lockForUpdate()->firstOrFail();
            if ((int) $response->lock_version !== $expectedVersion) {
                if ((array) $response->response_payload === $cleanPayload) {
                    return $response;
                }
                throw new ConflictHttpException('A newer answer was saved in another session. Reload the response before editing.');
            }
            if ($questionType === 'file_response') {
                $artifact = QuizFileArtifact::query()->whereKey((int) $cleanPayload['artifact_id'])->where('tenant_id', $this->tenantId())
                    ->where('attempt_id', $attemptId)->where('question_version_id', $questionVersionId)
                    ->where('purpose', 'learner_response')->where('scan_status', 'clean')->lockForUpdate()->first();
                if (! $artifact) {
                    throw ValidationException::withMessages(['response' => 'The scanned file is no longer available for this response.']);
                }
            }
            $nextVersion = $expectedVersion + 1;
            $response->forceFill(['response_payload' => $cleanPayload, 'lock_version' => $nextVersion, 'saved_at' => now(), 'grading_status' => 'ungraded'])->save();
            QuizResponseRevision::query()->create(['response_id' => $response->getKey(), 'revision_number' => $nextVersion, 'response_payload' => $cleanPayload, 'saved_by' => $actor->getKey(), 'created_at' => now()]);
            $lockedAttempt->increment('lock_version');
            $this->audit->record('assessment.response.saved', $actor, $this->tenantId(), $response, ['attempt_id' => $attemptId, 'question_version_id' => $questionVersionId, 'response_version' => $nextVersion]);

            return $response->fresh();
        }, 3);
    }

    public function submitAttempt(int $attemptId, User $actor, bool $expired = false): QuizAttempt
    {
        if (! $expired) {
            $this->authorize('assessment.attempt.respond');
        }
        $attempt = QuizAttempt::query()->where('membership_id', $this->context->membership()->getKey())->findOrFail($attemptId);
        $this->assertAttemptOwner($attempt, $actor);

        return DB::transaction(function () use ($attemptId, $actor): QuizAttempt {
            $attempt = QuizAttempt::query()->with(['quizVersion', 'responses.questionVersion'])->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttemptOwner($attempt, $actor);
            if ($attempt->status !== 'in_progress') {
                return $attempt;
            }
            $now = CarbonImmutable::now('UTC');
            $expired = $attempt->expires_at !== null && CarbonImmutable::parse($attempt->expires_at)->lessThanOrEqualTo($now);
            $snapshot = collect($attempt->question_snapshot)->keyBy('question_version_id');
            $scoreCents = 0;
            $maximumCents = 0;
            $manualPending = false;
            foreach ($attempt->responses as $response) {
                $question = $response->questionVersion;
                $item = $snapshot->get($response->question_version_id);
                if (! $question || ! $item) {
                    throw new ConflictHttpException('The frozen attempt question snapshot is incomplete.');
                }
                $maximumCents += QuizAutoScorer::toCents((string) $item['points']);
                $points = $this->scorer->score($question, (array) ($response->response_payload ?? []), (string) $item['points']);
                if ($points === null) {
                    $manualPending = true;
                    $response->forceFill(['grading_status' => 'pending_review', 'points_awarded' => null])->save();
                } else {
                    $scoreCents += $points;
                    $response->forceFill(['grading_status' => 'auto_graded', 'points_awarded' => self::fromCents($points), 'graded_at' => $now])->save();
                }
            }
            $scoreCents = max(0, min($scoreCents, $maximumCents));
            $status = $manualPending ? 'needs_manual_review' : 'graded';
            $attempt->forceFill([
                'status' => $status, 'submitted_at' => $now, 'graded_at' => $manualPending ? null : $now,
                'receipt_reference' => (string) Str::uuid(), 'score_points' => self::fromCents($scoreCents),
                'maximum_points' => self::fromCents($maximumCents),
                'percentage' => $maximumCents > 0 ? self::fromCents(self::roundedRatio(max(0, min($scoreCents, $maximumCents)) * 10000, $maximumCents)) : '0.00',
                'manual_review_pending' => $manualPending,
            ])->save();
            $this->queueEvent(
                $attempt,
                $expired ? 'quiz.attempt.expired' : 'quiz.attempt_submitted',
                'attempt:'.$attempt->getKey().':'.($expired ? 'expired' : 'submitted'),
                ['attempt_id' => $attempt->getKey(), 'quiz_id' => $attempt->quiz_id],
            );
            $feedbackPolicy = (string) ($attempt->quizVersion->settings['feedback_release'] ?? 'manual');
            if (! $manualPending && $feedbackPolicy === 'immediate') {
                $attempt->forceFill(['result_released_at' => $now])->save();
                $this->queueEvent($attempt, 'quiz.result_released', 'attempt:'.$attempt->getKey().':result:released', ['attempt_id' => $attempt->getKey(), 'quiz_id' => $attempt->quiz_id]);
            }
            $this->audit->record($expired ? 'assessment.attempt.expired' : 'assessment.attempt.submitted', $actor, $this->tenantId(), $attempt, ['attempt_id' => $attempt->getKey(), 'attempt_number' => $attempt->attempt_number, 'manual_review_pending' => $manualPending]);
            $binding = DB::table('exam_attempt_bindings')->where('tenant_id', $attempt->tenant_id)->where('attempt_id', $attempt->getKey())->lockForUpdate()->first();
            if ($binding) {
                DB::table('exam_attempt_bindings')->where('id', $binding->id)->whereNull('submitted_at')->update([
                    'submitted_at' => $now, 'receipt_reference' => $attempt->receipt_reference, 'updated_at' => $now,
                ]);
                $examEvent = $expired ? 'exam.attempt_expired' : 'exam.attempt_submitted';
                DB::table('quiz_outbox_events')->insertOrIgnore([
                    'tenant_id' => $attempt->tenant_id,
                    'event_key' => $examEvent.':attempt:'.$attempt->getKey(),
                    'event_type' => $examEvent,
                    'safe_payload' => json_encode(['session_id' => (int) $binding->session_id, 'attempt_id' => (int) $attempt->getKey()], JSON_THROW_ON_ERROR),
                    'status' => 'queued', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record($expired ? 'exams.attempt.expired' : 'exams.attempt.submitted', $actor, $this->tenantId(), $attempt, [
                    'session_id' => (int) $binding->session_id, 'attempt_id' => (int) $attempt->getKey(), 'receipt_reference' => $attempt->receipt_reference,
                ]);
            }

            return $attempt->fresh('responses');
        }, 3);
    }

    public function manualGrade(int $responseId, string $points, string $feedback, string $privateNotes, int $expectedGradeRevision, User $actor): QuizAttemptResponse
    {
        $this->authorize('assessment.grade.manage');
        $response = QuizAttemptResponse::query()->with(['attempt.quiz', 'questionVersion'])->findOrFail($responseId);
        $attempt = $response->attempt;
        $this->assertQuizScope($attempt->quiz);
        $this->assertInstructorQuizScope($attempt->quiz, $actor);
        if (! in_array($attempt->status, ['needs_manual_review', 'graded'], true)) {
            throw ValidationException::withMessages(['response' => 'Only a submitted attempt can be manually graded.']);
        }
        $maximum = QuizAutoScorer::toCents((string) collect($attempt->question_snapshot)->firstWhere('question_version_id', $response->question_version_id)['points']);
        $awarded = QuizAutoScorer::toCents($points);
        if ($awarded < 0 || $awarded > $maximum) {
            throw ValidationException::withMessages(['points' => 'The grade must be between zero and this question’s point value.']);
        }

        return DB::transaction(function () use ($response, $attempt, $actor, $awarded, $feedback, $privateNotes, $expectedGradeRevision): QuizAttemptResponse {
            $lockedResponse = QuizAttemptResponse::query()->lockForUpdate()->findOrFail($response->getKey());
            $currentRevision = (int) QuizGradingReview::query()->where('response_id', $lockedResponse->getKey())->max('revision_number');
            if ($currentRevision !== $expectedGradeRevision) {
                throw new ConflictHttpException('A newer grading decision exists. Reload before saving another review.');
            }
            QuizGradingReview::query()->create([
                'response_id' => $lockedResponse->getKey(), 'reviewed_by' => $actor->getKey(),
                'revision_number' => $currentRevision + 1, 'points_awarded' => self::fromCents($awarded),
                'learner_feedback' => QuestionDefinition::plainText($feedback, 4000),
                'private_notes' => QuestionDefinition::plainText($privateNotes, 4000),
            ]);
            $lockedResponse->forceFill(['points_awarded' => self::fromCents($awarded), 'grading_status' => 'graded', 'graded_by' => $actor->getKey(), 'graded_at' => now()])->save();
            $lockedAttempt = QuizAttempt::query()->with('responses')->lockForUpdate()->findOrFail($attempt->getKey());
            $pending = $lockedAttempt->responses->contains(static fn (QuizAttemptResponse $item): bool => ! in_array($item->grading_status, ['auto_graded', 'graded'], true));
            $sumCents = 0;
            foreach ($lockedAttempt->responses as $item) {
                $sumCents += QuizAutoScorer::toSignedCents((string) ($item->points_awarded ?? '0.00'));
            }
            $maximumCents = QuizAutoScorer::toCents((string) $lockedAttempt->maximum_points);
            $lockedAttempt->forceFill([
                'status' => $pending ? 'needs_manual_review' : 'graded', 'manual_review_pending' => $pending,
                'graded_at' => $pending ? null : now(), 'score_points' => self::fromCents(max(0, min($sumCents, $maximumCents))),
                'percentage' => $maximumCents > 0 ? self::fromCents(self::roundedRatio(max(0, min($sumCents, $maximumCents)) * 10000, $maximumCents)) : '0.00',
            ])->save();
            $this->audit->record('assessment.response.manually_graded', $actor, $this->tenantId(), $lockedResponse, ['attempt_id' => $attempt->getKey(), 'response_id' => $lockedResponse->getKey(), 'revision' => $currentRevision + 1]);
            if (! $pending) {
                $this->queueEvent($lockedAttempt, 'quiz.manual_grade.completed', 'attempt:'.$lockedAttempt->getKey().':manual-grade:revision:'.($currentRevision + 1), ['attempt_id' => $lockedAttempt->getKey(), 'quiz_id' => $lockedAttempt->quiz_id, 'grade_revision' => $currentRevision + 1]);
            }

            return $lockedResponse->fresh();
        }, 3);
    }

    public function releaseResults(int $quizId, User $actor): int
    {
        $this->authorize('assessment.results.release');
        $quiz = Quiz::query()->findOrFail($quizId);
        $this->assertQuizScope($quiz);
        $this->assertInstructorQuizScope($quiz, $actor);

        return DB::transaction(function () use ($quiz, $actor): int {
            $attempts = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->where('status', 'graded')->whereNull('result_released_at')->lockForUpdate()->get();
            foreach ($attempts as $attempt) {
                $gradeRevision = (int) DB::table('quiz_grading_reviews')->join('quiz_attempt_responses', function ($join): void {
                    $join->on('quiz_attempt_responses.id', '=', 'quiz_grading_reviews.response_id')
                        ->whereColumn('quiz_attempt_responses.tenant_id', '=', 'quiz_grading_reviews.tenant_id');
                })->where('quiz_attempt_responses.tenant_id', $this->tenantId())->where('quiz_attempt_responses.attempt_id', $attempt->getKey())->max('quiz_grading_reviews.revision_number');
                $attempt->forceFill(['result_released_at' => now()])->save();
                $this->queueEvent($attempt, 'quiz.result_released', 'attempt:'.$attempt->getKey().':result:released:grade:'.$gradeRevision, ['attempt_id' => $attempt->getKey(), 'quiz_id' => $quiz->getKey(), 'grade_revision' => $gradeRevision]);
            }
            $this->audit->record('assessment.quiz.results_released', $actor, $this->tenantId(), $quiz, ['quiz_id' => $quiz->getKey(), 'released_count' => $attempts->count()]);

            return $attempts->count();
        }, 3);
    }

    public function learnerAttempt(int $attemptId, User $actor): QuizAttempt
    {
        $this->authorize('assessment.attempt.view-own');
        $attempt = QuizAttempt::query()->with(['quizVersion', 'responses.questionVersion', 'responses.gradingReviews', 'regradeRequests'])->where('membership_id', $this->context->membership()->getKey())->findOrFail($attemptId);
        $this->assertAttemptOwner($attempt, $actor);
        if ($attempt->status === 'in_progress' && $attempt->expires_at && CarbonImmutable::parse($attempt->expires_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $attempt = $this->submitAttempt($attemptId, $actor, true)->load(['quizVersion', 'responses.questionVersion', 'responses.gradingReviews', 'regradeRequests']);
        }

        if ($attempt->result_released_at === null) {
            $attempt->makeHidden(['score_points', 'percentage', 'graded_at', 'result_released_at']);
            $attempt->responses->each(static function (QuizAttemptResponse $response): void {
                $response->makeHidden(['points_awarded', 'graded_by', 'graded_at', 'grading_status', 'grading_reviews']);
            });
        } elseif (($attempt->quizVersion->settings['result_visibility'] ?? 'score_only') !== 'score_and_feedback') {
            $attempt->responses->each(static function (QuizAttemptResponse $response): void {
                $response->makeHidden(['grading_reviews']);
            });
        } else {
            $attempt->responses->each(static function (QuizAttemptResponse $response): void {
                $latest = $response->gradingReviews->last();
                $response->setRelation('gradingReviews', $latest ? collect([$latest]) : collect());
            });
            $attempt->setRelation('regradeRequests', $attempt->regradeRequests->take(1)->values());
        }

        return $attempt;
    }

    public function requestRegrade(int $attemptId, string $reason, User $actor): QuizRegradeRequest
    {
        $this->authorize('assessment.regrade.request');
        $attempt = $this->learnerAttempt($attemptId, $actor);

        return DB::transaction(function () use ($attempt, $reason, $actor): QuizRegradeRequest {
            $lockedAttempt = QuizAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $this->assertAttemptOwner($lockedAttempt, $actor);
            if ($lockedAttempt->result_released_at === null || $lockedAttempt->status !== 'graded') {
                throw ValidationException::withMessages(['attempt' => 'A regrade request is available only for a released, graded attempt.']);
            }
            $existing = QuizRegradeRequest::query()->where('attempt_id', $lockedAttempt->getKey())->where('requested_by', $actor->getKey())->whereIn('status', ['open', 'approved'])->first();
            if ($existing) {
                return $existing;
            }
            $request = QuizRegradeRequest::query()->create(['attempt_id' => $lockedAttempt->getKey(), 'requested_by' => $actor->getKey(), 'reason' => QuestionDefinition::plainText($reason, 2000), 'status' => 'open']);
            $this->audit->record('assessment.regrade.requested', $actor, $this->tenantId(), $request, ['attempt_id' => $lockedAttempt->getKey(), 'request_id' => $request->getKey()]);
            $this->queueEvent($lockedAttempt, 'quiz.regrade.requested', 'regrade:'.$request->getKey().':requested', ['attempt_id' => $lockedAttempt->getKey(), 'request_id' => $request->getKey()]);

            return $request;
        }, 3);
    }

    public function decideRegrade(int $requestId, bool $approve, string $notes, User $actor): QuizRegradeRequest
    {
        $this->authorize('assessment.regrade.review');
        $request = QuizRegradeRequest::query()->with('attempt.quiz')->findOrFail($requestId);
        $this->assertQuizScope($request->attempt->quiz);
        $this->assertInstructorQuizScope($request->attempt->quiz, $actor);
        if ($request->status !== 'open' || (int) $request->requested_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['request' => 'A different authorized reviewer must decide an open request.']);
        }
        DB::transaction(function () use ($request, $approve, $notes, $actor): void {
            $locked = QuizRegradeRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($locked->status !== 'open' || (int) $locked->requested_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('The regrade request state changed.');
            }
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->getKey(), 'decision_notes' => QuestionDefinition::plainText($notes, 2000), 'decided_at' => now()])->save();
            if ($approve) {
                $attempt = QuizAttempt::query()->lockForUpdate()->findOrFail($locked->attempt_id);
                $attempt->forceFill(['status' => 'needs_manual_review', 'manual_review_pending' => true, 'result_released_at' => null])->save();
            }
            $this->audit->record($approve ? 'assessment.regrade.approved' : 'assessment.regrade.rejected', $actor, $this->tenantId(), $locked, ['attempt_id' => $locked->attempt_id, 'request_id' => $locked->getKey()]);
            $this->queueEvent($locked->attempt, 'quiz.regrade.decided', 'regrade:'.$locked->getKey().':decided', ['attempt_id' => $locked->attempt_id, 'request_id' => $locked->getKey(), 'decision' => $locked->status]);
        }, 3);

        return $request->fresh();
    }

    /** @return list<array<string,mixed>> */
    private function assembleQuestionSnapshot(QuizVersion $version): array
    {
        $version->loadMissing(['questionVersions.question']);
        $snapshot = [];
        $seen = [];
        foreach ($version->questionVersions as $questionVersion) {
            $item = $this->snapshotQuestion($questionVersion, (string) $questionVersion->pivot->points, (int) $questionVersion->pivot->position);
            if ((bool) ($version->settings['shuffle_options'] ?? false)) {
                $item['content'] = $this->shuffleContentOptions($item['content']);
            }
            $snapshot[] = $item;
            $seen[(int) $questionVersion->getKey()] = true;
        }

        foreach ($version->pools()->with('questionVersions.question')->get() as $pool) {
            $eligible = $pool->questionVersions->filter(static fn (QuizQuestionVersion $question): bool => $question->status === 'active'
                && ! isset($seen[(int) $question->getKey()])
                && ($pool->topic === null || $question->topic === $pool->topic)
                && ($pool->difficulty === null || $question->difficulty === $pool->difficulty))->values()->all();
            $eligible = $this->secureShuffle($eligible);
            $count = min((int) $pool->select_count, count($eligible));
            if ($count < (int) $pool->select_count && $pool->required) {
                throw ValidationException::withMessages(['quiz' => 'A required question pool does not contain enough approved questions.']);
            }
            foreach (array_slice($eligible, 0, $count) as $questionVersion) {
                $item = $this->snapshotQuestion($questionVersion, (string) $pool->points_per_question, count($snapshot) + 1);
                if ((bool) ($version->settings['shuffle_options'] ?? false)) {
                    $item['content'] = $this->shuffleContentOptions($item['content']);
                }
                $snapshot[] = $item;
                $seen[(int) $questionVersion->getKey()] = true;
            }
        }
        if ((bool) ($version->settings['shuffle_questions'] ?? false)) {
            $snapshot = $this->secureShuffle($snapshot);
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function snapshotQuestion(QuizQuestionVersion $question, string $points, int $position): array
    {
        if ($question->status !== 'active') {
            throw ValidationException::withMessages(['quiz' => 'A quiz references a question version that is no longer approved.']);
        }

        return [
            'question_version_id' => (int) $question->getKey(),
            'question_type' => (string) $question->question_type,
            'position' => $position,
            'points' => $points,
            'content' => (array) $question->content,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function shuffleContentOptions(array $content): array
    {
        foreach (['options', 'items'] as $key) {
            if (isset($content[$key]) && is_array($content[$key])) {
                $content[$key] = $this->secureShuffle(array_values($content[$key]));
            }
        }

        return $content;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function secureShuffle(array $items): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeResponse(string $type, array $content, array $payload): array
    {
        $knownOptions = array_map('strval', array_column((array) ($content['options'] ?? []), 'id'));
        if (in_array($type, ['single_choice', 'multiple_choice', 'image_based'], true)) {
            $selected = array_values(array_unique(array_map('strval', (array) ($payload['selected_ids'] ?? []))));
            if (array_diff($selected, $knownOptions) !== [] || ($type === 'single_choice' && count($selected) > 1)) {
                throw ValidationException::withMessages(['response' => 'Choose only available answer options.']);
            }

            return ['selected_ids' => $selected];
        }
        if ($type === 'true_false') {
            if (! is_bool($payload['value'] ?? null)) {
                throw ValidationException::withMessages(['response' => 'Choose True or False.']);
            }

            return ['value' => $payload['value']];
        }
        if ($type === 'matching') {
            $pairs = (array) ($content['pairs'] ?? []);
            $leftIds = array_map('strval', array_column($pairs, 'id'));
            $rightIds = $leftIds;
            $matches = (array) ($payload['matches'] ?? []);
            if (array_diff(array_map('strval', array_keys($matches)), $leftIds) !== [] || array_diff(array_map('strval', array_values($matches)), $rightIds) !== []) {
                throw ValidationException::withMessages(['response' => 'Use only the available matching items.']);
            }

            return ['matches' => array_map('strval', $matches)];
        }
        if ($type === 'ordering') {
            $allowed = array_map('strval', array_column((array) ($content['items'] ?? []), 'id'));
            $order = array_values(array_map('strval', (array) ($payload['order'] ?? [])));
            if (count($order) !== count($allowed) || array_diff($order, $allowed) !== [] || count(array_unique($order)) !== count($allowed)) {
                throw ValidationException::withMessages(['response' => 'Place each ordering item exactly once.']);
            }

            return ['order' => $order];
        }
        if (in_array($type, ['fill_blank', 'multiple_blanks'], true)) {
            $keys = array_values(array_map('strval', (array) ($content['blank_keys'] ?? [])));
            $values = (array) ($payload['values'] ?? []);
            if (array_diff(array_map('strval', array_keys($values)), $keys) !== []) {
                throw ValidationException::withMessages(['response' => 'Answer only the blanks shown in this question.']);
            }
            $clean = [];
            foreach ($keys as $key) {
                $clean[$key] = QuestionDefinition::plainText((string) ($values[$key] ?? ''), 500);
            }

            return ['values' => $clean];
        }
        if ($type === 'numeric') {
            $value = trim((string) ($payload['value'] ?? ''));
            if (! preg_match('/\A-?\d{1,12}(?:\.\d{1,6})?\z/', $value)) {
                throw ValidationException::withMessages(['response' => 'Enter a valid number.']);
            }

            return ['value' => $value];
        }
        if (in_array($type, ['short_text', 'long_response', 'case_passage', 'instructor_review'], true)) {
            $limit = $type === 'short_text' ? 4000 : 30000;

            return ['text' => QuestionDefinition::plainText((string) ($payload['text'] ?? ''), $limit)];
        }
        throw ValidationException::withMessages(['response' => 'This response type is not supported.']);
    }

    private function visibleBank(int $bankId): QuizQuestionBank
    {
        $membership = $this->context->membership();
        $query = QuizQuestionBank::query()->where('institution_id', $membership->institution_id)->where('status', 'active');
        if ($membership->campus_id !== null && ! $membership->hasPermission('assessment.bank.share')) {
            $query->where(function ($scope) use ($membership): void {
                $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id);
            });
        }
        $bank = $query->findOrFail($bankId);
        if (! $membership->hasPermission('assessment.bank.share')) {
            if ($bank->sharing_scope === 'institution' || $bank->campus_id === null) {
                abort(404);
            }
            $visible = $membership->campus_id !== null
                ? (int) $bank->campus_id === (int) $membership->campus_id
                : DB::table('course_section_instructors as instructor')->join('course_sections as section', function ($join): void {
                    $join->on('section.id', '=', 'instructor.section_id')->whereColumn('section.tenant_id', '=', 'instructor.tenant_id');
                })->where('instructor.tenant_id', $membership->tenant_id)->where('instructor.user_id', $membership->user_id)->where('section.campus_id', $bank->campus_id)->exists();
            if (! $visible) {
                abort(404);
            }
        }

        return $bank;
    }

    private function assertQuestionBankScope(QuizQuestion $question): void
    {
        $bank = $question->bank;
        if (! $bank || (int) $bank->institution_id !== (int) $this->context->membership()->institution_id) {
            abort(404);
        }
        $this->visibleBank((int) $bank->getKey());
    }

    /** @param Collection<int,CourseSection> $sections */
    private function assertBankMatchesSections(QuizQuestionBank $bank, $sections): void
    {
        foreach ($sections as $section) {
            if ((int) $section->offering->institution_id !== (int) $bank->institution_id) {
                abort(404);
            }
            if ($bank->campus_id !== null && (int) $bank->campus_id !== (int) $section->campus_id) {
                throw ValidationException::withMessages(['questions' => 'A campus-scoped question bank cannot be shared with another campus.']);
            }
        }
    }

    private function assertQuizScope(Quiz $quiz): void
    {
        $membership = $this->context->membership();
        if ((int) $quiz->institution_id !== (int) $membership->institution_id) {
            abort(404);
        }
        if ($membership->campus_id !== null) {
            $visible = DB::table('quiz_sections')->where('tenant_id', $this->tenantId())->where('quiz_id', $quiz->getKey())
                ->whereIn('section_id', CourseSection::query()->where('campus_id', $membership->campus_id)->select('id'))->exists();
            if (! $visible) {
                abort(404);
            }
        }
    }

    /** @param list<int> $sectionIds */
    private function assertInstructorSections(array $sectionIds, User $actor): void
    {
        $membership = $this->context->membership();
        if ($membership->hasPermission('assessment.quiz.manage-all')) {
            $sections = CourseSection::query()->whereIn('id', $sectionIds)->get();
            if ($sections->count() !== count($sectionIds) || $sections->contains(fn (CourseSection $section): bool => (int) $section->offering->institution_id !== (int) $membership->institution_id || ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $section->campus_id))) {
                abort(404);
            }

            return;
        }
        $assigned = DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $actor->getKey())->whereIn('section_id', $sectionIds)->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        if (count(array_unique($assigned)) !== count($sectionIds)) {
            abort(403);
        }
    }

    private function assertInstructorQuizScope(Quiz $quiz, User $actor): void
    {
        $sectionIds = DB::table('quiz_sections')->where('tenant_id', $this->tenantId())->where('quiz_id', $quiz->getKey())->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        if ($sectionIds === []) {
            abort(404);
        }
        $this->assertInstructorSections($sectionIds, $actor);
    }

    private function assertAttemptOwner(QuizAttempt $attempt, User $actor): void
    {
        $membership = $this->context->membership();
        if ((int) $attempt->membership_id !== (int) $membership->getKey() || (int) $attempt->tenant_id !== (int) $membership->tenant_id || (int) $attempt->student->user_id !== (int) $actor->getKey()) {
            abort(404);
        }
    }

    private function localDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['schedule' => 'Enter a valid date and time in the institution timezone.']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function queueEvent(QuizAttempt|Quiz $subject, string $eventType, string $eventKey, array $payload): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $this->tenantId(), 'event_key' => $eventKey, 'event_type' => $eventType,
            'safe_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0,
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function authorize(string $permission): void
    {
        abort_unless($this->context->hasMembership() && $this->context->membership()->hasPermission($permission), 403);
    }

    private function tenantId(): int
    {
        return (int) $this->context->tenant()->getKey();
    }

    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function roundedRatio(int $numerator, int $denominator): int
    {
        return $denominator > 0 ? intdiv($numerator + intdiv($denominator, 2), $denominator) : 0;
    }
}
