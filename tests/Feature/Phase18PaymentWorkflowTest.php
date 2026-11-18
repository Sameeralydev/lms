<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingMoney;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\BillingFeeHead;
use App\Models\BillingFeeStructure;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentEvent;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class Phase18PaymentWorkflowTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_cash_payment_allocates_atomically_and_idempotent_replay_does_not_duplicate_settlement(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Payment Cash Academy');
        $student = $this->student($tenant, $membership, 'STU-18001');
        $learner = User::factory()->create();
        $this->linkStudentUser($tenant, $membership, $student, $learner);
        $invoice = $this->invoice($tenant, $membership, $owner, $student, '100.00');
        $base = "/t/{$tenant->slug}/billing/payments";
        $payload = [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '60.00', 'currency' => 'USD',
            'reference' => 'CASH-18001', 'idempotency_key' => 'cash-'.Str::uuid(),
            'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '40.00']],
        ];

        $this->actingAs($owner)->post($base, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($owner)->post($base, $payload)->assertSessionHasNoErrors()->assertRedirect();

        self::assertSame('40.00', (string) $invoice->fresh()->paid_amount);
        self::assertSame('60.00', (string) $invoice->fresh()->outstanding_amount);
        self::assertSame('partially_paid', $invoice->fresh()->status);
        self::assertSame(1, \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->count());
        self::assertSame(1, \DB::table('billing_payment_allocations')->where('tenant_id', $tenant->getKey())->count());
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->get("/t/{$tenant->slug}/billing/payments/{$paymentId}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.payment.confirmed']);
        self::assertSame(1, \DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $learner->getKey())->count(), 'Idempotent replay must not duplicate payment notifications.');
        $this->actingAs($owner)->get("/t/{$tenant->slug}/billing/reports")->assertOk()->assertInertia(fn ($page) => $page
            ->where('collections.0.currency', 'USD')->where('collections.0.method', 'cash')
            ->where('collections.0.payment_count', 1)->where('collections.0.net_confirmed_amount', '60.00'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/billing/ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('currency_totals.0.debit', '100.00')->where('currency_totals.0.credit', '60.00')->where('currency_totals.0.balance', '40.00'));
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/{$paymentId}/allocate", [
            'request_key' => 'cash-credit-'.Str::uuid(), 'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '20.00']],
        ])->assertSessionHasNoErrors();
        self::assertSame('40.00', (string) $invoice->fresh()->outstanding_amount);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/billing/ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('currency_totals.0.credit', '60.00')->where('currency_totals.0.balance', '40.00'));
        $this->actingAs($owner)->post($base, [...$payload, 'amount' => '61.00'])->assertSessionHasErrors('idempotency_key');
        self::assertSame(1, \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_bank_transfer_does_not_settle_until_independent_review_and_replay_is_idempotent(): void
    {
        [$tenant, $collector, $membership] = $this->provision('Payment Bank Academy');
        $reviewer = User::factory()->create();
        $this->member($tenant, $membership, $reviewer, 'finance.manager');
        $student = $this->student($tenant, $membership, 'STU-18002');
        $invoice = $this->invoice($tenant, $membership, $collector, $student, '75.00');
        $base = "/t/{$tenant->slug}/billing/payments";
        $this->actingAs($collector)->post($base, [
            'student_id' => $student->getKey(), 'method' => 'bank_transfer', 'amount' => '75.00', 'currency' => 'USD',
            'reference' => 'BANK-18002', 'idempotency_key' => 'bank-'.Str::uuid(), 'allocations' => [],
        ])->assertSessionHasNoErrors();
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');
        self::assertSame('issued', $invoice->fresh()->status);
        self::assertSame('0.00', (string) $invoice->fresh()->paid_amount);
        self::assertNull(\DB::table('billing_payments')->where('id', $paymentId)->value('receipt_number'));
        $this->actingAs($collector)->get("/t/{$tenant->slug}/billing/reports")->assertOk()->assertInertia(fn ($page) => $page->has('collections', 0));

        $verify = ['request_key' => 'verify-'.Str::uuid(), 'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '50.00']]];
        $this->actingAs($collector)->post("{$base}/{$paymentId}/verify", $verify)->assertSessionHasErrors('payment');
        $this->actingAs($reviewer)->post("{$base}/{$paymentId}/verify", $verify)->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post("{$base}/{$paymentId}/verify", $verify)->assertSessionHasNoErrors();
        self::assertSame('partially_paid', $invoice->fresh()->status);
        self::assertSame('50.00', (string) $invoice->fresh()->paid_amount);
        self::assertSame('25.00', (string) BillingPayment::query()->findOrFail($paymentId)->unapplied_amount);
        self::assertSame(1, \DB::table('billing_payment_allocations')->where('payment_id', $paymentId)->count());
        self::assertNotNull(\DB::table('billing_payments')->where('id', $paymentId)->value('receipt_number'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.payment.verification_approved']);
    }

    public function test_allocation_rejects_foreign_student_invoice_without_any_partial_mutation(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Payment Isolation Academy');
        $student = $this->student($tenant, $membership, 'STU-18003');
        $otherStudent = $this->student($tenant, $membership, 'STU-18004');
        $invoice = $this->invoice($tenant, $membership, $owner, $student, '100.00');
        $foreignInvoice = $this->invoice($tenant, $membership, $owner, $otherStudent, '20.00');
        $base = "/t/{$tenant->slug}/billing/payments";
        $this->actingAs($owner)->post($base, [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '50.00', 'currency' => 'USD',
            'reference' => null, 'idempotency_key' => 'isolation-'.Str::uuid(),
            'allocations' => [
                ['invoice_id' => $invoice->getKey(), 'amount' => '10.00'],
                ['invoice_id' => $foreignInvoice->getKey(), 'amount' => '10.00'],
            ],
        ])->assertNotFound();

        self::assertSame('0.00', (string) $invoice->fresh()->paid_amount);
        self::assertSame('0.00', (string) $foreignInvoice->fresh()->paid_amount);
        self::assertSame(0, \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_refund_requires_independent_approval_and_execution_and_reverses_invoice_balance(): void
    {
        [$tenant, $collector, $membership] = $this->provision('Payment Refund Academy');
        $approver = User::factory()->create();
        $executor = User::factory()->create();
        $this->member($tenant, $membership, $approver, 'finance.manager');
        $this->member($tenant, $membership, $executor, 'finance.manager');
        $student = $this->student($tenant, $membership, 'STU-18005');
        $learner = User::factory()->create();
        $this->linkStudentUser($tenant, $membership, $student, $learner);
        $invoice = $this->invoice($tenant, $membership, $collector, $student, '100.00');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($collector)->post("{$base}/payments", [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '100.00', 'currency' => 'USD',
            'reference' => 'REFUND-SOURCE-18005', 'idempotency_key' => 'refund-source-'.Str::uuid(),
            'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '80.00']],
        ])->assertSessionHasNoErrors();
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($collector)->post("{$base}/payments/{$paymentId}/refunds", [
            'amount' => '30.00', 'reason' => 'Duplicate amount collected in error.', 'request_key' => 'refund-'.Str::uuid(),
        ])->assertSessionHasNoErrors();
        $refundId = (int) \DB::table('billing_payment_refunds')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($collector)->post("{$base}/refunds/{$refundId}/approve", ['note' => 'Reviewed source receipt.'])->assertSessionHasErrors('refund');
        self::assertSame('80.00', (string) $invoice->fresh()->paid_amount, 'Request and approval must not change invoice settlement.');
        $this->actingAs($approver)->post("{$base}/refunds/{$refundId}/approve", ['note' => 'Reviewed source receipt.'])->assertStatus(302)->assertSessionHasNoErrors();
        $this->actingAs($approver)->post("{$base}/refunds/{$refundId}/execute", ['payout_reference' => 'PAYOUT-18005'])->assertSessionHasErrors('refund');
        $this->actingAs($executor)->post("{$base}/refunds/{$refundId}/execute", ['payout_reference' => 'PAYOUT-18005'])->assertStatus(302)->assertSessionHasNoErrors();
        $this->actingAs($executor)->post("{$base}/refunds/{$refundId}/execute", ['payout_reference' => 'PAYOUT-18005'])->assertStatus(302)->assertSessionHasNoErrors();

        self::assertSame('70.00', (string) $invoice->fresh()->paid_amount);
        self::assertSame('30.00', (string) $invoice->fresh()->outstanding_amount);
        self::assertSame('partially_paid', $invoice->fresh()->status);
        self::assertSame('0.00', (string) BillingPayment::query()->findOrFail($paymentId)->unapplied_amount);
        self::assertSame('refunded', \DB::table('billing_payment_refunds')->where('id', $refundId)->value('status'));
        self::assertSame('10.00', BillingMoney::fromMinor(BillingMoney::toMinor((string) \DB::table('billing_payment_refund_allocations')->where('refund_id', $refundId)->value('amount'))));
        self::assertSame(1, \DB::table('billing_payment_refund_allocations')->where('refund_id', $refundId)->count(), 'Retrying an executed payout must not repeat invoice reversal.');
        $confirmation = BillingPaymentEvent::query()->where('payment_id', $paymentId)->where('event_type', 'payment.confirmed')->firstOrFail();
        self::assertSame([['invoice_id' => (int) $invoice->getKey(), 'amount' => '80.00']], $confirmation->metadataArray()['allocations']);
        self::assertSame('20.00', $confirmation->metadataArray()['unapplied_amount'], 'Later refunds must not rewrite the original receipt snapshot.');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.refund.executed']);
        $notificationTypes = \DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $learner->getKey())
            ->get(['data'])->map(static fn ($row): string => json_decode($row->data, true)['type'] ?? '')->all();
        self::assertSame(4, count($notificationTypes));
        self::assertContains('billing.refund.requested', $notificationTypes);
        self::assertContains('billing.refund.approved', $notificationTypes);
        self::assertContains('billing.refund.executed', $notificationTypes);
        $this->actingAs($executor)->get("/t/{$tenant->slug}/billing/reports")->assertOk()->assertInertia(fn ($page) => $page
            ->where('collections.0.currency', 'USD')->where('collections.0.method', 'cash')
            ->where('collections.0.payment_count', 1)->where('collections.0.net_confirmed_amount', '70.00'));
    }

    public function test_payment_reversal_uses_separate_review_and_execution_and_preserves_history(): void
    {
        [$tenant, $requester, $membership] = $this->provision('Payment Reversal Academy');
        $reviewer = User::factory()->create();
        $executor = User::factory()->create();
        $this->member($tenant, $membership, $reviewer, 'finance.manager');
        $this->member($tenant, $membership, $executor, 'finance.manager');
        $student = $this->student($tenant, $membership, 'STU-18010');
        $invoice = $this->invoice($tenant, $membership, $requester, $student, '100.00');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($requester)->post("{$base}/payments", [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '100.00', 'currency' => 'USD',
            'reference' => 'REVERSAL-SOURCE-18010', 'idempotency_key' => 'reversal-source-'.Str::uuid(),
            'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '80.00']],
        ])->assertSessionHasNoErrors();
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');
        $requestKey = 'reversal-'.Str::uuid();
        $reversalPayload = ['request_key' => $requestKey, 'reason' => 'Correct a duplicated cash receipt.'];
        $this->actingAs($requester)->post("{$base}/payments/{$paymentId}/reversals", $reversalPayload)->assertSessionHasNoErrors();
        $this->actingAs($requester)->post("{$base}/payments/{$paymentId}/reversals", $reversalPayload)->assertSessionHasNoErrors();
        $reversalId = (int) \DB::table('billing_payment_reversals')->where('request_key', $requestKey)->value('id');
        self::assertSame(1, \DB::table('billing_payment_reversals')->where('payment_id', $paymentId)->count());
        self::assertSame('80.00', (string) $invoice->fresh()->paid_amount, 'Requesting a reversal does not alter settlement.');
        $this->actingAs($requester)->post("{$base}/payment-reversals/{$reversalId}/review", ['decision' => 'approve', 'note' => 'Reviewed the original receipt.'])->assertSessionHasErrors('reversal');
        $this->actingAs($reviewer)->post("{$base}/payment-reversals/{$reversalId}/review", ['decision' => 'approve', 'note' => 'Reviewed the original receipt.'])->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post("{$base}/payments/{$paymentId}/refunds", ['amount' => '10.00', 'reason' => 'Refund requested during reversal.', 'request_key' => 'refund-block-'.Str::uuid()])->assertStatus(409);
        $this->actingAs($reviewer)->post("{$base}/payment-reversals/{$reversalId}/execute")->assertSessionHasErrors('reversal');
        $this->actingAs($executor)->post("{$base}/payment-reversals/{$reversalId}/execute")->assertStatus(302)->assertSessionHasNoErrors();
        $this->actingAs($executor)->post("{$base}/payment-reversals/{$reversalId}/execute")->assertStatus(302)->assertSessionHasNoErrors();

        self::assertSame('reversed', \DB::table('billing_payments')->where('id', $paymentId)->value('status'));
        self::assertSame('reversed', \DB::table('billing_payment_reversals')->where('id', $reversalId)->value('status'));
        self::assertSame(0, BillingMoney::toMinor((string) \DB::table('billing_payments')->where('id', $paymentId)->value('unapplied_amount')));
        self::assertSame('0.00', (string) $invoice->fresh()->paid_amount);
        self::assertSame('100.00', (string) $invoice->fresh()->outstanding_amount);
        self::assertSame(1, \DB::table('billing_payment_events')->where('payment_id', $paymentId)->where('event_type', 'payment.reversal.executed')->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.payment.reversal.executed']);
        $this->actingAs($requester)->get("{$base}/payments/{$paymentId}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($requester)->get("{$base}/ledger/{$student->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('currency_totals.0.debit', '180.00')->where('currency_totals.0.credit', '80.00')->where('currency_totals.0.balance', '100.00'));
        $this->actingAs($requester)->post("{$base}/payments/{$paymentId}/refunds", ['amount' => '10.00', 'reason' => 'Cannot refund reversed receipt.', 'request_key' => 'refund-after-reversal-'.Str::uuid()])->assertStatus(409);
    }

    public function test_guardian_payment_access_requires_explicit_financial_grant_and_revocation_is_immediate(): void
    {
        [$tenant, $owner, $membership] = $this->provision('Payment Guardian Academy');
        $student = $this->student($tenant, $membership, 'STU-18006');
        $guardianUser = User::factory()->create();
        $this->member($tenant, $membership, $guardianUser, 'parent.guardian');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $guardian = SisStudentGuardian::query()->create([
                'student_id' => $student->getKey(), 'guardian_user_id' => $guardianUser->getKey(), 'guardian_name' => 'Verified Guardian',
                'guardian_email' => $guardianUser->email, 'relationship' => 'parent', 'authority_status' => 'verified',
                'can_view_profile' => false, 'can_view_financial_records' => false, 'verified_by' => $owner->getKey(), 'verified_at' => now(),
            ]);
        } finally {
            $context->clear();
        }
        $invoice = $this->invoice($tenant, $membership, $owner, $student, '50.00');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/payments", [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '50.00', 'currency' => 'USD',
            'reference' => 'GUARDIAN-PAYMENT-18006', 'idempotency_key' => 'guardian-'.Str::uuid(),
            'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '50.00']],
        ])->assertSessionHasNoErrors();
        $paymentId = (int) \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->value('id');

        $this->actingAs($guardianUser)->get("/t/{$tenant->slug}/billing/my")->assertOk()->assertInertia(fn ($page) => $page->has('invoices.data', 0)->has('payments', 0));
        $this->actingAs($owner)->patch("/t/{$tenant->slug}/sis/guardians/{$guardian->getKey()}/financial-access", ['can_view_financial_records' => true])->assertStatus(302);
        $this->actingAs($guardianUser)->get("/t/{$tenant->slug}/billing/my")->assertOk()->assertInertia(fn ($page) => $page->has('invoices.data', 1)->has('payments', 1));
        $this->actingAs($guardianUser)->get("/t/{$tenant->slug}/billing/my/payments/{$paymentId}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardian->getKey()}/revoke", ['reason' => 'Authority ended by verified institutional request.'])->assertStatus(302);
        self::assertFalse((bool) $guardian->fresh()->can_view_financial_records);
        $this->actingAs($guardianUser)->get("/t/{$tenant->slug}/billing/my")->assertOk()->assertInertia(fn ($page) => $page->has('invoices.data', 0)->has('payments', 0));
        $this->actingAs($guardianUser)->get("/t/{$tenant->slug}/billing/my/payments/{$paymentId}/receipt")->assertNotFound();
    }

    public function test_reconciliation_is_scoped_and_exports_confirmed_pending_and_executed_records(): void
    {
        self::assertSame('1000000000000.00', BillingMoney::normalizeDecimal('1000000000000.0000'));
        [$tenant, $owner, $membership] = $this->provision('Payment Reconciliation Academy');
        $student = $this->student($tenant, $membership, 'STU-18007');
        $invoice = $this->invoice($tenant, $membership, $owner, $student, '100.00');
        $base = "/t/{$tenant->slug}/billing";
        $this->actingAs($owner)->post("{$base}/payments", [
            'student_id' => $student->getKey(), 'method' => 'cash', 'amount' => '40.00', 'currency' => 'USD',
            'reference' => 'RECON-CASH-18007', 'idempotency_key' => 'recon-cash-'.Str::uuid(),
            'allocations' => [['invoice_id' => $invoice->getKey(), 'amount' => '30.00']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/payments", [
            'student_id' => $student->getKey(), 'method' => 'bank_transfer', 'amount' => '20.00', 'currency' => 'USD',
            'reference' => 'RECON-BANK-18007', 'idempotency_key' => 'recon-bank-'.Str::uuid(), 'allocations' => [],
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get("{$base}/reconciliation?from=".now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/billing/reconciliation')
            ->where('payment_totals.0.method', 'bank_transfer')->where('payment_totals.0.status', 'pending_verification')
            ->where('payment_totals.1.method', 'cash')->where('payment_totals.1.amount', '40.00'));
        $export = $this->actingAs($owner)->get("{$base}/reconciliation.csv?from=".now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('RECON-CASH-18007', $export->streamedContent());
        self::assertStringContainsString('RECON-BANK-18007', $export->streamedContent());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.collection_reconciliation.exported']);
    }

    /** @return array{Tenant, User, TenantMembership} */
    private function provision(string $name): array
    {
        $owner = User::factory()->create();
        $provisioner = app(TenantProvisioner::class)->provision([
            'official_name' => $name, 'display_name' => $name, 'slug' => Str::slug($name), 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '',
            'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], User::factory()->create(), $owner->email);
        $acceptanceUrl = URL::temporarySignedRoute('invitations.accept', $provisioner->ownerInvitation->expires_at, ['token' => $provisioner->ownerInvitationToken]);
        $this->actingAs($owner)->post($acceptanceUrl)->assertRedirect("/t/{$provisioner->tenant->slug}/dashboard");
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $provisioner->tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();

        return [$provisioner->tenant, $owner, $membership];
    }

    private function student(Tenant $tenant, TenantMembership $membership, string $number): SisStudent
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            return SisStudent::query()->create(['institution_id' => $membership->institution_id, 'student_number' => $number, 'registration_reference' => 'REG-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Test', 'legal_last_name' => 'Learner', 'admission_date' => '2026-01-01', 'status' => 'active']);
        } finally {
            $context->clear();
        }
    }

    private function linkStudentUser(Tenant $tenant, TenantMembership $membership, SisStudent $student, User $user): void
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $student->forceFill(['user_id' => $user->getKey()])->save();
        } finally {
            $context->clear();
        }
    }

    private function invoice(Tenant $tenant, TenantMembership $membership, User $actor, SisStudent $student, string $amount): BillingInvoice
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $head = BillingFeeHead::query()->create(['institution_id' => $membership->institution_id, 'code' => 'FEE-'.Str::upper(Str::random(7)), 'name' => 'Tuition', 'category' => 'tuition', 'status' => 'active']);
            $structure = BillingFeeStructure::query()->create(['institution_id' => $membership->institution_id, 'campus_id' => $student->home_campus_id, 'code' => 'STRUCT-'.Str::upper(Str::random(6)), 'name' => 'Test fee', 'currency' => 'USD', 'frequency' => 'one_time', 'effective_from' => '2026-01-01', 'status' => 'active', 'created_by' => $actor->getKey(), 'published_by' => $actor->getKey(), 'published_at' => now()]);
            $structure->items()->create(['fee_head_id' => $head->getKey(), 'unit_amount' => $amount, 'quantity' => 1, 'sort_order' => 0]);

            return BillingInvoice::query()->create([
                'institution_id' => $membership->institution_id, 'campus_id' => $student->home_campus_id, 'student_id' => $student->getKey(), 'fee_structure_id' => $structure->getKey(),
                'invoice_number' => 'INV-'.Str::upper(Str::random(10)), 'idempotency_key' => (string) Str::uuid(), 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_on' => '2026-11-30',
                'currency' => 'USD', 'subtotal' => $amount, 'discount_total' => '0.00', 'adjusted_total' => $amount, 'total' => $amount, 'paid_amount' => '0.00', 'outstanding_amount' => $amount,
                'status' => 'issued', 'issued_at' => now(), 'issued_by' => $actor->getKey(),
            ]);
        } finally {
            $context->clear();
        }
    }

    private function member(Tenant $tenant, TenantMembership $owner, User $user, string $roleKey): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $owner);
        try {
            $membership = TenantMembership::query()->create(['institution_id' => $owner->institution_id, 'user_id' => $user->getKey(), 'campus_id' => null, 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->getKey()]);

            return $membership;
        } finally {
            $context->clear();
        }
    }
}
