<?php

namespace App\Domain\Billing;

use App\Domain\Tenancy\TenantContext;
use App\Models\BillingAdjustment;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentAllocation;
use App\Models\BillingPaymentRefundAllocation;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\User;
use Illuminate\Support\Carbon;

final class BillingStudentLedger
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{student:array{id:int,number:string,name:string},currency_totals:list<array{currency:string,debit:string,credit:string,balance:string}>,entries:list<array<string,mixed>>} */
    public function statement(int $studentId, ?User $viewer = null): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission($viewer === null ? 'billing.ledger.view' : 'billing.ledger.view-own'), 403);
        $student = SisStudent::query()->findOrFail($studentId);
        if ((int) $student->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
        if ($viewer !== null) {
            $isLearner = (int) $student->user_id === (int) $viewer->getKey();
            $isAuthorizedGuardian = SisStudentGuardian::query()->where('student_id', $studentId)
                ->where('guardian_user_id', $viewer->getKey())->where('authority_status', 'verified')
                ->where('can_view_financial_records', true)->whereNull('revoked_at')->exists();
            abort_unless($isLearner || $isAuthorizedGuardian, 404);
        }

        $entries = collect();
        foreach (BillingInvoice::query()->where('student_id', $studentId)->where('status', '<>', 'cancelled')->get() as $invoice) {
            $entries->push($this->entry(Carbon::parse($invoice->issued_at)->toIso8601String(), 'invoice', $invoice->invoice_number, 'charge', (string) $invoice->total, '0.00', $invoice->currency, 'Invoice issued'));
        }
        $allocations = BillingPaymentAllocation::query()->with('invoice:id,student_id,currency', 'payment:id,payment_number')
            ->whereHas('invoice', fn ($query) => $query->where('student_id', $studentId))->orderBy('allocated_at')->limit(10000)->get();
        foreach ($allocations as $allocation) {
            $entries->push($this->entry(Carbon::parse($allocation->allocated_at)->toIso8601String(), 'payment', $allocation->payment->payment_number, 'payment_allocation', '0.00', (string) $allocation->amount, $allocation->invoice->currency, 'Payment allocated to invoice'));
        }
        $reversals = BillingPaymentRefundAllocation::query()->with('invoice:id,student_id,currency', 'refund:id,refund_number,status')
            ->whereHas('invoice', fn ($query) => $query->where('student_id', $studentId))
            ->whereHas('refund', fn ($query) => $query->where('status', 'refunded'))->orderBy('reversed_at')->limit(10000)->get();
        foreach ($reversals as $reversal) {
            $entries->push($this->entry(Carbon::parse($reversal->reversed_at)->toIso8601String(), 'refund', $reversal->refund->refund_number, 'allocation_reversal', (string) $reversal->amount, '0.00', $reversal->invoice->currency, 'Refund reversed an invoice payment allocation'));
        }
        $adjustments = BillingAdjustment::query()->with('invoice:id,student_id,currency')->whereIn('status', ['posted', 'reversed'])
            ->whereHas('invoice', fn ($query) => $query->where('student_id', $studentId))->orderBy('posted_at')->limit(10000)->get();
        foreach ($adjustments as $adjustment) {
            $credit = $adjustment->kind === 'credit';
            $entries->push($this->entry(Carbon::parse($adjustment->posted_at)->toIso8601String(), 'adjustment', $adjustment->adjustment_number,
                $credit ? 'credit_note' : 'debit_adjustment', $credit ? '0.00' : (string) $adjustment->amount,
                $credit ? (string) $adjustment->amount : '0.00', $adjustment->currency, $adjustment->reason));
        }
        foreach (BillingPayment::query()->where('student_id', $studentId)->where('status', 'confirmed')->where('unapplied_amount', '>', 0)->limit(10000)->get() as $payment) {
            $entries->push($this->entry($payment->confirmed_at->toIso8601String(), 'payment', $payment->payment_number, 'unapplied_credit', '0.00', '0.00', $payment->currency,
                'Unapplied payment credit: '.$payment->unapplied_amount));
        }
        $entries = $entries->sortBy([['date', 'asc'], ['reference', 'asc']])->values();
        $running = [];
        $totals = [];
        foreach ($entries as $index => $row) {
            $currency = $row['currency'];
            $running[$currency] ??= 0;
            $debit = BillingMoney::toMinor($row['debit']);
            $credit = BillingMoney::toMinor($row['credit']);
            $running[$currency] += $debit - $credit;
            $row['balance'] = $this->formatSignedMinor($running[$currency]);
            $entries[$index] = $row;
            $totals[$currency] ??= ['debit' => 0, 'credit' => 0];
            $totals[$currency]['debit'] += $debit;
            $totals[$currency]['credit'] += $credit;
        }

        return [
            'student' => ['id' => (int) $student->getKey(), 'number' => $student->student_number, 'name' => trim($student->legal_first_name.' '.$student->legal_last_name)],
            'currency_totals' => collect($totals)->map(fn (array $total, string $currency): array => [
                'currency' => $currency, 'debit' => BillingMoney::fromMinor($total['debit']), 'credit' => BillingMoney::fromMinor($total['credit']),
                'balance' => $this->formatSignedMinor($running[$currency]),
            ])->values()->all(),
            'entries' => $entries->all(),
        ];
    }

    /** @return array{date:string,source:string,reference:string,type:string,debit:string,credit:string,currency:string,description:string,balance:string} */
    private function entry(string $date, string $source, string $reference, string $type, string $debit, string $credit, string $currency, string $description): array
    {
        return ['date' => $date, 'source' => $source, 'reference' => $reference, 'type' => $type, 'debit' => $debit, 'credit' => $credit, 'currency' => $currency, 'description' => $description, 'balance' => '0.00'];
    }

    private function formatSignedMinor(int $minor): string
    {
        return $minor < 0 ? '-'.BillingMoney::fromMinor(abs($minor)) : BillingMoney::fromMinor($minor);
    }
}
