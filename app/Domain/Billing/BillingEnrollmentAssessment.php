<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingFeeAssignment;
use App\Models\BillingFeeStructure;
use App\Models\CourseEnrollment;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional enrollment billing hook. It only creates an assignment when an institution
 * explicitly configures billing.enrollment_fee_assessment with a one-time fee structure.
 * Invoice issuance and collection remain separate authorized operations.
 */
final class BillingEnrollmentAssessment
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit, private readonly BillingFeeApplicability $applicability) {}

    public function assignConfiguredEnrollmentFee(CourseEnrollment $enrollment, User $actor): ?BillingFeeAssignment
    {
        return DB::transaction(function () use ($enrollment, $actor): ?BillingFeeAssignment {
            $locked = CourseEnrollment::query()->with(['student', 'offering.period', 'offering.program'])->lockForUpdate()->findOrFail($enrollment->getKey());
            if ($locked->status !== 'confirmed') {
                return null;
            }
            $student = $locked->student;
            $date = ($locked->confirmed_at ?? now())->toDateString();
            $period = $locked->offering->period;
            $resolved = $period === null ? ['rule' => null, 'ambiguous' => false] : $this->applicability->resolve(
                (int) $student->institution_id, $student->home_campus_id,
                $locked->offering->academic_program_id === null ? null : (int) $locked->offering->academic_program_id,
                (int) $period->getKey(), $date,
            );
            if ($resolved['ambiguous']) {
                throw ValidationException::withMessages(['billing.fee_applicability' => 'More than one equally applicable active billing rule exists; finance must resolve it explicitly.']);
            }
            $rule = $resolved['rule'];
            $structureId = $rule !== null ? $rule->fee_structure_id : data_get(TenantSetting::query()->where('key', 'billing.enrollment_fee_assessment')->first()?->value, 'fee_structure_id');
            if (! is_numeric($structureId) || (int) $structureId < 1) {
                return null;
            }
            $structure = BillingFeeStructure::query()->lockForUpdate()->findOrFail((int) $structureId);
            if ($structure->status !== 'active' || ($rule === null && $structure->frequency !== 'one_time')
                || (int) $structure->institution_id !== (int) $student->institution_id
                || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $student->home_campus_id)) {
                throw ValidationException::withMessages(['billing.enrollment_fee_assessment' => 'The resolved fee structure must be active and scoped to the learner institution/campus; the legacy enrollment setting accepts one-time structures only.']);
            }
            $existing = BillingFeeAssignment::query()->where('course_enrollment_id', $locked->getKey())->where('fee_structure_id', $structure->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            $periodStart = $period === null ? $date : Carbon::parse($period->starts_on)->toDateString();
            $periodEnd = $period === null ? $date : Carbon::parse($period->ends_on)->toDateString();
            $assignment = BillingFeeAssignment::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(), 'fee_structure_id' => $structure->getKey(), 'applicability_rule_id' => $rule?->getKey(),
                'academic_period_id' => $period?->getKey(), 'course_enrollment_id' => $locked->getKey(), 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'effective_from' => $date, 'source' => 'enrollment', 'status' => 'assigned',
                'idempotency_key' => 'enrollment:'.hash('sha256', $locked->getKey().':'.$structure->getKey().':'.($rule?->getKey() ?? 0)),
                'assigned_by' => $actor->getKey(), 'context_snapshot' => ['offering_id' => (int) $locked->offering_id, 'academic_period_id' => $period?->getKey(), 'academic_program_id' => $locked->offering->academic_program_id, 'assessment_only' => true],
            ]);
            $this->audit->record('billing.enrollment_assessment.assigned', $actor, (int) $this->context->tenant()->getKey(), $assignment, ['enrollment_id' => (int) $locked->getKey(), 'fee_structure_id' => (int) $structure->getKey()]);

            return $assignment;
        }, 3);
    }
}
