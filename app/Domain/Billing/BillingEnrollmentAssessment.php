<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingFeeAssignment;
use App\Models\BillingFeeStructure;
use App\Models\CourseEnrollment;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional enrollment billing hook. It only creates an assignment when an institution
 * explicitly configures billing.enrollment_fee_assessment with a one-time fee structure.
 * Invoice issuance and collection remain separate authorized operations.
 */
final class BillingEnrollmentAssessment
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    public function assignConfiguredEnrollmentFee(CourseEnrollment $enrollment, User $actor): ?BillingFeeAssignment
    {
        $setting = TenantSetting::query()->where('key', 'billing.enrollment_fee_assessment')->first();
        $structureId = data_get($setting?->value, 'fee_structure_id');
        if (! is_numeric($structureId) || (int) $structureId < 1) {
            return null;
        }

        return DB::transaction(function () use ($enrollment, $actor, $structureId): ?BillingFeeAssignment {
            $locked = CourseEnrollment::query()->with(['student', 'offering'])->lockForUpdate()->findOrFail($enrollment->getKey());
            if ($locked->status !== 'confirmed') {
                return null;
            }
            $student = $locked->student;
            $structure = BillingFeeStructure::query()->lockForUpdate()->findOrFail((int) $structureId);
            if ($structure->status !== 'active' || $structure->frequency !== 'one_time'
                || (int) $structure->institution_id !== (int) $student->institution_id
                || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $student->home_campus_id)) {
                throw ValidationException::withMessages(['billing.enrollment_fee_assessment' => 'Configured enrollment fee structure must be active, one-time, and scoped to the learner institution/campus.']);
            }
            $existing = BillingFeeAssignment::query()->where('course_enrollment_id', $locked->getKey())->where('fee_structure_id', $structure->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            $date = ($locked->confirmed_at ?? now())->toDateString();
            $assignment = BillingFeeAssignment::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(), 'fee_structure_id' => $structure->getKey(),
                'course_enrollment_id' => $locked->getKey(), 'period_start' => $date, 'period_end' => $date,
                'effective_from' => $date, 'source' => 'enrollment', 'status' => 'assigned',
                'idempotency_key' => 'enrollment:'.hash('sha256', $locked->getKey().':'.$structure->getKey()),
                'assigned_by' => $actor->getKey(), 'context_snapshot' => ['offering_id' => (int) $locked->offering_id, 'assessment_only' => true],
            ]);
            $this->audit->record('billing.enrollment_assessment.assigned', $actor, (int) $this->context->tenant()->getKey(), $assignment, ['enrollment_id' => (int) $locked->getKey(), 'fee_structure_id' => (int) $structure->getKey()]);

            return $assignment;
        }, 3);
    }
}
