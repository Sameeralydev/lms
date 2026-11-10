<?php

namespace App\Domain\Examinations;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExaminationResultService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param list<array{component_id:int,weight_percent:string}> $components */
    public function savePolicy(int $sessionId, string $writtenWeight, ?string $passPercent, int $recheckWindowDays, array $components, User $actor): int
    {
        abort_unless($this->membership()->hasPermission('exams.policy.manage'), 403);
        $tenantId = (int) $this->membership()->tenant_id;
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->first();
        abort_unless($session !== null, 404);
        $this->assertCampus((int) $session->campus_id);

        $written = self::hundredths($writtenWeight);
        $pass = $passPercent === null ? null : self::hundredths($passPercent);
        if ($written < 0 || $written > 10000 || ($pass !== null && ($pass < 0 || $pass > 10000)) || $recheckWindowDays < 1 || $recheckWindowDays > 90) {
            throw ValidationException::withMessages(['policy' => 'Weights, pass threshold, or recheck window are outside the supported range.']);
        }

        $normalized = [];
        $seen = [];
        $weightTotal = $written;
        foreach ($components as $component) {
            $id = (int) $component['component_id'];
            $weight = self::hundredths($component['weight_percent']);
            if (isset($seen[$id]) || $weight <= 0 || $weight > 10000) {
                throw ValidationException::withMessages(['components' => 'Each configured component must be unique and have a positive weight.']);
            }
            $valid = DB::table('exam_components')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->where('id', $id)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['components' => 'A selected component does not belong to this examination session.']);
            }
            $seen[$id] = true;
            $weightTotal += $weight;
            $normalized[] = ['id' => $id, 'weight' => self::markString($weight)];
        }
        if ($weightTotal !== 10000) {
            throw ValidationException::withMessages(['policy' => 'Written and component weights must total exactly 100 percent.']);
        }

        return DB::transaction(function () use ($tenantId, $sessionId, $written, $pass, $recheckWindowDays, $normalized, $actor): int {
            $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->lockForUpdate()->first();
            abort_unless($session !== null, 404);
            $latest = DB::table('exam_result_policies')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->orderByDesc('version')->first();
            $version = ((int) ($latest->version ?? 0)) + 1;
            $policyId = DB::table('exam_result_policies')->insertGetId([
                'tenant_id' => $tenantId, 'session_id' => $sessionId, 'version' => $version,
                'written_weight' => self::markString($written), 'pass_percent' => $pass === null ? null : self::markString($pass),
                'recheck_window_days' => $recheckWindowDays, 'effective_at' => now(), 'configured_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($normalized as $component) {
                DB::table('exam_result_policy_components')->insert([
                    'tenant_id' => $tenantId, 'policy_id' => $policyId, 'component_id' => $component['id'],
                    'weight_percent' => $component['weight'], 'required' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('exams.result_policy.configured', $actor, $tenantId, null, ['session_id' => $sessionId, 'policy_id' => $policyId, 'policy_version' => $version, 'weight_count' => count($normalized) + ($written > 0 ? 1 : 0)]);

            return $policyId;
        }, 3);
    }

    /** @return array{compiled:int,ready:int,pending:int,absent:int,unchanged:int} */
    public function compileSession(int $sessionId, User $actor): array
    {
        abort_unless($this->membership()->hasPermission('exams.results.compile'), 403);
        $tenantId = (int) $this->membership()->tenant_id;
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->first();
        abort_unless($session !== null, 404);
        $this->assertCampus((int) $session->campus_id);
        $candidates = DB::table('exam_candidates')->where('tenant_id', $tenantId)->where('cycle_id', $session->cycle_id)->where('status', 'registered')->whereNull('withdrawn_at')->orderBy('id')->pluck('id');
        $totals = ['compiled' => 0, 'ready' => 0, 'pending' => 0, 'absent' => 0, 'unchanged' => 0];
        foreach ($candidates as $candidateId) {
            $state = $this->compileCandidate($sessionId, (int) $candidateId, $actor);
            $totals[$state]++;
            $totals['compiled'] += $state === 'unchanged' ? 0 : 1;
        }

        return $totals;
    }

    /** @param array<string, bool|string> $checklist */
    public function approve(int $resultId, array $checklist, string $reason, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.results.approve'), 403);
        foreach (['scores_verified', 'attendance_verified', 'paper_version_verified'] as $key) {
            if (($checklist[$key] ?? false) !== true && ! in_array(strtolower((string) ($checklist[$key] ?? '')), ['1', 'true', 'yes', 'on'], true)) {
                throw ValidationException::withMessages(['checklist' => 'Complete all result verification checklist items before approval.']);
            }
        }
        DB::transaction(function () use ($resultId, $checklist, $reason, $actor): void {
            $tenantId = (int) $this->membership()->tenant_id;
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->lockForUpdate()->first();
            abort_unless($result !== null, 404);
            $this->assertResultCampus($result);
            abort_unless($result->status === 'approval_pending' && $result->total_marks !== null, 409, 'Only a fully compiled result can be approved.');
            abort_unless((int) $result->compiled_by !== (int) $actor->getKey(), 403, 'The result compiler cannot approve their own result.');
            $snapshot = json_decode((string) $result->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(is_array($snapshot) && ($snapshot['missing'] ?? []) === [] && ($snapshot['review_pending'] ?? []) === [] && ($snapshot['moderation_pending'] ?? []) === [], 409);
            DB::table('exam_result_approvals')->insert([
                'tenant_id' => $tenantId, 'result_id' => $resultId, 'result_version' => $result->version,
                'reviewer_id' => $actor->getKey(), 'decision' => 'approved', 'checklist' => json_encode($checklist, JSON_THROW_ON_ERROR),
                'reason' => mb_substr(trim($reason), 0, 2000), 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('exams.result.approved', $actor, $tenantId, null, ['result_id' => $resultId, 'result_version' => (int) $result->version]);
        }, 3);
    }

    public function release(int $resultId, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.results.release'), 403);
        DB::transaction(function () use ($resultId, $actor): void {
            $tenantId = (int) $this->membership()->tenant_id;
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->lockForUpdate()->first();
            abort_unless($result !== null, 404);
            $this->assertResultCampus($result);
            abort_unless($result->status === 'approved' && $result->approved_by !== null, 409, 'Only independently approved results can be released.');
            abort_unless((int) $result->compiled_by !== (int) $actor->getKey() && (int) $result->approved_by !== (int) $actor->getKey(), 403, 'The compiler or approver cannot release this result.');
            $policy = DB::table('exam_result_policies')->where('tenant_id', $tenantId)->where('id', $result->policy_id)->firstOrFail();
            $releasedAt = now();
            DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->update([
                'status' => 'released', 'released_by' => $actor->getKey(), 'released_at' => $releasedAt,
                'recheck_until' => $releasedAt->copy()->addDays((int) $policy->recheck_window_days), 'updated_at' => $releasedAt,
            ]);
            DB::table('quiz_outbox_events')->insertOrIgnore([
                'tenant_id' => $tenantId, 'event_key' => 'exam.result.released:'.$resultId.':v'.$result->version,
                'event_type' => 'exam.result_released', 'safe_payload' => json_encode(['result_id' => $resultId, 'version' => (int) $result->version], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => $releasedAt, 'created_at' => $releasedAt, 'updated_at' => $releasedAt,
            ]);
            $this->audit->record('exams.result.released', $actor, $tenantId, null, ['result_id' => $resultId, 'result_version' => (int) $result->version]);
        }, 3);
    }

    public function requestRecheck(int $resultId, string $type, string $reason, User $actor): int
    {
        abort_unless($this->membership()->hasPermission('exams.recheck.request'), 403);
        if (! in_array($type, ['clerical', 'calculation', 'review', 'appeal'], true) || trim($reason) === '' || mb_strlen($reason) > 4000) {
            throw ValidationException::withMessages(['reason' => 'Choose a supported appeal or recheck type and provide a clear reason.']);
        }

        return DB::transaction(function () use ($resultId, $type, $reason, $actor): int {
            $tenantId = (int) $this->membership()->tenant_id;
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->lockForUpdate()->first();
            abort_unless($result !== null && $result->status === 'released', 404);
            $this->assertResultCampus($result);
            $owns = DB::table('exam_candidates')->join('sis_students', function ($join): void {
                $join->on('sis_students.id', '=', 'exam_candidates.student_id')->on('sis_students.tenant_id', '=', 'exam_candidates.tenant_id');
            })->where('exam_candidates.tenant_id', $tenantId)->where('exam_candidates.id', $result->candidate_id)->where('sis_students.user_id', $actor->getKey())->where('sis_students.status', 'active')->exists();
            abort_unless($owns, 404);
            abort_unless($result->recheck_until !== null && now()->lte($result->recheck_until), 409, 'The institution recheck period has closed.');
            $active = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('result_id', $resultId)->whereIn('status', ['submitted', 'assigned', 'approval_pending', 'escalated', 'reopened'])->exists();
            abort_unless(! $active, 409, 'An active recheck request already exists for this result.');
            $requestId = DB::table('exam_recheck_requests')->insertGetId([
                'tenant_id' => $tenantId, 'result_id' => $resultId, 'result_version' => $result->version,
                'student_user_id' => $actor->getKey(), 'request_type' => $type, 'reason' => trim($reason), 'status' => 'submitted',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $isAppeal = $type === 'appeal';
            DB::table('quiz_outbox_events')->insertOrIgnore([
                'tenant_id' => $tenantId, 'event_key' => 'exam.'.($isAppeal ? 'appeal' : 'recheck').'.requested:'.$requestId,
                'event_type' => $isAppeal ? 'exam.appeal_requested' : 'exam.recheck_requested', 'safe_payload' => json_encode(['recheck_id' => $requestId], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($isAppeal ? 'exams.appeal.submitted' : 'exams.recheck.requested', $actor, $tenantId, null, ['result_id' => $resultId, 'recheck_id' => $requestId, 'request_type' => $type]);
            if ($isAppeal) {
                $this->appendAppealHistory($tenantId, $requestId, $actor, 'submitted', null, 'submitted', (int) $result->version, ['request_type' => 'appeal']);
            }

            return (int) $requestId;
        }, 3);
    }

    public function assignRecheck(int $requestId, int $reviewerId, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.recheck.review'), 403);
        $tenantId = (int) $this->membership()->tenant_id;
        $targetHasPermission = DB::table('tenant_memberships')->join('membership_role', function ($join): void {
            $join->on('membership_role.tenant_id', '=', 'tenant_memberships.tenant_id')->on('membership_role.membership_id', '=', 'tenant_memberships.id');
        })->join('permission_role', 'permission_role.role_id', '=', 'membership_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.user_id', $reviewerId)->where('tenant_memberships.status', 'active')->whereNull('tenant_memberships.revoked_at')->where('permissions.key', 'exams.recheck.review')->exists();
        abort_unless($targetHasPermission, 422, 'The assigned reviewer must have an active examination recheck role.');

        DB::transaction(function () use ($requestId, $reviewerId, $actor, $tenantId): void {
            $request = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            abort_unless(in_array($request->status, ['submitted', 'assigned', 'escalated', 'reopened'], true), 409);
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $request->result_id)->firstOrFail();
            $this->assertResultCampus($result);
            $reviewerMembership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $reviewerId)->where('status', 'active')->whereNull('revoked_at')->first(['campus_id']);
            $sessionCampus = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $result->session_id)->value('campus_id');
            abort_unless($reviewerMembership !== null && ($reviewerMembership->campus_id === null || (int) $reviewerMembership->campus_id === (int) $sessionCampus), 422, 'The reviewer must be authorized for the examination campus.');
            abort_unless((int) $result->compiled_by !== $reviewerId && (int) ($result->approved_by ?? 0) !== $reviewerId, 422, 'A compiler or approver cannot independently review the same result.');
            DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->update(['status' => 'assigned', 'assigned_reviewer_id' => $reviewerId, 'updated_at' => now()]);
            $event = $request->request_type === 'appeal' ? 'exams.appeal.assigned' : 'exams.recheck.assigned';
            $this->audit->record($event, $actor, $tenantId, null, ['recheck_id' => $requestId, 'reviewer_id' => $reviewerId]);
            if ($request->request_type === 'appeal') {
                $this->appendAppealHistory($tenantId, $requestId, $actor, 'assigned', (string) $request->status, 'assigned', (int) $request->result_version, ['reviewer_id' => $reviewerId]);
            }
        }, 3);
    }

    public function decideRecheck(int $requestId, string $decision, string $findings, ?string $proposedMarks, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.recheck.review'), 403);
        if (! in_array($decision, ['upheld', 'adjusted', 'denied'], true) || trim($findings) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose a decision and provide documented review findings.']);
        }
        DB::transaction(function () use ($requestId, $decision, $findings, $proposedMarks, $actor): void {
            $tenantId = (int) $this->membership()->tenant_id;
            $request = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request !== null && $request->status === 'assigned' && (int) $request->assigned_reviewer_id === (int) $actor->getKey(), 404);
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $request->result_id)->lockForUpdate()->firstOrFail();
            $this->assertResultCampus($result);
            abort_unless((int) $result->compiled_by !== (int) $actor->getKey() && (int) ($result->approved_by ?? 0) !== (int) $actor->getKey(), 403);
            $proposedCents = null;
            if ($decision === 'adjusted') {
                if ($proposedMarks === null || ($proposedCents = self::hundredths($proposedMarks)) < 0 || $proposedCents > 10000) {
                    throw ValidationException::withMessages(['proposed_marks' => 'A proposed score from 0 to 100 is required for an adjustment.']);
                }
            } elseif ($proposedMarks !== null) {
                throw ValidationException::withMessages(['proposed_marks' => 'Only an adjustment decision may propose revised marks.']);
            }
            $status = $decision === 'adjusted' ? 'approval_pending' : 'decided';
            DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->update([
                'status' => $status, 'review_findings' => mb_substr(trim($findings), 0, 5000), 'proposed_marks' => $proposedCents === null ? null : self::markString($proposedCents),
                'decision' => $decision, 'decided_by' => $actor->getKey(), 'decided_at' => $decision === 'adjusted' ? null : now(), 'updated_at' => now(),
            ]);
            if ($decision !== 'adjusted') {
                $this->queueRecheckDecision($tenantId, $requestId, (int) $request->result_id, $decision);
            }
            $event = $request->request_type === 'appeal' ? 'exams.appeal.decided' : 'exams.recheck.reviewed';
            $this->audit->record($event, $actor, $tenantId, null, ['recheck_id' => $requestId, 'result_id' => (int) $request->result_id, 'decision' => $decision]);
            if ($request->request_type === 'appeal') {
                $this->appendAppealHistory($tenantId, $requestId, $actor, 'decision_recorded', 'assigned', $status, (int) $request->result_version, ['decision' => $decision]);
            }
        }, 3);
    }

    /** Record a lifecycle transition for an appeal without rewriting its audit history. */
    public function transitionAppeal(int $requestId, string $action, string $reason, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.recheck.review'), 403);
        if (! in_array($action, ['escalate', 'close', 'reopen'], true) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Choose a supported appeal action and provide a reason of no more than 2,000 characters.']);
        }

        DB::transaction(function () use ($requestId, $action, $reason, $actor): void {
            $tenantId = (int) $this->membership()->tenant_id;
            $request = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request !== null && $request->request_type === 'appeal', 404);
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $request->result_id)->lockForUpdate()->firstOrFail();
            $this->assertResultCampus($result);

            $from = (string) $request->status;
            $to = match ($action) {
                'escalate' => 'escalated',
                'close' => 'closed',
                'reopen' => 'reopened',
            };
            if ($action === 'escalate') {
                abort_unless($from === 'assigned', 409, 'Only an assigned appeal can be escalated.');
            } elseif ($action === 'close') {
                abort_unless($from === 'decided' && $result->status === 'released', 409, 'An appeal can be closed only after its decision or approved remedy has been released.');
            } else {
                abort_unless($from === 'closed' && $result->status === 'released' && $result->recheck_until !== null && now()->lte($result->recheck_until), 409, 'This appeal is outside the permitted reopening window.');
            }

            DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->update(['status' => $to, 'updated_at' => now()]);
            $cleanReason = mb_substr(trim(strip_tags($reason)), 0, 2000);
            $this->audit->record('exams.appeal.'.$action, $actor, $tenantId, null, [
                'appeal_id' => $requestId,
                'result_id' => (int) $request->result_id,
                'from_status' => $from,
                'to_status' => $to,
                'result_version' => (int) $result->version,
                'reason' => $cleanReason,
            ]);
            $this->appendAppealHistory($tenantId, $requestId, $actor, $action, $from, $to, (int) $result->version);
            $this->queueAppealTransition($tenantId, $requestId, (int) $request->result_id, $action);
        }, 3);
    }

    public function approveRecheck(int $requestId, string $reason, User $actor): int
    {
        abort_unless($this->membership()->hasPermission('exams.results.approve'), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the independent approval of this amendment.']);
        }

        return DB::transaction(function () use ($requestId, $reason, $actor): int {
            $tenantId = (int) $this->membership()->tenant_id;
            $request = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request !== null && $request->status === 'approval_pending' && $request->decision === 'adjusted', 404);
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $request->result_id)->lockForUpdate()->firstOrFail();
            $this->assertResultCampus($result);
            abort_unless((int) $result->version === (int) $request->result_version && $result->status === 'released', 409, 'The released result has changed since this appeal or recheck was submitted. Create a new request against the current result version.');
            abort_unless((int) $request->assigned_reviewer_id !== (int) $actor->getKey() && (int) $result->compiled_by !== (int) $actor->getKey() && (int) ($result->approved_by ?? 0) !== (int) $actor->getKey(), 403, 'The compiler, previous approver, or recheck reviewer cannot approve this amendment.');
            $oldSnapshot = json_decode((string) $result->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $version = (int) $result->version + 1;
            $marks = self::hundredths((string) $request->proposed_marks);
            $snapshot = $oldSnapshot;
            $snapshot['amendment'] = ['request_id' => $requestId, 'type' => $request->request_type, 'reason' => trim($reason), 'previous_marks' => (string) $result->total_marks, 'proposed_marks' => self::markString($marks), 'review_findings' => (string) $request->review_findings];
            $snapshot['total_marks'] = self::markString($marks);
            $policy = DB::table('exam_result_policies')->where('tenant_id', $tenantId)->where('id', $result->policy_id)->firstOrFail();
            $outcome = $policy->pass_percent === null ? 'complete' : ($marks >= self::hundredths((string) $policy->pass_percent) ? 'pass' : 'fail');
            $snapshot['outcome'] = $outcome;
            DB::table('exam_result_versions')->insert(['tenant_id' => $tenantId, 'result_id' => $result->id, 'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'change_reason' => mb_substr(trim($reason), 0, 500), 'created_by' => $actor->getKey(), 'created_at' => now()]);
            DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $result->id)->update([
                'version' => $version, 'total_marks' => self::markString($marks), 'percentage' => self::markString($marks), 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'status' => 'approved', 'outcome' => $outcome, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'released_by' => null, 'released_at' => null, 'recheck_until' => null, 'updated_at' => now(),
            ]);
            DB::table('exam_result_approvals')->insert(['tenant_id' => $tenantId, 'result_id' => $result->id, 'result_version' => $version, 'reviewer_id' => $actor->getKey(), 'decision' => 'approved', 'checklist' => json_encode(['independent_recheck_approval' => true], JSON_THROW_ON_ERROR), 'reason' => mb_substr(trim($reason), 0, 2000), 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->update(['status' => 'decided', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'final_result_version' => $version, 'updated_at' => now()]);
            $this->queueRecheckDecision($tenantId, $requestId, (int) $result->id, 'adjusted');
            $this->audit->record($request->request_type === 'appeal' ? 'exams.appeal.amendment_approved' : 'exams.result.amended', $actor, $tenantId, null, ['result_id' => (int) $result->id, 'result_version' => $version, 'recheck_id' => $requestId]);
            if ($request->request_type === 'appeal') {
                $this->appendAppealHistory($tenantId, $requestId, $actor, 'remedy_approved', 'approval_pending', 'decided', $version, ['decision' => 'adjusted']);
            }

            return $version;
        }, 3);
    }

    public function reportIncident(int $sessionId, ?int $candidateId, string $type, string $severity, string $description, User $actor): int
    {
        abort_unless($this->membership()->hasPermission('exams.incidents.report'), 403);
        if (! in_array($type, ['technical', 'attendance', 'paper_access', 'interruption', 'complaint', 'other'], true) || ! in_array($severity, ['standard', 'urgent'], true) || trim($description) === '') {
            throw ValidationException::withMessages(['description' => 'Select a supported incident type and provide a factual description.']);
        }
        $tenantId = (int) $this->membership()->tenant_id;
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->first();
        abort_unless($session !== null, 404);
        $this->assertCampus((int) $session->campus_id);
        if ($candidateId !== null) {
            abort_unless(DB::table('exam_candidates')->where('tenant_id', $tenantId)->where('cycle_id', $session->cycle_id)->where('id', $candidateId)->exists(), 404);
        }
        $id = DB::transaction(function () use ($tenantId, $sessionId, $candidateId, $actor, $type, $severity, $description): int {
            $id = (int) DB::table('exam_incidents')->insertGetId(['tenant_id' => $tenantId, 'session_id' => $sessionId, 'candidate_id' => $candidateId, 'reported_by' => $actor->getKey(), 'incident_type' => $type, 'severity' => $severity, 'description' => mb_substr(trim(strip_tags($description)), 0, 5000), 'status' => 'reported', 'created_at' => now(), 'updated_at' => now()]);
            $this->appendIncidentHistory($tenantId, $id, $actor, 'reported', null, 'reported', null, null, 'Incident reported for restricted examination review.');
            $this->audit->record('exams.incident.reported', $actor, $tenantId, null, ['incident_id' => $id, 'session_id' => $sessionId, 'candidate_id' => $candidateId, 'incident_type' => $type, 'severity' => $severity]);
            $this->queueIncidentNotification('exam.incident.reported', 'exam.incident.reported:'.$id, $tenantId, $id);

            return $id;
        }, 3);

        return (int) $id;
    }

    public function reviewIncident(int $incidentId, string $decision, string $notes, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.incidents.review'), 403);
        if (! in_array($decision, ['resolved', 'follow_up', 'no_finding', 'refer'], true) || trim($notes) === '') {
            throw ValidationException::withMessages(['decision' => 'Record a supported incident disposition and review notes.']);
        }
        $tenantId = (int) $this->membership()->tenant_id;
        DB::transaction(function () use ($incidentId, $decision, $notes, $actor, $tenantId): void {
            $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->lockForUpdate()->first();
            abort_unless($incident !== null, 404);
            $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $incident->session_id)->firstOrFail();
            $this->assertCampus((int) $session->campus_id);
            abort_unless($incident->status === 'reported', 409);
            DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->update(['status' => 'reviewed', 'reviewed_by' => $actor->getKey(), 'review_notes' => mb_substr(trim(strip_tags($notes)), 0, 5000), 'decision' => $decision, 'reviewed_at' => now(), 'updated_at' => now()]);
            $this->appendIncidentHistory($tenantId, $incidentId, $actor, 'reviewed', (string) $incident->status, 'reviewed', $decision, null, $notes);
            $this->audit->record('exams.incident.reviewed', $actor, $tenantId, null, ['incident_id' => $incidentId, 'decision' => $decision]);
            $this->queueIncidentNotification('exam.incident.reviewed', 'exam.incident.reviewed:'.$incidentId, $tenantId, $incidentId);
        }, 3);
    }

    /** Apply an audited, tenant-scoped incident lifecycle transition. */
    public function transitionIncident(int $incidentId, string $action, string $notes, ?int $assigneeId, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.incidents.review'), 403);
        if (! in_array($action, ['assign', 'escalate', 'investigate', 'resolve', 'close', 'reopen'], true) || trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => 'Choose a supported incident action and provide a reason.']);
        }

        $tenantId = (int) $this->membership()->tenant_id;
        DB::transaction(function () use ($incidentId, $action, $notes, $assigneeId, $actor, $tenantId): void {
            $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->lockForUpdate()->first();
            abort_unless($incident !== null, 404);
            $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $incident->session_id)->firstOrFail();
            $this->assertCampus((int) $session->campus_id);
            $from = (string) $incident->status;
            $to = $from;
            $event = $action;
            $newAssignee = $incident->assigned_to === null ? null : (int) $incident->assigned_to;
            $allowed = match ($action) {
                'assign' => ['reported', 'reviewed', 'escalated', 'investigating'],
                'escalate' => ['reported', 'reviewed', 'investigating'],
                'investigate' => ['reported', 'reviewed', 'escalated'],
                'resolve' => ['reported', 'reviewed', 'escalated', 'investigating'],
                'close' => ['resolved'],
                'reopen' => ['resolved', 'closed'],
            };
            abort_unless(in_array($from, $allowed, true), 409, 'This incident cannot make that transition from its current state.');

            if ($action === 'assign') {
                if ($assigneeId === null) {
                    throw ValidationException::withMessages(['assignee_id' => 'Select an authorized examination reviewer.']);
                }
                $assignee = TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $assigneeId)->where('status', 'active')->whereNull('revoked_at')
                    ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', (int) $session->campus_id))->first();
                abort_unless($assignee !== null && $assignee->hasPermission('exams.incidents.review'), 422, 'The assignee must have active incident-review authority in this institution.');
                $newAssignee = $assigneeId;
            } elseif ($action === 'escalate') {
                $to = 'escalated';
            } elseif ($action === 'investigate') {
                $to = 'investigating';
                $event = 'investigation_started';
            } elseif ($action === 'reopen') {
                $to = 'investigating';
                $event = 'reopened';
            } elseif ($action === 'resolve') {
                $to = 'resolved';
            } elseif ($action === 'close') {
                $to = 'closed';
            }

            $cleanNotes = mb_substr(trim(strip_tags($notes)), 0, 5000);
            $updates = ['status' => $to, 'assigned_to' => $newAssignee, 'updated_at' => now()];
            if ($action === 'escalate') {
                $updates['escalated_at'] = now();
            }
            if ($action === 'close') {
                $updates['closed_at'] = now();
            }
            if ($event === 'reopened') {
                $updates['closed_at'] = null;
            }
            DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->update($updates);
            $historyId = $this->appendIncidentHistory($tenantId, $incidentId, $actor, $event, $from, $to, null, $newAssignee, $cleanNotes);
            $this->audit->record('exams.incident.'.$event, $actor, $tenantId, null, ['incident_id' => $incidentId, 'history_id' => $historyId, 'from_status' => $from, 'to_status' => $to, 'assigned_to' => $newAssignee]);
            $this->queueIncidentNotification('exam.incident.updated', 'exam.incident.updated:'.$historyId, $tenantId, $incidentId, $historyId);
        }, 3);
    }

    private function appendIncidentHistory(int $tenantId, int $incidentId, User $actor, string $event, ?string $from, ?string $to, ?string $decision, ?int $assigneeId, string $notes): int
    {
        return (int) DB::table('exam_incident_history')->insertGetId([
            'tenant_id' => $tenantId, 'incident_id' => $incidentId, 'actor_id' => $actor->getKey(),
            'event_type' => $event, 'from_status' => $from, 'to_status' => $to, 'decision' => $decision,
            'assigned_to' => $assigneeId, 'safe_notes' => mb_substr(trim(strip_tags($notes)), 0, 5000),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function controllerWorkspace(?int $sessionFilter = null): array
    {
        $membership = $this->membership();
        $tenantId = (int) $membership->tenant_id;
        if ($sessionFilter !== null) {
            abort_unless(DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionFilter)
                ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))->exists(), 404);
        }
        $sessions = DB::table('exam_sessions')->where('tenant_id', $tenantId)->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))->when($sessionFilter !== null, fn ($query) => $query->where('id', $sessionFilter))->orderByDesc('starts_at_utc')->limit(100)->get(['id', 'cycle_id', 'campus_id', 'title', 'status', 'starts_at_utc']);
        $sessionIds = $sessions->pluck('id');
        $policies = DB::table('exam_result_policies')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->orderByDesc('version')->get()->unique('session_id')->map(function ($policy): array {
            $components = DB::table('exam_result_policy_components')->join('exam_components', function ($join): void {
                $join->on('exam_components.id', '=', 'exam_result_policy_components.component_id')->on('exam_components.tenant_id', '=', 'exam_result_policy_components.tenant_id');
            })->where('exam_result_policy_components.tenant_id', $policy->tenant_id)->where('exam_result_policy_components.policy_id', $policy->id)->get(['exam_components.id', 'exam_components.title', 'exam_result_policy_components.weight_percent']);

            return ['sessionId' => (int) $policy->session_id, 'version' => (int) $policy->version, 'writtenWeight' => (string) $policy->written_weight, 'passPercent' => $policy->pass_percent === null ? null : (string) $policy->pass_percent, 'recheckDays' => (int) $policy->recheck_window_days, 'components' => $components->map(fn ($component): array => ['id' => (int) $component->id, 'title' => $component->title, 'weight' => (string) $component->weight_percent])->all()];
        })->values()->all();
        $results = DB::table('exam_results')->join('exam_candidates', function ($join): void {
            $join->on('exam_candidates.id', '=', 'exam_results.candidate_id')->on('exam_candidates.tenant_id', '=', 'exam_results.tenant_id');
        })->join('exam_sessions', function ($join): void {
            $join->on('exam_sessions.id', '=', 'exam_results.session_id')->on('exam_sessions.tenant_id', '=', 'exam_results.tenant_id');
        })->where('exam_results.tenant_id', $tenantId)->whereIn('exam_results.session_id', $sessionIds)->orderByDesc('exam_results.updated_at')->limit(200)->get(['exam_results.id', 'exam_results.session_id', 'exam_results.candidate_id', 'exam_results.version', 'exam_results.status', 'exam_results.outcome', 'exam_results.total_marks', 'exam_results.maximum_marks', 'exam_candidates.candidate_number', 'exam_sessions.title as session_title'])->map(fn ($row): array => ['id' => (int) $row->id, 'sessionId' => (int) $row->session_id, 'candidateId' => (int) $row->candidate_id, 'version' => (int) $row->version, 'status' => $row->status, 'outcome' => $row->outcome, 'marks' => $row->total_marks === null ? null : (string) $row->total_marks, 'maximum' => (string) $row->maximum_marks, 'candidateNumber' => $row->candidate_number, 'session' => $row->session_title])->all();
        $recheckRows = DB::table('exam_recheck_requests')->join('exam_results', function ($join): void {
            $join->on('exam_results.id', '=', 'exam_recheck_requests.result_id')->on('exam_results.tenant_id', '=', 'exam_recheck_requests.tenant_id');
        })->join('exam_candidates', function ($join): void {
            $join->on('exam_candidates.id', '=', 'exam_results.candidate_id')->on('exam_candidates.tenant_id', '=', 'exam_results.tenant_id');
        })->where('exam_recheck_requests.tenant_id', $tenantId)->whereIn('exam_results.session_id', $sessionIds)
            ->whereIn('exam_recheck_requests.status', ['submitted', 'assigned', 'approval_pending', 'escalated', 'reopened', 'decided', 'closed'])
            ->orderBy('exam_recheck_requests.created_at')->limit(100)
            ->get(['exam_recheck_requests.id', 'exam_recheck_requests.status', 'exam_recheck_requests.request_type', 'exam_recheck_requests.reason', 'exam_recheck_requests.assigned_reviewer_id', 'exam_recheck_requests.proposed_marks', 'exam_recheck_requests.student_user_id', 'exam_results.id as result_id', 'exam_results.status as result_status', 'exam_results.session_id', 'exam_candidates.candidate_number']);
        $appealHistory = $recheckRows->isEmpty() ? collect() : DB::table('exam_appeal_history')->where('tenant_id', $tenantId)->whereIn('appeal_id', $recheckRows->where('request_type', 'appeal')->pluck('id'))->orderBy('id')->get(['id', 'appeal_id', 'action', 'from_status', 'to_status', 'result_version', 'created_at'])->groupBy('appeal_id');
        $appealEvidence = $recheckRows->isEmpty() ? collect() : DB::table('exam_appeal_evidence')->where('tenant_id', $tenantId)->whereIn('appeal_id', $recheckRows->where('request_type', 'appeal')->pluck('id'))->orderBy('id')->get(['id', 'appeal_id', 'original_name', 'scan_status'])->groupBy('appeal_id');
        $rechecks = $recheckRows->map(function ($row) use ($appealHistory, $appealEvidence, $membership): array {
            return [
                'id' => (int) $row->id,
                'status' => $row->status,
                'type' => $row->request_type,
                'reason' => $row->reason,
                'reviewerId' => $row->assigned_reviewer_id === null ? null : (int) $row->assigned_reviewer_id,
                'proposedMarks' => $row->proposed_marks === null ? null : (string) $row->proposed_marks,
                'sessionId' => (int) $row->session_id,
                'resultStatus' => (string) $row->result_status,
                'candidateNumber' => $row->candidate_number,
                'canUploadEvidence' => $row->request_type === 'appeal' && ((int) $row->student_user_id === (int) $membership->user_id || $membership->hasPermission('exams.recheck.review')) && $row->status !== 'closed',
                'evidence' => ($appealEvidence->get($row->id) ?? collect())->map(static fn ($item): array => ['id' => (int) $item->id, 'name' => $item->original_name, 'scanStatus' => $item->scan_status])->all(),
                'history' => ($appealHistory->get($row->id) ?? collect())->map(static fn ($event): array => [
                    'id' => (int) $event->id,
                    'action' => (string) $event->action,
                    'from' => $event->from_status,
                    'to' => (string) $event->to_status,
                    'resultVersion' => $event->result_version === null ? null : (int) $event->result_version,
                    'at' => (string) $event->created_at,
                ])->all(),
            ];
        })->all();
        $incidentRows = DB::table('exam_incidents')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->orderByDesc('created_at')->limit(100)->get(['id', 'session_id', 'candidate_id', 'reported_by', 'incident_type', 'severity', 'description', 'status', 'decision', 'assigned_to']);
        $evidenceByIncident = $incidentRows->isEmpty() ? collect() : DB::table('exam_incident_evidence')->where('tenant_id', $tenantId)->whereIn('incident_id', $incidentRows->pluck('id'))->orderBy('id')->get(['id', 'incident_id', 'original_name', 'scan_status'])->groupBy('incident_id');
        $incidentHistory = $incidentRows->isEmpty() ? collect() : DB::table('exam_incident_history')->where('tenant_id', $tenantId)->whereIn('incident_id', $incidentRows->pluck('id'))->orderBy('id')->get(['id', 'incident_id', 'event_type', 'from_status', 'to_status', 'decision', 'safe_notes', 'created_at'])->groupBy('incident_id');
        $incidentReviewers = DB::table('tenant_memberships')->join('membership_role', function ($join): void {
            $join->on('membership_role.tenant_id', '=', 'tenant_memberships.tenant_id')->on('membership_role.membership_id', '=', 'tenant_memberships.id');
        })->join('permission_role', 'permission_role.role_id', '=', 'membership_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.status', 'active')->whereNull('tenant_memberships.revoked_at')->where('permissions.key', 'exams.incidents.review')->distinct()->orderBy('users.name')->limit(100)->get(['users.id', 'users.name', 'tenant_memberships.campus_id'])->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name, 'campusId' => $row->campus_id === null ? null : (int) $row->campus_id]);
        $incidents = $incidentRows->map(function ($row) use ($sessions, $incidentReviewers, $evidenceByIncident, $incidentHistory): array {
            $campusId = $sessions->firstWhere('id', $row->session_id)?->campus_id;

            return ['id' => (int) $row->id, 'sessionId' => (int) $row->session_id, 'campusId' => $campusId === null ? null : (int) $campusId, 'candidateId' => $row->candidate_id === null ? null : (int) $row->candidate_id, 'reportedBy' => (int) $row->reported_by, 'type' => $row->incident_type, 'severity' => $row->severity, 'description' => $row->description, 'status' => $row->status, 'decision' => $row->decision, 'assignedTo' => $row->assigned_to === null ? null : (int) $row->assigned_to, 'reviewers' => $incidentReviewers->filter(static fn ($reviewer): bool => $reviewer['campusId'] === null || $reviewer['campusId'] === ($campusId === null ? null : (int) $campusId))->values()->map(static fn ($reviewer): array => ['id' => $reviewer['id'], 'name' => $reviewer['name']])->all(), 'evidence' => ($evidenceByIncident->get($row->id) ?? collect())->map(static fn ($item): array => ['id' => (int) $item->id, 'name' => $item->original_name, 'scanStatus' => $item->scan_status])->all(), 'history' => ($incidentHistory->get($row->id) ?? collect())->map(static fn ($item): array => ['id' => (int) $item->id, 'event' => $item->event_type, 'from' => $item->from_status, 'to' => $item->to_status, 'decision' => $item->decision, 'notes' => $item->safe_notes, 'at' => $item->created_at])->all()];
        })->all();
        $releasedOutcomes = DB::table('exam_results')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->where('status', 'released')->select('outcome', DB::raw('COUNT(*) as total'))->groupBy('outcome')->orderBy('outcome')->get()->map(fn ($row): array => ['outcome' => (string) $row->outcome, 'count' => (int) $row->total])->all();
        $subjectPerformance = DB::table('exam_results as results')->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'results.session_id')->on('sessions.tenant_id', '=', 'results.tenant_id');
        })->join('campuses', function ($join): void {
            $join->on('campuses.id', '=', 'sessions.campus_id')->on('campuses.tenant_id', '=', 'sessions.tenant_id');
        })->join('academic_courses as courses', function ($join): void {
            $join->on('courses.id', '=', 'sessions.academic_course_id')->on('courses.tenant_id', '=', 'sessions.tenant_id');
        })->where('results.tenant_id', $tenantId)->whereIn('results.session_id', $sessionIds)->where('results.status', 'released')
            ->when($membership->campus_id !== null, fn ($query) => $query->where('sessions.campus_id', $membership->campus_id))
            ->select('campuses.id as campus_id', 'campuses.name as campus', 'courses.id as course_id', 'courses.code as course_code', 'courses.title as course_title', DB::raw('COUNT(results.id) as candidate_count'), DB::raw('AVG(results.percentage) as average_percentage'), DB::raw("SUM(CASE WHEN results.outcome = 'pass' THEN 1 ELSE 0 END) as passed_count"))
            ->groupBy('campuses.id', 'campuses.name', 'courses.id', 'courses.code', 'courses.title')->orderBy('campuses.name')->orderBy('courses.code')->get()
            ->map(static function ($row): array {
                $count = (int) $row->candidate_count;

                return ['campus' => (string) $row->campus, 'courseCode' => (string) $row->course_code, 'courseTitle' => (string) $row->course_title,
                    'releasedCandidates' => $count, 'averagePercentage' => round((float) $row->average_percentage, 2),
                    'passRatePercent' => $count === 0 ? null : round(((int) $row->passed_count / $count) * 100, 1)];
            })->all();
        $componentProgress = DB::table('exam_component_allocations')->join('exam_components', function ($join): void {
            $join->on('exam_components.id', '=', 'exam_component_allocations.component_id')->on('exam_components.tenant_id', '=', 'exam_component_allocations.tenant_id');
        })->where('exam_component_allocations.tenant_id', $tenantId)->whereIn('exam_components.session_id', $sessionIds)->select('exam_component_allocations.status', DB::raw('COUNT(*) as total'))->groupBy('exam_component_allocations.status')->orderBy('exam_component_allocations.status')->get()->map(fn ($row): array => ['status' => (string) $row->status, 'count' => (int) $row->total])->all();

        $cycleIds = $sessions->pluck('cycle_id')->unique();
        $registrationByCycle = DB::table('exam_candidates as candidates')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('candidates.tenant_id', $tenantId)->whereIn('candidates.cycle_id', $cycleIds)
            ->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id))
            ->select('candidates.cycle_id', DB::raw('COUNT(*) as total'))->groupBy('candidates.cycle_id')->pluck('total', 'candidates.cycle_id');
        $attendanceBySession = DB::table('exam_attendance_records')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)
            ->select('session_id', 'attendance_status', DB::raw('COUNT(*) as total'))->groupBy('session_id', 'attendance_status')->get()->groupBy('session_id');
        $allocationBySession = DB::table('exam_component_allocations')->join('exam_components', function ($join): void {
            $join->on('exam_components.id', '=', 'exam_component_allocations.component_id')->on('exam_components.tenant_id', '=', 'exam_component_allocations.tenant_id');
        })->where('exam_component_allocations.tenant_id', $tenantId)->whereIn('exam_components.session_id', $sessionIds)
            ->select('exam_components.session_id', 'exam_component_allocations.status', DB::raw('COUNT(*) as total'))
            ->groupBy('exam_components.session_id', 'exam_component_allocations.status')->get()->groupBy('session_id');
        $moderationBySession = DB::table('exam_component_allocations as first_mark')->join('exam_component_allocations as second_mark', function ($join): void {
            $join->on('second_mark.tenant_id', '=', 'first_mark.tenant_id')->on('second_mark.component_id', '=', 'first_mark.component_id')->on('second_mark.candidate_id', '=', 'first_mark.candidate_id')->where('second_mark.marking_round', '=', 2);
        })->join('exam_components as components', function ($join): void {
            $join->on('components.id', '=', 'first_mark.component_id')->on('components.tenant_id', '=', 'first_mark.tenant_id');
        })->leftJoin('exam_component_moderation_decisions as decisions', function ($join): void {
            $join->on('decisions.tenant_id', '=', 'first_mark.tenant_id')->on('decisions.component_id', '=', 'first_mark.component_id')->on('decisions.candidate_id', '=', 'first_mark.candidate_id');
        })->where('first_mark.tenant_id', $tenantId)->whereIn('components.session_id', $sessionIds)->where('first_mark.marking_round', 1)
            ->where('first_mark.status', 'submitted')->where('second_mark.status', 'submitted')->where('components.second_marking_required', true)
            ->whereNull('decisions.id')->select('components.session_id', DB::raw('COUNT(*) as total'))->groupBy('components.session_id')->pluck('total', 'components.session_id');
        $resultCountsBySession = DB::table('exam_results')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)
            ->select('session_id', 'status', DB::raw('COUNT(*) as total'))->groupBy('session_id', 'status')->get()->groupBy('session_id');
        $recheckCountsBySession = DB::table('exam_recheck_requests')->join('exam_results', function ($join): void {
            $join->on('exam_results.id', '=', 'exam_recheck_requests.result_id')->on('exam_results.tenant_id', '=', 'exam_recheck_requests.tenant_id');
        })->where('exam_recheck_requests.tenant_id', $tenantId)->whereIn('exam_results.session_id', $sessionIds)
            ->select('exam_results.session_id', 'exam_recheck_requests.status', DB::raw('COUNT(*) as total'))
            ->groupBy('exam_results.session_id', 'exam_recheck_requests.status')->get()->groupBy('session_id');
        $incidentCountsBySession = DB::table('exam_incidents')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)
            ->select('session_id', 'status', DB::raw('COUNT(*) as total'))->groupBy('session_id', 'status')->get()->groupBy('session_id');
        $seatCountsBySession = DB::table('exam_seat_allocations')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)
            ->select('session_id', DB::raw('COUNT(*) as total'))->groupBy('session_id')->pluck('total', 'session_id');
        $seatingRoomsBySession = DB::table('exam_seat_allocations as allocations')->join('academic_rooms as rooms', function ($join): void {
            $join->on('rooms.id', '=', 'allocations.room_id')->on('rooms.tenant_id', '=', 'allocations.tenant_id');
        })->where('allocations.tenant_id', $tenantId)->whereIn('allocations.session_id', $sessionIds)
            ->select('allocations.session_id', 'allocations.room_id', 'rooms.capacity')->distinct()->get()->groupBy('session_id');
        $invigilatorCountsBySession = DB::table('exam_invigilator_assignments')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)
            ->select('session_id', 'status', DB::raw('COUNT(*) as total'))->groupBy('session_id', 'status')->get()->groupBy('session_id');
        $exceptionCountsByCycle = DB::table('exam_eligibility_exceptions as exceptions')->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.id', '=', 'exceptions.candidate_id')->on('candidates.tenant_id', '=', 'exceptions.tenant_id');
        })->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('exceptions.tenant_id', $tenantId)->whereIn('exceptions.cycle_id', $cycleIds)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id))
            ->select('exceptions.cycle_id', 'exceptions.status', DB::raw('COUNT(*) as total'))->groupBy('exceptions.cycle_id', 'exceptions.status')->get()->groupBy('cycle_id');
        $approvedAccommodationsByCycle = DB::table('exam_accommodation_requests as requests')->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.id', '=', 'requests.candidate_id')->on('candidates.tenant_id', '=', 'requests.tenant_id');
        })->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('requests.tenant_id', $tenantId)->whereIn('requests.status', ['approved'])
            ->whereIn('candidates.cycle_id', $cycleIds)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id))
            ->select('candidates.cycle_id', DB::raw('COUNT(*) as total'))->groupBy('candidates.cycle_id')->pluck('total', 'candidates.cycle_id');
        $dateSheetsByCycle = DB::table('exam_date_sheets')->where('tenant_id', $tenantId)->whereIn('cycle_id', $cycleIds)->get(['cycle_id', 'status'])->keyBy('cycle_id');
        $activeAdmitsBySession = DB::table('exam_admit_documents as documents')->where('documents.tenant_id', $tenantId)
            ->whereIn('documents.session_id', $sessionIds)->whereNull('documents.revoked_at')
            ->where(fn ($query) => $query->whereNull('documents.expires_at')->orWhere('documents.expires_at', '>', now()))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('exam_admit_documents as newer')
                    ->whereColumn('newer.tenant_id', 'documents.tenant_id')->whereColumn('newer.candidate_id', 'documents.candidate_id')
                    ->whereColumn('newer.session_id', 'documents.session_id')->whereColumn('newer.revision', '>', 'documents.revision');
            })->select('documents.session_id', DB::raw('COUNT(*) as total'))->groupBy('documents.session_id')->pluck('total', 'documents.session_id');
        $appealCountsBySession = DB::table('exam_recheck_requests as appeals')->join('exam_results as results', function ($join): void {
            $join->on('results.id', '=', 'appeals.result_id')->on('results.tenant_id', '=', 'appeals.tenant_id');
        })->where('appeals.tenant_id', $tenantId)->where('appeals.request_type', 'appeal')->whereIn('results.session_id', $sessionIds)
            ->whereIn('appeals.status', ['submitted', 'assigned', 'approval_pending'])
            ->select('results.session_id', DB::raw('COUNT(*) as total'))->groupBy('results.session_id')->pluck('total', 'results.session_id');
        $cycleStates = DB::table('exam_cycles')->where('tenant_id', $tenantId)->whereIn('id', $cycleIds)->get(['id', 'category'])->keyBy('id');
        $campusAndCourseNames = DB::table('exam_sessions as sessions')->join('campuses', function ($join): void {
            $join->on('campuses.id', '=', 'sessions.campus_id')->on('campuses.tenant_id', '=', 'sessions.tenant_id');
        })->join('academic_courses as courses', function ($join): void {
            $join->on('courses.id', '=', 'sessions.academic_course_id')->on('courses.tenant_id', '=', 'sessions.tenant_id');
        })->where('sessions.tenant_id', $tenantId)->whereIn('sessions.id', $sessionIds)
            ->get(['sessions.id', 'campuses.name as campus_name', 'courses.code as course_code', 'courses.title as course_title'])->keyBy('id');
        $operationalReports = $sessions->map(function ($session) use ($registrationByCycle, $attendanceBySession, $allocationBySession, $moderationBySession, $resultCountsBySession, $recheckCountsBySession, $incidentCountsBySession, $campusAndCourseNames, $seatCountsBySession, $seatingRoomsBySession, $invigilatorCountsBySession, $exceptionCountsByCycle, $approvedAccommodationsByCycle, $dateSheetsByCycle, $activeAdmitsBySession, $appealCountsBySession, $cycleStates): array {
            $counts = static function ($grouped, int $sessionId, string $status): int {
                $row = ($grouped->get($sessionId) ?? collect())->firstWhere('status', $status);

                return $row === null ? 0 : (int) $row->total;
            };
            $attendance = $attendanceBySession->get($session->id) ?? collect();
            $attended = (int) $attendance->whereIn('attendance_status', ['present', 'late'])->sum('total');
            $registrationCount = (int) ($registrationByCycle[$session->cycle_id] ?? 0);
            $names = $campusAndCourseNames->get($session->id);
            $exceptions = $exceptionCountsByCycle->get($session->cycle_id) ?? collect();
            $invigilators = $invigilatorCountsBySession->get($session->id) ?? collect();
            $seatingRooms = $seatingRoomsBySession->get($session->id) ?? collect();
            $cycle = $cycleStates->get($session->cycle_id);
            $roomCapacity = (int) $seatingRooms->sum(static fn ($room): int => (int) ($room->capacity ?? 0));
            $seatCapacity = $roomCapacity > 0 ? $roomCapacity : (int) ($session->capacity ?? 0);
            $allocatedSeats = (int) ($seatCountsBySession[$session->id] ?? 0);
            $dateSheet = $dateSheetsByCycle->get($session->cycle_id);

            return [
                'sessionId' => (int) $session->id, 'session' => (string) $session->title, 'status' => (string) $session->status,
                'startsAt' => (string) $session->starts_at_utc, 'campus' => (string) ($names->campus_name ?? 'Campus'),
                'subject' => trim((string) (($names->course_code ?? '').' '.($names->course_title ?? ''))),
                'registered' => $registrationCount, 'present' => $attended,
                'absent' => $counts($attendanceBySession, (int) $session->id, 'absent'),
                'markingAssigned' => $counts($allocationBySession, (int) $session->id, 'assigned'),
                'markingSubmitted' => $counts($allocationBySession, (int) $session->id, 'submitted'),
                'moderationBacklog' => (int) ($moderationBySession[$session->id] ?? 0),
                'resultsPending' => $counts($resultCountsBySession, (int) $session->id, 'approval_pending'),
                'resultsReleased' => $counts($resultCountsBySession, (int) $session->id, 'released'),
                'openRechecks' => $counts($recheckCountsBySession, (int) $session->id, 'submitted') + $counts($recheckCountsBySession, (int) $session->id, 'assigned') + $counts($recheckCountsBySession, (int) $session->id, 'approval_pending'),
                'openAppeals' => (int) ($appealCountsBySession[$session->id] ?? 0),
                'openIncidents' => $counts($incidentCountsBySession, (int) $session->id, 'reported') + $counts($incidentCountsBySession, (int) $session->id, 'reviewed') + $counts($incidentCountsBySession, (int) $session->id, 'escalated') + $counts($incidentCountsBySession, (int) $session->id, 'investigating'),
                'dateSheetStatus' => (string) ($dateSheet->status ?? 'not_created'),
                'seatsAllocated' => $allocatedSeats,
                'seatCapacity' => $seatCapacity,
                'seatUtilizationPercent' => $seatCapacity > 0 ? round(($allocatedSeats / $seatCapacity) * 100, 1) : null,
                'invigilatorsAssigned' => (int) $invigilators->sum(static fn ($row): int => (int) $row->total),
                'invigilatorsAccepted' => $counts($invigilatorCountsBySession, (int) $session->id, 'accepted'),
                'eligibilityExceptionsPending' => $counts($exceptionCountsByCycle, (int) $session->cycle_id, 'pending'),
                'eligibilityExceptionsApproved' => $counts($exceptionCountsByCycle, (int) $session->cycle_id, 'approved'),
                'approvedAccommodations' => (int) ($approvedAccommodationsByCycle[$session->cycle_id] ?? 0),
                'activeAdmitDocuments' => (int) ($activeAdmitsBySession[$session->id] ?? 0),
                'retakeRegistrations' => $cycle->category === 'retake' ? $registrationCount : 0,
                'makeupRegistrations' => $cycle->category === 'makeup' ? $registrationCount : 0,
            ];
        })->values()->all();

        return ['sessions' => $sessions->map(fn ($row): array => ['id' => (int) $row->id, 'title' => $row->title, 'status' => $row->status, 'startsAt' => $row->starts_at_utc])->all(), 'components' => DB::table('exam_components')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'session_id', 'title', 'component_type', 'maximum_marks'])->map(fn ($component): array => ['id' => (int) $component->id, 'sessionId' => (int) $component->session_id, 'title' => $component->title, 'type' => $component->component_type, 'maximum' => (string) $component->maximum_marks])->all(), 'policies' => $policies, 'results' => $results, 'rechecks' => $rechecks, 'incidents' => $incidents, 'operationalReports' => $operationalReports, 'analytics' => ['releasedOutcomes' => $releasedOutcomes, 'subjectPerformance' => $subjectPerformance, 'componentProgress' => $componentProgress], 'candidates' => DB::table('exam_candidates')->where('tenant_id', $tenantId)->whereIn('cycle_id', $cycleIds)->where('status', 'registered')->orderBy('candidate_number')->limit(500)->get(['id', 'candidate_number'])->map(fn ($row): array => ['id' => (int) $row->id, 'number' => $row->candidate_number])->all(), 'reviewers' => $this->reviewers($tenantId), 'metrics' => ['sessions' => count($sessions), 'registered' => DB::table('exam_candidates')->where('tenant_id', $tenantId)->whereIn('cycle_id', $cycleIds)->where('status', 'registered')->whereNull('withdrawn_at')->count(), 'present' => DB::table('exam_attendance_records')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->whereIn('attendance_status', ['present', 'late'])->count(), 'pendingMarking' => DB::table('exam_results')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->where('status', 'marking_pending')->count(), 'pendingApproval' => DB::table('exam_results')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->where('status', 'approval_pending')->count(), 'released' => DB::table('exam_results')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->where('status', 'released')->count(), 'openRechecks' => DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->whereIn('exam_recheck_requests.result_id', DB::table('exam_results')->select('id')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds))->whereIn('status', ['submitted', 'assigned', 'approval_pending'])->count(), 'openIncidents' => DB::table('exam_incidents')->where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds)->whereIn('status', ['reported', 'reviewed', 'escalated', 'investigating'])->count()]];
    }

    /** @return list<array<string, int|string>> */
    public function exportOperationalReports(User $actor, ?int $sessionFilter = null): array
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('exams.analytics.view'), 403);
        $rows = $this->controllerWorkspace($sessionFilter)['operationalReports'];
        $this->audit->record('exams.operations_report.exported', $actor, (int) $membership->tenant_id, null, ['row_count' => count($rows), 'session_filter' => $sessionFilter]);

        return $rows;
    }

    /** @return array<string,mixed> */
    public function studentWorkspace(User $actor): array
    {
        $tenantId = (int) $this->membership()->tenant_id;
        $studentId = DB::table('sis_students')->where('tenant_id', $tenantId)->where('user_id', $actor->getKey())->where('status', 'active')->value('id');
        if ($studentId === null) {
            return ['released' => [], 'pendingCount' => 0];
        }
        $base = DB::table('exam_results')->join('exam_candidates', function ($join): void {
            $join->on('exam_candidates.id', '=', 'exam_results.candidate_id')->on('exam_candidates.tenant_id', '=', 'exam_results.tenant_id');
        })->join('exam_sessions', function ($join): void {
            $join->on('exam_sessions.id', '=', 'exam_results.session_id')->on('exam_sessions.tenant_id', '=', 'exam_results.tenant_id');
        })->where('exam_results.tenant_id', $tenantId)->where('exam_candidates.student_id', $studentId);
        $released = (clone $base)->where('exam_results.status', 'released')->orderByDesc('exam_results.released_at')->limit(100)->get(['exam_results.id', 'exam_results.version', 'exam_results.status', 'exam_results.outcome', 'exam_results.total_marks', 'exam_results.maximum_marks', 'exam_results.percentage', 'exam_results.recheck_until', 'exam_results.released_at', 'exam_results.source_snapshot', 'exam_sessions.title'])->map(function ($row) use ($tenantId): array {
            $snapshot = json_decode((string) $row->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $request = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('result_id', $row->id)->orderByDesc('id')->first(['id', 'status', 'decision', 'request_type']);
            $requestEvidence = $request !== null && $request->request_type === 'appeal' ? DB::table('exam_appeal_evidence')->where('tenant_id', $tenantId)->where('appeal_id', $request->id)->orderBy('id')->get(['id', 'original_name', 'scan_status'])->map(static fn ($item): array => ['id' => (int) $item->id, 'name' => $item->original_name, 'scanStatus' => $item->scan_status])->all() : [];
            $components = array_map(static fn (array $source): array => ['title' => (string) ($source['title'] ?? 'Examination component'), 'marks' => (string) ($source['marks'] ?? ''), 'maximum' => (string) ($source['maximum'] ?? ''), 'weight' => (string) ($source['weight'] ?? '')], $snapshot['sources'] ?? []);

            return ['id' => (int) $row->id, 'session' => $row->title, 'version' => (int) $row->version, 'status' => $row->status, 'outcome' => $row->outcome, 'marks' => (string) $row->total_marks, 'maximum' => (string) $row->maximum_marks, 'percentage' => (string) $row->percentage, 'recheckUntil' => $row->recheck_until, 'releasedAt' => $row->released_at, 'components' => $components, 'recheck' => $request === null ? null : ['id' => (int) $request->id, 'status' => $request->status, 'decision' => $request->decision, 'type' => $request->request_type, 'evidence' => $requestEvidence], 'canRequestRecheck' => $row->recheck_until !== null && now()->lte($row->recheck_until) && ! ($request && in_array($request->status, ['submitted', 'assigned', 'approval_pending'], true))];
        })->all();

        return ['released' => $released, 'pendingCount' => (clone $base)->whereIn('exam_results.status', ['attendance_pending', 'marking_pending', 'review_pending', 'moderation_pending', 'approval_pending', 'approved'])->count()];
    }

    /** @return array<string, mixed> */
    public function phase14Contract(int $resultId): array
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('exams.analytics.view'), 403);
        $tenantId = (int) $membership->tenant_id;
        $row = DB::table('exam_results')->join('exam_sessions', function ($join): void {
            $join->on('exam_sessions.id', '=', 'exam_results.session_id')->on('exam_sessions.tenant_id', '=', 'exam_results.tenant_id');
        })->join('exam_cycles', function ($join): void {
            $join->on('exam_cycles.id', '=', 'exam_sessions.cycle_id')->on('exam_cycles.tenant_id', '=', 'exam_sessions.tenant_id');
        })->join('exam_candidates', function ($join): void {
            $join->on('exam_candidates.id', '=', 'exam_results.candidate_id')->on('exam_candidates.tenant_id', '=', 'exam_results.tenant_id');
        })->join('sis_students', function ($join): void {
            $join->on('sis_students.id', '=', 'exam_candidates.student_id')->on('sis_students.tenant_id', '=', 'exam_candidates.tenant_id');
        })->where('exam_results.tenant_id', $tenantId)->where('exam_results.id', $resultId)->where('exam_results.status', 'released')->first([
            'exam_results.id', 'exam_results.version', 'exam_results.status', 'exam_results.outcome', 'exam_results.total_marks', 'exam_results.maximum_marks', 'exam_results.percentage', 'exam_results.source_snapshot', 'exam_results.approved_by', 'exam_results.approved_at', 'exam_results.released_at', 'exam_results.session_id', 'exam_results.candidate_id', 'exam_sessions.cycle_id', 'exam_sessions.academic_course_id', 'exam_cycles.academic_period_id', 'exam_cycles.category', 'exam_cycles.source_cycle_id', 'exam_cycles.institution_id', 'sis_students.id as student_id', 'sis_students.student_number',
        ]);
        abort_unless($row !== null, 404);
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $row->session_id)->firstOrFail();
        $this->assertCampus((int) $session->campus_id);
        $snapshot = json_decode((string) $row->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $versions = DB::table('exam_result_versions')->where('tenant_id', $tenantId)->where('result_id', $resultId)->orderBy('version')->get(['version', 'snapshot', 'change_reason', 'created_by', 'created_at'])->map(function ($version): array {
            $versionSnapshot = json_decode((string) $version->snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['version' => (int) $version->version, 'score' => $versionSnapshot['total_marks'] ?? null, 'reason' => $version->change_reason, 'actor_id' => (int) $version->created_by, 'created_at' => $version->created_at];
        })->all();

        return ['tenant_id' => $tenantId, 'institution_id' => (int) $row->institution_id, 'candidate' => ['sis_student_id' => (int) $row->student_id, 'student_number' => (string) $row->student_number], 'academic_course_id' => (int) $row->academic_course_id, 'academic_period_id' => (int) $row->academic_period_id, 'examination' => ['cycle_id' => (int) $row->cycle_id, 'session_id' => (int) $row->session_id, 'category' => (string) $row->category, 'source_cycle_id' => $row->source_cycle_id === null ? null : (int) $row->source_cycle_id], 'components' => $snapshot['sources'] ?? [], 'score' => ['marks' => (string) $row->total_marks, 'maximum_marks' => (string) $row->maximum_marks, 'percentage' => (string) $row->percentage, 'outcome' => (string) $row->outcome], 'status' => 'released', 'version' => (int) $row->version, 'approval' => ['approved_by' => (int) $row->approved_by, 'approved_at' => $row->approved_at, 'released_at' => $row->released_at], 'history' => $versions];
    }

    private function compileCandidate(int $sessionId, int $candidateId, User $actor): string
    {
        $tenantId = (int) $this->membership()->tenant_id;

        return DB::transaction(function () use ($sessionId, $candidateId, $actor, $tenantId): string {
            $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->lockForUpdate()->first();
            $candidate = DB::table('exam_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->lockForUpdate()->first();
            abort_unless($session !== null && $candidate !== null && (int) $candidate->cycle_id === (int) $session->cycle_id, 404);
            $cycle = DB::table('exam_cycles')->where('tenant_id', $tenantId)->where('id', $session->cycle_id)->lockForUpdate()->first();
            abort_unless($cycle !== null, 404);
            $this->assertCampus((int) $session->campus_id);
            $policy = DB::table('exam_result_policies')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->orderByDesc('version')->first();
            if ($policy === null) {
                throw ValidationException::withMessages(['policy' => 'Configure a versioned result policy before compilation.']);
            }
            $current = DB::table('exam_results')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->where('candidate_id', $candidateId)->lockForUpdate()->first();
            if ($current !== null && in_array($current->status, ['approved', 'released', 'archived'], true)) {
                return 'unchanged';
            }
            $policyComponents = DB::table('exam_result_policy_components')->join('exam_components', function ($join): void {
                $join->on('exam_components.id', '=', 'exam_result_policy_components.component_id')->on('exam_components.tenant_id', '=', 'exam_result_policy_components.tenant_id');
            })->where('exam_result_policy_components.tenant_id', $tenantId)->where('exam_result_policy_components.policy_id', $policy->id)->orderBy('exam_components.id')->get(['exam_components.id', 'exam_components.title', 'exam_components.maximum_marks', 'exam_result_policy_components.weight_percent']);
            $attendance = DB::table('exam_attendance_records')->where('tenant_id', $tenantId)->where('session_id', $sessionId)->where('candidate_id', $candidateId)->first();
            $sources = [];
            $missing = [];
            $reviewPending = [];
            $moderationPending = [];
            if ((float) $policy->written_weight > 0) {
                $binding = DB::table('exam_attempt_bindings')->join('quiz_attempts', function ($join): void {
                    $join->on('quiz_attempts.id', '=', 'exam_attempt_bindings.attempt_id')->on('quiz_attempts.tenant_id', '=', 'exam_attempt_bindings.tenant_id');
                })->join('exam_session_deliveries', function ($join): void {
                    $join->on('exam_session_deliveries.tenant_id', '=', 'exam_attempt_bindings.tenant_id')->on('exam_session_deliveries.session_id', '=', 'exam_attempt_bindings.session_id');
                })->where('exam_attempt_bindings.tenant_id', $tenantId)->where('exam_attempt_bindings.session_id', $sessionId)->where('exam_attempt_bindings.candidate_id', $candidateId)->first(['quiz_attempts.id', 'quiz_attempts.status', 'quiz_attempts.score_points', 'quiz_attempts.maximum_points', 'quiz_attempts.graded_at', 'quiz_attempts.manual_review_pending', 'exam_session_deliveries.paper_version_id']);
                if ($binding === null || $binding->graded_at === null || $binding->score_points === null || $binding->maximum_points === null || ! in_array($binding->status, ['graded', 'submitted'], true)) {
                    $missing[] = 'written';
                } elseif ((bool) $binding->manual_review_pending) {
                    $reviewPending[] = 'written';
                } else {
                    $sources[] = ['key' => 'written', 'title' => 'Written examination', 'source_id' => (int) $binding->id, 'paper_version_id' => (int) $binding->paper_version_id, 'marks' => (string) $binding->score_points, 'maximum' => (string) $binding->maximum_points, 'weight' => (string) $policy->written_weight];
                }
            }
            foreach ($policyComponents as $component) {
                $source = DB::table('exam_component_results')->where('tenant_id', $tenantId)->where('component_id', $component->id)->where('candidate_id', $candidateId)->where('result_status', 'approved')->orderByDesc('version')->first();
                if ($source === null) {
                    $componentRow = DB::table('exam_components')->where('tenant_id', $tenantId)->where('id', $component->id)->first(['second_marking_required']);
                    $submittedRounds = DB::table('exam_component_allocations')->join('exam_component_markings', function ($join): void {
                        $join->on('exam_component_markings.tenant_id', '=', 'exam_component_allocations.tenant_id')->on('exam_component_markings.allocation_id', '=', 'exam_component_allocations.id');
                    })->where('exam_component_allocations.tenant_id', $tenantId)->where('exam_component_allocations.component_id', $component->id)->where('exam_component_allocations.candidate_id', $candidateId)->where('exam_component_markings.status', 'submitted')->distinct()->count('exam_component_allocations.marking_round');
                    if ((bool) ($componentRow->second_marking_required ?? false) && $submittedRounds >= 2) {
                        $moderationPending[] = 'component:'.$component->id;
                    } elseif (! (bool) ($componentRow->second_marking_required ?? false) && $submittedRounds > 0) {
                        $reviewPending[] = 'component:'.$component->id;
                    } else {
                        $missing[] = 'component:'.$component->id;
                    }

                    continue;
                }
                $sources[] = ['key' => 'component:'.(int) $component->id, 'title' => $component->title, 'source_id' => (int) $source->id, 'marks' => (string) $source->final_marks, 'maximum' => (string) $source->maximum_marks, 'weight' => (string) $component->weight_percent, 'component_version' => (int) $source->version, 'moderation_decision_id' => $source->moderation_decision_id === null ? null : (int) $source->moderation_decision_id];
            }
            $status = 'approval_pending';
            $outcome = 'pending';
            $total = null;
            $percentage = null;
            if ($candidate->status !== 'registered' || $candidate->withdrawn_at !== null) {
                $status = 'withdrawn';
                $outcome = 'withdrawn';
            } elseif ($attendance === null) {
                $status = 'attendance_pending';
            } elseif (in_array($attendance->attendance_status, ['absent', 'withdrawn'], true)) {
                $status = $attendance->attendance_status;
                $outcome = $attendance->attendance_status;
            } elseif ($missing !== []) {
                $status = 'marking_pending';
            } elseif ($moderationPending !== []) {
                $status = 'moderation_pending';
            } elseif ($reviewPending !== []) {
                $status = 'review_pending';
            } else {
                $totalCents = 0;
                foreach ($sources as $source) {
                    $earned = self::hundredths($source['marks']);
                    $maximum = self::hundredths($source['maximum']);
                    $weight = self::hundredths($source['weight']);
                    if ($maximum <= 0 || $earned < 0 || $earned > $maximum) {
                        throw ValidationException::withMessages(['marks' => 'A source mark is outside its approved maximum.']);
                    }
                    $totalCents += (int) round(($earned * $weight) / $maximum, 0, PHP_ROUND_HALF_UP);
                }
                $total = self::markString($totalCents);
                $percentage = $total;
                $outcome = $policy->pass_percent === null ? 'complete' : ($totalCents >= self::hundredths((string) $policy->pass_percent) ? 'pass' : 'fail');
            }
            $snapshot = [
                'session_id' => $sessionId, 'candidate_id' => $candidateId, 'attendance_record_id' => $attendance === null ? null : (int) $attendance->id,
                'cycle' => ['id' => (int) $cycle->id, 'category' => (string) $cycle->category, 'source_cycle_id' => $cycle->source_cycle_id === null ? null : (int) $cycle->source_cycle_id],
                'attendance_status' => $attendance?->attendance_status, 'sources' => $sources, 'missing' => $missing, 'review_pending' => $reviewPending, 'moderation_pending' => $moderationPending,
                'policy' => ['id' => (int) $policy->id, 'version' => (int) $policy->version, 'written_weight' => (string) $policy->written_weight, 'pass_percent' => $policy->pass_percent === null ? null : (string) $policy->pass_percent],
                'total_marks' => $total, 'maximum_marks' => '100.00', 'percentage' => $percentage, 'outcome' => $outcome,
            ];
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($current !== null && hash_equals((string) ($current->source_hash ?? ''), $hash)) {
                return 'unchanged';
            }
            $version = (int) ($current->version ?? 0) + 1;
            $values = ['policy_id' => $policy->id, 'version' => $version, 'status' => $status, 'outcome' => $outcome, 'total_marks' => $total, 'maximum_marks' => '100.00', 'percentage' => $percentage, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'source_hash' => $hash, 'policy_version' => 'exam-result-policy-'.$policy->version, 'compiled_by' => $actor->getKey(), 'approved_by' => null, 'approved_at' => null, 'released_by' => null, 'released_at' => null, 'recheck_until' => null, 'updated_at' => now()];
            if ($current === null) {
                $resultId = DB::table('exam_results')->insertGetId($values + ['tenant_id' => $tenantId, 'session_id' => $sessionId, 'candidate_id' => $candidateId, 'created_at' => now()]);
            } else {
                $resultId = (int) $current->id;
                DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $resultId)->update($values);
            }
            DB::table('exam_result_versions')->insert(['tenant_id' => $tenantId, 'result_id' => $resultId, 'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'change_reason' => $current === null ? 'Initial deterministic result compilation' : 'Recompiled pending result from a changed authorized source', 'created_by' => $actor->getKey(), 'created_at' => now()]);
            $this->audit->record('exams.result.compiled', $actor, $tenantId, null, ['result_id' => $resultId, 'result_version' => $version, 'status' => $status, 'source_count' => count($sources), 'missing_count' => count($missing)]);

            return $status === 'absent' ? 'absent' : ($status === 'approval_pending' ? 'ready' : 'pending');
        }, 3);
    }

    private function queueRecheckDecision(int $tenantId, int $requestId, int $resultId, string $decision): void
    {
        $isAppeal = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->value('request_type') === 'appeal';
        $prefix = $isAppeal ? 'exam.appeal' : 'exam.recheck';
        DB::table('quiz_outbox_events')->insertOrIgnore(['tenant_id' => $tenantId, 'event_key' => $prefix.'.decided:'.$requestId, 'event_type' => $prefix.'_decided', 'safe_payload' => json_encode(['recheck_id' => $requestId, 'result_id' => $resultId, 'decision' => $decision], JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function queueAppealTransition(int $tenantId, int $requestId, int $resultId, string $action): void
    {
        $eventType = 'exam.appeal_'.match ($action) {
            'escalate' => 'escalated',
            'close' => 'closed',
            'reopen' => 'reopened',
            default => $action,
        };
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event_key' => 'exam.appeal.'.$action.':'.$requestId,
            'event_type' => $eventType,
            'safe_payload' => json_encode(['recheck_id' => $requestId, 'result_id' => $resultId], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, int|string>|null $safeDetails */
    private function appendAppealHistory(int $tenantId, int $appealId, User $actor, string $action, ?string $from, string $to, ?int $resultVersion, ?array $safeDetails = null): void
    {
        DB::table('exam_appeal_history')->insert([
            'tenant_id' => $tenantId,
            'appeal_id' => $appealId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'result_version' => $resultVersion,
            'actor_user_id' => $actor->getKey(),
            'safe_details' => $safeDetails === null ? null : json_encode($safeDetails, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function queueIncidentNotification(string $type, string $key, int $tenantId, int $incidentId, ?int $historyId = null): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore(['tenant_id' => $tenantId, 'event_key' => $key, 'event_type' => $type, 'safe_payload' => json_encode(['incident_id' => $incidentId, 'history_id' => $historyId], JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<array{id:int,name:string}> */
    private function reviewers(int $tenantId): array
    {
        return DB::table('tenant_memberships')->join('membership_role', function ($join): void {
            $join->on('membership_role.tenant_id', '=', 'tenant_memberships.tenant_id')->on('membership_role.membership_id', '=', 'tenant_memberships.id');
        })->join('permission_role', 'permission_role.role_id', '=', 'membership_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.status', 'active')->whereNull('tenant_memberships.revoked_at')->where('permissions.key', 'exams.recheck.review')->distinct()->orderBy('users.name')->limit(100)->get(['users.id', 'users.name'])->map(fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])->all();
    }

    private function assertResultCampus(object $result): void
    {
        $session = DB::table('exam_sessions')->where('tenant_id', $this->membership()->tenant_id)->where('id', $result->session_id)->first();
        abort_unless($session !== null, 404);
        $this->assertCampus((int) $session->campus_id);
    }

    private function assertCampus(int $campusId): void
    {
        $membership = $this->membership();
        abort_unless($membership->campus_id === null || (int) $membership->campus_id === $campusId, 404);
    }

    private function membership(): TenantMembership
    {
        abort_unless($this->context->hasMembership(), 403);

        return $this->context->membership();
    }

    private static function hundredths(string $value): int
    {
        if (preg_match('/\A(\d{1,7})(?:\.(\d{1,2}))?\z/D', trim($value), $matches) !== 1) {
            throw ValidationException::withMessages(['marks' => 'Marks and weights must be valid non-negative decimal values with at most two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private static function markString(int $hundredths): string
    {
        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
