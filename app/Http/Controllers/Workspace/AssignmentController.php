<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Assignments\AssignmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Assignment;
use App\Models\AssignmentArtifact;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentExtension;
use App\Models\AssignmentGrade;
use App\Models\AssignmentGroup;
use App\Models\AssignmentIntegrityCase;
use App\Models\AssignmentRecipient;
use App\Models\AssignmentRubric;
use App\Models\AssignmentRubricVersion;
use App\Models\AssignmentSubmission;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssignmentController
{
    public function __construct(private readonly TenantContext $context, private readonly AssignmentWorkflow $workflow) {}

    public function index(Request $request, Tenant $tenant): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.view'), 403);
        $query = Assignment::query()->with('offering.course')->where('institution_id', $membership->institution_id);
        if (! $membership->hasPermission('enrollment.registrar.manage') && ! $membership->hasPermission('tenant.view')) {
            $sectionIds = \DB::table('course_section_instructors')->where('tenant_id', $tenant->getKey())->where('user_id', $request->user()->getKey())->pluck('section_id');
            $query->whereHas('sections', fn ($sections) => $sections->whereIn('course_sections.id', $sectionIds));
        }
        $assignments = $query->withCount(['recipients', 'submissions'])->orderByDesc('updated_at')->paginate(25)->through(fn (Assignment $assignment): array => [
            'id' => (int) $assignment->getKey(), 'title' => $assignment->title, 'course' => $assignment->offering->course->title,
            'type' => $assignment->assignment_type, 'collaboration_mode' => $assignment->collaboration_mode, 'status' => $assignment->status, 'due_at' => $assignment->due_at?->toIso8601String(),
            'published_at' => $assignment->published_at?->toIso8601String(), 'recipients_count' => (int) $assignment->recipients_count,
            'submissions_count' => (int) $assignment->submissions_count,
        ]);

        return Inertia::render('workspace/assignments/instructor-index', [
            'tenant' => ['slug' => $tenant->slug], 'assignments' => $assignments,
            'metrics' => [
                'drafts' => (clone $query)->where('status', 'draft')->count(),
                'published' => Assignment::query()->where('institution_id', $membership->institution_id)->where('status', 'published')->count(),
                'pending_grading' => AssignmentSubmission::query()->where('status', 'submitted')->whereHas('assignment', fn ($q) => $q->where('institution_id', $membership->institution_id))->count(),
            ],
            'permissions' => ['manage' => $membership->hasPermission('assignments.manage'), 'publish' => $membership->hasPermission('assignments.publish'), 'review' => $membership->hasPermission('assignments.review'), 'rubrics' => $membership->hasPermission('assignments.rubrics.manage'), 'reports' => $membership->hasPermission('assignments.report.view')],
        ]);
    }

    public function create(Request $request, Tenant $tenant): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.manage'), 403);
        $offerings = CourseOffering::query()->with(['course', 'sections'])->where('institution_id', $membership->institution_id)->whereIn('status', ['open', 'published'])->orderBy('offering_code')->get();
        if (! $membership->hasPermission('enrollment.registrar.manage') && ! $membership->hasPermission('tenant.view')) {
            $assignedIds = \DB::table('course_section_instructors')->where('tenant_id', $tenant->getKey())->where('user_id', $request->user()->getKey())->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
            $offerings->each(fn (CourseOffering $offering) => $offering->setRelation('sections', $offering->sections->whereIn('id', $assignedIds)->values()));
        }

        return Inertia::render('workspace/assignments/create', [
            'tenant' => ['slug' => $tenant->slug],
            'offerings' => $offerings->map(fn (CourseOffering $offering): array => ['id' => (int) $offering->getKey(), 'code' => $offering->offering_code, 'course' => $offering->course->title, 'timezone' => $offering->institution_timezone, 'sections' => $offering->sections->map(fn (CourseSection $section): array => ['id' => (int) $section->getKey(), 'code' => $section->section_code, 'campus_id' => $section->campus_id])->all()])->all(),
            'rubrics' => AssignmentRubricVersion::query()->with(['rubric', 'criteria'])->where('status', 'published')->whereHas('rubric', fn ($query) => $query->where('institution_id', $membership->institution_id))->orderByDesc('published_at')->get()->map(static fn (AssignmentRubricVersion $version): array => ['id' => (int) $version->getKey(), 'name' => $version->rubric->name, 'version' => (int) $version->version_number, 'maximum_points' => (float) $version->maximum_points])->all(),
            'types' => ['homework', 'coursework', 'essay', 'research', 'project', 'practical', 'presentation', 'portfolio', 'reflection', 'file_submission'],
        ]);
    }

    public function rubrics(Tenant $tenant): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.rubrics.manage'), 403);
        $rubrics = AssignmentRubric::query()->with(['versions.criteria'])->where('institution_id', $membership->institution_id)->orderBy('name')->get()->map(static fn (AssignmentRubric $rubric): array => [
            'id' => (int) $rubric->getKey(), 'name' => $rubric->name, 'versions' => $rubric->versions->map(static fn (AssignmentRubricVersion $version): array => [
                'id' => (int) $version->getKey(), 'number' => (int) $version->version_number, 'status' => $version->status, 'maximum_points' => (float) $version->maximum_points,
                'criteria' => $version->criteria->map(static fn ($criterion): array => ['id' => (int) $criterion->getKey(), 'title' => $criterion->title, 'maximum_points' => (float) $criterion->maximum_points])->all(),
            ])->all(),
        ])->all();

        return Inertia::render('workspace/assignments/rubrics', ['tenant' => ['slug' => $tenant->slug], 'rubrics' => $rubrics]);
    }

    public function storeRubric(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:160'], 'maximum_points' => ['required', 'numeric', 'gt:0', 'max:999999.99'], 'criteria' => ['required', 'array', 'min:1', 'max:20'], 'criteria.*.title' => ['required', 'string', 'min:2', 'max:160'], 'criteria.*.description' => ['nullable', 'string', 'max:2000'], 'criteria.*.maximum_points' => ['required', 'numeric', 'gt:0', 'max:999999.99']]);
        $this->workflow->createRubric($data, $request->user());

        return back()->with('status', 'Rubric draft created. Review its point totals, then publish the version for assignment use.');
    }

    public function reviseRubric(Request $request, Tenant $tenant, int $rubricId): RedirectResponse
    {
        $data = $request->validate(['maximum_points' => ['required', 'numeric', 'gt:0', 'max:999999.99'], 'criteria' => ['required', 'array', 'min:1', 'max:20'], 'criteria.*.title' => ['required', 'string', 'min:2', 'max:160'], 'criteria.*.description' => ['nullable', 'string', 'max:2000'], 'criteria.*.maximum_points' => ['required', 'numeric', 'gt:0', 'max:999999.99']]);
        $this->workflow->reviseRubric(AssignmentRubric::query()->findOrFail($rubricId), $data, $request->user());

        return back()->with('status', 'A new immutable rubric draft version is ready for review.');
    }

    public function publishRubric(Request $request, Tenant $tenant, int $versionId): RedirectResponse
    {
        $this->workflow->publishRubricVersion(AssignmentRubricVersion::query()->findOrFail($versionId), $request->user());

        return back()->with('status', 'Rubric version published and available for assignment authoring.');
    }

    public function analytics(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless($this->context->membership()->hasPermission('assignments.report.view'), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:draft,under_review,approved,scheduled,published,closed,archived'], 'due_from' => ['nullable', 'date'], 'due_to' => ['nullable', 'date', 'after_or_equal:due_from']]);
        $query = $this->filteredAssignmentQuery($request, $tenant, $filters);
        $ids = (clone $query)->select('assignments.id');
        $assignmentIds = (clone $query)->select('assignments.id');
        $metrics = [
            'assignments' => (clone $query)->count(),
            'eligible_learners' => AssignmentRecipient::query()->whereIn('assignment_id', $assignmentIds)->where('status', 'eligible')->count(),
            'submissions' => AssignmentSubmission::query()->whereIn('assignment_id', $ids)->whereIn('status', ['submitted', 'under_review', 'graded', 'returned_for_revision', 'finalized'])->count(),
            'awaiting_marking' => AssignmentSubmission::query()->whereIn('assignment_id', $ids)->where('status', 'submitted')->count(),
            'late_attempts' => AssignmentAttempt::query()->where('is_late', true)->whereHas('submission', fn ($submissions) => $submissions->whereIn('assignment_id', (clone $query)->select('assignments.id')))->count(),
            'released_average' => round((float) (AssignmentGrade::query()->where('status', 'released')->whereHas('attempt.submission', fn ($submissions) => $submissions->whereIn('assignment_id', (clone $query)->select('assignments.id')))->avg('score_points') ?? 0), 2),
        ];
        $assignments = $query->with('offering.course')->withCount(['recipients', 'submissions'])->orderByDesc('updated_at')->paginate(25)->withQueryString()->through(static fn (Assignment $assignment): array => [
            'id' => (int) $assignment->getKey(), 'title' => $assignment->title, 'course' => $assignment->offering->course->title,
            'status' => $assignment->status, 'due_at' => $assignment->due_at?->toIso8601String(),
            'eligible' => (int) $assignment->recipients_count, 'submissions' => (int) $assignment->submissions_count,
        ]);

        return Inertia::render('workspace/assignments/analytics', ['tenant' => ['slug' => $tenant->slug], 'metrics' => $metrics, 'assignments' => $assignments, 'filters' => ['status' => $filters['status'] ?? '', 'due_from' => $filters['due_from'] ?? '', 'due_to' => $filters['due_to'] ?? '']]);
    }

    public function exportAnalytics(Request $request, Tenant $tenant): StreamedResponse
    {
        abort_unless($this->context->membership()->hasPermission('assignments.report.view'), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:draft,under_review,approved,scheduled,published,closed,archived'], 'due_from' => ['nullable', 'date'], 'due_to' => ['nullable', 'date', 'after_or_equal:due_from']]);
        $query = $this->filteredAssignmentQuery($request, $tenant, $filters)->with('offering.course')->withCount(['recipients', 'submissions'])->orderBy('assignments.id');

        return response()->streamDownload(static function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            if (! is_resource($stream)) {
                return;
            }
            fputcsv($stream, ['Assignment', 'Course', 'Status', 'Due at', 'Eligible learners', 'Submissions']);
            $query->chunkById(500, static function ($assignments) use ($stream): void {
                foreach ($assignments as $assignment) {
                    $cells = [$assignment->title, $assignment->offering->course->title, $assignment->status, $assignment->due_at?->toIso8601String(), (string) $assignment->recipients_count, (string) $assignment->submissions_count];
                    fputcsv($stream, array_map(static fn ($value): string => self::safeCsvCell((string) ($value ?? '')), $cells));
                }
            }, 'id', 'id');
            fclose($stream);
        }, 'assignment-analytics.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<string,mixed> $filters
     * @return Builder<Assignment>
     */
    private function filteredAssignmentQuery(Request $request, Tenant $tenant, array $filters): Builder
    {
        $membership = $this->context->membership();
        $query = Assignment::query()->where('institution_id', $membership->institution_id);
        if (! $membership->hasPermission('enrollment.registrar.manage') && ! $membership->hasPermission('tenant.view')) {
            $sectionIds = \DB::table('course_section_instructors')->where('tenant_id', $tenant->getKey())->where('user_id', $request->user()->getKey())->pluck('section_id');
            $query->whereHas('sections', fn ($sections) => $sections->whereIn('course_sections.id', $sectionIds));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['due_from'] ?? null)) {
            $query->whereDate('due_at', '>=', $filters['due_from']);
        }
        if (filled($filters['due_to'] ?? null)) {
            $query->whereDate('due_at', '<=', $filters['due_to']);
        }

        return $query;
    }

    private static function safeCsvCell(string $value): string
    {
        return preg_match('/^\s*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'offering_id' => ['required', 'integer'], 'section_ids' => ['required', 'array', 'min:1', 'max:50'], 'section_ids.*' => ['required', 'integer', 'distinct'],
            'title' => ['required', 'string', 'max:200'], 'assignment_type' => ['required', 'in:homework,coursework,essay,research,project,practical,presentation,portfolio,reflection,file_submission'],
            'category' => ['nullable', 'string', 'max:100'], 'summary' => ['nullable', 'string', 'max:1000'], 'instructions' => ['nullable', 'string', 'max:20000'],
            'submission_mode' => ['required', 'in:text,url,mixed,file'], 'collaboration_mode' => ['sometimes', 'in:individual,group'], 'maximum_attempts' => ['required', 'integer', 'between:1,20'],
            'maximum_points' => ['nullable', 'numeric', 'min:0', 'max:999999.99'], 'rubric_version_id' => ['nullable', 'integer'], 'timezone' => ['required', 'timezone'],
            'available_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date'], 'late_until' => ['nullable', 'date'], 'review_required' => ['sometimes', 'boolean'],
        ]);
        $assignment = $this->workflow->createDraft($data, $request->user());

        return redirect()->route('tenant.assignments.index', $tenant)->with('status', 'Draft assignment created.');
    }

    public function copy(Request $request, Tenant $tenant, int $assignmentId): RedirectResponse
    {
        $assignment = Assignment::query()->findOrFail($assignmentId);
        $copy = $this->workflow->cloneAsDraft($assignment, $request->user());

        return redirect()->route('tenant.assignments.index', $tenant)->with('status', 'Assignment copied as draft with its original schedule cleared.')->with('copied_assignment_id', $copy->getKey());
    }

    public function submitForReview(Request $request, Tenant $tenant, int $assignmentId): RedirectResponse
    {
        $this->workflow->sendForReview(Assignment::query()->findOrFail($assignmentId), $request->user());

        return back()->with('status', 'Assignment sent into its configured publication workflow.');
    }

    public function review(Request $request, Tenant $tenant, int $assignmentId): RedirectResponse
    {
        $assignment = Assignment::query()->findOrFail($assignmentId);
        $this->workflow->decideReview($assignment, $request->user(), $request->validate(['decision' => ['required', 'in:approve,return'], 'reason' => ['nullable', 'string', 'max:1000']])['decision'] === 'approve', $request->input('reason'));

        return back()->with('status', 'Assignment review recorded.');
    }

    public function publish(Request $request, Tenant $tenant, int $assignmentId): RedirectResponse
    {
        $this->workflow->publish(Assignment::query()->findOrFail($assignmentId), $request->user());

        return back()->with('status', 'Assignment published or scheduled.');
    }

    public function groups(Request $request, Tenant $tenant, int $assignmentId): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.groups.manage'), 403);
        $assignment = Assignment::query()->with('sections')->findOrFail($assignmentId);
        abort_unless($assignment->collaboration_mode === 'group', 404);
        $sectionIds = $assignment->sections->map(static fn (CourseSection $section): int => (int) $section->getKey())->all();
        $this->workflow->assertInstructorScope($sectionIds, $request->user());
        $sections = $assignment->sections->map(function (CourseSection $section) use ($assignment): array {
            $learners = AssignmentRecipient::query()->with('membership.user')->where('assignment_id', $assignment->getKey())->where('section_id', $section->getKey())->where('status', 'eligible')->get()->map(static fn (AssignmentRecipient $recipient): array => [
                'membership_id' => (int) $recipient->membership_id,
                'name' => $recipient->membership->user->name,
                'student_id' => (int) $recipient->student_id,
            ])->all();

            return ['id' => (int) $section->getKey(), 'code' => $section->section_code, 'learners' => $learners];
        })->all();
        $groups = AssignmentGroup::query()->with('members.membership.user')->where('assignment_id', $assignment->getKey())->orderBy('name')->get()->map(static fn (AssignmentGroup $group): array => [
            'id' => (int) $group->getKey(), 'name' => $group->name, 'section_id' => (int) $group->section_id,
            'frozen' => $group->frozen_at !== null, 'members' => $group->members->map(static fn ($member): array => ['id' => (int) $member->membership_id, 'name' => $member->membership->user->name])->all(),
        ])->all();

        return Inertia::render('workspace/assignments/groups', ['tenant' => ['slug' => $tenant->slug], 'assignment' => ['id' => (int) $assignment->getKey(), 'title' => $assignment->title], 'sections' => $sections, 'groups' => $groups]);
    }

    public function storeGroup(Request $request, Tenant $tenant, int $assignmentId): RedirectResponse
    {
        $data = $request->validate(['section_id' => ['required', 'integer'], 'name' => ['required', 'string', 'min:2', 'max:120'], 'membership_ids' => ['required', 'array', 'min:2', 'max:8'], 'membership_ids.*' => ['required', 'integer', 'distinct'], 'leader_membership_id' => ['nullable', 'integer']]);
        $assignment = Assignment::query()->findOrFail($assignmentId);
        $this->workflow->createGroup($assignment, $request->user(), (int) $data['section_id'], $data['name'], array_map('intval', $data['membership_ids']), isset($data['leader_membership_id']) ? (int) $data['leader_membership_id'] : null);

        return back()->with('status', 'Group membership has been recorded for this assignment section.');
    }

    public function extensions(Request $request, Tenant $tenant): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.extension.review'), 403);
        $query = AssignmentExtension::query()->with(['recipient.assignment.offering.course', 'recipient.student'])->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('created_at');
        if (! $membership->hasPermission('tenant.view') && ! $membership->hasPermission('enrollment.registrar.manage')) {
            $sectionIds = \DB::table('course_section_instructors')->where('tenant_id', $tenant->getKey())->where('user_id', $request->user()->getKey())->pluck('section_id');
            $query->whereHas('recipient', fn ($recipients) => $recipients->whereIn('section_id', $sectionIds));
        }
        $extensions = $query->paginate(30)->through(static fn (AssignmentExtension $extension): array => [
            'id' => (int) $extension->getKey(), 'status' => $extension->status, 'reason' => $extension->reason,
            'requested_due_at' => $extension->requested_due_at->toIso8601String(), 'previous_due_at' => $extension->previous_due_at?->toIso8601String(),
            'effective_due_at' => $extension->effective_due_at?->toIso8601String(), 'student' => trim($extension->recipient->student->legal_first_name.' '.$extension->recipient->student->legal_last_name),
            'assignment' => $extension->recipient->assignment->title, 'course' => $extension->recipient->assignment->offering->course->title,
        ]);

        return Inertia::render('workspace/assignments/extensions', ['tenant' => ['slug' => $tenant->slug], 'extensions' => $extensions]);
    }

    public function mine(Tenant $tenant): InertiaResponse
    {
        $data = $this->workflow->studentWorkspace($this->context->membership());

        return Inertia::render('workspace/assignments/student-index', ['tenant' => ['slug' => $tenant->slug], ...$data]);
    }

    public function showStudent(Tenant $tenant, int $recipientId): InertiaResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.view'), 403);
        $recipient = AssignmentRecipient::query()->with(['assignment.offering.course', 'submission.attempts.artifacts', 'submission.attempts.grades', 'submission.attempts.integrityCases'])->where('membership_id', $membership->getKey())->findOrFail($recipientId);
        abort_unless($recipient->status === 'eligible' && in_array($recipient->assignment->status, ['published', 'closed'], true), 404);
        $assignment = $recipient->assignment;
        $submission = $this->workflow->submissionForRecipient($recipient, $membership);
        $submission?->load(['attempts.artifacts', 'attempts.grades', 'attempts.integrityCases']);
        $attempt = $submission?->attempts->first();
        $grade = $attempt?->grades->first();

        return Inertia::render('workspace/assignments/student-show', [
            'tenant' => ['slug' => $tenant->slug], 'recipient' => ['id' => (int) $recipient->getKey(), 'due_at' => $recipient->effective_due_at?->toIso8601String() ?? $assignment->due_at?->toIso8601String()],
            'assignment' => ['id' => (int) $assignment->getKey(), 'title' => $assignment->title, 'course' => $assignment->offering->course->title, 'type' => $assignment->assignment_type, 'summary' => $assignment->summary, 'instructions' => $assignment->instructions, 'submission_mode' => $assignment->submission_mode, 'maximum_points' => $assignment->maximum_points, 'maximum_attempts' => (int) $assignment->maximum_attempts, 'timezone' => $assignment->timezone, 'version' => (int) $assignment->current_version],
            'submission' => ['id' => $submission?->getKey(), 'status' => $submission === null ? 'not_started' : $submission->status, 'attempt_count' => $submission === null ? 0 : (int) $submission->attempt_count, 'feedback' => in_array($grade?->status, ['released', 'returned_for_revision'], true) ? $grade->learner_feedback : null, 'released_score' => $grade?->status === 'released' ? (float) $grade->score_points : null, 'integrity_cases' => $attempt?->integrityCases->map(static fn (AssignmentIntegrityCase $case): array => ['id' => (int) $case->getKey(), 'status' => $case->status, 'case_type' => $case->case_type, 'concern_summary' => $case->concern_summary, 'learner_response' => $case->learner_response, 'response_due_at' => $case->response_due_at?->toIso8601String(), 'disposition' => $case->disposition])->all() ?? [], 'attempt' => $attempt ? ['id' => (int) $attempt->getKey(), 'status' => $attempt->status, 'response_text' => $attempt->status === 'draft' ? $attempt->response_text : null, 'response_url' => $attempt->status === 'draft' ? $attempt->response_url : null, 'receipt_reference' => $attempt->receipt_reference, 'artifacts' => $attempt->artifacts->map(fn (AssignmentArtifact $artifact): array => ['id' => (int) $artifact->getKey(), 'name' => $artifact->original_name, 'scan_status' => $artifact->scan_status, 'download_url' => $artifact->scan_status === 'clean' ? route('tenant.assignments.mine.artifacts.download', [$tenant, $artifact->getKey()]) : null])->all()] : null],
            'can_submit' => $this->workflow->canSubmit($recipient, $submission),
        ]);
    }

    public function saveDraft(Request $request, Tenant $tenant, int $recipientId): RedirectResponse
    {
        $data = $request->validate(['response_text' => ['nullable', 'string', 'max:100000'], 'response_url' => ['nullable', 'url', 'max:2000'], 'idempotency_key' => ['nullable', 'string', 'max:80']]);
        $recipient = AssignmentRecipient::query()->findOrFail($recipientId);
        $attempt = $this->workflow->createOrUpdateDraft($recipient, $request->user(), $data, $data['idempotency_key'] ?? null);

        return back()->with('status', 'Draft saved.')->with('draft_attempt_id', $attempt->getKey());
    }

    public function submit(Request $request, Tenant $tenant, int $recipientId): RedirectResponse
    {
        $data = $request->validate(['attempt_id' => ['required', 'integer']]);
        $attempt = $this->workflow->submit(AssignmentRecipient::query()->findOrFail($recipientId), $request->user(), (int) $data['attempt_id']);

        return redirect()->route('tenant.assignments.mine.receipt', [$tenant, $attempt->receipt_reference])->with('status', 'Submission received.');
    }

    public function uploadArtifacts(Request $request, Tenant $tenant, int $recipientId): RedirectResponse
    {
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:5'], 'files.*' => ['required', 'file', 'mimes:pdf,doc,docx,odt,txt,png,jpg,jpeg', 'max:20480']]);
        $files = [];
        foreach ((array) $request->file('files', []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }
        abort_if(array_sum(array_map(static fn ($file): int => (int) $file->getSize(), $files)) > 50 * 1024 * 1024, 422, 'The combined upload must be no larger than 50 MB.');
        $this->workflow->attachArtifacts(AssignmentRecipient::query()->findOrFail($recipientId), $request->user(), $files);

        return back()->with('status', 'Files are stored privately in quarantine and cannot be submitted until scanning succeeds.');
    }

    public function downloadArtifact(Request $request, Tenant $tenant, int $artifactId): StreamedResponse
    {
        $artifact = AssignmentArtifact::query()->findOrFail($artifactId);
        $path = $this->workflow->authorizeArtifactDownload($artifact, $request->user());

        return Storage::disk('local')->download($path, $artifact->original_name, [
            'Content-Type' => $artifact->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function receipt(Tenant $tenant, string $reference): InertiaResponse
    {
        $membership = $this->context->membership();
        $attempt = AssignmentAttempt::query()->with(['submission.assignment.offering.course', 'submission.recipient.student'])->where('receipt_reference', $reference)->where('status', 'submitted')->firstOrFail();
        $recipient = $attempt->submission->recipient;
        $groupMemberIds = $attempt->receipt_snapshot['group_membership_ids'] ?? [];
        abort_unless(($recipient !== null && (int) $recipient->membership_id === (int) $membership->getKey()) || in_array((int) $membership->getKey(), array_map('intval', $groupMemberIds), true), 404);

        return Inertia::render('workspace/assignments/receipt', ['tenant' => ['slug' => $tenant->slug], 'receipt' => [
            'reference' => $attempt->receipt_reference, 'assignment' => $attempt->submission->assignment->title, 'course' => $attempt->submission->assignment->offering->course->title,
            'attempt' => (int) $attempt->attempt_number, 'submitted_at' => $attempt->submitted_at?->toIso8601String(), 'status' => $attempt->status,
            'late' => (bool) $attempt->is_late, 'file_count' => (int) ($attempt->receipt_snapshot['file_count'] ?? 0),
        ]]);
    }

    public function submissions(Request $request, Tenant $tenant, int $assignmentId): InertiaResponse
    {
        $assignment = Assignment::query()->with('offering.course')->findOrFail($assignmentId);
        $sections = \DB::table('assignment_sections')->where('tenant_id', $tenant->getKey())->where('assignment_id', $assignment->getKey())->pluck('section_id')->all();
        abort_unless($this->context->membership()->hasPermission('assignments.submission.view'), 403);
        $this->workflow->assertInstructorScope($sections, $request->user());
        $assignment->load('rubricVersion.criteria');
        $submissions = AssignmentSubmission::query()->with(['recipient.student', 'attempts.grades', 'attempts.artifacts', 'attempts.integrityCases'])->where('assignment_id', $assignment->getKey())->orderBy('status')->paginate(40)->through(fn (AssignmentSubmission $submission): array => [
            'id' => (int) $submission->getKey(), 'status' => $submission->status, 'student' => $submission->recipient?->student?->legal_first_name.' '.$submission->recipient?->student?->legal_last_name,
            'student_number' => $submission->recipient?->student?->student_number, 'attempts' => $submission->attempts->map(fn (AssignmentAttempt $attempt): array => [
                'id' => (int) $attempt->getKey(), 'number' => (int) $attempt->attempt_number, 'status' => $attempt->status,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(), 'late' => (bool) $attempt->is_late,
                'response_text' => $attempt->response_text, 'response_url' => $attempt->response_url,
                'artifacts' => $attempt->artifacts->map(fn (AssignmentArtifact $artifact): array => ['name' => $artifact->original_name, 'scan_status' => $artifact->scan_status, 'download_url' => $artifact->scan_status === 'clean' ? route('tenant.assignments.artifacts.download', [$tenant, $artifact->getKey()]) : null])->all(),
                'grade' => $attempt->grades->first() ? ['id' => (int) $attempt->grades->first()->getKey(), 'status' => $attempt->grades->first()->status, 'lock_version' => (int) $attempt->grades->first()->lock_version, 'score_points' => $attempt->grades->first()->score_points, 'criterion_scores' => $attempt->grades->first()->criterion_scores, 'learner_feedback' => $attempt->grades->first()->learner_feedback, 'private_notes' => $attempt->grades->first()->private_notes, 'marked_by' => (int) $attempt->grades->first()->marked_by] : null,
                'integrity_cases' => $attempt->integrityCases->map(static fn (AssignmentIntegrityCase $case): array => ['id' => (int) $case->getKey(), 'status' => $case->status, 'case_type' => $case->case_type, 'concern_summary' => $case->concern_summary, 'learner_response' => $case->learner_response, 'disposition' => $case->disposition])->all(),
            ])->all(),
        ]);

        return Inertia::render('workspace/assignments/submissions', ['tenant' => ['slug' => $tenant->slug], 'assignment' => ['id' => (int) $assignment->getKey(), 'title' => $assignment->title, 'course' => $assignment->offering->course->title, 'maximum_points' => $assignment->maximum_points, 'rubric_criteria' => $assignment->rubricVersion?->criteria->map(static fn ($criterion): array => ['id' => (int) $criterion->getKey(), 'title' => $criterion->title, 'maximum_points' => (float) $criterion->maximum_points])->all() ?? []], 'permissions' => ['grade' => $this->context->membership()->hasPermission('assignments.submission.grade'), 'release' => $this->context->membership()->hasPermission('assignments.grade.release'), 'integrity_report' => $this->context->membership()->hasPermission('assignments.integrity.report'), 'integrity_manage' => $this->context->membership()->hasPermission('assignments.integrity.manage'), 'actor_id' => (int) $request->user()->getKey()], 'submissions' => $submissions]);
    }

    public function grade(Request $request, Tenant $tenant, int $attemptId): RedirectResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'score_points' => ['nullable', 'numeric', 'min:0', 'max:999999.99'], 'criterion_scores' => ['sometimes', 'array'], 'criterion_scores.*' => ['required_with:criterion_scores', 'numeric', 'min:0', 'max:999999.99'], 'learner_feedback' => ['nullable', 'string', 'max:10000'], 'private_notes' => ['nullable', 'string', 'max:10000']]);
        if (! array_key_exists('score_points', $data) && ! array_key_exists('criterion_scores', $data)) {
            throw ValidationException::withMessages(['score_points' => 'Provide a points score or complete rubric criteria scores.']);
        }
        $grade = $this->workflow->grade(AssignmentAttempt::query()->findOrFail($attemptId), $request->user(), $data);

        $assignmentId = (int) $grade->attempt()->with('submission')->firstOrFail()->submission->assignment_id;

        return to_route('tenant.assignments.submissions', [$tenant, $assignmentId])
            ->with('status', 'Grade saved as an unreleased draft.')
            ->with('grade_id', $grade->getKey());
    }

    public function reportIntegrityConcern(Request $request, Tenant $tenant, int $attemptId): RedirectResponse
    {
        $data = $request->validate(['case_type' => ['required', 'in:authorship,collaboration,source_use,assessment_conduct,other'], 'concern_summary' => ['required', 'string', 'min:12', 'max:5000'], 'response_due_at' => ['nullable', 'date']]);
        $attempt = AssignmentAttempt::query()->findOrFail($attemptId);
        $dueAt = isset($data['response_due_at']) ? $this->workflow->parseLocalInstant($data['response_due_at'], $attempt->submission->assignment->timezone) : null;
        $this->workflow->reportIntegrityConcern($attempt, $request->user(), $data['case_type'], $data['concern_summary'], $dueAt);

        return to_route('tenant.assignments.submissions', [$tenant, (int) $attempt->submission->assignment_id])
            ->with('status', 'Integrity concern recorded for human review. No automated similarity result was generated.');
    }

    public function respondIntegrityConcern(Request $request, Tenant $tenant, int $caseId): RedirectResponse
    {
        $data = $request->validate(['response' => ['required', 'string', 'min:8', 'max:10000']]);
        $case = AssignmentIntegrityCase::query()->findOrFail($caseId);
        $attempt = $case->attempt()->with('submission')->firstOrFail();
        $this->workflow->respondToIntegrityConcern($case, $request->user(), $data['response']);
        $recipient = AssignmentRecipient::query()
            ->where('assignment_id', $attempt->submission->assignment_id)
            ->where('membership_id', $this->context->membership()->getKey())
            ->firstOrFail();

        return to_route('tenant.assignments.mine.show', [$tenant, $recipient->getKey()])
            ->with('status', 'Your response was submitted to the independent reviewer.');
    }

    public function decideIntegrityConcern(Request $request, Tenant $tenant, int $caseId): RedirectResponse
    {
        $data = $request->validate(['disposition' => ['required', 'in:substantiated,cleared,no_action'], 'review_notes' => ['required', 'string', 'min:12', 'max:10000']]);
        $case = AssignmentIntegrityCase::query()->findOrFail($caseId);
        $assignmentId = (int) $case->attempt()->with('submission')->firstOrFail()->submission->assignment_id;
        $this->workflow->decideIntegrityCase($case, $request->user(), $data['disposition'], $data['review_notes']);

        return to_route('tenant.assignments.submissions', [$tenant, $assignmentId])
            ->with('status', 'Human-reviewed integrity case decision recorded.');
    }

    public function returnForRevision(Request $request, Tenant $tenant, int $attemptId): RedirectResponse
    {
        $data = $request->validate(['learner_feedback' => ['required', 'string', 'min:8', 'max:10000']]);
        $this->workflow->returnForRevision(AssignmentAttempt::query()->findOrFail($attemptId), $request->user(), $data['learner_feedback']);

        return back()->with('status', 'The work was returned for revision with learner-visible feedback.');
    }

    public function releaseGrade(Request $request, Tenant $tenant, int $gradeId): RedirectResponse
    {
        $this->workflow->releaseGrade(AssignmentGrade::query()->findOrFail($gradeId), $request->user());

        return back()->with('status', 'Grade released to the learner.');
    }

    public function requestExtension(Request $request, Tenant $tenant, int $recipientId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:12', 'max:2000'], 'requested_due_at' => ['required', 'date']]);
        $recipient = AssignmentRecipient::query()->findOrFail($recipientId);
        $timezone = $recipient->assignment->timezone;
        $due = $this->workflow->parseLocalInstant($data['requested_due_at'], $timezone);
        $this->workflow->requestExtension($recipient, $request->user(), $data['reason'], $due);

        return back()->with('status', 'Extension request submitted.');
    }

    public function decideExtension(Request $request, Tenant $tenant, int $extensionId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'effective_due_at' => ['nullable', 'date']]);
        $extension = AssignmentExtension::query()->findOrFail($extensionId);
        $effective = isset($data['effective_due_at']) ? $this->workflow->parseLocalInstant($data['effective_due_at'], $extension->recipient->assignment->timezone) : null;
        $this->workflow->decideExtension($extension, $request->user(), $data['decision'] === 'approve', $effective);

        return back()->with('status', 'Extension decision recorded.');
    }

    public function revokeExtension(Request $request, Tenant $tenant, int $extensionId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:12', 'max:2000']]);
        $this->workflow->revokeExtension(AssignmentExtension::query()->findOrFail($extensionId), $request->user(), $data['reason']);

        return back()->with('status', 'The extension was revoked and the original deadline restored.');
    }
}
