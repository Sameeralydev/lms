<?php

namespace Tests\Feature;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Analytics\AcademicAnalyticsScheduleDispatcher;
use App\Domain\Analytics\AcademicMetricRegistry;
use App\Domain\Analytics\AcademicReportBuilder;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Jobs\GenerateAcademicAnalyticsReport;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\AcademicAnalyticsReportReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class Phase15AcademicAnalyticsTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_authorized_academic_operator_sees_governed_real_data_metrics_and_export(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Analytics Academy');
        $campus = $this->createCampus($tenant, 'ANL-NORTH');
        $definitions = AcademicMetricRegistry::definitions();

        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?campus_id={$campus->getKey()}&from=2026-10-01&to=2026-11-11")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/analytics/index')
            ->has('metrics', 12)
            ->where('metrics.0.key', 'students.active')
            ->where('metrics.3.value', null)
            ->where('filters.campus_id', (int) $campus->getKey())
            ->where('campuses.0.id', (int) $campus->getKey())
            ->where('metrics.7.key', 'assignments.published')
            ->where('metrics.7.value', 0)
            ->where('metrics.8.key', 'assignments.submissions')
            ->where('metrics.8.value', 0)
            ->where('metrics.9.key', 'quizzes.published')
            ->where('metrics.9.value', 0)
            ->where('metrics.10.key', 'exams.sessions.published')
            ->where('metrics.10.value', 0)
            ->where('metrics.11.key', 'exams.results.released')
            ->where('metrics.11.value', 0)
            ->where('permissions.export', true));

        $csv = $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics.csv?campus_id={$campus->getKey()}")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('students.active', $csv->streamedContent());
        self::assertStringContainsString('attendance_entries|attendance_registers|attendance_status_definitions', $csv->streamedContent());
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?metrics%5B0%5D=attendance.rate")
            ->assertOk()->assertInertia(fn ($page) => $page->has('metrics', 1)->where('metrics.0.key', 'attendance.rate')->where('metric_options', fn ($options) => count($options) === 12));
        $this->actingAs($owner)->getJson("/t/{$tenant->slug}/academics/analytics?metrics%5B0%5D=unknown.metric")->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.analytics.exported']);
        self::assertCount(12, $definitions);
        foreach ($definitions as $definition) {
            self::assertNotEmpty($definition['definition']);
            self::assertNotEmpty($definition['sources']);
            self::assertSame('academic.analytics.view', $definition['allowed_permission']);
        }
    }

    public function test_analytics_denies_unprivileged_users_and_fails_closed_for_foreign_campus_filters(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Scoped Analytics Academy');
        $campus = $this->createCampus($tenant, 'ANL-SCOPE');
        $student = User::factory()->create();
        $membership = $this->createMember($tenant, $student, 'student');
        self::assertFalse($membership->hasPermission('academic.analytics.view'));

        $this->actingAs($student)->get("/t/{$tenant->slug}/academics/analytics")->assertForbidden();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?campus_id=99999999")->assertNotFound();

        $campusAdmin = User::factory()->create();
        $campusMembership = $this->createMember($tenant, $campusAdmin, 'campus.admin', (int) $campus->getKey());
        self::assertTrue($campusMembership->hasPermission('academic.analytics.view'));
        $this->actingAs($campusAdmin)->get("/t/{$tenant->slug}/academics/analytics?campus_id=99999998")->assertNotFound();
        $this->actingAs($campusAdmin)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments&dimensions%5B0%5D=status&campus_id=99999998")->assertNotFound();
        $this->actingAs($campusAdmin)->get("/t/{$tenant->slug}/academics/analytics")->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.campus_id', (int) $campus->getKey())->has('campuses', 1));
    }

    public function test_report_catalog_defaults_to_and_only_exposes_sources_granted_to_the_membership(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Report Catalog Authorization Academy');
        $admissionsUser = User::factory()->create();
        $membership = $this->createMember($tenant, $admissionsUser, 'admissions.officer');
        $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'admissions.officer')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['academic.view', 'academic.analytics.view', 'academic.analytics.export', 'academic.analytics.reports.manage'])->pluck('id')->all());

        self::assertTrue($membership->fresh()->hasPermission('academic.analytics.view'));
        self::assertTrue($membership->hasPermission('admissions.applications.view'));
        self::assertFalse($membership->hasPermission('enrollment.registrar.view'));

        $this->actingAs($admissionsUser)->get("/t/{$tenant->slug}/academics/analytics")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('builder_report.dataset', 'admissions')
            ->has('builder_report.definitions.admissions')
            ->missing('builder_report.definitions.enrollments'));
        $this->actingAs($admissionsUser)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments")
            ->assertForbidden();
        $this->actingAs($admissionsUser)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed")
            ->assertForbidden();
    }

    public function test_gpa_analytics_uses_the_latest_snapshot_for_active_policies_and_drilldown_reconciles(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'GPA Analytics Academy');
        $campus = $this->createCampus($tenant, 'ANL-GPA');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $studentId = DB::table('sis_students')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'home_campus_id' => $campus->getKey(),
                'student_number' => 'ANL-GPA-01', 'registration_reference' => 'ANL-GPA-REF-01', 'legal_first_name' => 'Gpa', 'legal_last_name' => 'Learner',
                'admission_date' => today()->toDateString(), 'status' => 'active', 'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $policyId = DB::table('academic_gpa_policies')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'policy_key' => 'ANL-GPA', 'version' => 1,
                'name' => 'Analytics GPA policy', 'repeat_rule' => 'highest', 'exclude_pass_fail' => true, 'status' => 'active',
                'created_by' => $owner->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $oldSnapshotId = DB::table('academic_gpa_snapshots')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'student_id' => $studentId, 'policy_id' => $policyId,
                'academic_period_id' => null, 'calculation_type' => 'cumulative', 'scope_key' => 'cumulative', 'snapshot_number' => 1,
                'gpa' => '2.100', 'quality_points' => '21.000', 'attempted_credits' => '10.000', 'academic_standing' => 'Developing',
                'source_hash' => hash('sha256', 'old'), 'supersedes_id' => null, 'calculated_by' => $owner->getKey(), 'calculated_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
            ]);
            $latestSnapshotId = DB::table('academic_gpa_snapshots')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'student_id' => $studentId, 'policy_id' => $policyId,
                'academic_period_id' => null, 'calculation_type' => 'cumulative', 'scope_key' => 'cumulative', 'snapshot_number' => 2,
                'gpa' => '3.200', 'quality_points' => '32.000', 'attempted_credits' => '10.000', 'academic_standing' => 'Good',
                'source_hash' => hash('sha256', 'latest'), 'supersedes_id' => $oldSnapshotId, 'calculated_by' => $owner->getKey(), 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=gpa_snapshots&report_measure=average_gpa&dimensions%5B0%5D=standing&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('builder_report.total', 1)->where('builder_report.rows.0.standing', 'Good')->where('builder_report.rows.0.total', 3.2));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=gpa_snapshots&dimension=standing&value=Good&report_measure=records&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 1)->where('report.rows.0.record_id', $latestSnapshotId)->where('report.rows.0.measure_value', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=gpa_snapshots&dimension=standing&value=Good&report_measure=average_gpa&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 1)->where('report.rows.0.measure_value', 3.2));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=gpa_snapshots&dimension=standing&value=Good&report_measure=unique_learners&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 1)->where('report.rows.0.record_id', $studentId));
        $this->assertDatabaseHas('academic_gpa_snapshots', ['id' => $oldSnapshotId, 'gpa' => '2.100']);
    }

    public function test_campus_student_metric_prefers_current_placement_and_falls_back_to_home_campus(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Placement Analytics Academy');
        $home = $this->createCampus($tenant, 'ANL-HOME');
        $current = $this->createCampus($tenant, 'ANL-CURRENT');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();

        $movedStudent = DB::table('sis_students')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'home_campus_id' => $home->getKey(),
            'student_number' => 'ANL-0001', 'registration_reference' => 'ANL-REF-0001', 'legal_first_name' => 'Moved', 'legal_last_name' => 'Learner',
            'admission_date' => today()->toDateString(), 'status' => 'active', 'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sis_student_placements')->insert([
            'tenant_id' => $tenant->getKey(), 'student_id' => $movedStudent, 'institution_id' => $membership->institution_id,
            'campus_id' => $current->getKey(), 'placement_type' => 'placement', 'status' => 'active', 'effective_from' => today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sis_students')->insert([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'home_campus_id' => $home->getKey(),
            'student_number' => 'ANL-0002', 'registration_reference' => 'ANL-REF-0002', 'legal_first_name' => 'Home', 'legal_last_name' => 'Learner',
            'admission_date' => today()->toDateString(), 'status' => 'active', 'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?campus_id={$home->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('metrics.0.value', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?campus_id={$current->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('metrics.0.value', 1));
    }

    public function test_allowlisted_report_sources_execute_and_pdf_and_drilldown_are_real_scoped_outputs(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Analytics Catalog Academy');
        $campus = $this->createCampus($tenant, 'ANL-CATALOG');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $builder = app(AcademicReportBuilder::class);

        foreach (AcademicReportBuilder::definitions() as $dataset => $definition) {
            $dimension = array_key_first($definition['dimensions']);
            $measure = array_key_first($definition['measures']);
            $report = $builder->build($membership, ['dataset' => $dataset, 'measure' => $measure, 'dimensions' => [$dimension]]);
            self::assertSame($dataset, $report['dataset']);
            self::assertSame([], $report['rows']);
        }

        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/report-builder.pdf?dataset=enrollments&dimensions%5B0%5D=status&campus_id={$campus->getKey()}")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4', false)->assertSee('Course enrollments', false)->assertSee('Analytics Catalog Academy', false)->assertSee('ANL-CATALOG Campus', false);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/analytics/drilldown')->where('report.total', 0));

        $student = User::factory()->create();
        $this->createMember($tenant, $student, 'student');
        $this->actingAs($student)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed")
            ->assertForbidden();
    }

    public function test_report_schedules_are_permission_scoped_editable_and_have_timezone_aware_runs(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Analytics Schedule Academy');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/analytics/reports", [
            'name' => 'Weekly enrollments', 'dataset' => 'enrollments', 'report_measure' => 'records', 'dimensions' => ['status'],
        ])->assertRedirect();
        $saved = DB::table('academic_analytics_saved_reports')->where('tenant_id', $tenant->getKey())->where('membership_id', $membership->getKey())->first();
        self::assertNotNull($saved);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/analytics/schedules", [
            'saved_report_id' => $saved->id, 'name' => 'Monday summary', 'frequency' => 'weekly', 'timezone' => 'Asia/Karachi', 'local_time' => '08:30', 'weekday' => 1,
            'recipients' => [$membership->getKey()],
        ])->assertRedirect()->assertSessionHas('status', 'Scheduled report created.');
        $schedule = DB::table('academic_analytics_report_schedules')->where('tenant_id', $tenant->getKey())->where('membership_id', $membership->getKey())->first();
        self::assertNotNull($schedule);
        self::assertSame('active', $schedule->status);
        self::assertSame('Asia/Karachi', $schedule->timezone);
        self::assertSame(1, DB::table('academic_analytics_report_schedule_recipients')->where('schedule_id', $schedule->id)->count());
        self::assertGreaterThan(now()->timestamp, strtotime((string) $schedule->next_run_at));

        $this->actingAs($owner)->put("/t/{$tenant->slug}/academics/analytics/schedules/{$schedule->id}", [
            'saved_report_id' => $saved->id, 'name' => 'Revised Monday summary', 'frequency' => 'weekly', 'timezone' => 'Asia/Karachi', 'local_time' => '09:15', 'weekday' => 1,
        ])->assertRedirect()->assertSessionHas('status', 'Report schedule saved.');
        $this->assertDatabaseHas('academic_analytics_report_schedules', ['id' => $schedule->id, 'name' => 'Revised Monday summary', 'local_time' => '09:15']);

        Storage::fake('local');
        Queue::fake();
        DB::table('academic_analytics_report_schedules')->where('id', $schedule->id)->update(['next_run_at' => now()->subMinute()]);
        self::assertEquals(1, app(AcademicAnalyticsScheduleDispatcher::class)->dispatchDue());
        $run = DB::table('academic_analytics_report_runs')->where('schedule_id', $schedule->id)->first();
        self::assertNotNull($run);
        self::assertSame('queued', $run->status);
        $runId = (int) $run->id;
        Queue::assertPushed(GenerateAcademicAnalyticsReport::class, fn (GenerateAcademicAnalyticsReport $job): bool => $job->runId === $runId);
        app(GenerateAcademicAnalyticsReport::class, ['runId' => $runId])->handle(
            app(TenantContext::class), app(AcademicReportBuilder::class), app(AcademicRecordPdf::class), app(AuditRecorder::class),
        );
        $this->assertDatabaseHas('academic_analytics_report_runs', ['id' => $runId, 'status' => 'completed', 'recipient_count' => 1]);
        $scheduledPdfPath = "analytics/{$tenant->getKey()}/{$schedule->id}/{$runId}.pdf";
        Storage::disk('local')->assertExists($scheduledPdfPath);
        self::assertStringContainsString('Analytics Schedule Academy', Storage::disk('local')->get($scheduledPdfPath));
        self::assertEquals(1, $owner->notifications()->where('type', AcademicAnalyticsReportReady::class)->count());
        app(GenerateAcademicAnalyticsReport::class, ['runId' => $runId])->handle(
            app(TenantContext::class), app(AcademicReportBuilder::class), app(AcademicRecordPdf::class), app(AuditRecorder::class),
        );
        self::assertEquals(1, $owner->notifications()->where('type', AcademicAnalyticsReportReady::class)->count());

        $student = User::factory()->create();
        $this->createMember($tenant, $student, 'student');
        $this->actingAs($student)->post("/t/{$tenant->slug}/academics/analytics/schedules", [
            'saved_report_id' => $saved->id, 'name' => 'Unauthorized', 'frequency' => 'daily', 'timezone' => 'UTC', 'local_time' => '08:30',
        ])->assertForbidden();
    }

    public function test_analytics_reports_are_saved_applied_and_deleted_only_by_their_membership_owner(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Saved Analytics Academy');
        $campus = $this->createCampus($tenant, 'ANL-SAVED');
        $url = "/t/{$tenant->slug}/academics/analytics";

        $this->actingAs($owner)->post("{$url}/reports", [
            'name' => 'Autumn activity', 'campus_id' => $campus->getKey(), 'from' => '2026-10-01', 'to' => '2026-11-13', 'metrics' => ['attendance.rate', 'quizzes.published'],
            'dataset' => 'enrollments', 'report_measure' => 'unique_learners', 'dimensions' => ['status', 'campus'], 'report_status' => 'confirmed', 'report_search' => 'North', 'report_sort' => 'total', 'report_direction' => 'desc',
        ])->assertRedirect($url);
        $report = DB::table('academic_analytics_saved_reports')->where('tenant_id', $tenant->getKey())->where('name', 'Autumn activity')->first();
        self::assertNotNull($report);
        self::assertSame($campus->getKey(), $report->campus_id);
        self::assertSame(['status', 'campus'], json_decode($report->filters, true, flags: JSON_THROW_ON_ERROR)['builder']['dimensions']);
        self::assertSame('unique_learners', json_decode($report->filters, true, flags: JSON_THROW_ON_ERROR)['builder']['measure']);
        self::assertSame('academic.analytics.report.saved', DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'academic.analytics.report.saved')->value('event_type'));

        $member = User::factory()->create();
        $this->createMember($tenant, $member, 'academic.admin');
        $this->actingAs($member)->get("{$url}/reports/{$report->id}")->assertNotFound();
        $applied = $this->actingAs($owner)->get("{$url}/reports/{$report->id}")->assertRedirect();
        self::assertStringContainsString('campus_id='.$campus->getKey(), (string) $applied->headers->get('Location'));
        self::assertStringContainsString('dataset=enrollments', (string) $applied->headers->get('Location'));
        self::assertStringContainsString('report_measure=unique_learners', (string) $applied->headers->get('Location'));
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('saved_reports.0.name', 'Autumn activity')->where('permissions.manage_reports', true));
        $this->actingAs($owner)->delete("{$url}/reports/{$report->id}")->assertRedirect($url);
        $this->assertDatabaseMissing('academic_analytics_saved_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.analytics.report.deleted']);
    }

    public function test_saved_analytics_reports_enforce_permission_scoped_filters_and_unique_names(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Saved Analytics Security Academy');
        $campus = $this->createCampus($tenant, 'ANL-SAFE');
        $url = "/t/{$tenant->slug}/academics/analytics/reports";
        $this->actingAs($owner)->post($url, ['name' => 'Scoped report', 'campus_id' => 999999, 'metrics' => ['attendance.rate']])->assertNotFound();
        $this->actingAs($owner)->post($url, ['name' => 'Bad metric', 'campus_id' => $campus->getKey(), 'metrics' => ['unregistered.metric']])->assertSessionHasErrors('metrics.0');
        $this->actingAs($owner)->post($url, ['name' => 'Scoped report', 'campus_id' => $campus->getKey(), 'metrics' => ['attendance.rate']])->assertRedirect();
        $this->actingAs($owner)->post($url, ['name' => 'Scoped report', 'campus_id' => $campus->getKey(), 'metrics' => ['attendance.rate']])->assertSessionHasErrors('name');
        $student = User::factory()->create();
        $this->createMember($tenant, $student, 'student');
        $this->actingAs($student)->post($url, ['name' => 'Unauthorized', 'metrics' => ['attendance.rate']])->assertForbidden();
        $this->assertDatabaseMissing('academic_analytics_saved_reports', ['tenant_id' => $tenant->getKey(), 'name' => 'Unauthorized']);
    }

    public function test_saved_report_quota_is_enforced_before_persisting_or_auditing_an_extra_report(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Saved Analytics Quota Academy');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 20; $index++) {
            $rows[] = [
                'tenant_id' => $tenant->getKey(),
                'institution_id' => $membership->institution_id,
                'membership_id' => $membership->getKey(),
                'name' => 'Quota report '.$index,
                'filters' => json_encode(['campus_id' => null, 'from' => null, 'to' => null, 'metrics' => null], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('academic_analytics_saved_reports')->insert($rows);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/analytics/reports", [
            'name' => 'Quota overflow',
            'metrics' => ['attendance.rate'],
        ])->assertSessionHasErrors('name');

        self::assertSame(20, DB::table('academic_analytics_saved_reports')->where('membership_id', $membership->getKey())->count());
        $this->assertDatabaseMissing('academic_analytics_saved_reports', ['membership_id' => $membership->getKey(), 'name' => 'Quota overflow']);
        $this->assertDatabaseMissing('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.analytics.report.saved', 'subject_id' => DB::table('academic_analytics_saved_reports')->where('name', 'Quota overflow')->value('id')]);
    }

    public function test_report_builder_executes_only_curated_cross_domain_datasets_and_dimensions(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Curated Reports Academy');
        $cases = [
            'enrollments' => 'source',
            'admissions' => 'eligibility',
            'attendance' => 'date',
            'exam_sessions' => 'date',
        ];

        foreach ($cases as $dataset => $dimension) {
            $response = $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset={$dataset}&dimensions%5B0%5D={$dimension}");
            $response->assertOk()->assertInertia(fn ($page) => $page
                ->where('builder_report.dataset', $dataset)
                ->where('builder_report.dimensions.0', $dimension)
                ->where('builder_report.total', 0)
                ->has('builder_report.rows', 0));
        }

        $this->actingAs($owner)->getJson("/t/{$tenant->slug}/academics/analytics?dataset=users&dimensions%5B0%5D=email")->assertUnprocessable();
        $this->actingAs($owner)->getJson("/t/{$tenant->slug}/academics/analytics?dataset=attendance&dimensions%5B0%5D=student_id")->assertUnprocessable();
    }

    public function test_assignment_metrics_count_only_published_work_and_submitted_attempts_in_the_selected_campus(): void
    {
        $owner = User::factory()->create();
        $studentUser = User::factory()->create();
        $tenant = $this->provision($owner, 'Coursework Analytics Academy');
        $campus = $this->createCampus($tenant, 'ANL-COURSE');
        $otherCampus = $this->createCampus($tenant, 'ANL-OTHER');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $configuration = AcademicConfiguration::query()->firstOrFail();
            $features = AcademicDefaults::present($configuration)['enabled_features'];
            $features['catalog'] = true;
            $configuration->forceFill(['enabled_features' => $features])->save();
            $year = AcademicYear::query()->create(['institution_id' => $membership->institution_id, 'name' => 'Analytics year', 'code' => 'AY-ANALYTICS', 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Analytics term', 'code' => 'TERM-ANALYTICS', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $membership->institution_id, 'code' => 'ANL-101', 'title' => 'Analytics coursework', 'status' => 'active']);
            $policy = EnrollmentPolicy::query()->create(['institution_id' => $membership->institution_id, 'name' => 'Analytics policy', 'maximum_courses' => 4, 'created_by' => $owner->getKey()]);
            $offering = CourseOffering::query()->create(['institution_id' => $membership->institution_id, 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'ANL-101-A', 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $owner->getKey()]);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
            $foreignSection = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $otherCampus->getKey(), 'section_code' => 'B', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
            $studentMembership = $this->createMember($tenant, $studentUser, 'student');
            $context->set($tenant, $membership);
            $student = DB::table('sis_students')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'home_campus_id' => $campus->getKey(),
                'user_id' => $studentUser->getKey(), 'student_number' => 'ANL-STUDENT-01', 'registration_reference' => 'ANL-REF-01',
                'legal_first_name' => 'Analytics', 'legal_last_name' => 'Learner', 'admission_date' => today()->toDateString(), 'status' => 'active',
                'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now(),
            ]);
            CourseEnrollment::query()->create([
                'institution_id' => $membership->institution_id, 'student_id' => $student, 'offering_id' => $offering->getKey(),
                'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar',
                'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay(),
            ]);
            $secondCourse = AcademicCourse::query()->create(['institution_id' => $membership->institution_id, 'code' => 'ANL-102', 'title' => 'Second analytics coursework', 'status' => 'active']);
            $secondOffering = CourseOffering::query()->create(['institution_id' => $membership->institution_id, 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $secondCourse->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'ANL-102-A', 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $owner->getKey()]);
            $secondSection = CourseSection::query()->create(['offering_id' => $secondOffering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
            CourseEnrollment::query()->create([
                'institution_id' => $membership->institution_id, 'student_id' => $student, 'offering_id' => $secondOffering->getKey(),
                'section_id' => $secondSection->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar',
                'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay(),
            ]);
            $assignmentId = DB::table('assignments')->insertGetId([
                'tenant_id' => $tenant->getKey(), 'institution_id' => $membership->institution_id, 'offering_id' => $offering->getKey(), 'created_by' => $owner->getKey(),
                'title' => 'Published analytics assignment', 'assignment_type' => 'coursework', 'collaboration_mode' => 'individual', 'maximum_attempts' => 1,
                'maximum_points' => '10.00', 'timezone' => 'UTC', 'status' => 'published', 'current_version' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('assignment_sections')->insert([
                ['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'section_id' => $section->getKey(), 'created_at' => now(), 'updated_at' => now()],
                ['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'section_id' => $foreignSection->getKey(), 'created_at' => now(), 'updated_at' => now()],
            ]);
            $recipientId = DB::table('assignment_recipients')->insertGetId(['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'membership_id' => $studentMembership->getKey(), 'student_id' => $student, 'section_id' => $section->getKey(), 'eligible_from' => now(), 'status' => 'eligible', 'created_at' => now(), 'updated_at' => now()]);
            $submissionId = DB::table('assignment_submissions')->insertGetId(['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'recipient_id' => $recipientId, 'status' => 'submitted', 'attempt_count' => 1, 'lock_version' => 1, 'updated_by' => $studentUser->getKey(), 'submitted_at' => now(), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('assignment_attempts')->insert(['tenant_id' => $tenant->getKey(), 'submission_id' => $submissionId, 'attempt_number' => 1, 'status' => 'submitted', 'receipt_reference' => 'ANL-'.Str::upper(Str::random(12)), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $context->clear();
        }

        $url = "/t/{$tenant->slug}/academics/analytics?campus_id={$campus->getKey()}&metrics%5B0%5D=assignments.published&metrics%5B1%5D=assignments.submissions";
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page->has('metrics', 2)
            ->where('metrics.0.key', 'assignments.published')->where('metrics.0.value', 1)
            ->where('metrics.1.key', 'assignments.submissions')->where('metrics.1.value', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?campus_id={$otherCampus->getKey()}&metrics%5B0%5D=assignments.submissions")
            ->assertOk()->assertInertia(fn ($page) => $page->where('metrics.0.value', 0));

        $builderUrl = "/t/{$tenant->slug}/academics/analytics?dataset=enrollments&dimensions%5B0%5D=status&dimensions%5B1%5D=campus&campus_id={$campus->getKey()}&report_status=confirmed&report_search=COURSE&report_sort=campus&report_direction=asc&report_per_page=1";
        $this->actingAs($owner)->get($builderUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('builder_report.dataset', 'enrollments')
            ->where('builder_report.total', 1)
            ->where('builder_report.rows.0.status', 'confirmed')
            ->where('builder_report.rows.0.campus', 'ANL-COURSE Campus')
            ->where('builder_report.rows.0.total', 2));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments&report_measure=unique_learners&dimensions%5B0%5D=status&dimensions%5B1%5D=campus&campus_id={$campus->getKey()}&report_status=confirmed&report_search=COURSE")
            ->assertOk()->assertInertia(fn ($page) => $page->where('builder_report.measure', 'unique_learners')->where('builder_report.measure_label', 'Unique learners')->where('builder_report.rows.0.total', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 2)->has('report.rows', 2)->where('report.rows.0.status', 'confirmed'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed&report_measure=unique_learners&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 1)->has('report.rows', 1)->where('report.rows.0.status', 'learner'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed&report_measure=unique_learners")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 1)->has('report.rows', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/drilldown?dataset=enrollments&dimension=status&value=confirmed&campus_id={$otherCampus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('report.total', 0)->has('report.rows', 0));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments&dimensions%5B0%5D=status&campus_id={$otherCampus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('builder_report.total', 0)->has('builder_report.rows', 0));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments&dimensions%5B0%5D=unknown")
            ->assertUnprocessable();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=admissions&report_measure=unique_learners&dimensions%5B0%5D=status")
            ->assertUnprocessable();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=attendance&report_measure=unique_learners&dimensions%5B0%5D=status")
            ->assertOk()->assertInertia(fn ($page) => $page->where('builder_report.measure_label', 'Unique learners')->where('builder_report.total', 0));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics?dataset=enrollments&dimensions%5B0%5D=status&report_status=pending&campus_id={$campus->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('builder_report.total', 0)->has('builder_report.rows', 0));
        $formulaLikeCampusName = '=HYPERLINK("https://example.test","campus")';
        DB::table('campuses')->where('id', $campus->getKey())->update(['name' => $formulaLikeCampusName]);
        $export = $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/report-builder.csv?dataset=enrollments&dimensions%5B0%5D=status&dimensions%5B1%5D=campus&campus_id={$campus->getKey()}")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csvRows = array_map('str_getcsv', explode("\n", trim($export->streamedContent())));
        self::assertSame(['status', 'campus', 'Enrollments'], $csvRows[0]);
        self::assertSame(['confirmed', "'".$formulaLikeCampusName, '2'], $csvRows[1]);
        $uniqueExport = $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics/report-builder.csv?dataset=enrollments&report_measure=unique_learners&dimensions%5B0%5D=status&dimensions%5B1%5D=campus&campus_id={$campus->getKey()}")
            ->assertOk();
        $uniqueRows = array_map('str_getcsv', explode("\n", trim($uniqueExport->streamedContent())));
        self::assertSame(['status', 'campus', 'Unique learners'], $uniqueRows[0]);
        self::assertSame(['confirmed', "'".$formulaLikeCampusName, '1'], $uniqueRows[1]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.analytics.builder_exported']);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/analytics")
            ->assertOk()->assertInertia(fn ($page) => $page->where('export_history.0.dataset', 'enrollments')
            ->where('export_history.0.rows', 1)->where('export_history.0.truncated', false));
    }

    private function provision(User $owner, string $name): Tenant
    {
        $actor = User::factory()->create();
        $details = [
            'official_name' => $name, 'display_name' => $name, 'slug' => Str::slug($name), 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ];
        $result = app(TenantProvisioner::class)->provision($details, $actor, $owner->email);
        $acceptanceUrl = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($acceptanceUrl)->assertRedirect("/t/{$result->tenant->slug}/dashboard");

        return $result->tenant;
    }

    private function createCampus(Tenant $tenant, string $code): Campus
    {
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            return Campus::query()->create(['institution_id' => $membership->institution_id, 'code' => $code, 'name' => $code.' Campus', 'status' => 'active']);
        } finally {
            $context->clear();
        }
    }

    private function createMember(Tenant $tenant, User $user, string $roleKey, ?int $campusId = null): TenantMembership
    {
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $member = TenantMembership::query()->create(['institution_id' => $membership->institution_id, 'user_id' => $user->getKey(), 'campus_id' => $campusId, 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $member->roles()->attach($role->getKey(), ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->getKey()]);

            return $member;
        } finally {
            $context->clear();
        }
    }
}
