<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingInvoiceIssuer;
use App\Domain\Billing\BillingInvoiceReport;
use App\Domain\Billing\BillingLifecycleService;
use App\Domain\Billing\BillingStudentLedger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\BillingAdjustment;
use App\Models\BillingConcession;
use App\Models\BillingFeeAssignment;
use App\Models\BillingInstallment;
use App\Models\BillingInstallmentPlan;
use App\Models\BillingInvoice;
use App\Models\BillingRecurringPlan;
use App\Models\Campus;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BillingLifecycleController extends Controller
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage') || $membership->hasPermission('billing.concessions.manage')
            || $membership->hasPermission('billing.installments.manage') || $membership->hasPermission('billing.adjustments.manage')
            || $membership->hasPermission('billing.ledger.view'), 403);
        $canManageStudents = $membership->hasPermission('billing.assignments.manage') || $membership->hasPermission('billing.concessions.manage') || $membership->hasPermission('billing.invoices.issue');
        $canBrowseInvoices = $membership->hasPermission('billing.invoices.view') || $membership->hasPermission('billing.invoices.issue');
        $scope = fn ($query) => $query->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($q) => $q->where('campus_id', $membership->campus_id));

        return Inertia::render('workspace/billing/lifecycle', [
            'tenant' => ['slug' => $tenant->slug],
            'students' => SisStudent::query()->when(! $canManageStudents, fn ($q) => $q->whereRaw('1 = 0'))->where('institution_id', $membership->institution_id)->whereIn('status', ['pre_admitted', 'admitted', 'active', 'on_leave'])->whereNull('archived_at')
                ->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']),
            'campuses' => Campus::query()->when(! $canManageStudents, fn ($q) => $q->whereRaw('1 = 0'))->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))->orderBy('name')->get(['id', 'name']),
            'structures' => DB::table('billing_fee_structures')->when(! $canManageStudents && ! $membership->hasPermission('billing.fees.manage'), fn ($q) => $q->whereRaw('1 = 0'))->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('status', 'active')
                ->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
                ->orderBy('name')->get(['id', 'name', 'currency', 'frequency', 'effective_from', 'effective_until']),
            'invoices' => BillingInvoice::query()->when(! $canBrowseInvoices, fn ($q) => $q->whereRaw('1 = 0'))->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($q) => $q->where('campus_id', $membership->campus_id))
                ->whereNotIn('status', ['cancelled'])->with('student:id,student_number,legal_first_name,legal_last_name')->latest('issued_at')->limit(200)->get()
                ->map(static fn (BillingInvoice $invoice): array => ['id' => (int) $invoice->getKey(), 'number' => $invoice->invoice_number, 'student_id' => (int) $invoice->student_id, 'student' => $invoice->student->student_number.' · '.trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name), 'currency' => $invoice->currency, 'amount_due' => (string) $invoice->adjusted_total, 'outstanding' => (string) $invoice->outstanding_amount, 'status' => $invoice->status])->all(),
            'assignments' => $scope(BillingFeeAssignment::query()->when(! $membership->hasPermission('billing.assignments.manage'), fn ($q) => $q->whereRaw('1 = 0')))->with(['student:id,student_number,legal_first_name,legal_last_name', 'feeStructure:id,code,name'])->latest()->limit(100)->get()
                ->map(static fn (BillingFeeAssignment $row): array => ['id' => (int) $row->getKey(), 'student' => $row->student->student_number.' · '.trim($row->student->legal_first_name.' '.$row->student->legal_last_name), 'structure' => $row->feeStructure->name, 'period_start' => Carbon::parse($row->period_start)->toDateString(), 'period_end' => Carbon::parse($row->period_end)->toDateString(), 'source' => $row->source, 'status' => $row->status])->all(),
            'concessions' => $scope(BillingConcession::query()->when(! $membership->hasPermission('billing.concessions.manage') && ! $membership->hasPermission('billing.concessions.approve'), fn ($q) => $q->whereRaw('1 = 0')))->with('student:id,student_number,legal_first_name,legal_last_name')->latest()->limit(100)->get()
                ->map(static fn (BillingConcession $row): array => ['id' => (int) $row->getKey(), 'student' => $row->student->student_number.' · '.trim($row->student->legal_first_name.' '.$row->student->legal_last_name), 'code' => $row->code, 'name' => $row->name, 'kind' => $row->kind, 'value' => (string) $row->value, 'status' => $row->status, 'requested_by' => (int) $row->requested_by])->all(),
            'installment_plans' => BillingInstallmentPlan::query()->when(! $membership->hasPermission('billing.installments.manage'), fn ($q) => $q->whereRaw('1 = 0'))->where('tenant_id', $membership->tenant_id)->whereHas('invoice', fn ($q) => $q->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($nested) => $nested->where('campus_id', $membership->campus_id)))
                ->with(['invoice:id,invoice_number,student_id', 'installments'])->latest()->limit(100)->get()->map(static fn (BillingInstallmentPlan $row): array => ['id' => (int) $row->getKey(), 'invoice' => $row->invoice->invoice_number, 'status' => $row->status, 'installments' => $row->installments->map(static fn (BillingInstallment $item): array => ['sequence' => $item->sequence, 'due_on' => Carbon::parse($item->due_on)->toDateString(), 'amount' => (string) $item->amount])->all()])->all(),
            'adjustments' => BillingAdjustment::query()->when(! $membership->hasPermission('billing.adjustments.manage') && ! $membership->hasPermission('billing.adjustments.approve') && ! $membership->hasPermission('billing.adjustments.post'), fn ($q) => $q->whereRaw('1 = 0'))->where('tenant_id', $membership->tenant_id)->whereHas('invoice', fn ($q) => $q->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($nested) => $nested->where('campus_id', $membership->campus_id)))
                ->with('invoice:id,invoice_number')->latest()->limit(100)->get()->map(static fn (BillingAdjustment $row): array => ['id' => (int) $row->getKey(), 'number' => $row->adjustment_number, 'invoice' => $row->invoice->invoice_number, 'kind' => $row->kind, 'amount' => (string) $row->amount, 'status' => $row->status, 'requested_by' => (int) $row->requested_by, 'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by])->all(),
            'permissions' => [
                'assign' => $membership->hasPermission('billing.assignments.manage'), 'concession_request' => $membership->hasPermission('billing.concessions.manage'),
                'concession_review' => $membership->hasPermission('billing.concessions.approve'), 'installments' => $membership->hasPermission('billing.installments.manage'),
                'adjustment_request' => $membership->hasPermission('billing.adjustments.manage'), 'adjustment_review' => $membership->hasPermission('billing.adjustments.approve'),
                'adjustment_post' => $membership->hasPermission('billing.adjustments.post'), 'ledger' => $membership->hasPermission('billing.ledger.view'),
                'issue' => $membership->hasPermission('billing.invoices.issue'),
                'recurring' => $membership->hasPermission('billing.assignments.manage') && $membership->hasPermission('billing.invoices.issue'),
            ],
            'recurring_plans' => BillingRecurringPlan::query()->when(! $membership->hasPermission('billing.assignments.manage') || ! $membership->hasPermission('billing.invoices.issue'), fn ($q) => $q->whereRaw('1 = 0'))->with(['student:id,student_number,legal_first_name,legal_last_name', 'feeStructure:id,name'])
                ->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($q) => $q->where('campus_id', $membership->campus_id))
                ->latest()->limit(100)->get()->map(static fn (BillingRecurringPlan $plan): array => [
                    'id' => (int) $plan->getKey(), 'student' => $plan->student->student_number.' · '.trim($plan->student->legal_first_name.' '.$plan->student->legal_last_name),
                    'structure' => $plan->feeStructure->name, 'frequency' => $plan->frequency, 'next_period_start' => $plan->period_start->toDateString(),
                    'next_period_end' => $plan->period_end->toDateString(), 'next_run_at' => $plan->next_run_at->toIso8601String(), 'status' => $plan->status,
                ])->all(),
            'enrollment_assessment_structure_id' => ($configuredStructureId = data_get(TenantSetting::query()->where('key', 'billing.enrollment_fee_assessment')->first()?->value, 'fee_structure_id')) === null ? null : (int) $configuredStructureId,
        ]);
    }

    public function configureEnrollmentAssessment(Tenant $tenant, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['fee_structure_id' => ['nullable', 'integer', 'min:1']]);
        $service->configureEnrollmentFeeAssessment(isset($data['fee_structure_id']) ? (int) $data['fee_structure_id'] : null, $request->user());

        return back()->with('status', 'Enrollment assessment setting saved. A configured fee creates an assignment only; invoice issuance remains a separate finance action.');
    }

    public function storeRecurringPlan(Tenant $tenant, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'], 'fee_structure_id' => ['required', 'integer', 'min:1'],
            'frequency' => ['required', Rule::in(['monthly', 'annual'])], 'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'], 'due_days_after_period' => ['required', 'integer', 'min:0', 'max:90'],
            'timezone' => ['required', 'timezone'],
        ]);
        $service->createRecurringPlan($data, $request->user());

        return back()->with('status', 'Recurring billing plan created. Automatic invoices are generated only for the periods in this plan.');
    }

    public function recurringPlanStatus(Tenant $tenant, int $planId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'cancelled'])]]);
        $service->setRecurringPlanStatus($planId, $data['status'], $request->user());

        return back()->with('status', 'Recurring billing plan status updated.');
    }

    public function storeAssignment(Tenant $tenant, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'], 'fee_structure_id' => ['required', 'integer', 'min:1'],
            'course_enrollment_id' => ['nullable', 'integer', 'min:1'], 'academic_period_id' => ['nullable', 'integer', 'min:1'],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);
        $service->assign($data, $request->user());

        return back()->with('status', 'Fee assignment recorded. Issued invoices remain immutable.');
    }

    public function bulkIssue(Tenant $tenant, Request $request, BillingInvoiceIssuer $issuer, TenantContext $context): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.invoices.issue'), 403);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'], 'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'fee_structure_id' => ['required', 'integer', 'min:1'], 'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'due_on' => ['required', 'date', 'after_or_equal:period_start'],
        ]);
        $success = 0;
        $failed = [];
        foreach ($data['student_ids'] as $studentId) {
            try {
                $key = 'bulk:'.hash('sha256', implode(':', [$membership->tenant_id, $data['fee_structure_id'], $studentId, $data['period_start'], $data['period_end']]));
                $issuer->issue([
                    'student_id' => (int) $studentId, 'fee_structure_id' => (int) $data['fee_structure_id'],
                    'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'due_on' => $data['due_on'], 'idempotency_key' => $key,
                ], $request->user());
                $success++;
            } catch (\Throwable $exception) {
                logger()->warning('Bulk billing learner invoice failed.', ['student_id' => (int) $studentId, 'exception_class' => $exception::class]);
                $failed[] = (int) $studentId;
            }
        }

        return back()->with('status', "Bulk issue finished: {$success} invoice(s) issued or already present; ".count($failed).' learner(s) need review.')->with('billing_bulk_failed_ids', $failed);
    }

    public function storeConcession(Tenant $tenant, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:120'], 'kind' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $service->requestConcession($data, $request->user());

        return back()->with('status', 'Concession request submitted for independent review.');
    }

    public function reviewConcession(Tenant $tenant, int $concessionId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $service->reviewConcession($concessionId, $data['decision'] === 'approve', $data['note'], $request->user());

        return back()->with('status', 'Concession review recorded.');
    }

    public function revokeConcession(Tenant $tenant, int $concessionId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->revokeConcession($concessionId, $data['reason'], $request->user());

        return back()->with('status', 'Concession revoked for future invoices. Existing invoice snapshots remain unchanged.');
    }

    public function storeInstallments(Tenant $tenant, int $invoiceId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['installments' => ['required', 'array', 'min:1', 'max:36'], 'installments.*.due_on' => ['required', 'date'], 'installments.*.amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/']]);
        $service->createInstallments($invoiceId, $data['installments'], $request->user());

        return back()->with('status', 'Draft installment schedule created.');
    }

    public function publishInstallments(Tenant $tenant, int $planId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $service->publishInstallments($planId, $request->user());

        return back()->with('status', 'Installment schedule published.');
    }

    public function storeAdjustment(Tenant $tenant, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $service->requestAdjustment($data, $request->user());

        return back()->with('status', 'Adjustment submitted for independent approval.');
    }

    public function reviewAdjustment(Tenant $tenant, int $adjustmentId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $service->reviewAdjustment($adjustmentId, $data['decision'] === 'approve', $data['note'], $request->user());

        return back()->with('status', 'Adjustment review recorded.');
    }

    public function postAdjustment(Tenant $tenant, int $adjustmentId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $service->postAdjustment($adjustmentId, $request->user());

        return back()->with('status', 'Approved adjustment posted to the invoice balance. Original invoice and payment history remain unchanged.');
    }

    public function reverseAdjustment(Tenant $tenant, int $adjustmentId, Request $request, BillingLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:100']]);
        $service->requestAdjustmentReversal($adjustmentId, $data['reason'], $data['idempotency_key'], $request->user());

        return back()->with('status', 'Compensating reversal submitted for independent approval.');
    }

    public function ledger(Tenant $tenant, int $studentId, BillingStudentLedger $ledger, TenantContext $context): Response
    {
        abort_unless($context->membership()->hasPermission('billing.ledger.view'), 403);

        return Inertia::render('workspace/billing/ledger', ['tenant' => ['slug' => $tenant->slug], 'is_own_statement' => false, ...$ledger->statement($studentId)]);
    }

    public function ownLedger(Tenant $tenant, int $studentId, Request $request, BillingStudentLedger $ledger): Response
    {
        return Inertia::render('workspace/billing/ledger', ['tenant' => ['slug' => $tenant->slug], 'is_own_statement' => true, ...$ledger->statement($studentId, $request->user())]);
    }

    public function exportLedger(Tenant $tenant, int $studentId, Request $request, BillingStudentLedger $ledger, TenantContext $context, AuditRecorder $audit, bool $own = false): StreamedResponse
    {
        $statement = $own ? $ledger->statement($studentId, $request->user()) : $ledger->statement($studentId);
        $membership = $context->membership();
        $audit->record('billing.student_ledger.exported', $request->user(), (int) $membership->tenant_id, null, ['student_id' => $studentId, 'entry_count' => count($statement['entries']), 'own_portal' => $own]);
        $entries = $statement['entries'];

        return response()->streamDownload(static function () use ($entries): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Date', 'Source', 'Reference', 'Type', 'Description', 'Currency', 'Debit', 'Credit', 'Balance']);
            foreach ($entries as $entry) {
                fputcsv($output, [substr((string) $entry['date'], 0, 10), $entry['source'], BillingInvoiceReport::safeCsvCell($entry['reference']),
                    BillingInvoiceReport::safeCsvCell($entry['type']), BillingInvoiceReport::safeCsvCell($entry['description']), $entry['currency'], $entry['debit'], $entry['credit'], $entry['balance']]);
            }
            fclose($output);
        }, 'student-financial-statement.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
