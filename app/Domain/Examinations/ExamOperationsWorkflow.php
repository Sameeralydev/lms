<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicRoom;
use App\Models\CourseEnrollment;
use App\Models\ExamCandidate;
use App\Models\ExamCycle;
use App\Models\ExamSession;
use App\Models\SisStudent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Transactional operational workflows shared by HTTP actions and integration probes. */
final class ExamOperationsWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $access,
        private readonly AuditRecorder $audit,
    ) {}

    public function registerCandidate(int $cycleId, int $studentId, User $actor): ExamCandidate
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.candidates.manage'), 403);
        $tenantId = (int) $membership->tenant_id;
        $cycle = ExamCycle::query()->findOrFail($cycleId);
        abort_unless((int) $cycle->tenant_id === $tenantId, 404);
        $this->access->assertCampus($cycle->campus_id === null ? null : (int) $cycle->campus_id);
        abort_unless($cycle->status === 'published', 409, 'Candidate registration requires a published examination cycle.');
        $today = now()->toDateString();
        abort_unless(($cycle->registration_opens_on === null || Carbon::parse($cycle->registration_opens_on)->toDateString() <= $today)
            && ($cycle->registration_closes_on === null || Carbon::parse($cycle->registration_closes_on)->toDateString() >= $today), 409, 'Candidate registration is not open.');

        return DB::transaction(function () use ($cycleId, $studentId, $actor, $tenantId): ExamCandidate {
            $cycle = ExamCycle::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cycleId);
            abort_unless($cycle->status === 'published', 409, 'Candidate registration requires a published examination cycle.');
            $student = SisStudent::query()->where('tenant_id', $tenantId)->where('institution_id', $cycle->institution_id)->where('status', 'active')->lockForUpdate()->findOrFail($studentId);
            if ($cycle->source_cycle_id !== null) {
                $sourceCycle = ExamCycle::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cycle->source_cycle_id);
                abort_unless((int) $sourceCycle->institution_id === (int) $cycle->institution_id && in_array($sourceCycle->status, ['completed', 'closed'], true), 409, 'The source examination is no longer eligible for retake or makeup registration.');
                $requiredOutcome = $cycle->category === 'retake' ? 'fail' : 'absent';
                $eligibleResult = DB::table('exam_results as results')
                    ->join('exam_candidates as source_candidates', function ($join): void {
                        $join->on('source_candidates.id', '=', 'results.candidate_id')->on('source_candidates.tenant_id', '=', 'results.tenant_id');
                    })->join('exam_sessions as source_sessions', function ($join): void {
                        $join->on('source_sessions.id', '=', 'results.session_id')->on('source_sessions.tenant_id', '=', 'results.tenant_id');
                    })
                    ->where('results.tenant_id', $tenantId)->where('source_candidates.cycle_id', $sourceCycle->getKey())
                    ->where('source_candidates.student_id', $studentId)->where('source_candidates.status', 'registered')->whereNull('source_candidates.withdrawn_at')
                    ->where('source_sessions.cycle_id', $sourceCycle->getKey())->where('results.status', 'released')->where('results.outcome', $requiredOutcome)
                    ->orderByDesc('results.id')->lockForUpdate()->first(['results.id']);
                if ($eligibleResult === null) {
                    throw ValidationException::withMessages(['student_id' => $cycle->category === 'retake'
                        ? 'Retake registration is limited to candidates with a released unsuccessful source result.'
                        : 'Makeup registration is limited to candidates with a released absent source result.']);
                }
            }
            $candidate = ExamCandidate::query()->firstOrCreate(
                ['cycle_id' => $cycle->getKey(), 'student_id' => $student->getKey()],
                ['candidate_number' => $student->student_number, 'registered_by' => $actor->getKey(), 'status' => 'registered', 'registered_at' => now()],
            );
            if ($candidate->wasRecentlyCreated) {
                $this->audit->record('exams.candidate.registered', $actor, $tenantId, $candidate, ['cycle_id' => (int) $cycle->getKey(), 'source_cycle_id' => $cycle->source_cycle_id === null ? null : (int) $cycle->source_cycle_id]);
                if (in_array($cycle->category, ['retake', 'makeup'], true)) {
                    $eventType = $cycle->category === 'retake' ? 'exam.retake_registered' : 'exam.makeup_registered';
                    DB::table('quiz_outbox_events')->insertOrIgnore([
                        'tenant_id' => $tenantId,
                        'event_key' => $eventType.':candidate:'.$candidate->getKey(),
                        'event_type' => $eventType,
                        'safe_payload' => json_encode(['candidate_id' => (int) $candidate->getKey(), 'cycle_id' => (int) $cycle->getKey()], JSON_THROW_ON_ERROR),
                        'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            return $candidate;
        }, 3);
    }

    public function allocateSeat(int $sessionId, int $candidateId, int $roomId, string $seatNumber, User $actor): void
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.manage'), 403);
        $tenantId = (int) $membership->tenant_id;
        $session = ExamSession::query()->where('tenant_id', $tenantId)->findOrFail($sessionId);
        $this->access->assertCampus((int) $session->campus_id);
        abort_unless((bool) preg_match('/\A[A-Za-z0-9._-]{1,24}\z/', $seatNumber), 422, 'Seat number format is invalid.');

        try {
            DB::transaction(function () use ($sessionId, $candidateId, $roomId, $seatNumber, $actor, $tenantId): void {
                $session = ExamSession::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($sessionId);
                $room = AcademicRoom::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($roomId);
                abort_unless((int) $room->campus_id === (int) $session->campus_id && $room->status === 'active', 422, 'Select an active room in the examination campus.');
                $candidate = ExamCandidate::query()->where('tenant_id', $tenantId)->where('cycle_id', $session->cycle_id)->lockForUpdate()->findOrFail($candidateId);
                abort_unless($candidate->status === 'registered' && $candidate->withdrawn_at === null, 422, 'Only registered, non-withdrawn candidates can be seated.');
                $cycle = ExamCycle::query()->where('tenant_id', $tenantId)->findOrFail($session->cycle_id);
                $eligible = CourseEnrollment::query()->where('student_id', $candidate->student_id)->where('status', 'confirmed')->whereNull('dropped_at')
                    ->where(fn ($query) => $query->whereNull('withdrawn_effective_at')->orWhere('withdrawn_effective_at', '>', $session->starts_at_utc))
                    ->whereHas('offering', fn ($query) => $query->where('academic_course_id', $session->academic_course_id)->where('institution_id', $cycle->institution_id))
                    ->whereHas('section', fn ($query) => $query->where('campus_id', $session->campus_id)->where('status', 'open'))->lockForUpdate()->exists();
                if (! $eligible) {
                    throw ValidationException::withMessages(['candidate_id' => 'An active confirmed enrollment in this course and campus is required on the examination date.']);
                }
                $existing = DB::table('exam_seat_allocations')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->where('candidate_id', $candidateId)->first();
                if ($existing) {
                    if ((int) $existing->room_id === $roomId && (string) $existing->seat_number === $seatNumber) {
                        return;
                    }
                    throw new ConflictHttpException('This candidate already has a seat allocation for the session.');
                }
                abort_unless(DB::table('exam_seat_allocations')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->where('room_id', $roomId)->count() < (int) $room->capacity, 409, 'The examination room has reached capacity.');
                DB::table('exam_seat_allocations')->insert([
                    'tenant_id' => $tenantId, 'session_id' => $sessionId, 'candidate_id' => $candidateId,
                    'room_id' => $roomId, 'seat_number' => $seatNumber, 'allocated_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('exams.seat.allocated', $actor, $tenantId, $session, ['candidate_id' => $candidateId, 'room_id' => $roomId, 'seat_number' => $seatNumber]);
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                throw new ConflictHttpException('The candidate, seat, or room allocation was concurrently changed.', $exception);
            }
            throw $exception;
        }
    }
}
