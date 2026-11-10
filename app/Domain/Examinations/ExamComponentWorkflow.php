<?php

namespace App\Domain\Examinations;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamAnswerScript;
use App\Models\ExamCandidate;
use App\Models\ExamComponent;
use App\Models\ExamComponentAllocation;
use App\Models\ExamComponentExaminer;
use App\Models\ExamComponentMarkDraft;
use App\Models\ExamComponentMarking;
use App\Models\ExamSession;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExamComponentWorkflow
{
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'text/plain' => 'txt', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly AdmissionDocumentScanner $scanner,
    ) {}

    /** @param array<string,mixed> $data */
    public function createComponent(int $sessionId, array $data, User $actor): ExamComponent
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('exams.components.manage'), 403);
        $tenantId = (int) $membership->tenant_id;
        $session = ExamSession::query()->whereKey($sessionId)->firstOrFail();
        $this->assertSessionCampus($session);
        abort_unless($session->status === 'published', 409, 'Components can be scheduled only for a published exam session.');
        $this->assertRoom($data['room_id'] ?? null, $tenantId, (int) $session->campus_id);
        $maximum = self::cents((string) $data['maximum_marks']);
        if ($maximum < 1) {
            throw ValidationException::withMessages(['maximum_marks' => 'The component maximum must be greater than zero.']);
        }
        $rubrics = $this->validateRubric((array) ($data['rubric'] ?? []), $maximum);
        $starts = isset($data['starts_at']) && $data['starts_at'] !== '' ? CarbonImmutable::parse((string) $data['starts_at'], (string) config('app.timezone'))->utc() : null;
        $ends = isset($data['ends_at']) && $data['ends_at'] !== '' ? CarbonImmutable::parse((string) $data['ends_at'], (string) config('app.timezone'))->utc() : null;
        if ($starts && $ends && $ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'The component end must be after its start.']);
        }
        if ($starts && ($starts->lessThan($session->starts_at_utc) || $starts->greaterThanOrEqualTo($session->ends_at_utc))) {
            throw ValidationException::withMessages(['starts_at' => 'The component must occur within the examination session.']);
        }

        return DB::transaction(function () use ($sessionId, $data, $tenantId, $actor, $maximum, $rubrics, $starts, $ends): ExamComponent {
            $lockedSession = ExamSession::query()->lockForUpdate()->findOrFail($sessionId);
            $component = ExamComponent::query()->create([
                'session_id' => $sessionId, 'component_type' => $data['component_type'], 'code' => strtoupper((string) $data['code']),
                'title' => trim((string) $data['title']), 'instructions' => $data['instructions'] ?? null, 'room_id' => $data['room_id'] ?? null,
                'starts_at_utc' => $starts, 'ends_at_utc' => $ends, 'maximum_marks' => self::markString($maximum),
                'status' => 'draft', 'second_marking_required' => (bool) ($data['second_marking_required'] ?? false),
                'moderation_difference_threshold' => isset($data['moderation_difference_threshold']) ? (string) $data['moderation_difference_threshold'] : null,
                'blind_second_marking' => (bool) ($data['blind_second_marking'] ?? false), 'created_by' => $actor->getKey(),
            ]);
            foreach ($rubrics as $position => $criterion) {
                DB::table('exam_component_rubrics')->insert([
                    'tenant_id' => $tenantId, 'component_id' => $component->getKey(), 'criterion_key' => $criterion['key'],
                    'title' => $criterion['title'], 'description' => $criterion['description'], 'maximum_marks' => self::markString($criterion['maximum_cents']),
                    'position' => $position + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('exams.component.created', $actor, $tenantId, $component, ['session_id' => $lockedSession->getKey(), 'component_type' => $component->component_type, 'rubric_count' => count($rubrics)]);

            return $component->load('rubric');
        }, 3);
    }

    public function publishComponent(int $componentId, User $actor): ExamComponent
    {
        abort_unless($this->membership()->hasPermission('exams.components.manage'), 403);

        return DB::transaction(function () use ($componentId, $actor): ExamComponent {
            $component = ExamComponent::query()->with('rubric')->lockForUpdate()->findOrFail($componentId);
            $this->assertComponentCampus($component);
            abort_unless($component->status === 'draft' && $component->rubric->isNotEmpty(), 409, 'A draft component needs a complete rubric before publishing.');
            $sum = $component->rubric->sum(fn ($criterion): int => self::cents((string) $criterion->maximum_marks));
            abort_unless($sum === self::cents((string) $component->maximum_marks), 422, 'Rubric criteria must sum exactly to the component maximum.');
            if ($component->room_id !== null && $component->starts_at_utc !== null && $component->ends_at_utc !== null) {
                DB::table('academic_rooms')->where('tenant_id', $component->tenant_id)->where('id', $component->room_id)->lockForUpdate()->first();
                $conflict = ExamComponent::query()->where('id', '!=', $componentId)->where('room_id', $component->room_id)->where('status', 'published')
                    ->where('starts_at_utc', '<', $component->ends_at_utc)->where('ends_at_utc', '>', $component->starts_at_utc)->exists();
                abort_unless(! $conflict, 409, 'The selected room is already booked for an overlapping published practical/oral component.');
            }
            $component->forceFill(['status' => 'published'])->save();
            $this->audit->record('exams.component.published', $actor, (int) $component->tenant_id, $component, ['maximum_marks' => (string) $component->maximum_marks]);

            return $component;
        }, 3);
    }

    public function assignExaminer(int $componentId, int $userId, string $role, User $actor): ExamComponentExaminer
    {
        abort_unless($this->membership()->hasPermission('exams.mark.allocate'), 403);
        if (! in_array($role, ['primary', 'second', 'moderator'], true)) {
            throw ValidationException::withMessages(['role' => 'Select a supported examiner role.']);
        }
        $component = ExamComponent::query()->findOrFail($componentId);
        $this->assertComponentCampus($component);
        abort_unless($component->status === 'published', 409);
        if ($role === 'second' && ! $component->second_marking_required) {
            throw ValidationException::withMessages(['role' => 'Second examiner assignment requires independent second marking policy.']);
        }
        $requiredPermission = $role === 'moderator' ? 'exams.moderate' : 'exams.mark';
        $eligible = DB::table('tenant_memberships')->where('tenant_id', $component->tenant_id)->where('user_id', $userId)
            ->where('status', 'active')->whereNull('revoked_at')->get()->contains(function ($row) use ($requiredPermission): bool {
                $membership = TenantMembership::query()->find($row->id);

                return $membership !== null && $membership->hasPermission($requiredPermission);
            });
        abort_unless($eligible, 422, 'The assigned user must be an active institution member with the required marking permission.');
        $componentTimes = $component->only(['starts_at_utc', 'ends_at_utc']);
        if ($role !== 'moderator' && $componentTimes['starts_at_utc'] && $componentTimes['ends_at_utc']) {
            $conflict = DB::table('exam_component_examiners as assignment')->join('exam_components as existing', function ($join): void {
                $join->on('existing.id', '=', 'assignment.component_id')->on('existing.tenant_id', '=', 'assignment.tenant_id');
            })->where('assignment.tenant_id', $component->tenant_id)->where('assignment.user_id', $userId)->where('assignment.status', 'assigned')
                ->where('assignment.examiner_role', $role)->where('existing.status', 'published')->where('existing.id', '!=', $componentId)
                ->where('existing.starts_at_utc', '<', $componentTimes['ends_at_utc'])->where('existing.ends_at_utc', '>', $componentTimes['starts_at_utc'])->exists();
            abort_unless(! $conflict, 409, 'This examiner already has an overlapping component assignment.');
        }

        return DB::transaction(function () use ($component, $userId, $role, $actor): ExamComponentExaminer {
            $assignment = ExamComponentExaminer::query()->where('component_id', $component->getKey())->where('user_id', $userId)->where('examiner_role', $role)->lockForUpdate()->first();
            if ($assignment && $assignment->status === 'assigned') {
                return $assignment;
            }
            $assignment ??= new ExamComponentExaminer(['component_id' => $component->getKey(), 'user_id' => $userId, 'examiner_role' => $role]);
            $assignment->forceFill(['status' => 'assigned', 'assigned_by' => $actor->getKey(), 'assigned_at' => now(), 'revoked_at' => null])->save();
            $this->audit->record('exams.examiner.assigned', $actor, (int) $component->tenant_id, $assignment, ['component_id' => $component->getKey(), 'examiner_role' => $role]);

            return $assignment;
        }, 3);
    }

    public function allocate(int $componentId, int $candidateId, int $examinerId, int $round, string $reason, User $actor): ExamComponentAllocation
    {
        abort_unless($this->membership()->hasPermission('exams.mark.allocate'), 403);
        if (! in_array($round, [1, 2], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['allocation' => 'A valid marking round and allocation reason are required.']);
        }

        return DB::transaction(function () use ($componentId, $candidateId, $examinerId, $round, $reason, $actor): ExamComponentAllocation {
            $component = ExamComponent::query()->lockForUpdate()->findOrFail($componentId);
            $this->assertComponentCampus($component);
            abort_unless($component->status === 'published', 409);
            abort_unless($round === 1 || $component->second_marking_required, 422, 'This component does not require a second marking round.');
            $session = ExamSession::query()->whereKey($component->session_id)->firstOrFail();
            $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->lockForUpdate()->findOrFail($candidateId);
            abort_unless($candidate->status === 'registered' && $candidate->withdrawn_at === null, 422, 'Only an active registered candidate can be allocated.');
            $eligibleEnrollment = DB::table('course_enrollments as enrollments')->join('course_offerings as offerings', function ($join): void {
                $join->on('offerings.id', '=', 'enrollments.offering_id')->on('offerings.tenant_id', '=', 'enrollments.tenant_id');
            })->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'enrollments.section_id')->on('sections.tenant_id', '=', 'enrollments.tenant_id');
            })->where('enrollments.tenant_id', $candidate->tenant_id)->where('enrollments.student_id', $candidate->student_id)->where('enrollments.status', 'confirmed')->whereNull('enrollments.dropped_at')
                ->where(fn ($query) => $query->whereNull('enrollments.withdrawn_effective_at')->orWhere('enrollments.withdrawn_effective_at', '>', now()))
                ->where('offerings.academic_course_id', $session->academic_course_id)->where('sections.campus_id', $session->campus_id)->exists();
            abort_unless($eligibleEnrollment, 422, 'Candidate needs an active confirmed enrollment in the component course and campus.');
            $role = $round === 1 ? 'primary' : 'second';
            $isAssigned = ExamComponentExaminer::query()->where('component_id', $componentId)->where('user_id', $examinerId)->where('examiner_role', $role)->where('status', 'assigned')->exists();
            abort_unless($isAssigned, 422, 'Examiner is not assigned to this marking round.');
            if ($round === 2) {
                $firstExaminer = ExamComponentAllocation::query()->where('component_id', $componentId)->where('candidate_id', $candidateId)->where('marking_round', 1)->value('examiner_id');
                abort_unless((int) $firstExaminer !== $examinerId, 422, 'Independent second marking requires a different examiner.');
            }
            $tenantId = (int) $component->tenant_id;
            $allocation = ExamComponentAllocation::query()->where('component_id', $componentId)->where('candidate_id', $candidateId)->where('marking_round', $round)->lockForUpdate()->first();
            if ($allocation && $allocation->status === 'submitted') {
                throw new ConflictHttpException('Submitted marking is immutable and cannot be reassigned.');
            }
            $previous = $allocation?->examiner_id;
            $allocation ??= new ExamComponentAllocation(['component_id' => $componentId, 'candidate_id' => $candidateId, 'marking_round' => $round]);
            $allocation->forceFill(['examiner_id' => $examinerId, 'status' => 'assigned', 'assigned_by' => $actor->getKey(), 'assigned_at' => now(), 'submitted_at' => null])->save();
            DB::table('exam_component_allocation_history')->insert(['tenant_id' => $tenantId, 'allocation_id' => $allocation->getKey(), 'previous_examiner_id' => $previous, 'new_examiner_id' => $examinerId, 'changed_by' => $actor->getKey(), 'reason' => mb_substr(trim($reason), 0, 500), 'created_at' => now()]);
            $this->audit->record('exams.marking.allocated', $actor, $tenantId, $allocation, ['component_id' => $componentId, 'candidate_id' => $candidateId, 'marking_round' => $round, 'examiner_id' => $examinerId]);

            return $allocation;
        }, 3);
    }

    /** @param array<string,string> $marks */
    public function saveDraft(int $allocationId, array $marks, string $feedback, string $privateNotes, int $expectedVersion, User $actor): ExamComponentMarkDraft
    {
        abort_unless($this->membership()->hasPermission('exams.mark'), 403);

        return DB::transaction(function () use ($allocationId, $marks, $feedback, $privateNotes, $expectedVersion, $actor): ExamComponentMarkDraft {
            $allocation = ExamComponentAllocation::query()->with('component.session', 'component.rubric')->lockForUpdate()->findOrFail($allocationId);
            $this->assertComponentCampus($allocation->component);
            abort_unless((int) $allocation->examiner_id === (int) $actor->getKey() && $allocation->status !== 'submitted', 404);
            $validated = $this->validateMarks($allocation, $marks, false);
            $draft = ExamComponentMarkDraft::query()->where('allocation_id', $allocationId)->lockForUpdate()->first();
            $current = (int) ($draft->lock_version ?? 0);
            if ($current !== $expectedVersion) {
                throw new ConflictHttpException('A newer marking draft exists. Reload before saving.');
            }
            $draft ??= new ExamComponentMarkDraft(['allocation_id' => $allocationId]);
            $draft->forceFill(['rubric_marks' => $validated, 'feedback' => mb_substr(trim($feedback), 0, 4000), 'private_notes' => mb_substr(trim($privateNotes), 0, 4000), 'lock_version' => $current + 1, 'updated_by' => $actor->getKey()])->save();
            $allocation->forceFill(['status' => 'in_progress'])->save();
            $this->audit->record('exams.marking.draft_saved', $actor, (int) $allocation->tenant_id, $draft, [
                'allocation_id' => (int) $allocation->getKey(), 'component_id' => (int) $allocation->component_id,
                'candidate_id' => (int) $allocation->candidate_id, 'revision' => $current + 1,
            ]);

            return $draft;
        }, 3);
    }

    public function submitMarking(int $allocationId, int $expectedVersion, User $actor): ExamComponentMarking
    {
        abort_unless($this->membership()->hasPermission('exams.mark'), 403);

        return DB::transaction(function () use ($allocationId, $expectedVersion, $actor): ExamComponentMarking {
            $allocation = ExamComponentAllocation::query()->with('component.session', 'component.rubric')->lockForUpdate()->findOrFail($allocationId);
            $this->assertComponentCampus($allocation->component);
            abort_unless((int) $allocation->examiner_id === (int) $actor->getKey(), 404);
            abort_unless($allocation->status !== 'submitted' && $allocation->component->status === 'published', 409);
            $draft = ExamComponentMarkDraft::query()->where('allocation_id', $allocationId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $draft->lock_version === $expectedVersion, 409, 'Marking draft changed. Reload before submission.');
            $marks = $this->validateMarks($allocation, (array) $draft->rubric_marks, true);
            $total = array_sum(array_map(static fn (string $mark): int => self::cents($mark), $marks));
            $revision = (int) ExamComponentMarking::query()->where('allocation_id', $allocationId)->max('revision') + 1;
            $marking = ExamComponentMarking::query()->create([
                'allocation_id' => $allocationId, 'revision' => $revision, 'rubric_marks' => $marks,
                'total_marks' => self::markString($total), 'maximum_marks' => (string) $allocation->component->maximum_marks,
                'feedback' => $draft->feedback, 'private_notes' => $draft->private_notes, 'status' => 'submitted',
                'submitted_by' => $actor->getKey(), 'submitted_at' => now(),
            ]);
            $allocation->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $this->audit->record('exams.marking.submitted', $actor, (int) $allocation->tenant_id, $marking, ['component_id' => $allocation->component_id, 'candidate_id' => $allocation->candidate_id, 'marking_round' => $allocation->marking_round, 'revision' => $revision, 'total_marks' => self::markString($total)]);
            if ($allocation->marking_round === 1 && ! $allocation->component->second_marking_required) {
                $this->createResult($allocation, $marking, null, $actor, 'pending_approval');
            }

            return $marking;
        }, 3);
    }

    public function approveSingleMark(int $markingId, User $actor): void
    {
        abort_unless($this->membership()->hasPermission('exams.results.approve'), 403);
        $marking = ExamComponentMarking::query()->with('allocation.component.session')->findOrFail($markingId);
        $this->assertComponentCampus($marking->allocation->component);
        abort_unless((int) $marking->submitted_by !== (int) $actor->getKey(), 403, 'The marking examiner cannot approve their own result.');
        DB::transaction(function () use ($marking, $actor): void {
            $allocation = ExamComponentAllocation::query()->with('component')->lockForUpdate()->findOrFail($marking->allocation_id);
            abort_unless($allocation->marking_round === 1 && ! $allocation->component->second_marking_required, 409);
            $this->createResult($allocation, $marking, null, $actor, 'approved');
        }, 3);
    }

    public function moderate(int $componentId, int $candidateId, string $finalMarks, string $decision, string $reason, User $actor): int
    {
        abort_unless($this->membership()->hasPermission('exams.moderate'), 403);
        abort_unless(ExamComponentExaminer::query()->where('component_id', $componentId)->where('user_id', $actor->getKey())->where('examiner_role', 'moderator')->where('status', 'assigned')->exists(), 403, 'A moderator must be explicitly assigned to this component.');
        if (trim($reason) === '' || ! in_array($decision, ['confirmed', 'adjusted'], true)) {
            throw ValidationException::withMessages(['reason' => 'Choose a moderation decision and explain the outcome.']);
        }

        return DB::transaction(function () use ($componentId, $candidateId, $finalMarks, $decision, $reason, $actor): int {
            $component = ExamComponent::query()->with('session', 'rubric')->lockForUpdate()->findOrFail($componentId);
            $this->assertComponentCampus($component);
            abort_unless($component->second_marking_required && $component->status === 'published', 409);
            $allocations = ExamComponentAllocation::query()->where('component_id', $componentId)->where('candidate_id', $candidateId)->whereIn('marking_round', [1, 2])->where('status', 'submitted')->orderBy('marking_round')->lockForUpdate()->get();
            abort_unless($allocations->count() === 2, 409, 'Both independent examiner marks must be submitted before moderation.');
            $markings = $allocations->map(fn ($allocation) => ExamComponentMarking::query()->where('allocation_id', $allocation->getKey())->orderByDesc('revision')->firstOrFail());
            abort_unless((int) $markings[0]->submitted_by !== (int) $markings[1]->submitted_by, 409);
            abort_unless((int) $markings[0]->submitted_by !== (int) $actor->getKey() && (int) $markings[1]->submitted_by !== (int) $actor->getKey(), 403, 'A participating examiner cannot moderate their own candidate.');
            $maximum = self::cents((string) $component->maximum_marks);
            $final = self::cents($finalMarks);
            abort_unless($final >= 0 && $final <= $maximum, 422, 'Final marks must be within the component maximum.');
            $first = self::cents((string) $markings[0]->total_marks);
            $second = self::cents((string) $markings[1]->total_marks);
            $difference = abs($first - $second);
            $threshold = $component->moderation_difference_threshold === null ? null : self::cents((string) $component->moderation_difference_threshold);
            abort_unless($decision !== 'confirmed' || $final === $first || $final === $second, 422, 'Confirming a mark must select one of the submitted examiner scores.');
            abort_unless($threshold === null || $difference <= $threshold || $decision === 'adjusted', 422, 'A difference above threshold requires an adjusted moderation decision.');
            $revision = (int) DB::table('exam_component_moderation_decisions')->where('tenant_id', $component->tenant_id)->where('component_id', $componentId)->where('candidate_id', $candidateId)->max('revision') + 1;
            $decisionId = DB::table('exam_component_moderation_decisions')->insertGetId([
                'tenant_id' => $component->tenant_id, 'component_id' => $componentId, 'candidate_id' => $candidateId, 'revision' => $revision,
                'first_marking_id' => $markings[0]->getKey(), 'second_marking_id' => $markings[1]->getKey(), 'first_marks' => self::markString($first),
                'second_marks' => self::markString($second), 'final_marks' => self::markString($final), 'decision' => $decision,
                'reason' => mb_substr(trim($reason), 0, 4000), 'moderated_by' => $actor->getKey(), 'decided_at' => now(),
            ]);
            $result = DB::table('exam_component_results')->where('tenant_id', $component->tenant_id)->where('component_id', $componentId)->where('candidate_id', $candidateId)->max('version') + 1;
            DB::table('exam_component_results')->insert([
                'tenant_id' => $component->tenant_id, 'component_id' => $componentId, 'candidate_id' => $candidateId, 'version' => $result,
                'final_marks' => self::markString($final), 'maximum_marks' => (string) $component->maximum_marks, 'result_status' => 'approved',
                'policy_version' => 'exam-marking-v1', 'moderation_decision_id' => $decisionId, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('exams.marks.moderated', $actor, (int) $component->tenant_id, $component, ['component_id' => $componentId, 'candidate_id' => $candidateId, 'moderation_revision' => $revision, 'result_version' => $result, 'decision' => $decision]);

            return (int) $result;
        }, 3);
    }

    public function uploadScript(int $sessionId, ?int $componentId, int $candidateId, UploadedFile $file, User $actor): ExamAnswerScript
    {
        abort_unless($this->membership()->hasPermission('exams.scripts.upload'), 403);
        $session = ExamSession::query()->findOrFail($sessionId);
        $this->assertSessionCampus($session);
        $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->findOrFail($candidateId);
        abort_unless($candidate->status === 'registered' && $candidate->withdrawn_at === null, 404);
        if ($componentId !== null) {
            abort_unless(ExamComponent::query()->whereKey($componentId)->where('session_id', $sessionId)->exists(), 404);
        }
        $mime = (string) $file->getMimeType();
        if (! isset(self::MIME_EXTENSIONS[$mime]) || ! $file->isValid() || (int) $file->getSize() < 1 || (int) $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload a supported document or image up to 10 MB.']);
        }
        $tenantId = (int) $session->tenant_id;
        $extension = self::MIME_EXTENSIONS[$mime];
        $path = Storage::disk('local')->putFileAs('examinations/'.$tenantId.'/quarantine', $file, (string) Str::uuid().'.'.$extension);
        if (! is_string($path)) {
            throw new \RuntimeException('The private answer script could not be stored.');
        }
        $absolute = Storage::disk('local')->path($path);
        $sha = hash_file('sha256', $absolute);
        if (! is_string($sha)) {
            Storage::disk('local')->delete($path);
            throw new \RuntimeException('The answer script integrity hash could not be calculated.');
        }
        try {
            $script = DB::transaction(function () use ($sessionId, $componentId, $candidateId, $file, $mime, $path, $sha, $actor): ExamAnswerScript {
                $revision = (int) ExamAnswerScript::query()->where('session_id', $sessionId)->where('candidate_id', $candidateId)->when($componentId === null, fn ($query) => $query->whereNull('component_id'), fn ($query) => $query->where('component_id', $componentId))->max('revision') + 1;

                return ExamAnswerScript::query()->create([
                    'session_id' => $sessionId, 'candidate_id' => $candidateId, 'component_id' => $componentId, 'revision' => $revision,
                    'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())) ?: 'answer-script', 0, 255),
                    'mime_type' => $mime, 'size_bytes' => (int) $file->getSize(), 'sha256' => $sha, 'private_path' => $path,
                    'scan_status' => 'quarantined', 'uploaded_by' => $actor->getKey(), 'retention_until' => now()->addYears(7),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $script->forceFill(['scan_status' => 'scanning', 'scanner' => $this->scanner->name()])->save();
        $scan = $this->scanner->scan($absolute);
        $status = match ($scan->status) {
            'clean' => 'clean', 'rejected' => 'infected', default => 'unavailable'
        };
        $script->forceFill(['scan_status' => $status, 'scanner' => $this->scanner->name(), 'signature_version' => $this->scanner->name(), 'result_code' => $scan->code, 'scanned_at' => now()])->save();
        $this->audit->record('exams.script.scanned', $actor, $tenantId, $script, ['script_id' => $script->getKey(), 'scan_status' => $status, 'result_code' => $scan->code, 'scanner' => $this->scanner->name()]);

        return $script->fresh();
    }

    /** @return array{string,string,string} */
    public function scriptDownload(int $scriptId, User $actor): array
    {
        $script = ExamAnswerScript::query()->findOrFail($scriptId);
        $session = ExamSession::query()->findOrFail($script->session_id);
        $this->assertSessionCampus($session);
        abort_unless($script->scan_status === 'clean', 404);
        $membership = $this->membership();
        $controller = $membership->hasPermission('exams.mark.allocate') || $membership->hasPermission('exams.audit.view');
        $assigned = $script->component_id !== null && ExamComponentAllocation::query()->where('component_id', $script->component_id)->where('candidate_id', $script->candidate_id)->where('examiner_id', $actor->getKey())->where('status', '!=', 'cancelled')->exists();
        abort_unless($controller || $assigned, 404);
        $pattern = '/\Aexaminations\/'.(int) $membership->tenant_id.'\/quarantine\/[a-f0-9-]{36}\.(pdf|jpg|png|webp|txt|doc|docx|odt)\z/iD';
        abort_unless(preg_match($pattern, (string) $script->private_path) === 1, 404);
        $absolute = Storage::disk('local')->path($script->private_path);
        abort_unless(is_file($absolute), 404);
        $actual = hash_file('sha256', $absolute);
        if (! is_string($actual) || ! hash_equals((string) $script->sha256, $actual)) {
            $script->forceFill(['scan_status' => 'infected', 'result_code' => 'content_hash_mismatch'])->save();
            $this->audit->record('exams.script.access_revoked', $actor, (int) $membership->tenant_id, $script, ['reason' => 'content_hash_mismatch']);
            throw new NotFoundHttpException;
        }
        $this->audit->record('exams.script.downloaded', $actor, (int) $membership->tenant_id, $script, ['script_id' => $scriptId]);

        return [$absolute, (string) $script->original_name, (string) $script->mime_type];
    }

    /** @return array<string,mixed> */
    public function workspace(User $actor): array
    {
        $membership = $this->membership();
        $rows = ExamComponentAllocation::query()->with(['component.session', 'markings'])->where('examiner_id', $actor->getKey())->whereIn('status', ['assigned', 'in_progress'])->when($membership->campus_id !== null, fn ($query) => $query->whereHas('component.session', fn ($sessionQuery) => $sessionQuery->where('campus_id', $membership->campus_id)))->orderBy('assigned_at')->paginate(30);
        $queue = $rows->through(function (ExamComponentAllocation $allocation): array {
            return ['id' => (int) $allocation->getKey(), 'componentId' => (int) $allocation->component_id, 'component' => $allocation->component->title, 'type' => $allocation->component->component_type, 'session' => $allocation->component->session->title, 'candidateNumber' => (string) ExamCandidate::query()->whereKey($allocation->candidate_id)->value('candidate_number'), 'round' => (int) $allocation->marking_round, 'status' => $allocation->status, 'draft' => ExamComponentMarkDraft::query()->where('allocation_id', $allocation->getKey())->first()?->only(['rubric_marks', 'feedback', 'lock_version']), 'scripts' => ExamAnswerScript::query()->where('session_id', $allocation->component->session_id)->where('candidate_id', $allocation->candidate_id)->where('scan_status', 'clean')->when($allocation->component_id !== null, fn ($query) => $query->where(fn ($componentQuery) => $componentQuery->whereNull('component_id')->orWhere('component_id', $allocation->component_id)))->get(['id', 'original_name'])->map(fn (ExamAnswerScript $script): array => ['id' => (int) $script->getKey(), 'name' => $script->original_name, 'url' => route('tenant.academics.examinations.scripts.download', [$this->context->tenant()->slug, $script->getKey()])])->all(), 'rubric' => $allocation->component->rubric()->get()->map(fn ($rubric): array => ['key' => $rubric->criterion_key, 'title' => $rubric->title, 'description' => $rubric->description, 'maximumMarks' => (string) $rubric->maximum_marks])->all()];
        });
        $moderation = [];
        if ($membership->hasPermission('exams.moderate')) {
            $moderation = DB::table('exam_component_allocations as first')->join('exam_component_allocations as second', function ($join): void {
                $join->on('first.tenant_id', '=', 'second.tenant_id')->on('first.component_id', '=', 'second.component_id')->on('first.candidate_id', '=', 'second.candidate_id');
            })->join('exam_components as components', function ($join): void {
                $join->on('components.tenant_id', '=', 'first.tenant_id')->on('components.id', '=', 'first.component_id');
            })->join('exam_component_examiners as moderator', function ($join) use ($actor): void {
                $join->on('moderator.tenant_id', '=', 'components.tenant_id')->on('moderator.component_id', '=', 'components.id')->where('moderator.user_id', '=', $actor->getKey())->where('moderator.examiner_role', '=', 'moderator')->where('moderator.status', '=', 'assigned');
            })->where('first.tenant_id', $membership->tenant_id)->where('first.marking_round', 1)->where('second.marking_round', 2)->where('first.status', 'submitted')->where('second.status', 'submitted')->where('components.second_marking_required', true)->when($membership->campus_id !== null, fn ($query) => $query->whereExists(fn ($sessions) => $sessions->selectRaw('1')->from('exam_sessions')->whereColumn('exam_sessions.id', 'components.session_id')->where('exam_sessions.campus_id', $membership->campus_id)))->select(['components.id as component_id', 'components.title', 'first.id as first_allocation_id', 'second.id as second_allocation_id', 'first.candidate_id'])->limit(100)->get()->map(function ($row): array {
                $first = ExamComponentMarking::query()->where('allocation_id', $row->first_allocation_id)->where('status', 'submitted')->orderByDesc('revision')->first();
                $second = ExamComponentMarking::query()->where('allocation_id', $row->second_allocation_id)->where('status', 'submitted')->orderByDesc('revision')->first();

                return ['componentId' => (int) $row->component_id, 'title' => $row->title, 'candidateId' => (int) $row->candidate_id, 'candidateNumber' => (string) ExamCandidate::query()->whereKey($row->candidate_id)->value('candidate_number'), 'firstMarks' => $first?->total_marks, 'secondMarks' => $second?->total_marks, 'maximumMarks' => $first?->maximum_marks, 'difference' => $first !== null && $second !== null ? number_format(abs((float) $first->total_marks - (float) $second->total_marks), 2, '.', '') : null];
            })->all();
        }
        $pendingApprovals = [];
        if ($membership->hasPermission('exams.results.approve')) {
            $pendingApprovals = ExamComponentMarking::query()->with('allocation.component')->where('status', 'submitted')->whereHas('allocation', fn ($query) => $query->where('marking_round', 1)->whereHas('component', fn ($componentQuery) => $componentQuery->where('tenant_id', $membership->tenant_id)->where('second_marking_required', false)->when($membership->campus_id !== null, fn ($scope) => $scope->whereHas('session', fn ($sessionQuery) => $sessionQuery->where('campus_id', $membership->campus_id)))))->where('submitted_by', '!=', $actor->getKey())->orderBy('submitted_at')->limit(100)->get()->map(fn (ExamComponentMarking $marking): array => ['id' => (int) $marking->getKey(), 'component' => $marking->allocation->component->title, 'candidateNumber' => (string) ExamCandidate::query()->whereKey($marking->allocation->candidate_id)->value('candidate_number'), 'marks' => (string) $marking->total_marks, 'maximum' => (string) $marking->maximum_marks])->all();
        }

        $history = ExamComponentMarking::query()->with(['allocation.component.session'])->where('submitted_by', $actor->getKey())->where('status', 'submitted')->whereHas('allocation.component', fn ($query) => $query->where('tenant_id', $membership->tenant_id)->when($membership->campus_id !== null, fn ($componentQuery) => $componentQuery->whereHas('session', fn ($sessionQuery) => $sessionQuery->where('campus_id', $membership->campus_id))))->orderByDesc('submitted_at')->limit(100)->get()->map(fn (ExamComponentMarking $marking): array => ['id' => (int) $marking->getKey(), 'revision' => (int) $marking->revision, 'component' => $marking->allocation->component->title, 'candidateNumber' => (string) ExamCandidate::query()->whereKey($marking->allocation->candidate_id)->value('candidate_number'), 'round' => (int) $marking->allocation->marking_round, 'marks' => (string) $marking->total_marks, 'maximum' => (string) $marking->maximum_marks, 'submittedAt' => Carbon::parse($marking->submitted_at)->toISOString()])->all();

        return ['allocations' => $queue, 'moderation' => $moderation, 'pendingApprovals' => $pendingApprovals, 'history' => $history, 'canManage' => $membership->hasPermission('exams.mark.allocate'), 'canUpload' => $membership->hasPermission('exams.scripts.upload'), 'canModerate' => $membership->hasPermission('exams.moderate'), 'canApprove' => $membership->hasPermission('exams.results.approve')];
    }

    /** @param array<int, array<string, mixed>> $rubric
     * @return list<array<string,mixed>>
     */
    private function validateRubric(array $rubric, int $maximum): array
    {
        if ($rubric === [] || count($rubric) > 40) {
            throw ValidationException::withMessages(['rubric' => 'Provide between one and forty rubric criteria.']);
        }
        $validated = [];
        $sum = 0;
        foreach (array_values($rubric) as $index => $criterion) {
            $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($criterion['key'] ?? 'criterion_'.$index))) ?: 'criterion_'.$index;
            $marks = self::cents((string) ($criterion['maximum_marks'] ?? '0'));
            if ($marks < 1 || trim((string) ($criterion['title'] ?? '')) === '') {
                throw ValidationException::withMessages(['rubric' => 'Each rubric criterion needs a title and positive maximum.']);
            }
            $validated[] = ['key' => substr($key, 0, 48), 'title' => mb_substr(trim((string) $criterion['title']), 0, 160), 'description' => mb_substr(trim((string) ($criterion['description'] ?? '')), 0, 2000), 'maximum_cents' => $marks];
            $sum += $marks;
        }
        if ($sum !== $maximum) {
            throw ValidationException::withMessages(['rubric' => 'Rubric criteria must sum exactly to the component maximum.']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $marks
     * @return array<string,string>
     */
    private function validateMarks(ExamComponentAllocation $allocation, array $marks, bool $requireAll): array
    {
        $rubrics = $allocation->component->rubric->keyBy('criterion_key');
        if (array_diff(array_keys($marks), $rubrics->keys()->all()) !== []) {
            throw ValidationException::withMessages(['marks' => 'Marks contain an unknown rubric criterion.']);
        }
        if ($requireAll && array_diff($rubrics->keys()->all(), array_keys($marks)) !== []) {
            throw ValidationException::withMessages(['marks' => 'Submit a score for every rubric criterion.']);
        }
        $clean = [];
        foreach ($marks as $key => $value) {
            $cents = self::cents((string) $value);
            abort_unless($cents >= 0 && $cents <= self::cents((string) $rubrics[$key]->maximum_marks), 422, 'A criterion score exceeds its maximum.');
            $clean[$key] = self::markString($cents);
        }

        return $clean;
    }

    private function createResult(ExamComponentAllocation $allocation, ExamComponentMarking $marking, ?int $moderationId, User $actor, string $status): void
    {
        $version = (int) DB::table('exam_component_results')->where('tenant_id', $allocation->tenant_id)->where('component_id', $allocation->component_id)->where('candidate_id', $allocation->candidate_id)->max('version') + 1;
        $approved = $status === 'approved';
        DB::table('exam_component_results')->insert(['tenant_id' => $allocation->tenant_id, 'component_id' => $allocation->component_id, 'candidate_id' => $allocation->candidate_id, 'version' => $version, 'final_marks' => $marking->total_marks, 'maximum_marks' => $marking->maximum_marks, 'result_status' => $status, 'policy_version' => 'exam-marking-v1', 'moderation_decision_id' => $moderationId, 'approved_by' => $approved ? $actor->getKey() : null, 'approved_at' => $approved ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('exams.component.result_versioned', $actor, (int) $allocation->tenant_id, $allocation, ['component_id' => $allocation->component_id, 'candidate_id' => $allocation->candidate_id, 'result_version' => $version, 'result_status' => $status]);
    }

    private function assertRoom(mixed $roomId, int $tenantId, int $campusId): void
    {
        if ($roomId !== null) {
            abort_unless(DB::table('academic_rooms')->where('tenant_id', $tenantId)->where('campus_id', $campusId)->where('id', $roomId)->exists(), 422, 'Select a room in the examination campus.');
        }
    }

    private function assertSessionCampus(ExamSession $session): void
    {
        $campusId = $this->membership()->campus_id;
        abort_unless($campusId === null || (int) $campusId === (int) $session->campus_id, 404);
    }

    private function assertComponentCampus(ExamComponent $component): void
    {
        $component->loadMissing('session');
        $this->assertSessionCampus($component->session);
    }

    private function membership(): TenantMembership
    {
        return $this->context->membership();
    }

    private static function cents(string $value): int
    {
        if (preg_match('/\A(0|[1-9]\d{0,6})(?:\.(\d{1,2}))?\z/D', $value, $match) !== 1) {
            throw ValidationException::withMessages(['marks' => 'Use a non-negative mark with at most two decimal places.']);
        }

        return ((int) $match[1] * 100) + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function markString(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
