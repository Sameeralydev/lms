<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingInvoiceReport;
use App\Domain\Billing\BillingMoney;
use App\Domain\Billing\BillingRecurringProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\BillingInvoice;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class Phase17BillingFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_fee_structure_issues_decimal_safe_idempotent_invoice_with_immutable_lines_and_pdf(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Academy');
        $student = $this->student($tenant, $membership, 'STU-17001');
        $base = "/t/{$tenant->slug}/billing";

        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'TUITION', 'name' => 'Monthly tuition', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", [
            'code' => '2026-MONTHLY', 'name' => 'Monthly fee schedule', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01',
            'items' => [['fee_head_id' => $headId, 'unit_amount' => '125.50', 'quantity' => '2']],
        ])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->put("{$base}/structures/{$structureId}", [
            'campus_id' => '', 'name' => 'Monthly tuition schedule v2', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'effective_until' => '',
            'items' => [['fee_head_id' => $headId, 'unit_amount' => '126.25', 'quantity' => '2']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        $this->actingAs($owner)->put("{$base}/structures/{$structureId}", [
            'campus_id' => '', 'name' => 'Illegal live edit', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'effective_until' => '',
            'items' => [['fee_head_id' => $headId, 'unit_amount' => '1.00', 'quantity' => '1']],
        ])->assertStatus(409);

        $payload = ['student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_on' => '2026-11-15', 'idempotency_key' => (string) Str::uuid()];
        $response = $this->actingAs($owner)->post("{$base}/invoices", $payload)->assertSessionHasNoErrors();
        $invoice = BillingInvoice::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $response->assertRedirect(route('tenant.billing.invoices.show', [$tenant, $invoice->getKey()]));
        self::assertSame('252.50', (string) $invoice->total);
        self::assertSame('252.50', (string) $invoice->outstanding_amount);
        self::assertSame('0.00', (string) $invoice->paid_amount);
        self::assertSame('Monthly tuition', $invoice->items()->value('fee_head_name'));
        self::assertSame('252.50', $invoice->items()->value('line_total'));

        $this->actingAs($owner)->put("{$base}/fee-heads/{$headId}", ['name' => 'Renamed tuition', 'status' => 'active'])->assertSessionHasNoErrors();
        self::assertSame('Monthly tuition', $invoice->fresh()->items()->value('fee_head_name'), 'Issued line descriptions are immutable snapshots.');
        $changedRequest = $payload;
        $changedRequest['due_on'] = '2026-11-20';
        $this->actingAs($owner)->post("{$base}/invoices", $changedRequest)->assertSessionHasErrors('idempotency_key');
        $this->actingAs($owner)->post("{$base}/invoices", $payload)->assertSessionHasNoErrors();
        self::assertSame(1, BillingInvoice::query()->where('tenant_id', $tenant->getKey())->count(), 'Repeating an idempotent request returns the original invoice.');

        $this->actingAs($owner)->get("{$base}/invoices/{$invoice->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/invoice')->where('invoice.total', '252.50')->where('invoice.items.0.fee_head_name', 'Monthly tuition'));
        $learner = User::factory()->create();
        $this->member($tenant, $membership, $learner, 'student');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $student->forceFill(['user_id' => $learner->getKey()])->save();
        } finally {
            $context->clear();
        }
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/my-invoices')->has('invoices.data', 1)->where('invoices.data.0.invoice_number', $invoice->invoice_number));
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my/{$invoice->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/invoice')->where('can_cancel', false)->where('invoice.id', $invoice->getKey()));
        $learnerPdf = $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my/{$invoice->getKey()}/print")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-1.4', $learnerPdf->getContent());
        $this->actingAs($learner)->get("{$base}/invoices/{$invoice->getKey()}")->assertForbidden();

        $otherStudent = $this->student($tenant, $membership, 'STU-17004');
        $this->actingAs($owner)->post("{$base}/invoices", [
            'student_id' => $otherStudent->getKey(), 'fee_structure_id' => $structureId,
            'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'due_on' => '2026-12-31',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $otherInvoice = BillingInvoice::query()->where('student_id', $otherStudent->getKey())->firstOrFail();
        $this->actingAs($owner)->get("{$base}/reports?search=STU-17001")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/reports')->where('summary.invoice_count', 1)->where('summary.invoiced_total', '252.50')
            ->has('invoices.data', 1)->where('invoices.data.0.invoice_number', $invoice->invoice_number)
            ->where('aging.0.currency', 'USD')->where('aging.0.days_1_30', '252.50'));
        $csv = $this->actingAs($owner)->get("{$base}/reports.csv?search=STU-17001")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString($invoice->invoice_number, $csv->streamedContent());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.invoice_report.exported']);
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my/{$otherInvoice->getKey()}")->assertNotFound();

        $pdf = $this->actingAs($owner)->get("{$base}/invoices/{$invoice->getKey()}/print")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.invoice.issued', 'subject_id' => (string) $invoice->getKey()]);
    }

    public function test_money_parser_rejects_negative_excess_precision_and_malformed_amounts(): void
    {
        self::assertSame(10005, BillingMoney::toMinor('100.05'));
        self::assertSame('251.00', BillingMoney::fromMinor(25100));
        self::assertSame("'=SUM(1,2)", BillingInvoiceReport::safeCsvCell('=SUM(1,2)'));
        self::assertSame('TUITION-01', BillingInvoiceReport::safeCsvCell('TUITION-01'));
        foreach (['-0.01', '1.001', '1e2', '01.00', 'NaN'] as $invalid) {
            try {
                BillingMoney::toMinor($invalid);
            } catch (\InvalidArgumentException) {
                continue;
            }

            self::fail("Invalid money value {$invalid} was accepted.");
        }
    }

    public function test_due_date_cannot_precede_billing_period_start(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Due Date Academy');
        $student = $this->student($tenant, $membership, 'STU-17005');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'TUITION', 'name' => 'Tuition', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", ['code' => 'DUE-26', 'name' => 'Due date test', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'items' => [['fee_head_id' => $headId, 'unit_amount' => '10.00', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();

        $this->actingAs($owner)->post("{$base}/invoices", ['student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'due_on' => '2026-11-30', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('due_on');
        self::assertSame(0, BillingInvoice::query()->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_foreign_tenant_cannot_open_an_invoice_and_duplicate_period_is_rejected(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Tenant One');
        $student = $this->student($tenant, $membership, 'STU-17002');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'REG', 'name' => 'Registration', 'category' => 'registration'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", ['code' => 'REG-26', 'name' => 'Registration fee', 'currency' => 'EUR', 'frequency' => 'one_time', 'effective_from' => '2026-01-01', 'items' => [['fee_head_id' => $headId, 'unit_amount' => '45.25', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        $payload = ['student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_on' => '2026-11-30', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post("{$base}/invoices", $payload)->assertSessionHasNoErrors();
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($owner)->post("{$base}/invoices", $payload)->assertSessionHasErrors('period_start');
        self::assertSame(1, BillingInvoice::query()->where('tenant_id', $tenant->getKey())->count());

        [$otherTenant, $otherOwner, $otherMembership] = $this->provision('Billing Tenant Two');
        $foreignInvoiceId = (int) \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/billing/invoices/{$foreignInvoiceId}")->assertNotFound();
        $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/billing/reports")->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.invoice_count', 0)->has('invoices.data', 0));
        $foreignCsv = $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/billing/reports.csv")->assertOk();
        self::assertStringNotContainsString('INV-', $foreignCsv->streamedContent());
        $financeUser = User::factory()->create();
        $this->member($otherTenant, $otherMembership, $financeUser, 'finance.manager');
        $this->actingAs($financeUser)->get("/t/{$otherTenant->slug}/billing/reports")->assertOk();
    }

    public function test_unpaid_invoice_can_be_cancelled_with_audited_reason_without_deleting_history(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Cancellation Academy');
        $student = $this->student($tenant, $membership, 'STU-17003');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'EXAM', 'name' => 'Examination fee', 'category' => 'examination'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", ['code' => 'EXAM-26', 'name' => 'Exam charge', 'currency' => 'GBP', 'frequency' => 'one_time', 'effective_from' => '2026-01-01', 'items' => [['fee_head_id' => $headId, 'unit_amount' => '20.00', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/invoices", ['student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'due_on' => '2026-12-31', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $invoice = BillingInvoice::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$base}/invoices/{$invoice->getKey()}/cancel", ['reason' => 'Billing period was configured incorrectly.'])->assertSessionHasNoErrors();
        self::assertSame('cancelled', $invoice->fresh()->status);
        self::assertSame('0.00', (string) $invoice->fresh()->outstanding_amount);
        self::assertSame(1, $invoice->items()->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.invoice.cancelled']);
    }

    public function test_fee_assignment_concession_installments_adjustment_and_ledger_workflow_is_audited(): void
    {
        [$tenant, $owner, $ownerMembership] = $this->provision('Billing Lifecycle Academy');
        $student = $this->student($tenant, $ownerMembership, 'STU-17006');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->get("{$base}/lifecycle")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/lifecycle')->where('permissions.assign', true)->has('students', 1));

        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'TUITION', 'name' => 'Tuition', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", [
            'code' => 'LIFECYCLE-2026', 'name' => 'Lifecycle tuition', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01',
            'items' => [['fee_head_id' => $headId, 'unit_amount' => '200.00', 'quantity' => '1']],
        ])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();

        $assignmentKey = (string) Str::uuid();
        $this->actingAs($owner)->post("{$base}/assignments", [
            'student_id' => $student->getKey(), 'fee_structure_id' => $structureId,
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'effective_from' => '2026-11-01',
            'idempotency_key' => $assignmentKey,
        ])->assertSessionHasNoErrors();
        $assignmentId = (int) \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->value('id');

        $this->actingAs($owner)->post("{$base}/concessions", [
            'student_id' => $student->getKey(), 'code' => 'NEED-BASED-26', 'name' => 'Need-based support', 'kind' => 'percentage',
            'value' => '10.00', 'effective_from' => '2026-11-01', 'effective_until' => '2026-11-30', 'reason' => 'Approved synthetic test concession.',
        ])->assertSessionHasNoErrors();
        $concessionId = (int) \DB::table('billing_concessions')->where('tenant_id', $tenant->getKey())->value('id');

        $reviewer = User::factory()->create();
        $this->member($tenant, $ownerMembership, $reviewer, 'finance.manager');
        $poster = User::factory()->create();
        $this->member($tenant, $ownerMembership, $poster, 'finance.manager');
        $this->actingAs($reviewer)->post("{$base}/concessions/{$concessionId}/review", ['decision' => 'approve', 'note' => 'Eligibility verified.'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post("{$base}/invoices", [
            'student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'fee_assignment_id' => $assignmentId,
            'concession_id' => $concessionId, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'due_on' => '2026-11-30', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $invoice = BillingInvoice::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        self::assertSame('200.00', (string) $invoice->subtotal);
        self::assertSame('20.00', (string) $invoice->discount_total);
        self::assertSame('180.00', (string) $invoice->total);
        self::assertSame(1, \DB::table('billing_concession_applications')->where('invoice_id', $invoice->getKey())->count());

        $this->actingAs($owner)->post("{$base}/invoices/{$invoice->getKey()}/installments", ['installments' => [
            ['due_on' => '2026-11-15', 'amount' => '100.00'], ['due_on' => '2026-11-30', 'amount' => '80.00'],
        ]])->assertSessionHasNoErrors();
        $planId = (int) \DB::table('billing_installment_plans')->where('invoice_id', $invoice->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/installment-plans/{$planId}/publish")->assertSessionHasNoErrors();
        self::assertSame('published', \DB::table('billing_installment_plans')->where('id', $planId)->value('status'));

        $adjustmentKey = (string) Str::uuid();
        $this->actingAs($owner)->post("{$base}/adjustments", [
            'invoice_id' => $invoice->getKey(), 'kind' => 'credit', 'amount' => '20.00', 'currency' => 'USD',
            'idempotency_key' => $adjustmentKey, 'reason' => 'Approved billing correction.',
        ])->assertSessionHasNoErrors();
        $adjustmentId = (int) \DB::table('billing_adjustments')->where('idempotency_key', $adjustmentKey)->value('id');
        $this->actingAs($reviewer)->post("{$base}/adjustments/{$adjustmentId}/review", ['decision' => 'approve', 'note' => 'Correction independently reviewed.'])->assertSessionHasNoErrors();
        $this->actingAs($poster)->post("{$base}/adjustments/{$adjustmentId}/post")->assertSessionHasNoErrors();

        $invoice->refresh();
        self::assertSame('160.00', (string) $invoice->adjusted_total);
        self::assertSame('160.00', (string) $invoice->outstanding_amount);
        self::assertSame('posted', \DB::table('billing_adjustments')->where('id', $adjustmentId)->value('status'));
        self::assertSame(3, \DB::table('billing_adjustment_events')->where('adjustment_id', $adjustmentId)->count());
        self::assertSame(2, \DB::table('billing_concession_events')->where('concession_id', $concessionId)->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.adjustment.posted']);
        $this->actingAs($owner)->post("{$base}/invoices/{$invoice->getKey()}/cancel", ['reason' => 'Cannot discard posted adjustment history.'])->assertSessionHasErrors('invoice');
        $this->actingAs($owner)->get("{$base}/ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/ledger')->where('currency_totals.0.balance', '160.00')->has('entries', 2));

        $reversalKey = (string) Str::uuid();
        $this->actingAs($poster)->post("{$base}/adjustments/{$adjustmentId}/reverse", ['reason' => 'Reverse the posted correction.', 'idempotency_key' => $reversalKey])->assertSessionHasNoErrors();
        $reversalId = (int) \DB::table('billing_adjustments')->where('reverses_adjustment_id', $adjustmentId)->value('id');
        self::assertNotSame(0, $reversalId);
        // The original requester reviews, while a different operator posts the compensating entry.
        $this->actingAs($owner)->post("{$base}/adjustments/{$reversalId}/review", ['decision' => 'approve', 'note' => 'Reversal supported by source documents.'])->assertSessionHasNoErrors();
        $reversalPoster = User::factory()->create();
        $this->member($tenant, $ownerMembership, $reversalPoster, 'finance.manager');
        $this->actingAs($reversalPoster)->post("{$base}/adjustments/{$reversalId}/post")->assertSessionHasNoErrors();
        self::assertSame('180.00', (string) $invoice->fresh()->adjusted_total);
        self::assertSame('reversed', \DB::table('billing_adjustments')->where('id', $adjustmentId)->value('status'));
        self::assertSame('posted', \DB::table('billing_adjustments')->where('id', $reversalId)->value('status'));
        $this->actingAs($owner)->get("{$base}/ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/ledger')->where('currency_totals.0.balance', '180.00')->has('entries', 3));
        $this->actingAs($poster)->post("{$base}/adjustments/{$adjustmentId}/reverse", ['reason' => 'A second reversal request.', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        self::assertSame(1, \DB::table('billing_adjustments')->where('reverses_adjustment_id', $adjustmentId)->count(), 'A posted adjustment can only receive one compensating reversal.');
    }

    public function test_learner_can_view_only_their_own_financial_statement(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Learner Statement Academy');
        $student = $this->student($tenant, $membership, 'STU-17020');
        $otherStudent = $this->student($tenant, $membership, 'STU-17021');
        $learner = User::factory()->create();
        $this->member($tenant, $membership, $learner, 'student');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $student->forceFill(['user_id' => $learner->getKey()])->save();
        } finally {
            $context->clear();
        }
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my-ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/billing/ledger')->where('student.id', $student->getKey())->has('entries', 0));
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my-ledger/{$student->getKey()}.csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs($learner)->get("/t/{$tenant->slug}/billing/my-ledger/{$otherStudent->getKey()}")->assertNotFound();
    }

    public function test_bulk_invoice_issue_is_scoped_bounded_and_idempotent(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Bulk Academy');
        $studentA = $this->student($tenant, $membership, 'STU-17030');
        $studentB = $this->student($tenant, $membership, 'STU-17031');
        $learner = User::factory()->create();
        $this->member($tenant, $membership, $learner, 'student');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $studentA->forceFill(['user_id' => $learner->getKey()])->save();
        } finally {
            $context->clear();
        }
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'BULK-TUITION', 'name' => 'Bulk tuition', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", ['code' => 'BULK-26', 'name' => 'Bulk 2026', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'items' => [['fee_head_id' => $headId, 'unit_amount' => '25.00', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        $payload = ['student_ids' => [$studentA->getKey(), $studentB->getKey()], 'fee_structure_id' => $structureId, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_on' => '2026-11-20'];
        $this->actingAs($owner)->post("{$base}/invoices/bulk", $payload)->assertSessionHasNoErrors();
        self::assertSame(2, BillingInvoice::query()->where('tenant_id', $tenant->getKey())->count());
        self::assertSame(1, \DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $learner->getKey())->count(), 'New invoice notification is written to the learner internal inbox once.');
        $this->actingAs($owner)->post("{$base}/invoices/bulk", $payload)->assertSessionHasNoErrors();
        self::assertSame(2, BillingInvoice::query()->where('tenant_id', $tenant->getKey())->count(), 'Repeating a bulk request resolves the original per-learner invoices.');
        $this->actingAs($owner)->get("{$base}/lifecycle")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/billing/lifecycle')->where('permissions.issue', true));
    }

    public function test_recurring_plan_generates_one_idempotent_invoice_and_advances_period(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Billing Recurring Academy');
        $student = $this->student($tenant, $membership, 'STU-17040');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/fee-heads", ['code' => 'REC-TUITION', 'name' => 'Recurring tuition', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures", ['code' => 'REC-26', 'name' => 'Recurring monthly', 'currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'items' => [['fee_head_id' => $headId, 'unit_amount' => '50.00', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $structureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/recurring-plans", [
            'student_id' => $student->getKey(), 'fee_structure_id' => $structureId, 'frequency' => 'monthly',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_days_after_period' => 5, 'timezone' => 'Asia/Karachi',
        ])->assertSessionHasNoErrors();
        self::assertSame(1, \DB::table('billing_recurring_plans')->where('tenant_id', $tenant->getKey())->count());
        $result = app(BillingRecurringProcessor::class)->processDue();
        self::assertSame(['processed' => 1, 'failed' => 0], $result);
        self::assertSame(1, \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->count());
        self::assertDatabaseHas('billing_recurring_runs', ['tenant_id' => $tenant->getKey(), 'status' => 'completed', 'attempts' => 1]);
        self::assertSame('2026-11-01', substr((string) \DB::table('billing_recurring_plans')->where('tenant_id', $tenant->getKey())->value('period_start'), 0, 10));
        self::assertSame(['processed' => 0, 'failed' => 0], app(BillingRecurringProcessor::class)->processDue());
        self::assertSame(1, \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->count());
    }

    /** @return array{Tenant, User, TenantMembership} */
    private function provision(string $name): array
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $details = ['official_name' => $name, 'display_name' => $name, 'slug' => Str::slug($name), 'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en'];
        $result = app(TenantProvisioner::class)->provision($details, $actor, $owner->email);
        $acceptanceUrl = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($acceptanceUrl)->assertRedirect("/t/{$result->tenant->slug}/dashboard");
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $result->tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();

        return [$result->tenant, $owner, $membership];
    }

    private function student(Tenant $tenant, TenantMembership $membership, string $studentNumber): SisStudent
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            return SisStudent::query()->create([
                'institution_id' => $membership->institution_id, 'student_number' => $studentNumber,
                'registration_reference' => 'REG-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Test',
                'legal_last_name' => 'Learner', 'admission_date' => '2026-01-01', 'status' => 'active',
            ]);
        } finally {
            $context->clear();
        }
    }

    private function member(Tenant $tenant, TenantMembership $ownerMembership, User $user, string $roleKey): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $member = TenantMembership::query()->create(['institution_id' => $ownerMembership->institution_id, 'user_id' => $user->getKey(), 'campus_id' => null, 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $member->roles()->attach($role->getKey(), ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->getKey()]);

            return $member;
        } finally {
            $context->clear();
        }
    }
}
