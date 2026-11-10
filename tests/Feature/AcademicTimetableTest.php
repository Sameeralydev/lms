<?php

namespace Tests\Feature;

use App\Domain\Academic\Timetabling\TimetableConflictChecker;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class AcademicTimetableTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_institution_can_register_an_examination_room_with_a_capacity_for_seating_plans(): void
    {
        [$tenant, $owner] = $this->fixture();
        $campusId = (int) DB::table('campuses')->where('tenant_id', $tenant->getKey())->value('id');
        $base = "/t/{$tenant->slug}/academics/timetables";

        $this->actingAs($owner)->post("{$base}/rooms", [
            'campus_id' => $campusId,
            'code' => 'EXAM-HALL-A',
            'name' => 'Main Examination Hall',
            'room_type' => 'examination',
            'capacity' => 240,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('academic_rooms', [
            'tenant_id' => $tenant->getKey(),
            'campus_id' => $campusId,
            'code' => 'EXAM-HALL-A',
            'room_type' => 'examination',
            'capacity' => 240,
            'status' => 'active',
        ]);
    }

    public function test_timetable_conflicts_are_enforced_and_independent_approval_publishes_student_schedule(): void
    {
        [$tenant, $owner, $reviewer, $teacher, $student, $period, $section] = $this->fixture();
        $base = "/t/{$tenant->slug}/academics/timetables";
        $campusId = (int) DB::table('campuses')->where('tenant_id', $tenant->getKey())->value('id');

        $this->actingAs($owner)->post("{$base}/buildings", ['campus_id' => $campusId, 'code' => 'BLD-A', 'name' => 'Main Building'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/rooms", ['campus_id' => $campusId, 'building_id' => null, 'code' => 'R-101', 'name' => 'Room 101', 'room_type' => 'classroom', 'capacity' => 40, 'features' => ['projector']])->assertRedirect()->assertSessionHasNoErrors();
        $roomId = (int) DB::table('academic_rooms')->where('tenant_id', $tenant->getKey())->value('id');
        $starts = now()->addDays(7)->startOfWeek()->toDateString();
        $ends = now()->addDays(28)->endOfWeek()->toDateString();
        $this->actingAs($owner)->post($base, ['academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(), 'campus_id' => $campusId, 'name' => 'Autumn timetable', 'timezone' => 'UTC', 'independent_review_required' => true])->assertRedirect();
        $timetable = AcademicTimetable::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $meeting = ['section_id' => $section->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => $roomId, 'starts_on' => $starts, 'ends_on' => $ends, 'weekdays' => [1], 'starts_at_local' => '09:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person'];
        $this->actingAs($owner)->postJson("{$base}/{$timetable->getKey()}/conflicts", $meeting)->assertOk()->assertJsonCount(0, 'conflicts');
        $this->actingAs($owner)->post("{$base}/availability", ['campus_id' => $campusId, 'user_id' => $teacher->getKey(), 'weekdays' => [1], 'effective_from' => $starts, 'effective_until' => $ends, 'starts_at' => '09:00', 'ends_at' => '10:00', 'availability_type' => 'unavailable'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->postJson("{$base}/{$timetable->getKey()}/conflicts", $meeting)->assertOk()->assertJsonFragment(['type' => 'instructor_unavailable']);
        DB::table('academic_instructor_availabilities')->where('tenant_id', $tenant->getKey())->delete();
        $blockedStart = new \DateTimeImmutable($starts.'T09:15:00+00:00');
        $blockedEnd = new \DateTimeImmutable($starts.'T09:30:00+00:00');
        $this->actingAs($owner)->post("{$base}/room-blocks", ['room_id' => $roomId, 'starts_at_utc' => $blockedStart->format('Y-m-d\\TH:i:sP'), 'ends_at_utc' => $blockedEnd->format('Y-m-d\\TH:i:sP'), 'block_type' => 'maintenance', 'reason' => 'Projector repair'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->postJson("{$base}/{$timetable->getKey()}/conflicts", $meeting)->assertOk()->assertJsonFragment(['type' => 'room_unavailable']);
        DB::table('academic_room_blocks')->where('tenant_id', $tenant->getKey())->delete();
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", $meeting)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $timetable->meetings()->count());
        $this->actingAs($owner)->postJson("{$base}/{$timetable->getKey()}/conflicts", $meeting)->assertOk()->assertJsonFragment(['type' => 'section_double_booking'])->assertJsonFragment(['type' => 'instructor_double_booking'])->assertJsonFragment(['type' => 'room_double_booking']);
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", $meeting)->assertSessionHasErrors('conflicts');

        $studentProfile = SisStudent::query()->where('tenant_id', $tenant->getKey())->where('user_id', $student->getKey())->firstOrFail();
        CourseEnrollment::query()->create(['institution_id' => $timetable->institution_id, 'student_id' => $studentProfile->getKey(), 'offering_id' => $section->offering_id, 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'student', 'requested_at' => now(), 'confirmed_at' => now()]);
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/submit")->assertRedirect();
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/review", ['decision' => 'approve', 'note' => 'Reviewed room capacity, instructor, and period schedule.'])->assertForbidden();
        $this->actingAs($reviewer)->post("{$base}/{$timetable->getKey()}/review", ['decision' => 'approve', 'note' => 'Reviewed room capacity, instructor, and period schedule.'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/publish")->assertRedirect();
        $this->assertSame('published', $timetable->fresh()->status);
        $this->assertDatabaseHas('academic_timetable_snapshots', ['tenant_id' => $tenant->getKey(), 'timetable_id' => $timetable->getKey(), 'version' => 1]);
        $this->actingAs($student)->get("/t/{$tenant->slug}/schedule")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/my-schedule')->where('kind', 'student')->has('meetings', 1));
        $this->actingAs($student)->get("/t/{$tenant->slug}/schedule.ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('BEGIN:VEVENT');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.timetable.published']);
        $meetingId = (int) DB::table('academic_timetable_meetings')->where('timetable_id', $timetable->getKey())->value('id');
        $occurrences = array_keys(app(TimetableConflictChecker::class)->occurrences($timetable->meetings()->first()->toArray() + ['timezone' => 'UTC']));
        $this->assertGreaterThanOrEqual(3, count($occurrences));
        $occurrence = $occurrences[0];
        $sameSeriesTarget = $occurrences[1];
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $occurrence, 'replacement_date' => $sameSeriesTarget,
            'change_type' => 'reschedule', 'starts_at_local' => '09:00', 'reason' => 'Attempt to move onto an existing weekly occurrence.',
        ])->assertSessionHasErrors('replacement_date');
        $this->assertSame(0, DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->count());
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $occurrence, 'replacement_date' => now()->parse($occurrence)->addDay()->toDateString(),
            'change_type' => 'reschedule', 'starts_at_local' => '09:30', 'reason' => 'Room maintenance requires this class to move.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $changeId = (int) DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-changes/{$changeId}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/academics/timetable-changes/{$changeId}/decision", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_timetable_changes', ['id' => $changeId, 'status' => 'approved']);
        $this->assertSame(now()->parse($occurrence)->addDay()->toDateString(), Carbon::parse(DB::table('academic_timetable_changes')->where('id', $changeId)->value('replacement_date'))->toDateString());
        $this->assertDatabaseHas('admission_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.schedule.rescheduled', 'status' => 'queued']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.timetable.change_reviewed']);
        $replacementDate = now()->parse($occurrence)->addDay()->toDateString();
        $this->actingAs($student)->get("/t/{$tenant->slug}/schedule")->assertOk()->assertInertia(fn ($page) => $page->where('scheduleEvents.0.date', $replacementDate)->has('changeHistory', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/timetables/analytics")->assertOk()->assertInertia(fn ($page) => $page->where('metrics.published_timetables', 1)->where('metrics.published_meetings', 1)->where('metrics.approved_changes.reschedule', 1)->has('qualityFindings'));

        $cancelledDate = $occurrences[1];
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $cancelledDate, 'change_type' => 'cancellation', 'reason' => 'The campus has an approved closure on this class date.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $cancellationId = (int) DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->where('change_type', 'cancellation')->value('id');
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/academics/timetable-changes/{$cancellationId}/decision", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $makeupDate = Carbon::parse($cancelledDate)->addDays(2)->toDateString();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $cancelledDate, 'replacement_date' => $makeupDate, 'change_type' => 'makeup', 'starts_at_local' => '11:00', 'reason' => 'A makeup class is required for the canceled teaching day.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $makeupId = (int) DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->where('change_type', 'makeup')->value('id');
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/academics/timetable-changes/{$makeupId}/decision", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();

        $substitute = User::factory()->create();
        $tenantContext = app(TenantContext::class);
        $tenantContext->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $substituteMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $substitute->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $teacherRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
            $substituteMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $substitute->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $tenantContext->clear();
        }
        $substitutionDate = $occurrences[2];
        $substitutionWeekday = Carbon::parse($substitutionDate)->dayOfWeekIso;
        $this->actingAs($owner)->post("{$base}/availability", ['campus_id' => $campusId, 'user_id' => $substitute->getKey(), 'weekdays' => [$substitutionWeekday], 'effective_from' => $substitutionDate, 'effective_until' => $substitutionDate, 'starts_at' => '08:00', 'ends_at' => '12:00', 'availability_type' => 'unavailable'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $substitutionDate, 'replacement_date' => $substitutionDate, 'change_type' => 'substitution', 'starts_at_local' => '09:00', 'instructor_user_id' => $substitute->getKey(), 'reason' => 'Test rejecting an unavailable substitute assignment.',
        ])->assertSessionHasErrors('change');
        DB::table('academic_instructor_availabilities')->where('tenant_id', $tenant->getKey())->where('user_id', $substitute->getKey())->delete();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}/changes", [
            'occurrence_date' => $substitutionDate, 'replacement_date' => $substitutionDate, 'change_type' => 'substitution', 'starts_at_local' => '09:00', 'instructor_user_id' => $substitute->getKey(), 'reason' => 'The assigned instructor is unavailable for this class date.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $substitutionId = (int) DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->where('change_type', 'substitution')->value('id');
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/academics/timetable-changes/{$substitutionId}/decision", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admission_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.schedule.cancelled', 'status' => 'queued']);
        $this->assertDatabaseHas('admission_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.schedule.makeup_added', 'status' => 'queued']);
        $this->assertDatabaseHas('admission_outbox_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.schedule.substituted', 'status' => 'queued']);
        $this->actingAs($student)->get("/t/{$tenant->slug}/schedule")->assertOk()->assertInertia(fn ($page) => $page->where('scheduleEvents.0.date', $replacementDate)->where('scheduleEvents.1.date', $makeupDate)->where('scheduleEvents.2.instructor', $substitute->name)->has('changeHistory', 4));
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/revise")->assertRedirect()->assertSessionHasNoErrors();
        $revision = AcademicTimetable::query()->where('tenant_id', $tenant->getKey())->where('supersedes_timetable_id', $timetable->getKey())->firstOrFail();
        $this->assertSame(2, (int) $revision->version);
        $this->assertSame('draft', $revision->status);
        $this->assertSame(1, $revision->meetings()->count());
        $draftMeeting = $revision->meetings()->firstOrFail();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/academics/timetable-meetings/{$meetingId}", [
            'section_id' => $section->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => $roomId,
            'starts_on' => $starts, 'ends_on' => $ends, 'weekdays' => [1, 3], 'starts_at_local' => '11:00',
            'duration_minutes' => 50, 'delivery_mode' => 'in_person',
        ])->assertSessionHasErrors('meeting');
        $this->actingAs($owner)->put("/t/{$tenant->slug}/academics/timetable-meetings/{$draftMeeting->getKey()}", [
            'section_id' => $section->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => $roomId,
            'starts_on' => $starts, 'ends_on' => $ends, 'weekdays' => [1, 3], 'starts_at_local' => '11:00',
            'duration_minutes' => 50, 'delivery_mode' => 'in_person',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_timetable_meetings', ['id' => $meetingId, 'starts_at_local' => '09:00']);
        $this->assertSame([1, 3], $draftMeeting->fresh()->weekdays);
        $this->actingAs($owner)->patch("/t/{$tenant->slug}/academics/timetable-meetings/{$draftMeeting->getKey()}/series/cancel", ['reason' => 'The draft section was canceled after enrollment review.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_timetable_meetings', ['id' => $draftMeeting->getKey(), 'status' => 'cancelled']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.timetable.series_updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.timetable.series_cancelled']);
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/revise")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, AcademicTimetable::query()->where('tenant_id', $tenant->getKey())->where('supersedes_timetable_id', $timetable->getKey())->count());
    }

    public function test_timetable_routes_deny_wrong_role_and_tenant_references(): void
    {
        [$tenant, $owner, , $teacher, , $period] = $this->fixture();
        $another = $this->fixture('Other Schedule Tenant');
        $foreignSection = $another[6];
        $base = "/t/{$tenant->slug}/academics/timetables";
        $this->actingAs($teacher)->post("{$base}/buildings", ['campus_id' => null, 'code' => 'BAD', 'name' => 'Denied building'])->assertForbidden();
        $campusId = (int) DB::table('campuses')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post($base, ['academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(), 'campus_id' => $campusId, 'name' => 'Scoped timetable', 'timezone' => 'UTC'])->assertRedirect();
        $timetable = AcademicTimetable::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $foreignInstructor = $another[3];
        $meeting = ['section_id' => $foreignSection->getKey(), 'instructor_user_id' => $foreignInstructor->getKey(), 'room_id' => null, 'starts_on' => now()->addDays(7)->toDateString(), 'ends_on' => now()->addDays(7)->toDateString(), 'weekdays' => [(int) now()->addDays(7)->dayOfWeekIso], 'starts_at_local' => '10:00', 'duration_minutes' => 45, 'delivery_mode' => 'online', 'online_location' => 'https://example.test/room'];
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", $meeting)->assertNotFound();
        $this->assertSame(0, DB::table('academic_timetable_meetings')->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_timetable_authorization_fails_closed_for_guests_scopes_revoked_members_and_forged_resources(): void
    {
        [$tenant, $owner, $reviewer, $teacher, $student, $period, $section] = $this->fixture('Scheduling Security Academy');
        [$foreignTenant, $foreignOwner] = $this->fixture('Foreign Scheduling Academy');
        $base = "/t/{$tenant->slug}/academics/timetables";
        $schedule = "/t/{$tenant->slug}/schedule";

        $this->get($base)->assertRedirect('/login');
        $this->get($schedule.'.csv')->assertRedirect('/login');
        $this->get($schedule.'.ics')->assertRedirect('/login');

        $campusId = (int) DB::table('campuses')->where('tenant_id', $tenant->getKey())->value('id');
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $otherCampus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'OTHER', 'name' => 'Other Campus', 'status' => 'active', 'is_primary' => false]);
            $unassignedInstructor = User::factory()->create();
            $unassignedMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $unassignedInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $teacherRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
            $unassignedMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        $foreignCampusId = (int) DB::table('campuses')->where('tenant_id', $foreignTenant->getKey())->value('id');
        $foreignBase = "/t/{$foreignTenant->slug}/academics/timetables";
        $this->actingAs($foreignOwner)->post("{$foreignBase}/rooms", ['campus_id' => $foreignCampusId, 'code' => 'FOREIGN-ROOM', 'name' => 'Foreign Room', 'room_type' => 'classroom', 'capacity' => 50])->assertRedirect()->assertSessionHasNoErrors();
        $foreignRoomId = (int) DB::table('academic_rooms')->where('tenant_id', $foreignTenant->getKey())->value('id');

        DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->update(['campus_id' => $campusId]);
        $beforeBuildingCount = DB::table('academic_buildings')->where('tenant_id', $tenant->getKey())->count();
        $this->actingAs($owner)->post("{$base}/buildings", ['campus_id' => (int) $otherCampus->getKey(), 'code' => 'OUT-OF-SCOPE', 'name' => 'Out of Scope Building'])->assertNotFound();
        $this->assertSame($beforeBuildingCount, DB::table('academic_buildings')->where('tenant_id', $tenant->getKey())->count());

        $this->actingAs($owner)->post($base, ['academic_year_id' => $period->academic_year_id, 'academic_period_id' => $period->getKey(), 'campus_id' => $campusId, 'name' => 'Authorization Draft', 'timezone' => 'UTC', 'independent_review_required' => true])->assertRedirect();
        $timetable = AcademicTimetable::query()->where('tenant_id', $tenant->getKey())->where('name', 'Authorization Draft')->firstOrFail();
        $starts = now()->addDays(14)->startOfWeek()->toDateString();
        $ends = now()->addDays(28)->endOfWeek()->toDateString();
        $this->actingAs($owner)->post("{$base}/rooms", ['campus_id' => $campusId, 'code' => 'LOCAL-ROOM', 'name' => 'Local Room', 'room_type' => 'classroom', 'capacity' => 50])->assertRedirect()->assertSessionHasNoErrors();
        $localRoomId = (int) DB::table('academic_rooms')->where('tenant_id', $tenant->getKey())->where('code', 'LOCAL-ROOM')->value('id');
        $meeting = ['section_id' => $section->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => $localRoomId, 'starts_on' => $starts, 'ends_on' => $ends, 'weekdays' => [1], 'starts_at_local' => '10:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person'];

        $this->actingAs($teacher)->post("{$base}/{$timetable->getKey()}/publish")->assertForbidden();
        $this->assertDatabaseHas('academic_timetables', ['id' => $timetable->getKey(), 'status' => 'draft']);
        $this->actingAs($teacher)->postJson("{$base}/{$timetable->getKey()}/conflicts", $meeting)->assertForbidden();
        $this->actingAs($teacher)->post("/t/{$tenant->slug}/academics/timetable-meetings/999999/changes", ['occurrence_date' => $starts, 'replacement_date' => $starts, 'change_type' => 'substitution', 'reason' => 'Unauthorized substitution attempt.'])->assertForbidden();
        $this->assertSame(0, DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->count());

        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", [...$meeting, 'room_id' => $foreignRoomId])->assertSessionHasErrors('room_id');
        $this->assertSame(0, $timetable->meetings()->count());
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", [...$meeting, 'instructor_user_id' => $unassignedInstructor->getKey()])->assertSessionHasErrors('instructor_user_id');
        $this->assertSame(0, $timetable->meetings()->count());

        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/meetings", $meeting)->assertRedirect()->assertSessionHasNoErrors();
        $meetingId = (int) $timetable->meetings()->value('id');
        $forgedChangeCount = DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->count();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/timetable-meetings/999999/changes", ['occurrence_date' => $starts, 'replacement_date' => $starts, 'change_type' => 'makeup', 'reason' => 'Forged meeting reference attempt.'])->assertNotFound();
        $this->assertSame($forgedChangeCount, DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->count());

        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/submit")->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/{$timetable->getKey()}/review", ['decision' => 'approve', 'note' => 'The independent reviewer checked the schedule.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("{$base}/{$timetable->getKey()}/publish")->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($student)->get($schedule)->assertOk()->assertInertia(fn ($page) => $page->where('scheduleEvents', [])->has('meetings', 0));
        $this->actingAs($student)->get($schedule.'.csv')->assertOk()->assertDontSee('Scheduling Course');
        $this->actingAs($student)->get($schedule.'.ics')->assertOk()->assertDontSee('BEGIN:VEVENT');
        $this->actingAs($owner)->get("/t/{$foreignTenant->slug}/academics/timetables")->assertNotFound();

        DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $teacher->getKey())->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($teacher)->get($base)->assertNotFound();
        $this->actingAs($teacher)->get($schedule.'.csv')->assertNotFound();
        $this->assertDatabaseHas('academic_timetable_meetings', ['id' => $meetingId, 'timetable_id' => $timetable->getKey(), 'status' => 'scheduled']);
        $this->assertSame(0, DB::table('academic_timetable_changes')->where('tenant_id', $tenant->getKey())->count());
    }

    /** @return array{Tenant,User,User,User,User,AcademicPeriod,CourseSection} */
    private function fixture(string $name = 'Scheduling Academy'): array
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $teacher = User::factory()->create();
        $student = User::factory()->create();
        $creator = User::factory()->create();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        $provisioned = app(TenantProvisioner::class)->provision(['slug' => $slug, 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en'], $creator, $owner->email);
        $tenant = $provisioned->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Main Campus', 'status' => 'active', 'is_primary' => true]);
            foreach ([[$owner, 'tenant.owner'], [$reviewer, 'academic.admin'], [$teacher, 'teacher'], [$student, 'student']] as [$user, $roleKey]) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            }
            $start = now()->subMonth()->toDateString();
            $finish = now()->addMonths(3)->toDateString();
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Schedule Year', 'code' => 'AY-'.Str::upper(Str::random(6)), 'starts_on' => $start, 'ends_on' => $finish, 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Schedule Term', 'code' => 'T-'.Str::upper(Str::random(6)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => $start, 'ends_on' => $finish, 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'SCHED-'.Str::upper(Str::random(6)), 'title' => 'Scheduling Course', 'status' => 'active']);
            $policyId = DB::table('enrollment_policies')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'name' => 'Schedule Policy', 'maximum_courses' => 8, 'created_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            $offeringId = DB::table('course_offerings')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'offering_code' => 'SO-'.Str::upper(Str::random(6)), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            $section = CourseSection::query()->create(['offering_id' => $offeringId, 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 40, 'reserved_seats' => 0, 'status' => 'open']);
            DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $student->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(8)), 'registration_reference' => 'REF-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Schedule', 'legal_last_name' => 'Learner', 'admission_date' => now()->subYear()->toDateString(), 'status' => 'active', 'student_type' => 'standard']);
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $reviewer, $teacher, $student, $period, $section];
    }
}
