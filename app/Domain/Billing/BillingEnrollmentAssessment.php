<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingFeeAssignment;
use App\Models\BillingFeeStructure;
use App\Models\BillingInvoice;
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

    public function assignConfiguredEnrollmentFee(CourseEnrollment $enrollment, User $actor, ?string $assessmentDate = null, bool $allowNewAssignment = true): ?BillingFeeAssignment
    {
        return DB::transaction(function () use ($enrollment, $actor, $assessmentDate, $allowNewAssignment): ?BillingFeeAssignment {
            $locked = CourseEnrollment::query()->with(['student', 'offering.period', 'offering.program'])->lockForUpdate()->findOrFail($enrollment->getKey());
            if ($locked->status !== 'confirmed') {
                return null;
            }
            $student = $locked->student;
            // Initial assessment uses the confirmation date. A staff reassessment
            // evaluates current eligibility rather than replaying a stale snapshot.
            $date = Carbon::parse($assessmentDate ?? $locked->confirmed_at ?? now())->toDateString();
            $period = $locked->offering->period;
            $placementQuery = DB::table('sis_student_placements')->where('tenant_id', $locked->tenant_id)->where('student_id', $student->getKey())
                ->where('status', 'active')->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
                ->where(fn ($q) => $q->whereNull('academic_period_id')->orWhere('academic_period_id', $period?->getKey()));
            if ($period !== null) {
                $placementQuery->orderByRaw('CASE WHEN academic_period_id = ? THEN 0 ELSE 1 END', [$period->getKey()]);
            }
            $placement = $placementQuery->orderByDesc('effective_from')->orderByDesc('id')->first(['academic_class_id', 'academic_section_id']);
            $resolved = $period === null ? ['rule' => null, 'ambiguous' => false] : $this->applicability->resolve(
                (int) $student->institution_id, $student->home_campus_id,
                $locked->offering->academic_program_id === null ? null : (int) $locked->offering->academic_program_id,
                (int) $period->getKey(), $date,
                (int) $locked->offering->academic_course_id,
                $placement?->academic_class_id === null ? null : (int) $placement->academic_class_id,
                $placement?->academic_section_id === null ? null : (int) $placement->academic_section_id,
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
            if (! $allowNewAssignment) {
                return null;
            }
            $periodStart = $period === null ? $date : max(Carbon::parse($period->starts_on)->toDateString(), $date);
            $periodEnd = $period === null ? $date : Carbon::parse($period->ends_on)->toDateString();
            if ($periodStart > $periodEnd) {
                return null;
            }
            $assignment = BillingFeeAssignment::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(), 'fee_structure_id' => $structure->getKey(), 'applicability_rule_id' => $rule?->getKey(),
                'academic_period_id' => $period?->getKey(), 'course_enrollment_id' => $locked->getKey(), 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'effective_from' => $date, 'source' => 'enrollment', 'status' => 'assigned',
                'idempotency_key' => 'enrollment:'.hash('sha256', $locked->getKey().':'.$structure->getKey().':'.($rule?->getKey() ?? 0)),
                'assigned_by' => $actor->getKey(), 'context_snapshot' => ['offering_id' => (int) $locked->offering_id, 'academic_period_id' => $period?->getKey(), 'academic_program_id' => $locked->offering->academic_program_id,
                    'academic_course_id' => (int) $locked->offering->academic_course_id, 'academic_class_id' => $placement?->academic_class_id, 'academic_section_id' => $placement?->academic_section_id, 'assessment_only' => true],
            ]);
            $this->audit->record('billing.enrollment_assessment.assigned', $actor, (int) $this->context->tenant()->getKey(), $assignment, ['enrollment_id' => (int) $locked->getKey(), 'fee_structure_id' => (int) $structure->getKey()]);

            return $assignment;
        }, 3);
    }

    /** Re-evaluate an existing enrollment without issuing or changing an invoice.
     * @return array{assignment:BillingFeeAssignment|null,outcome:string,issued_obligation_preserved:bool}
     */
    public function reassess(int $enrollmentId, string $reason, User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage'), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reassessment reason is required.']);
        }

        return DB::transaction(function () use ($enrollmentId, $reason, $actor, $membership): array {
            $enrollment = CourseEnrollment::query()->with('student')->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($enrollmentId);
            $student = $enrollment->student;
            if ((int) $student->institution_id !== (int) $membership->institution_id
                || ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id)) {
                abort(404);
            }
            $beforeIds = BillingFeeAssignment::query()->where('tenant_id', $membership->tenant_id)->where('course_enrollment_id', $enrollmentId)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $hasIssuedObligation = $beforeIds !== [] && BillingInvoice::query()->where('tenant_id', $membership->tenant_id)->whereIn('fee_assignment_id', $beforeIds)
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])->exists();
            $assignment = $this->assignConfiguredEnrollmentFee($enrollment, $actor, now()->toDateString(), ! $hasIssuedObligation);
            $created = $assignment !== null && ! in_array($assignment->getKey(), $beforeIds, true);
            $outcome = $assignment === null ? ($hasIssuedObligation ? 'financial_review_required' : 'no_applicable_fee') : ($created ? 'new_assignment' : 'unchanged');
            $this->audit->record('billing.enrollment_assessment.reassessed', $actor, (int) $membership->tenant_id, $assignment ?? $enrollment, [
                'enrollment_id' => $enrollmentId, 'reason' => mb_substr(trim($reason), 0, 500), 'outcome' => $outcome,
                'issued_obligation_preserved' => $hasIssuedObligation, 'requires_financial_review' => $outcome === 'financial_review_required',
                'assignment_id' => $assignment?->getKey(),
            ]);

            return ['assignment' => $assignment, 'outcome' => $outcome, 'issued_obligation_preserved' => $hasIssuedObligation];
        }, 3);
    }
}
