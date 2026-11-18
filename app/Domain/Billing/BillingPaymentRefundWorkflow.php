<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentAllocation;
use App\Models\BillingPaymentEvent;
use App\Models\BillingPaymentRefund;
use App\Models\BillingPaymentRefundAllocation;
use App\Models\SisStudentGuardian;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\BillingRefundStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingPaymentRefundWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    public function request(int $paymentId, string $amount, string $reason, string $requestKey, User $actor): BillingPaymentRefund
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.refunds.request'), 403);
        $this->assertRequestKey($requestKey);
        $amountMinor = BillingMoney::toMinor($amount);
        if ($amountMinor < 1 || trim($reason) === '') {
            throw ValidationException::withMessages(['amount' => 'A positive refund amount and reason are required.']);
        }

        return DB::transaction(function () use ($paymentId, $amountMinor, $reason, $requestKey, $actor, $membership): BillingPaymentRefund {
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $this->assertPaymentScope($payment, $membership);
            $existing = BillingPaymentRefund::query()->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->payment_id !== (int) $payment->getKey()
                    || BillingMoney::toMinor((string) $existing->amount) !== $amountMinor
                    || $existing->reason !== trim($reason)) {
                    throw ValidationException::withMessages(['request_key' => 'This key is already bound to a different refund request.']);
                }

                return $existing;
            }
            if ($payment->status !== 'confirmed') {
                throw new ConflictHttpException('Only confirmed payments may be considered for a refund.');
            }
            $reservedMinor = 0;
            foreach (BillingPaymentRefund::query()->where('payment_id', $payment->getKey())->whereIn('status', ['pending_approval', 'approved', 'refunded'])->get(['amount']) as $refund) {
                $reservedMinor += BillingMoney::toMinor((string) $refund->amount);
            }
            if ($reservedMinor + $amountMinor > BillingMoney::toMinor((string) $payment->amount)) {
                throw ValidationException::withMessages(['amount' => 'Refund requests cannot exceed the unrefunded confirmed payment amount.']);
            }
            $refund = BillingPaymentRefund::query()->create([
                'payment_id' => $payment->getKey(), 'refund_number' => 'RFD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'request_key' => $requestKey, 'currency' => $payment->currency, 'amount' => BillingMoney::fromMinor($amountMinor),
                'status' => 'pending_approval', 'reason' => trim($reason), 'requested_by' => $actor->getKey(), 'requested_at' => now(),
            ]);
            $this->event($payment, $actor, 'refund.requested', 'confirmed', 'refund_pending_approval', 'refund.request.'.$requestKey, [
                'refund_number' => $refund->refund_number, 'amount' => (string) $refund->amount, 'reason' => mb_substr($refund->reason, 0, 500),
            ]);
            $this->audit->record('billing.refund.requested', $actor, (int) $membership->tenant_id, $refund, ['refund_number' => $refund->refund_number, 'payment_number' => $payment->payment_number, 'amount' => (string) $refund->amount]);
            $this->notifyFinancialRecipients($payment, $refund, 'billing.refund.requested', 'A refund request was submitted for review.');

            return $refund;
        }, 3);
    }

    public function approve(int $refundId, string $note, User $actor): BillingPaymentRefund
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.refunds.approve'), 403);

        return DB::transaction(function () use ($refundId, $note, $actor, $membership): BillingPaymentRefund {
            $refund = BillingPaymentRefund::query()->lockForUpdate()->findOrFail($refundId);
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $this->assertPaymentScope($payment, $membership);
            if ($refund->status !== 'pending_approval') {
                throw new ConflictHttpException('Only pending refund requests can be reviewed.');
            }
            if ((int) $refund->requested_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['refund' => 'A refund requester cannot approve their own request.']);
            }
            $refund->forceFill(['status' => 'approved', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            $this->event($payment, $actor, 'refund.approved', 'refund_pending_approval', 'refund_approved', 'refund.approve.'.$refund->request_key, ['refund_number' => $refund->refund_number, 'note' => mb_substr(trim($note), 0, 500)]);
            $this->audit->record('billing.refund.approved', $actor, (int) $membership->tenant_id, $refund, ['refund_number' => $refund->refund_number, 'amount' => (string) $refund->amount]);
            $this->notifyFinancialRecipients($payment, $refund, 'billing.refund.approved', 'A refund request was approved; payout processing is pending.');

            return $refund;
        }, 3);
    }

    public function reject(int $refundId, string $note, User $actor): BillingPaymentRefund
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.refunds.approve'), 403);

        return DB::transaction(function () use ($refundId, $note, $actor, $membership): BillingPaymentRefund {
            $refund = BillingPaymentRefund::query()->lockForUpdate()->findOrFail($refundId);
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $this->assertPaymentScope($payment, $membership);
            if ($refund->status !== 'pending_approval') {
                throw new ConflictHttpException('Only pending refund requests can be rejected.');
            }
            if ((int) $refund->requested_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['refund' => 'A refund requester cannot review their own request.']);
            }
            $refund->forceFill(['status' => 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            $this->event($payment, $actor, 'refund.rejected', 'refund_pending_approval', 'refund_rejected', 'refund.reject.'.$refund->request_key, ['refund_number' => $refund->refund_number, 'note' => mb_substr(trim($note), 0, 500)]);
            $this->audit->record('billing.refund.rejected', $actor, (int) $membership->tenant_id, $refund, ['refund_number' => $refund->refund_number, 'note' => mb_substr(trim($note), 0, 500)]);
            $this->notifyFinancialRecipients($payment, $refund, 'billing.refund.rejected', 'A refund request was declined.');

            return $refund;
        }, 3);
    }

    public function execute(int $refundId, string $payoutReference, User $actor): BillingPaymentRefund
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.refunds.execute'), 403);
        if (trim($payoutReference) === '') {
            throw ValidationException::withMessages(['payout_reference' => 'Record the manual payout reference before marking a refund as executed.']);
        }

        return DB::transaction(function () use ($refundId, $payoutReference, $actor, $membership): BillingPaymentRefund {
            $refund = BillingPaymentRefund::query()->lockForUpdate()->findOrFail($refundId);
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $this->assertPaymentScope($payment, $membership);
            if ($refund->status === 'refunded' && $refund->payout_reference === trim($payoutReference)) {
                return $refund->load('allocations');
            }
            if ($refund->status !== 'approved') {
                throw new ConflictHttpException('Only approved refunds can be recorded as executed.');
            }
            if ((int) $refund->requested_by === (int) $actor->getKey() || (int) $refund->reviewed_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['refund' => 'The requester and approver cannot record the refund payout.']);
            }
            $payoutReference = trim($payoutReference);
            if (BillingPaymentRefund::query()->where('payout_reference', $payoutReference)->where('id', '<>', $refund->getKey())->exists()) {
                throw ValidationException::withMessages(['payout_reference' => 'This payout reference is already linked to another refund.']);
            }
            $refundMinor = BillingMoney::toMinor((string) $refund->amount);
            $unappliedMinor = BillingMoney::toMinor((string) $payment->unapplied_amount);
            $unappliedRefundMinor = min($refundMinor, $unappliedMinor);
            $allocationRefundMinor = $refundMinor - $unappliedRefundMinor;
            $sources = BillingPaymentAllocation::query()->where('payment_id', $payment->getKey())->orderByDesc('id')->lockForUpdate()->get();
            $sourceIds = $sources->modelKeys();
            $alreadyRefunded = $sourceIds === [] ? collect() : BillingPaymentRefundAllocation::query()->whereIn('payment_allocation_id', $sourceIds)
                ->whereHas('refund', fn ($query) => $query->where('status', 'refunded'))->get()->groupBy('payment_allocation_id');
            $reversals = [];
            foreach ($sources as $source) {
                if ($allocationRefundMinor === 0) {
                    break;
                }
                $previousMinor = (int) ($alreadyRefunded->get($source->getKey(), collect())->sum(fn ($row): int => BillingMoney::toMinor((string) $row->amount)));
                $availableMinor = BillingMoney::toMinor((string) $source->amount) - $previousMinor;
                if ($availableMinor <= 0) {
                    continue;
                }
                $take = min($allocationRefundMinor, $availableMinor);
                $reversals[] = ['source' => $source, 'amount_minor' => $take];
                $allocationRefundMinor -= $take;
            }
            if ($allocationRefundMinor > 0) {
                throw ValidationException::withMessages(['amount' => 'The invoice allocations no longer support this approved refund amount.']);
            }
            $invoiceIds = collect($reversals)->map(static fn (array $row): int => (int) $row['source']->invoice_id)->unique()->sort()->values()->all();
            $invoices = $invoiceIds === [] ? collect() : BillingInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $reversalByInvoice = [];
            foreach ($reversals as $row) {
                $invoiceId = (int) $row['source']->invoice_id;
                $reversalByInvoice[$invoiceId] = ($reversalByInvoice[$invoiceId] ?? 0) + $row['amount_minor'];
            }
            foreach ($reversalByInvoice as $invoiceId => $amountMinor) {
                $invoice = $invoices->get($invoiceId);
                if ($invoice === null || (int) $invoice->student_id !== (int) $payment->student_id || $invoice->status === 'cancelled') {
                    throw ValidationException::withMessages(['amount' => 'A refunded invoice allocation is no longer eligible for reversal.']);
                }
                $paidMinor = BillingMoney::toMinor((string) $invoice->paid_amount);
                if ($amountMinor > $paidMinor) {
                    throw ValidationException::withMessages(['amount' => 'Refund reversal exceeds the invoice paid balance.']);
                }
                $newPaid = $paidMinor - $amountMinor;
                $newOutstanding = BillingMoney::toMinor((string) $invoice->outstanding_amount) + $amountMinor;
                $invoice->forceFill(['paid_amount' => BillingMoney::fromMinor($newPaid), 'outstanding_amount' => BillingMoney::fromMinor($newOutstanding), 'status' => $newPaid === 0 ? 'issued' : 'partially_paid'])->save();
            }
            foreach ($reversals as $row) {
                BillingPaymentRefundAllocation::query()->create([
                    'refund_id' => $refund->getKey(), 'payment_allocation_id' => $row['source']->getKey(), 'invoice_id' => $row['source']->invoice_id,
                    'amount' => BillingMoney::fromMinor($row['amount_minor']), 'reversed_at' => now(),
                ]);
            }
            $payment->forceFill(['unapplied_amount' => BillingMoney::fromMinor($unappliedMinor - $unappliedRefundMinor)])->save();
            $refund->forceFill([
                'status' => 'refunded', 'unapplied_refund_amount' => BillingMoney::fromMinor($unappliedRefundMinor),
                'executed_by' => $actor->getKey(), 'executed_at' => now(), 'payout_reference' => $payoutReference,
            ])->save();
            $this->event($payment, $actor, 'refund.executed', 'refund_approved', 'refunded', 'refund.execute.'.$refund->request_key, [
                'refund_number' => $refund->refund_number, 'amount' => (string) $refund->amount, 'payout_reference' => $payoutReference,
                'unapplied_refund_amount' => BillingMoney::fromMinor($unappliedRefundMinor), 'allocation_reversal_count' => count($reversals),
            ]);
            $this->audit->record('billing.refund.executed', $actor, (int) $membership->tenant_id, $refund, [
                'refund_number' => $refund->refund_number, 'payment_number' => $payment->payment_number, 'amount' => (string) $refund->amount,
                'payout_reference' => $payoutReference, 'allocation_reversal_count' => count($reversals),
            ]);
            $this->notifyFinancialRecipients($payment, $refund, 'billing.refund.executed', 'A refund payout was recorded as executed.');

            return $refund->load('allocations');
        }, 3);
    }

    /** @param array<string, mixed> $metadata */
    private function event(BillingPayment $payment, User $actor, string $type, string $from, string $to, string $requestKey, array $metadata): void
    {
        BillingPaymentEvent::query()->create([
            'payment_id' => $payment->getKey(), 'actor_user_id' => $actor->getKey(), 'event_type' => $type,
            'from_status' => $from, 'to_status' => $to, 'request_key' => $requestKey, 'metadata' => $metadata, 'occurred_at' => now(),
        ]);
    }

    private function assertPaymentScope(BillingPayment $payment, TenantMembership $membership): void
    {
        if ((int) $payment->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $payment->campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
    }

    private function assertRequestKey(string $key): void
    {
        if ($key === '' || strlen($key) > 80) {
            throw ValidationException::withMessages(['request_key' => 'The request key must contain 1 to 80 characters.']);
        }
    }

    private function notifyFinancialRecipients(BillingPayment $payment, BillingPaymentRefund $refund, string $event, string $message): void
    {
        $payment->loadMissing('student');
        $recipients = array_filter([(int) $payment->student->user_id]);
        $guardianIds = SisStudentGuardian::query()->where('student_id', $payment->student_id)
            ->where('authority_status', 'verified')->where('can_view_financial_records', true)
            ->whereNull('revoked_at')->whereNotNull('guardian_user_id')->pluck('guardian_user_id')
            ->map(static fn ($id): int => (int) $id)->all();
        foreach (User::query()->whereIn('id', array_values(array_unique([...$recipients, ...$guardianIds])))->get() as $recipient) {
            $recipient->notify(new BillingRefundStatusNotification($payment, $refund, $event, $message));
        }
    }
}
