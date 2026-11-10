<?php

namespace Tests\Feature;

use App\Domain\Academic\Curriculum\CurriculumGovernanceService;
use App\Domain\Academic\Curriculum\CurriculumImportProcessor;
use App\Domain\Academic\Curriculum\CurriculumWorkflow;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Jobs\ProcessAcademicCurriculumImportBatch;
use App\Models\AcademicCurriculum;
use App\Models\AcademicCurriculumCompletionRule;
use App\Models\AcademicCurriculumFramework;
use App\Models\AcademicCurriculumImportBatch;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumReview;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicDocument;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase06ProbeEnvironment;
use Tests\TestCase;

class CurriculumManagementTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_curriculum_can_be_created_mapped_independently_reviewed_published_and_revised(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-owner');
        $this->seedAcademicReferences($tenant, $institution);
        $framework = $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/curriculum/frameworks", [
            'code' => 'INTL-BASE', 'title' => 'International learning framework', 'education_stage' => 'Secondary',
            'required_learning_areas' => ['Communication'],
        ])->assertRedirect();
        $frameworkModel = AcademicCurriculumFramework::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'INTL-BASE')->firstOrFail();
        $outcome = AcademicLearningOutcome::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'COMM-01')->firstOrFail();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/curriculum/frameworks/{$frameworkModel->getKey()}/outcomes", [
            'learning_outcome_id' => $outcome->getKey(), 'required' => true,
        ])->assertRedirect();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/curriculum/curricula", [
            'academic_program_id' => AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id'),
            'framework_id' => $frameworkModel->getKey(), 'code' => 'GEN-CURR', 'title' => 'General curriculum',
        ])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-CURR')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $url = "/t/{$tenant->slug}/academics/curriculum";
        $detailUrl = "{$url}/curricula/{$curriculum->getKey()}?version={$version->getKey()}";

        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/completion-rules", [
            'version_lock' => $version->lock_version, 'code' => 'CORE-MILESTONE', 'title' => 'Institution completion milestone',
            'rule_type' => 'completion_milestone', 'target_value' => null, 'unit' => null,
            'academic_period_id' => null, 'is_required' => true, 'description' => 'Institution-defined non-credit completion evidence.',
        ])->assertRedirect($detailUrl);
        $version = $this->version($tenant, $version->getKey());

        $reviewer = User::factory()->create();
        $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/submit", [
            'reviewer_user_id' => $reviewer->getKey(), 'version_lock' => $version->lock_version,
        ])->assertSessionHasErrors('curriculum');

        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'node_type' => 'unit', 'title' => 'Foundations', 'is_required' => true,
        ])->assertRedirect();
        $unit = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('title', 'Foundations')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'parent_id' => $unit->getKey(), 'node_type' => 'topic', 'title' => 'Clear communication',
        ])->assertRedirect();
        $topic = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('title', 'Clear communication')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/competency-frameworks", ['code' => 'CORE-COMP', 'title' => 'Core competencies'])->assertRedirect();
        $competencyFrameworkId = (int) DB::table('academic_competency_frameworks')->where('tenant_id', $tenant->getKey())->where('code', 'CORE-COMP')->value('id');
        $this->actingAs($owner)->post("{$url}/competency-frameworks/{$competencyFrameworkId}/competencies", [
            'code' => 'COMM-01', 'title' => 'Communicate clearly', 'descriptor' => 'Communicates effectively for the selected audience.', 'mastery_criteria' => 'Adapts message to audience.',
        ])->assertRedirect();
        $competencyId = (int) DB::table('academic_competencies')->where('tenant_id', $tenant->getKey())->where('framework_id', $competencyFrameworkId)->where('code', 'COMM-01')->value('id');
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}/competencies", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'competency_id' => $competencyId,
            'expected_level' => 'proficient', 'mapping_strength' => 'developed',
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_node_competencies', ['tenant_id' => $tenant->getKey(), 'node_id' => $topic->getKey(), 'competency_id' => $competencyId, 'expected_level' => 'proficient']);
        $staleVersionLock = $this->version($tenant, $version->getKey())->lock_version;
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}/outcomes", [
            'version_lock' => $staleVersionLock, 'learning_outcome_id' => $outcome->getKey(), 'mapping_strength' => 'developed',
        ])->assertRedirect($detailUrl);
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}/outcomes", [
            'version_lock' => $staleVersionLock, 'learning_outcome_id' => $outcome->getKey(), 'mapping_strength' => 'mastered',
        ])->assertStatus(409);
        $matrixUrl = "/t/{$tenant->slug}/academics/curriculum/curricula/{$curriculum->getKey()}/matrix?version={$version->getKey()}";
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}/outcomes", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version,
            'learning_outcome_id' => $outcome->getKey(), 'mapping_strength' => 'mastered', 'surface' => 'matrix',
        ])->assertRedirect($matrixUrl);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $this->assertSame(100, app(CurriculumWorkflow::class)->validationSummary($this->version($tenant, $version->getKey()))['coverage_percent']);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/submit", [
            'reviewer_user_id' => $reviewer->getKey(), 'version_lock' => $this->version($tenant, $version->getKey())->lock_version,
        ])->assertRedirect();
        $this->actingAs($reviewer)->get("{$url}/reviews")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/curriculum/reviews')
            ->where('assignments.data.0.version.curriculum.title', 'General curriculum')
            ->has('assignments.data', 1));
        $this->actingAs($owner)->get("{$url}/reviews")->assertOk()->assertInertia(fn ($page) => $page->has('assignments.data', 0));
        $version = $this->version($tenant, $version->getKey());
        $this->actingAs($reviewer)->post("{$url}/versions/{$version->getKey()}/decision", ['decision' => 'request_changes', 'review_note' => 'Clarify the lesson activity.'])->assertRedirect();
        $version = $this->version($tenant, $version->getKey());
        $this->assertSame('changes_requested', $version->status);
        $this->actingAs($reviewer)->get("{$url}/reviews")->assertOk()->assertInertia(fn ($page) => $page->has('assignments.data', 0));
        $topic = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->findOrFail($topic->getKey());
        $this->actingAs($owner)->put("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}", [
            'version_lock' => $version->lock_version, 'node_lock' => $topic->lock_version, 'title' => $topic->title,
            'description' => 'Clarified instructional activity.', 'is_required' => false, 'workload_unit' => 'minutes',
        ])->assertRedirect($detailUrl);
        $version = $this->version($tenant, $version->getKey());
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/submit", [
            'reviewer_user_id' => $reviewer->getKey(), 'version_lock' => $version->lock_version,
        ])->assertRedirect();
        $this->actingAs($reviewer)->get("{$url}/reviews")->assertOk()->assertInertia(fn ($page) => $page->has('assignments.data', 1));
        $version = $this->version($tenant, $version->getKey());
        $this->actingAs($reviewer)->post("{$url}/versions/{$version->getKey()}/decision", ['decision' => 'approve', 'review_note' => 'Design is complete.'])->assertRedirect();
        $version = $this->version($tenant, $version->getKey());
        $this->assertSame('approved', $version->status);
        $this->actingAs($reviewer)->post("{$url}/versions/{$version->getKey()}/publish")->assertForbidden();
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_versions', ['id' => $version->getKey(), 'status' => 'published']);
        $this->actingAs($owner)->post("{$url}/versions/{$version->getKey()}/publish")->assertStatus(409);
        $this->actingAs($owner)->get("{$url}/curricula/{$curriculum->getKey()}/reviews")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/curriculum/review-history')
            ->where('history.data.0.decision', 'approve')
            ->where('history.data.0.review_note', 'Design is complete.')
            ->where('history.data.0.reviewer.name', $reviewer->name)
            ->where('history.data.1.decision', 'request_changes')
            ->where('history.data.1.review_note', 'Clarify the lesson activity.')
            ->has('history.data', 2));
        $this->actingAs($owner)->get("{$url}/curricula/{$curriculum->getKey()}/print")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/print')->where('curriculum.code', 'GEN-CURR')->where('version.status', 'published')->has('nodes', 2));
        $this->actingAs($owner)->put("{$url}/versions/{$version->getKey()}/nodes/{$topic->getKey()}", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'node_lock' => $topic->lock_version, 'title' => 'Silent edit', 'is_required' => true, 'workload_unit' => 'minutes',
        ])->assertStatus(409);

        $this->actingAs($owner)->post("{$url}/curricula/{$curriculum->getKey()}/revisions", ['revision_note' => 'Annual review'])->assertRedirect();
        $newDraft = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->where('version_number', 2)->firstOrFail();
        $this->assertSame('draft', $newDraft->status);
        $this->assertSame(2, AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $newDraft->getKey())->count());
        $this->assertDatabaseHas('academic_curriculum_completion_rules', ['tenant_id' => $tenant->getKey(), 'curriculum_version_id' => $newDraft->getKey(), 'code' => 'CORE-MILESTONE']);
        $copiedRule = AcademicCurriculumCompletionRule::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $newDraft->getKey())->where('code', 'CORE-MILESTONE')->firstOrFail();
        $draftState = $this->version($tenant, $newDraft->getKey());
        $this->actingAs($owner)->put("{$url}/versions/{$newDraft->getKey()}/completion-rules/{$copiedRule->getKey()}", [
            'version_lock' => $draftState->lock_version, 'code' => 'CORE-MILESTONE', 'title' => 'Updated institution milestone',
            'rule_type' => 'completion_milestone', 'target_value' => null, 'unit' => null,
            'academic_period_id' => null, 'is_required' => true, 'description' => 'Revised non-credit completion evidence.',
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.published']);

        $this->actingAs($owner)->post("{$url}/curricula/{$curriculum->getKey()}/sharing", ['sharing_status' => 'shared'])->assertRedirect();
        $this->assertDatabaseHas('academic_curricula', ['id' => $curriculum->getKey(), 'sharing_status' => 'shared']);

        [$adopter, $tenantB, $institutionB] = $this->tenant('curriculum-template-adopter');
        $this->seedAcademicReferences($tenantB, $institutionB);
        $programB = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->where('code', 'GEN-01')->firstOrFail();
        $this->actingAs($adopter)->post("/t/{$tenantB->slug}/academics/curriculum/competency-frameworks", ['code' => 'LOCAL-COMP', 'title' => 'Local competencies'])->assertRedirect();
        $localCompetencyFrameworkId = (int) DB::table('academic_competency_frameworks')->where('tenant_id', $tenantB->getKey())->where('code', 'LOCAL-COMP')->value('id');
        $this->actingAs($adopter)->post("/t/{$tenantB->slug}/academics/curriculum/competency-frameworks/{$localCompetencyFrameworkId}/competencies", [
            'code' => 'COMM-01', 'title' => 'Local communication', 'descriptor' => 'Local equivalent descriptor.',
        ])->assertRedirect();
        $this->actingAs($adopter)->get("/t/{$tenantB->slug}/academics/curriculum")->assertOk()->assertInertia(fn ($page) => $page->where('sharedTemplates.0.code', 'GEN-CURR'));
        $this->actingAs($adopter)->post("/t/{$tenantB->slug}/academics/curriculum/templates/{$curriculum->getKey()}/adopt", [
            'academic_program_id' => $programB->getKey(), 'code' => 'ADOPTED-CURR', 'title' => 'Local adopted curriculum',
        ])->assertRedirect();
        $adoptedCurriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->where('code', 'ADOPTED-CURR')->firstOrFail();
        $adoptedVersion = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->where('curriculum_id', $adoptedCurriculum->getKey())->firstOrFail();
        $this->actingAs($adopter)->get("/t/{$tenantB->slug}/academics/curriculum/curricula/{$curriculum->getKey()}/print")->assertNotFound();
        $this->actingAs($adopter)->get("{$url}/curricula/{$curriculum->getKey()}/reviews")->assertNotFound();
        $this->assertSame(2, AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->where('curriculum_version_id', $adoptedVersion->getKey())->count());
        $this->assertDatabaseHas('academic_curriculum_nodes', ['tenant_id' => $tenantB->getKey(), 'curriculum_version_id' => $adoptedVersion->getKey(), 'title' => 'Clear communication']);
        $this->assertDatabaseHas('academic_curriculum_node_competencies', ['tenant_id' => $tenantB->getKey(), 'curriculum_version_id' => $adoptedVersion->getKey(), 'expected_level' => 'proficient']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenantB->getKey(), 'event_type' => 'academic.curriculum.template.adopted']);

        $revisionNode = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $newDraft->getKey())->where('title', 'Clear communication')->firstOrFail();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/academics/curriculum/versions/{$newDraft->getKey()}/nodes/{$revisionNode->getKey()}", [
            'version_lock' => $this->version($tenant, $newDraft->getKey())->lock_version, 'node_lock' => $revisionNode->lock_version,
            'title' => 'Clear communication revised', 'description' => 'Updated syllabus description.', 'is_required' => true, 'workload_unit' => 'hours',
        ])->assertRedirect();
        $this->actingAs($owner)->delete("/t/{$tenant->slug}/academics/curriculum/versions/{$newDraft->getKey()}/nodes/{$revisionNode->getKey()}/outcomes/{$outcome->getKey()}", [
            'version_lock' => $this->version($tenant, $newDraft->getKey())->lock_version,
        ])->assertRedirect();
        $this->assertDatabaseMissing('academic_curriculum_node_outcomes', ['tenant_id' => $tenant->getKey(), 'curriculum_version_id' => $newDraft->getKey(), 'node_id' => $revisionNode->getKey(), 'learning_outcome_id' => $outcome->getKey()]);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/curriculum/versions/{$newDraft->getKey()}/nodes/{$revisionNode->getKey()}/outcomes", [
            'version_lock' => $this->version($tenant, $newDraft->getKey())->lock_version, 'learning_outcome_id' => $outcome->getKey(), 'mapping_strength' => 'developed',
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.outcome.unmapped']);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum/analytics?academic_program_id=".AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/analytics')->where('metrics.curricula', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum/quality")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/quality')->has('findings'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum/curricula/{$curriculum->getKey()}/matrix?version={$newDraft->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/matrix')->where('outcomes.0.code', 'COMM-01')->has('nodes.data', 2));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum/curricula/{$curriculum->getKey()}/compare?from_version={$version->getKey()}&to_version={$newDraft->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/compare')->where('comparison.from.number', 1)->where('comparison.to.number', 2)->where('comparison.summary.added', 0)->where('comparison.summary.modified', 2));
        $this->actingAs($adopter)->get("/t/{$tenantB->slug}/academics/curriculum/curricula/{$curriculum->getKey()}/matrix?version={$version->getKey()}")->assertNotFound();
    }

    public function test_csv_import_has_a_non_mutating_preview_and_version_locked_confirmation(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-import');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $programId = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $this->actingAs($owner)->post("{$base}/curricula", ['academic_program_id' => $programId, 'code' => 'IMPORT-01', 'title' => 'Imported curriculum'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'IMPORT-01')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $content = "code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nUNIT-01,,unit,Imported unit,,true,60,minutes,,,\nTOPIC-01,UNIT-01,topic,Imported topic,,false,30,minutes,,,\n";
        $path = "{$base}/curricula/{$curriculum->getKey()}/import/{$version->getKey()}";
        $this->actingAs($owner)->post("{$path}/preview", ['version_lock' => $version->lock_version, 'file' => UploadedFile::fake()->createWithContent('outline.csv', $content)])->assertRedirect();
        $this->assertDatabaseCount('academic_curriculum_nodes', 0);
        $this->actingAs($owner)->get($path)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/import')->where('preview.valid_count', 2)->where('preview.total_count', 2)->has('preview.errors', 0));
        $sessionKey = "academic.curriculum.import.{$tenant->getKey()}.{$curriculum->getKey()}";
        $preview = $this->app['session']->get($sessionKey);
        self::assertIsArray($preview);
        $this->actingAs($owner)->post("{$path}/confirm", [
            'version_lock' => $version->lock_version,
            'preview_checksum' => $preview['checksum'],
            'file' => UploadedFile::fake()->createWithContent('outline.csv', $content.'EXTRA,,unit,Changed after preview,,true,5,minutes,,,\n'),
        ])->assertStatus(409);
        $this->assertDatabaseCount('academic_curriculum_nodes', 0);
        $this->actingAs($owner)->post("{$path}/confirm", [
            'version_lock' => $version->lock_version,
            'preview_checksum' => $preview['checksum'],
            'file' => UploadedFile::fake()->createWithContent('outline.csv', $content),
        ])->assertRedirect();
        $this->assertDatabaseCount('academic_curriculum_nodes', 2);
        $this->assertDatabaseHas('academic_curriculum_nodes', ['tenant_id' => $tenant->getKey(), 'curriculum_version_id' => $version->getKey(), 'code' => 'UNIT-01', 'parent_id' => null, 'is_required' => true]);
        $unit = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'UNIT-01')->firstOrFail();
        $this->assertDatabaseHas('academic_curriculum_nodes', ['tenant_id' => $tenant->getKey(), 'curriculum_version_id' => $version->getKey(), 'code' => 'TOPIC-01', 'parent_id' => $unit->getKey(), 'is_required' => false]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.imported']);
        $this->assertNull($this->app['session']->get($sessionKey));
        $this->assertDatabaseHas('academic_curriculum_import_batches', [
            'tenant_id' => $tenant->getKey(), 'curriculum_id' => $curriculum->getKey(),
            'curriculum_version_id' => $version->getKey(), 'status' => 'completed',
            'total_count' => 2, 'processed_count' => 2, 'imported_count' => 2,
        ]);
    }

    public function test_queued_curriculum_import_reports_progress_and_allows_audited_safe_retry(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-import-progress');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $programId = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $this->actingAs($owner)->post("{$base}/curricula", ['academic_program_id' => $programId, 'code' => 'IMPORT-PROGRESS', 'title' => 'Import progress'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'IMPORT-PROGRESS')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $path = "{$base}/curricula/{$curriculum->getKey()}/import/{$version->getKey()}";
        $content = "code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nBATCH-UNIT,,unit,Batched unit,,true,60,minutes,,,\nBATCH-TOPIC,BATCH-UNIT,topic,Batched topic,,true,30,minutes,,,\n";
        $this->actingAs($owner)->post("{$path}/preview", [
            'version_lock' => $version->lock_version, 'file' => UploadedFile::fake()->createWithContent('batch.csv', $content),
        ])->assertRedirect();
        $preview = $this->app['session']->get("academic.curriculum.import.{$tenant->getKey()}.{$curriculum->getKey()}");
        self::assertIsArray($preview);
        Queue::fake();
        $this->actingAs($owner)->post("{$path}/confirm", [
            'version_lock' => $version->lock_version, 'preview_checksum' => $preview['checksum'],
            'file' => UploadedFile::fake()->createWithContent('batch.csv', $content),
        ])->assertRedirect();
        $batch = AcademicCurriculumImportBatch::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        self::assertSame('queued', $batch->status);
        $this->assertDatabaseCount('academic_curriculum_nodes', 0);
        Queue::assertPushed(ProcessAcademicCurriculumImportBatch::class, fn (ProcessAcademicCurriculumImportBatch $job): bool => $job->batchId === $batch->getKey());
        $this->actingAs($owner)->get("{$path}?batch={$batch->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('importBatch.status', 'queued')->where('importBatch.processed_count', 0)->where('importBatch.total_count', 2));

        $batch->forceFill(['status' => 'failed', 'failure_code' => 'processing_failed'])->save();
        $this->actingAs($owner)->post("{$path}/batches/{$batch->getKey()}/retry")->assertRedirect();
        $batch->refresh();
        self::assertSame('queued', $batch->status);
        self::assertSame(1, $batch->manual_retry_count);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.import_retry_queued']);
        (new ProcessAcademicCurriculumImportBatch((int) $batch->getKey()))->handle(app(CurriculumImportProcessor::class));

        $batch->refresh();
        self::assertSame('completed', $batch->status);
        self::assertSame(2, $batch->processed_count);
        self::assertSame(2, $batch->imported_count);
        self::assertNull($batch->payload);
        $this->assertDatabaseCount('academic_curriculum_nodes', 2);
        $this->actingAs($owner)->get("{$path}?batch={$batch->getKey()}")->assertOk()->assertInertia(fn ($page) => $page->where('importBatch.status', 'completed')->where('importBatch.imported_count', 2));
    }

    public function test_program_completion_rules_are_version_locked_audited_and_tenant_scoped(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-completion-rules');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $programId = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $this->actingAs($owner)->post("{$base}/curricula", ['academic_program_id' => $programId, 'code' => 'RULES-01', 'title' => 'Program requirements'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'RULES-01')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $versionUrl = "{$base}/versions/{$version->getKey()}/completion-rules";
        $detailUrl = "{$base}/curricula/{$curriculum->getKey()}?version={$version->getKey()}";
        $payload = [
            'version_lock' => $version->lock_version, 'code' => 'CORE-CREDITS', 'title' => 'Minimum core credits',
            'rule_type' => 'minimum_credits', 'target_value' => '24.000', 'unit' => 'credits',
            'academic_period_id' => null, 'is_required' => true, 'description' => 'Required course plan should meet the program minimum.',
        ];
        $this->actingAs($owner)->post($versionUrl, $payload)->assertRedirect($detailUrl);
        $rule = AcademicCurriculumCompletionRule::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $version->getKey())->where('code', 'CORE-CREDITS')->firstOrFail();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.completion_rule.created']);
        $this->actingAs($owner)->get($detailUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/curriculum/show')
            ->where('completionRules.0.code', 'CORE-CREDITS')
            ->where('completionRules.0.target_value', '24.000'));

        $staleLock = $version->lock_version;
        $currentVersion = $this->version($tenant, $version->getKey());
        $update = [...$payload, 'version_lock' => $currentVersion->lock_version, 'title' => 'Minimum graduation credits', 'target_value' => '30.000'];
        $this->actingAs($owner)->put("{$versionUrl}/{$rule->getKey()}", [...$update, 'version_lock' => $staleLock])->assertStatus(409);
        $this->actingAs($owner)->put("{$versionUrl}/{$rule->getKey()}", $update)->assertRedirect($detailUrl);
        $this->assertDatabaseHas('academic_curriculum_completion_rules', ['id' => $rule->getKey(), 'title' => 'Minimum graduation credits', 'target_value' => '30.000']);

        [$otherOwner, $otherTenant, $otherInstitution] = $this->tenant('curriculum-completion-rules-other');
        $this->seedAcademicReferences($otherTenant, $otherInstitution);
        $foreignUrl = "/t/{$otherTenant->slug}/academics/curriculum/versions/{$version->getKey()}/completion-rules/{$rule->getKey()}";
        $this->actingAs($otherOwner)->put($foreignUrl, [...$update, 'version_lock' => $this->version($tenant, $version->getKey())->lock_version])->assertNotFound();

        $reviewer = User::factory()->create();
        $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $currentVersion = $this->version($tenant, $version->getKey());
        $this->actingAs($owner)->post("/t/{$tenant->slug}/academics/curriculum/versions/{$version->getKey()}/submit", [
            'reviewer_user_id' => $reviewer->getKey(), 'version_lock' => $currentVersion->lock_version,
        ])->assertSessionHasErrors('curriculum');

        $currentVersion = $this->version($tenant, $version->getKey());
        $this->actingAs($owner)->delete("{$versionUrl}/{$rule->getKey()}", ['version_lock' => $currentVersion->lock_version])->assertRedirect($detailUrl);
        $this->assertDatabaseMissing('academic_curriculum_completion_rules', ['id' => $rule->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.completion_rule.updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.completion_rule.deleted']);
    }

    public function test_curriculum_import_preview_rejects_cycles_duplicate_codes_and_untrusted_fields_without_writes(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-import-invalid');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $programId = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $this->actingAs($owner)->post("{$base}/curricula", ['academic_program_id' => $programId, 'code' => 'IMPORT-BAD', 'title' => 'Invalid import'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'IMPORT-BAD')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $content = "code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nA,B,unit,Unit A,,true,10,minutes,,,\nB,A,topic,Topic B,,true,10,minutes,,,\nA,,unit,Duplicate code,,true,10,minutes,,,\n";
        $path = "{$base}/curricula/{$curriculum->getKey()}/import/{$version->getKey()}";
        $this->actingAs($owner)->post("{$path}/preview", ['version_lock' => $version->lock_version, 'file' => UploadedFile::fake()->createWithContent('invalid.csv', $content)])->assertRedirect();
        $this->assertDatabaseCount('academic_curriculum_nodes', 0);
        $this->actingAs($owner)->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('preview.valid_count', 1)->has('preview.errors'));
        $errorReport = $this->actingAs($owner)->get("{$path}/errors")->assertOk();
        $this->assertStringContainsString('row,errors', $errorReport->streamedContent());
        $this->assertStringContainsString('duplicated in the file', $errorReport->streamedContent());
        $json = json_encode(['schema_version' => 1, 'nodes' => [['code' => 'JSON-UNIT', 'type' => 'unit', 'title' => 'JSON imported unit', 'tenant_id' => $tenant->getKey()]]], JSON_THROW_ON_ERROR);
        $this->actingAs($owner)->post("{$path}/preview", ['version_lock' => $version->lock_version, 'file' => UploadedFile::fake()->createWithContent('untrusted.json', $json)])->assertRedirect();
        $this->assertDatabaseCount('academic_curriculum_nodes', 0);
        $this->actingAs($owner)->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('preview.valid_count', 0)->has('preview.errors'));
        $json = json_encode(['schema_version' => 1, 'nodes' => [['code' => 'JSON-UNIT', 'type' => 'unit', 'title' => 'JSON imported unit']]], JSON_THROW_ON_ERROR);
        $this->actingAs($owner)->post("{$path}/preview", ['version_lock' => $version->lock_version, 'file' => UploadedFile::fake()->createWithContent('outline.json', $json)])->assertRedirect();
        $this->actingAs($owner)->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('preview.valid_count', 1)->has('preview.errors', 0));
        $sessionKey = "academic.curriculum.import.{$tenant->getKey()}.{$curriculum->getKey()}";
        $preview = $this->app['session']->get($sessionKey);
        $this->actingAs($owner)->post("{$path}/confirm", [
            'version_lock' => $version->lock_version,
            'preview_checksum' => $preview['checksum'],
            'file' => UploadedFile::fake()->createWithContent('outline.json', $json),
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_nodes', ['tenant_id' => $tenant->getKey(), 'curriculum_version_id' => $version->getKey(), 'code' => 'JSON-UNIT', 'title' => 'JSON imported unit']);
    }

    public function test_curriculum_prerequisite_cycles_and_cross_tenant_records_are_rejected(): void
    {
        [$owner, $tenantA, $institutionA] = $this->tenant('curriculum-a');
        $this->seedAcademicReferences($tenantA, $institutionA);
        [$ownerB, $tenantB, $institutionB] = $this->tenant('curriculum-b');
        $this->seedAcademicReferences($tenantB, $institutionB, 'B');
        $urlA = "/t/{$tenantA->slug}/academics/curriculum";
        $this->actingAs($owner)->post("{$urlA}/curricula", ['academic_program_id' => AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('code', 'GEN-01')->value('id'), 'code' => 'CYCLE-A', 'title' => 'Cycle test'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('code', 'CYCLE-A')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$urlA}/versions/{$version->getKey()}/nodes", ['version_lock' => $version->lock_version, 'node_type' => 'unit', 'title' => 'Node A'])->assertRedirect();
        $nodeA = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('title', 'Node A')->firstOrFail();
        $this->actingAs($owner)->post("{$urlA}/versions/{$version->getKey()}/nodes", ['version_lock' => $this->version($tenantA, $version->getKey())->lock_version, 'node_type' => 'unit', 'title' => 'Node B'])->assertRedirect();
        $nodeB = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->where('title', 'Node B')->firstOrFail();
        $this->actingAs($owner)->post("{$urlA}/versions/{$version->getKey()}/nodes/{$nodeA->getKey()}/prerequisites", ['version_lock' => $this->version($tenantA, $version->getKey())->lock_version, 'prerequisite_node_id' => $nodeB->getKey(), 'dependency_type' => 'required'])->assertRedirect();
        $this->actingAs($owner)->get("{$urlA}/curricula/{$curriculum->getKey()}/pathway?version={$version->getKey()}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/curriculum/pathway')
            ->where('edges.0.prerequisite_title', 'Node B')
            ->where('edges.0.node_title', 'Node A')
            ->has('edges', 1));
        $this->actingAs($ownerB)->get("{$urlA}/curricula/{$curriculum->getKey()}/pathway?version={$version->getKey()}")->assertNotFound();
        $this->actingAs($owner)->post("{$urlA}/versions/{$version->getKey()}/nodes/{$nodeB->getKey()}/prerequisites", ['version_lock' => $this->version($tenantA, $version->getKey())->lock_version, 'prerequisite_node_id' => $nodeA->getKey(), 'dependency_type' => 'required'])->assertSessionHasErrors('prerequisite_node_id');

        $programB = AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->where('code', 'GEN-01B')->value('id');
        $this->actingAs($ownerB)->post("/t/{$tenantB->slug}/academics/curriculum/curricula", ['academic_program_id' => $programB, 'code' => 'CURR-B', 'title' => 'Tenant B plan'])->assertRedirect();
        $foreignCurriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->first();
        self::assertNotNull($foreignCurriculum);
        $this->actingAs($owner)->get("{$urlA}/curricula/{$foreignCurriculum->getKey()}")->assertNotFound();
        $this->actingAs($owner)->post("{$urlA}/curricula", ['academic_program_id' => AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->value('id'), 'code' => 'FOREIGN', 'title' => 'Invalid reference'])->assertSessionHasErrors('academic_program_id');
    }

    public function test_curriculum_workspace_is_hidden_without_explicit_permission(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-permission');
        $readonly = User::factory()->create();
        $this->membership($tenant, $institution, $readonly, 'teacher');

        $this->actingAs($readonly)->get("/t/{$tenant->slug}/academics/curriculum")->assertForbidden();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/index'));
    }

    public function test_mysql_concurrent_curriculum_edits_approval_and_publication_are_serialized(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== Phase06MySqlTestTarget::DATABASE
            || ! function_exists('proc_open')) {
            $this->markTestSkipped('Curriculum race verification requires the guarded Phase 06 MySQL database and process support.');
        }

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/phase06-curriculum-concurrency.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            Phase06ProbeEnvironment::current(),
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process, 'The guarded curriculum concurrency orchestrator must start.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, 'Guarded MySQL curriculum races must complete and clean their fixtures: '.trim((string) $stderr));
        $summary = json_decode(trim((string) $stdout), true);
        $this->assertIsArray($summary);
        foreach (['edit', 'approval', 'publish'] as $race) {
            $this->assertSame(1, $summary[$race]['success'] ?? null, "Exactly one concurrent {$race} operation must commit.");
            $this->assertSame(1, $summary[$race]['conflict'] ?? null, "The losing concurrent {$race} operation must receive a conflict.");
        }
        $this->assertSame(1, $summary['approval']['approved_review_rows'] ?? null);
        $this->assertSame(1, $summary['publish']['publish_audit_rows'] ?? null);
    }

    public function test_curriculum_detail_keeps_query_count_bounded_for_a_realistic_syllabus(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-large-render');
        $this->seedAcademicReferences($tenant, $institution, 'PERF');
        $programId = (int) DB::table('academic_programs')
            ->where('tenant_id', $tenant->getKey())
            ->where('code', 'GEN-01PERF')
            ->value('id');
        $timestamp = now();
        $curriculumId = (int) DB::table('academic_curricula')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(),
            'academic_program_id' => $programId, 'code' => 'PERF-220', 'title' => 'Synthetic 220-node curriculum',
            'status' => 'draft', 'sharing_status' => 'private', 'current_version_number' => 1,
            'created_by' => $owner->getKey(), 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $versionId = (int) DB::table('academic_curriculum_versions')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'curriculum_id' => $curriculumId,
            'version_number' => 1, 'status' => 'draft', 'title' => 'Synthetic 220-node draft',
            'lock_version' => 1, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);

        $unitCodes = [];
        $units = [];
        for ($unitNumber = 1; $unitNumber <= 20; $unitNumber++) {
            $code = sprintf('UNIT-%02d', $unitNumber);
            $unitCodes[] = $code;
            $units[] = [
                'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(),
                'curriculum_version_id' => $versionId, 'node_type' => 'unit', 'code' => $code,
                'title' => 'Synthetic unit '.$unitNumber, 'sort_order' => $unitNumber * 10,
                'is_required' => true, 'workload_unit' => 'minutes', 'lock_version' => 1,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ];
        }
        DB::table('academic_curriculum_nodes')->insert($units);
        $unitIds = DB::table('academic_curriculum_nodes')
            ->where('tenant_id', $tenant->getKey())
            ->where('curriculum_version_id', $versionId)
            ->whereIn('code', $unitCodes)
            ->pluck('id', 'code');

        $topics = [];
        foreach ($unitCodes as $unitNumber => $unitCode) {
            for ($topicNumber = 1; $topicNumber <= 10; $topicNumber++) {
                $code = sprintf('TOPIC-%02d-%02d', $unitNumber + 1, $topicNumber);
                $topics[] = [
                    'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(),
                    'curriculum_version_id' => $versionId, 'parent_id' => $unitIds[$unitCode],
                    'node_type' => 'topic', 'code' => $code, 'title' => 'Synthetic topic '.$code,
                    'sort_order' => $topicNumber * 10, 'is_required' => true,
                    'workload_unit' => 'minutes', 'lock_version' => 1,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
        }
        DB::table('academic_curriculum_nodes')->insert($topics);

        $queryCount = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });
        $startedAt = hrtime(true);
        $response = $this->actingAs($owner)->get("/t/{$tenant->slug}/academics/curriculum/curricula/{$curriculumId}?version={$versionId}");
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/curriculum/show')
            ->has('nodes', 220));
        $this->assertLessThanOrEqual(45, $queryCount, 'Rendering a 220-node curriculum must not issue per-node queries.');

        fwrite(STDOUT, sprintf(
            "CURRICULUM_RENDER_BASELINE driver=%s nodes=220 queries=%d elapsed_ms=%.2f\n",
            DB::getDriverName(),
            $queryCount,
            $elapsedMilliseconds,
        ));
    }

    public function test_phase_six_curriculum_permissions_are_backfilled_only_to_system_roles(): void
    {
        [, $tenant] = $this->tenant('curriculum-permission-backfill');
        foreach (['tenant.owner', 'tenant.admin', 'academic.admin'] as $roleKey) {
            $this->assertSame(
                ['academic.curriculum.audit.view', 'academic.curriculum.manage', 'academic.curriculum.publish', 'academic.curriculum.review', 'academic.curriculum.templates.share', 'academic.curriculum.view'],
                Role::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail()->permissions()->where('key', 'like', 'academic.curriculum.%')->orderBy('key')->pluck('key')->all(),
            );
        }
        $this->assertSame(
            ['academic.curriculum.audit.view', 'academic.curriculum.view'],
            Role::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('key', 'auditor')->firstOrFail()->permissions()->where('key', 'like', 'academic.curriculum.%')->orderBy('key')->pluck('key')->all(),
        );
    }

    public function test_framework_draft_edit_activation_and_retirement_follow_a_versioned_lifecycle(): void
    {
        [$owner, $tenant] = $this->tenant('curriculum-framework-lifecycle');
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $this->actingAs($owner)->post("{$base}/frameworks", ['code' => 'LIFECYCLE', 'title' => 'Initial framework'])->assertRedirect();
        $framework = AcademicCurriculumFramework::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'LIFECYCLE')->firstOrFail();
        $this->actingAs($owner)->put("{$base}/frameworks/{$framework->getKey()}", [
            'lock_version' => 1, 'title' => 'Updated framework', 'education_stage' => 'Upper secondary',
            'required_learning_areas' => ['Communication'], 'terminology' => ['period' => 'Term'],
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_frameworks', ['id' => $framework->getKey(), 'title' => 'Updated framework', 'lock_version' => 2]);
        $this->actingAs($owner)->put("{$base}/frameworks/{$framework->getKey()}", ['lock_version' => 1, 'title' => 'Stale update'])->assertStatus(409);
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/transition", ['status' => 'active'])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_frameworks', ['id' => $framework->getKey(), 'status' => 'active']);
        $this->actingAs($owner)->put("{$base}/frameworks/{$framework->getKey()}", ['lock_version' => 3, 'title' => 'Forbidden active edit'])->assertStatus(409);
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/transition", ['status' => 'retired'])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_frameworks', ['id' => $framework->getKey(), 'status' => 'retired']);
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/outcomes", ['learning_outcome_id' => 999, 'required' => true])->assertStatus(409);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.framework.activated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.framework.retired']);
        $this->actingAs($owner)->get("{$base}/frameworks/{$framework->getKey()}/history")->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/curriculum/framework-history')->where('framework.code', 'LIFECYCLE')->has('history.data', 4));
    }

    public function test_framework_administrator_assignments_are_revocable_and_protect_the_last_administrator(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-assignment-safety');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $this->actingAs($owner)->post("{$base}/frameworks", [
            'code' => 'ASSIGNMENT-FW', 'title' => 'Assignment test framework', 'required_learning_areas' => [],
        ])->assertRedirect();
        $framework = AcademicCurriculumFramework::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'ASSIGNMENT-FW')->firstOrFail();
        $firstAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $this->membership($tenant, $institution, $firstAdmin, 'academic.admin');
        $this->membership($tenant, $institution, $secondAdmin, 'academic.admin');
        $assign = fn (User $user) => $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/administrators", [
            'user_id' => $user->getKey(), 'responsibility' => 'administrator', 'reason' => 'Assigned for curriculum governance.',
        ])->assertRedirect();
        $assign($firstAdmin);
        $assign($secondAdmin);
        $firstAssignment = DB::table('academic_curriculum_framework_administrators')->where('tenant_id', $tenant->getKey())->where('framework_id', $framework->getKey())->where('user_id', $firstAdmin->getKey())->first();
        $secondAssignment = DB::table('academic_curriculum_framework_administrators')->where('tenant_id', $tenant->getKey())->where('framework_id', $framework->getKey())->where('user_id', $secondAdmin->getKey())->first();
        self::assertNotNull($firstAssignment);
        self::assertNotNull($secondAssignment);

        $this->actingAs($owner)->post("{$base}/framework-administrators/{$firstAssignment->id}/revoke", ['reason' => 'Assignment scope has ended.'])->assertRedirect();
        $this->assertDatabaseHas('academic_curriculum_framework_administrators', ['id' => $firstAssignment->id, 'revoked_by' => $owner->getKey(), 'revocation_reason' => 'Assignment scope has ended.']);
        $this->actingAs($firstAdmin)->put("{$base}/frameworks/{$framework->getKey()}", ['lock_version' => 1, 'title' => 'Revoked user edit'])->assertForbidden();
        DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $owner->getKey())->update(['status' => 'suspended']);
        $this->actingAs($secondAdmin)->post("{$base}/framework-administrators/{$secondAssignment->id}/revoke", ['reason' => 'Attempt to remove final administrator.'])->assertSessionHasErrors('assignment');
        $this->assertDatabaseHas('academic_curriculum_framework_administrators', ['id' => $secondAssignment->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.framework_administrator.assigned']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.framework_administrator.revoked']);
    }

    public function test_program_scoped_review_policy_requires_a_complete_immutable_checklist_before_approval(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('curriculum-governance-checklist');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $programId = (int) AcademicProgram::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $outcome = AcademicLearningOutcome::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'COMM-01')->firstOrFail();
        $this->actingAs($owner)->post("{$base}/frameworks", ['code' => 'POLICY-FW', 'title' => 'Policy framework', 'required_learning_areas' => []])->assertRedirect();
        $framework = AcademicCurriculumFramework::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'POLICY-FW')->firstOrFail();
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/outcomes", ['learning_outcome_id' => $outcome->getKey(), 'required' => true])->assertRedirect();

        $reviewer = User::factory()->create();
        $publisher = User::factory()->create();
        $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $this->membership($tenant, $institution, $publisher, 'academic.admin');
        $assignmentData = ['responsibility' => 'administrator', 'academic_program_id' => $programId, 'reason' => 'Assigned for this program review.'];
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/administrators", ['user_id' => $reviewer->getKey(), ...$assignmentData])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/frameworks/{$framework->getKey()}/administrators", ['user_id' => $publisher->getKey(), ...$assignmentData])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/governance/policies", [
            'scope' => 'curriculum', 'framework_id' => $framework->getKey(), 'reviewer_eligibility' => 'program_scope', 'minimum_reviewers' => 1,
            'require_independent_reviewer' => true, 'require_separate_publisher' => true, 'require_changes_note' => true, 'require_rejection_note' => true,
            'require_checklist_completion' => true, 'allow_resubmission' => true, 'effective_from' => today()->toDateString(),
            'checklist_code' => 'CURRICULUM-REVIEW', 'checklist_title' => 'Curriculum review',
            'checklist_items' => [['category' => 'Alignment', 'prompt' => 'Are the outcomes aligned?', 'is_required' => true, 'requires_note' => false]],
        ])->assertRedirect();

        $this->actingAs($owner)->post("{$base}/curricula", ['academic_program_id' => $programId, 'framework_id' => $framework->getKey(), 'code' => 'POLICY-CURR', 'title' => 'Checklist curriculum'])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'POLICY-CURR')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/nodes", ['version_lock' => $version->lock_version, 'node_type' => 'unit', 'title' => 'Core unit'])->assertRedirect();
        $unit = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('title', 'Core unit')->firstOrFail();
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/nodes", ['version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'parent_id' => $unit->getKey(), 'node_type' => 'topic', 'title' => 'Communication topic'])->assertRedirect();
        $topic = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('title', 'Communication topic')->firstOrFail();
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/nodes/{$topic->getKey()}/outcomes", [
            'version_lock' => $this->version($tenant, $version->getKey())->lock_version, 'learning_outcome_id' => $outcome->getKey(), 'mapping_strength' => 'mastered',
        ])->assertRedirect();
        $version = $this->version($tenant, $version->getKey());
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/submit", ['reviewer_user_ids' => [$reviewer->getKey()], 'version_lock' => $version->lock_version])->assertRedirect();
        $review = AcademicCurriculumReview::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $version->getKey())->firstOrFail();
        $snapshot = json_decode((string) DB::table('academic_curriculum_reviews')->where('id', $review->getKey())->value('checklist_snapshot'), true);
        self::assertIsArray($snapshot);
        self::assertCount(1, $snapshot);
        $firstItem = $snapshot[0] ?? null;
        self::assertIsArray($firstItem);
        self::assertArrayHasKey('id', $firstItem);
        $itemId = (int) $firstItem['id'];
        $decision = "{$base}/versions/{$version->getKey()}/decision";
        $this->actingAs($reviewer)->post($decision, ['decision' => 'approve'])->assertSessionHasErrors('checklist_responses');
        $this->assertDatabaseHas('academic_curriculum_reviews', ['id' => $review->getKey(), 'decision' => 'assigned']);
        $this->actingAs($reviewer)->post($decision, [
            'decision' => 'approve', 'checklist_responses' => [$itemId => ['complete' => true, 'evidence_reference' => 'Outcome map checked']],
        ])->assertRedirect();
        $review->refresh();
        $savedSnapshot = json_decode((string) DB::table('academic_curriculum_reviews')->where('id', $review->getKey())->value('checklist_snapshot'), true);
        $responses = json_decode((string) DB::table('academic_curriculum_reviews')->where('id', $review->getKey())->value('checklist_responses'), true);
        self::assertSame($snapshot, $savedSnapshot);
        self::assertIsArray($responses);
        $firstResponse = $responses[0] ?? null;
        self::assertIsArray($firstResponse);
        self::assertSame(true, $firstResponse['complete'] ?? false);
        $version->refresh();
        self::assertSame('approved', $version->status);
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/publish")->assertStatus(409);
        $this->actingAs($publisher)->post("{$base}/versions/{$version->getKey()}/publish")->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.governance_policy.published']);
    }

    public function test_competency_framework_requires_independent_review_and_revision_preserves_published_snapshot(): void
    {
        [$owner, $tenant, $institution] = $this->tenant('competency-governance');
        $this->seedAcademicReferences($tenant, $institution);
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $reviewer = User::factory()->create();
        $publisher = User::factory()->create();
        $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $this->membership($tenant, $institution, $publisher, 'academic.admin');
        $this->actingAs($owner)->post("{$base}/competency-frameworks", ['code' => 'GOV-COMP', 'title' => 'Governed competencies'])->assertRedirect();
        $frameworkId = (int) DB::table('academic_competency_frameworks')->where('tenant_id', $tenant->getKey())->where('code', 'GOV-COMP')->value('id');
        $this->actingAs($owner)->post("{$base}/competency-frameworks/{$frameworkId}/competencies", [
            'code' => 'GOV-SKILL', 'title' => 'Governed skill', 'descriptor' => 'Demonstrates the expected skill.', 'mastery_levels' => ['Developing', 'Proficient'],
            'evidence_requirements' => ['Annotated work sample'],
        ])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/governance/policies", [
            'scope' => 'competency', 'competency_framework_id' => $frameworkId, 'reviewer_eligibility' => 'institution_wide', 'minimum_reviewers' => 1,
            'require_independent_reviewer' => true, 'require_separate_publisher' => true, 'require_changes_note' => true, 'require_rejection_note' => true,
            'require_checklist_completion' => false, 'allow_resubmission' => true, 'effective_from' => today()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/competency-frameworks/{$frameworkId}/submit", ['reviewer_user_ids' => [$reviewer->getKey()]])->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/competency-frameworks/{$frameworkId}/decision", ['decision' => 'approve', 'review_note' => 'Competency definitions reviewed.'])->assertRedirect();
        $this->actingAs($reviewer)->post("{$base}/competency-frameworks/{$frameworkId}/publish")->assertSessionHasErrors('framework');
        $this->actingAs($publisher)->post("{$base}/competency-frameworks/{$frameworkId}/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_competency_frameworks', ['id' => $frameworkId, 'status' => 'published', 'version_number' => 1]);
        $this->actingAs($owner)->post("{$base}/competency-frameworks/{$frameworkId}/revisions", ['revision_note' => 'Update competency descriptors.'])->assertRedirect();
        $this->assertDatabaseHas('academic_competency_frameworks', ['id' => $frameworkId, 'status' => 'published', 'version_number' => 1]);
        $this->assertDatabaseHas('academic_competency_frameworks', ['tenant_id' => $tenant->getKey(), 'based_on_framework_id' => $frameworkId, 'status' => 'draft', 'version_number' => 2]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.competency_framework.published']);

        $programId = (int) DB::table('academic_programs')->where('tenant_id', $tenant->getKey())->where('code', 'GEN-01')->value('id');
        $competencyId = (int) DB::table('academic_competencies')->where('tenant_id', $tenant->getKey())->where('framework_id', $frameworkId)->where('code', 'GOV-SKILL')->value('id');
        $this->actingAs($owner)->post("{$base}/programs/{$programId}/competencies/{$competencyId}", [
            'expected_level' => 'Proficient', 'evidence_requirements' => ['Annotated portfolio entry'], 'is_required' => true,
        ])->assertRedirect();
        $requirement = DB::table('academic_program_competency_requirements')
            ->where('tenant_id', $tenant->getKey())->where('academic_program_id', $programId)->where('competency_id', $competencyId)->first();
        self::assertNotNull($requirement);
        self::assertSame('Proficient', $requirement->expected_level);
        self::assertSame(['Annotated portfolio entry'], json_decode((string) $requirement->evidence_requirements, true));

        $this->actingAs($owner)->post("{$base}/curricula", [
            'academic_program_id' => $programId, 'code' => 'COMP-GAP-CURR', 'title' => 'Competency gap curriculum',
        ])->assertRedirect();
        $curriculum = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'COMP-GAP-CURR')->firstOrFail();
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_id', $curriculum->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/nodes", [
            'version_lock' => $version->lock_version, 'node_type' => 'unit', 'title' => 'Competency unit',
        ])->assertRedirect();
        $node = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('curriculum_version_id', $version->getKey())->where('title', 'Competency unit')->firstOrFail();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            self::assertSame([[
                'competency_id' => $competencyId, 'title' => 'Governed skill', 'expected_level' => 'Proficient',
            ]], app(CurriculumGovernanceService::class)->competencyGaps($version));
        } finally {
            $context->clear();
        }
        $version->refresh();
        $this->actingAs($owner)->post("{$base}/versions/{$version->getKey()}/nodes/{$node->getKey()}/competencies", [
            'version_lock' => $version->lock_version, 'competency_id' => $competencyId,
            'expected_level' => 'Proficient', 'mapping_strength' => 'developed',
        ])->assertRedirect();
        $context->enterProvisioning($tenant);
        try {
            self::assertSame([], app(CurriculumGovernanceService::class)->competencyGaps($version->refresh()));
        } finally {
            $context->clear();
        }
    }

    public function test_private_curriculum_resource_download_fails_closed_on_scanner_outage_and_denies_other_tenants(): void
    {
        Storage::fake('local');
        [$owner, $tenant, $institution] = $this->tenant('curriculum-private-resource');
        $this->seedAcademicReferences($tenant, $institution);
        $context = app(TenantContext::class);
        $relativePath = "academic/tenants/{$tenant->getKey()}/documents/".Str::uuid().'.pdf';
        $contents = "%PDF-1.7\ncontrolled curriculum file\n";
        Storage::disk('local')->put($relativePath, $contents);
        $context->enterProvisioning($tenant);
        try {
            $document = AcademicDocument::query()->create([
                'institution_id' => $institution->getKey(), 'document_type' => 'learning_resource', 'code' => 'PRIVATE-RESOURCE', 'version_number' => 1,
                'title' => 'Private learning resource', 'private_file_path' => $relativePath, 'file_sha256' => hash('sha256', $contents),
                'mime_type' => 'application/pdf', 'file_size' => strlen($contents), 'status' => 'approved', 'created_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }
        $base = "/t/{$tenant->slug}/academics/curriculum";
        $this->actingAs($owner)->post("{$base}/resources", [
            'code' => 'PRIVATE-RESOURCE', 'title' => 'Private resource reference', 'resource_type' => 'private_document', 'academic_document_id' => $document->getKey(),
            'captions_available' => false, 'transcript_available' => false, 'is_required' => true,
        ])->assertRedirect();
        $resourceId = (int) DB::table('academic_curriculum_resources')->where('tenant_id', $tenant->getKey())->where('code', 'PRIVATE-RESOURCE')->value('id');
        $this->actingAs($owner)->post("{$base}/resources/{$resourceId}/transition", ['status' => 'in_review'])->assertRedirect();
        $reviewer = User::factory()->create();
        $this->membership($tenant, $institution, $reviewer, 'academic.admin');
        $this->actingAs($reviewer)->post("{$base}/resources/{$resourceId}/transition", ['status' => 'approved'])->assertRedirect();
        $scanner = \Mockery::mock(AdmissionDocumentScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn(AdmissionDocumentScanResult::unavailable('signature_database_unavailable'));
        $scanner->shouldReceive('name')->never();
        $this->app->instance(AdmissionDocumentScanner::class, $scanner);
        $this->actingAs($owner)->get("{$base}/resources/{$resourceId}/download")->assertStatus(503)->assertDontSee($relativePath);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.curriculum.resource.download_blocked']);

        [$otherOwner, $otherTenant, $otherInstitution] = $this->tenant('curriculum-other-resource-tenant');
        $this->seedAcademicReferences($otherTenant, $otherInstitution);
        $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/academics/curriculum/resources/{$resourceId}/download")->assertNotFound();
    }

    /** @return array{User,Tenant,Institution} */
    private function tenant(string $name): array
    {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $result = app(TenantProvisioner::class)->provision([
            'slug' => $name, 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $url = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($url)->assertRedirect("/t/{$result->tenant->slug}/dashboard");
        $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $result->tenant->getKey())->firstOrFail();

        return [$owner, $result->tenant, $institution];
    }

    private function seedAcademicReferences(Tenant $tenant, Institution $institution, string $suffix = ''): void
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $level = AcademicLevel::query()->create(['institution_id' => $institution->getKey(), 'name' => 'General level '.$suffix, 'code' => 'GEN'.$suffix, 'category' => 'custom', 'status' => 'active']);
            AcademicProgram::query()->create(['institution_id' => $institution->getKey(), 'academic_level_id' => $level->getKey(), 'title' => 'General program '.$suffix, 'code' => 'GEN-01'.$suffix, 'credit_bearing' => false, 'delivery_mode' => 'on_campus', 'status' => 'active']);
            AcademicLearningOutcome::query()->create(['institution_id' => $institution->getKey(), 'public_id' => (string) Str::uuid(), 'code' => 'COMM-01'.$suffix, 'version_number' => 1, 'name' => 'Communicate clearly '.$suffix, 'description' => 'Communicates ideas for an intended audience.', 'status' => 'active']);
        } finally {
            $context->clear();
        }
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

    private function version(Tenant $tenant, int $versionId): AcademicCurriculumVersion
    {
        return AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->findOrFail($versionId);
    }
}
