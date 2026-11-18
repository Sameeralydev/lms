<?php

namespace App\Domain\Billing;

use App\Models\BillingInvoice;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;

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
     * @return array{invoice_count:int,invoiced_total:string,collected_total:string,outstanding_total:string,overdue_total:string}
     */
    public function summary(TenantMembership $membership, array $filters): array
    {
        $row = $this->query($membership, $filters)->toBase()->selectRaw(
            'COUNT(*) as invoice_count, '
            .'ROUND(COALESCE(SUM(CASE WHEN status <> ? THEN total ELSE 0 END), 0), 2) as invoiced_total, '
            .'ROUND(COALESCE(SUM(paid_amount), 0), 2) as collected_total, '
            .'ROUND(COALESCE(SUM(outstanding_amount), 0), 2) as outstanding_total, '
            .'ROUND(COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND due_on < ? AND status <> ? THEN outstanding_amount ELSE 0 END), 0), 2) as overdue_total',
            ['cancelled', now()->toDateString(), 'cancelled'],
        )->first();

        return [
            'invoice_count' => (int) $row->invoice_count,
            'invoiced_total' => self::decimal((string) $row->invoiced_total),
            'collected_total' => self::decimal((string) $row->collected_total),
            'outstanding_total' => self::decimal((string) $row->outstanding_total),
            'overdue_total' => self::decimal((string) $row->overdue_total),
        ];
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
