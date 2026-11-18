<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingInstallmentSchedule;
use App\Domain\Billing\BillingInvoiceIssuer;
use App\Domain\Billing\BillingInvoiceReport;
use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\BillingFeeHead;
use App\Models\BillingFeeStructure;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BillingController extends Controller
{
    public function index(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        $institution = Institution::query()->whereKey($membership->institution_id)->firstOrFail();
        $invoices = BillingInvoice::query()->with('student:id,student_number,legal_first_name,legal_last_name')
            ->where('institution_id', $institution->getKey())->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->latest('issued_at')->paginate(15)->withQueryString();
        $summary = DB::table('billing_invoices')->where('tenant_id', $membership->tenant_id)->where('institution_id', $institution->getKey())
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->whereIn('status', ['issued', 'partially_paid'])->selectRaw('currency, COUNT(*) as issued_count, COALESCE(SUM(outstanding_amount), 0) as outstanding_total')
            ->groupBy('currency')->orderBy('currency')->get();

        return Inertia::render('workspace/billing/index', [
            'tenant' => ['slug' => $tenant->slug],
            'institution' => ['name' => $institution->display_name],
            'summary' => [
                'issued_count' => (int) $summary->sum('issued_count'),
                'outstanding_totals' => $summary->map(static fn ($row): array => ['currency' => $row->currency, 'amount' => (string) $row->outstanding_total])->filter(static fn (array $row): bool => (float) $row['amount'] > 0)->values(),
            ],
            'fee_heads' => BillingFeeHead::query()->where('institution_id', $institution->getKey())->orderBy('name')->get(['id', 'code', 'name', 'category', 'status']),
            'structures' => BillingFeeStructure::query()->with('items:id,tenant_id,fee_structure_id,fee_head_id,unit_amount,quantity,sort_order')->withCount('items')->where('institution_id', $institution->getKey())->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))->orderByDesc('created_at')->get(['id', 'campus_id', 'code', 'name', 'currency', 'frequency', 'effective_from', 'effective_until', 'status'])->map(static fn (BillingFeeStructure $structure): array => [
                'id' => (int) $structure->getKey(), 'campus_id' => $structure->campus_id === null ? null : (int) $structure->campus_id,
                'code' => $structure->code, 'name' => $structure->name, 'currency' => $structure->currency,
                'frequency' => $structure->frequency, 'effective_from' => $structure->effective_from->toDateString(),
                'effective_until' => $structure->effective_until?->toDateString(), 'status' => $structure->status,
                'items_count' => (int) $structure->items_count,
                'items' => $structure->items->map(static fn ($item): array => ['fee_head_id' => (int) $item->fee_head_id, 'unit_amount' => (string) $item->unit_amount, 'quantity' => (string) $item->quantity])->all(),
            ]),
            'students' => SisStudent::query()->where('institution_id', $institution->getKey())->whereIn('status', ['pre_admitted', 'admitted', 'active', 'on_leave'])->whereNull('archived_at')->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name', 'home_campus_id']),
            'campuses' => Campus::query()->where('institution_id', $institution->getKey())->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))->orderBy('name')->get(['id', 'name']),
            'invoices' => $invoices->through(static fn (BillingInvoice $invoice): array => [
                'id' => (int) $invoice->getKey(), 'student_id' => (int) $invoice->student_id, 'invoice_number' => $invoice->invoice_number,
                'student_number' => $invoice->student->student_number,
                'student_name' => trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name),
                'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(),
                'due_on' => $invoice->due_on->toDateString(), 'currency' => $invoice->currency,
                'total' => (string) $invoice->total, 'outstanding_amount' => (string) $invoice->outstanding_amount, 'status' => $invoice->status,
            ]),
            'permissions' => [
                'manage_fees' => $membership->hasPermission('billing.fees.manage'),
                'issue_invoices' => $membership->hasPermission('billing.invoices.issue'),
                'cancel_invoices' => $membership->hasPermission('billing.invoices.cancel'),
                'view_reports' => $membership->hasPermission('billing.reports.view'),
                'view_payments' => $membership->hasPermission('billing.payments.view'),
                'view_lifecycle' => $membership->hasPermission('billing.assignments.manage')
                    || $membership->hasPermission('billing.concessions.manage')
                    || $membership->hasPermission('billing.installments.manage')
                    || $membership->hasPermission('billing.adjustments.manage')
                    || $membership->hasPermission('billing.ledger.view'),
            ],
        ]);
    }

    public function myInvoices(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.portal.view-own'), 403);
        $studentIds = SisStudent::query()->where('institution_id', $membership->institution_id)
            ->where('user_id', $request->user()->getKey())->whereNull('archived_at')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $guardianStudentIds = SisStudentGuardian::query()->where('guardian_user_id', $request->user()->getKey())
            ->where('authority_status', 'verified')->where('can_view_financial_records', true)->whereNull('revoked_at')
            ->whereHas('student', fn ($query) => $query->where('institution_id', $membership->institution_id)->whereNull('archived_at'))
            ->pluck('student_id')->map(static fn ($id): int => (int) $id)->all();
        $studentIds = array_values(array_unique([...$studentIds, ...$guardianStudentIds]));
        $invoices = BillingInvoice::query()->with('student:id,student_number,legal_first_name,legal_last_name')
            ->where('institution_id', $membership->institution_id)->whereIn('student_id', $studentIds)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->latest('issued_at')->paginate(15)->withQueryString();
        $payments = BillingPayment::query()->with(['allocations.invoice:id,invoice_number'])
            ->where('institution_id', $membership->institution_id)->whereIn('student_id', $studentIds)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->latest('recorded_at')->limit(50)->get()->map(static fn (BillingPayment $payment): array => [
                'id' => (int) $payment->getKey(), 'payment_number' => $payment->payment_number, 'receipt_number' => $payment->receipt_number,
                'status' => $payment->status, 'method' => $payment->method, 'currency' => $payment->currency, 'amount' => (string) $payment->amount,
                'recorded_at' => $payment->recorded_at->toDateString(), 'allocations' => $payment->allocations->map(static fn ($allocation): array => [
                    'invoice_number' => $allocation->invoice->invoice_number, 'amount' => (string) $allocation->amount,
                ])->all(),
            ]);

        return Inertia::render('workspace/billing/my-invoices', [
            'tenant' => ['slug' => $tenant->slug],
            'payments' => $payments,
            'invoices' => $invoices->through(static fn (BillingInvoice $invoice): array => [
                'id' => (int) $invoice->getKey(), 'invoice_number' => $invoice->invoice_number,
                'student_number' => $invoice->student->student_number,
                'student_name' => trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name),
                'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(),
                'due_on' => $invoice->due_on->toDateString(), 'currency' => $invoice->currency,
                'total' => (string) $invoice->total, 'outstanding_amount' => (string) $invoice->outstanding_amount, 'status' => $invoice->status,
            ]),
        ]);
    }

    public function reports(Tenant $tenant, Request $request, TenantContext $context, BillingInvoiceReport $reports): Response
    {
        abort_unless($context->membership()->hasPermission('billing.reports.view'), 403);
        $membership = $context->membership();
        $filters = $this->reportFilters($request, $membership);
        $invoices = $reports->query($membership, $filters)->latest('issued_at')->paginate(25)->withQueryString();

        return Inertia::render('workspace/billing/reports', [
            'tenant' => ['slug' => $tenant->slug],
            'filters' => $filters,
            'summary' => $reports->summary($membership, $filters),
            'collections' => $reports->confirmedCollections($membership, $filters),
            'aging' => $reports->agingSummary($membership, $filters),
            'installment_summary' => $reports->installmentSummary($membership, $filters),
            'campuses' => Campus::query()->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))
                ->orderBy('name')->get(['id', 'name']),
            'can_view_invoice' => $membership->hasPermission('billing.invoices.view'),
            'invoices' => $invoices->through(static fn (BillingInvoice $invoice): array => [
                'id' => (int) $invoice->getKey(), 'invoice_number' => $invoice->invoice_number,
                'student_number' => $invoice->student->student_number,
                'student_name' => trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name),
                'issued_at' => $invoice->issued_at->toDateString(), 'due_on' => $invoice->due_on->toDateString(),
                'currency' => $invoice->currency, 'total' => (string) $invoice->total,
                'outstanding_amount' => (string) $invoice->outstanding_amount, 'status' => $invoice->status,
            ]),
            'export_url' => route('tenant.billing.reports.csv', [$tenant, ...array_filter($filters, static fn ($value) => $value !== null && $value !== '')]),
        ]);
    }

    public function exportReports(Tenant $tenant, Request $request, TenantContext $context, BillingInvoiceReport $reports, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($context->membership()->hasPermission('billing.reports.view'), 403);
        $membership = $context->membership();
        $filters = $this->reportFilters($request, $membership);
        $query = $reports->query($membership, $filters)->latest('issued_at');
        $rowCount = (clone $query)->count();
        $audit->record('billing.invoice_report.exported', $request->user(), (int) $membership->tenant_id, null, [
            'row_count' => $rowCount, 'from' => $filters['from'], 'to' => $filters['to'],
            'status' => $filters['status'], 'campus_id' => $filters['campus_id'],
        ]);

        return response()->streamDownload(static function () use ($query): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Invoice', 'Student number', 'Student', 'Issued', 'Due date', 'Currency', 'Total', 'Outstanding', 'Status']);
            $query->chunkById(500, static function ($invoices) use ($output): void {
                foreach ($invoices as $invoice) {
                    fputcsv($output, [
                        BillingInvoiceReport::safeCsvCell((string) $invoice->invoice_number),
                        BillingInvoiceReport::safeCsvCell($invoice->student->student_number),
                        BillingInvoiceReport::safeCsvCell(trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name)),
                        $invoice->issued_at->toDateString(), $invoice->due_on->toDateString(), $invoice->currency,
                        (string) $invoice->total, (string) $invoice->outstanding_amount, $invoice->status,
                    ]);
                }
            }, 'id');
            fclose($output);
        }, 'billing-invoices.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function printReports(Tenant $tenant, Request $request, TenantContext $context, BillingInvoiceReport $reports, AuditRecorder $audit, AcademicRecordPdf $pdf): HttpResponse
    {
        abort_unless($context->membership()->hasPermission('billing.reports.view'), 403);
        $membership = $context->membership();
        $filters = $this->reportFilters($request, $membership);
        $query = $reports->query($membership, $filters)->latest('issued_at');
        $summary = $reports->summary($membership, $filters);
        $lines = [
            strtoupper((string) $membership->institution()->value('display_name')), 'BILLING INVOICE REGISTER',
            'Generated: '.now()->toDateTimeString(), 'Scope: institution '.$membership->institution_id.($filters['campus_id'] === null ? '' : ', campus '.$filters['campus_id']),
            'Filters: from '.($filters['from'] ?? 'any').' to '.($filters['to'] ?? 'any').'; status '.($filters['status'] ?? 'all').'; search '.($filters['search'] ?? 'none'),
            'Matching invoices: '.$summary['invoice_count'],
        ];
        foreach ($summary['currencies'] as $currency) {
            $lines[] = sprintf('%s invoiced %s | collected %s | outstanding %s | overdue %s', $currency['currency'], $currency['invoiced_total'], $currency['collected_total'], $currency['outstanding_total'], $currency['overdue_total']);
        }
        $lines[] = 'Confirmed collections (net of executed refunds; pending and reversed payments excluded)';
        foreach ($reports->confirmedCollections($membership, $filters) as $collection) {
            $lines[] = sprintf('%s %s: count %d, net %s', $collection['currency'], $collection['method'], $collection['payment_count'], $collection['net_confirmed_amount']);
        }
        $lines[] = 'Outstanding balance aging';
        foreach ($reports->agingSummary($membership, $filters) as $aging) {
            $lines[] = sprintf('%s: current %s | 1-30 %s | 31-60 %s | 61-90 %s | over 90 %s', $aging['currency'], $aging['current'], $aging['days_1_30'], $aging['days_31_60'], $aging['days_61_90'], $aging['days_over_90']);
        }
        $lines[] = 'Invoice | Student | Issued | Due | Currency | Total | Outstanding | Status';
        $query->limit(500)->get()->each(static function (BillingInvoice $invoice) use (&$lines): void {
            $lines[] = implode(' | ', [$invoice->invoice_number, $invoice->student->student_number, $invoice->issued_at->toDateString(), $invoice->due_on->toDateString(), $invoice->currency, (string) $invoice->total, (string) $invoice->outstanding_amount, $invoice->status]);
        });
        $audit->record('billing.invoice_report.pdf_exported', $request->user(), (int) $membership->tenant_id, null, [
            'row_limit' => min(500, (clone $query)->count()), 'from' => $filters['from'], 'to' => $filters['to'], 'status' => $filters['status'], 'campus_id' => $filters['campus_id'],
        ]);

        return response($pdf->render($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="billing-invoice-register.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{from:string|null,to:string|null,status:string|null,campus_id:int|null,search:string|null} */
    private function reportFilters(Request $request, TenantMembership $membership): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['issued', 'partially_paid', 'paid', 'overdue', 'cancelled'])],
            'campus_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))
                ->findOrFail($campusId);
        }

        return ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'status' => $data['status'] ?? null,
            'campus_id' => $campusId, 'search' => isset($data['search']) ? trim($data['search']) : null];
    }

    public function storeFeeHead(Tenant $tenant, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.fees.manage'), 403);
        $institutionId = (int) $context->membership()->institution_id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('billing_fee_heads', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'name' => ['required', 'string', 'max:140'], 'category' => ['required', Rule::in(['registration', 'admission', 'tuition', 'examination', 'certification', 'materials', 'transport', 'hostel', 'other'])],
        ]);
        $head = BillingFeeHead::query()->create(['institution_id' => $institutionId, ...$data, 'status' => 'active']);
        $audit->record('billing.fee_head.created', $request->user(), (int) $context->tenant()->getKey(), $head, ['code' => $head->code, 'category' => $head->category]);

        return back()->with('status', 'Fee head created.');
    }

    public function updateFeeHead(Tenant $tenant, int $headId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.fees.manage'), 403);
        $head = BillingFeeHead::query()->findOrFail($headId);
        $data = $request->validate(['name' => ['required', 'string', 'max:140'], 'status' => ['required', Rule::in(['active', 'inactive'])]]);
        $head->fill($data)->save();
        $audit->record('billing.fee_head.updated', $request->user(), (int) $context->tenant()->getKey(), $head, ['fields' => array_keys($data)]);

        return back()->with('status', 'Fee head updated. Existing invoices retain their issued snapshots.');
    }

    public function storeStructure(Tenant $tenant, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);
        $institutionId = (int) $membership->institution_id;
        $data = $request->validate([
            'campus_id' => ['nullable', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('billing_fee_structures', 'code')->where(fn ($query) => $query->where('tenant_id', $membership->tenant_id)->where('institution_id', $institutionId))],
            'name' => ['required', 'string', 'max:160'], 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'frequency' => ['required', Rule::in(['one_time', 'monthly', 'term', 'annual'])],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*.fee_head_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($membership->campus_id !== null && $campusId !== (int) $membership->campus_id) {
            abort(404);
        }
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $institutionId)->findOrFail($campusId);
        }
        $headIds = array_map(static fn (array $item): int => (int) $item['fee_head_id'], $data['items']);
        $heads = BillingFeeHead::query()->where('institution_id', $institutionId)->where('status', 'active')->whereIn('id', $headIds)->get()->keyBy('id');
        if ($heads->count() !== count(array_unique($headIds))) {
            abort(404);
        }
        $structure = DB::transaction(function () use ($data, $campusId, $institutionId, $membership, $request, $audit): BillingFeeStructure {
            $attributes = [
                'institution_id' => $institutionId, 'campus_id' => $campusId,
                'code' => strtoupper($data['code']), 'name' => trim($data['name']), 'currency' => $data['currency'],
                'frequency' => $data['frequency'], 'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null, 'status' => 'draft', 'created_by' => $request->user()->getKey(),
            ];
            $structure = new BillingFeeStructure;
            $structure->fill($attributes);
            $structure->save();
            foreach ($data['items'] as $index => $item) {
                $structure->items()->create(['fee_head_id' => $item['fee_head_id'], 'unit_amount' => $item['unit_amount'], 'quantity' => $item['quantity'], 'sort_order' => $index]);
            }
            $audit->record('billing.fee_structure.created', $request->user(), (int) $membership->tenant_id, $structure, ['code' => $structure->code, 'items' => count($data['items'])]);

            return $structure;
        });

        return back()->with('status', "Fee structure {$structure->code} saved as a draft.");
    }

    public function publishStructure(Tenant $tenant, int $structureId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);
        $structure = DB::transaction(function () use ($structureId, $request, $membership, $audit): BillingFeeStructure {
            $locked = BillingFeeStructure::query()->with('items.feeHead')->lockForUpdate()->findOrFail($structureId);
            if ($membership->campus_id !== null && (int) $locked->campus_id !== (int) $membership->campus_id) {
                abort(404);
            }
            if ($locked->status === 'active') {
                return $locked;
            }
            if ($locked->status !== 'draft' || $locked->items->isEmpty() || $locked->items->contains(fn ($item): bool => $item->feeHead?->status !== 'active')) {
                abort(409, 'Only a complete draft structure using active fee heads can be activated.');
            }
            $locked->forceFill(['status' => 'active', 'published_by' => $request->user()->getKey(), 'published_at' => now()])->save();
            $audit->record('billing.fee_structure.activated', $request->user(), (int) $membership->tenant_id, $locked, ['code' => $locked->code, 'effective_from' => $locked->effective_from->toDateString()]);

            return $locked;
        });

        return back()->with('status', "Fee structure {$structure->code} activated.");
    }

    public function updateDraftStructure(Tenant $tenant, int $structureId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);
        $data = $request->validate([
            'campus_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'frequency' => ['required', Rule::in(['one_time', 'monthly', 'term', 'annual'])],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*.fee_head_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $institutionId = (int) $membership->institution_id;
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($membership->campus_id !== null && $campusId !== (int) $membership->campus_id) {
            abort(404);
        }
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $institutionId)->findOrFail($campusId);
        }
        $headIds = array_map(static fn (array $item): int => (int) $item['fee_head_id'], $data['items']);
        $heads = BillingFeeHead::query()->where('institution_id', $institutionId)->where('status', 'active')->whereIn('id', $headIds)->get()->keyBy('id');
        if ($heads->count() !== count(array_unique($headIds))) {
            abort(404);
        }

        DB::transaction(function () use ($structureId, $data, $campusId, $institutionId, $membership, $request, $audit, $headIds): void {
            $structure = BillingFeeStructure::query()->lockForUpdate()->findOrFail($structureId);
            abort_unless((int) $structure->institution_id === $institutionId, 404);
            if ($membership->campus_id !== null && (int) $structure->campus_id !== (int) $membership->campus_id) {
                abort(404);
            }
            if ($structure->status !== 'draft') {
                abort(409, 'Activated fee structures are immutable; create a new draft version for future billing changes.');
            }
            $lockedHeads = BillingFeeHead::query()->where('institution_id', $institutionId)->where('status', 'active')->whereIn('id', $headIds)->lockForUpdate()->get();
            if ($lockedHeads->count() !== count(array_unique($headIds))) {
                abort(409, 'A selected fee head is no longer active. Refresh and review the draft lines.');
            }
            $structure->fill(['campus_id' => $campusId, 'name' => trim($data['name']), 'currency' => $data['currency'],
                'frequency' => $data['frequency'], 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null])->save();
            $structure->items()->delete();
            foreach ($data['items'] as $index => $item) {
                $structure->items()->create(['fee_head_id' => $item['fee_head_id'], 'unit_amount' => $item['unit_amount'], 'quantity' => $item['quantity'], 'sort_order' => $index]);
            }
            $audit->record('billing.fee_structure.draft_updated', $request->user(), (int) $membership->tenant_id, $structure, ['code' => $structure->code, 'items' => count($data['items'])]);
        }, 3);

        return back()->with('status', 'Draft fee structure updated. Issued invoice snapshots remain unchanged.');
    }

    public function issueInvoice(Tenant $tenant, Request $request, TenantContext $context, BillingInvoiceIssuer $issuer): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.invoices.issue'), 403);
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'], 'fee_structure_id' => ['required', 'integer', 'min:1'],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'due_on' => ['required', 'date', 'after_or_equal:period_start'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:80'],
            'fee_assignment_id' => ['nullable', 'integer', 'min:1'], 'concession_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $invoice = $issuer->issue(['student_id' => (int) $data['student_id'], 'fee_structure_id' => (int) $data['fee_structure_id'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'due_on' => $data['due_on'], 'idempotency_key' => $data['idempotency_key'], 'fee_assignment_id' => isset($data['fee_assignment_id']) ? (int) $data['fee_assignment_id'] : null, 'concession_id' => isset($data['concession_id']) ? (int) $data['concession_id'] : null], $request->user());

        return redirect()->route('tenant.billing.invoices.show', [$tenant, $invoice->getKey()])->with('status', "Invoice {$invoice->invoice_number} issued.");
    }

    public function showInvoice(Tenant $tenant, int $invoiceId, TenantContext $context): Response
    {
        abort_unless($context->membership()->hasPermission('billing.invoices.view'), 403);
        $invoice = BillingInvoice::query()->with(['items', 'student', 'feeStructure', 'installmentPlan.installments'])->findOrFail($invoiceId);
        $this->assertCampusScope($invoice->campus_id, $context);

        return Inertia::render('workspace/billing/invoice', [
            'tenant' => ['slug' => $tenant->slug],
            'invoice' => $this->invoiceViewData($invoice),
            'can_cancel' => $context->membership()->hasPermission('billing.invoices.cancel') && $invoice->status === 'issued',
            'print_url' => route('tenant.billing.invoices.print', [$tenant, $invoice->getKey()]),
            'back_url' => route('tenant.billing.index', $tenant),
        ]);
    }

    public function showMyInvoice(Tenant $tenant, int $invoiceId, Request $request, TenantContext $context): Response
    {
        abort_unless($context->membership()->hasPermission('billing.portal.view-own'), 403);
        $invoice = BillingInvoice::query()->with(['items', 'student', 'feeStructure', 'installmentPlan.installments'])->findOrFail($invoiceId);
        $this->assertPortalInvoiceAccess($invoice, $request->user()->getKey(), $context);

        return Inertia::render('workspace/billing/invoice', [
            'tenant' => ['slug' => $tenant->slug], 'invoice' => $this->invoiceViewData($invoice),
            'can_cancel' => false,
            'print_url' => route('tenant.billing.my.print', [$tenant, $invoice->getKey()]),
            'back_url' => route('tenant.billing.my', $tenant),
        ]);
    }

    public function printInvoice(Tenant $tenant, int $invoiceId, TenantContext $context, AcademicRecordPdf $pdf): HttpResponse
    {
        abort_unless($context->membership()->hasPermission('billing.invoices.view'), 403);
        $invoice = BillingInvoice::query()->with(['items', 'student', 'feeStructure'])->findOrFail($invoiceId);
        $this->assertCampusScope($invoice->campus_id, $context);
        $institution = Institution::query()->findOrFail($invoice->institution_id);
        $lines = [
            strtoupper($institution->display_name), 'FEE INVOICE', 'Invoice: '.$invoice->invoice_number,
            'Student: '.$invoice->student->student_number.' - '.$invoice->student->legal_first_name.' '.$invoice->student->legal_last_name,
            'Billing period: '.$invoice->period_start->toDateString().' to '.$invoice->period_end->toDateString(),
            'Due date: '.$invoice->due_on->toDateString(), 'Status: '.$invoice->status,
            '------------------------------------------------------------',
        ];
        foreach ($invoice->items as $item) {
            $lines[] = $item->fee_head_code.' '.$item->fee_head_name.' x'.$item->quantity.' @ '.$invoice->currency.' '.$item->unit_amount.' = '.$invoice->currency.' '.$item->line_total;
        }
        array_push($lines, 'Subtotal: '.$invoice->currency.' '.$invoice->subtotal, 'Discount: '.$invoice->currency.' '.$invoice->discount_total, 'Total: '.$invoice->currency.' '.$invoice->total, 'Paid: '.$invoice->currency.' '.$invoice->paid_amount, 'Outstanding: '.$invoice->currency.' '.$invoice->outstanding_amount, 'Generated: '.now()->toDateTimeString());

        return response($pdf->render($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function printMyInvoice(Tenant $tenant, int $invoiceId, Request $request, TenantContext $context, AcademicRecordPdf $pdf): HttpResponse
    {
        abort_unless($context->membership()->hasPermission('billing.portal.view-own'), 403);
        $invoice = BillingInvoice::query()->with(['items', 'student', 'feeStructure'])->findOrFail($invoiceId);
        $this->assertPortalInvoiceAccess($invoice, $request->user()->getKey(), $context);
        $institution = Institution::query()->findOrFail($invoice->institution_id);
        $lines = [strtoupper($institution->display_name), 'FEE INVOICE', 'Invoice: '.$invoice->invoice_number,
            'Student: '.$invoice->student->student_number.' - '.$invoice->student->legal_first_name.' '.$invoice->student->legal_last_name,
            'Billing period: '.$invoice->period_start->toDateString().' to '.$invoice->period_end->toDateString(),
            'Due date: '.$invoice->due_on->toDateString(), 'Status: '.$invoice->status];
        foreach ($invoice->items as $item) {
            $lines[] = $item->fee_head_code.' '.$item->fee_head_name.' x'.$item->quantity.' @ '.$invoice->currency.' '.$item->unit_amount.' = '.$invoice->currency.' '.$item->line_total;
        }
        array_push($lines, 'Total: '.$invoice->currency.' '.$invoice->total, 'Paid: '.$invoice->currency.' '.$invoice->paid_amount, 'Outstanding: '.$invoice->currency.' '.$invoice->outstanding_amount);

        return response($pdf->render($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function cancelInvoice(Tenant $tenant, int $invoiceId, Request $request, TenantContext $context, BillingInvoiceIssuer $issuer): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $invoice = BillingInvoice::query()->findOrFail($invoiceId);
        $this->assertCampusScope($invoice->campus_id, $context);
        $issuer->cancel($invoice, $request->user(), $data['reason']);

        return back()->with('status', 'Unpaid invoice cancelled; its issued snapshot was retained.');
    }

    private function assertCampusScope(?int $campusId, TenantContext $context): void
    {
        $membership = $context->membership();
        if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $campusId) {
            abort(404);
        }
    }

    private function assertPortalInvoiceAccess(BillingInvoice $invoice, int $userId, TenantContext $context): void
    {
        $membership = $context->membership();
        $ownStudent = SisStudent::query()->whereKey($invoice->student_id)->where('institution_id', $membership->institution_id)
            ->where('user_id', $userId)->whereNull('archived_at')->exists();
        $verifiedGuardian = SisStudentGuardian::query()->where('student_id', $invoice->student_id)->where('guardian_user_id', $userId)
            ->where('authority_status', 'verified')->where('can_view_financial_records', true)->whereNull('revoked_at')->exists();
        abort_unless($ownStudent || $verifiedGuardian, 404);
        $this->assertCampusScope($invoice->campus_id, $context);
    }

    /** @return array<string, mixed> */
    private function invoiceViewData(BillingInvoice $invoice): array
    {
        return [
            'id' => (int) $invoice->getKey(), 'invoice_number' => $invoice->invoice_number,
            'institution' => $invoice->feeStructure->institution->display_name,
            'campus' => $invoice->campus_id === null ? null : Campus::query()->find($invoice->campus_id)?->name,
            'student_number' => $invoice->student->student_number,
            'student_name' => trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name),
            'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(),
            'due_on' => $invoice->due_on->toDateString(), 'currency' => $invoice->currency,
            'subtotal' => (string) $invoice->subtotal, 'discount_total' => (string) $invoice->discount_total,
            'total' => (string) $invoice->total, 'paid_amount' => (string) $invoice->paid_amount,
            'outstanding_amount' => (string) $invoice->outstanding_amount, 'status' => $invoice->status,
            'installments' => $invoice->installmentPlan === null ? [] : app(BillingInstallmentSchedule::class)->lines($invoice->installmentPlan, $invoice),
            'issued_at' => $invoice->issued_at->toIso8601String(), 'items' => $invoice->items->map(static fn ($item): array => [
                'fee_head_code' => $item->fee_head_code, 'fee_head_name' => $item->fee_head_name,
                'quantity' => (int) $item->quantity, 'unit_amount' => (string) $item->unit_amount, 'line_total' => (string) $item->line_total,
            ])->all(),
        ];
    }
}
