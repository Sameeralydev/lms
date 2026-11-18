<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingInvoiceReport;
use App\Domain\Billing\BillingMoney;
use App\Domain\Billing\BillingPaymentRefundWorkflow;
use App\Domain\Billing\BillingPaymentWorkflow;
use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentRefund;
use App\Models\BillingPaymentReversal;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BillingPaymentController extends Controller
{
    public function index(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.payments.view'), 403);
        $payments = BillingPayment::query()->with(['student:id,student_number,legal_first_name,legal_last_name', 'allocations.invoice:id,invoice_number', 'refunds', 'reversals'])
            ->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('recorded_at')->paginate(20)->withQueryString();
        $summary = DB::table('billing_payments')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->selectRaw("currency, COUNT(*) as payment_count, COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) as confirmed_total, COALESCE(SUM(CASE WHEN status = 'pending_verification' THEN amount ELSE 0 END), 0) as pending_total")
            ->groupBy('currency')->orderBy('currency')->get();
        $paymentCount = (int) $summary->sum('payment_count');
        $refundTotals = DB::table('billing_payment_refunds as refunds')->join('billing_payments as payments', function ($join): void {
            $join->on('payments.id', '=', 'refunds.payment_id')->on('payments.tenant_id', '=', 'refunds.tenant_id');
        })->where('refunds.tenant_id', $membership->tenant_id)->where('payments.institution_id', $membership->institution_id)
            ->where('refunds.status', 'refunded')->when($membership->campus_id !== null, fn ($query) => $query->where('payments.campus_id', $membership->campus_id))
            ->selectRaw('refunds.currency, COALESCE(SUM(refunds.amount), 0) as refunded_total')->groupBy('refunds.currency')->orderBy('refunds.currency')->get();
        $canViewRefunds = $membership->hasPermission('billing.refunds.view');
        $students = $membership->hasPermission('billing.payments.collect')
            ? SisStudent::query()->where('institution_id', $membership->institution_id)->whereIn('status', ['pre_admitted', 'admitted', 'active', 'on_leave'])->whereNull('archived_at')
                ->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))
                ->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name'])
            : collect();
        $openInvoices = ($membership->hasPermission('billing.payments.collect') || $membership->hasPermission('billing.payments.verify') || $membership->hasPermission('billing.payments.allocate'))
            ? BillingInvoice::query()->with('student:id,student_number,legal_first_name,legal_last_name')->where('institution_id', $membership->institution_id)
                ->whereIn('status', ['issued', 'partially_paid'])->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
                ->orderBy('due_on')->limit(500)->get(['id', 'student_id', 'invoice_number', 'currency', 'outstanding_amount', 'due_on'])->map(static fn (BillingInvoice $invoice): array => [
                    'id' => (int) $invoice->getKey(), 'student_id' => (int) $invoice->student_id, 'invoice_number' => $invoice->invoice_number,
                    'student_number' => $invoice->student->student_number, 'currency' => $invoice->currency,
                    'outstanding_amount' => (string) $invoice->outstanding_amount, 'due_on' => $invoice->due_on->toDateString(),
                ])->all()
            : [];

        return Inertia::render('workspace/billing/payments', [
            'tenant' => ['slug' => $tenant->slug],
            'summary' => [
                'payment_count' => $paymentCount,
                'confirmed_totals' => $summary->map(static fn ($row): array => ['currency' => $row->currency, 'amount' => (string) $row->confirmed_total])->filter(static fn (array $row): bool => (float) $row['amount'] > 0)->values(),
                'pending_totals' => $summary->map(static fn ($row): array => ['currency' => $row->currency, 'amount' => (string) $row->pending_total])->filter(static fn (array $row): bool => (float) $row['amount'] > 0)->values(),
                'refunded_totals' => $refundTotals->map(static fn ($row): array => ['currency' => $row->currency, 'amount' => (string) $row->refunded_total])->filter(static fn (array $row): bool => (float) $row['amount'] > 0)->values(),
            ],
            'students' => $students,
            'open_invoices' => $openInvoices,
            'payments' => $payments->through(fn (BillingPayment $payment): array => [
                'id' => (int) $payment->getKey(), 'student_id' => (int) $payment->student_id, 'payment_number' => $payment->payment_number, 'student_number' => $payment->student->student_number,
                'student_name' => trim($payment->student->legal_first_name.' '.$payment->student->legal_last_name), 'method' => $payment->method,
                'status' => $payment->status, 'currency' => $payment->currency, 'amount' => (string) $payment->amount,
                'unapplied_amount' => (string) $payment->unapplied_amount, 'reference' => $payment->reference,
                'recorded_at' => $payment->recorded_at->toIso8601String(), 'receipt_number' => $payment->receipt_number,
                'allocations' => $payment->allocations->map(static fn ($allocation): array => ['invoice_number' => $allocation->invoice->invoice_number, 'amount' => (string) $allocation->amount])->all(),
                'refunds' => $canViewRefunds ? $payment->refunds->map(static fn ($refund): array => [
                    'id' => (int) $refund->getKey(), 'refund_number' => $refund->refund_number, 'amount' => (string) $refund->amount,
                    'currency' => $refund->currency, 'status' => $refund->status, 'reason' => $refund->reason,
                    'payout_reference' => $refund->payout_reference,
                ])->all() : [],
                'reversals' => $payment->reversals->map(static fn (BillingPaymentReversal $reversal): array => [
                    'id' => (int) $reversal->getKey(), 'reversal_number' => $reversal->reversal_number, 'status' => $reversal->status,
                    'reason' => $reversal->reason, 'review_note' => $reversal->review_note,
                ])->all(),
            ]),
            'permissions' => [
                'collect' => $membership->hasPermission('billing.payments.collect'), 'verify' => $membership->hasPermission('billing.payments.verify'),
                'allocate' => $membership->hasPermission('billing.payments.allocate'), 'request_refund' => $membership->hasPermission('billing.refunds.request'),
                'approve_refund' => $membership->hasPermission('billing.refunds.approve'), 'execute_refund' => $membership->hasPermission('billing.refunds.execute'),
                'view_refunds' => $canViewRefunds,
                'request_reversal' => $membership->hasPermission('billing.payments.reverse.request'),
                'approve_reversal' => $membership->hasPermission('billing.payments.reverse.approve'),
                'execute_reversal' => $membership->hasPermission('billing.payments.reverse.execute'),
            ],
            'filters' => ['status' => $request->string('status')->toString()],
        ]);
    }

    public function reconciliation(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.reports.view'), 403);
        $filters = $this->reconciliationFilters($request, $membership);
        $payments = $this->paymentPeriodQuery($membership, $filters);
        $refunds = $this->refundPeriodQuery($membership, $filters);
        $paymentTotals = DB::table('billing_payments')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->when($filters['campus_id'] !== null, fn ($query) => $query->where('campus_id', $filters['campus_id']))
            ->whereDate('recorded_at', '>=', $filters['from'])->whereDate('recorded_at', '<=', $filters['to'])
            ->selectRaw('method, status, currency, COUNT(*) as record_count, COALESCE(SUM(amount), 0) as amount_total, COALESCE(SUM(unapplied_amount), 0) as unapplied_total')
            ->groupBy('method', 'status', 'currency')->orderBy('currency')->orderBy('method')->orderBy('status')->get();
        $refundTotals = DB::table('billing_payment_refunds as refunds')->join('billing_payments as payments', function ($join): void {
            $join->on('payments.tenant_id', '=', 'refunds.tenant_id')->on('payments.id', '=', 'refunds.payment_id');
        })->where('refunds.tenant_id', $membership->tenant_id)->where('payments.institution_id', $membership->institution_id)
            ->where('refunds.status', 'refunded')->whereDate('refunds.executed_at', '>=', $filters['from'])->whereDate('refunds.executed_at', '<=', $filters['to'])
            ->when($membership->campus_id !== null, fn ($query) => $query->where('payments.campus_id', $membership->campus_id))
            ->when($filters['campus_id'] !== null, fn ($query) => $query->where('payments.campus_id', $filters['campus_id']))
            ->selectRaw('refunds.currency, COUNT(*) as refund_count, COALESCE(SUM(refunds.amount), 0) as amount_total')
            ->groupBy('refunds.currency')->orderBy('refunds.currency')->get();
        $paymentRows = (clone $payments)->with(['student:id,student_number,legal_first_name,legal_last_name', 'allocations.invoice:id,invoice_number'])
            ->latest('recorded_at')->paginate(25, ['*'], 'payments_page')->withQueryString();
        $refundRows = (clone $refunds)->with(['payment:id,payment_number,method,student_id', 'payment.student:id,student_number,legal_first_name,legal_last_name'])
            ->latest('executed_at')->paginate(25, ['*'], 'refunds_page')->withQueryString();

        return Inertia::render('workspace/billing/reconciliation', [
            'tenant' => ['slug' => $tenant->slug],
            'filters' => $filters,
            'campuses' => Campus::query()->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))
                ->orderBy('name')->get(['id', 'name']),
            'payment_totals' => $paymentTotals->map(static fn ($row): array => [
                'method' => $row->method, 'status' => $row->status, 'currency' => $row->currency,
                'record_count' => (int) $row->record_count, 'amount' => BillingMoney::normalizeDecimal((string) $row->amount_total),
                'unapplied_amount' => BillingMoney::normalizeDecimal((string) $row->unapplied_total),
            ])->all(),
            'refund_totals' => $refundTotals->map(static fn ($row): array => [
                'currency' => $row->currency, 'refund_count' => (int) $row->refund_count,
                'amount' => BillingMoney::normalizeDecimal((string) $row->amount_total),
            ])->all(),
            'payments' => $paymentRows->through(static fn (BillingPayment $payment): array => [
                'id' => (int) $payment->getKey(), 'payment_number' => $payment->payment_number,
                'student_number' => $payment->student->student_number,
                'student_name' => trim($payment->student->legal_first_name.' '.$payment->student->legal_last_name),
                'method' => $payment->method, 'status' => $payment->status, 'currency' => $payment->currency,
                'amount' => (string) $payment->amount, 'unapplied_amount' => (string) $payment->unapplied_amount,
                'recorded_at' => $payment->recorded_at->toIso8601String(),
                'allocations' => $payment->allocations->map(static fn ($allocation): array => [
                    'invoice_number' => $allocation->invoice->invoice_number, 'amount' => (string) $allocation->amount,
                ])->all(),
            ]),
            'refunds' => $refundRows->through(static fn (BillingPaymentRefund $refund): array => [
                'refund_number' => $refund->refund_number, 'payment_number' => $refund->payment->payment_number,
                'student_number' => $refund->payment->student->student_number, 'currency' => $refund->currency,
                'amount' => (string) $refund->amount, 'payout_reference' => $refund->payout_reference,
                'executed_at' => $refund->executedAtDateTime()?->toIso8601String(),
            ]),
            'export_url' => route('tenant.billing.reconciliation.csv', [$tenant, ...$filters]),
        ]);
    }

    public function exportReconciliation(Tenant $tenant, Request $request, TenantContext $context, AuditRecorder $audit): StreamedResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.reports.view'), 403);
        $filters = $this->reconciliationFilters($request, $membership);
        $payments = $this->paymentPeriodQuery($membership, $filters)->with('student:id,student_number,legal_first_name,legal_last_name')->orderBy('id');
        $refunds = $this->refundPeriodQuery($membership, $filters)->with('payment.student:id,student_number,legal_first_name,legal_last_name')->orderBy('id');
        $audit->record('billing.collection_reconciliation.exported', $request->user(), (int) $membership->tenant_id, null, [
            'from' => $filters['from'], 'to' => $filters['to'], 'campus_id' => $filters['campus_id'],
            'payment_count' => (clone $payments)->count(), 'refund_count' => (clone $refunds)->count(),
        ]);

        return response()->streamDownload(static function () use ($payments, $refunds): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Record type', 'Date', 'Reference', 'Payment', 'Student number', 'Student', 'Method', 'Status', 'Currency', 'Amount', 'Unapplied amount']);
            $payments->chunkById(500, static function ($rows) use ($output): void {
                foreach ($rows as $payment) {
                    fputcsv($output, ['payment', $payment->recorded_at->toIso8601String(), BillingInvoiceReport::safeCsvCell((string) ($payment->reference ?? '')),
                        BillingInvoiceReport::safeCsvCell($payment->payment_number), BillingInvoiceReport::safeCsvCell($payment->student->student_number),
                        BillingInvoiceReport::safeCsvCell(trim($payment->student->legal_first_name.' '.$payment->student->legal_last_name)),
                        $payment->method, $payment->status, $payment->currency, (string) $payment->amount, (string) $payment->unapplied_amount]);
                }
            });
            $refunds->chunkById(500, static function ($rows) use ($output): void {
                foreach ($rows as $refund) {
                    fputcsv($output, ['refund', $refund->executedAtDateTime()?->toIso8601String(), BillingInvoiceReport::safeCsvCell((string) $refund->payout_reference),
                        BillingInvoiceReport::safeCsvCell($refund->payment->payment_number), BillingInvoiceReport::safeCsvCell($refund->payment->student->student_number),
                        BillingInvoiceReport::safeCsvCell(trim($refund->payment->student->legal_first_name.' '.$refund->payment->student->legal_last_name)),
                        'manual_refund', $refund->status, $refund->currency, (string) $refund->amount, '0.00']);
                }
            });
            fclose($output);
        }, 'billing-collection-reconciliation.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{from:string,to:string,campus_id:int|null} */
    private function reconciliationFilters(Request $request, TenantMembership $membership): array
    {
        $today = now()->toDateString();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'campus_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today;
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            abort(422, 'Select a reconciliation period of no more than 367 days.');
        }
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))->findOrFail($campusId);
        }

        return ['from' => $from, 'to' => $to, 'campus_id' => $campusId];
    }

    /** @param array{from:string,to:string,campus_id:int|null} $filters
     * @return Builder<BillingPayment>
     */
    private function paymentPeriodQuery(TenantMembership $membership, array $filters): Builder
    {
        return BillingPayment::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->when($filters['campus_id'] !== null, fn ($query) => $query->where('campus_id', $filters['campus_id']))
            ->whereDate('recorded_at', '>=', $filters['from'])->whereDate('recorded_at', '<=', $filters['to']);
    }

    /** @param array{from:string,to:string,campus_id:int|null} $filters
     * @return Builder<BillingPaymentRefund>
     */
    private function refundPeriodQuery(TenantMembership $membership, array $filters): Builder
    {
        return BillingPaymentRefund::query()->where('tenant_id', $membership->tenant_id)->where('status', 'refunded')
            ->whereHas('payment', fn ($query) => $query->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($nested) => $nested->where('campus_id', $membership->campus_id))
                ->when($filters['campus_id'] !== null, fn ($nested) => $nested->where('campus_id', $filters['campus_id'])))
            ->whereDate('executed_at', '>=', $filters['from'])->whereDate('executed_at', '<=', $filters['to']);
    }

    public function store(Tenant $tenant, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.collect'), 403);
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer'])],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,bank_transfer'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'allocations' => ['nullable', 'array', 'max:20'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'min:1'],
            'allocations.*.amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
        ]);
        $payment = $workflow->record([...$data, 'reference' => isset($data['reference']) ? trim($data['reference']) : null, 'allocations' => $data['allocations'] ?? []], $request->user());

        $message = match ($payment->status) {
            'confirmed' => 'Payment recorded and receipt issued.',
            'reversed' => 'The matching payment already exists and has been reversed; no new collection was created.',
            'rejected' => 'The matching bank-transfer submission already exists and was rejected; no new collection was created.',
            default => 'Bank transfer recorded for independent verification.',
        };

        return redirect()->route('tenant.billing.payments.index', $tenant)->with('status', $message);
    }

    public function verify(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.verify'), 403);
        $data = $request->validate([
            'request_key' => ['required', 'string', 'max:80'],
            'allocations' => ['nullable', 'array', 'max:20'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'min:1'],
            'allocations.*.amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
        ]);
        $workflow->verifyBankTransfer($paymentId, $data['allocations'] ?? [], $data['request_key'], $request->user());

        return back()->with('status', 'Bank transfer verified, allocated and receipted.');
    }

    public function reject(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.verify'), 403);
        $data = $request->validate(['request_key' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        $workflow->rejectBankTransfer($paymentId, $data['reason'], $data['request_key'], $request->user());

        return back()->with('status', 'Bank transfer rejected with an immutable review record.');
    }

    public function allocate(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.allocate'), 403);
        $data = $request->validate([
            'request_key' => ['required', 'string', 'max:80'],
            'allocations' => ['required', 'array', 'min:1', 'max:20'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'min:1'],
            'allocations.*.amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
        ]);
        $workflow->allocate($paymentId, $data['allocations'], $data['request_key'], $request->user());

        return back()->with('status', 'Payment balance allocated to the selected invoices.');
    }

    public function requestReversal(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.reverse.request'), 403);
        $data = $request->validate(['request_key' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $workflow->requestReversal($paymentId, $data['reason'], $data['request_key'], $request->user());

        return back()->with('status', 'Payment reversal requested; settlement remains unchanged until independent approval and execution.');
    }

    public function reviewReversal(Tenant $tenant, int $reversalId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.reverse.approve'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $workflow->reviewReversal($reversalId, $data['decision'] === 'approve', $data['note'], $request->user());

        return back()->with('status', 'Payment reversal decision recorded.');
    }

    public function executeReversal(Tenant $tenant, int $reversalId, Request $request, TenantContext $context, BillingPaymentWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.payments.reverse.execute'), 403);
        $workflow->executeReversal($reversalId, $request->user());

        return back()->with('status', 'Approved payment reversal executed; invoice balances were restored transactionally.');
    }

    public function requestRefund(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, BillingPaymentRefundWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.refunds.request'), 403);
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'], 'request_key' => ['required', 'string', 'max:80'],
        ]);
        $workflow->request($paymentId, $data['amount'], $data['reason'], $data['request_key'], $request->user());

        return back()->with('status', 'Refund request submitted for independent approval.');
    }

    public function approveRefund(Tenant $tenant, int $refundId, Request $request, TenantContext $context, BillingPaymentRefundWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.refunds.approve'), 403);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $workflow->approve($refundId, $data['note'], $request->user());

        return back()->with('status', 'Refund request approved; payout execution is still a separate step.');
    }

    public function rejectRefund(Tenant $tenant, int $refundId, Request $request, TenantContext $context, BillingPaymentRefundWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.refunds.approve'), 403);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $workflow->reject($refundId, $data['note'], $request->user());

        return back()->with('status', 'Refund request rejected with an immutable event record.');
    }

    public function executeRefund(Tenant $tenant, int $refundId, Request $request, TenantContext $context, BillingPaymentRefundWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('billing.refunds.execute'), 403);
        $data = $request->validate(['payout_reference' => ['required', 'string', 'max:120']]);
        $workflow->execute($refundId, $data['payout_reference'], $request->user());

        return back()->with('status', 'Manual refund execution recorded and related invoice balances adjusted transactionally.');
    }

    public function receipt(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, AuditRecorder $audit, AcademicRecordPdf $pdf): HttpResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.receipts.view'), 403);
        $payment = BillingPayment::query()->with(['student', 'recordedBy', 'confirmedBy', 'allocations.invoice', 'events'])->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))->findOrFail($paymentId);
        abort_unless(in_array($payment->status, ['confirmed', 'reversed'], true) && $payment->receipt_number !== null, 404);
        $audit->record('billing.payment_receipt.viewed', $request->user(), (int) $membership->tenant_id, $payment, ['receipt_number' => $payment->receipt_number]);

        return $this->receiptResponse($payment, $pdf);
    }

    public function myReceipt(Tenant $tenant, int $paymentId, Request $request, TenantContext $context, AcademicRecordPdf $pdf): HttpResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.portal.view-own'), 403);
        $studentIds = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $request->user()->getKey())
            ->whereNull('archived_at')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $guardianStudentIds = SisStudentGuardian::query()->where('guardian_user_id', $request->user()->getKey())->where('authority_status', 'verified')
            ->where('can_view_financial_records', true)->whereNull('revoked_at')->whereHas('student', fn ($query) => $query->where('institution_id', $membership->institution_id)->whereNull('archived_at'))
            ->pluck('student_id')->map(static fn ($id): int => (int) $id)->all();
        $payment = BillingPayment::query()->with(['student', 'recordedBy', 'confirmedBy', 'allocations.invoice'])->where('institution_id', $membership->institution_id)
            ->whereIn('student_id', array_values(array_unique([...$studentIds, ...$guardianStudentIds])))
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))->findOrFail($paymentId);
        abort_unless(in_array($payment->status, ['confirmed', 'reversed'], true) && $payment->receipt_number !== null, 404);

        return $this->receiptResponse($payment, $pdf);
    }

    private function receiptResponse(BillingPayment $payment, AcademicRecordPdf $pdf): HttpResponse
    {
        $institution = Institution::query()->findOrFail($payment->institution_id);
        $campus = $payment->campus_id === null ? null : Campus::query()->find($payment->campus_id);
        $confirmation = $payment->events()->whereIn('event_type', ['payment.confirmed', 'payment.verification_approved'])->orderBy('id')->first();
        $confirmationMetadata = $confirmation?->metadataArray() ?? [];
        $receiptAllocations = $confirmationMetadata['allocations'] ?? $payment->allocations->map(static fn ($allocation): array => [
            'invoice_id' => (int) $allocation->invoice_id, 'amount' => (string) $allocation->amount,
        ])->all();
        $lines = [
            $institution->display_name,
            $campus === null ? 'Institution-wide collection' : $campus->name,
            'PAYMENT RECEIPT',
            'Status: '.($payment->status === 'reversed' ? 'VOIDED — payment reversal completed' : 'Confirmed'),
            'Receipt: '.$payment->receipt_number,
            'Payment: '.$payment->payment_number,
            'Student: '.$payment->student->student_number.' - '.trim($payment->student->legal_first_name.' '.$payment->student->legal_last_name),
            'Recorded: '.$payment->recorded_at->format('Y-m-d H:i:s T'),
            'Collected by: '.$payment->recordedBy->name,
            'Verified by: '.($payment->confirmedBy === null ? 'Not applicable' : $payment->confirmedBy->name),
            'Method: '.str_replace('_', ' ', $payment->method),
            'Reference: '.($payment->reference ?: 'Not supplied'),
            'Confirmed amount: '.$payment->currency.' '.$payment->amount,
            'Invoice allocations:',
        ];
        foreach ($receiptAllocations as $allocation) {
            $invoice = $payment->allocations->firstWhere('invoice_id', (int) $allocation['invoice_id'])?->invoice;
            if ($invoice !== null) {
                $lines[] = $invoice->invoice_number.' - '.$payment->currency.' '.$allocation['amount'];
            }
        }
        $unappliedAtIssue = $confirmationMetadata['unapplied_amount'] ?? $payment->unapplied_amount;
        $lines[] = 'Unapplied balance at receipt issue: '.$payment->currency.' '.$unappliedAtIssue;

        return response($pdf->render($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$payment->receipt_number.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
