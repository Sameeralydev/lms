<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingAdjustment;
use App\Models\BillingConcession;
use App\Models\BillingConcessionApplication;
use App\Models\BillingFeeAssignment;
use App\Models\BillingFeeStructure;
use App\Models\BillingInvoice;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\User;
use App\Notifications\BillingActivityNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BillingInvoiceIssuer
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /**
     * @param  array{student_id:int,fee_structure_id:int,period_start:string,period_end:string,due_on:string,idempotency_key:string,fee_assignment_id?:int|null,concession_id?:int|null}  $data
     */
    public function issue(array $data, User $actor): BillingInvoice
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.invoices.issue'), 403);

        return DB::transaction(function () use ($data, $actor, $membership): BillingInvoice {
            $existing = BillingInvoice::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->student_id !== $data['student_id']
                    || (int) $existing->fee_structure_id !== $data['fee_structure_id']
                    || $existing->period_start->toDateString() !== $data['period_start']
                    || $existing->period_end->toDateString() !== $data['period_end']
                    || $existing->due_on->toDateString() !== $data['due_on']
                    || ($existing->fee_assignment_id === null ? null : (int) $existing->fee_assignment_id) !== ($data['fee_assignment_id'] ?? null)
                    || ($existing->concession_id === null ? null : (int) $existing->concession_id) !== ($data['concession_id'] ?? null)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key is already bound to a different invoice request.']);
                }

                return $existing->load('items');
            }

            $student = SisStudent::query()->lockForUpdate()->findOrFail($data['student_id']);
            if (! in_array($student->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true) || $student->archived_at !== null) {
                throw ValidationException::withMessages(['student_id' => 'This learner is not eligible for a new institutional charge.']);
            }
            $institution = Institution::query()->whereKey($student->institution_id)->firstOrFail();
            if ((int) $institution->getKey() !== (int) $membership->institution_id) {
                abort(404);
            }
            if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $student->home_campus_id) {
                abort(404);
            }
            if (BillingInvoice::query()->where('student_id', $student->getKey())->where('fee_structure_id', $data['fee_structure_id'])
                ->whereDate('period_start', $data['period_start'])->whereDate('period_end', $data['period_end'])->exists()) {
                throw ValidationException::withMessages(['period_start' => 'An invoice already exists for this learner, fee structure and billing period.']);
            }

            $structure = BillingFeeStructure::query()->with('items.feeHead')->lockForUpdate()->findOrFail($data['fee_structure_id']);
            if ((int) $structure->institution_id !== (int) $student->institution_id
                || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $student->home_campus_id)) {
                abort(404);
            }
            $periodStart = Carbon::parse($data['period_start']);
            $periodEnd = Carbon::parse($data['period_end']);
            $dueOn = Carbon::parse($data['due_on']);
            if ($structure->status !== 'active'
                || $periodStart->lt($structure->effective_from)
                || $periodEnd->lt($structure->effective_from)
                || ($structure->effective_until !== null && ($periodStart->gt($structure->effective_until) || $periodEnd->gt($structure->effective_until)))
                || $dueOn->lt($periodStart)) {
                throw ValidationException::withMessages(['fee_structure_id' => 'The selected fee structure is not active for this billing period.']);
            }
            if ($structure->items->isEmpty()) {
                throw ValidationException::withMessages(['fee_structure_id' => 'A fee structure must contain at least one active fee item before it can be billed.']);
            }

            $assignmentId = $data['fee_assignment_id'] ?? null;
            $assignment = null;
            if ($assignmentId !== null) {
                $assignment = BillingFeeAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
                if ((int) $assignment->student_id !== (int) $student->getKey() || (int) $assignment->fee_structure_id !== (int) $structure->getKey()
                    || $assignment->status !== 'assigned' || Carbon::parse($assignment->period_start)->toDateString() !== $data['period_start']
                    || Carbon::parse($assignment->period_end)->toDateString() !== $data['period_end'] || Carbon::parse($assignment->effective_from)->gt($periodStart)
                    || ($assignment->effective_until !== null && Carbon::parse($assignment->effective_until)->lt($periodEnd))) {
                    throw ValidationException::withMessages(['fee_assignment_id' => 'The fee assignment does not apply to this learner, structure, or billing period.']);
                }
            }

            $subtotalMinor = 0;
            $lineSnapshots = [];
            foreach ($structure->items as $item) {
                if ($item->feeHead === null || $item->feeHead->status !== 'active') {
                    throw ValidationException::withMessages(['fee_structure_id' => 'The fee structure contains an inactive fee head.']);
                }
                $unitMinor = BillingMoney::toMinor((string) $item->unit_amount);
                $lineMinor = $unitMinor * (int) $item->quantity;
                $subtotalMinor += $lineMinor;
                $lineSnapshots[] = [
                    'fee_head_code' => $item->feeHead->code,
                    'fee_head_name' => $item->feeHead->name,
                    'quantity' => (int) $item->quantity,
                    'unit_amount' => BillingMoney::fromMinor($unitMinor),
                    'line_total' => BillingMoney::fromMinor($lineMinor),
                ];
            }
            if ($subtotalMinor <= 0) {
                throw ValidationException::withMessages(['fee_structure_id' => 'Invoice total must be greater than zero.']);
            }

            $concession = null;
            $discountMinor = 0;
            if (($data['concession_id'] ?? null) !== null) {
                $concession = BillingConcession::query()->lockForUpdate()->findOrFail((int) $data['concession_id']);
                if ((int) $concession->student_id !== (int) $student->getKey() || $concession->status !== 'approved'
                    || Carbon::parse($concession->effective_from)->gt($periodEnd) || ($concession->effective_until !== null && Carbon::parse($concession->effective_until)->lt($periodStart))) {
                    throw ValidationException::withMessages(['concession_id' => 'The concession is not approved or effective for this learner and billing period.']);
                }
                $valueMinor = BillingMoney::toMinor((string) $concession->value);
                $discountMinor = $concession->kind === 'percentage'
                    ? min($subtotalMinor, intdiv(($subtotalMinor * $valueMinor) + 5000, 10000))
                    : min($subtotalMinor, $valueMinor);
            }
            if ($discountMinor >= $subtotalMinor) {
                throw ValidationException::withMessages(['concession_id' => 'A concession cannot reduce an invoice to zero or a negative amount.']);
            }
            $totalMinor = $subtotalMinor - $discountMinor;
            $total = BillingMoney::fromMinor($totalMinor);
            $subtotal = BillingMoney::fromMinor($subtotalMinor);
            $concessionSnapshot = $concession === null ? null : [
                'code' => $concession->code, 'name' => $concession->name, 'kind' => $concession->kind,
                'value' => (string) $concession->value, 'subtotal' => BillingMoney::fromMinor($subtotalMinor),
                'discount_amount' => BillingMoney::fromMinor($discountMinor), 'approved_by' => (int) $concession->reviewed_by,
            ];
            $invoice = BillingInvoice::query()->create([
                'institution_id' => $student->institution_id,
                'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(),
                'fee_structure_id' => $structure->getKey(),
                'fee_assignment_id' => $assignment?->getKey(), 'concession_id' => $concession?->getKey(),
                'invoice_number' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'idempotency_key' => $data['idempotency_key'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'due_on' => $data['due_on'],
                'currency' => $structure->currency,
                'subtotal' => $subtotal,
                'discount_total' => BillingMoney::fromMinor($discountMinor), 'adjusted_total' => $total,
                'concession_snapshot' => $concessionSnapshot,
                'total' => $total,
                'paid_amount' => '0.00',
                'outstanding_amount' => $total,
                'status' => 'issued',
                'issued_at' => now(),
                'issued_by' => $actor->getKey(),
            ]);
            foreach ($lineSnapshots as $snapshot) {
                $invoice->items()->create($snapshot);
            }
            if ($concession !== null) {
                BillingConcessionApplication::query()->create([
                    'concession_id' => $concession->getKey(), 'invoice_id' => $invoice->getKey(),
                    'subtotal' => BillingMoney::fromMinor($subtotalMinor), 'discount_amount' => BillingMoney::fromMinor($discountMinor),
                    'calculation_snapshot' => $concessionSnapshot, 'applied_at' => now(),
                ]);
            }
            $this->audit->record('billing.invoice.issued', $actor, (int) $membership->tenant_id, $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'student_id' => (int) $student->getKey(),
                'fee_structure_id' => (int) $structure->getKey(),
                'currency' => $invoice->currency,
                'total' => $total,
            ]);
            $recipients = collect([$student->user])->filter()->merge(
                SisStudentGuardian::query()->where('student_id', $student->getKey())->where('authority_status', 'verified')
                    ->where('can_view_financial_records', true)->whereNull('revoked_at')->with('guardian')->get()->pluck('guardian'),
            )->filter()->unique(fn (User $recipient): int => (int) $recipient->getKey());
            foreach ($recipients as $recipient) {
                $recipient->notify(new BillingActivityNotification('billing.invoice.issued', 'A new fee invoice is available.', [
                    'invoice_number' => (string) $invoice->invoice_number, 'amount_due' => (string) $invoice->outstanding_amount,
                    'currency' => (string) $invoice->currency, 'due_on' => $invoice->due_on->toDateString(), 'tenant_slug' => (string) $membership->tenant->slug,
                ]));
            }

            return $invoice->load('items');
        }, 3);
    }

    public function cancel(BillingInvoice $invoice, User $actor, string $reason): BillingInvoice
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.invoices.cancel'), 403);

        return DB::transaction(function () use ($invoice, $actor, $reason, $membership): BillingInvoice {
            $locked = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->status !== 'issued' || BillingMoney::toMinor((string) $locked->paid_amount) !== 0
                || BillingAdjustment::query()->where('invoice_id', $locked->getKey())->whereIn('status', ['posted', 'reversed'])->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Only an unpaid issued invoice can be cancelled. Paid or adjusted history must be corrected through an approved compensating record.']);
            }
            $locked->forceFill(['status' => 'cancelled', 'outstanding_amount' => '0.00', 'cancellation_reason' => trim($reason), 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey()])->save();
            $this->audit->record('billing.invoice.cancelled', $actor, (int) $membership->tenant_id, $locked, ['reason' => mb_substr(trim($reason), 0, 500)]);

            return $locked;
        }, 3);
    }
}
