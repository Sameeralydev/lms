<?php

namespace App\Http\Controllers\Workspace;

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
use App\Models\Campus;
use App\Models\SisStudent;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BillingLifecycleController extends Controller
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('billing.assignments.manage') || $membership->hasPermission('billing.concessions.manage')
            || $membership->hasPermission('billing.installments.manage') || $membership->hasPermission('billing.adjustments.manage')
            || $membership->hasPermission('billing.ledger.view'), 403);
        $scope = fn ($query) => $query->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($q) => $q->where('campus_id', $membership->campus_id));

        return Inertia::render('workspace/billing/lifecycle', [
            'tenant' => ['slug' => $tenant->slug],
            'students' => SisStudent::query()->where('institution_id', $membership->institution_id)->whereIn('status', ['pre_admitted', 'admitted', 'active', 'on_leave'])->whereNull('archived_at')
                ->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']),
            'campuses' => Campus::query()->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))->orderBy('name')->get(['id', 'name']),
            'structures' => DB::table('billing_fee_structures')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('status', 'active')
                ->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
                ->orderBy('name')->get(['id', 'name', 'currency', 'effective_from', 'effective_until']),
            'invoices' => BillingInvoice::query()->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($q) => $q->where('campus_id', $membership->campus_id))
                ->whereNotIn('status', ['cancelled'])->with('student:id,student_number,legal_first_name,legal_last_name')->latest('issued_at')->limit(200)->get()
                ->map(static fn (BillingInvoice $invoice): array => ['id' => (int) $invoice->getKey(), 'number' => $invoice->invoice_number, 'student_id' => (int) $invoice->student_id, 'student' => $invoice->student->student_number.' · '.trim($invoice->student->legal_first_name.' '.$invoice->student->legal_last_name), 'currency' => $invoice->currency, 'amount_due' => (string) $invoice->adjusted_total, 'outstanding' => (string) $invoice->outstanding_amount, 'status' => $invoice->status])->all(),
            'assignments' => $scope(BillingFeeAssignment::query())->with(['student:id,student_number,legal_first_name,legal_last_name', 'feeStructure:id,code,name'])->latest()->limit(100)->get()
                ->map(static fn (BillingFeeAssignment $row): array => ['id' => (int) $row->getKey(), 'student' => $row->student->student_number.' · '.trim($row->student->legal_first_name.' '.$row->student->legal_last_name), 'structure' => $row->feeStructure->name, 'period_start' => Carbon::parse($row->period_start)->toDateString(), 'period_end' => Carbon::parse($row->period_end)->toDateString(), 'source' => $row->source, 'status' => $row->status])->all(),
            'concessions' => $scope(BillingConcession::query())->with('student:id,student_number,legal_first_name,legal_last_name')->latest()->limit(100)->get()
                ->map(static fn (BillingConcession $row): array => ['id' => (int) $row->getKey(), 'student' => $row->student->student_number.' · '.trim($row->student->legal_first_name.' '.$row->student->legal_last_name), 'code' => $row->code, 'name' => $row->name, 'kind' => $row->kind, 'value' => (string) $row->value, 'status' => $row->status, 'requested_by' => (int) $row->requested_by])->all(),
            'installment_plans' => BillingInstallmentPlan::query()->where('tenant_id', $membership->tenant_id)->whereHas('invoice', fn ($q) => $q->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($nested) => $nested->where('campus_id', $membership->campus_id)))
                ->with(['invoice:id,invoice_number,student_id', 'installments'])->latest()->limit(100)->get()->map(static fn (BillingInstallmentPlan $row): array => ['id' => (int) $row->getKey(), 'invoice' => $row->invoice->invoice_number, 'status' => $row->status, 'installments' => $row->installments->map(static fn (BillingInstallment $item): array => ['sequence' => $item->sequence, 'due_on' => Carbon::parse($item->due_on)->toDateString(), 'amount' => (string) $item->amount])->all()])->all(),
            'adjustments' => BillingAdjustment::query()->where('tenant_id', $membership->tenant_id)->whereHas('invoice', fn ($q) => $q->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($nested) => $nested->where('campus_id', $membership->campus_id)))
                ->with('invoice:id,invoice_number')->latest()->limit(100)->get()->map(static fn (BillingAdjustment $row): array => ['id' => (int) $row->getKey(), 'number' => $row->adjustment_number, 'invoice' => $row->invoice->invoice_number, 'kind' => $row->kind, 'amount' => (string) $row->amount, 'status' => $row->status, 'requested_by' => (int) $row->requested_by, 'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by])->all(),
            'permissions' => [
                'assign' => $membership->hasPermission('billing.assignments.manage'), 'concession_request' => $membership->hasPermission('billing.concessions.manage'),
                'concession_review' => $membership->hasPermission('billing.concessions.approve'), 'installments' => $membership->hasPermission('billing.installments.manage'),
                'adjustment_request' => $membership->hasPermission('billing.adjustments.manage'), 'adjustment_review' => $membership->hasPermission('billing.adjustments.approve'),
                'adjustment_post' => $membership->hasPermission('billing.adjustments.post'), 'ledger' => $membership->hasPermission('billing.ledger.view'),
            ],
        ]);
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

    public function ledger(Tenant $tenant, int $studentId, BillingStudentLedger $ledger, TenantContext $context): Response
    {
        abort_unless($context->membership()->hasPermission('billing.ledger.view'), 403);

        return Inertia::render('workspace/billing/ledger', ['tenant' => ['slug' => $tenant->slug], ...$ledger->statement($studentId)]);
    }
}
