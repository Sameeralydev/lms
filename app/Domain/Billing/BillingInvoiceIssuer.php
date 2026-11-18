<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingFeeStructure;
use App\Models\BillingInvoice;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BillingInvoiceIssuer
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /**
     * @param  array{student_id:int,fee_structure_id:int,period_start:string,period_end:string,due_on:string,idempotency_key:string}  $data
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
                    || $existing->due_on->toDateString() !== $data['due_on']) {
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

            $total = BillingMoney::fromMinor($subtotalMinor);
            $invoice = BillingInvoice::query()->create([
                'institution_id' => $student->institution_id,
                'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(),
                'fee_structure_id' => $structure->getKey(),
                'invoice_number' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'idempotency_key' => $data['idempotency_key'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'due_on' => $data['due_on'],
                'currency' => $structure->currency,
                'subtotal' => $total,
                'discount_total' => '0.00',
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
            $this->audit->record('billing.invoice.issued', $actor, (int) $membership->tenant_id, $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'student_id' => (int) $student->getKey(),
                'fee_structure_id' => (int) $structure->getKey(),
                'currency' => $invoice->currency,
                'total' => $total,
            ]);

            return $invoice->load('items');
        }, 3);
    }

    public function cancel(BillingInvoice $invoice, User $actor, string $reason): BillingInvoice
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.invoices.cancel'), 403);

        return DB::transaction(function () use ($invoice, $actor, $reason, $membership): BillingInvoice {
            $locked = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->status !== 'issued' || BillingMoney::toMinor((string) $locked->paid_amount) !== 0) {
                throw ValidationException::withMessages(['invoice' => 'Only an unpaid issued invoice can be cancelled. Paid or adjusted history must be corrected through an approved compensating record.']);
            }
            $locked->forceFill(['status' => 'cancelled', 'outstanding_amount' => '0.00', 'cancellation_reason' => trim($reason), 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey()])->save();
            $this->audit->record('billing.invoice.cancelled', $actor, (int) $membership->tenant_id, $locked, ['reason' => mb_substr(trim($reason), 0, 500)]);

            return $locked;
        }, 3);
    }
}
