<?php

namespace App\Domain\Examinations;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamCandidate;
use App\Models\ExamCandidateAccommodation;
use App\Models\ExamSession;
use App\Models\SisStudent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExamCandidateGovernanceWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @return array{eligible:bool,checks:array<string,bool>,exception_id:int|null} */
    public function evaluate(ExamCandidate $candidate, ExamSession $session): array
    {
        abort_unless((int) $candidate->cycle_id === (int) $session->cycle_id, 404);
        $student = SisStudent::query()->whereKey($candidate->student_id)->firstOrFail();
        $policy = DB::table('exam_eligibility_policies')->where('tenant_id', $candidate->tenant_id)
            ->where('institution_id', $student->institution_id)->where('active', true)->first();
        $requireStudent = $policy === null || (bool) $policy->require_active_student;
        $requireEnrollment = $policy === null || (bool) $policy->require_confirmed_enrollment;
        $checks = [
            'candidate_registered' => $candidate->status === 'registered' && $candidate->withdrawn_at === null,
            'student_active' => ! $requireStudent || $student->status === 'active',
            'confirmed_course_enrollment' => ! $requireEnrollment || DB::table('course_enrollments as enrollments')
                ->join('course_offerings as offerings', function ($join): void {
                    $join->on('offerings.id', '=', 'enrollments.offering_id')->on('offerings.tenant_id', '=', 'enrollments.tenant_id');
                })->join('course_sections as sections', function ($join): void {
                    $join->on('sections.id', '=', 'enrollments.section_id')->on('sections.tenant_id', '=', 'enrollments.tenant_id');
                })->where('enrollments.tenant_id', $candidate->tenant_id)->where('enrollments.student_id', $candidate->student_id)
                ->where('enrollments.status', 'confirmed')->whereNull('enrollments.dropped_at')
                ->where(fn ($query) => $query->whereNull('enrollments.withdrawn_effective_at')->orWhere('enrollments.withdrawn_effective_at', '>', $session->starts_at_utc))
                ->where('offerings.academic_course_id', $session->academic_course_id)->where('sections.campus_id', $session->campus_id)->exists(),
        ];
        $failedRules = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $approvedExceptions = collect();
        if ($failedRules !== []) {
            $approvedExceptions = DB::table('exam_eligibility_exceptions')->where('tenant_id', $candidate->tenant_id)->where('candidate_id', $candidate->getKey())
                ->where('cycle_id', $candidate->cycle_id)->where('status', 'approved')->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereIn('rule_key', $failedRules)->whereIn('rule_key', ['confirmed_course_enrollment'])->pluck('rule_key')->unique();
        }

        $unresolved = array_diff($failedRules, $approvedExceptions->all());
        $exceptionId = $approvedExceptions->isEmpty() ? null : DB::table('exam_eligibility_exceptions')->where('tenant_id', $candidate->tenant_id)->where('candidate_id', $candidate->getKey())->where('cycle_id', $candidate->cycle_id)->where('status', 'approved')->whereNull('revoked_at')->whereIn('rule_key', $approvedExceptions)->latest('reviewed_at')->value('id');

        return ['eligible' => $failedRules === [] || $unresolved === [], 'checks' => $checks, 'exception_id' => $exceptionId === null ? null : (int) $exceptionId];
    }

    public function requestEligibilityException(int $candidateId, int $cycleId, string $ruleKey, string $reason, User $actor): int
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $candidate = ExamCandidate::query()->where('cycle_id', $cycleId)->findOrFail($candidateId);
        abort_unless($this->isStudentActor($candidate, $actor), 403);
        abort_unless(in_array($ruleKey, ['confirmed_course_enrollment'], true), 422, 'This eligibility rule cannot be overridden through a student request.');
        $session = ExamSession::query()->where('cycle_id', $cycleId)->orderBy('starts_at_utc')->firstOrFail();
        abort_unless(! $this->evaluate($candidate, $session)['checks']['confirmed_course_enrollment'], 409, 'The requested eligibility exception does not match a current failed policy check.');

        return DB::transaction(function () use ($tenantId, $candidate, $ruleKey, $reason, $actor): int {
            $locked = ExamCandidate::query()->lockForUpdate()->findOrFail($candidate->getKey());
            $pending = DB::table('exam_eligibility_exceptions')->where('tenant_id', $tenantId)->where('candidate_id', $locked->getKey())->where('cycle_id', $locked->cycle_id)->where('rule_key', $ruleKey)->where('status', 'pending')->exists();
            if ($pending) {
                throw ValidationException::withMessages(['reason' => 'A request for this exception is already awaiting review.']);
            }
            $id = DB::table('exam_eligibility_exceptions')->insertGetId(['tenant_id' => $tenantId, 'candidate_id' => $locked->getKey(), 'cycle_id' => $locked->cycle_id, 'rule_key' => $ruleKey, 'student_reason' => strip_tags($reason), 'status' => 'pending', 'requested_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('exams.eligibility.exception.requested', $actor, $tenantId, null, ['candidate_id' => (int) $locked->getKey(), 'cycle_id' => (int) $locked->cycle_id, 'exception_id' => $id, 'rule_key' => $ruleKey]);
            $this->queue('exam.eligibility_exception.requested', 'exam.eligibility_exception.requested:'.$id, ['exception_id' => $id]);

            return $id;
        }, 3);
    }

    public function decideEligibilityException(int $id, string $decision, string $reason, ?string $expiresAt, User $actor): void
    {
        $this->requirePermission('exams.eligibility.manage');
        DB::transaction(function () use ($id, $decision, $reason, $expiresAt, $actor): void {
            $exception = DB::table('exam_eligibility_exceptions')->where('tenant_id', $this->context->tenant()->getKey())->where('id', $id)->lockForUpdate()->first();
            abort_unless($exception !== null, 404);
            abort_unless((int) $exception->requested_by !== (int) $actor->getKey(), 403, 'The requester cannot review their own eligibility exception.');
            abort_unless($exception->status === 'pending', 409, 'This eligibility exception has already been decided.');
            DB::table('exam_eligibility_exceptions')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => strip_tags($reason), 'expires_at' => $decision === 'approved' ? $expiresAt : null, 'updated_at' => now()]);
            $this->audit->record('exams.eligibility.exception.'.$decision, $actor, (int) $this->context->tenant()->getKey(), null, ['exception_id' => $id, 'candidate_id' => (int) $exception->candidate_id, 'cycle_id' => (int) $exception->cycle_id, 'rule_key' => $exception->rule_key]);
            $this->queue('exam.eligibility_exception.decided', 'exam.eligibility_exception.decided:'.$id, ['exception_id' => $id]);
        }, 3);
    }

    public function revokeEligibilityException(int $id, string $reason, User $actor): void
    {
        $this->requirePermission('exams.eligibility.manage');
        DB::transaction(function () use ($id, $reason, $actor): void {
            $exception = DB::table('exam_eligibility_exceptions')->where('tenant_id', $this->context->tenant()->getKey())->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($exception->status === 'approved' && $exception->revoked_at === null, 409);
            DB::table('exam_eligibility_exceptions')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now(), 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => strip_tags($reason), 'updated_at' => now()]);
            $this->audit->record('exams.eligibility.exception.revoked', $actor, (int) $this->context->tenant()->getKey(), null, ['exception_id' => $id, 'candidate_id' => (int) $exception->candidate_id]);
        }, 3);
    }

    /** @param array{accommodation_type:string,extra_time_minutes:int,confidential_details?:string|null} $data */
    public function requestAccommodation(int $cycleId, int $candidateId, array $data, User $actor): int
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $candidate = ExamCandidate::query()->where('cycle_id', $cycleId)->findOrFail($candidateId);
        abort_unless($this->isStudentActor($candidate, $actor), 403);

        return DB::transaction(function () use ($candidate, $data, $actor, $tenantId): int {
            $locked = ExamCandidate::query()->lockForUpdate()->findOrFail($candidate->getKey());
            $exists = DB::table('exam_accommodation_requests')->where('tenant_id', $tenantId)->where('candidate_id', $locked->getKey())->where('status', 'pending')->exists();
            if ($exists) {
                throw ValidationException::withMessages(['accommodation_type' => 'An accommodation request is already awaiting review.']);
            }
            $id = DB::table('exam_accommodation_requests')->insertGetId(['tenant_id' => $tenantId, 'candidate_id' => $locked->getKey(), 'accommodation_type' => $data['accommodation_type'], 'extra_time_minutes' => $data['extra_time_minutes'], 'confidential_details' => isset($data['confidential_details']) ? encrypt(strip_tags($data['confidential_details'])) : null, 'status' => 'pending', 'requested_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('exams.accommodation.requested', $actor, $tenantId, null, ['request_id' => $id, 'candidate_id' => (int) $locked->getKey(), 'type' => $data['accommodation_type']]);
            $this->queue('exam.accommodation.requested', 'exam.accommodation.requested:'.$id, ['request_id' => $id]);

            return $id;
        }, 3);
    }

    public function decideAccommodation(int $id, string $decision, string $reason, ?string $expiresAt, User $actor): void
    {
        $this->requirePermission('exams.accommodation.manage');
        DB::transaction(function () use ($id, $decision, $reason, $expiresAt, $actor): void {
            $tenantId = (int) $this->context->tenant()->getKey();
            $request = DB::table('exam_accommodation_requests')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            abort_unless((int) $request->requested_by !== (int) $actor->getKey(), 403, 'The requester cannot review their own accommodation request.');
            abort_unless($request->status === 'pending', 409, 'This accommodation request has already been decided.');
            $accommodationId = null;
            if ($decision === 'approved') {
                $candidate = ExamCandidate::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($request->candidate_id);
                $revision = (int) ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('accommodation_type', $request->accommodation_type)->max('revision') + 1;
                ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('accommodation_type', $request->accommodation_type)->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);
                $accommodation = ExamCandidateAccommodation::query()->create(['candidate_id' => $candidate->getKey(), 'accommodation_type' => $request->accommodation_type, 'revision' => $revision, 'extra_time_minutes' => $request->extra_time_minutes, 'confidential_details' => $request->confidential_details === null ? null : decrypt($request->confidential_details), 'authorized_by' => $actor->getKey(), 'authorized_at' => now(), 'expires_at' => $expiresAt, 'status' => 'active']);
                $accommodationId = (int) $accommodation->getKey();
            }
            DB::table('exam_accommodation_requests')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => strip_tags($reason), 'accommodation_id' => $accommodationId, 'updated_at' => now()]);
            $this->audit->record('exams.accommodation.'.$decision, $actor, $tenantId, null, ['request_id' => $id, 'candidate_id' => (int) $request->candidate_id, 'accommodation_id' => $accommodationId]);
            $this->queue('exam.accommodation.decided', 'exam.accommodation.decided:'.$id, ['request_id' => $id]);
        }, 3);
    }

    public function revokeAccommodation(int $id, string $reason, User $actor): void
    {
        $this->requirePermission('exams.accommodation.manage');
        DB::transaction(function () use ($id, $reason, $actor): void {
            $tenantId = (int) $this->context->tenant()->getKey();
            $record = ExamCandidateAccommodation::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            abort_unless($record->status === 'active', 409);
            $record->status = 'revoked';
            $record->save();
            DB::table('exam_accommodation_requests')->where('tenant_id', $tenantId)->where('accommodation_id', $id)->update(['status' => 'revoked', 'review_reason' => strip_tags($reason), 'updated_at' => now()]);
            $this->audit->record('exams.accommodation.revoked', $actor, $tenantId, $record, ['candidate_id' => (int) $record->candidate_id, 'accommodation_id' => $id]);
        }, 3);
    }

    /** @return array{document_id:int,verification_code:string} */
    public function issueAdmitDocument(int $sessionId, int $candidateId, User $actor): array
    {
        $this->requirePermission('exams.admit.issue');
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($sessionId, $candidateId, $actor, $tenantId): array {
            $session = ExamSession::query()->with('cycle', 'course')->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($sessionId);
            $candidate = ExamCandidate::query()->with('student')->where('tenant_id', $tenantId)->where('cycle_id', $session->cycle_id)->lockForUpdate()->findOrFail($candidateId);
            abort_unless(in_array($session->status, ['published', 'active'], true) && $session->cycle->status === 'published', 409, 'An admit document requires a published examination session.');
            $eligibility = $this->evaluate($candidate, $session);
            abort_unless($eligibility['eligible'], 422, 'Candidate is not eligible for this examination.');
            $previous = DB::table('exam_admit_documents')->where('tenant_id', $tenantId)->where('candidate_id', $candidateId)->where('session_id', $sessionId)->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($previous as $document) {
                DB::table('exam_admit_documents')->where('id', $document->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $revision = (int) DB::table('exam_admit_documents')->where('tenant_id', $tenantId)->where('candidate_id', $candidateId)->where('session_id', $sessionId)->max('revision') + 1;
            $code = Str::random(48);
            $seat = DB::table('exam_seat_allocations as allocations')->join('academic_rooms as rooms', function ($join): void {
                $join->on('rooms.id', '=', 'allocations.room_id')->on('rooms.tenant_id', '=', 'allocations.tenant_id');
            })->where('allocations.tenant_id', $tenantId)->where('allocations.session_id', $sessionId)->where('allocations.candidate_id', $candidateId)->first(['rooms.name as room_name', 'allocations.seat_number']);
            $accommodations = ExamCandidateAccommodation::query()->where('tenant_id', $tenantId)->where('candidate_id', $candidateId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get(['accommodation_type', 'extra_time_minutes'])->map(static fn (ExamCandidateAccommodation $item): array => ['type' => (string) $item->accommodation_type, 'extra_time_minutes' => (int) $item->extra_time_minutes])->values()->all();
            $snapshot = ['candidate_number' => $candidate->candidate_number, 'student_number' => $candidate->student->student_number, 'session' => $session->title, 'course' => $session->course->title, 'starts_at' => CarbonImmutable::parse($session->starts_at_utc)->toIso8601String(), 'campus_id' => (int) $session->campus_id, 'seat' => $seat === null ? null : ['room' => (string) $seat->room_name, 'number' => (string) $seat->seat_number], 'approved_accommodations' => $accommodations, 'revision' => $revision];
            $documentId = (int) DB::table('exam_admit_documents')->insertGetId(['tenant_id' => $tenantId, 'candidate_id' => $candidateId, 'session_id' => $sessionId, 'revision' => $revision, 'verification_hash' => hash('sha256', $code), 'verification_code_encrypted' => encrypt($code), 'safe_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'issued_by' => $actor->getKey(), 'issued_at' => now(), 'expires_at' => $session->ends_at_utc, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('exams.admit_document.issued', $actor, $tenantId, null, ['document_id' => $documentId, 'candidate_id' => $candidateId, 'session_id' => $sessionId, 'revision' => $revision]);
            $this->queue('exam.admit_document.issued', 'exam.admit_document.issued:'.$documentId, ['document_id' => $documentId]);

            return ['document_id' => $documentId, 'verification_code' => $code];
        }, 3);
    }

    public function revokeAdmitDocument(int $id, string $reason, User $actor): void
    {
        $this->requirePermission('exams.admit.issue');
        DB::transaction(function () use ($id, $reason, $actor): void {
            $tenantId = (int) $this->context->tenant()->getKey();
            $document = DB::table('exam_admit_documents')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($document !== null && $document->revoked_at === null, 404);
            DB::table('exam_admit_documents')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->audit->record('exams.admit_document.revoked', $actor, $tenantId, null, ['document_id' => $id, 'candidate_id' => (int) $document->candidate_id, 'session_id' => (int) $document->session_id, 'reason' => strip_tags($reason)]);
        }, 3);
    }

    /** Public verifier intentionally returns no candidate or exam details. */
    public function verifyAdmitDocument(string $code): bool
    {
        if (strlen($code) < 32 || strlen($code) > 96) {
            return false;
        }
        $hash = hash('sha256', $code);
        $document = DB::table('exam_admit_documents as documents')->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.id', '=', 'documents.candidate_id')->on('candidates.tenant_id', '=', 'documents.tenant_id');
        })->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'documents.session_id')->on('sessions.tenant_id', '=', 'documents.tenant_id');
        })->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
        })->where('documents.verification_hash', $hash)->first(['documents.id', 'documents.tenant_id', 'documents.revoked_at', 'documents.expires_at', 'candidates.status as candidate_status', 'candidates.withdrawn_at', 'sessions.status as session_status', 'cycles.status as cycle_status']);
        $valid = $document !== null && $document->revoked_at === null && ($document->expires_at === null || now()->lt($document->expires_at)) && $document->candidate_status === 'registered' && $document->withdrawn_at === null && in_array($document->session_status, ['published', 'active'], true) && $document->cycle_status === 'published';
        $tenantId = $document ? (int) $document->tenant_id : null;
        $this->audit->record('exams.admit_document.verified', null, $tenantId, null, ['valid' => $valid, 'document_id' => $valid ? (int) $document->id : null]);

        return $valid;
    }

    /** @return array{snapshot:array<string,mixed>,verification_code:string,revision:int} */
    public function ownAdmitDocument(int $id, User $actor): array
    {
        $membership = $this->context->membership();
        $document = DB::table('exam_admit_documents as docs')->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.id', '=', 'docs.candidate_id')->on('candidates.tenant_id', '=', 'docs.tenant_id');
        })->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('docs.tenant_id', $membership->tenant_id)->where('docs.id', $id)->where('students.institution_id', $membership->institution_id)->where('students.user_id', $actor->getKey())->whereNull('docs.revoked_at')->where(fn ($query) => $query->whereNull('docs.expires_at')->orWhere('docs.expires_at', '>', now()))->first(['docs.safe_snapshot', 'docs.verification_code_encrypted', 'docs.revision']);
        abort_unless($document !== null, 404);

        return ['snapshot' => json_decode((string) $document->safe_snapshot, true, 512, JSON_THROW_ON_ERROR), 'verification_code' => decrypt($document->verification_code_encrypted), 'revision' => (int) $document->revision];
    }

    private function isStudentActor(ExamCandidate $candidate, User $actor): bool
    {
        $student = SisStudent::query()->whereKey($candidate->student_id)->firstOrFail();

        return (int) $student->user_id === (int) $actor->getKey()
            && DB::table('tenant_memberships')->where('tenant_id', $candidate->tenant_id)->where('institution_id', $student->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('revoked_at')->exists();
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->context->membership()->hasPermission($permission), 403);
    }

    /** @param array<string, int|string> $payload */
    private function queue(string $type, string $key, array $payload): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore(['tenant_id' => $this->context->tenant()->getKey(), 'event_key' => $key, 'event_type' => $type, 'safe_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
