<?php

namespace App\Domain\Sis;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\SisPrivateDocument;
use App\Models\SisStudent;
use App\Models\SisStudentTransferCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisStudentTransferWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $academicAccess,
        private readonly SisStudentLifecycle $lifecycle,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function request(SisStudent $student, array $data, User $actor): SisStudentTransferCase
    {
        return DB::transaction(function () use ($student, $data, $actor): SisStudentTransferCase {
            $tenantId = (int) $this->context->tenant()->getKey();
            $lockedStudent = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($student->getKey());
            $this->academicAccess->assertCampus($lockedStudent->home_campus_id === null ? null : (int) $lockedStudent->home_campus_id);

            if (in_array($lockedStudent->status, ['withdrawn', 'transferred', 'graduated', 'alumni', 'archived'], true)) {
                throw ValidationException::withMessages(['student' => 'This lifecycle state cannot start another transfer case.']);
            }

            if (SisStudentTransferCase::query()->where('student_id', $lockedStudent->getKey())->whereIn('status', ['pending_review', 'approved'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['student' => 'An unresolved transfer case already exists for this student.']);
            }

            $evidence = SisPrivateDocument::query()->whereKey($data['evidence_document_id'])
                ->where('student_id', $lockedStudent->getKey())->where('category', 'transfer_certificate')
                ->where('scan_status', 'clean')->lockForUpdate()->first();
            $evidenceVersion = $evidence?->versions()->where('version_number', $evidence->current_version)->first();
            if (! $evidence || (int) $evidence->current_version < 1 || ! $evidenceVersion
                || ($evidenceVersion->expires_at !== null && ! Carbon::parse($evidenceVersion->expires_at)->isFuture())) {
                throw ValidationException::withMessages(['evidence_document_id' => 'A current, unexpired, malware-clean transfer certificate for this student is required.']);
            }

            $transfer = SisStudentTransferCase::query()->create([
                'student_id' => $lockedStudent->getKey(), 'direction' => $data['direction'], 'status' => 'pending_review',
                'external_institution_name' => $data['external_institution_name'],
                'external_institution_code' => $data['external_institution_code'] ?? null,
                'external_country_code' => isset($data['external_country_code']) ? strtoupper($data['external_country_code']) : null,
                'requested_effective_on' => $data['requested_effective_on'], 'request_reason' => $data['request_reason'],
                'evidence_document_id' => $evidence->getKey(), 'requested_by' => $actor->getKey(), 'requested_at' => now(),
            ]);
            $this->audit->record('sis.student.transfer_requested', $actor, $tenantId, $transfer, [
                'student_id' => $lockedStudent->getKey(), 'direction' => $transfer->direction, 'evidence_attached' => true,
            ]);

            return $transfer;
        }, 3);
    }

    public function decide(SisStudentTransferCase $transfer, string $decision, string $note, User $actor): SisStudentTransferCase
    {
        return DB::transaction(function () use ($transfer, $decision, $note, $actor): SisStudentTransferCase {
            $tenantId = (int) $this->context->tenant()->getKey();
            $locked = SisStudentTransferCase::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($transfer->getKey());
            abort_unless($locked->status === 'pending_review', 409);
            abort_if((int) $locked->requested_by === (int) $actor->getKey(), 403, 'Transfer requests require an independent reviewer.');
            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($locked->student_id);
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);

            $locked->forceFill([
                'status' => $decision,
                'review_decision' => $decision,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->record('sis.student.transfer_reviewed', $actor, $tenantId, $locked, [
                'student_id' => $locked->student_id, 'direction' => $locked->direction, 'decision' => $decision,
            ]);

            return $locked;
        }, 3);
    }

    public function complete(SisStudentTransferCase $transfer, string $note, User $actor): SisStudentTransferCase
    {
        return DB::transaction(function () use ($transfer, $note, $actor): SisStudentTransferCase {
            $tenantId = (int) $this->context->tenant()->getKey();
            $locked = SisStudentTransferCase::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($transfer->getKey());
            abort_unless($locked->status === 'approved', 409);
            abort_if(Carbon::parse($locked->requested_effective_on)->isFuture(), 409, 'The effective date has not arrived.');
            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($locked->student_id);
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);

            if ($locked->direction === 'outgoing') {
                $this->lifecycle->transition(
                    $student,
                    'transferred',
                    Carbon::parse($locked->requested_effective_on),
                    'sis.external_transfer_completed',
                    $note,
                    $actor,
                );
            } elseif ($student->status === 'pre_admitted') {
                $this->lifecycle->transition(
                    $student,
                    'admitted',
                    Carbon::parse($locked->requested_effective_on),
                    'sis.incoming_transfer_accepted',
                    $note,
                    $actor,
                );
            }

            $locked->forceFill([
                'status' => 'completed', 'completed_by' => $actor->getKey(), 'completed_at' => now(), 'completion_note' => $note,
            ])->save();
            $this->audit->record('sis.student.transfer_completed', $actor, $tenantId, $locked, [
                'student_id' => $locked->student_id, 'direction' => $locked->direction,
            ]);

            return $locked;
        }, 3);
    }
}
