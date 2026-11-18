<?php

namespace App\Domain\Billing;

use App\Models\BillingInstallmentPlan;
use App\Models\BillingInvoice;
use Illuminate\Support\Carbon;

/**
 * Derives installment settlement from the invoice's authoritative paid amount.
 * Collections apply in sequence order; a refund or reversal reduces the later
 * unpaid sequence first when the current allocation snapshot is recomputed.
 */
final class BillingInstallmentSchedule
{
    /** @return list<array{sequence:int,due_on:string,amount:string,paid_amount:string,outstanding_amount:string,status:string}> */
    public function lines(BillingInstallmentPlan $plan, ?BillingInvoice $invoice = null): array
    {
        $invoice ??= $plan->invoice;
        $remainingPaid = BillingMoney::toMinor((string) $invoice->paid_amount);
        $today = today()->toDateString();
        $lines = [];

        foreach ($plan->installments->filter(static fn ($installment): bool => $installment->status !== 'cancelled')->sortBy('sequence') as $installment) {
            $amountMinor = BillingMoney::toMinor((string) $installment->amount);
            $paidMinor = min($remainingPaid, $amountMinor);
            $remainingPaid -= $paidMinor;
            $outstandingMinor = $amountMinor - $paidMinor;
            $dueOn = Carbon::parse($installment->due_on)->toDateString();
            $status = $outstandingMinor === 0 ? 'paid' : ($dueOn < $today ? 'overdue' : ($paidMinor > 0 ? 'partially_paid' : 'scheduled'));

            $lines[] = [
                'sequence' => (int) $installment->sequence,
                'due_on' => $dueOn,
                'amount' => BillingMoney::fromMinor($amountMinor),
                'paid_amount' => BillingMoney::fromMinor($paidMinor),
                'outstanding_amount' => BillingMoney::fromMinor($outstandingMinor),
                'status' => $status,
            ];
        }

        return $lines;
    }
}
