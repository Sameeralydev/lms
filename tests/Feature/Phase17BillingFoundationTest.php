<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingInvoiceReport;
use App\Domain\Billing\BillingMoney;
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
            ->has('invoices.data', 1)->where('invoices.data.0.invoice_number', $invoice->invoice_number));
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
                self::fail("Invalid money value {$invalid} was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
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
