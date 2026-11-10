<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicRoom;
use App\Models\ExamCandidate;
use App\Models\ExamCandidateAccommodation;
use App\Models\ExamCycle;
use App\Models\ExamSeatingPlan;
use App\Models\ExamSeatingPlanRevision;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ExamSeatingPlanWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $access,
        private readonly AuditRecorder $audit,
        private readonly ExamCandidateGovernanceWorkflow $eligibility,
    ) {}

    /** @param list<array{candidate_id:int,room_id:int,seat_number:string}> $assignments */
    public function createRevision(int $sessionId, array $assignments, User $actor): ExamSeatingPlanRevision
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.manage'), 403);
        $tenantId = (int) $membership->tenant_id;
        if ($assignments === []) {
            throw ValidationException::withMessages(['assignments' => 'Add at least one eligible candidate seat assignment.']);
        }

        return DB::transaction(function () use ($sessionId, $assignments, $actor, $tenantId): ExamSeatingPlanRevision {
            $session = ExamSession::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($sessionId);
            $this->access->assertCampus((int) $session->campus_id);
            abort_unless(in_array($session->mode, ['onsite', 'practical', 'oral'], true), 409, 'Online sessions do not use physical seating plans.');
            abort_unless(in_array($session->status, ['draft', 'published'], true) && now()->lt($session->starts_at_utc), 409, 'Seating plans can only be revised before the examination starts.');
            abort_unless(! DB::table('exam_attempt_bindings')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->exists(), 409, 'Seating is locked after an examination attempt begins.');
            abort_unless(! DB::table('exam_attendance_records')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->exists(), 409, 'Seating is locked after attendance is recorded.');
            $cycle = ExamCycle::query()->where('tenant_id', $tenantId)->findOrFail($session->cycle_id);

            $candidateIds = array_map(static fn (array $row): int => (int) $row['candidate_id'], $assignments);
            $seatKeys = array_map(static fn (array $row): string => (int) $row['room_id'].':'.strtoupper(trim($row['seat_number'])), $assignments);
            if (count(array_unique($candidateIds)) !== count($candidateIds)) {
                throw ValidationException::withMessages(['assignments' => 'A candidate can appear only once in a seating plan.']);
            }
            if (count(array_unique($seatKeys)) !== count($seatKeys)) {
                throw ValidationException::withMessages(['assignments' => 'A room seat can be assigned only once.']);
            }
            $expectedCandidateIds = ExamCandidate::query()->where('cycle_id', $cycle->getKey())->where('status', 'registered')->whereNull('withdrawn_at')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            sort($candidateIds);
            if ($candidateIds !== $expectedCandidateIds) {
                throw ValidationException::withMessages(['assignments' => 'The plan must seat every registered, non-withdrawn candidate exactly once.']);
            }

            $rooms = AcademicRoom::query()->where('tenant_id', $tenantId)->where('institution_id', $cycle->institution_id)
                ->where('campus_id', $session->campus_id)->where('status', 'active')->whereIn('id', array_column($assignments, 'room_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($rooms->count() !== count(array_unique(array_map(static fn (array $row): int => (int) $row['room_id'], $assignments)))) {
                throw ValidationException::withMessages(['assignments' => 'Every room must be active and belong to this institution and examination campus.']);
            }
            $perRoom = [];
            $snapshotAssignments = [];
            foreach ($assignments as $assignment) {
                $candidateId = (int) $assignment['candidate_id'];
                $roomId = (int) $assignment['room_id'];
                $seat = trim((string) $assignment['seat_number']);
                if (! preg_match('/\A[A-Za-z0-9._-]{1,24}\z/', $seat)) {
                    throw ValidationException::withMessages(['assignments' => 'Seat labels must use 1–24 letters, numbers, dots, underscores or hyphens.']);
                }
                $candidate = ExamCandidate::query()->where('tenant_id', $tenantId)->where('cycle_id', $cycle->getKey())->findOrFail($candidateId);
                if (! $this->eligibility->evaluate($candidate, $session)['eligible']) {
                    throw ValidationException::withMessages(['assignments' => 'Every candidate must have active confirmed enrollment in the session course and campus on the exam date.']);
                }
                $room = $rooms->get($roomId);
                $accessibleRoomRequired = ExamCandidateAccommodation::query()->where('candidate_id', $candidateId)->where('status', 'active')
                    ->where('accommodation_type', 'accessible_room')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $session->starts_at_utc))->exists();
                $features = is_array($room->features) ? $room->features : [];
                abort_unless(! $accessibleRoomRequired || in_array('accessible', $features, true) || ($features['accessible'] ?? false) === true, 422, 'The candidate accommodation requires a room marked accessible.');
                $perRoom[$roomId] = ($perRoom[$roomId] ?? 0) + 1;
                $snapshotAssignments[] = ['candidate_id' => $candidateId, 'candidate_number' => (string) $candidate->candidate_number, 'room_id' => $roomId, 'room_code' => (string) $room->code, 'room_name' => (string) $room->name, 'seat_number' => $seat];
            }

            foreach ($perRoom as $roomId => $count) {
                $room = $rooms->get($roomId);
                if ($count > (int) $room->capacity || ($session->capacity !== null && count($assignments) > (int) $session->capacity)) {
                    throw ValidationException::withMessages(['assignments' => 'The seating plan exceeds the room or examination session capacity.']);
                }
                $overlap = ExamSession::query()->where('tenant_id', $tenantId)->where('id', '!=', $sessionId)->where('room_id', $roomId)
                    ->whereIn('status', ['draft', 'published', 'active'])->where('starts_at_utc', '<', $session->ends_at_utc)->where('ends_at_utc', '>', $session->starts_at_utc)->exists();
                $blocked = DB::table('academic_room_blocks')->where('tenant_id', $tenantId)->where('room_id', $roomId)->where('status', 'active')
                    ->where('starts_at_utc', '<', $session->ends_at_utc)->where('ends_at_utc', '>', $session->starts_at_utc)->exists();
                if ($overlap || $blocked) {
                    throw ValidationException::withMessages(['assignments' => 'A selected room conflicts with another examination or an active room block.']);
                }
            }

            $plan = ExamSeatingPlan::query()->firstOrCreate(['session_id' => $sessionId], ['created_by' => $actor->getKey(), 'current_revision' => 0, 'published_revision' => 0, 'status' => 'draft']);
            $plan = ExamSeatingPlan::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($plan->getKey());
            $revisionNumber = (int) ExamSeatingPlanRevision::query()->where('plan_id', $plan->getKey())->max('revision') + 1;
            $revision = ExamSeatingPlanRevision::query()->create([
                'plan_id' => $plan->getKey(), 'revision' => $revisionNumber, 'assignment_snapshot' => $snapshotAssignments,
                'status' => 'draft', 'created_by' => $actor->getKey(),
            ]);
            $plan->forceFill(['current_revision' => $revisionNumber, 'status' => 'draft'])->save();
            $this->audit->record('exams.seating.revision_created', $actor, $tenantId, $revision, ['session_id' => $sessionId, 'revision' => $revisionNumber, 'candidate_count' => count($assignments)]);

            return $revision;
        }, 3);
    }

    public function submit(int $revisionId, User $actor): void
    {
        abort_unless($this->context->membership()->hasPermission('exams.manage'), 403);
        DB::transaction(function () use ($revisionId, $actor): void {
            $revision = $this->lockedRevision($revisionId);
            abort_unless($revision->status === 'draft', 409);
            $revision->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $revision->plan->forceFill(['status' => 'review'])->save();
            $this->audit->record('exams.seating.submitted', $actor, (int) $revision->tenant_id, $revision, ['revision' => (int) $revision->revision]);
        }, 3);
    }

    public function decide(int $revisionId, string $decision, ?string $notes, User $actor): void
    {
        abort_unless($this->context->membership()->hasPermission('exams.approve'), 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        DB::transaction(function () use ($revisionId, $decision, $notes, $actor): void {
            $revision = $this->lockedRevision($revisionId);
            abort_unless($revision->status === 'submitted', 409);
            abort_unless((int) $revision->created_by !== (int) $actor->getKey(), 403, 'The seating-plan author cannot review their own plan.');
            $revision->forceFill(['status' => $decision, 'reviewed_by' => $actor->getKey(), 'review_notes' => $notes === null ? null : mb_substr(trim(strip_tags($notes)), 0, 2000), 'reviewed_at' => now()])->save();
            $revision->plan->forceFill(['status' => $decision])->save();
            $this->audit->record('exams.seating.reviewed', $actor, (int) $revision->tenant_id, $revision, ['revision' => (int) $revision->revision, 'decision' => $decision]);
        }, 3);
    }

    public function publish(int $revisionId, User $actor): void
    {
        abort_unless($this->context->membership()->hasPermission('exams.publish'), 403);
        DB::transaction(function () use ($revisionId, $actor): void {
            $revision = $this->lockedRevision($revisionId);
            abort_unless($revision->status === 'approved' && $revision->reviewed_by !== null, 409);
            abort_unless((int) $revision->created_by !== (int) $actor->getKey() && (int) $revision->reviewed_by !== (int) $actor->getKey(), 403, 'The author or reviewer cannot publish this seating plan.');
            $plan = ExamSeatingPlan::query()->where('tenant_id', $revision->tenant_id)->lockForUpdate()->findOrFail($revision->plan_id);
            $session = ExamSession::query()->where('tenant_id', $revision->tenant_id)->lockForUpdate()->findOrFail($plan->session_id);
            abort_unless(now()->lt($session->starts_at_utc) && ! DB::table('exam_attempt_bindings')->where('tenant_id', $revision->tenant_id)->where('session_id', $session->getKey())->exists() && ! DB::table('exam_attendance_records')->where('tenant_id', $revision->tenant_id)->where('session_id', $session->getKey())->exists(), 409, 'Seating publication is closed after exam entry or attendance begins.');
            if ((int) $revision->revision !== (int) $plan->current_revision) {
                throw new ConflictHttpException('A newer seating revision must be reviewed before publication.');
            }
            $snapshot = $this->snapshotAssignments($revision);
            $this->assertPublishableSnapshot($session, $snapshot, (int) $revision->tenant_id);
            $allocations = DB::table('exam_seat_allocations')->where('tenant_id', $revision->tenant_id)->where('session_id', $session->getKey())->lockForUpdate()->get();
            $candidateIds = array_column($snapshot, 'candidate_id');
            $removedAllocations = $allocations->filter(static fn (object $row): bool => ! in_array((int) $row->candidate_id, $candidateIds, true))
                ->map(static fn (object $row): array => ['candidate_id' => (int) $row->candidate_id, 'room_id' => (int) $row->room_id, 'seat_number' => (string) $row->seat_number])->values()->all();
            foreach ($allocations as $allocation) {
                DB::table('exam_seat_allocations')->where('id', $allocation->id)->update(['seat_number' => '~r'.$allocation->id]);
            }
            foreach ($snapshot as $assignment) {
                $candidateId = (int) $assignment['candidate_id'];
                $existing = DB::table('exam_seat_allocations')->where('tenant_id', $revision->tenant_id)->where('session_id', $session->getKey())->where('candidate_id', $candidateId)->first();
                if ($existing) {
                    DB::table('exam_seat_allocations')->where('id', $existing->id)->update(['room_id' => (int) $assignment['room_id'], 'seat_number' => (string) $assignment['seat_number'], 'allocated_by' => $actor->getKey(), 'updated_at' => now()]);
                } else {
                    DB::table('exam_seat_allocations')->insert(['tenant_id' => $revision->tenant_id, 'session_id' => $session->getKey(), 'candidate_id' => $candidateId, 'room_id' => (int) $assignment['room_id'], 'seat_number' => (string) $assignment['seat_number'], 'allocated_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('exam_seat_allocations')->where('tenant_id', $revision->tenant_id)->where('session_id', $session->getKey())->whereNotIn('candidate_id', $candidateIds)->delete();
            ExamSeatingPlanRevision::query()->where('plan_id', $plan->getKey())->where('status', 'published')->update(['status' => 'superseded', 'updated_at' => now()]);
            $revision->forceFill(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now()])->save();
            $plan->forceFill(['published_revision' => $revision->revision, 'status' => 'published'])->save();
            $this->audit->record('exams.seating.published', $actor, (int) $revision->tenant_id, $revision, ['session_id' => (int) $session->getKey(), 'revision' => (int) $revision->revision, 'assignment_count' => count($snapshot), 'removed_allocations' => $removedAllocations]);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function forSession(int $sessionId): array
    {
        $session = ExamSession::query()->findOrFail($sessionId);
        $plan = ExamSeatingPlan::query()->with('revisions')->where('session_id', $sessionId)->first();
        $candidates = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->where('status', 'registered')->whereNull('withdrawn_at')->with('student:id,student_number,legal_first_name,legal_last_name')->orderBy('candidate_number')->get();
        $allocations = DB::table('exam_seat_allocations')->where('tenant_id', $session->tenant_id)->where('session_id', $sessionId)->get()->keyBy('candidate_id');

        return ['planId' => $plan?->getKey(), 'status' => $plan?->status, 'currentRevision' => $plan === null ? 0 : (int) $plan->current_revision, 'publishedRevision' => $plan === null ? 0 : (int) $plan->published_revision, 'revisions' => $plan === null ? [] : $plan->revisions->map(static fn (ExamSeatingPlanRevision $revision): array => ['id' => (int) $revision->getKey(), 'revision' => (int) $revision->revision, 'status' => $revision->status, 'createdAt' => $revision->created_at?->toIso8601String(), 'reviewNotes' => $revision->review_notes])->values()->all(), 'candidates' => $candidates->map(static function (ExamCandidate $candidate) use ($allocations): array {
            $allocation = $allocations->get($candidate->getKey());

            return ['id' => (int) $candidate->getKey(), 'candidateNumber' => (string) $candidate->candidate_number, 'studentName' => trim((string) $candidate->student?->legal_first_name.' '.(string) $candidate->student?->legal_last_name), 'seat' => $allocation ? ['room_id' => (int) $allocation->room_id, 'seat_number' => (string) $allocation->seat_number] : null];
        })->values()->all()];
    }

    private function lockedRevision(int $revisionId): ExamSeatingPlanRevision
    {
        $revision = ExamSeatingPlanRevision::query()->with('plan.session')->lockForUpdate()->findOrFail($revisionId);
        abort_unless((int) $revision->tenant_id === (int) $this->context->tenant()->getKey(), 404);
        $this->access->assertCampus((int) $revision->plan->session->campus_id);

        return $revision;
    }

    /** @return list<array{candidate_id:int,room_id:int,seat_number:string}> */
    private function snapshotAssignments(ExamSeatingPlanRevision $revision): array
    {
        $raw = json_decode((string) $revision->getRawOriginal('assignment_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ConflictHttpException('The stored seating revision has an invalid assignment snapshot.');
        }
        $assignments = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['candidate_id'], $row['room_id'], $row['seat_number'])
                || ! is_numeric($row['candidate_id']) || ! is_numeric($row['room_id']) || ! is_string($row['seat_number'])) {
                throw new ConflictHttpException('The stored seating revision has an invalid assignment snapshot.');
            }
            $assignments[] = ['candidate_id' => (int) $row['candidate_id'], 'room_id' => (int) $row['room_id'], 'seat_number' => $row['seat_number']];
        }

        return $assignments;
    }

    /** @param list<array{candidate_id:int,room_id:int,seat_number:string}> $assignments */
    private function assertPublishableSnapshot(ExamSession $session, array $assignments, int $tenantId): void
    {
        $cycle = ExamCycle::query()->where('tenant_id', $tenantId)->findOrFail($session->cycle_id);
        $candidateIds = array_column($assignments, 'candidate_id');
        $expectedIds = ExamCandidate::query()->where('cycle_id', $cycle->getKey())->where('status', 'registered')->whereNull('withdrawn_at')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $sortedIds = $candidateIds;
        sort($sortedIds);
        abort_unless($sortedIds === $expectedIds, 409, 'Candidate eligibility changed after this seating revision was prepared. Create and review a new revision.');
        abort_unless(count(array_unique($candidateIds)) === count($candidateIds), 409, 'The seating revision contains a duplicate candidate.');
        $seatKeys = array_map(static fn (array $row): string => $row['room_id'].':'.strtoupper($row['seat_number']), $assignments);
        abort_unless(count(array_unique($seatKeys)) === count($seatKeys), 409, 'The seating revision contains duplicate room seats.');
        $roomIds = array_values(array_unique(array_column($assignments, 'room_id')));
        $rooms = AcademicRoom::query()->where('tenant_id', $tenantId)->where('institution_id', $cycle->institution_id)->where('campus_id', $session->campus_id)->where('status', 'active')->whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($rooms->count() === count($roomIds), 409, 'A selected room is no longer active in this examination campus.');
        $counts = [];
        foreach ($assignments as $assignment) {
            $candidate = ExamCandidate::query()->where('tenant_id', $tenantId)->where('cycle_id', $cycle->getKey())->findOrFail($assignment['candidate_id']);
            abort_unless($this->eligibility->evaluate($candidate, $session)['eligible'], 409, 'Candidate eligibility changed after this seating revision was prepared.');
            $requiresAccessibleRoom = ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('status', 'active')->where('accommodation_type', 'accessible_room')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $session->starts_at_utc))->exists();
            $roomFeatures = is_array($rooms->get($assignment['room_id'])->features) ? $rooms->get($assignment['room_id'])->features : [];
            abort_unless(! $requiresAccessibleRoom || in_array('accessible', $roomFeatures, true) || ($roomFeatures['accessible'] ?? false) === true, 409, 'The published seating assignment conflicts with a candidate accessibility accommodation.');
            $counts[$assignment['room_id']] = ($counts[$assignment['room_id']] ?? 0) + 1;
        }
        foreach ($counts as $roomId => $count) {
            abort_unless($count <= (int) $rooms->get($roomId)->capacity && ($session->capacity === null || count($assignments) <= (int) $session->capacity), 409, 'Room or session capacity changed after this seating revision was prepared.');
            $overlap = ExamSession::query()->where('tenant_id', $tenantId)->where('id', '!=', $session->getKey())->where('room_id', $roomId)
                ->whereIn('status', ['draft', 'published', 'active'])->where('starts_at_utc', '<', $session->ends_at_utc)->where('ends_at_utc', '>', $session->starts_at_utc)->exists();
            $blocked = DB::table('academic_room_blocks')->where('tenant_id', $tenantId)->where('room_id', $roomId)->where('status', 'active')->where('starts_at_utc', '<', $session->ends_at_utc)->where('ends_at_utc', '>', $session->starts_at_utc)->exists();
            abort_unless(! $overlap && ! $blocked, 409, 'A room conflict was introduced after this seating revision was prepared.');
        }
    }
}
