<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Examinations\ExamPaperPublicationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamPaperReview;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\QuizQuestionVersion;
use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class ExaminationPaperController extends Controller
{
    public function storeVersion(Request $request, Tenant $tenant, int $sessionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $session = ExamSession::query()->with('cycle')->findOrFail($sessionId);
        $access->assertCampus((int) $session->campus_id);
        abort_unless(in_array($session->status, ['draft', 'published'], true) && Carbon::parse($session->starts_at_utc)->isFuture(), 409, 'Paper versions cannot be created after the examination session starts.');

        $data = $request->validate([
            'set_code' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9]+$/'],
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600', 'lte:'.$session->duration_minutes],
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.question_version_id' => ['required', 'integer', 'distinct', Rule::exists('quiz_question_versions', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
            'questions.*.points' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
        ]);

        $requestedIds = [];
        foreach ($data['questions'] as $questionRow) {
            $requestedIds[(int) $questionRow['question_version_id']] = true;
        }
        if (count($requestedIds) !== count($data['questions'])) {
            throw ValidationException::withMessages(['questions' => 'Question versions may appear only once in a paper.']);
        }
        $versions = QuizQuestionVersion::query()->with('question.bank')->where('status', 'active')->whereIn('id', array_keys($requestedIds))->get()->keyBy('id');
        if ($versions->count() !== count($requestedIds)) {
            throw ValidationException::withMessages(['questions' => 'Every selected question must be an active, approved question version in this institution.']);
        }

        $totalCents = 0;
        $snapshots = [];
        foreach (array_values($data['questions']) as $position => $row) {
            $version = $versions->get((int) $row['question_version_id']);
            if (! $version || ! $version->question || ! $version->question->bank
                || $version->question->status !== 'active'
                || (int) $version->question->current_version !== (int) $version->version_number
                || (int) $version->question->bank->institution_id !== (int) $session->cycle->institution_id
                || $version->question->bank->status !== 'active'
                || ($version->question->bank->campus_id !== null && (int) $version->question->bank->campus_id !== (int) $session->campus_id && $version->question->bank->sharing_scope !== 'institution')) {
                throw ValidationException::withMessages(['questions' => 'Selected questions must be current approved versions from an active question bank owned by this institution.']);
            }
            $points = (int) round((float) $row['points'] * 100);
            $totalCents += $points;
            $snapshots[] = [
                'question_version_id' => (int) $version->getKey(),
                'position' => $position + 1,
                'points' => sprintf('%d.%02d', intdiv($points, 100), $points % 100),
                'question_type' => $version->question_type,
                'topic' => $version->topic,
                'difficulty' => $version->difficulty,
                'content' => $version->content,
            ];
        }
        if ($totalCents < 1 || $totalCents > 999_999_999) {
            throw ValidationException::withMessages(['questions' => 'The paper total is outside the supported marks range.']);
        }
        $totalMarks = sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100);
        $blueprint = [
            'title' => trim($data['title']),
            'instructions' => isset($data['instructions']) ? Str::limit(strip_tags($data['instructions']), 5000, '') : null,
            'duration_minutes' => (int) $data['duration_minutes'],
            'question_count' => count($snapshots),
            'set_code' => $data['set_code'],
        ];
        $hashPayload = ['blueprint' => $blueprint, 'questions' => $snapshots, 'total_marks' => $totalMarks];
        $contentHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $paperVersion = DB::transaction(function () use ($session, $request, $data, $blueprint, $snapshots, $totalMarks, $contentHash, $audit, $tenantId): ExamPaperVersion {
            ExamSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $paper = ExamPaper::query()->firstOrCreate(
                ['session_id' => $session->getKey()],
                ['created_by' => $request->user()->getKey(), 'current_version' => 0, 'status' => 'draft'],
            );
            $paper = ExamPaper::query()->lockForUpdate()->findOrFail($paper->getKey());
            $versionNumber = ((int) ExamPaperVersion::query()->where('paper_id', $paper->getKey())->where('set_code', $data['set_code'])->max('version_number')) + 1;

            $paperVersion = ExamPaperVersion::query()->create([
                'paper_id' => $paper->getKey(),
                'version_number' => $versionNumber,
                'set_code' => $data['set_code'],
                'title' => trim($data['title']),
                'instructions' => $blueprint['instructions'],
                'blueprint_snapshot' => $blueprint,
                'question_snapshot' => $snapshots,
                'total_marks' => $totalMarks,
                'duration_minutes' => (int) $data['duration_minutes'],
                'content_hash' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'status' => 'draft',
            ]);
            $audit->record('exams.paper.version.created', $request->user(), $tenantId, $paperVersion, [
                'session_id' => (int) $session->getKey(), 'version' => $paperVersion->version_number,
                'set_code' => $paperVersion->set_code, 'question_count' => count($snapshots),
                'total_marks' => $totalMarks, 'content_hash' => $contentHash,
            ]);

            return $paperVersion;
        });

        return back()->with('success', 'Confidential paper draft saved as an immutable version.');
    }

    public function submitForReview(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $versionId, $context, $access, $audit): void {
            $version = ExamPaperVersion::query()->lockForUpdate()->findOrFail($versionId);
            $access->assertCampus((int) $version->paper()->firstOrFail()->session()->value('campus_id'));
            abort_unless($version->status === 'draft', 409, 'Only a draft paper version can be submitted for review; revisions require a new immutable version.');
            $version->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $audit->record('exams.paper.version.submitted', $request->user(), (int) $context->tenant()->getKey(), $version, ['paper_id' => (int) $version->paper_id, 'version' => (int) $version->version_number]);
        });

        return back()->with('success', 'Paper version submitted for independent review.');
    }

    public function review(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'notes' => ['nullable', 'string', 'max:2000']]);
        $actor = $request->user();
        DB::transaction(function () use ($versionId, $data, $actor, $context, $access, $audit): void {
            $version = ExamPaperVersion::query()->lockForUpdate()->findOrFail($versionId);
            $access->assertCampus((int) $version->paper()->firstOrFail()->session()->value('campus_id'));
            abort_unless($version->status === 'submitted', 409, 'Only a submitted paper version can be reviewed.');
            abort_unless((int) $version->created_by !== (int) $actor->getKey(), 403, 'Paper authors cannot review their own version.');
            $notes = isset($data['notes']) ? Str::limit(strip_tags(trim($data['notes'])), 2000, '') : null;
            $review = ExamPaperReview::query()->create([
                'paper_version_id' => $version->getKey(), 'reviewer_id' => $actor->getKey(),
                'decision' => $data['decision'], 'notes' => $notes, 'decided_at' => now(),
            ]);
            $version->forceFill(['status' => $data['decision'], 'approved_at' => $data['decision'] === 'approved' ? now() : null])->save();
            $audit->record('exams.paper.version.reviewed', $actor, (int) $context->tenant()->getKey(), $review, ['paper_version_id' => (int) $version->getKey(), 'decision' => $data['decision']]);
        });

        return back()->with('success', 'Paper review decision recorded.');
    }

    public function publish(Request $request, Tenant $tenant, int $versionId, ExamPaperPublicationWorkflow $workflow): RedirectResponse
    {
        $workflow->publish($versionId, $request->user());

        return back()->with('success', 'Approved examination paper version published.');
    }

    public function confidentialPreview(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): JsonResponse
    {
        $version = ExamPaperVersion::query()->with('paper')->findOrFail($versionId);
        $access->assertCampus((int) $version->paper()->firstOrFail()->session()->value('campus_id'));
        try {
            $blueprint = $version->decryptedBlueprint();
            $questions = $version->decryptedQuestions();
        } catch (DecryptException|UnexpectedValueException) {
            abort(409, 'Confidential paper content cannot be decrypted.');
        }
        $canonical = ['blueprint' => $blueprint, 'questions' => $questions, 'total_marks' => (string) $version->total_marks];
        $actualHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        abort_unless(hash_equals((string) $version->content_hash, $actualHash), 409, 'Paper integrity verification failed.');
        $audit->record('exams.paper.version.previewed', $request->user(), (int) $context->tenant()->getKey(), $version, ['paper_id' => (int) $version->paper_id, 'version' => (int) $version->version_number, 'status' => $version->status]);

        return response()->json([
            'paper' => ['title' => $blueprint['title'], 'instructions' => $blueprint['instructions'] ?? null, 'duration_minutes' => $version->duration_minutes, 'total_marks' => $version->total_marks, 'set_code' => $version->set_code, 'version' => $version->version_number, 'status' => $version->status],
            'questions' => $questions,
        ])->header('Cache-Control', 'private, no-store');
    }
}
