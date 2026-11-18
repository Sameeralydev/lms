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
use App\Models\BillingInstallmentEvent;
use App\Models\BillingInstallmentPlan;
use App\Models\BillingInvoice;
use App\Models\BillingRecurringPlan;
use App\Models\CourseEnrollment;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\TenantMembership;
use App\Models\TenantSetting;
use App\Models\User;
use App\Notifications\BillingActivityNotification;
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
            $this->notifyStudent((int) $row->student_id, 'billing.concession.'.$row->status, 'A financial support request was '.$row->status.'.', ['concession_code' => (string) $row->code]);

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
            $this->installmentEvent($plan, $actor, 'created', null, null, ['after' => $this->installmentSnapshot($plan->load('installments'))]);
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
            $this->installmentEvent($plan, $actor, 'published', null, null, ['after' => $this->installmentSnapshot($plan)]);
            $this->audit->record('billing.installment_plan.published', $actor, (int) $membership->tenant_id, $plan, ['invoice_id' => (int) $plan->invoice_id, 'count' => (int) $plan->installment_count]);
            $this->notifyInvoiceStudent($plan->invoice, 'billing.installments.published', 'An installment schedule is available.', ['installment_count' => (int) $plan->installment_count]);

            return $plan;
        }, 3);
    }

    /** @param list<array{sequence?:int|null,due_on:string,amount:string}> $rows */
    public function amendInstallments(int $planId, array $rows, string $reason, string $requestKey, User $actor): BillingInstallmentPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.installments.manage'), 403);
        if ($rows === [] || count($rows) > 36 || trim($reason) === '') {
            throw ValidationException::withMessages(['installments' => 'A reason and between 1 and 36 revised installments are required.']);
        }
        $hash = hash('sha256', json_encode(['rows' => $rows, 'reason' => trim($reason)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($planId, $rows, $reason, $requestKey, $hash, $actor, $membership): BillingInstallmentPlan {
            $plan = BillingInstallmentPlan::query()->with(['invoice', 'installments'])->lockForUpdate()->findOrFail($planId);
            $invoice = BillingInvoice::query()->lockForUpdate()->findOrFail($plan->invoice_id);
            $this->assertFinancialScope($invoice->institution_id, $invoice->campus_id, $membership);
            if ($plan->status !== 'published' || $invoice->status === 'cancelled') {
                throw new ConflictHttpException('Only a published schedule for a live invoice can be amended.');
            }

            $priorEvent = BillingInstallmentEvent::query()->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($priorEvent !== null) {
                if ((int) $priorEvent->plan_id !== (int) $plan->getKey() || $priorEvent->request_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'This amendment key is already bound to different schedule data.']);
                }

                return $plan->fresh(['invoice', 'installments']);
            }

            $active = $plan->installments->filter(static fn (BillingInstallment $item): bool => $item->status !== 'cancelled')->sortBy('sequence')->values();
            $remainingPaid = BillingMoney::toMinor((string) $invoice->paid_amount);
            $paidBySequence = [];
            foreach ($active as $item) {
                $amountMinor = BillingMoney::toMinor((string) $item->amount);
                $paidBySequence[(int) $item->sequence] = min($remainingPaid, $amountMinor);
                $remainingPaid -= $paidBySequence[(int) $item->sequence];
            }

            $before = $this->installmentSnapshot($plan);
            $existingBySequence = $active->keyBy('sequence');
            $seenSequences = [];
            $totalMinor = 0;
            $previousDate = null;
            $normalized = [];
            $nextSequence = (int) $plan->installments->max('sequence');
            foreach ($rows as $row) {
                $sequence = isset($row['sequence']) ? (int) $row['sequence'] : null;
                if ($sequence !== null) {
                    if (! $existingBySequence->has($sequence) || isset($seenSequences[$sequence])) {
                        throw ValidationException::withMessages(['installments' => 'Each existing installment sequence may appear at most once.']);
                    }
                    $seenSequences[$sequence] = true;
                    $old = $existingBySequence->get($sequence);
                    $amountMinor = BillingMoney::toMinor($row['amount']);
                    if ($paidBySequence[$sequence] > 0 && (Carbon::parse($old->due_on)->toDateString() !== Carbon::parse($row['due_on'])->toDateString()
                        || BillingMoney::toMinor((string) $old->amount) !== $amountMinor)) {
                        throw ValidationException::withMessages(['installments' => 'An installment with posted payments is immutable; amend only unpaid installments.']);
                    }
                } else {
                    $sequence = ++$nextSequence;
                    $amountMinor = BillingMoney::toMinor($row['amount']);
                }
                $date = Carbon::parse($row['due_on']);
                if ($amountMinor < 1 || ($previousDate !== null && $date->lt($previousDate))) {
                    throw ValidationException::withMessages(['installments' => 'Installment amounts must be positive and due dates ordered by sequence.']);
                }
                $totalMinor += $amountMinor;
                $previousDate = $date;
                $normalized[] = ['sequence' => $sequence, 'due_on' => $date->toDateString(), 'amount_minor' => $amountMinor];
            }

            if ($totalMinor !== BillingMoney::toMinor((string) $invoice->adjusted_total)) {
                throw ValidationException::withMessages(['installments' => 'The amended schedule must still reconcile to the full invoice obligation.']);
            }
            foreach ($active as $item) {
                if (! isset($seenSequences[(int) $item->sequence]) && ($paidBySequence[(int) $item->sequence] ?? 0) > 0) {
                    throw ValidationException::withMessages(['installments' => 'An installment with posted payments cannot be removed.']);
                }
                if (! isset($seenSequences[(int) $item->sequence])) {
                    $item->forceFill(['status' => 'cancelled'])->save();
                }
            }
            foreach ($normalized as $item) {
                $installment = $existingBySequence->get($item['sequence']);
                if ($installment !== null) {
                    $installment->forceFill(['due_on' => $item['due_on'], 'amount' => BillingMoney::fromMinor($item['amount_minor'])])->save();
                } else {
                    BillingInstallment::query()->create([
                        'tenant_id' => $membership->tenant_id, 'plan_id' => $plan->getKey(), 'sequence' => $item['sequence'],
                        'due_on' => $item['due_on'], 'amount' => BillingMoney::fromMinor($item['amount_minor']), 'status' => 'scheduled',
                    ]);
                }
            }
            $plan->forceFill(['installment_count' => count($normalized), 'version' => (int) $plan->version + 1])->save();
            $plan->refresh()->load(['invoice', 'installments']);
            $after = $this->installmentSnapshot($plan);
            $this->installmentEvent($plan, $actor, 'amended', $requestKey, $hash, ['reason' => mb_substr(trim($reason), 0, 500), 'before' => $before, 'after' => $after], trim($reason));
            $this->audit->record('billing.installment_plan.amended', $actor, (int) $membership->tenant_id, $plan, ['invoice_id' => (int) $invoice->getKey(), 'version' => (int) $plan->version, 'reason' => mb_substr(trim($reason), 0, 500)]);
            $this->notifyInvoiceStudent($invoice, 'billing.installments.amended', 'The installment schedule for an invoice was updated.', ['installment_plan_id' => (int) $plan->getKey(), 'version' => (int) $plan->version]);

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
            $this->reconcilePublishedInstallmentPlan($invoice, $actor, $nextTotal - $currentTotal, 'Adjustment '.$row->adjustment_number.' posted.');
            $row->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
            if ($row->reverses_adjustment_id !== null) {
                $original = BillingAdjustment::query()->lockForUpdate()->findOrFail($row->reverses_adjustment_id);
                if ($original->status !== 'posted') {
                    throw new ConflictHttpException('The original adjustment is no longer posted; reversal was not applied.');
                }
                $original->forceFill(['status' => 'reversed'])->save();
                $this->adjustmentEvent($original, $actor, 'reversed', ['reversal_adjustment_id' => (int) $row->getKey()]);
                $this->audit->record('billing.adjustment.reversed', $actor, (int) $membership->tenant_id, $original, ['reversal_adjustment_id' => (int) $row->getKey()]);
            }
            $this->adjustmentEvent($row, $actor, 'posted', ['adjusted_total' => BillingMoney::fromMinor($nextTotal), 'outstanding' => BillingMoney::fromMinor($outstanding)]);
            $this->audit->record('billing.adjustment.posted', $actor, (int) $membership->tenant_id, $row, ['invoice_id' => (int) $invoice->getKey(), 'kind' => $row->kind, 'amount' => (string) $row->amount]);
            $this->notifyInvoiceStudent($invoice, $row->reverses_adjustment_id === null ? 'billing.adjustment.posted' : 'billing.adjustment.reversed', 'A reviewed invoice balance adjustment was posted.', ['adjustment_number' => (string) $row->adjustment_number]);

            return $row;
        }, 3);
    }

    public function requestAdjustmentReversal(int $id, string $reason, string $idempotencyKey, User $actor): BillingAdjustment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.adjustments.manage'), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reversal reason is required.']);
        }

        return DB::transaction(function () use ($id, $reason, $idempotencyKey, $actor, $membership): BillingAdjustment {
            $original = BillingAdjustment::query()->with('invoice')->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($original->invoice->institution_id, $original->invoice->campus_id, $membership);
            if ($original->status !== 'posted' || $original->reverses_adjustment_id !== null || (int) $original->requested_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('Only another authorized operator may request reversal of a posted adjustment.');
            }
            $existing = BillingAdjustment::query()->where('reverses_adjustment_id', $original->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            $reversal = BillingAdjustment::query()->create([
                'invoice_id' => $original->invoice_id,
                'reverses_adjustment_id' => $original->getKey(),
                'adjustment_number' => 'REV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'idempotency_key' => $idempotencyKey, 'kind' => $original->kind === 'credit' ? 'debit' : 'credit',
                'amount' => $original->amount, 'currency' => $original->currency, 'status' => 'pending_approval',
                'reason' => 'Reversal of '.$original->adjustment_number.': '.trim($reason), 'requested_by' => $actor->getKey(),
            ]);
            $this->adjustmentEvent($reversal, $actor, 'reversal_requested', ['original_adjustment_id' => (int) $original->getKey(), 'reason' => mb_substr(trim($reason), 0, 500)]);
            $this->audit->record('billing.adjustment.reversal_requested', $actor, (int) $membership->tenant_id, $reversal, ['original_adjustment_id' => (int) $original->getKey()]);

            return $reversal;
        }, 3);
    }

    /** @param array{student_id:int,fee_structure_id:int,frequency:string,period_start:string,period_end:string,due_days_after_period:int,timezone:string} $data */
    public function createRecurringPlan(array $data, User $actor): BillingRecurringPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage') && $membership->hasPermission('billing.invoices.issue'), 403);
        if (! in_array($data['frequency'], ['monthly', 'annual'], true) || ! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['frequency' => 'Choose a supported billing frequency and valid institution timezone.']);
        }

        return DB::transaction(function () use ($data, $actor, $membership): BillingRecurringPlan {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($data['student_id']);
            $this->assertStudentScope($student, $membership);
            $structure = BillingFeeStructure::query()->lockForUpdate()->findOrFail($data['fee_structure_id']);
            if ($structure->status !== 'active' || (int) $structure->institution_id !== (int) $student->institution_id
                || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $student->home_campus_id)
                || ! in_array($structure->frequency, ['monthly', 'annual'], true)) {
                throw ValidationException::withMessages(['fee_structure_id' => 'Choose an active recurring fee structure for this learner.']);
            }
            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end = Carbon::parse($data['period_end'])->startOfDay();
            $expectedMonths = ['monthly' => 1, 'annual' => 12][$data['frequency']];
            if ($end->lt($start) || $end->toDateString() !== $start->copy()->addMonthsNoOverflow($expectedMonths)->subDay()->toDateString()
                || $data['frequency'] !== $structure->frequency || $data['due_days_after_period'] > 90) {
                throw ValidationException::withMessages(['period_end' => 'The initial period must match the selected structure frequency; due-day offset is limited to 90 days.']);
            }
            $exists = BillingRecurringPlan::query()->where('student_id', $student->getKey())->where('fee_structure_id', $structure->getKey())
                ->where('period_start', $start->toDateString())->where('period_end', $end->toDateString())->exists();
            if ($exists) {
                throw ValidationException::withMessages(['period_start' => 'A recurring plan already covers this learner and period.']);
            }
            $nextRunAt = Carbon::parse($end->toDateString(), $data['timezone'])->addDays((int) $data['due_days_after_period'])->startOfDay()->utc();
            $plan = BillingRecurringPlan::query()->create([
                'institution_id' => $student->institution_id, 'campus_id' => $student->home_campus_id, 'student_id' => $student->getKey(),
                'fee_structure_id' => $structure->getKey(), 'frequency' => $data['frequency'], 'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(), 'due_days_after_period' => $data['due_days_after_period'], 'timezone' => $data['timezone'],
                'next_run_at' => $nextRunAt, 'status' => 'active', 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('billing.recurring_plan.created', $actor, (int) $membership->tenant_id, $plan, ['student_id' => (int) $student->getKey(), 'frequency' => $plan->frequency]);

            return $plan;
        }, 3);
    }

    public function setRecurringPlanStatus(int $id, string $status, User $actor): BillingRecurringPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage'), 403);
        if (! in_array($status, ['active', 'paused', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid recurring-plan state.']);
        }

        return DB::transaction(function () use ($id, $status, $actor, $membership): BillingRecurringPlan {
            $plan = BillingRecurringPlan::query()->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($plan->institution_id, $plan->campus_id, $membership);
            if ($plan->status === 'cancelled' || ($status === 'cancelled' && $plan->status === 'cancelled')) {
                throw new ConflictHttpException('A cancelled recurring plan is terminal.');
            }
            $plan->forceFill(['status' => $status])->save();
            $this->audit->record('billing.recurring_plan.'.$status, $actor, (int) $membership->tenant_id, $plan, []);

            return $plan;
        }, 3);
    }

    public function retryRecurringPlan(int $id, User $actor): BillingRecurringPlan
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage') && $membership->hasPermission('billing.invoices.issue'), 403);

        return DB::transaction(function () use ($id, $actor, $membership): BillingRecurringPlan {
            $plan = BillingRecurringPlan::query()->with('runs')->lockForUpdate()->findOrFail($id);
            $this->assertFinancialScope($plan->institution_id, $plan->campus_id, $membership);
            $latestRun = $plan->runs->first();
            if ($plan->status !== 'active' || $latestRun === null || $latestRun->status !== 'failed'
                || $latestRun->period_start !== $plan->period_start->toDateString() || $latestRun->period_end !== $plan->period_end->toDateString()) {
                throw new ConflictHttpException('Only the current failed billing period of an active recurring plan can be retried.');
            }
            $plan->forceFill(['next_run_at' => now()])->save();
            $this->audit->record('billing.recurring_plan.retry_scheduled', $actor, (int) $membership->tenant_id, $plan, [
                'period_start' => $plan->period_start->toDateString(), 'period_end' => $plan->period_end->toDateString(), 'previous_attempts' => (int) $latestRun->attempts,
            ]);

            return $plan;
        }, 3);
    }

    public function configureEnrollmentFeeAssessment(?int $feeStructureId, User $actor): void
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage'), 403);
        if ($feeStructureId !== null) {
            $structure = BillingFeeStructure::query()->findOrFail($feeStructureId);
            if ($structure->status !== 'active' || $structure->frequency !== 'one_time' || (int) $structure->institution_id !== (int) $membership->institution_id
                || ($membership->campus_id !== null && $structure->campus_id !== null && (int) $structure->campus_id !== (int) $membership->campus_id)) {
                throw ValidationException::withMessages(['fee_structure_id' => 'Enrollment assessment requires an active one-time structure in the authorized institution/campus.']);
            }
            TenantSetting::query()->updateOrCreate(['key' => 'billing.enrollment_fee_assessment'], ['tenant_id' => $membership->tenant_id, 'value' => ['fee_structure_id' => $feeStructureId]]);
        } else {
            TenantSetting::query()->where('key', 'billing.enrollment_fee_assessment')->delete();
        }
        $this->audit->record('billing.enrollment_assessment.configuration_updated', $actor, (int) $membership->tenant_id, null, ['enabled' => $feeStructureId !== null, 'fee_structure_id' => $feeStructureId]);
    }

    /** @param array{enabled:bool,method:string,effective_from:string,effective_until:?string} $policy */
    public function saveProrationPolicy(array $policy, User $actor): void
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);
        if (! in_array($policy['method'], ['calendar_days'], true)
            || ($policy['effective_until'] !== null && Carbon::parse($policy['effective_until'])->lt(Carbon::parse($policy['effective_from'])))) {
            throw ValidationException::withMessages(['method' => 'Choose a supported calculation method and valid effective dates.']);
        }
        TenantSetting::query()->updateOrCreate(['key' => 'billing.proration_policy'], ['tenant_id' => $membership->tenant_id, 'value' => $policy]);
        $this->audit->record('billing.proration.policy_updated', $actor, (int) $membership->tenant_id, null, [
            'enabled' => $policy['enabled'], 'method' => $policy['method'], 'effective_from' => $policy['effective_from'], 'effective_until' => $policy['effective_until'],
        ]);
    }

    /** @param array{amount:string,period_start:string,period_end:string,charge_start:string,charge_end:string} $input
     * @return array{method:string,full_amount:string,eligible_days:int,period_days:int,prorated_amount:string,eligible_from:string,eligible_until:string}
     */
    public function previewProration(array $input, User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);
        $policy = TenantSetting::query()->where('key', 'billing.proration_policy')->first()?->value;
        if (! is_array($policy) || ! is_bool($policy['enabled'] ?? null) || ! is_string($policy['method'] ?? null)
            || ! is_string($policy['effective_from'] ?? null) || (($policy['effective_until'] ?? null) !== null && ! is_string($policy['effective_until']))) {
            throw ValidationException::withMessages(['policy' => 'Configure a proration policy before requesting a preview.']);
        }
        $result = app(BillingProrationCalculator::class)->preview(
            $input['amount'], $input['period_start'], $input['period_end'], $input['charge_start'], $input['charge_end'], $policy,
        );
        $this->audit->record('billing.proration.previewed', $actor, (int) $membership->tenant_id, null, [
            'method' => $result['method'], 'period_days' => $result['period_days'], 'eligible_days' => $result['eligible_days'],
            'prorated_amount' => $result['prorated_amount'],
        ]);

        return $result;
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

    /** @param array<string, int|string|null> $details */
    private function notifyStudent(int $studentId, string $event, string $message, array $details): void
    {
        $student = SisStudent::query()->with('user')->findOrFail($studentId);
        $recipients = collect([$student->user])->filter()->merge(
            SisStudentGuardian::query()->where('student_id', $studentId)->where('authority_status', 'verified')
                ->where('can_view_financial_records', true)->whereNull('revoked_at')->with('guardian')->get()->pluck('guardian'),
        )->filter()->unique(fn (User $recipient): int => (int) $recipient->getKey());
        foreach ($recipients as $recipient) {
            $recipient->notify(new BillingActivityNotification($event, $message, [
                ...$details,
                'tenant_id' => (int) $this->context->tenant()->getKey(),
                'tenant_slug' => (string) $this->context->tenant()->slug,
            ]));
        }
    }

    /** @param array<string, int|string|null> $details */
    private function notifyInvoiceStudent(BillingInvoice $invoice, string $event, string $message, array $details): void
    {
        $this->notifyStudent((int) $invoice->student_id, $event, $message, [...$details, 'invoice_number' => (string) $invoice->invoice_number]);
    }

    /** @return list<array{sequence:int,due_on:string,amount:string,status:string}> */
    private function installmentSnapshot(BillingInstallmentPlan $plan): array
    {
        return $plan->installments->sortBy('sequence')->map(static fn (BillingInstallment $item): array => [
            'sequence' => (int) $item->sequence,
            'due_on' => Carbon::parse($item->due_on)->toDateString(),
            'amount' => (string) $item->amount,
            'status' => (string) $item->status,
        ])->values()->all();
    }

    /** @param array<string,mixed> $snapshot */
    private function installmentEvent(BillingInstallmentPlan $plan, User $actor, string $event, ?string $requestKey, ?string $requestHash, array $snapshot, ?string $reason = null): void
    {
        BillingInstallmentEvent::query()->create([
            'plan_id' => $plan->getKey(), 'actor_user_id' => $actor->getKey(), 'event_type' => $event,
            'version' => (int) $plan->version, 'request_key' => $requestKey, 'request_hash' => $requestHash, 'reason' => $reason,
            'snapshot' => $snapshot, 'occurred_at' => now(),
        ]);
    }

    private function reconcilePublishedInstallmentPlan(BillingInvoice $invoice, User $actor, int $deltaMinor, string $reason): void
    {
        if ($deltaMinor === 0) {
            return;
        }
        $plan = BillingInstallmentPlan::query()->with('installments')->where('invoice_id', $invoice->getKey())->lockForUpdate()->first();
        if ($plan === null) {
            return;
        }
        $active = $plan->installments->filter(static fn (BillingInstallment $item): bool => $item->status !== 'cancelled')->sortBy('sequence')->values();
        $before = $this->installmentSnapshot($plan);
        $remainingPaid = BillingMoney::toMinor((string) $invoice->paid_amount);
        $paidBySequence = [];
        foreach ($active as $item) {
            $amountMinor = BillingMoney::toMinor((string) $item->amount);
            $paidBySequence[(int) $item->sequence] = min($remainingPaid, $amountMinor);
            $remainingPaid -= $paidBySequence[(int) $item->sequence];
        }

        if ($deltaMinor < 0) {
            $reduction = -$deltaMinor;
            foreach ($active->sortByDesc('sequence') as $item) {
                $sequence = (int) $item->sequence;
                $amountMinor = BillingMoney::toMinor((string) $item->amount);
                $outstandingMinor = $amountMinor - ($paidBySequence[$sequence] ?? 0);
                $applied = min($reduction, $outstandingMinor);
                if ($applied === 0) {
                    continue;
                }
                $nextAmount = $amountMinor - $applied;
                $item->forceFill($nextAmount === 0 ? ['status' => 'cancelled'] : ['amount' => BillingMoney::fromMinor($nextAmount)])->save();
                $reduction -= $applied;
                if ($reduction === 0) {
                    break;
                }
            }
            if ($reduction !== 0) {
                throw new \LogicException('Installment schedule could not absorb an approved invoice credit.');
            }
        } else {
            $lastUnpaid = $active->sortByDesc('sequence')->first(static fn (BillingInstallment $item): bool => ($paidBySequence[(int) $item->sequence] ?? 0) < BillingMoney::toMinor((string) $item->amount));
            if ($lastUnpaid !== null) {
                $amount = BillingMoney::toMinor((string) $lastUnpaid->amount) + $deltaMinor;
                $lastUnpaid->forceFill(['amount' => BillingMoney::fromMinor($amount)])->save();
            } else {
                BillingInstallment::query()->create([
                    'plan_id' => $plan->getKey(), 'sequence' => (int) $plan->installments->max('sequence') + 1,
                    'due_on' => $invoice->due_on->toDateString(), 'amount' => BillingMoney::fromMinor($deltaMinor), 'status' => 'scheduled',
                ]);
            }
        }

        $plan->refresh()->load('installments');
        $plan->forceFill([
            'installment_count' => $plan->installments->filter(static fn (BillingInstallment $item): bool => $item->status !== 'cancelled')->count(),
            'version' => (int) $plan->version + 1,
        ])->save();
        $plan->refresh()->load('installments');
        $deltaSnapshot = $deltaMinor < 0 ? '-'.BillingMoney::fromMinor(-$deltaMinor) : BillingMoney::fromMinor($deltaMinor);
        $this->installmentEvent($plan, $actor, 'adjustment_reconciled', null, null, ['reason' => $reason, 'delta' => $deltaSnapshot, 'before' => $before, 'after' => $this->installmentSnapshot($plan)], $reason);
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
