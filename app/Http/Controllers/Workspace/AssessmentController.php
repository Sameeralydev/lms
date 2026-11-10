<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Assessments\QuizArtifactWorkflow;
use App\Domain\Assessments\QuizAssessmentQueries;
use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Examinations\ExamDeliveryWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\CourseSection;
use App\Models\Quiz;
use App\Models\QuizAssessmentPolicy;
use App\Models\QuizQuestionBank;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AssessmentController
{
    public function index(Request $request, Tenant $tenant, TenantContext $context, QuizAssessmentWorkflow $workflow): Response
    {
        $membership = $context->membership();
        if ($membership->roles()->where('key', 'student')->exists()) {
            $learnerData = $workflow->studentQuizzes($request->user());

            return Inertia::render('workspace/assessments/index', [
                'mode' => 'student', 'tenant' => ['slug' => $tenant->slug],
                'upcoming' => $learnerData['upcoming'], 'available' => $learnerData['available'],
                'completed' => $learnerData['completed'],
            ]);
        }

        $assignedIds = DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $request->user()->getKey())->pluck('section_id');
        $sections = CourseSection::query()->whereHas('offering', fn ($query) => $query->where('institution_id', $membership->institution_id))
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->when(! $membership->hasPermission('assessment.quiz.manage-all'), fn ($query) => $query->whereIn('id', $assignedIds))
            ->with('offering.course')->orderBy('section_code')->get();
        $sectionIds = $sections->modelKeys();
        $quizzes = Quiz::query()->with(['versions' => fn ($query) => $query->orderByDesc('version_number'), 'offering.course'])
            ->whereIn('id', DB::table('quiz_sections')->where('tenant_id', $membership->tenant_id)->whereIn('section_id', $sectionIds)->select('quiz_id'))
            ->orderByDesc('updated_at')->paginate(20)->withQueryString();
        $banks = QuizQuestionBank::query()->where('institution_id', $membership->institution_id)->where('status', 'active')
            ->when($membership->campus_id !== null && ! $membership->hasPermission('assessment.bank.share'), fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
            ->with('questions.versions')->orderBy('name')->get();
        $policy = QuizAssessmentPolicy::query()->where('institution_id', $membership->institution_id)->where('scope_key', $membership->campus_id === null ? 'institution' : 'campus:'.$membership->campus_id)->first();

        return Inertia::render('workspace/assessments/index', [
            'mode' => 'instructor', 'tenant' => ['slug' => $tenant->slug], 'sections' => $sections->map(fn (CourseSection $section): array => ['id' => (int) $section->getKey(), 'code' => $section->section_code, 'course' => $section->offering?->course?->title, 'offeringId' => (int) $section->offering_id])->values(),
            'quizzes' => $quizzes, 'banks' => $banks->map(fn (QuizQuestionBank $bank): array => ['id' => (int) $bank->getKey(), 'name' => $bank->name, 'questions' => $bank->questions->map(function ($question) use ($membership): ?array {
                $latestVersion = $question->versions->first();
                $version = $question->versions->firstWhere('version_number', $question->current_version) ?? $latestVersion;
                if (! $version || ! $latestVersion) {
                    return null;
                }

                return [
                    'id' => (int) $version->getKey(), 'questionId' => (int) $question->getKey(),
                    'status' => $version->status, 'type' => $version->question_type,
                    'prompt' => $version->content['prompt'] ?? $version->content['passage'] ?? 'Question',
                    'content' => $version->content, 'difficulty' => $version->difficulty,
                    'answerKey' => ($membership->hasPermission('assessment.bank.manage') || $membership->hasPermission('assessment.bank.review')) ? $version->answer_key : null,
                    'scoringRules' => ($membership->hasPermission('assessment.bank.manage') || $membership->hasPermission('assessment.bank.review')) ? $version->scoring_rules : [],
                    'defaultPoints' => (string) $version->default_points,
                    'estimatedSeconds' => (int) $version->estimated_seconds,
                    'latestStatus' => $latestVersion->status,
                    'latestVersionId' => (int) $latestVersion->getKey(),
                    'latestContent' => $latestVersion->content,
                    'latestPrompt' => $latestVersion->content['prompt'] ?? $latestVersion->content['passage'] ?? 'Question',
                    'latestType' => $latestVersion->question_type,
                    'hasPendingRevision' => (int) $latestVersion->getKey() !== (int) $version->getKey(),
                ];
            })->filter()->values()])->values(),
            'policy' => $policy ? ['settings' => $policy->settings, 'lockVersion' => (int) $policy->lock_version, 'campusId' => $policy->campus_id] : ['settings' => ['maximum_attempts' => 3, 'maximum_cooldown_minutes' => 0, 'require_independent_review' => true, 'require_independent_publisher' => true, 'default_feedback_release' => 'manual', 'default_result_visibility' => 'score_only'], 'lockVersion' => 0, 'campusId' => $membership->campus_id],
            'permissions' => ['manage' => $membership->hasPermission('assessment.quiz.manage'), 'manageAll' => $membership->hasPermission('assessment.quiz.manage-all'), 'bankManage' => $membership->hasPermission('assessment.bank.manage'), 'bankShare' => $membership->hasPermission('assessment.bank.share'), 'policyManage' => $membership->hasPermission('assessment.policy.manage'), 'bankReview' => $membership->hasPermission('assessment.bank.review'), 'quizReview' => $membership->hasPermission('assessment.quiz.review'), 'publish' => $membership->hasPermission('assessment.quiz.publish'), 'grade' => $membership->hasPermission('assessment.grade.manage'), 'release' => $membership->hasPermission('assessment.results.release')],
        ]);
    }

    public function storeBank(Request $request, TenantContext $context, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'], 'campus_id' => ['nullable', 'integer'], 'academic_course_id' => ['nullable', 'integer'], 'sharing_scope' => ['required', 'in:campus,institution']]);
        $data['campus_id'] ??= $context->membership()->campus_id;
        $workflow->createBank($data, $request->user());

        return back()->with('success', 'Question bank created.');
    }

    public function storeQuestion(Request $request, Tenant $tenant, int $bankId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['question_type' => ['required', 'string', 'max:32'], 'content' => ['required', 'array'], 'answer_key' => ['nullable', 'array'], 'scoring_rules' => ['nullable', 'array'], 'default_points' => ['required', 'string', 'max:12'], 'topic' => ['nullable', 'string', 'max:120'], 'difficulty' => ['required', 'in:easy,moderate,challenging'], 'estimated_seconds' => ['required', 'integer', 'min:1', 'max:3600'], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:48']]);
        $workflow->createQuestion($bankId, $data, $request->user());

        return back()->with('success', 'Question saved as a draft.');
    }

    public function reviseQuestion(Request $request, Tenant $tenant, int $questionId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['question_type' => ['required', 'string', 'max:32'], 'content' => ['required', 'array'], 'answer_key' => ['nullable', 'array'], 'scoring_rules' => ['nullable', 'array'], 'default_points' => ['nullable', 'string', 'max:12'], 'topic' => ['nullable', 'string', 'max:120'], 'difficulty' => ['nullable', 'in:easy,moderate,challenging'], 'estimated_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:48']]);
        $workflow->reviseQuestion($questionId, $data, $request->user());

        return back()->with('success', 'An immutable draft revision was created for independent review.');
    }

    public function questionReview(Request $request, Tenant $tenant, int $questionId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $workflow->submitQuestionForReview($questionId, $request->user());

        return back()->with('success', 'Question submitted for independent review.');
    }

    public function questionDecision(Request $request, Tenant $tenant, int $questionId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $workflow->reviewQuestion($questionId, $request->user(), $data['decision'] === 'approve');

        return back()->with('success', 'Question review recorded.');
    }

    public function storeQuiz(Request $request, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['offering_id' => ['required', 'integer'], 'section_ids' => ['required', 'array', 'min:1', 'max:50'], 'section_ids.*' => ['integer'], 'questions' => ['required', 'array', 'min:1', 'max:100'], 'questions.*.question_version_id' => ['required', 'integer'], 'questions.*.points' => ['required', 'numeric', 'min:0.01', 'max:10000'], 'title' => ['required', 'string', 'max:200'], 'category' => ['nullable', 'string', 'max:48'], 'description' => ['nullable', 'string', 'max:4000'], 'instructions' => ['required', 'string', 'max:8000'], 'mode' => ['required', 'in:practice,self_check,graded,instructor_led,lesson_check'], 'available_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date'], 'time_limit_minutes' => ['nullable', 'integer', 'min:0', 'max:240'], 'maximum_attempts' => ['required', 'integer', 'min:1', 'max:10'], 'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'], 'pass_threshold' => ['nullable', 'integer', 'min:0', 'max:100'], 'grading_policy' => ['required', 'in:best,latest,average'], 'feedback_release' => ['required', 'in:immediate,after_manual,after_close,manual'], 'result_visibility' => ['required', 'in:score_only,score_and_feedback'], 'shuffle_questions' => ['sometimes', 'boolean'], 'shuffle_options' => ['sometimes', 'boolean'], 'navigation' => ['required', 'in:free,sequential']]);
        $quiz = $workflow->createQuiz($data, $request->user());

        return back()->with('success', 'Quiz draft created: '.$quiz->getKey());
    }

    public function storePool(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'pool_key' => ['required', 'string', 'max:64'], 'question_version_ids' => ['required', 'array', 'min:1', 'max:100'],
            'question_version_ids.*' => ['integer', 'distinct'], 'select_count' => ['required', 'integer', 'min:1', 'max:50'],
            'points_per_question' => ['required', 'numeric', 'gt:0', 'max:10000'], 'topic' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['nullable', 'in:easy,moderate,challenging'], 'required' => ['required', 'boolean'],
        ]);
        $workflow->addQuestionPool($quizId, $data['question_version_ids'], $data['pool_key'], (int) $data['select_count'], (string) $data['points_per_question'], $data['topic'] ?? null, $data['difficulty'] ?? null, (bool) $data['required'], $request->user());

        return back()->with('status', 'Randomized question pool saved to the draft.');
    }

    public function accommodate(Request $request, Tenant $tenant, int $quizId, int $membershipId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['extra_time_seconds' => ['required', 'integer', 'min:0', 'max:86400'], 'availability_ends_at' => ['nullable', 'date'], 'response_format' => ['nullable', 'in:standard,text_only,large_text'], 'confidential_reason' => ['nullable', 'string', 'max:1000']]);
        $workflow->saveAccommodation($quizId, $membershipId, $data, $request->user());

        return back()->with('success', 'Confidential assessment accommodation saved.');
    }

    public function grading(Request $request, QuizAssessmentQueries $queries): Response
    {
        return Inertia::render('workspace/assessments/grading', ['responses' => $queries->gradingQueue($request->user()), 'tenant' => ['slug' => $request->route('tenant')->slug]]);
    }

    public function regrades(Request $request, QuizAssessmentQueries $queries): Response
    {
        return Inertia::render('workspace/assessments/regrades', ['requests' => $queries->regradeQueue($request->user()), 'tenant' => ['slug' => $request->route('tenant')->slug]]);
    }

    public function analytics(Request $request, QuizAssessmentQueries $queries): Response
    {
        $filters = $request->validate(['quiz_id' => ['nullable', 'integer'], 'section_id' => ['nullable', 'integer']]);

        return Inertia::render('workspace/assessments/analytics', ['report' => $queries->analytics($request->user(), isset($filters['quiz_id']) ? (int) $filters['quiz_id'] : null, isset($filters['section_id']) ? (int) $filters['section_id'] : null), 'tenant' => ['slug' => $request->route('tenant')->slug]]);
    }

    public function notifications(Request $request, QuizAssessmentQueries $queries): Response
    {
        return Inertia::render('workspace/assessments/notifications', ['notifications' => $queries->notifications($request->user()), 'tenant' => ['slug' => $request->route('tenant')->slug]]);
    }

    public function markNotificationRead(Request $request, Tenant $tenant, int $notificationId, QuizAssessmentQueries $queries): RedirectResponse
    {
        $queries->markNotificationRead($notificationId, $request->user());

        return back()->with('success', 'Notification marked as read.');
    }

    public function markForReview(Request $request, Tenant $tenant, int $attemptId, int $questionVersionId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['marked' => ['required', 'boolean']]);
        $workflow->markForReview($attemptId, $questionVersionId, (bool) $data['marked'], $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attemptId]);
    }

    public function quizReview(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $workflow->submitQuizForReview($quizId, $request->user());

        return back()->with('success', 'Quiz submitted for independent review.');
    }

    public function quizDecision(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $workflow->reviewQuiz($quizId, $request->user(), $data['decision'] === 'approve');

        return back()->with('success', 'Quiz review recorded.');
    }

    public function publish(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $workflow->publishQuiz($quizId, $request->user());

        return back()->with('success', 'Approved quiz scheduled or published.');
    }

    public function close(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $workflow->closeQuiz($quizId, $request->user());

        return back()->with('success', 'Quiz closed. Existing attempts may still be submitted before archive.');
    }

    public function archive(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $workflow->archiveQuiz($quizId, $request->user());

        return back()->with('success', 'Quiz archived after pending work was resolved.');
    }

    public function policy(Request $request, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'campus_id' => ['nullable', 'integer'], 'settings' => ['required', 'array'], 'settings.maximum_attempts' => ['required', 'integer', 'min:1', 'max:10'], 'settings.maximum_cooldown_minutes' => ['required', 'integer', 'min:0', 'max:43200'], 'settings.require_independent_review' => ['required', 'boolean'], 'settings.require_independent_publisher' => ['required', 'boolean'], 'settings.default_feedback_release' => ['required', 'in:immediate,after_manual,after_close,manual'], 'settings.default_result_visibility' => ['required', 'in:score_only,score_and_feedback']]);
        $workflow->savePolicy($data['settings'], isset($data['campus_id']) ? (int) $data['campus_id'] : null, (int) $data['expected_version'], $request->user());

        return back()->with('success', 'Assessment policy saved.');
    }

    public function start(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $attempt = $workflow->startAttempt($quizId, $data['idempotency_key'], $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attempt->getKey()]);
    }

    public function attempt(Request $request, Tenant $tenant, int $attemptId, QuizAssessmentWorkflow $workflow, QuizArtifactWorkflow $artifacts, ExamDeliveryWorkflow $exams): Response
    {
        $attempt = $workflow->learnerAttempt($attemptId, $request->user());
        $artifacts->attachLearnerDownloadUrls($attempt);
        $binding = $exams->bindingForAttempt($attemptId);
        abort_if($binding && ((int) $binding->candidate->student_id !== (int) $attempt->student_id), 404);

        return Inertia::render('workspace/assessments/attempt', [
            'tenant' => ['slug' => $tenant->slug], 'attempt' => $attempt, 'questionAssets' => $artifacts->learnerAssets($attempt),
            'exam' => $binding ? [
                'title' => $binding->session->title, 'course' => $binding->session->course->title,
                'instructions' => $binding->session->delivery?->paperVersion?->instructions,
                'endsAt' => Carbon::parse($binding->session->ends_at_utc)->toIso8601String(), 'receiptReference' => $binding->receipt_reference,
            ] : null,
        ]);
    }

    public function uploadQuestionAsset(Request $request, Tenant $tenant, int $questionVersionId, QuizArtifactWorkflow $artifacts): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,txt,doc,docx,odt', 'max:10240']]);
        $artifact = $artifacts->uploadQuestionAsset($questionVersionId, $data['file'], $request->user());

        return back()->with($artifact->scan_status === 'clean' ? 'success' : 'error', $artifact->scan_status === 'clean'
            ? 'Question asset passed scanning and is attached to the draft version.'
            : 'The upload remains private and unavailable because scanning did not approve it.');
    }

    public function uploadFileResponse(Request $request, Tenant $tenant, int $attemptId, int $questionVersionId, QuizArtifactWorkflow $artifacts): RedirectResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,txt,doc,docx,odt', 'max:10240'],
        ]);
        $artifact = $artifacts->uploadLearnerResponse($attemptId, $questionVersionId, $data['file'], (int) $data['expected_version'], $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attemptId])->with($artifact->scan_status === 'clean' ? 'success' : 'error', $artifact->scan_status === 'clean'
            ? 'Your file response passed scanning and was saved.'
            : 'Your file remains quarantined and was not submitted because scanning did not approve it.');
    }

    public function downloadArtifact(Request $request, Tenant $tenant, int $artifactId, QuizArtifactWorkflow $artifacts): mixed
    {
        [$path, $name, $mime] = $artifacts->authorizedDownload($artifactId, $request->user());
        $inline = str_starts_with($mime, 'image/');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function response(Request $request, Tenant $tenant, int $attemptId, int $questionVersionId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'response' => ['required', 'array']]);
        $workflow->saveResponse($attemptId, $questionVersionId, $data['response'], (int) $data['expected_version'], $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attemptId])->with('success', 'Response saved.');
    }

    public function submit(Request $request, Tenant $tenant, int $attemptId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $attempt = $workflow->submitAttempt($attemptId, $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attempt->getKey()])->with('success', 'Attempt submitted.');
    }

    public function manualGrade(Request $request, Tenant $tenant, int $responseId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['points' => ['required', 'numeric', 'min:0', 'max:100000'], 'feedback' => ['nullable', 'string', 'max:4000'], 'private_notes' => ['nullable', 'string', 'max:4000'], 'expected_revision' => ['required', 'integer', 'min:0']]);
        $workflow->manualGrade($responseId, (string) $data['points'], $data['feedback'] ?? '', $data['private_notes'] ?? '', (int) $data['expected_revision'], $request->user());

        return back()->with('success', 'Grade saved to revision history.');
    }

    public function release(Request $request, Tenant $tenant, int $quizId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $count = $workflow->releaseResults($quizId, $request->user());

        return back()->with('success', "Released {$count} graded result(s).");
    }

    public function requestRegrade(Request $request, Tenant $tenant, int $attemptId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $workflow->requestRegrade($attemptId, $data['reason'], $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attemptId])->with('success', 'Regrade request recorded.');
    }

    public function decideRegrade(Request $request, Tenant $tenant, int $requestId, QuizAssessmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $workflow->decideRegrade($requestId, $data['decision'] === 'approve', $data['notes'] ?? '', $request->user());

        return back()->with('success', 'Regrade request decision recorded.');
    }
}
