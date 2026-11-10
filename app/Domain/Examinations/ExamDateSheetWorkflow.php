<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamCycle;
use App\Models\ExamDateSheet;
use App\Models\ExamDateSheetRevision;
use App\Models\ExamSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ExamDateSheetWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $access,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<int> $sessionIds */
    public function createRevision(int $cycleId, array $sessionIds, User $actor): ExamDateSheetRevision
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.manage'), 403);
        $tenantId = (int) $membership->tenant_id;
        $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
        if ($sessionIds === []) {
            throw ValidationException::withMessages(['session_ids' => 'Choose at least one scheduled examination session.']);
        }

        return DB::transaction(function () use ($cycleId, $sessionIds, $actor, $tenantId): ExamDateSheetRevision {
            $cycle = ExamCycle::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cycleId);
            $this->access->assertCampus($cycle->campus_id === null ? null : (int) $cycle->campus_id);
            $sessions = ExamSession::query()->where('tenant_id', $tenantId)->where('cycle_id', $cycleId)->whereIn('id', $sessionIds)
                ->with(['course:id,title,code'])->orderBy('starts_at_utc')->lockForUpdate()->get();
            if ($sessions->count() !== count($sessionIds)) {
                throw ValidationException::withMessages(['session_ids' => 'Every selected session must belong to this examination cycle.']);
            }
            foreach ($sessions as $session) {
                $this->access->assertCampus((int) $session->campus_id);
                if ($session->status === 'archived') {
                    throw ValidationException::withMessages(['session_ids' => 'Archived sessions cannot be placed on a new date-sheet revision.']);
                }
            }
            $sheet = ExamDateSheet::query()->firstOrCreate(
                ['cycle_id' => $cycleId], ['created_by' => $actor->getKey(), 'current_revision' => 0, 'status' => 'draft'],
            );
            $sheet = ExamDateSheet::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($sheet->getKey());
            $revision = (int) ExamDateSheetRevision::query()->where('date_sheet_id', $sheet->getKey())->max('revision') + 1;
            $orderedSessions = $sessions->all();
            $snapshot = array_map(static function (ExamSession $session): array {
                return [
                    'session_id' => (int) $session->getKey(), 'code' => (string) $session->code, 'title' => (string) $session->title,
                    'course' => ['id' => (int) $session->academic_course_id, 'code' => $session->course?->code, 'title' => $session->course?->title],
                    'campus_id' => (int) $session->campus_id, 'room_id' => $session->room_id === null ? null : (int) $session->room_id,
                    'mode' => (string) $session->mode,
                    'starts_at_utc' => CarbonImmutable::parse((string) $session->starts_at_utc)->toIso8601String(),
                    'ends_at_utc' => CarbonImmutable::parse((string) $session->ends_at_utc)->toIso8601String(),
                    'duration_minutes' => (int) $session->duration_minutes,
                ];
            }, $orderedSessions);
            $record = ExamDateSheetRevision::query()->create([
                'date_sheet_id' => $sheet->getKey(), 'revision' => $revision, 'schedule_snapshot' => $snapshot,
                'status' => 'draft', 'created_by' => $actor->getKey(),
            ]);
            $sheet->forceFill(['status' => 'draft'])->save();
            $this->audit->record('exams.date_sheet.revision_created', $actor, $tenantId, $record, [
                'cycle_id' => $cycleId, 'revision' => $revision, 'session_count' => count($snapshot),
            ]);

            return $record;
        }, 3);
    }

    public function submitForReview(int $revisionId, User $actor): ExamDateSheetRevision
    {
        abort_unless($this->context->membership()->hasPermission('exams.manage'), 403);

        return DB::transaction(function () use ($revisionId, $actor): ExamDateSheetRevision {
            $revision = ExamDateSheetRevision::query()->with('dateSheet.cycle')->lockForUpdate()->findOrFail($revisionId);
            $this->assertScope($revision);
            $snapshot = json_decode((string) $revision->getRawOriginal('schedule_snapshot'), true);
            abort_unless($revision->status === 'draft' && is_array($snapshot) && array_is_list($snapshot) && $snapshot !== [], 409, 'Only a populated draft date sheet can be submitted.');
            $revision->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $revision->dateSheet->forceFill(['status' => 'review'])->save();
            $this->audit->record('exams.date_sheet.submitted', $actor, (int) $revision->tenant_id, $revision, ['revision' => (int) $revision->revision]);

            return $revision->fresh();
        }, 3);
    }

    public function decide(int $revisionId, string $decision, ?string $notes, User $actor): ExamDateSheetRevision
    {
        abort_unless($this->context->membership()->hasPermission('exams.approve'), 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);

        return DB::transaction(function () use ($revisionId, $decision, $notes, $actor): ExamDateSheetRevision {
            $revision = ExamDateSheetRevision::query()->with('dateSheet.cycle')->lockForUpdate()->findOrFail($revisionId);
            $this->assertScope($revision);
            abort_unless($revision->status === 'submitted', 409, 'Only a submitted date-sheet revision can be reviewed.');
            abort_unless((int) $revision->created_by !== (int) $actor->getKey(), 403, 'The date-sheet author cannot review their own revision.');
            $revision->forceFill(['status' => $decision, 'reviewed_by' => $actor->getKey(), 'review_notes' => $notes === null ? null : mb_substr(trim(strip_tags($notes)), 0, 2000), 'reviewed_at' => now()])->save();
            $revision->dateSheet->forceFill(['status' => $decision])->save();
            $this->audit->record('exams.date_sheet.reviewed', $actor, (int) $revision->tenant_id, $revision, ['revision' => (int) $revision->revision, 'decision' => $decision]);

            return $revision->fresh();
        }, 3);
    }

    public function publish(int $revisionId, User $actor): ExamDateSheetRevision
    {
        abort_unless($this->context->membership()->hasPermission('exams.publish'), 403);

        return DB::transaction(function () use ($revisionId, $actor): ExamDateSheetRevision {
            $revision = ExamDateSheetRevision::query()->with('dateSheet.cycle')->lockForUpdate()->findOrFail($revisionId);
            $this->assertScope($revision);
            abort_unless($revision->status === 'approved' && $revision->reviewed_by !== null, 409, 'Only an approved date-sheet revision can be published.');
            abort_unless((int) $revision->created_by !== (int) $actor->getKey() && (int) $revision->reviewed_by !== (int) $actor->getKey(), 403, 'The author or reviewer cannot publish this date-sheet revision.');
            $sheet = ExamDateSheet::query()->where('tenant_id', $revision->tenant_id)->lockForUpdate()->findOrFail($revision->date_sheet_id);
            if ((int) $revision->revision <= (int) $sheet->current_revision) {
                throw new ConflictHttpException('A newer date-sheet revision has already been published.');
            }
            $previousPublished = ExamDateSheetRevision::query()
                ->where('tenant_id', $revision->tenant_id)
                ->where('date_sheet_id', $sheet->getKey())
                ->where('status', 'published')
                ->orderByDesc('revision')
                ->first(['schedule_snapshot']);
            $previousSnapshot = $previousPublished === null
                ? []
                : json_decode((string) $previousPublished->getRawOriginal('schedule_snapshot'), true, 512, JSON_THROW_ON_ERROR);
            ExamDateSheetRevision::query()->where('date_sheet_id', $sheet->getKey())->where('status', 'published')->update(['status' => 'superseded', 'updated_at' => now()]);
            $revision->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $sheet->forceFill(['current_revision' => $revision->revision, 'status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now()])->save();
            $this->audit->record('exams.date_sheet.published', $actor, (int) $revision->tenant_id, $revision, ['revision' => (int) $revision->revision, 'session_count' => count((array) $revision->schedule_snapshot)]);
            $this->queueCandidateNotices($revision, is_array($previousSnapshot) ? $previousSnapshot : []);

            return $revision->fresh();
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function forCycle(int $cycleId): ?array
    {
        $sheet = ExamDateSheet::query()->with(['revisions'])->where('cycle_id', $cycleId)->first();
        if (! $sheet) {
            return null;
        }

        return [
            'id' => (int) $sheet->getKey(), 'status' => $sheet->status, 'currentRevision' => (int) $sheet->current_revision,
            'revisions' => $sheet->revisions->map(static fn (ExamDateSheetRevision $revision): array => [
                'id' => (int) $revision->getKey(), 'revision' => (int) $revision->revision, 'status' => $revision->status,
                'createdAt' => $revision->created_at?->toIso8601String(), 'reviewNotes' => $revision->review_notes,
                'sessions' => $revision->schedule_snapshot,
            ])->values()->all(),
        ];
    }

    private function assertScope(ExamDateSheetRevision $revision): void
    {
        $cycle = $revision->dateSheet?->cycle;
        abort_unless($cycle !== null && (int) $revision->tenant_id === (int) $this->context->tenant()->getKey(), 404);
        $this->access->assertCampus($cycle->campus_id === null ? null : (int) $cycle->campus_id);
    }

    /** @param list<array<string, mixed>> $previousSnapshot */
    private function queueCandidateNotices(ExamDateSheetRevision $revision, array $previousSnapshot): void
    {
        $tenantId = (int) $revision->tenant_id;
        $snapshot = json_decode((string) $revision->getRawOriginal('schedule_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ! array_is_list($snapshot)) {
            return;
        }
        $sessionIds = array_values(array_unique(array_filter(array_map(
            static fn ($session): int => is_array($session) && is_numeric($session['session_id'] ?? null) ? (int) $session['session_id'] : 0,
            $snapshot,
        ))));
        if ($sessionIds === []) {
            return;
        }
        $currentBySession = collect($snapshot)->filter(static fn ($item): bool => is_array($item) && is_numeric($item['session_id'] ?? null))
            ->keyBy(static fn (array $item): int => (int) $item['session_id']);
        $previousBySession = collect($previousSnapshot)->filter(static fn (array $item): bool => is_numeric($item['session_id'] ?? null))
            ->keyBy(static fn (array $item): int => (int) $item['session_id']);
        $allSessionIds = $currentBySession->keys()->merge($previousBySession->keys())->unique()->map(static fn ($id): int => (int) $id)->values();
        $changedSessionIds = $allSessionIds->filter(static function (int $sessionId) use ($currentBySession, $previousBySession): bool {
            return $currentBySession->get($sessionId) !== $previousBySession->get($sessionId);
        })->all();
        $removedSessionIds = array_values(array_diff($previousBySession->keys()->map(static fn ($id): int => (int) $id)->all(), $currentBySession->keys()->map(static fn ($id): int => (int) $id)->all()));
        $changeType = $previousSnapshot === [] ? 'published' : ($removedSessionIds !== [] ? 'cancelled' : 'rescheduled');
        $eventType = $previousSnapshot === [] ? 'exam.date_sheet_published' : 'exam.schedule_changed';
        $noticeSessionIds = $previousSnapshot === [] ? $sessionIds : ($changedSessionIds !== [] ? $changedSessionIds : $sessionIds);
        $candidates = DB::table('exam_candidates')->where('tenant_id', $tenantId)
            ->where('cycle_id', $revision->dateSheet->cycle_id)->where('status', 'registered')->whereNull('withdrawn_at')
            ->orderBy('id')->pluck('id');
        foreach ($candidates as $candidateId) {
            DB::table('quiz_outbox_events')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'event_key' => ($eventType === 'exam.date_sheet_published' ? 'exam.date_sheet.published' : 'exam.schedule.changed').':revision:'.$revision->getKey().':candidate:'.$candidateId,
                'event_type' => $eventType,
                'safe_payload' => json_encode(['revision_id' => (int) $revision->getKey(), 'session_ids' => $noticeSessionIds, 'candidate_id' => (int) $candidateId, 'change_type' => $changeType], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
