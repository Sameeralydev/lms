<?php

namespace App\Domain\Billing;

use App\Models\BillingInstallmentPlan;
use App\Models\BillingInvoice;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Tenant and campus scoped invoice reporting shared by the UI and export. */
final class BillingInvoiceReport
{
    /**
     * @param  array{from?:string|null,to?:string|null,status?:string|null,campus_id?:int|null,search?:string|null}  $filters
     * @return Builder<BillingInvoice>
     */
    public function query(TenantMembership $membership, array $filters): Builder
    {
        return BillingInvoice::query()->with('student:id,student_number,legal_first_name,legal_last_name')
            ->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn (Builder $query) => $query->where('campus_id', $membership->campus_id))
            ->when(($filters['campus_id'] ?? null) !== null, fn (Builder $query) => $query->where('campus_id', $filters['campus_id']))
            ->when(($filters['from'] ?? null) !== null, fn (Builder $query) => $query->whereDate('issued_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn (Builder $query) => $query->whereDate('issued_at', '<=', $filters['to']))
            ->when(($filters['status'] ?? null) !== null, function (Builder $query) use ($filters): void {
                if ($filters['status'] === 'overdue') {
                    $query->where('outstanding_amount', '>', 0)->whereDate('due_on', '<', now()->toDateString())->where('status', '<>', 'cancelled');
                } else {
                    $query->where('status', $filters['status']);
                }
            })
            ->when(($filters['search'] ?? null) !== null, function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('invoice_number', 'like', '%'.$search.'%')
                        ->orWhereHas('student', fn (Builder $student) => $student->where('student_number', 'like', '%'.$search.'%')
                            ->orWhere('legal_first_name', 'like', '%'.$search.'%')->orWhere('legal_last_name', 'like', '%'.$search.'%'));
                });
            });
    }

    /** @param array{from?:string|null,to?:string|null,status?:string|null,campus_id?:int|null,search?:string|null} $filters
     * @return array{invoice_count:int,currencies:list<array{currency:string,invoiced_total:string,collected_total:string,outstanding_total:string,overdue_total:string}>}
     */
    public function summary(TenantMembership $membership, array $filters): array
    {
        $rows = $this->query($membership, $filters)->toBase()->selectRaw(
            'currency, COUNT(*) as invoice_count, '
            .'ROUND(COALESCE(SUM(CASE WHEN status <> ? THEN total ELSE 0 END), 0), 2) as invoiced_total, '
            .'ROUND(COALESCE(SUM(paid_amount), 0), 2) as collected_total, '
            .'ROUND(COALESCE(SUM(outstanding_amount), 0), 2) as outstanding_total, '
            .'ROUND(COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND due_on < ? AND status <> ? THEN outstanding_amount ELSE 0 END), 0), 2) as overdue_total',
            ['cancelled', now()->toDateString(), 'cancelled'],
        )->groupBy('currency')->orderBy('currency')->get();

        return [
            'invoice_count' => (int) $rows->sum('invoice_count'),
            'currencies' => $rows->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'invoiced_total' => self::decimal((string) $row->invoiced_total),
                'collected_total' => self::decimal((string) $row->collected_total),
                'outstanding_total' => self::decimal((string) $row->outstanding_total),
                'overdue_total' => self::decimal((string) $row->overdue_total),
            ])->all(),
        ];
    }

    /** @param array{from?:string|null,to?:string|null,status?:string|null,campus_id?:int|null,search?:string|null} $filters
     * @return list<array{currency:string,method:string,payment_count:int,net_confirmed_amount:string}>
     */
    public function confirmedCollections(TenantMembership $membership, array $filters): array
    {
        $query = DB::table('billing_payments as payments')
            ->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.tenant_id', '=', 'payments.tenant_id')
                    ->on('students.institution_id', '=', 'payments.institution_id');
            })
            ->where('payments.tenant_id', $membership->tenant_id)
            ->where('payments.institution_id', $membership->institution_id)
            ->where('payments.status', 'confirmed')
            ->whereNotNull('payments.confirmed_at')
            ->when($membership->campus_id !== null, fn ($builder) => $builder->where('payments.campus_id', $membership->campus_id))
            ->when(($filters['campus_id'] ?? null) !== null, fn ($builder) => $builder->where('payments.campus_id', $filters['campus_id']))
            ->when(($filters['from'] ?? null) !== null, fn ($builder) => $builder->whereDate('payments.confirmed_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($builder) => $builder->whereDate('payments.confirmed_at', '<=', $filters['to']));

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function ($nested) use ($search): void {
                $nested->where('payments.payment_number', 'like', '%'.$search.'%')
                    ->orWhere('students.student_number', 'like', '%'.$search.'%')
                    ->orWhere('students.legal_first_name', 'like', '%'.$search.'%')
                    ->orWhere('students.legal_last_name', 'like', '%'.$search.'%');
            });
        }

        return $query->selectRaw(
            'payments.currency, payments.method, COUNT(*) as payment_count, '
            .'ROUND(SUM(payments.amount - COALESCE((SELECT SUM(refunds.amount) FROM billing_payment_refunds as refunds '
            .'WHERE refunds.tenant_id = payments.tenant_id AND refunds.payment_id = payments.id AND refunds.status = ?), 0)), 2) as net_confirmed_amount',
            ['refunded'],
        )->groupBy('payments.currency', 'payments.method')->orderBy('payments.currency')->orderBy('payments.method')->get()
            ->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'method' => (string) $row->method,
                'payment_count' => (int) $row->payment_count,
                'net_confirmed_amount' => self::decimal((string) $row->net_confirmed_amount),
            ])->all();
    }

    /** @param array{from?:string|null,to?:string|null,status?:string|null,campus_id?:int|null,search?:string|null} $filters
     * @return list<array{currency:string,current:string,days_1_30:string,days_31_60:string,days_61_90:string,days_over_90:string}>
     */
    public function agingSummary(TenantMembership $membership, array $filters): array
    {
        $totals = [];
        $this->query($membership, [...$filters, 'status' => null])->where('status', '<>', 'cancelled')->where('outstanding_amount', '>', 0)
            ->orderBy('id')->chunkById(500, function ($invoices) use (&$totals): void {
                foreach ($invoices as $invoice) {
                    $currency = (string) $invoice->currency;
                    $totals[$currency] ??= ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'days_over_90' => 0];
                    $due = Carbon::parse($invoice->due_on)->startOfDay();
                    $age = $due->lessThan(now()->startOfDay()) ? (int) $due->diffInDays(now()->startOfDay()) : -1;
                    $bucket = $age < 0 ? 'current' : ($age <= 30 ? 'days_1_30' : ($age <= 60 ? 'days_31_60' : ($age <= 90 ? 'days_61_90' : 'days_over_90')));
                    $totals[$currency][$bucket] += BillingMoney::toMinor((string) $invoice->outstanding_amount);
                }
            });

        return collect($totals)->map(static fn (array $amounts, string $currency): array => [
            'currency' => $currency, 'current' => BillingMoney::fromMinor($amounts['current']),
            'days_1_30' => BillingMoney::fromMinor($amounts['days_1_30']), 'days_31_60' => BillingMoney::fromMinor($amounts['days_31_60']),
            'days_61_90' => BillingMoney::fromMinor($amounts['days_61_90']), 'days_over_90' => BillingMoney::fromMinor($amounts['days_over_90']),
        ])->values()->all();
    }

    /** @param array{from?:string|null,to?:string|null,status?:string|null,campus_id?:int|null,search?:string|null} $filters
     * @return list<array{currency:string,installment_count:int,scheduled_total:string,paid_total:string,outstanding_total:string,overdue_total:string}>
     */
    public function installmentSummary(TenantMembership $membership, array $filters): array
    {
        $invoiceIds = $this->query($membership, [...$filters, 'status' => null])->select('billing_invoices.id');
        $totals = [];
        BillingInstallmentPlan::query()->with(['invoice', 'installments'])->where('status', 'published')->whereIn('invoice_id', $invoiceIds)
            ->orderBy('id')->chunkById(200, function ($plans) use (&$totals): void {
                $schedule = app(BillingInstallmentSchedule::class);
                foreach ($plans as $plan) {
                    $currency = (string) $plan->invoice->currency;
                    $totals[$currency] ??= ['installment_count' => 0, 'scheduled' => 0, 'paid' => 0, 'outstanding' => 0, 'overdue' => 0];
                    foreach ($schedule->lines($plan, $plan->invoice) as $line) {
                        $totals[$currency]['installment_count']++;
                        $totals[$currency]['scheduled'] += BillingMoney::toMinor($line['amount']);
                        $totals[$currency]['paid'] += BillingMoney::toMinor($line['paid_amount']);
                        $totals[$currency]['outstanding'] += BillingMoney::toMinor($line['outstanding_amount']);
                        if ($line['status'] === 'overdue') {
                            $totals[$currency]['overdue'] += BillingMoney::toMinor($line['outstanding_amount']);
                        }
                    }
                }
            });

        ksort($totals);

        return collect($totals)->map(static fn (array $row, string $currency): array => [
            'currency' => $currency, 'installment_count' => $row['installment_count'],
            'scheduled_total' => BillingMoney::fromMinor($row['scheduled']), 'paid_total' => BillingMoney::fromMinor($row['paid']),
            'outstanding_total' => BillingMoney::fromMinor($row['outstanding']), 'overdue_total' => BillingMoney::fromMinor($row['overdue']),
        ])->values()->all();
    }

    private static function decimal(string $value): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            return '0.00';
        }

        $whole = ltrim($matches[1], '0') ?: '0';

        return $whole.'.'.str_pad(substr($matches[2] ?? '', 0, 2), 2, '0');
    }

    public static function safeCsvCell(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
