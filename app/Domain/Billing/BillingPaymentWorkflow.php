<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentAllocation;
use App\Models\BillingPaymentEvent;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\BillingPaymentStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingPaymentWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /**
     * @param  array{student_id:int,method:string,amount:string,currency:string,reference:?string,idempotency_key:string,allocations:list<array{invoice_id:int,amount:string}>}  $data
     */
    public function record(array $data, User $actor): BillingPayment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.payments.collect'), 403);
        $amountMinor = BillingMoney::toMinor($data['amount']);
        if (preg_match('/^[A-Z]{3}$/', $data['currency']) !== 1) {
            throw ValidationException::withMessages(['currency' => 'Select a supported three-letter currency code.']);
        }
        if (strlen($data['idempotency_key']) > 80) {
            throw ValidationException::withMessages(['idempotency_key' => 'The request key must not exceed 80 characters.']);
        }
        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be greater than zero.']);
        }
        if (! in_array($data['method'], ['cash', 'bank_transfer'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose a supported manual payment method.']);
        }
        if ($data['method'] === 'bank_transfer' && trim((string) $data['reference']) === '') {
            throw ValidationException::withMessages(['reference' => 'A bank transfer reference is required before verification.']);
        }
        if ($data['method'] === 'bank_transfer' && $data['allocations'] !== []) {
            throw ValidationException::withMessages(['allocations' => 'Bank transfers remain unallocated until an authorized verifier confirms the funds.']);
        }

        return DB::transaction(function () use ($data, $actor, $membership, $amountMinor): BillingPayment {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($data['student_id']);
            $this->assertStudentScope($student, $membership);
            $existing = BillingPayment::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertSamePaymentRequest($existing, $data, $amountMinor);

                return $existing->load(['student', 'allocations.invoice']);
            }

            if (BillingPayment::query()->where('institution_id', $membership->institution_id)
                ->where('method', $data['method'])->where('reference', $data['reference'])->whereNotNull('reference')->exists()) {
                throw ValidationException::withMessages(['reference' => 'This payment reference has already been recorded for the institution.']);
            }

            $status = $data['method'] === 'cash' ? 'confirmed' : 'pending_verification';
            $payment = BillingPayment::query()->create([
                'institution_id' => $student->institution_id,
                'campus_id' => $student->home_campus_id,
                'student_id' => $student->getKey(),
                'payment_number' => 'PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'idempotency_key' => $data['idempotency_key'],
                'method' => $data['method'], 'status' => $status,
                'currency' => $this->paymentCurrency($data['allocations'], $student->getKey(), $data['currency']),
                'amount' => BillingMoney::fromMinor($amountMinor),
                'unapplied_amount' => $status === 'confirmed' ? BillingMoney::fromMinor($amountMinor) : '0.00',
                'reference' => $data['reference'] === null ? null : trim($data['reference']),
                'receipt_number' => $status === 'confirmed' ? $this->receiptNumber() : null,
                'recorded_at' => now(), 'recorded_by' => $actor->getKey(),
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'confirmed_by' => $status === 'confirmed' ? $actor->getKey() : null,
            ]);

            $this->event($payment, $actor, 'payment.recorded', null, $status, $data['idempotency_key'], [
                'method' => $payment->method, 'amount' => (string) $payment->amount,
            ]);
            if ($status === 'confirmed') {
                $allocatedMinor = $this->applyAllocations($payment, $data['allocations'], $actor, $amountMinor, $data['idempotency_key']);
                $payment->forceFill(['unapplied_amount' => BillingMoney::fromMinor($amountMinor - $allocatedMinor)])->save();
                $this->event($payment, $actor, 'payment.confirmed', null, 'confirmed', $data['idempotency_key'].'.confirmed', [
                    'allocations' => $this->normalizedAllocations($data['allocations']),
                    'allocated_amount' => BillingMoney::fromMinor($allocatedMinor),
                    'unapplied_amount' => (string) $payment->unapplied_amount,
                ]);
                $this->audit->record('billing.payment.confirmed', $actor, (int) $membership->tenant_id, $payment, [
                    'payment_number' => $payment->payment_number, 'method' => $payment->method,
                    'amount' => (string) $payment->amount, 'allocated_amount' => BillingMoney::fromMinor($allocatedMinor),
                ]);
                $this->notifyStudentAndGuardians($payment, 'billing.payment.confirmed', 'A payment was confirmed.');
            } else {
                $this->audit->record('billing.payment.submitted_for_verification', $actor, (int) $membership->tenant_id, $payment, [
                    'payment_number' => $payment->payment_number, 'method' => $payment->method,
                    'amount' => (string) $payment->amount,
                ]);
                $this->notifyStudentAndGuardians($payment, 'billing.payment.pending_verification', 'A bank transfer was submitted for verification.');
            }

            return $payment->load(['student', 'allocations.invoice']);
        }, 3);
    }

    /**
     * @param  list<array{invoice_id:int,amount:string}>  $allocations
     */
    public function verifyBankTransfer(int $paymentId, array $allocations, string $requestKey, User $actor): BillingPayment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.payments.verify'), 403);
        $this->assertRequestKey($requestKey);

        return DB::transaction(function () use ($paymentId, $allocations, $requestKey, $actor, $membership): BillingPayment {
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $this->assertPaymentScope($payment, $membership);
            $replay = BillingPaymentEvent::query()->where('request_key', $requestKey)->where('payment_id', $paymentId)->first();
            $this->assertEventKeyAvailable($requestKey, $paymentId, 'payment.verification_approved');
            if ($replay !== null && $payment->status === 'confirmed') {
                $this->assertSameAllocations($replay, $allocations);

                return $payment->load(['student', 'allocations.invoice']);
            }
            if ($payment->status !== 'pending_verification' || $payment->method !== 'bank_transfer') {
                throw new ConflictHttpException('Only a pending bank transfer can be verified.');
            }
            if ((int) $payment->recorded_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['payment' => 'The collector cannot verify their own bank-transfer submission.']);
            }
            $amountMinor = BillingMoney::toMinor((string) $payment->amount);
            $payment->forceFill([
                'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $actor->getKey(),
                'receipt_number' => $this->receiptNumber(), 'unapplied_amount' => BillingMoney::fromMinor($amountMinor),
            ])->save();
            $allocatedMinor = $this->applyAllocations($payment, $allocations, $actor, $amountMinor, $requestKey);
            $payment->forceFill(['unapplied_amount' => BillingMoney::fromMinor($amountMinor - $allocatedMinor)])->save();
            $this->event($payment, $actor, 'payment.verification_approved', 'pending_verification', 'confirmed', $requestKey, [
                'allocations' => $this->normalizedAllocations($allocations),
                'allocated_amount' => BillingMoney::fromMinor($allocatedMinor),
                'unapplied_amount' => (string) $payment->unapplied_amount,
            ]);
            $this->audit->record('billing.payment.verification_approved', $actor, (int) $membership->tenant_id, $payment, [
                'payment_number' => $payment->payment_number, 'allocated_amount' => BillingMoney::fromMinor($allocatedMinor),
            ]);
            $this->notifyStudentAndGuardians($payment, 'billing.payment.confirmed', 'A bank transfer was verified and confirmed.');

            return $payment->load(['student', 'allocations.invoice']);
        }, 3);
    }

    /** @param list<array{invoice_id:int,amount:string}> $allocations */
    public function allocate(int $paymentId, array $allocations, string $requestKey, User $actor): BillingPayment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.payments.allocate'), 403);
        $this->assertRequestKey($requestKey);

        return DB::transaction(function () use ($paymentId, $allocations, $requestKey, $actor, $membership): BillingPayment {
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $this->assertPaymentScope($payment, $membership);
            $existingEvents = BillingPaymentEvent::query()->where('request_key', $requestKey)->where('payment_id', $paymentId)->first();
            $this->assertEventKeyAvailable($requestKey, $paymentId, 'payment.allocated');
            if ($existingEvents !== null && $existingEvents->event_type === 'payment.allocated') {
                $this->assertSameAllocations($existingEvents, $allocations);

                return $payment->load(['student', 'allocations.invoice']);
            }
            if ($payment->status !== 'confirmed') {
                throw new ConflictHttpException('Only confirmed payments can be allocated.');
            }
            $allocatedBefore = $this->netAllocatedMinor($payment);
            $available = BillingMoney::toMinor((string) $payment->unapplied_amount);
            $refunded = 0;
            foreach ($payment->refunds()->where('status', 'refunded')->get(['amount']) as $refund) {
                $refunded += BillingMoney::toMinor((string) $refund->amount);
            }
            if ($allocatedBefore + $available + $refunded !== BillingMoney::toMinor((string) $payment->amount)) {
                throw new LogicException('Payment allocations and unapplied balance do not reconcile.');
            }
            $allocatedNow = $this->applyAllocations($payment, $allocations, $actor, $available, $requestKey);
            $payment->forceFill(['unapplied_amount' => BillingMoney::fromMinor($available - $allocatedNow)])->save();
            $this->event($payment, $actor, 'payment.allocated', 'confirmed', 'confirmed', $requestKey, [
                'allocations' => $this->normalizedAllocations($allocations),
                'allocated_amount' => BillingMoney::fromMinor($allocatedNow),
                'unapplied_amount' => (string) $payment->unapplied_amount,
            ]);
            $this->audit->record('billing.payment.allocated', $actor, (int) $membership->tenant_id, $payment, [
                'payment_number' => $payment->payment_number, 'allocated_amount' => BillingMoney::fromMinor($allocatedNow),
            ]);

            return $payment->load(['student', 'allocations.invoice']);
        }, 3);
    }

    public function rejectBankTransfer(int $paymentId, string $reason, string $requestKey, User $actor): BillingPayment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.payments.verify'), 403);
        $this->assertRequestKey($requestKey);

        return DB::transaction(function () use ($paymentId, $reason, $requestKey, $actor, $membership): BillingPayment {
            $payment = BillingPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $this->assertPaymentScope($payment, $membership);
            $replay = BillingPaymentEvent::query()->where('request_key', $requestKey)->where('payment_id', $paymentId)->first();
            $this->assertEventKeyAvailable($requestKey, $paymentId, 'payment.verification_rejected');
            if ($replay !== null && $payment->status === 'rejected') {
                if (($replay->metadataArray()['reason'] ?? null) !== mb_substr(trim($reason), 0, 500)) {
                    throw ValidationException::withMessages(['request_key' => 'This request key is already bound to a different rejection reason.']);
                }

                return $payment->load(['student', 'allocations.invoice']);
            }
            if ($payment->status !== 'pending_verification' || $payment->method !== 'bank_transfer') {
                throw new ConflictHttpException('Only a pending bank transfer can be rejected.');
            }
            if ((int) $payment->recorded_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['payment' => 'The submitter cannot review their own bank-transfer submission.']);
            }
            $payment->forceFill(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->getKey(), 'rejection_reason' => trim($reason)])->save();
            $this->event($payment, $actor, 'payment.verification_rejected', 'pending_verification', 'rejected', $requestKey, ['reason' => mb_substr(trim($reason), 0, 500)]);
            $this->audit->record('billing.payment.verification_rejected', $actor, (int) $membership->tenant_id, $payment, ['reason' => mb_substr(trim($reason), 0, 500)]);
            $this->notifyStudentAndGuardians($payment, 'billing.payment.rejected', 'A bank transfer requires your attention.');

            return $payment->load(['student', 'allocations.invoice']);
        }, 3);
    }

    /** @param list<array{invoice_id:int,amount:string}> $allocations */
    private function applyAllocations(BillingPayment $payment, array $allocations, User $actor, int $availableMinor, string $requestKey): int
    {
        if ($allocations === []) {
            return 0;
        }
        $ids = array_map(static fn (array $row): int => (int) $row['invoice_id'], $allocations);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['allocations' => 'Select each invoice once per allocation request.']);
        }
        sort($ids);
        $invoices = BillingInvoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($invoices->count() !== count($ids)) {
            abort(404);
        }
        $requestedByInvoice = collect($allocations)->keyBy(static fn (array $row): int => (int) $row['invoice_id']);
        $requestedTotal = 0;
        foreach ($ids as $invoiceId) {
            $invoice = $invoices->get($invoiceId);
            $data = $requestedByInvoice->get($invoiceId);
            $minor = BillingMoney::toMinor((string) $data['amount']);
            if ($minor < 1) {
                throw ValidationException::withMessages(['allocations' => 'Allocation amounts must be greater than zero.']);
            }
            if ((int) $invoice->student_id !== (int) $payment->student_id
                || (int) $invoice->institution_id !== (int) $payment->institution_id
                || $invoice->campus_id !== $payment->campus_id
                || $invoice->currency !== $payment->currency) {
                abort(404);
            }
            if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['allocations' => 'Only open invoices can receive payment allocations.']);
            }
            $outstanding = BillingMoney::toMinor((string) $invoice->outstanding_amount);
            if ($minor > $outstanding) {
                throw ValidationException::withMessages(['allocations' => 'An allocation cannot exceed the invoice outstanding balance.']);
            }
            $requestedTotal += $minor;
            if ($requestedTotal > $availableMinor) {
                throw ValidationException::withMessages(['allocations' => 'Allocations cannot exceed the available confirmed payment amount.']);
            }

            $allocationKey = $this->allocationRequestKey($requestKey, $invoiceId);
            $existing = BillingPaymentAllocation::query()->where('request_key', $allocationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->payment_id !== (int) $payment->getKey() || BillingMoney::toMinor((string) $existing->amount) !== $minor) {
                    throw ValidationException::withMessages(['request_key' => 'The allocation request key is already bound to different allocation details.']);
                }
                throw new LogicException('An allocation request key already exists outside an idempotent operation replay.');
            }
            $paid = BillingMoney::toMinor((string) $invoice->paid_amount) + $minor;
            $remaining = $outstanding - $minor;
            $invoice->forceFill([
                'paid_amount' => BillingMoney::fromMinor($paid),
                'outstanding_amount' => BillingMoney::fromMinor($remaining),
                'status' => $remaining === 0 ? 'paid' : 'partially_paid',
            ])->save();
            $payment->allocations()->create([
                'invoice_id' => $invoice->getKey(), 'request_key' => $allocationKey,
                'amount' => BillingMoney::fromMinor($minor), 'allocated_by' => $actor->getKey(), 'allocated_at' => now(),
            ]);
        }

        return $requestedTotal;
    }

    /** @param array{student_id:int,method:string,amount:string,currency:string,reference:?string,idempotency_key:string,allocations:list<array{invoice_id:int,amount:string}>} $data */
    private function assertSamePaymentRequest(BillingPayment $payment, array $data, int $amountMinor): void
    {
        if ((int) $payment->student_id !== $data['student_id'] || $payment->method !== $data['method']
            || BillingMoney::toMinor((string) $payment->amount) !== $amountMinor
            || $payment->currency !== $data['currency']
            || $payment->reference !== ($data['reference'] === null ? null : trim($data['reference']))) {
            throw ValidationException::withMessages(['idempotency_key' => 'This key is already bound to a different payment request.']);
        }
        if ($data['method'] === 'cash') {
            $expected = $this->normalizedAllocations($data['allocations']);
            $actual = $payment->allocations()->get()->map(static fn (BillingPaymentAllocation $allocation): array => [
                'invoice_id' => (int) $allocation->invoice_id,
                'amount' => BillingMoney::fromMinor(BillingMoney::toMinor((string) $allocation->amount)),
            ])->all();
            usort($actual, static fn (array $left, array $right): int => $left['invoice_id'] <=> $right['invoice_id']);
            if ($actual !== $expected) {
                throw ValidationException::withMessages(['idempotency_key' => 'This key is already bound to different invoice allocations.']);
            }
            foreach ($data['allocations'] as $row) {
                $key = $this->allocationRequestKey($data['idempotency_key'], (int) $row['invoice_id']);
                $existing = BillingPaymentAllocation::query()->where('request_key', $key)->first();
                if ($existing === null || (int) $existing->payment_id !== (int) $payment->getKey()
                    || BillingMoney::toMinor((string) $existing->amount) !== BillingMoney::toMinor((string) $row['amount'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key is already bound to different invoice allocations.']);
                }
            }
        }
    }

    private function assertStudentScope(SisStudent $student, TenantMembership $membership): void
    {
        if ((int) $student->institution_id !== (int) $membership->institution_id) {
            abort(404);
        }
        if ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id) {
            abort(404);
        }
        if ($student->archived_at !== null || ! in_array($student->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true)) {
            throw ValidationException::withMessages(['student_id' => 'The learner is not eligible for payment activity.']);
        }
    }

    private function assertPaymentScope(BillingPayment $payment, TenantMembership $membership): void
    {
        if ((int) $payment->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $payment->campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
    }

    /** @param list<array{invoice_id:int,amount:string}> $allocations */
    private function paymentCurrency(array $allocations, int $studentId, string $currency): string
    {
        if ($allocations === []) {
            return $currency;
        }

        $invoices = BillingInvoice::query()->where('student_id', $studentId)->whereIn('id', array_column($allocations, 'invoice_id'))->get(['id', 'currency']);
        $currencies = $invoices->pluck('currency')->unique()->values();
        if ($invoices->count() !== count(array_unique(array_column($allocations, 'invoice_id')))) {
            abort(404);
        }
        if ($currencies->count() !== 1 || $currencies->first() !== $currency) {
            throw ValidationException::withMessages(['allocations' => 'All selected invoices must belong to the learner and use the payment currency.']);
        }

        return $currency;
    }

    /** @param list<array{invoice_id:int,amount:string}> $allocations */
    private function assertSameAllocations(BillingPaymentEvent $event, array $allocations): void
    {
        if (($event->metadataArray()['allocations'] ?? []) !== $this->normalizedAllocations($allocations)) {
            throw ValidationException::withMessages(['request_key' => 'This request key is already bound to different invoice allocations.']);
        }
    }

    /** @param list<array{invoice_id:int,amount:string}> $allocations
     * @return list<array{invoice_id:int,amount:string}>
     */
    private function normalizedAllocations(array $allocations): array
    {
        $normalized = array_map(static fn (array $row): array => [
            'invoice_id' => (int) $row['invoice_id'],
            'amount' => BillingMoney::fromMinor(BillingMoney::toMinor((string) $row['amount'])),
        ], $allocations);
        usort($normalized, static fn (array $left, array $right): int => $left['invoice_id'] <=> $right['invoice_id']);

        return $normalized;
    }

    /** @param array<string, mixed> $metadata */
    private function event(BillingPayment $payment, User $actor, string $type, ?string $from, ?string $to, ?string $requestKey, array $metadata): BillingPaymentEvent
    {
        return $payment->events()->create([
            'actor_user_id' => $actor->getKey(), 'event_type' => $type,
            'from_status' => $from, 'to_status' => $to, 'request_key' => $requestKey,
            'metadata' => $metadata, 'occurred_at' => now(),
        ]);
    }

    private function receiptNumber(): string
    {
        return 'REC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function allocatedMinor(BillingPayment $payment): int
    {
        $minor = 0;
        foreach ($payment->allocations()->get(['amount']) as $allocation) {
            $minor += BillingMoney::toMinor((string) $allocation->amount);
        }

        return $minor;
    }

    private function netAllocatedMinor(BillingPayment $payment): int
    {
        $allocated = $this->allocatedMinor($payment);
        $allocationIds = $payment->allocations()->pluck('id')->all();
        if ($allocationIds === []) {
            return 0;
        }
        foreach (BillingPaymentAllocation::query()->whereIn('id', $allocationIds)
            ->withSum(['refundAllocations as refunded_amount' => fn ($query) => $query->whereHas('refund', fn ($refund) => $refund->where('status', 'refunded'))], 'amount')
            ->get(['id']) as $allocation) {
            $refundedAmount = $allocation->getAttribute('refunded_amount');
            if ($refundedAmount !== null) {
                $allocated -= BillingMoney::toMinor((string) $refundedAmount);
            }
        }

        return $allocated;
    }

    private function allocationRequestKey(string $requestKey, int $invoiceId): string
    {
        return substr($requestKey, 0, 55).'.a.'.substr(hash('sha256', $requestKey), 0, 12).'.'.$invoiceId;
    }

    private function assertRequestKey(string $requestKey): void
    {
        if ($requestKey === '' || strlen($requestKey) > 80) {
            throw ValidationException::withMessages(['request_key' => 'The request key must contain 1 to 80 characters.']);
        }
    }

    private function assertEventKeyAvailable(string $requestKey, int $paymentId, string $expectedType): void
    {
        $existing = BillingPaymentEvent::query()->where('request_key', $requestKey)->first();
        if ($existing !== null && ((int) $existing->payment_id !== $paymentId || $existing->event_type !== $expectedType)) {
            throw ValidationException::withMessages(['request_key' => 'This request key is already bound to another financial operation.']);
        }
    }

    private function notifyStudentAndGuardians(BillingPayment $payment, string $event, string $message): void
    {
        $payment->loadMissing('student');
        $userIds = array_filter([(int) $payment->student->user_id]);
        $guardianIds = SisStudentGuardian::query()->where('student_id', $payment->student_id)->where('authority_status', 'verified')
            ->where('can_view_financial_records', true)->whereNull('revoked_at')->whereNotNull('guardian_user_id')
            ->pluck('guardian_user_id')->map(static fn ($id): int => (int) $id)->all();
        $userIds = array_values(array_unique([...$userIds, ...$guardianIds]));
        foreach (User::query()->whereIn('id', $userIds)->get() as $recipient) {
            $recipient->notify(new BillingPaymentStatusNotification($payment, $event, $message));
        }
    }
}
