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
