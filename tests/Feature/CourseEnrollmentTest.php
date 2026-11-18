<?php

namespace Tests\Feature;

use App\Domain\Admissions\AdmissionOutboxDispatcher;
use App\Domain\Admissions\AdmissionOutboxProcessor;
use App\Domain\Admissions\LocalAdmissionOutboxDispatcher;
use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Enrollment\WaitlistNotificationOutbox;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicLevel;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AdmissionOutboxEvent;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryWorkspace;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\CourseWaitlistEntry;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\EnrollmentWaitlistNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

#[Group('phase08')]
class CourseEnrollmentTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_student_registration_is_tenant_scoped_idempotent_and_capacity_safe_with_waitlist_promotion(): void
    {
        Notification::fake();
        [$tenant, $owner, $studentA, $studentB, $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";

        // Optional billing automation creates an enrollment-linked assessment only after
        // the institution explicitly configures an eligible one-time structure.
        $this->actingAs($owner)->post("{$base}/billing/fee-heads", ['code' => 'ENROLL-REG', 'name' => 'Enrollment registration', 'category' => 'registration'])->assertSessionHasNoErrors();
        $billingHeadId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/billing/structures", ['code' => 'ENROLL-ONE-TIME', 'name' => 'Enrollment fee', 'currency' => 'USD', 'frequency' => 'one_time', 'effective_from' => now()->toDateString(), 'items' => [['fee_head_id' => $billingHeadId, 'unit_amount' => '15.00', 'quantity' => '1']]])->assertSessionHasNoErrors();
        $billingStructureId = (int) \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/billing/structures/{$billingStructureId}/activate")->assertSessionHasNoErrors();
        $this->actingAs($owner)->put("{$base}/billing/enrollment-assessment", ['fee_structure_id' => $billingStructureId])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", [
            'name' => 'Default registration', 'minimum_courses' => 0, 'maximum_courses' => 4,
            'maximum_credits' => '', 'requires_approval' => false, 'waitlist_enabled' => true, 'waitlist_offer_hours' => 48,
        ])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'BIO-101-A', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(),
            'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", [
            'section_code' => 'A', 'capacity' => 1, 'reserved_seats' => 0,
        ])->assertRedirect();
        $section = CourseSection::query()->where('offering_id', $offeringId)->firstOrFail();
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();

        $this->actingAs($owner)->post("{$base}/registrar/enrollment/holds", [
            'student_id' => $this->studentId($studentA), 'hold_type' => 'academic', 'reason_code' => 'ADVISING_REVIEW',
            'reason' => 'Synthetic test hold for registration.', 'effective_at' => now()->subMinute()->format('Y-m-d\\TH:i'),
            'expires_at' => now()->addDay()->format('Y-m-d\\TH:i'),
        ])->assertRedirect();
        $this->actingAs($studentA)->get("{$base}/registration")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/registration/index')->where('studentAvailable', true));
        $keyA = (string) Str::uuid();
        $this->actingAs($studentA)->from("{$base}/registration")->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => $keyA])->assertSessionHasErrors('section_id');
        $holdId = (int) \DB::table('course_registration_holds')->where('tenant_id', $tenant->getKey())->where('student_id', $this->studentId($studentA))->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/holds/{$holdId}/revoke", ['reason' => 'Synthetic test hold resolved.'])->assertRedirect();
        $this->actingAs($studentA)->postJson("{$base}/registration/preview", ['section_id' => $section->getKey()])->assertOk()->assertJsonPath('preview.expected_status', 'confirmed');
        $this->assertSame(0, CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->count());
        $this->actingAs($studentA)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => $keyA])->assertRedirect();
        $enrollmentA = CourseEnrollment::query()->where('offering_id', $offeringId)->where('student_id', $this->studentId($studentA))->firstOrFail();
        $this->assertSame('confirmed', $enrollmentA->status);
        $this->assertDatabaseHas('billing_fee_assignments', [
            'tenant_id' => $tenant->getKey(), 'student_id' => $enrollmentA->student_id, 'course_enrollment_id' => $enrollmentA->getKey(),
            'fee_structure_id' => $billingStructureId, 'source' => 'enrollment', 'status' => 'assigned',
        ]);
        $this->assertSame(0, \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->count(), 'Enrollment assessment records an assignment; invoice issuance remains an explicit finance action.');
        $this->assertSame(0, $section->fresh()->availableSeats());
        $this->actingAs($studentA)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => $keyA])->assertRedirect();
        $this->assertSame(1, CourseEnrollment::query()->where('offering_id', $offeringId)->count());

        $keyB = (string) Str::uuid();
        $this->actingAs($studentB)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => $keyB])->assertRedirect();
        $enrollmentB = CourseEnrollment::query()->where('offering_id', $offeringId)->where('student_id', $this->studentId($studentB))->firstOrFail();
        $this->assertSame('waitlisted', $enrollmentB->status);
        $this->assertDatabaseHas('course_waitlist_entries', ['tenant_id' => $tenant->getKey(), 'student_id' => $enrollmentB->student_id, 'position' => 1, 'status' => 'waiting']);
        $this->actingAs($owner)->get("{$base}/registrar/enrollment")->assertOk()->assertInertia(fn ($page) => $page->has('waitlistEntries', 1)->where('waitlistEntries.0.position', 1)->where('waitlistEntries.0.status', 'waiting'));

        $this->actingAs($studentA)->delete("{$base}/registration/{$enrollmentA->getKey()}")->assertRedirect();
        $entry = CourseWaitlistEntry::query()->where('student_id', $enrollmentB->student_id)->firstOrFail();
        $this->assertSame('offer_pending', $enrollmentB->fresh()->status);
        $this->assertSame('offered', $entry->status);
        $this->assertNotNull($entry->offer_expires_at);
        $this->assertFalse(app(WaitlistNotificationOutbox::class)->record($entry->fresh(), $enrollmentB->fresh(), 'offer_created'));
        $this->actingAs($studentB)->post("{$base}/registration/waitlist/{$entry->getKey()}/accept")->assertRedirect();
        $this->assertSame('confirmed', $enrollmentB->fresh()->status);
        $this->assertSame('accepted', $entry->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.waitlist.offer_accepted']);

        $this->actingAs($studentA)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('waitlisted', $enrollmentA->fresh()->status);
        $this->actingAs($studentB)->delete("{$base}/registration/{$enrollmentB->getKey()}")->assertRedirect();
        $reinstatementOffer = CourseWaitlistEntry::query()->where('student_id', $enrollmentA->student_id)->firstOrFail();
        $this->assertSame('offer_pending', $enrollmentA->fresh()->status);
        $this->assertSame('offered', $reinstatementOffer->status);
        $this->actingAs($studentA)->post("{$base}/registration/waitlist/{$reinstatementOffer->getKey()}/accept")->assertRedirect();
        $this->assertSame('confirmed', $enrollmentA->fresh()->status);
        $this->assertDatabaseHas('course_enrollment_events', ['tenant_id' => $tenant->getKey(), 'enrollment_id' => $enrollmentA->getKey(), 'event_type' => 'waitlist_accepted']);

        app()->instance(AdmissionOutboxDispatcher::class, new class implements AdmissionOutboxDispatcher
        {
            public function dispatch(AdmissionOutboxEvent $outbox): void
            {
                throw new RuntimeException('Synthetic transient delivery failure.');
            }
        });
        $firstAttempt = app(AdmissionOutboxProcessor::class)->dispatchAvailable(10);
        $this->assertSame(4, $firstAttempt['retried']);
        $this->assertSame(0, $firstAttempt['processed']);
        $this->travel(3)->seconds();
        app()->instance(AdmissionOutboxDispatcher::class, app(LocalAdmissionOutboxDispatcher::class));
        // Queue workers have no request tenant context; the listener must resolve
        // membership explicitly from the tenant id carried by the durable event.
        app(TenantContext::class)->clear();
        $retry = app(AdmissionOutboxProcessor::class)->dispatchAvailable(10);
        $this->assertSame(4, $retry['processed']);
        $this->assertSame(2, \DB::table('admission_outbox_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'enrollment.waitlist.offer_accepted')->where('status', 'processed')->count());
        Notification::assertSentOnDemand(EnrollmentWaitlistNotification::class);
    }

    public function test_authorized_enrollment_reassessment_is_idempotent_and_preserves_issued_invoice_history(): void
    {
        [$tenant, $owner, $studentUser, $secondStudentUser, $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/billing/fee-heads", ['code' => 'REASSESS', 'name' => 'Reassessment fee', 'category' => 'tuition'])->assertSessionHasNoErrors();
        $headId = (int) \DB::table('billing_fee_heads')->where('tenant_id', $tenant->getKey())->value('id');
        foreach ([['OLD-REASSESS', 'Original fee', '80.00'], ['NEW-REASSESS', 'Updated fee', '120.00']] as [$code, $name, $amount]) {
            $this->actingAs($owner)->post("{$base}/billing/structures", ['code' => $code, 'name' => $name, 'currency' => 'USD', 'frequency' => 'one_time', 'effective_from' => today()->subYear()->toDateString(),
                'items' => [['fee_head_id' => $headId, 'unit_amount' => $amount, 'quantity' => '1']]])->assertSessionHasNoErrors();
        }
        $structures = \DB::table('billing_fee_structures')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->all();
        [$oldStructureId, $newStructureId] = array_map('intval', $structures);
        foreach ([$oldStructureId, $newStructureId] as $structureId) {
            $this->actingAs($owner)->post("{$base}/billing/structures/{$structureId}/activate")->assertSessionHasNoErrors();
        }
        $this->actingAs($owner)->put("{$base}/billing/enrollment-assessment", ['fee_structure_id' => $oldStructureId])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Reassessment policy', 'minimum_courses' => 0, 'maximum_courses' => 2, 'maximum_credits' => '', 'requires_approval' => false, 'waitlist_enabled' => false, 'waitlist_offer_hours' => 24])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", ['offering_code' => 'REASSESS-OFFER', 'academic_year_id' => $period->academic_year_id,
            'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person'])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => 'A', 'capacity' => 3, 'reserved_seats' => 0])->assertRedirect();
        $sectionId = (int) \DB::table('course_sections')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($studentUser)->post("{$base}/registration", ['section_id' => $sectionId, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($secondStudentUser)->post("{$base}/registration", ['section_id' => $sectionId, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $studentId = (int) \DB::table('sis_students')->where('tenant_id', $tenant->getKey())->where('user_id', $studentUser->getKey())->value('id');
        $secondStudentId = (int) \DB::table('sis_students')->where('tenant_id', $tenant->getKey())->where('user_id', $secondStudentUser->getKey())->value('id');
        $enrollmentId = (int) \DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->where('student_id', $studentId)->value('id');
        $secondEnrollmentId = (int) \DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->where('student_id', $secondStudentId)->value('id');
        $assignment = \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->where('course_enrollment_id', $enrollmentId)->first();
        $assignmentId = (int) $assignment->id;
        $secondAssignmentId = (int) \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->where('course_enrollment_id', $secondEnrollmentId)->value('id');
        $originalAssignmentSnapshot = (array) $assignment;
        $periodStart = Carbon::parse($assignment->period_start)->toDateString();
        $periodEnd = Carbon::parse($assignment->period_end)->toDateString();
        self::assertSame(today()->toDateString(), $periodStart);
        $this->actingAs($owner)->post("{$base}/billing/invoices", ['student_id' => $studentId, 'fee_structure_id' => $oldStructureId, 'fee_assignment_id' => $assignmentId,
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'due_on' => $periodStart, 'idempotency_key' => 'reassess-invoice-'.Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->where('fee_assignment_id', $assignmentId)->first();
        self::assertNotNull($invoice);
        $paymentBase = "{$base}/billing/payments";
        $this->actingAs($owner)->post($paymentBase, ['student_id' => $studentId, 'method' => 'cash', 'amount' => '30.00', 'currency' => 'USD',
            'reference' => 'REASSESS-PARTIAL', 'idempotency_key' => 'reassess-payment-'.Str::uuid(), 'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '30.00']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $partiallyPaidInvoice = \DB::table('billing_invoices')->where('id', $invoice->id)->first(['paid_amount', 'outstanding_amount', 'status']);
        self::assertSame('partially_paid', $partiallyPaidInvoice->status);
        self::assertSame('30.00', number_format((float) $partiallyPaidInvoice->paid_amount, 2, '.', ''));
        self::assertSame('50.00', number_format((float) $partiallyPaidInvoice->outstanding_amount, 2, '.', ''));
        $this->actingAs($owner)->post($paymentBase, ['student_id' => $studentId, 'method' => 'cash', 'amount' => '50.00', 'currency' => 'USD',
            'reference' => 'REASSESS-SETTLEMENT', 'idempotency_key' => 'reassess-settlement-'.Str::uuid(), 'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '50.00']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $invoiceSnapshot = \DB::table('billing_invoices')->where('id', $invoice->id)->first(['subtotal', 'total', 'outstanding_amount', 'paid_amount', 'status']);
        $invoiceSnapshot = array_values((array) $invoiceSnapshot);
        $invoiceItemsSnapshot = \DB::table('billing_invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        $paymentAllocationsSnapshot = \DB::table('billing_payment_allocations')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();

        // A future-dated placement moves the learner into a more-specific
        // course/class/section fee rule. Reassessment must evaluate that new scope.
        $transitionDate = today()->addDay()->toDateString();
        $institutionId = (int) \DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id');
        $unrelatedUser = User::factory()->create();
        $unrelatedStudentId = \DB::table('sis_students')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institutionId, 'user_id' => $unrelatedUser->getKey(),
            'student_number' => 'UNRELATED-'.Str::upper(Str::random(6)), 'registration_reference' => 'UNRELATED-'.Str::upper(Str::random(6)),
            'legal_first_name' => 'Unaffected', 'legal_last_name' => 'Learner', 'admission_date' => today()->toDateString(), 'status' => 'active',
            'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now()]);
        $classId = \DB::table('academic_classes')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institutionId,
            'academic_year_id' => $period->academic_year_id, 'group_type' => 'cohort', 'code' => 'REASSESS-'.Str::upper(Str::random(5)),
            'name' => 'Reassessment cohort', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $sectionId = \DB::table('academic_sections')->insertGetId(['tenant_id' => $tenant->getKey(), 'academic_class_id' => $classId,
            'code' => 'REASSESS-A', 'name' => 'Reassessment section', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$studentId, $secondStudentId] as $placedStudentId) {
            \DB::table('sis_student_placements')->insert(['tenant_id' => $tenant->getKey(), 'student_id' => $placedStudentId, 'institution_id' => $institutionId,
                'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(), 'academic_class_id' => $classId,
                'academic_section_id' => $sectionId, 'placement_type' => 'placement', 'status' => 'active', 'effective_from' => $transitionDate,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->post("{$base}/billing/fee-applicability", ['fee_structure_id' => $newStructureId, 'campus_id' => '',
            'academic_program_id' => '', 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(),
            'academic_class_id' => $classId, 'academic_section_id' => $sectionId, 'effective_from' => $transitionDate, 'effective_until' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $newRuleId = (int) \DB::table('billing_fee_applicability_rules')->where('tenant_id', $tenant->getKey())->where('academic_section_id', $sectionId)->value('id');
        $this->actingAs($owner)->patch("{$base}/billing/fee-applicability/{$newRuleId}/status", ['status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse($transitionDate)->startOfDay());
        $beforeBilledReassessment = \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->count();
        $this->actingAs($owner)->post("{$base}/billing/enrollment-assessment/{$enrollmentId}/reassess", ['reason' => 'Academic placement and fee rule changed'])
            ->assertSessionHasNoErrors()->assertSessionHas('billingReassessmentResult', static fn (array $result): bool => $result['outcome'] === 'financial_review_required'
                && $result['issued_obligation_preserved'] === true && $result['assignment_id'] === null);
        self::assertSame($beforeBilledReassessment, \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->count(),
            'An existing paid obligation requires finance review before a second billable assignment is created.');
        $beforeUnauthorized = \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->count();
        $this->actingAs($studentUser)->post("{$base}/billing/enrollment-assessment/{$enrollmentId}/reassess", ['reason' => 'Unauthorized request'])->assertForbidden();
        self::assertSame($beforeUnauthorized, \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->count());
        self::assertSame(1, \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'billing.enrollment_assessment.reassessed')->count(), 'Denied callers do not create reassessment audit events.');
        $this->actingAs($owner)->post("{$base}/billing/enrollment-assessment/{$secondEnrollmentId}/reassess", ['reason' => 'Unbilled learner eligibility changed'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/billing/enrollment-assessment/{$secondEnrollmentId}/reassess", ['reason' => 'Repeat the same eligibility review'])->assertSessionHasNoErrors();

        self::assertSame(1, \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->where('course_enrollment_id', $enrollmentId)->count());
        self::assertSame(2, \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->where('course_enrollment_id', $secondEnrollmentId)->count());
        self::assertSame(3, \DB::table('billing_fee_assignments')->where('tenant_id', $tenant->getKey())->count());
        $reassessedAssignment = \DB::table('billing_fee_assignments')->where('course_enrollment_id', $secondEnrollmentId)->where('id', '<>', $secondAssignmentId)->first();
        self::assertSame($newRuleId, (int) $reassessedAssignment->applicability_rule_id);
        $contextSnapshot = json_decode((string) $reassessedAssignment->context_snapshot, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($course->getKey(), (int) $contextSnapshot['academic_course_id']);
        self::assertSame($classId, (int) $contextSnapshot['academic_class_id']);
        self::assertSame($sectionId, (int) $contextSnapshot['academic_section_id']);
        self::assertSame($invoiceSnapshot, array_values((array) \DB::table('billing_invoices')->where('id', $invoice->id)->first(['subtotal', 'total', 'outstanding_amount', 'paid_amount', 'status'])));
        self::assertSame($invoiceItemsSnapshot, \DB::table('billing_invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all());
        self::assertSame($paymentAllocationsSnapshot, \DB::table('billing_payment_allocations')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all());
        self::assertSame(1, \DB::table('billing_invoices')->where('tenant_id', $tenant->getKey())->count(), 'Reassessment does not issue a duplicate invoice.');
        self::assertSame(2, \DB::table('billing_payments')->where('tenant_id', $tenant->getKey())->count());
        self::assertSame(2, \DB::table('billing_payment_allocations')->where('invoice_id', $invoice->id)->count());
        self::assertSame('paid', \DB::table('billing_invoices')->where('id', $invoice->id)->value('status'));
        self::assertSame(3, \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'billing.enrollment_assessment.reassessed')->count());
        self::assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'billing.enrollment_assessment.reassessed', 'subject_id' => (string) $enrollmentId]);
        $reviewEvent = \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'billing.enrollment_assessment.reassessed')
            ->where('subject_id', (string) $enrollmentId)->firstOrFail(['metadata']);
        $reviewMetadata = json_decode((string) $reviewEvent->metadata, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('financial_review_required', $reviewMetadata['outcome']);
        self::assertTrue($reviewMetadata['requires_financial_review']);
        $this->assertDatabaseHas('billing_fee_assignments', ['id' => $assignmentId, 'fee_structure_id' => $oldStructureId]);
        self::assertSame($originalAssignmentSnapshot, (array) \DB::table('billing_fee_assignments')->where('id', $assignmentId)->first(), 'The original assignment snapshot is immutable after reassessment.');
        self::assertSame(3, \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'billing.enrollment_assessment.assigned')->count());
        self::assertSame(0, \DB::table('billing_adjustments')->where('tenant_id', $tenant->getKey())->count(), 'Reassessment does not bypass the approved financial adjustment workflow.');
        self::assertSame(0, \DB::table('billing_fee_assignments')->where('student_id', $unrelatedStudentId)->count(), 'Other learner assessment state remains untouched.');
        self::assertSame(0, \DB::table('billing_invoices')->where('student_id', $unrelatedStudentId)->count(), 'Other learner financial state remains untouched.');

        $foreignTenant = $this->basicTenant('Foreign Reassessment Academy');
        $foreignOwnerId = (int) \DB::table('tenant_memberships')->where('tenant_id', $foreignTenant->getKey())->value('user_id');
        $foreignOwner = User::query()->findOrFail($foreignOwnerId);
        $protectedState = [
            \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->orderBy('id')->pluck('id')->all(),
            \DB::table('billing_invoices')->where('id', $invoice->id)->value('status'),
            \DB::table('billing_payment_allocations')->where('invoice_id', $invoice->id)->count(),
            \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->count(),
        ];
        $this->actingAs($foreignOwner)->post("/t/{$foreignTenant->slug}/billing/enrollment-assessment/{$enrollmentId}/reassess", ['reason' => 'Attempt foreign enrollment access'])->assertNotFound();

        $campusId = \DB::table('campuses')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institutionId,
            'code' => 'REASSESS-CAMPUS', 'name' => 'Reassessment Campus', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->update(['campus_id' => $campusId]);
        $this->actingAs($owner)->post("{$base}/billing/enrollment-assessment/{$enrollmentId}/reassess", ['reason' => 'Attempt foreign campus access'])->assertNotFound();
        self::assertSame($protectedState, [
            \DB::table('billing_fee_assignments')->where('course_enrollment_id', $enrollmentId)->orderBy('id')->pluck('id')->all(),
            \DB::table('billing_invoices')->where('id', $invoice->id)->value('status'),
            \DB::table('billing_payment_allocations')->where('invoice_id', $invoice->id)->count(),
            \DB::table('audit_events')->where('tenant_id', $tenant->getKey())->count(),
        ], 'Cross-tenant and cross-campus denials do not mutate assignments, payments, or audit history.');
    }

    public function test_student_cannot_register_using_another_tenants_section_or_access_another_students_enrollment(): void
    {
        [$tenant, $owner, $student, , $period, $course] = $this->fixture();
        $otherTenant = $this->basicTenant('Other Enrollment Tenant');
        $otherSectionId = $this->createOpenSection($otherTenant);

        $this->actingAs($student)->post("/t/{$tenant->slug}/registration", ['section_id' => $otherSectionId, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(0, CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->count());
        $this->assertSame(0, CourseEnrollment::query()->where('tenant_id', $otherTenant->getKey())->count());
        $this->actingAs($owner)->get("/t/{$tenant->slug}/registrar/enrollment")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/registration/registrar'));
    }

    public function test_instructors_can_only_view_rosters_for_sections_assigned_to_their_active_membership(): void
    {
        [$tenant, $owner, , , $period, $course] = $this->fixture();
        $teacher = User::factory()->create();
        $outsider = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            foreach ([$teacher, $outsider] as $identity) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $identity->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            }
        } finally {
            $context->clear();
        }
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Roster Policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 48])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'ROSTER-1', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(),
            'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => 'A', 'capacity' => 10])->assertRedirect();
        $sectionId = (int) \DB::table('course_sections')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/sections/{$sectionId}/instructors", ['user_id' => $teacher->getKey()])->assertRedirect();
        $this->actingAs($teacher)->get("{$base}/instructor/sections")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/registration/instructor-sections')->has('sections.data', 1));
        $this->actingAs($teacher)->get("{$base}/instructor/sections/{$sectionId}/roster")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/registration/roster'));
        $this->actingAs($teacher)->get("{$base}/instructor/sections/{$sectionId}/roster.csv")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($outsider)->get("{$base}/instructor/sections/{$sectionId}/roster")->assertForbidden();
        $this->actingAs($outsider)->get("{$base}/instructor/sections/{$sectionId}/roster.csv")->assertForbidden();
    }

    public function test_registrar_can_edit_offering_and_section_but_cannot_overbook_capacity_with_reservations(): void
    {
        [$tenant, $owner, $student, , $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Editable policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'EDIT-101', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(),
            'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offering = CourseOffering::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offering->getKey()}/sections", ['section_code' => 'EDIT-A', 'capacity' => 2])->assertRedirect();
        $section = CourseSection::query()->where('offering_id', $offering->getKey())->firstOrFail();
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offering->getKey()}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($student)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => (string) Str::uuid()])->assertRedirect();

        $institutionId = (int) \DB::table('institutions')->where('tenant_id', $tenant->getKey())->value('id');
        $levelId = AcademicLevel::query()->create(['institution_id' => $institutionId, 'name' => 'Secondary', 'code' => 'SEC', 'sequence' => 1, 'status' => 'active'])->getKey();
        \DB::table('enrollment_policy_academic_levels')->insert([
            'tenant_id' => $tenant->getKey(), 'policy_id' => $policyId, 'academic_level_id' => $levelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/policies/{$policyId}", [
            'name' => 'Editable policy interim', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24,
            'status' => 'active',
        ])->assertRedirect();
        $this->assertDatabaseHas('enrollment_policy_academic_levels', ['tenant_id' => $tenant->getKey(), 'policy_id' => $policyId, 'academic_level_id' => $levelId]);

        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/policies/{$policyId}", [
            'name' => 'Editable policy revised', 'minimum_courses' => 1, 'maximum_courses' => 5,
            'maximum_credits' => '18.000', 'requires_approval' => true, 'waitlist_enabled' => true,
            'waitlist_offer_hours' => 36, 'require_prerequisites' => true, 'require_withdrawal_approval' => false,
            'allow_section_transfer' => false, 'allow_late_registration' => true, 'waitlist_ordering' => 'fifo',
            'status' => 'active', 'academic_level_ids' => [],
        ])->assertRedirect();
        $this->assertDatabaseHas('enrollment_policies', [
            'id' => $policyId, 'tenant_id' => $tenant->getKey(), 'name' => 'Editable policy revised',
            'minimum_courses' => 1, 'maximum_courses' => 5, 'waitlist_offer_hours' => 36,
            'requires_approval' => true, 'allow_section_transfer' => false,
        ]);

        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offering->getKey()}", [
            'policy_id' => $policyId, 'delivery_mode' => 'hybrid', 'campus_id' => '',
        ])->assertRedirect();
        $this->assertSame('hybrid', $offering->fresh()->delivery_mode);
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/sections/{$section->getKey()}", [
            'capacity' => 2, 'reserved_seats' => 1, 'campus_id' => '', 'status' => 'open',
        ])->assertRedirect();
        $this->assertSame(0, $section->fresh()->availableSeats());
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/sections/{$section->getKey()}", [
            'capacity' => 1, 'reserved_seats' => 1, 'campus_id' => '', 'status' => 'open',
        ])->assertSessionHasErrors('capacity');
        $this->assertSame(2, (int) $section->fresh()->capacity);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.offering.updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.section.updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.policy.updated']);
    }

    public function test_withdrawal_policy_requires_registrar_decision_and_keeps_a_safe_event_history(): void
    {
        [$tenant, $owner, $studentA, , $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", [
            'name' => 'Withdrawal policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24, 'requires_approval' => true,
            'require_withdrawal_approval' => true,
        ])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'WITHDRAW-1', 'academic_year_id' => $period->academic_year_id,
            'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(),
            'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => 'A', 'capacity' => 5])->assertRedirect();
        $sectionId = (int) \DB::table('course_sections')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($studentA)->post("{$base}/registration", ['section_id' => $sectionId, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $enrollment = CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('pending', $enrollment->status);
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/requests/{$enrollment->getKey()}/decision", ['decision' => 'approve', 'reason' => 'Eligibility confirmed.'])->assertRedirect();
        $this->assertSame('confirmed', $enrollment->fresh()->status);
        $this->actingAs($studentA)->post("{$base}/registration/{$enrollment->getKey()}/withdraw", ['reason' => 'Learner requested withdrawal.'])->assertRedirect();
        $this->assertSame('pending_withdrawal', $enrollment->fresh()->status);
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/withdrawals/{$enrollment->getKey()}/decision", ['decision' => 'approve', 'reason' => 'Registrar approved request.'])->assertRedirect();
        $this->assertSame('withdrawn', $enrollment->fresh()->status);
        $this->assertDatabaseHas('course_enrollment_events', ['tenant_id' => $tenant->getKey(), 'enrollment_id' => $enrollment->getKey(), 'event_type' => 'withdrawal_approved', 'to_status' => 'withdrawn']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.withdrawal.approved']);
    }

    public function test_scheduled_waitlist_expiry_releases_seat_and_records_the_transition(): void
    {
        Notification::fake();
        [$tenant, $owner, $studentA, $studentB, $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Expiry policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 1])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'EXPIRY-1', 'academic_year_id' => $period->academic_year_id,
            'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(),
            'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offering = CourseOffering::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offering->getKey()}/sections", ['section_code' => 'EXP-A', 'capacity' => 1])->assertRedirect();
        $section = CourseSection::query()->where('offering_id', $offering->getKey())->firstOrFail();
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offering->getKey()}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($studentA)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($studentB)->post("{$base}/registration", ['section_id' => $section->getKey(), 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($studentA)->delete("{$base}/registration/".CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->where('student_id', $this->studentId($studentA))->value('id'))->assertRedirect();
        $entry = CourseWaitlistEntry::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('offered', $entry->status);

        $this->travelTo(Carbon::parse($entry->offer_expires_at)->addSecond());
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);
        try {
            $expired = app(CourseEnrollmentWorkflow::class)->expireWaitlistOffers();
            $this->assertSame(1, $expired);
            $this->assertSame('expired', $entry->fresh()->status);
            $this->assertSame('rejected', CourseEnrollment::query()->where('student_id', $this->studentId($studentB))->firstOrFail()->status);
            $this->assertDatabaseHas('course_enrollment_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'waitlist_offer_expired']);
            $this->assertDatabaseHas('admission_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.waitlist.offer_expired', 'status' => 'queued']);
            $delivery = app(AdmissionOutboxProcessor::class)->dispatchAvailable();
            $this->assertSame(2, $delivery['processed']);
            Notification::assertSentOnDemand(EnrollmentWaitlistNotification::class);
            $this->assertSame(1, $section->fresh()->availableSeats());
        } finally {
            $context->clear();
        }
    }

    public function test_section_transfer_keeps_original_seat_until_an_authorized_decision(): void
    {
        [$tenant, $owner, $student, , $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Transfer policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'TRANSFER-1', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(),
            'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person',
        ])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        foreach ([['A', 2], ['B', 2]] as [$code, $capacity]) {
            $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => $code, 'capacity' => $capacity])->assertRedirect();
        }
        $sections = CourseSection::query()->where('offering_id', $offeringId)->orderBy('id')->get();
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($student)->post("{$base}/registration", ['section_id' => $sections[0]->getKey(), 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $enrollment = CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->actingAs($student)->post("{$base}/registration/{$enrollment->getKey()}/transfer", ['section_id' => $sections[1]->getKey(), 'reason' => 'Schedule conflict requires transfer.'])->assertRedirect();
        $this->assertSame((int) $sections[0]->getKey(), (int) $enrollment->fresh()->section_id);
        $transferId = (int) \DB::table('course_section_transfers')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/transfers/{$transferId}/decision", ['decision' => 'approve', 'reason' => 'Capacity and eligibility confirmed.'])->assertRedirect();
        $this->assertSame((int) $sections[1]->getKey(), (int) $enrollment->fresh()->section_id);
        $this->assertDatabaseHas('course_section_transfers', ['tenant_id' => $tenant->getKey(), 'id' => $transferId, 'status' => 'approved']);
    }

    public function test_registrar_bulk_csv_requires_preview_and_confirmation_and_is_idempotent(): void
    {
        [$tenant, $owner, , , $period, $course] = $this->fixture();
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Bulk policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", ['offering_code' => 'BULK-1', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'in_person'])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => 'A', 'capacity' => 5])->assertRedirect();
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();
        $key = (string) Str::uuid();
        $upload = UploadedFile::fake()->createWithContent('batch.csv', "student_number,offering_code,section_code\n1001,BULK-1,A\n");
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/bulk/preview", ['file' => $upload, 'idempotency_key' => $key])->assertRedirect();
        $batchId = (int) \DB::table('course_enrollment_batches')->where('tenant_id', $tenant->getKey())->value('id');
        $this->assertDatabaseHas('course_enrollment_batches', ['id' => $batchId, 'status' => 'previewed', 'valid_count' => 1, 'error_count' => 0]);
        $this->assertSame(0, CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->count());
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/bulk/{$batchId}/confirm")->assertRedirect();
        $this->assertDatabaseHas('course_enrollment_batches', ['id' => $batchId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('course_enrollment_batch_rows', ['batch_id' => $batchId, 'status' => 'imported']);
        $this->assertSame(1, CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->count());
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/bulk/{$batchId}/confirm")->assertRedirect();
        $this->assertSame(1, CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_confirmed_enrollment_grants_only_existing_learning_permission_and_drop_revokes_assignment(): void
    {
        [$tenant, $owner, $student, , $period, $course] = $this->fixture();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $version = AcademicCourseVersion::query()->create([
                'academic_course_id' => $course->getKey(), 'version_number' => 1, 'title' => 'Published Version',
                'credit_bearing' => false, 'theory_credits' => 0, 'laboratory_credits' => 0, 'total_credits' => 0,
                'delivery_modes' => ['self_paced'], 'status' => 'published', 'is_current' => true,
            ]);
            $workspace = CourseDeliveryWorkspace::query()->create([
                'institution_id' => Institution::query()->firstOrFail()->getKey(), 'academic_course_id' => $course->getKey(),
                'academic_course_version_id' => $version->getKey(), 'public_id' => (string) Str::uuid(), 'code' => 'DELIVERY-01',
                'title' => 'Biology workspace', 'delivery_mode' => 'self_paced', 'status' => 'published', 'published_at' => now(), 'created_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }
        $base = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/policies", ['name' => 'Learning access policy', 'maximum_courses' => 4, 'waitlist_offer_hours' => 24])->assertRedirect();
        $policyId = (int) \DB::table('enrollment_policies')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings", [
            'offering_code' => 'ACCESS-1', 'academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(),
            'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'delivery_mode' => 'online', 'delivery_workspace_id' => $workspace->getKey(),
        ])->assertRedirect();
        $offeringId = (int) \DB::table('course_offerings')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$base}/registrar/enrollment/offerings/{$offeringId}/sections", ['section_code' => 'A', 'capacity' => 2])->assertRedirect();
        $sectionId = (int) \DB::table('course_sections')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->patch("{$base}/registrar/enrollment/offerings/{$offeringId}/status", ['status' => 'open'])->assertRedirect();
        $this->actingAs($student)->post("{$base}/registration", ['section_id' => $sectionId, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $membershipId = (int) TenantMembership::query()->where('user_id', $student->getKey())->value('id');
        $assignment = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('membership_id', $membershipId)->where('assignment_role', 'learner')->firstOrFail();
        $this->assertSame('active', $assignment->status);
        $enrollmentId = (int) CourseEnrollment::query()->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($student)->delete("{$base}/registration/{$enrollmentId}")->assertRedirect();
        $this->assertSame('revoked', $assignment->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'enrollment.learning_access.revoked']);
    }

    /** @return array{Tenant, User, User, User, AcademicPeriod, AcademicCourse} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $tenant = $this->provisionTenant('Enrollment Academy', $creator, $owner);
        $studentA = User::factory()->create();
        $studentB = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $ownerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $ownerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $ownerMembership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            foreach ([$studentA, $studentB] as $user) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            }
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => 'Current Year', 'code' => 'AY-TEST',
                'starts_on' => today()->subMonths(2), 'ends_on' => today()->addMonths(8), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active',
            ]);
            $period = AcademicPeriod::query()->create([
                'academic_year_id' => $year->getKey(), 'name' => 'Current Term', 'code' => 'TERM-TEST', 'period_type' => 'term', 'sequence' => 1,
                'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active',
            ]);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'BIO-101', 'title' => 'Biology Foundations', 'status' => 'active']);
            foreach ([[$studentA, '1001'], [$studentB, '1002']] as [$user, $number]) {
                SisStudent::query()->create([
                    'institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'student_number' => $number,
                    'registration_reference' => 'REF-'.$number, 'legal_first_name' => 'Test', 'legal_last_name' => 'Student',
                    'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard',
                ]);
            }
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $studentA, $studentB, $period, $course];
    }

    private function provisionTenant(string $name, User $creator, User $owner): Tenant
    {
        $result = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($name), 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '',
            'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);

        return $result->tenant;
    }

    private function basicTenant(string $name): Tenant
    {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $tenant = $this->provisionTenant($name, $creator, $owner);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            $suffix = strtoupper(Str::random(6));
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Foreign Year', 'code' => 'FY-'.$suffix, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Foreign Term', 'code' => 'FT-'.$suffix, 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'BIO-'.$suffix, 'title' => 'Biology', 'status' => 'active']);
            $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Policy', 'maximum_courses' => 4]);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'BIO-A', 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'section_code' => 'A', 'capacity' => 2, 'status' => 'open']);
        } finally {
            $context->clear();
        }

        return $tenant;
    }

    private function createOpenSection(Tenant $tenant): int
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Year', 'code' => 'Y1', 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Term', 'code' => 'T1', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'BIO-101', 'title' => 'Biology', 'status' => 'active']);
            $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Test Policy', 'maximum_courses' => 4]);
            $offering = CourseOffering::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(),
                'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'FOREIGN', 'status' => 'open',
            ]);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'section_code' => 'X', 'capacity' => 2, 'status' => 'open']);

            return (int) $section->getKey();
        } finally {
            $context->clear();
        }
    }

    private function studentId(User $user): int
    {
        return (int) SisStudent::query()->where('user_id', $user->getKey())->value('id');
    }
}
