<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\SisStudentPromotionBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisPromotionApprovalWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SisBulkPromotionWorkflow $promotions,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function submit(array $payload, User $actor): SisStudentPromotionBatch
    {
        return DB::transaction(function () use ($payload, $actor): SisStudentPromotionBatch {
            $preview = $this->promotions->preview($payload, $actor);
            $tenantId = (int) $this->context->tenant()->getKey();
            $institutionId = (int) Institution::query()->value('id');
            $batch = SisStudentPromotionBatch::query()->create([
                'institution_id' => $institutionId, 'requested_by' => $actor->getKey(), 'status' => 'pending',
                'campus_id' => $payload['campus_id'] ?? null, 'academic_year_id' => $payload['academic_year_id'] ?? null,
                'academic_period_id' => $payload['academic_period_id'] ?? null, 'academic_program_id' => $payload['academic_program_id'] ?? null,
                'academic_class_id' => $payload['academic_class_id'], 'academic_section_id' => $payload['academic_section_id'] ?? null,
                'effective_from' => $payload['effective_from'], 'reason_code' => $payload['reason_code'],
                'reason' => $payload['reason'] ?? null, 'requested_at' => now(),
            ]);
            foreach ($payload['student_ids'] as $studentId) {
                $batch->outcomes()->create(['student_id' => (int) $studentId, 'outcome_status' => 'pending']);
            }
            $this->audit->record('sis.students.bulk_promotion_submitted', $actor, $tenantId, $batch, [
                'count' => $preview['count'], 'destination_class_id' => (int) $batch->academic_class_id,
                'effective_from' => $batch->effective_from->toDateString(),
            ]);

            return $batch;
        }, 3);
    }

    public function decide(SisStudentPromotionBatch $batch, string $decision, string $reason, User $actor): int
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unsupported promotion decision.');
        }

        return DB::transaction(function () use ($batch, $decision, $reason, $actor): int {
            $locked = SisStudentPromotionBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This promotion request has already been decided.']);
            }
            if ((int) $locked->requested_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['decision' => 'The requester cannot approve or reject their own promotion request.']);
            }
            $tenantId = (int) $this->context->tenant()->getKey();
            $outcomes = $locked->outcomes()->orderBy('student_id')->lockForUpdate()->get();
            if ($outcomes->isEmpty()) {
                throw ValidationException::withMessages(['decision' => 'The promotion request has no student outcomes.']);
            }

            if ($decision === 'approved') {
                $payload = [
                    'student_ids' => array_map('intval', $outcomes->pluck('student_id')->all()),
                    'campus_id' => $locked->campus_id, 'academic_year_id' => $locked->academic_year_id,
                    'academic_period_id' => $locked->academic_period_id, 'academic_program_id' => $locked->academic_program_id,
                    'academic_class_id' => (int) $locked->academic_class_id, 'academic_section_id' => $locked->academic_section_id,
                    'effective_from' => $locked->effective_from->toDateString(), 'reason_code' => $locked->reason_code,
                    'reason' => $locked->reason,
                ];
                $this->promotions->execute($payload, $actor);
                $locked->outcomes()->update(['outcome_status' => 'promoted', 'outcome_reason' => trim($reason), 'decided_at' => now()]);
            } else {
                $locked->outcomes()->update(['outcome_status' => 'rejected', 'outcome_reason' => trim($reason), 'decided_at' => now()]);
            }

            $locked->forceFill([
                'status' => $decision, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(),
                'decision_reason' => trim($reason),
            ])->save();
            $this->audit->record('sis.students.bulk_promotion_'.$decision, $actor, $tenantId, $locked, [
                'count' => $outcomes->count(), 'destination_class_id' => (int) $locked->academic_class_id,
            ]);

            return $outcomes->count();
        }, 3);
    }
}
