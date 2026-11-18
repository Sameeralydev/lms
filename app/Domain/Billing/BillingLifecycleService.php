<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingAdjustment;
use App\Models\BillingAdjustmentEvent;
use App\Models\BillingConcession;
use App\Models\BillingConcessionEvent;
use App\Models\BillingFeeAssignment;
use App\Models\BillingFeeStructure;
use App\Models\BillingInstallment;
use App\Models\BillingInstallmentPlan;
use App\Models\BillingInvoice;
use App\Models\CourseEnrollment;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingLifecycleService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array{student_id:int,fee_structure_id:int,course_enrollment_id?:int|null,academic_period_id?:int|null,period_start:string,period_end:string,effective_from:string,effective_until?:string|null,source?:string,idempotency_key:string} $data */
    public function assign(array $data, User $actor): BillingFeeAssignment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage'), 403);

        return DB::transaction(function () use ($data, $actor, $membership): BillingFeeAssignment {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($data['student_id']);
            $this->assertStudentScope($student, $membership);
            $structure = BillingFeeStructure::query()->lockForUpdate()->findOrFail($data['fee_structure_id']);
            if ((int) $structure->institution_id !== (int) $membership->institution_id
                || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $student->home_campus_id)
                || ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id)) {
                abort(404);
            }
            $start = Carbon::parse($data['period_start']);
            $end = Carbon::parse($data['period_end']);
            $effective = Carbon::parse($data['effective_from']);
            $effectiveUntil = isset($data['effective_until']) ? Carbon::parse($data['effective_until']) : null;
            if ($structure->status !== 'active' || $start->gt($end) || $effective->lt($structure->effective_from)
                || ($structure->effective_until !== null && $end->gt($structure->effective_until))
                || ($effectiveUntil !== null && $effectiveUntil->lt($effective))) {
                throw ValidationException::withMessages(['fee_structure_id' => 'The selected active fee structure is not effective for this assignment period.']);
            }
            $enrollmentId = $data['course_enrollment_id'] ?? null;
            if ($enrollmentId !== null) {
                $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollmentId);
                if ((int) $enrollment->student_id !== (int) $student->getKey() || ! in_array($enrollment->status, ['confirmed', 'active'], true)) {
                    throw ValidationException::withMessages(['course_enrollment_id' => 'Fee assignments require an active enrollment belonging to this learner.']);
                }
            }
            $existing = BillingFeeAssignment::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->student_id !== (int) $student->getKey() || (int) $existing->fee_structure_id !== (int) $structure->getKey()
                    || Carbon::parse($existing->period_start)->toDateString() !== $start->toDateString() || Carbon::parse($existing->period_end)->toDateString() !== $end->toDateString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This assignment key is already bound to different assignment data.']);
                }

                return $existing;
            }
            $assignment = BillingFeeAssignment::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id, 'student_id' => $student->getKey(),
                'fee_structure_id' => $structure->getKey(), 'course_enrollment_id' => $enrollmentId,
                'academic_period_id' => $data['academic_period_id'] ?? null, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'effective_from' => $effective->toDateString(), 'effective_until' => $effectiveUntil?->toDateString(),
                'source' => $data['source'] ?? ($enrollmentId === null ? 'manual' : 'enrollment'), 'status' => 'assigned',
                'idempotency_key' => $data['idempotency_key'], 'assigned_by' => $actor->getKey(),
                'context_snapshot' => ['student_number' => $student->student_number, 'structure_code' => $structure->code, 'currency' => $structure->currency],
            ]);
            $this->audit->record('billing.fee_assignment.created', $actor, (int) $membership->tenant_id, $assignment, ['student_id' => (int) $student->getKey(), 'fee_structure_id' => (int) $structure->getKey(), 'source' => $assignment->source]);

            return $assignment;
        }, 3);
    }

    /** @param array{student_id:int,code:string,name:string,kind:string,value:string,effective_from:string,effective_until?:string|null,reason:string} $data */
    public function requestConcession(array $data, User $actor): BillingConcession
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.concessions.manage'), 403);
        $valueMinor = BillingMoney::toMinor($data['value']);
        if ($data['kind'] === 'percentage' && $valueMinor > 10000) {
            throw ValidationException::withMessages(['value' => 'Percentage concessions must be between 0.00 and 100.00.']);
        }
        if ($valueMinor < 1) {
            throw ValidationException::withMessages(['value' => 'A concession amount greater than zero is required.']);
        }

        return DB::transaction(function () use ($data, $actor, $membership): BillingConcession {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($data['student_id']);
            $this->assertStudentScope($student, $membership);
            $start = Carbon::parse($data['effective_from']);
            $end = isset($data['effective_until']) ? Carbon::parse($data['effective_until']) : null;
            if ($end !== null && $end->lt($start)) {
                throw ValidationException::withMessages(['effective_until' => 'Concession end date must not precede its start date.']);
            }
            $concession = BillingConcession::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id, 'student_id' => $student->getKey(),
                'code' => Str::upper(trim($data['code'])), 'name' => trim($data['name']), 'kind' => $data['kind'],
                'value' => BillingMoney::fromMinor(BillingMoney::toMinor($data['value'])), 'status' => 'pending_approval',
                'effective_from' => $start->toDateString(), 'effective_until' => $end?->toDateString(),
                'reason' => trim($data['reason']), 'requested_by' => $actor->getKey(),
            ]);
            $this->concessionEvent($concession, $actor, 'requested', null, ['kind' => $concession->kind, 'value' => (string) $concession->value]);
            $this->audit->record('billing.concession.requested', $actor, (int) $membership->tenant_id, $concession, ['student_id' => (int) $student->getKey(), 'kind' => $concession->kind]);

            return $concession;
        }, 3);
    }

    public function reviewConcession(int $id, bool $approve, string $note, User $actor): BillingConcession
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.concessions.approve'), 403);

        return DB::transaction(function () use ($id, $approve, $note, $actor, $membership): BillingConcession {
            $row = BillingConcession::query()->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($row->institution_id, $row->campus_id, $membership);
            if ($row->status !== 'pending_approval' || (int) $row->requested_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('Concession review requires a pending request and an independent reviewer.');
            }
            $row->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            $this->concessionEvent($row, $actor, $row->status, null, ['note' => mb_substr(trim($note), 0, 500)]);
            $this->audit->record('billing.concession.'.$row->status, $actor, (int) $membership->tenant_id, $row, ['student_id' => (int) $row->student_id]);

            return $row;
        }, 3);
    }

    public function revokeConcession(int $id, string $reason, User $actor): BillingConcession
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.concessions.manage'), 403);

        return DB::transaction(function () use ($id, $reason, $actor, $membership): BillingConcession {
            $row = BillingConcession::query()->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($row->institution_id, $row->campus_id, $membership);
            if ($row->status !== 'approved' || trim($reason) === '') {
                throw new ConflictHttpException('Only an approved concession can be revoked with a reason. Existing invoice snapshots remain unchanged.');
            }
            $row->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'revocation_reason' => trim($reason)])->save();
            $this->concessionEvent($row, $actor, 'revoked', null, ['reason' => mb_substr(trim($reason), 0, 500)]);
            $this->audit->record('billing.concession.revoked', $actor, (int) $membership->tenant_id, $row, ['student_id' => (int) $row->student_id]);

            return $row;
        }, 3);
    }

    /** @param list<array{due_on:string,amount:string}> $rows */
    public function createInstallments(int $invoiceId, array $rows, User $actor): BillingInstallmentPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.installments.manage'), 403);
        if ($rows === [] || count($rows) > 36) {
            throw ValidationException::withMessages(['installments' => 'Provide between 1 and 36 installments.']);
        }

        return DB::transaction(function () use ($invoiceId, $rows, $actor, $membership): BillingInstallmentPlan {
            $invoice = BillingInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $this->assertFinancialScope($invoice->institution_id, $invoice->campus_id, $membership);
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['invoice' => 'Cancelled invoices cannot have installment schedules.']);
            }
            if (BillingInstallmentPlan::query()->where('invoice_id', $invoiceId)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'An installment plan already exists; use the authorized rescheduling workflow.']);
            }
            $total = 0;
            $previous = null;
            foreach ($rows as $index => $item) {
                $amount = BillingMoney::toMinor($item['amount']);
                $date = Carbon::parse($item['due_on']);
                if ($amount < 1 || ($previous !== null && $date->lt($previous))) {
                    throw ValidationException::withMessages(['installments' => 'Installments must have positive amounts and nondecreasing due dates.']);
                }
                $total += $amount;
                $previous = $date;
            }
            if ($total !== BillingMoney::toMinor((string) $invoice->adjusted_total)) {
                throw ValidationException::withMessages(['installments' => 'Installment amounts must reconcile exactly to the invoice amount due.']);
            }
            $plan = BillingInstallmentPlan::query()->create(['invoice_id' => $invoiceId, 'status' => 'draft', 'installment_count' => count($rows), 'created_by' => $actor->getKey(), 'version' => 1]);
            foreach ($rows as $index => $item) {
                BillingInstallment::query()->create(['plan_id' => $plan->getKey(), 'sequence' => $index + 1, 'due_on' => $item['due_on'], 'amount' => BillingMoney::fromMinor(BillingMoney::toMinor($item['amount'])), 'status' => 'scheduled']);
            }
            $this->audit->record('billing.installment_plan.created', $actor, (int) $membership->tenant_id, $plan, ['invoice_id' => $invoiceId, 'count' => count($rows)]);

            return $plan->load('installments');
        }, 3);
    }

    public function publishInstallments(int $planId, User $actor): BillingInstallmentPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.installments.manage'), 403);

        return DB::transaction(function () use ($planId, $actor, $membership): BillingInstallmentPlan {
            $plan = BillingInstallmentPlan::query()->with('invoice', 'installments')->lockForUpdate()->findOrFail($planId);
            $this->assertFinancialScope($plan->invoice->institution_id, $plan->invoice->campus_id, $membership);
            if ($plan->status !== 'draft' || $plan->installments->count() !== (int) $plan->installment_count) {
                throw new ConflictHttpException('Only a complete draft installment plan can be published.');
            }
            $sum = $plan->installments->sum(fn (BillingInstallment $row): int => BillingMoney::toMinor((string) $row->amount));
            if ($sum !== BillingMoney::toMinor((string) $plan->invoice->adjusted_total)) {
                throw ValidationException::withMessages(['installments' => 'The schedule no longer reconciles to the invoice balance.']);
            }
            $plan->forceFill(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now()])->save();
            $this->audit->record('billing.installment_plan.published', $actor, (int) $membership->tenant_id, $plan, ['invoice_id' => (int) $plan->invoice_id, 'count' => (int) $plan->installment_count]);

            return $plan;
        }, 3);
    }

    /** @param array{invoice_id:int,kind:string,amount:string,currency:string,idempotency_key:string,reason:string} $data */
    public function requestAdjustment(array $data, User $actor): BillingAdjustment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.adjustments.manage'), 403);
        $amount = BillingMoney::toMinor($data['amount']);
        if ($amount < 1 || ! in_array($data['kind'], ['credit', 'debit'], true)) {
            throw ValidationException::withMessages(['amount' => 'A positive amount and supported credit/debit type are required.']);
        }

        return DB::transaction(function () use ($data, $actor, $membership, $amount): BillingAdjustment {
            $invoice = BillingInvoice::query()->lockForUpdate()->findOrFail($data['invoice_id']);
            $this->assertFinancialScope($invoice->institution_id, $invoice->campus_id, $membership);
            if ($invoice->status === 'cancelled' || $invoice->currency !== $data['currency']) {
                throw ValidationException::withMessages(['invoice_id' => 'The invoice is not eligible for this adjustment.']);
            }
            $existing = BillingAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->invoice_id !== (int) $invoice->getKey() || $existing->kind !== $data['kind'] || BillingMoney::toMinor((string) $existing->amount) !== $amount) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key is already bound to different adjustment details.']);
                }

                return $existing;
            }
            $adjustment = BillingAdjustment::query()->create([
                'invoice_id' => $invoice->getKey(), 'adjustment_number' => 'ADJ-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'idempotency_key' => $data['idempotency_key'], 'kind' => $data['kind'], 'amount' => BillingMoney::fromMinor($amount),
                'currency' => $invoice->currency, 'status' => 'pending_approval', 'reason' => trim($data['reason']), 'requested_by' => $actor->getKey(),
            ]);
            $this->adjustmentEvent($adjustment, $actor, 'requested', ['invoice_id' => (int) $invoice->getKey()]);
            $this->audit->record('billing.adjustment.requested', $actor, (int) $membership->tenant_id, $adjustment, ['invoice_id' => (int) $invoice->getKey(), 'kind' => $adjustment->kind, 'amount' => (string) $adjustment->amount]);

            return $adjustment;
        }, 3);
    }

    public function reviewAdjustment(int $id, bool $approve, string $note, User $actor): BillingAdjustment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.adjustments.approve'), 403);

        return DB::transaction(function () use ($id, $approve, $note, $actor, $membership): BillingAdjustment {
            $row = BillingAdjustment::query()->with('invoice')->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($row->invoice->institution_id, $row->invoice->campus_id, $membership);
            if ($row->status !== 'pending_approval' || (int) $row->requested_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('Adjustment review requires a pending request and an independent approver.');
            }
            $row->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            $this->adjustmentEvent($row, $actor, $row->status, ['note' => mb_substr(trim($note), 0, 500)]);
            $this->audit->record('billing.adjustment.'.$row->status, $actor, (int) $membership->tenant_id, $row, ['invoice_id' => (int) $row->invoice_id]);

            return $row;
        }, 3);
    }

    public function postAdjustment(int $id, User $actor): BillingAdjustment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.adjustments.post'), 403);

        return DB::transaction(function () use ($id, $actor, $membership): BillingAdjustment {
            $row = BillingAdjustment::query()->with('invoice')->lockForUpdate()->findOrFail($id);
            $invoice = BillingInvoice::query()->lockForUpdate()->findOrFail($row->invoice_id);
            $this->assertFinancialScope($invoice->institution_id, $invoice->campus_id, $membership);
            if ($row->status !== 'approved' || (int) $row->requested_by === (int) $actor->getKey() || (int) $row->reviewed_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('Only an approved adjustment may be posted by a third authorized financial operator.');
            }
            $amount = BillingMoney::toMinor((string) $row->amount);
            $currentTotal = BillingMoney::toMinor((string) $invoice->adjusted_total);
            $paid = BillingMoney::toMinor((string) $invoice->paid_amount);
            $nextTotal = $row->kind === 'credit' ? $currentTotal - $amount : $currentTotal + $amount;
            if ($row->kind === 'credit' && $amount > BillingMoney::toMinor((string) $invoice->outstanding_amount)) {
                throw ValidationException::withMessages(['amount' => 'A credit note cannot exceed the currently outstanding amount.']);
            }
            if ($nextTotal < $paid || $nextTotal < 0) {
                throw ValidationException::withMessages(['amount' => 'The adjustment would create a negative or already-overpaid invoice balance.']);
            }
            $outstanding = $nextTotal - $paid;
            $invoice->forceFill(['adjusted_total' => BillingMoney::fromMinor($nextTotal), 'outstanding_amount' => BillingMoney::fromMinor($outstanding), 'status' => $outstanding === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued')])->save();
            $row->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
            $this->adjustmentEvent($row, $actor, 'posted', ['adjusted_total' => BillingMoney::fromMinor($nextTotal), 'outstanding' => BillingMoney::fromMinor($outstanding)]);
            $this->audit->record('billing.adjustment.posted', $actor, (int) $membership->tenant_id, $row, ['invoice_id' => (int) $invoice->getKey(), 'kind' => $row->kind, 'amount' => (string) $row->amount]);

            return $row;
        }, 3);
    }

    private function assertStudentScope(SisStudent $student, TenantMembership $membership): void
    {
        if ((int) $student->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id)
            || $student->archived_at !== null || ! in_array($student->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true)) {
            abort(404);
        }
    }

    private function assertFinancialScope(int $institutionId, ?int $campusId, TenantMembership $membership): void
    {
        if ($institutionId !== (int) $membership->institution_id || ($membership->campus_id !== null && $campusId !== (int) $membership->campus_id)) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function concessionEvent(BillingConcession $row, User $actor, string $type, ?string $key, array $metadata): void
    {
        BillingConcessionEvent::query()->create(['concession_id' => $row->getKey(), 'actor_user_id' => $actor->getKey(), 'event_type' => $type, 'request_key' => $key, 'metadata' => $metadata, 'occurred_at' => now()]);
    }

    /** @param array<string, mixed> $metadata */
    private function adjustmentEvent(BillingAdjustment $row, User $actor, string $type, array $metadata): void
    {
        BillingAdjustmentEvent::query()->create(['adjustment_id' => $row->getKey(), 'actor_user_id' => $actor->getKey(), 'event_type' => $type, 'metadata' => $metadata, 'occurred_at' => now()]);
    }
}
