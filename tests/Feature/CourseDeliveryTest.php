<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Learning\CourseDeliveryActivityRetentionProcessor;
use App\Domain\Learning\CourseDeliveryMediaRetentionProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\CourseDeliveryActivityResponse;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryLessonRevision;
use App\Models\CourseDeliveryLiveSession;
use App\Models\CourseDeliveryModule;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\GuardedRefreshDatabase;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class CourseDeliveryTest extends TestCase
{
    use GuardedRefreshDatabase;

    protected function setUp(): void
    {
        $environment = TestDatabaseGuard::currentEnvironment();
        if (($environment['DB_CONNECTION'] ?? '') === 'mysql'
            && ($environment['DB_DATABASE'] ?? '') !== Phase07MySqlTestTarget::DATABASE) {
            $this->markTestSkipped('Course delivery integration tests require the separately guarded Phase 07 MySQL schema.');
        }

        parent::setUp();
    }

    public function test_course_delivery_authoring_requires_independent_review_and_publishes_immutable_learner_snapshot(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-workflow');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'DEL-101');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'DEL-101-S1', 'title' => 'Course delivery: foundations', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'DEL-101-S1')->firstOrFail();

        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/{$workspace->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/learning/show')->where('workspace.status', 'draft'));
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Foundations'])->assertRedirect();
        $moduleId = (int) CourseDeliveryModule::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('workspace_id', $workspace->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$moduleId}/lessons", [
            'title' => 'Systems thinking', 'learning_objectives' => 'Explain a system boundary.', 'estimated_minutes' => 15,
        ])->assertRedirect();
        $lesson = CourseDeliveryLesson::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('workspace_id', $workspace->getKey())->firstOrFail();
        $lessonUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";
        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'clamav-test-signature';
            }
        });
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('guide.txt', 'A private course resource.'),
            'license_label' => 'Institution licensed', 'alt_text' => '',
        ])->assertRedirect();
        $media = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->first();
        $this->assertNotNull($media);
        $mediaContent = json_decode((string) $media->content, true);
        $this->assertSame('clean', $mediaContent['scan_status']);
        $this->assertSame(1, $mediaContent['asset_version']);
        $this->assertSame('clamav-test-signature', $mediaContent['scanner']);
        $this->assertStringContainsString('/clean/', $mediaContent['private_path']);
        Storage::disk('local')->assertExists($mediaContent['private_path']);
        Storage::disk('public')->assertMissing($mediaContent['private_path']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($mediaContent['private_path']));
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('guide-v2.txt', 'Revised private resource.'),
            'license_label' => 'Institution licensed', 'replaces_asset_id' => $media->id,
        ])->assertRedirect();
        $mediaReplacement = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->orderByDesc('id')->first();
        $replacementContent = json_decode((string) $mediaReplacement->content, true);
        $this->assertSame(2, $replacementContent['asset_version']);
        $this->assertSame((int) $media->id, $replacementContent['replaces_asset_id']);
        $this->assertSame('clean', $replacementContent['scan_status']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.media.version_created']);
        Storage::disk('local')->assertExists($mediaContent['private_path']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($replacementContent['private_path']));
        $this->actingAs($owner)->put($lessonUrl, [
            'lock_version' => 1, 'title' => 'Systems thinking', 'summary' => 'A first lesson.',
            'learning_objectives' => 'Explain a system boundary.', 'estimated_minutes' => 15,
            'blocks' => [['type' => 'text', 'content' => ['text' => 'Represent a system with a clear boundary. <script>alert(1)</script>']]],
        ])->assertRedirect();
        $this->assertDatabaseHas('course_delivery_blocks', ['lesson_id' => $lesson->getKey(), 'block_type' => 'text']);
        $this->assertDatabaseHas('course_delivery_blocks', ['id' => $media->id, 'block_type' => 'media_asset']);
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertRedirect();
        $ownerMembership = TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $ownerMembership->getKey(), 'assignment_role' => 'reviewer',
        ])->assertRedirect();
        $this->actingAs($owner)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertForbidden();

        $reviewer = User::factory()->create();
        $reviewerMembership = $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $reviewerMembership->getKey(), 'assignment_role' => 'reviewer',
        ])->assertRedirect();
        $this->actingAs($reviewer)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertRedirect();

        $publisher = User::factory()->create();
        $this->membership($tenant, $institution, $publisher, 'tenant.admin');
        $this->actingAs($publisher)->post($lessonUrl.'/publish')->assertRedirect();
        $revisionId = (int) DB::table('course_delivery_lesson_revisions')->where('tenant_id', $tenant->getKey())->where('lesson_id', $lesson->getKey())->value('id');
        $revision = CourseDeliveryLessonRevision::withoutGlobalScope('tenant')->findOrFail($revisionId);
        $originalSnapshot = $revision->lesson_snapshot;

        $learner = User::factory()->create();
        $learnerMembership = $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $learnerMembership->getKey(), 'assignment_role' => 'learner',
        ])->assertRedirect();
        $this->actingAs($learner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/publish")->assertForbidden();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/publish")->assertRedirect();
        DB::table('course_delivery_blocks')->whereIn('id', [$media->id, $mediaReplacement->id])->update(['created_at' => now()->subYears(10)]);
        $retention = app(CourseDeliveryMediaRetentionProcessor::class)->run();
        $this->assertSame(2, $retention['archived']);
        $this->assertSame(0, $retention['purged']);
        foreach ([$media, $mediaReplacement] as $versionedAsset) {
            $archivedContent = json_decode((string) DB::table('course_delivery_blocks')->where('id', $versionedAsset->id)->value('content'), true);
            $this->assertSame('archived', $archivedContent['storage_status']);
            Storage::disk('local')->assertMissing($archivedContent['private_path']);
            Storage::disk('local_archive')->assertExists($archivedContent['archived_path']);
            $this->beforeApplicationDestroyed(fn () => Storage::disk('local_archive')->delete($archivedContent['archived_path']));
            $archivedContent['archived_at'] = now()->subYears(10)->toIso8601String();
            DB::table('course_delivery_blocks')->where('id', $versionedAsset->id)->update(['content' => json_encode($archivedContent, JSON_THROW_ON_ERROR)]);
        }
        config()->set('learning.media_purge_after_days', 366);
        $this->assertSame(0, app(CourseDeliveryMediaRetentionProcessor::class)->run()['purged']);
        $this->actingAs($learner)->get($lessonUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/lesson')->where('version', 1)
            ->where('lesson.blocks.0.content.text', 'Represent a system with a clear boundary. <script>alert(1)</script>')
            ->where('lesson.blocks.1.type', 'media_asset')
            ->where('lesson.blocks.1.content.asset_id', (int) $media->id));
        $mediaResponse = $this->actingAs($learner)->get($lessonUrl.'/media/'.(int) $media->id);
        $mediaResponse->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('Content-Disposition', 'inline; filename="learning-asset-'.(int) $media->id.'.txt"');
        [$outsideTenantOwner] = $this->tenant('media-route-outsider');
        $this->actingAs($outsideTenantOwner)->get($lessonUrl.'/media/'.(int) $media->id)->assertNotFound();
        $progressUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/progress";
        $completionUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/complete";
        $this->actingAs($learner)->put($progressUrl, ['resume_seconds' => 42])->assertRedirect();
        $this->actingAs($learner)->post($completionUrl)->assertRedirect();
        $this->actingAs($learner)->post($completionUrl)->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', [
            'tenant_id' => $tenant->getKey(), 'workspace_id' => $workspace->getKey(),
            'lesson_id' => $lesson->getKey(), 'membership_id' => $learnerMembership->getKey(),
            'revision_version' => 1, 'status' => 'completed', 'resume_seconds' => 42,
        ]);
        $this->assertSame(1, DB::table('course_delivery_progress')->where('membership_id', $learnerMembership->getKey())->where('lesson_id', $lesson->getKey())->count());
        $this->actingAs($learner)->put($progressUrl, ['resume_seconds' => 43])->assertStatus(409);

        $this->actingAs($owner)->post($lessonUrl.'/revise')->assertRedirect();
        $lesson = CourseDeliveryLesson::withoutGlobalScope('tenant')->findOrFail($lesson->getKey());
        $this->actingAs($owner)->put($lessonUrl, [
            'lock_version' => $lesson->lock_version, 'title' => 'Systems thinking revised', 'summary' => 'New draft.',
            'learning_objectives' => 'Compare two system boundaries.', 'estimated_minutes' => 18,
            'blocks' => [['type' => 'text', 'content' => ['text' => 'Second draft content.']]],
        ])->assertRedirect();
        $snapshotJson = DB::table('course_delivery_lesson_revisions')->where('id', $revisionId)->value('lesson_snapshot');
        $this->assertSame($originalSnapshot, json_decode((string) $snapshotJson, true));
        $this->assertSame('draft', DB::table('course_delivery_lessons')->where('id', $lesson->getKey())->value('status'));
        $this->actingAs($learner)->get($lessonUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('version', 1)->where('lesson.title', 'Systems thinking')
            ->where('lesson.blocks.0.content.text', 'Represent a system with a clear boundary. <script>alert(1)</script>'));
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertRedirect();
        $this->actingAs($reviewer)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($publisher)->post($lessonUrl.'/publish')->assertRedirect();
        $this->actingAs($learner)->get($lessonUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('version', 2)->where('progress.status', 'started')->where('progress.resume_seconds', 0));
        $this->actingAs($owner)->post($lessonUrl.'/media/'.(int) $media->id.'/revoke')->assertRedirect();
        $this->actingAs($learner)->get($lessonUrl.'/media/'.(int) $media->id)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.media.access_revoked']);

        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($tenant, $institution, $teacher, 'teacher');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $teacherMembership->getKey(), 'assignment_role' => 'instructor',
            'effective_from' => now()->toDateString(), 'effective_until' => now()->addDay()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($teacher)->get("/t/{$tenant->slug}/learning/{$workspace->getKey()}")->assertOk();
        $assignmentId = (int) DB::table('course_delivery_assignments')->where('workspace_id', $workspace->getKey())->where('membership_id', $teacherMembership->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments/{$assignmentId}/revoke", ['reason' => 'Instructor role changed'])->assertRedirect();
        $this->actingAs($teacher)->get("/t/{$tenant->slug}/learning/{$workspace->getKey()}")->assertNotFound();
        $this->assertDatabaseHas('course_delivery_assignments', ['id' => $assignmentId, 'status' => 'revoked', 'change_reason' => 'Instructor role changed']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.assignment.revoked']);

        $this->actingAs($owner)->post($lessonUrl.'/revise')->assertRedirect();
        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::rejected();
            }

            public function name(): string
            {
                return 'clamav-test-signature';
            }
        });
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('infected.txt', 'EICAR test payload'),
            'license_label' => 'Test fixture',
        ])->assertRedirect();
        $rejectedAsset = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->orderByDesc('id')->first();
        $rejectedContent = json_decode((string) $rejectedAsset->content, true);
        $this->assertSame('rejected', $rejectedContent['scan_status']);
        $this->assertStringContainsString('/quarantine/', $rejectedContent['private_path']);
        Storage::disk('local')->assertExists($rejectedContent['private_path']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($rejectedContent['private_path']));
        $this->actingAs($learner)->get($lessonUrl.'/media/'.(int) $rejectedAsset->id)->assertNotFound();
        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::unavailable('scanner_execution_failed');
            }

            public function name(): string
            {
                return 'clamav-test-signature';
            }
        });
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('unavailable.txt', 'Scanner outage fixture'),
            'license_label' => 'Test fixture',
        ])->assertRedirect();
        $unavailableAsset = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->orderByDesc('id')->first();
        $unavailableContent = json_decode((string) $unavailableAsset->content, true);
        $this->assertSame('quarantined', $unavailableContent['scan_status']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($unavailableContent['private_path']));
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertSessionHasErrors('lesson');
        $learnerAssignmentId = (int) DB::table('course_delivery_assignments')->where('workspace_id', $workspace->getKey())
            ->where('membership_id', $learnerMembership->getKey())->where('assignment_role', 'learner')->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments/{$learnerAssignmentId}/revoke", [
            'reason' => 'Learner access ended',
        ])->assertRedirect();
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertNotFound();
        $this->actingAs($learner)->get($lessonUrl.'/media/'.(int) $media->id)->assertNotFound();
    }

    public function test_workspace_creation_and_lookup_cannot_cross_tenant_course_or_workspace_scope(): void
    {
        [$ownerA, $tenantA, $institutionA] = $this->tenant('delivery-tenant-a');
        [$courseA, $versionA] = $this->course($tenantA, $institutionA, 'A-101');
        [$ownerB, $tenantB, $institutionB] = $this->tenant('delivery-tenant-b');
        [$courseB, $versionB] = $this->course($tenantB, $institutionB, 'B-101');

        $this->actingAs($ownerA)->post("/t/{$tenantA->slug}/learning", [
            'academic_course_id' => $courseB->getKey(), 'academic_course_version_id' => $versionB->getKey(),
            'code' => 'CROSS-01', 'title' => 'Cross tenant', 'delivery_mode' => 'self_paced',
        ])->assertNotFound();

        $this->actingAs($ownerA)->post("/t/{$tenantA->slug}/learning", [
            'academic_course_id' => $courseA->getKey(), 'academic_course_version_id' => $versionA->getKey(),
            'code' => 'A-DELIVERY', 'title' => 'Tenant A delivery', 'delivery_mode' => 'blended',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('code', 'A-DELIVERY')->firstOrFail();
        $this->actingAs($ownerB)->get("/t/{$tenantB->slug}/learning/{$workspace->getKey()}")->assertNotFound();
    }

    public function test_every_registered_learning_route_rejects_guest_nonmember_and_revoked_members_without_mutation(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-route-matrix');
        [, $otherTenant] = $this->tenant('delivery-route-matrix-other');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'ROUTE-MATRIX');
        $campusA = DB::table('campuses')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'code' => 'MATRIX-A',
            'name' => 'Route matrix campus A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $campusB = DB::table('campuses')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'code' => 'MATRIX-B',
            'name' => 'Route matrix campus B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $workspace = CourseDeliveryWorkspace::query()->create([
                'institution_id' => $institution->getKey(), 'academic_course_id' => $course->getKey(),
                'academic_course_version_id' => $courseVersion->getKey(), 'campus_id' => $campusA, 'public_id' => (string) str()->uuid(),
                'code' => 'ROUTE-MATRIX', 'title' => 'Authorization route matrix', 'delivery_mode' => 'self_paced', 'status' => 'draft',
                'lock_version' => 1, 'created_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }
        $nonmember = User::factory()->create();
        $revoked = User::factory()->create();
        $wrongWorkflowRole = User::factory()->create();
        $this->membership($tenant, $institution, $wrongWorkflowRole, 'student');
        $wrongCampusRole = User::factory()->create();
        $wrongCampusMembership = $this->membership($tenant, $institution, $wrongCampusRole, 'tenant.admin');
        $wrongCampusMembership->forceFill(['campus_id' => $campusB])->save();
        $revokedMembership = $this->membership($tenant, $institution, $revoked, 'student');
        $revokedMembership->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $learningRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => is_string($route->getName()) && str_starts_with($route->getName(), 'tenant.learning.'))
            ->values();
        $this->assertCount(39, $learningRoutes, 'The generated authorization matrix must cover every currently registered Phase 07 learning route.');

        $mutationTables = [
            'course_delivery_workspaces', 'course_delivery_assignments', 'course_delivery_modules', 'course_delivery_lessons',
            'course_delivery_blocks', 'course_delivery_lesson_revisions', 'course_delivery_progress',
            'course_delivery_activity_responses', 'course_delivery_release_exceptions', 'course_delivery_templates',
            'course_delivery_template_versions', 'course_delivery_template_shares', 'course_delivery_copy_operations',
            'course_delivery_live_sessions', 'audit_events',
        ];
        $before = collect($mutationTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

        foreach ($learningRoutes as $route) {
            $uri = preg_replace_callback('/\\{([^}:?]+)(?::[^}?]+)?\\??\\}/', static function (array $match) use ($tenant, $workspace): string {
                return match ($match[1]) {
                    'tenant' => $tenant->slug,
                    'workspaceId' => (string) $workspace->getKey(),
                    default => '999999999',
                };
            }, $route->uri());
            $method = collect($route->methods())->first(fn (string $candidate): bool => ! in_array($candidate, ['HEAD', 'OPTIONS'], true)) ?? 'GET';
            $label = $method.' '.$route->uri().' ['.$route->getName().']';

            auth()->logout();
            $guest = $this->call($method, '/'.ltrim((string) $uri, '/'));
            $this->assertSame(302, $guest->getStatusCode(), $label.' must redirect a guest to authentication.');

            $this->actingAs($nonmember);
            $outsider = $this->call($method, '/'.ltrim((string) $uri, '/'));
            $this->assertContains($outsider->getStatusCode(), [403, 404], $label.' must deny an authenticated nonmember.');

            $this->actingAs($revoked);
            $revokedResponse = $this->call($method, '/'.ltrim((string) $uri, '/'));
            $this->assertContains($revokedResponse->getStatusCode(), [403, 404], $label.' must deny a revoked member.');

            $wrongTenantUri = preg_replace('/^t\\/[^\\/]+/', 't/'.$otherTenant->slug, (string) $uri);
            $this->actingAs($owner);
            $wrongTenantResponse = $this->call($method, '/'.ltrim($wrongTenantUri, '/'));
            $this->assertContains($wrongTenantResponse->getStatusCode(), [403, 404], $label.' must deny an institution member addressing another tenant.');

            $permissionMiddleware = collect($route->gatherMiddleware())
                ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'tenant.permission:'))
                ->flatMap(fn (string $middleware): array => explode(',', substr($middleware, strlen('tenant.permission:'))))
                ->map(fn (string $permission): string => trim($permission))
                ->reject(fn (string $permission): bool => in_array($permission, ['learning.delivery.view', 'learning.delivery.learn'], true));
            if ($permissionMiddleware->isNotEmpty()) {
                $this->actingAs($wrongWorkflowRole);
                $wrongRoleResponse = $this->call($method, '/'.ltrim((string) $uri, '/'));
                $this->assertContains($wrongRoleResponse->getStatusCode(), [403, 404], $label.' must deny the workflow role without the route permission.');
            }

            if (str_contains($route->uri(), '{workspaceId}') && in_array($method, ['GET', 'HEAD'], true)) {
                $this->actingAs($wrongCampusRole);
                $wrongCampusResponse = $this->call($method, '/'.ltrim((string) $uri, '/'));
                $this->assertContains($wrongCampusResponse->getStatusCode(), [403, 404], $label.' must deny an authorized member from the wrong campus.');
            }
        }

        $after = collect($mutationTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $this->assertSame($before, $after, 'Denied route requests must not mutate Phase 07 business or audit records.');
    }

    public function test_media_retention_archives_then_purges_only_unreferenced_verified_assets(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-media-retention');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'MED-RET-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'MED-RET-WS', 'title' => 'Media retention', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::query()->where('code', 'MED-RET-WS')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Resources'])->assertRedirect();
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", ['title' => 'Resource lesson'])->assertRedirect();
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'retention-test-scanner';
            }
        });
        $lessonUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('retained.txt', 'Verified private media.'), 'license_label' => 'Test fixture',
        ])->assertRedirect();
        $verified = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->firstOrFail();
        $verifiedContent = json_decode((string) $verified->content, true);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($verifiedContent['private_path']));
        DB::table('course_delivery_blocks')->where('id', $verified->id)->update(['created_at' => now()->subYears(10)]);

        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::unavailable('scanner_execution_failed');
            }

            public function name(): string
            {
                return 'retention-test-scanner';
            }
        });
        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('quarantined.txt', 'No scan approval.'), 'license_label' => 'Test fixture',
        ])->assertRedirect();
        $quarantined = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->orderByDesc('id')->firstOrFail();
        $quarantinedContent = json_decode((string) $quarantined->content, true);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($quarantinedContent['private_path']));
        DB::table('course_delivery_blocks')->where('id', $quarantined->id)->update(['created_at' => now()->subYears(10)]);

        $this->assertSame(['archived' => 1, 'purged' => 0, 'skipped' => 0], app(CourseDeliveryMediaRetentionProcessor::class)->run());
        $archived = json_decode((string) DB::table('course_delivery_blocks')->where('id', $verified->id)->value('content'), true);
        $quarantineState = json_decode((string) DB::table('course_delivery_blocks')->where('id', $quarantined->id)->value('content'), true);
        $this->assertSame('archived', $archived['storage_status']);
        $this->assertSame('quarantined', $quarantineState['scan_status']);
        $this->assertSame('active', $quarantineState['storage_status']);
        Storage::disk('local')->assertMissing($verifiedContent['private_path']);
        Storage::disk('local_archive')->assertExists($archived['archived_path']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local_archive')->delete($archived['archived_path']));

        $archived['archived_at'] = now()->subYears(10)->toIso8601String();
        DB::table('course_delivery_blocks')->where('id', $verified->id)->update(['content' => json_encode($archived, JSON_THROW_ON_ERROR)]);
        config()->set('learning.media_purge_after_days', 366);
        $this->assertSame(['archived' => 0, 'purged' => 1, 'skipped' => 0], app(CourseDeliveryMediaRetentionProcessor::class)->run());
        $purged = json_decode((string) DB::table('course_delivery_blocks')->where('id', $verified->id)->value('content'), true);
        $this->assertSame('purged', $purged['storage_status']);
        $this->assertSame('purged', $purged['scan_status']);
        Storage::disk('local_archive')->assertMissing($archived['archived_path']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.media.archived']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.media.purged']);
    }

    public function test_configured_clamav_scans_clean_and_eicar_learning_uploads(): void
    {
        if (config('admissions.document_scanner') !== 'clamav') {
            $this->markTestSkipped('A real ClamAV scanner is not configured in this test environment.');
        }

        $scanner = app(AdmissionDocumentScanner::class);
        $this->assertInstanceOf(ClamAvAdmissionDocumentScanner::class, $scanner);
        [$owner, $tenant, $institution] = $this->tenant('delivery-real-clamav');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'AV-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'AV-DEL', 'title' => 'Scanner integration', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'AV-DEL')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Scanner tests'])->assertRedirect();
        $module = CourseDeliveryModule::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", [
            'title' => 'Private uploads', 'learning_objectives' => 'Identify safe resources.',
        ])->assertRedirect();
        $lesson = CourseDeliveryLesson::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        $lessonUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";

        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('learning-clean.txt', 'A harmless learning resource.'),
            'license_label' => 'Test fixture',
        ])->assertRedirect();
        $cleanAsset = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->firstOrFail();
        $cleanContent = json_decode((string) $cleanAsset->content, true);
        $this->assertSame('clean', $cleanContent['scan_status']);
        $this->assertNotSame('clamav', $cleanContent['scanner']);

        $this->actingAs($owner)->post($lessonUrl.'/media', [
            'file' => UploadedFile::fake()->createWithContent('eicar-test.txt', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'),
            'license_label' => 'Antivirus test fixture',
        ])->assertRedirect();
        $rejectedAsset = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->orderByDesc('id')->firstOrFail();
        $rejectedContent = json_decode((string) $rejectedAsset->content, true);
        $this->assertSame('rejected', $rejectedContent['scan_status']);
        $this->assertStringContainsString('/quarantine/', $rejectedContent['private_path']);
        Storage::disk('local')->assertExists($rejectedContent['private_path']);
        $this->beforeApplicationDestroyed(function () use ($cleanContent, $rejectedContent): void {
            Storage::disk('local')->delete([$cleanContent['private_path'], $rejectedContent['private_path']]);
        });
    }

    public function test_release_window_and_sequential_progression_are_enforced_on_direct_learner_routes(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-release-policy');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'REL-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'REL-DEL', 'title' => 'Release policy course', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'REL-DEL')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Sequence'])->assertRedirect();
        $module = CourseDeliveryModule::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        foreach (['First lesson', 'Optional lesson', 'Final lesson'] as $title) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", [
                'title' => $title, 'learning_objectives' => "Complete {$title}.",
            ])->assertRedirect();
        }
        $lessons = CourseDeliveryLesson::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->orderBy('sort_order')->get();
        $releaseAt = CarbonImmutable::now()->addHour()->utc();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lessons[0]->getKey()}", [
            'lock_version' => 1, 'title' => $lessons[0]->title, 'summary' => null,
            'learning_objectives' => $lessons[0]->learning_objectives, 'estimated_minutes' => null,
            'blocks' => [['type' => 'release_policy', 'content' => [
                'progression_mode' => 'free', 'release_at' => '2030-01-01T09:00:00+05:00',
            ]]],
        ])->assertSessionHasErrors('blocks.0.content.release_at');
        foreach ($lessons as $index => $lesson) {
            $policy = $index === 0
                ? ['progression_mode' => 'free', 'release_at' => $releaseAt->format('Y-m-d\\TH:i:s\\Z')]
                : ['progression_mode' => 'sequential', 'release_at' => null, 'optional_path' => $index === 1, 'prerequisite_lesson_ids' => [(int) $lessons[0]->getKey()]];
            $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}", [
                'lock_version' => 1, 'title' => $lesson->title, 'summary' => null,
                'learning_objectives' => $lesson->learning_objectives, 'estimated_minutes' => null,
                'blocks' => [
                    ['type' => 'text', 'content' => ['text' => "Content for {$lesson->title}."]],
                    ['type' => 'release_policy', 'content' => $policy],
                ],
            ])->assertRedirect();
            $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/submit")->assertRedirect();
        }

        $reviewer = User::factory()->create();
        $reviewerMembership = $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $publisher = User::factory()->create();
        $publisherMembership = $this->membership($tenant, $institution, $publisher, 'tenant.admin');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $reviewerMembership->getKey(), 'assignment_role' => 'reviewer',
        ])->assertRedirect();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $publisherMembership->getKey(), 'assignment_role' => 'publisher',
        ])->assertRedirect();
        foreach ($lessons as $lesson) {
            $lessonPath = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";
            $this->actingAs($reviewer)->post($lessonPath.'/review', ['decision' => 'approved'])->assertRedirect();
            $this->actingAs($publisher)->post($lessonPath.'/publish')->assertRedirect();
        }
        $learner = User::factory()->create();
        $learnerMembership = $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $learnerMembership->getKey(), 'assignment_role' => 'learner',
        ])->assertRedirect();
        $this->actingAs($publisher)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/publish")->assertRedirect();

        $firstUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lessons[0]->getKey()}";
        $secondUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lessons[1]->getKey()}";
        $this->actingAs($learner)->get($firstUrl)->assertNotFound();
        $this->actingAs($learner)->get($secondUrl)->assertNotFound();
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.release.access_decided',
        ]);
        $denial = DB::table('audit_events')->where('tenant_id', $tenant->getKey())
            ->where('event_type', 'learning.delivery.release.access_decided')->orderByDesc('id')->first();
        $this->assertSame('deny', json_decode((string) $denial->metadata, true)['decision']);

        $exceptionPath = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lessons[0]->getKey()}/release-exceptions";
        $exceptionExpiry = CarbonImmutable::now()->addMinutes(20)->utc()->toIso8601String();
        $this->actingAs($learner)->post($exceptionPath, [
            'membership_id' => $learnerMembership->getKey(), 'reason' => 'Attempt unauthorized release exception.',
            'expires_at' => $exceptionExpiry,
        ])->assertForbidden();
        $this->actingAs($owner)->post($exceptionPath, [
            'membership_id' => $learnerMembership->getKey(), 'reason' => 'Learner needs a supervised early access window.',
            'expires_at' => $exceptionExpiry,
        ])->assertRedirect()->assertSessionHas('status', 'Learner release exception submitted for independent approval.');
        $exception = DB::table('course_delivery_release_exceptions')->where('membership_id', $learnerMembership->getKey())->first();
        $this->assertNotNull($exception);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/release-exceptions/{$exception->id}/decision", [
            'decision' => 'approve',
        ])->assertForbidden();
        $this->actingAs($publisher)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/release-exceptions/{$exception->id}/decision", [
            'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('course_delivery_release_exceptions', ['id' => $exception->id, 'status' => 'approved', 'approved_by' => $publisher->getKey()]);
        $this->actingAs($learner)->get($firstUrl)->assertOk();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.release_exception.approved']);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/release-exceptions/{$exception->id}/revoke", [
            'reason' => 'The supervised access window has ended.',
        ])->assertRedirect();
        $this->actingAs($learner)->get($firstUrl)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.release_exception.revoked']);
        $this->actingAs($owner)->post($exceptionPath, [
            'membership_id' => $learnerMembership->getKey(), 'reason' => 'A second limited access request for expiry verification.',
            'expires_at' => CarbonImmutable::now()->addMinutes(20)->utc()->toIso8601String(),
        ])->assertRedirect();
        $expiredExceptionId = (int) DB::table('course_delivery_release_exceptions')->where('membership_id', $learnerMembership->getKey())->orderByDesc('id')->value('id');
        $this->actingAs($publisher)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/release-exceptions/{$expiredExceptionId}/decision", [
            'decision' => 'approve',
        ])->assertRedirect();
        DB::table('course_delivery_release_exceptions')->where('id', $expiredExceptionId)->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($learner)->get($firstUrl)->assertNotFound();

        CarbonImmutable::setTestNow($releaseAt->addMinute());
        try {
            $this->actingAs($learner)->get($firstUrl)->assertOk()->assertInertia(fn ($page) => $page
                ->where('courseProgress.completed', 0)->where('courseProgress.total', 2)
                ->where('courseProgress.modules.0.title', 'Sequence'));
            $this->actingAs($learner)->get($secondUrl)->assertNotFound();
            $this->actingAs($learner)->post($firstUrl.'/complete')->assertRedirect();
            $this->actingAs($learner)->get($secondUrl)->assertOk()->assertInertia(fn ($page) => $page
                ->where('courseProgress.completed', 1)->where('courseProgress.total', 2)
                ->where('courseProgress.modules.0.percent', 50));
            $thirdUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lessons[2]->getKey()}";
            $this->actingAs($learner)->get($thirdUrl)->assertOk();
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertSame(1, DB::table('course_delivery_progress')->where('membership_id', $learnerMembership->getKey())->where('lesson_id', $lessons[0]->getKey())->where('status', 'completed')->count());
    }

    public function test_required_ungraded_activities_are_encrypted_versioned_and_require_authorized_instructor_confirmation(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-activities');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'ACT-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'ACT-DEL', 'title' => 'Learning activities', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'ACT-DEL')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Practice'])->assertRedirect();
        $module = CourseDeliveryModule::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", [
            'title' => 'Reflect and check', 'learning_objectives' => 'Practice a process.',
        ])->assertRedirect();
        $lesson = CourseDeliveryLesson::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        $lessonUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";
        $this->actingAs($owner)->put($lessonUrl, [
            'lock_version' => 1, 'title' => $lesson->title, 'summary' => null,
            'learning_objectives' => $lesson->learning_objectives, 'estimated_minutes' => null,
            'blocks' => [
                ['type' => 'text', 'content' => ['text' => 'Review the steps and reflect.']],
                ['type' => 'activity', 'content' => [
                    'activity_type' => 'reflection', 'prompt' => 'What did you learn?', 'required' => true,
                ]],
                ['type' => 'activity', 'content' => [
                    'activity_type' => 'checklist', 'prompt' => 'Complete each practice step.',
                    'required' => true, 'items' => ['Plan', 'Review'],
                ]],
                ['type' => 'activity', 'content' => [
                    'activity_type' => 'reading_acknowledgement',
                    'prompt' => 'Confirm that you reviewed the required material.', 'required' => true,
                ]],
                ['type' => 'completion_policy', 'content' => [
                    'require_all_required_activities' => true, 'require_instructor_confirmation' => true,
                ]],
            ],
        ])->assertRedirect();
        $activities = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'activity')->orderBy('sort_order')->get();
        $this->assertCount(3, $activities);
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertRedirect();
        $reviewer = User::factory()->create();
        $reviewerMembership = $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $publisher = User::factory()->create();
        $this->membership($tenant, $institution, $publisher, 'tenant.admin');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $reviewerMembership->getKey(), 'assignment_role' => 'reviewer',
        ])->assertRedirect();
        $this->actingAs($reviewer)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($publisher)->post($lessonUrl.'/publish')->assertRedirect();
        $learner = User::factory()->create();
        $learnerMembership = $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $learnerMembership->getKey(), 'assignment_role' => 'learner',
        ])->assertRedirect();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/publish")->assertRedirect();
        $this->actingAs($learner)->get($lessonUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('lesson.blocks.1.type', 'activity')
            ->where('lesson.blocks.1.content.prompt', 'What did you learn?'));
        $activityIds = $activities->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertStatus(409);

        $reflectionDraftKey = (string) str()->uuid();
        $reflectionPayload = ['text' => 'I learned to check each step.'];
        $reflectionPath = $lessonUrl.'/activities/'.$activityIds[0].'/responses';
        $this->actingAs($learner)->post($reflectionPath, [
            'idempotency_key' => $reflectionDraftKey, 'state' => 'draft', 'response' => $reflectionPayload,
        ])->assertRedirect();
        $record = CourseDeliveryActivityResponse::withoutGlobalScope('tenant')->where('membership_id', $learnerMembership->getKey())->firstOrFail();
        $rawPayload = (string) DB::table('course_delivery_activity_responses')->where('id', $record->getKey())->value('response_payload');
        $this->assertStringNotContainsString($reflectionPayload['text'], $rawPayload);
        $this->assertSame($reflectionPayload, $record->response_payload);
        $this->actingAs($learner)->post($reflectionPath, [
            'idempotency_key' => $reflectionDraftKey, 'state' => 'draft', 'response' => $reflectionPayload,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('course_delivery_activity_responses')->where('membership_id', $learnerMembership->getKey())->where('block_id', $activityIds[0])->count());

        $this->actingAs($learner)->post($reflectionPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => $reflectionPayload,
        ])->assertRedirect();
        $checklistPath = $lessonUrl.'/activities/'.$activityIds[1].'/responses';
        $this->actingAs($learner)->post($checklistPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => ['selected_items' => [0]],
        ])->assertSessionHasErrors('response.selected_items');
        $this->actingAs($learner)->post($checklistPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => ['selected_items' => [0, 1]],
        ])->assertRedirect();
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertStatus(409);
        $acknowledgementPath = $lessonUrl.'/activities/'.$activityIds[2].'/responses';
        $this->actingAs($learner)->post($acknowledgementPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => ['acknowledged' => false],
        ])->assertSessionHasErrors('acknowledged');
        $this->actingAs($learner)->post($acknowledgementPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => ['acknowledged' => true],
        ])->assertRedirect();
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', [
            'tenant_id' => $tenant->getKey(), 'workspace_id' => $workspace->getKey(),
            'lesson_id' => $lesson->getKey(), 'membership_id' => $learnerMembership->getKey(),
            'status' => 'started', 'completed_at' => null,
        ]);
        $this->assertNotNull(DB::table('course_delivery_progress')->where('membership_id', $learnerMembership->getKey())->value('learner_confirmed_at'));
        $this->actingAs($learner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/completion-confirmations/{$learnerMembership->getKey()}")->assertForbidden();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/{$workspace->getKey()}/completion-confirmations")
            ->assertOk()->assertInertia(fn ($page) => $page->where('pending.0.learner_name', $learner->name));

        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($tenant, $institution, $teacher, 'teacher');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $teacherMembership->getKey(), 'assignment_role' => 'instructor',
        ])->assertRedirect();
        $confirmPath = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/completion-confirmations/{$learnerMembership->getKey()}";
        $unassignedTeacher = User::factory()->create();
        $this->membership($tenant, $institution, $unassignedTeacher, 'teacher');
        $this->actingAs($unassignedTeacher)->post($confirmPath)->assertForbidden();
        $this->actingAs($teacher)->post($confirmPath)->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', [
            'membership_id' => $learnerMembership->getKey(), 'status' => 'completed',
            'instructor_confirmed_by' => $teacher->getKey(),
        ]);
        $confirmedAt = DB::table('course_delivery_progress')->where('membership_id', $learnerMembership->getKey())->value('instructor_confirmed_at');
        $this->actingAs($teacher)->post($confirmPath)->assertStatus(409);
        $this->assertSame($confirmedAt, DB::table('course_delivery_progress')->where('membership_id', $learnerMembership->getKey())->value('instructor_confirmed_at'));
        $completionEvent = DB::table('audit_events')->where('tenant_id', $tenant->getKey())
            ->where('event_type', 'learning.delivery.lesson.completion_confirmation_requested')->first();
        $this->assertNotNull($completionEvent);
        $this->assertSame(1, (int) json_decode((string) $completionEvent->metadata, true)['revision_version']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.activity_response.saved']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.lesson.instructor_completion_confirmed']);

        $responses = DB::table('course_delivery_activity_responses')->where('membership_id', $learnerMembership->getKey())->orderBy('id')->pluck('id');
        DB::table('course_delivery_activity_responses')->where('id', $responses[0])->update(['retention_until' => now()->subSecond()]);
        DB::table('course_delivery_activity_responses')->where('id', $responses[1])->update(['retention_until' => now()->addDay()]);
        DB::table('course_delivery_activity_responses')->where('id', $responses[2])->update(['retention_until' => null]);
        $this->assertSame(1, app(CourseDeliveryActivityRetentionProcessor::class)->run(1));
        $this->assertDatabaseMissing('course_delivery_activity_responses', ['id' => $responses[0]]);
        $this->assertDatabaseHas('course_delivery_activity_responses', ['id' => $responses[1]]);
        $this->assertDatabaseHas('course_delivery_activity_responses', ['id' => $responses[2]]);
    }

    public function test_completion_policy_combinations_preserve_revision_history_and_require_scoped_confirmation(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-completion-matrix');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'CMP-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'CMP-DEL', 'title' => 'Completion policy matrix', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::query()->where('code', 'CMP-DEL')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Policy combinations'])->assertRedirect();
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", ['title' => 'Revision-aware completion'])->assertRedirect();
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $lessonUrl = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}";
        $blocks = [
            ['type' => 'activity', 'content' => ['activity_type' => 'reflection', 'prompt' => 'Record one learning.', 'required' => true]],
            ['type' => 'completion_policy', 'content' => ['require_all_required_activities' => true, 'require_instructor_confirmation' => false]],
        ];
        $this->actingAs($owner)->put($lessonUrl, [
            'lock_version' => 1, 'title' => $lesson->title, 'summary' => null, 'learning_objectives' => 'Complete the reflection.',
            'estimated_minutes' => null, 'blocks' => $blocks,
        ])->assertRedirect();

        $reviewer = User::factory()->create();
        $reviewerMembership = $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $publisher = User::factory()->create();
        $publisherMembership = $this->membership($tenant, $institution, $publisher, 'tenant.admin');
        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($tenant, $institution, $teacher, 'teacher');
        foreach ([[$reviewerMembership, 'reviewer'], [$publisherMembership, 'publisher'], [$teacherMembership, 'instructor']] as [$membership, $role]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
                'membership_id' => $membership->getKey(), 'assignment_role' => $role,
            ])->assertRedirect();
        }
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertRedirect();
        $this->actingAs($reviewer)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($publisher)->post($lessonUrl.'/publish')->assertRedirect();
        $learner = User::factory()->create();
        $learnerMembership = $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $learnerMembership->getKey(), 'assignment_role' => 'learner',
        ])->assertRedirect();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/publish")->assertRedirect();

        $activityId = (int) DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'activity')->value('id');
        $activityPath = $lessonUrl.'/activities/'.$activityId.'/responses';
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertStatus(409);
        $this->actingAs($learner)->post($activityPath, [
            'idempotency_key' => (string) str()->uuid(), 'state' => 'submitted', 'response' => ['text' => 'A revision one response.'],
        ])->assertRedirect();
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', ['membership_id' => $learnerMembership->getKey(), 'revision_version' => 1, 'status' => 'completed']);

        $this->actingAs($owner)->post($lessonUrl.'/revise')->assertRedirect();
        $lesson->refresh();
        $this->actingAs($owner)->put($lessonUrl, [
            'lock_version' => $lesson->lock_version, 'title' => $lesson->title, 'summary' => null,
            'learning_objectives' => 'Use the revised instructor confirmation policy.', 'estimated_minutes' => null,
            'blocks' => [
                ['type' => 'text', 'content' => ['text' => 'No response is required in this revision.']],
                ['type' => 'completion_policy', 'content' => ['require_all_required_activities' => false, 'require_instructor_confirmation' => true]],
            ],
        ])->assertRedirect();
        $this->actingAs($owner)->post($lessonUrl.'/submit')->assertRedirect();
        $this->actingAs($reviewer)->post($lessonUrl.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($publisher)->post($lessonUrl.'/publish')->assertRedirect();
        $this->actingAs($learner)->get($lessonUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('version', 2)->where('progress.status', 'started')->has('activityResponses', 0));
        $this->actingAs($learner)->post($lessonUrl.'/complete')->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', ['membership_id' => $learnerMembership->getKey(), 'revision_version' => 2, 'status' => 'started']);
        $this->actingAs($teacher)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}/completion-confirmations/{$learnerMembership->getKey()}")->assertRedirect();
        $this->assertDatabaseHas('course_delivery_progress', [
            'membership_id' => $learnerMembership->getKey(), 'revision_version' => 2, 'status' => 'completed',
            'instructor_confirmed_by' => $teacher->getKey(),
        ]);
        $this->assertSame(1, DB::table('course_delivery_activity_responses')->where('membership_id', $learnerMembership->getKey())->where('revision_version', 1)->count());
        $this->assertDatabaseHas('course_delivery_blocks', ['id' => $activityId, 'block_type' => 'archived_activity']);
        $this->assertSame(0, $lesson->fresh('blocks')->blocks->where('block_type', 'archived_activity')->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.lesson.completed']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.lesson.instructor_completion_confirmed']);
    }

    public function test_assignment_does_not_elevate_a_member_without_the_institution_permission(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-assignment-policy');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'POL-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'POL-DEL', 'title' => 'Assignment policy', 'delivery_mode' => 'instructor_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'POL-DEL')->firstOrFail();
        $student = User::factory()->create();
        $studentMembership = $this->membership($tenant, $institution, $student, 'student');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/assignments", [
            'membership_id' => $studentMembership->getKey(), 'assignment_role' => 'instructor',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('course_delivery_assignments', ['workspace_id' => $workspace->getKey(), 'membership_id' => $studentMembership->getKey()]);
    }

    public function test_authoring_structure_supports_scoped_keyboard_reordering_and_rejects_incomplete_orders(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-reorder');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'ORD-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'ORD-DEL', 'title' => 'Ordered delivery', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'ORD-DEL')->firstOrFail();
        foreach (['First module', 'Second module'] as $title) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => $title])->assertRedirect();
        }
        $modules = CourseDeliveryModule::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->orderBy('sort_order')->get();
        $moduleIds = $modules->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/reorder", ['module_ids' => array_reverse($moduleIds)])->assertRedirect();
        $this->assertSame(1, (int) DB::table('course_delivery_modules')->where('id', $moduleIds[1])->value('sort_order'));
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/reorder", ['module_ids' => [$moduleIds[0]]])->assertSessionHasErrors('module_ids');

        $module = $modules->first();
        foreach (['First lesson', 'Second lesson'] as $title) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", ['title' => $title])->assertRedirect();
        }
        $lessonIds = DB::table('course_delivery_lessons')->where('module_id', $module->getKey())->orderBy('sort_order')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons/reorder", ['lesson_ids' => array_reverse($lessonIds)])->assertRedirect();
        $this->assertSame(1, (int) DB::table('course_delivery_lessons')->where('id', $lessonIds[1])->value('sort_order'));
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons/reorder", ['lesson_ids' => [$lessonIds[0], 999999]])->assertSessionHasErrors('lesson_ids');
    }

    public function test_learning_analytics_uses_tenant_scoped_delivery_records_and_protects_learner_access(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-analytics');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'ANL-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'ANL-DEL', 'title' => 'Analytics evidence', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'ANL-DEL')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules", ['title' => 'Quality module'])->assertRedirect();
        $module = CourseDeliveryModule::withoutGlobalScope('tenant')->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$workspace->getKey()}/modules/{$module->getKey()}/lessons", [
            'title' => 'A draft without objectives',
        ])->assertRedirect();
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        DB::table('course_delivery_blocks')->insert([
            'tenant_id' => $tenant->getKey(), 'lesson_id' => $lesson->getKey(), 'sort_order' => 1,
            'block_type' => 'external_link', 'content' => json_encode(['url' => 'http://127.0.0.1/internal-check'], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/analytics")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/analytics')
            ->where('metrics.workspaces', 1)
            ->where('metrics.lessons', 1)
            ->where('metrics.draft_lessons', 1)
            ->where('metrics.engaged_learners', 0)
            ->where('metrics.scheduled_releases', 0)
            ->where('metrics.malformed_external_links', 1)
            ->where('metrics.engaged_learners', 0)
            ->where('metrics.missing_learning_objectives', 1)
            ->where('metrics.curriculum_unlinked_workspaces', 1)
            ->has('findings', 2)
            ->where('findings.1.kind', 'malformed_external_link')
            ->where('workspaces.data.0.title', 'Analytics evidence'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/analytics?course_id=999999")
            ->assertOk()->assertInertia(fn ($page) => $page->where('metrics.workspaces', 0)->where('metrics.lessons', 0));

        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$workspace->getKey()}/lessons/{$lesson->getKey()}", [
            'lock_version' => $lesson->lock_version, 'title' => $lesson->title,
            'learning_objectives' => 'Review a validated external resource.', 'estimated_minutes' => null,
            'blocks' => [['type' => 'external_link', 'content' => ['url' => 'https://docs.example.test/guide']]],
        ])->assertRedirect();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/analytics")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('metrics.malformed_external_links', 0)
            ->where('metrics.missing_learning_objectives', 0)
            ->has('findings', 0));

        $learner = User::factory()->create();
        $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($learner)->get("/t/{$tenant->slug}/learning/analytics")->assertForbidden();

        [$otherOwner, $otherTenant] = $this->tenant('delivery-analytics-other');
        $this->actingAs($otherOwner)->get("/t/{$tenant->slug}/learning/analytics")->assertNotFound();
    }

    public function test_course_templates_are_versioned_selectively_copied_idempotently_and_tenant_scoped(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-template-copy');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'TPL-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'TPL-SOURCE', 'title' => 'Template source workspace', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $source = CourseDeliveryWorkspace::query()->where('code', 'TPL-SOURCE')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$source->getKey()}/modules", ['title' => 'Unit one', 'summary' => 'Template summary'])->assertRedirect();
        $module = CourseDeliveryModule::query()->where('workspace_id', $source->getKey())->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$source->getKey()}/modules/{$module->getKey()}/lessons", [
            'title' => 'Template lesson', 'summary' => 'Lesson summary', 'learning_objectives' => 'Describe the topic.', 'estimated_minutes' => 20,
        ])->assertRedirect();
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $source->getKey())->firstOrFail();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/learning/{$source->getKey()}/lessons/{$lesson->getKey()}", [
            'lock_version' => 1, 'title' => $lesson->title, 'summary' => $lesson->summary,
            'learning_objectives' => $lesson->learning_objectives, 'estimated_minutes' => 20,
            'blocks' => [
                ['type' => 'text', 'content' => ['text' => 'Reusable lesson content.']],
                ['type' => 'callout', 'content' => ['title' => 'Remember', 'text' => 'Keep the key idea in mind.']],
            ],
        ])->assertRedirect();

        app()->instance(AdmissionDocumentScanner::class, new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'template-copy-test-scanner';
            }
        });
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/{$source->getKey()}/lessons/{$lesson->getKey()}/media", [
            'file' => UploadedFile::fake()->createWithContent('template-guide.txt', 'A clean reusable course resource.'),
            'license_label' => 'Institution licensed', 'alt_text' => '', 'attribution' => 'TechMentors', 'transcript' => 'Text resource transcript.',
        ])->assertRedirect();
        $sourceAsset = DB::table('course_delivery_blocks')->where('lesson_id', $lesson->getKey())->where('block_type', 'media_asset')->first();
        $this->assertNotNull($sourceAsset);
        $sourceAssetContent = json_decode((string) $sourceAsset->content, true);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($sourceAssetContent['private_path']));

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates", [
            'workspace_id' => $source->getKey(), 'name' => 'Foundations course template', 'description' => 'Reusable core structure.',
        ])->assertRedirect();
        $template = DB::table('course_delivery_templates')->where('tenant_id', $tenant->getKey())->where('name', 'Foundations course template')->first();
        $this->assertNotNull($template);

        $campusId = (int) DB::table('campuses')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(),
            'code' => 'TPL-CAMPUS-B', 'name' => 'Template adopter campus', 'status' => 'active',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        [$campusCourse, $campusCourseVersion] = $this->course($tenant, $institution, 'TPL-201');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $campusCourse->getKey(), 'academic_course_version_id' => $campusCourseVersion->getKey(),
            'campus_id' => $campusId, 'code' => 'TPL-CAMPUS-DEST', 'title' => 'Cross-campus course destination',
            'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $campusDestination = CourseDeliveryWorkspace::query()->where('code', 'TPL-CAMPUS-DEST')->firstOrFail();

        $campusManager = User::factory()->create();
        $campusManagerMembership = $this->membership($tenant, $institution, $campusManager, 'academic.admin');
        $campusManagerMembership->forceFill(['campus_id' => $campusId])->save();
        $templatePath = '/t/'.$tenant->slug.'/learning/templates';
        $this->actingAs($campusManager)->get($templatePath)
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/templates')
            ->where('templates.data.0.id', (int) $template->id)
            ->where('templates.data.0.is_shared', false)
            ->where('sourceWorkspaces.0.id', (int) $campusDestination->getKey())
            ->has('sourceWorkspaces', 1)
            ->has('workspaces', 1));
        $this->actingAs($campusManager)->get('/t/'.$tenant->slug.'/learning/'.$source->getKey())->assertNotFound();
        $this->actingAs($campusManager)->get($templatePath.'/'.$template->id.'/versions')
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/learning/template-versions'));

        $version = DB::table('course_delivery_template_versions')->where('template_id', $template->id)->first();
        $this->assertNotNull($version);
        $this->assertSame(64, strlen((string) $version->content_checksum));
        $snapshot = json_decode((string) $version->content_snapshot, true);
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame(1, $snapshot['lesson_count']);
        $this->assertArrayNotHasKey('student_records', $snapshot);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'TPL-DEST', 'title' => 'Template destination workspace', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $destination = CourseDeliveryWorkspace::query()->where('code', 'TPL-DEST')->firstOrFail();
        $copyKey = (string) str()->uuid();
        $copyPayload = [
            'version' => 1, 'destination_workspace_id' => $destination->getKey(), 'idempotency_key' => $copyKey,
            'module_ids' => [$module->getKey()], 'lesson_ids' => [$module->getKey() => [$lesson->getKey()]],
        ];
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/copies", $copyPayload)->assertRedirect();
        $this->assertDatabaseCount('course_delivery_modules', 2);
        $this->assertDatabaseCount('course_delivery_lessons', 2);
        $copiedModule = CourseDeliveryModule::query()->where('workspace_id', $destination->getKey())->firstOrFail();
        $copiedLesson = CourseDeliveryLesson::query()->where('workspace_id', $destination->getKey())->firstOrFail();
        $this->assertSame('Unit one', $copiedModule->title);
        $this->assertSame('Template lesson', $copiedLesson->title);
        $this->assertDatabaseHas('course_delivery_blocks', ['lesson_id' => $copiedLesson->getKey(), 'block_type' => 'text']);
        $copiedAsset = DB::table('course_delivery_blocks')->where('lesson_id', $copiedLesson->getKey())->where('block_type', 'media_asset')->first();
        $this->assertNotNull($copiedAsset);
        $copiedAssetContent = json_decode((string) $copiedAsset->content, true);
        $this->assertSame('clean', $copiedAssetContent['scan_status']);
        $this->assertNotSame($sourceAssetContent['private_path'], $copiedAssetContent['private_path']);
        $this->assertSame($sourceAssetContent['sha256'], $copiedAssetContent['sha256']);
        $this->assertSame('TechMentors', $copiedAssetContent['attribution']);
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete($copiedAssetContent['private_path']));
        $this->assertDatabaseHas('course_delivery_copy_operations', [
            'tenant_id' => $tenant->getKey(), 'idempotency_key' => $copyKey, 'destination_workspace_id' => $destination->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.template.copied']);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/copies", $copyPayload)->assertRedirect();
        $this->assertDatabaseCount('course_delivery_modules', 2);
        $this->assertDatabaseCount('course_delivery_lessons', 2);
        $this->assertSame(1, DB::table('course_delivery_copy_operations')->where('idempotency_key', $copyKey)->count());
        $conflictingPayload = $copyPayload;
        $conflictingPayload['destination_workspace_id'] = $source->getKey();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/copies", $conflictingPayload)->assertStatus(409);
        $this->assertSame(1, CourseDeliveryModule::query()->where('workspace_id', $source->getKey())->count());

        $campusCopyKey = (string) str()->uuid();
        $campusCopyPayload = [
            'version' => 1, 'destination_workspace_id' => $campusDestination->getKey(), 'idempotency_key' => $campusCopyKey,
            'module_ids' => [$module->getKey()], 'lesson_ids' => [$module->getKey() => [$lesson->getKey()]],
        ];
        $this->actingAs($campusManager)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/copies", $campusCopyPayload)->assertRedirect();
        $this->assertDatabaseHas('course_delivery_copy_operations', [
            'tenant_id' => $tenant->getKey(), 'idempotency_key' => $campusCopyKey,
            'destination_workspace_id' => $campusDestination->getKey(),
        ]);
        $this->assertSame(1, CourseDeliveryModule::query()->where('workspace_id', $campusDestination->getKey())->count());
        $this->assertSame(1, CourseDeliveryLesson::query()->where('workspace_id', $campusDestination->getKey())->count());

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/versions", [
            'workspace_id' => $destination->getKey(),
        ])->assertRedirect();
        $this->assertDatabaseHas('course_delivery_templates', ['id' => $template->id, 'current_version' => 2]);
        $this->assertDatabaseHas('course_delivery_template_versions', ['template_id' => $template->id, 'version_number' => 2]);

        [$otherOwner, $otherTenant, $otherInstitution] = $this->tenant('delivery-template-outsider');
        $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/learning/templates/{$template->id}/versions")->assertNotFound();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/shares", [
            'institution_id' => $otherInstitution->getKey(),
        ])->assertNotFound();

        $learner = User::factory()->create();
        $this->membership($tenant, $institution, $learner, 'student');
        $this->actingAs($learner)->get("/t/{$tenant->slug}/learning/templates")->assertForbidden();

        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/years", [
            'name' => 'Rollover year', 'code' => 'TPL-ROLL-YEAR', 'starts_on' => '2032-08-01', 'ends_on' => '2033-06-30',
        ])->assertRedirect();
        $academicYearId = (int) DB::table('academic_years')->where('institution_id', $institution->getKey())->where('code', 'TPL-ROLL-YEAR')->value('id');
        $rolloverPayload = [
            'version' => 1, 'academic_year_id' => $academicYearId, 'code' => 'TPL-ROLLOVER', 'title' => 'Rollover draft workspace',
            'idempotency_key' => (string) str()->uuid(), 'module_ids' => [$module->getKey()],
            'lesson_ids' => [$module->getKey() => [$lesson->getKey()]],
        ];
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/rollovers", $rolloverPayload)->assertRedirect();
        $rolloverWorkspace = CourseDeliveryWorkspace::query()->where('code', 'TPL-ROLLOVER')->firstOrFail();
        $this->assertSame($academicYearId, (int) $rolloverWorkspace->academic_year_id);
        $this->assertSame((int) $source->academic_course_version_id, (int) $rolloverWorkspace->academic_course_version_id);
        $this->assertSame(1, CourseDeliveryModule::query()->where('workspace_id', $rolloverWorkspace->getKey())->count());
        $this->assertSame(1, CourseDeliveryLesson::query()->where('workspace_id', $rolloverWorkspace->getKey())->count());
        $this->assertSame(0, DB::table('course_delivery_progress')->where('workspace_id', $rolloverWorkspace->getKey())->count());
        $this->assertSame(0, DB::table('course_delivery_activity_responses')->where('workspace_id', $rolloverWorkspace->getKey())->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.academic_session.rolled_over']);
        $workspaceCount = CourseDeliveryWorkspace::query()->count();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/rollovers", $rolloverPayload)->assertRedirect();
        $this->assertSame($workspaceCount, CourseDeliveryWorkspace::query()->count());
        $changedRollover = $rolloverPayload;
        $changedRollover['code'] = 'TPL-OTHER-ROLLOVER';
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/rollovers", $changedRollover)->assertStatus(409);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'TPL-ROLLBACK', 'title' => 'Rollback destination', 'delivery_mode' => 'self_paced',
        ])->assertRedirect();
        $rollbackDestination = CourseDeliveryWorkspace::query()->where('code', 'TPL-ROLLBACK')->firstOrFail();
        Storage::disk('local')->delete($sourceAssetContent['private_path']);
        $rollbackCopy = $copyPayload;
        $rollbackCopy['destination_workspace_id'] = $rollbackDestination->getKey();
        $rollbackCopy['idempotency_key'] = (string) str()->uuid();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning/templates/{$template->id}/copies", $rollbackCopy)->assertSessionHasErrors('template');
        $this->assertSame(0, CourseDeliveryModule::query()->where('workspace_id', $rollbackDestination->getKey())->count());
        $this->assertSame(0, CourseDeliveryLesson::query()->where('workspace_id', $rollbackDestination->getKey())->count());
        $this->assertSame(0, DB::table('course_delivery_copy_operations')->where('destination_workspace_id', $rollbackDestination->getKey())->count());
    }

    public function test_live_sessions_use_institution_time_zone_and_audited_reschedule_and_cancellation(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('delivery-live-session');
        [$course, $courseVersion] = $this->course($tenant, $institution, 'LVS-101');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/learning", [
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersion->getKey(),
            'code' => 'LVS-WS', 'title' => 'Blended session workspace', 'delivery_mode' => 'blended',
        ])->assertRedirect();
        $workspace = CourseDeliveryWorkspace::query()->where('code', 'LVS-WS')->firstOrFail();
        $instructorUser = User::factory()->create();
        $instructor = $this->membership($tenant, $institution, $instructorUser, 'teacher');
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            CourseDeliveryAssignment::query()->create([
                'tenant_id' => $tenant->getKey(), 'workspace_id' => $workspace->getKey(), 'membership_id' => $instructor->getKey(),
                'assignment_role' => 'instructor', 'sequence' => 1, 'status' => 'active', 'assigned_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }

        $path = "/t/{$tenant->slug}/learning/{$workspace->getKey()}/sessions";
        $this->actingAs($owner)->post($path, [
            'instructor_membership_id' => $instructor->getKey(), 'title' => 'Live seminar', 'description' => 'A supervised learning session.',
            'delivery_type' => 'online', 'timezone' => 'Asia/Karachi', 'starts_at' => '2031-05-01T09:00', 'ends_at' => '2031-05-01T10:00',
            'meeting_url' => 'https://meet.example.test/session', 'preparation_materials' => 'Read chapter 1.',
        ])->assertRedirect()->assertSessionHas('status', 'Live session scheduled.');
        $session = CourseDeliveryLiveSession::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $this->assertSame('2031-05-01 04:00:00', $session->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2031-05-01 05:00:00', $session->ends_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.session.scheduled']);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/learning/sessions")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/sessions')->has('sessions.data', 1)->where('sessions.data.0.title', 'Live seminar'));
        $this->actingAs($instructorUser)->get("/t/{$tenant->slug}/learning/sessions")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/sessions')->has('sessions.data', 1)->where('sessions.data.0.title', 'Live seminar'));
        $unassignedTeacher = User::factory()->create();
        $this->membership($tenant, $institution, $unassignedTeacher, 'teacher');
        $this->actingAs($unassignedTeacher)->get("/t/{$tenant->slug}/learning/sessions")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/learning/sessions')->has('sessions.data', 0));
        $this->actingAs($owner)->put($path.'/'.$session->getKey(), [
            'title' => 'Rescheduled seminar', 'description' => 'Updated details.', 'delivery_type' => 'hybrid',
            'timezone' => 'Asia/Karachi', 'starts_at' => '2031-05-02T11:00', 'ends_at' => '2031-05-02T12:00',
            'meeting_url' => 'https://meet.example.test/updated', 'venue' => 'Room 4', 'post_session_resources' => 'https://example.test/notes',
        ])->assertRedirect()->assertSessionHas('status', 'Live session details and time updated.');
        $session->refresh();
        $this->assertSame('2031-05-02 06:00:00', $session->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('hybrid', $session->delivery_type);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.session.rescheduled']);
        $this->actingAs($owner)->post($path.'/'.$session->getKey().'/cancel', ['reason' => 'Institution closure.'])
            ->assertRedirect()->assertSessionHas('status', 'Live session cancelled with an audit record.');
        $this->assertSame('cancelled', $session->refresh()->status);
        $this->assertSame('Institution closure.', $session->cancellation_reason);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'learning.delivery.session.cancelled']);
        $this->actingAs($owner)->post($path.'/'.$session->getKey().'/cancel', ['reason' => 'Duplicate cancellation.'])->assertStatus(409);
        $this->actingAs($owner)->put($path.'/'.$session->getKey(), [
            'title' => 'Must remain cancelled', 'description' => 'No update after cancellation.', 'delivery_type' => 'hybrid',
            'timezone' => 'Asia/Karachi', 'starts_at' => '2031-05-04T11:00', 'ends_at' => '2031-05-04T12:00',
        ])->assertStatus(409);
        $this->assertSame('cancelled', $session->refresh()->status);
        $this->actingAs($owner)->post($path, [
            'instructor_membership_id' => $instructor->getKey(), 'title' => 'Invalid link', 'delivery_type' => 'online',
            'timezone' => 'Asia/Karachi', 'starts_at' => '2031-05-03T09:00', 'ends_at' => '2031-05-03T10:00', 'meeting_url' => 'http://unsafe.example.test',
        ])->assertSessionHasErrors('meeting_url');
        $this->actingAs($owner)->post($path, [
            'instructor_membership_id' => $instructor->getKey(), 'title' => 'Nonexistent DST time', 'delivery_type' => 'online',
            'timezone' => 'Europe/London', 'starts_at' => '2024-03-31T01:30', 'ends_at' => '2024-03-31T03:00',
        ])->assertSessionHasErrors('starts_at');
        $this->actingAs($owner)->post($path, [
            'instructor_membership_id' => $instructor->getKey(), 'title' => 'Ambiguous DST time', 'delivery_type' => 'online',
            'timezone' => 'Europe/London', 'starts_at' => '2024-10-27T01:30', 'ends_at' => '2024-10-27T02:30',
        ])->assertSessionHasErrors('starts_at');

        [$outsider, $otherTenant] = $this->tenant('delivery-live-session-outsider');
        $this->actingAs($outsider)->get("/t/{$otherTenant->slug}/learning/sessions")->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace/learning/sessions')->has('sessions.data', 0));
        $this->actingAs($outsider)->put("/t/{$otherTenant->slug}/learning/{$workspace->getKey()}/sessions/{$session->getKey()}", [
            'title' => 'Unauthorized', 'delivery_type' => 'online', 'timezone' => 'UTC',
            'starts_at' => '2031-05-03T09:00', 'ends_at' => '2031-05-03T10:00',
        ])->assertNotFound();
    }

    /** @return array{User,Tenant,Institution} */
    private function tenant(string $slug): array
    {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $result = app(TenantProvisioner::class)->provision([
            'slug' => $slug, 'official_name' => $slug, 'display_name' => $slug, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $url = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($url)->assertRedirect("/t/{$result->tenant->slug}/dashboard");
        $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $result->tenant->getKey())->firstOrFail();

        return [$owner, $result->tenant, $institution];
    }

    /** @return array{AcademicCourse,AcademicCourseVersion} */
    private function course(Tenant $tenant, Institution $institution, string $code): array
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => $code, 'title' => "Course {$code}", 'status' => 'active']);
            $version = AcademicCourseVersion::query()->create([
                'academic_course_id' => $course->getKey(), 'version_number' => 1, 'title' => "Course {$code} v1",
                'credit_bearing' => false, 'theory_credits' => 0, 'laboratory_credits' => 0, 'total_credits' => 0,
                'delivery_modes' => ['on_campus', 'online'], 'status' => 'published', 'is_current' => true,
            ]);
        } finally {
            $context->clear();
        }

        return [$course, $version];
    }

    private function membership(Tenant $tenant, Institution $institution, User $user, string $roleKey): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->getKey()]);

            return $membership;
        } finally {
            $context->clear();
        }
    }
}
