<?php

use App\Domain\Learning\CourseDeliveryLiveSessionWorkflow;
use App\Domain\Learning\CourseDeliveryTemplateWorkflow;
use App\Domain\Learning\CourseDeliveryWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryLiveSession;
use App\Models\CourseDeliveryTemplate;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Phase06ProbeEnvironment;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\Phase07ProbeAuthorization;
use Tests\Support\TestDatabaseGuard;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = TestDatabaseGuard::currentEnvironment();
$connection = DB::connection();

try {
    TestDatabaseGuard::assertSafe($environment);
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase07MySqlTestTarget::DATABASE) {
        throw new RuntimeException('The Phase 07 learning concurrency probe requires the exact guarded MySQL schema.');
    }
    Phase07ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "Phase 07 learning concurrency probe refused the database identity or active runner lock.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false) {
    fwrite(STDERR, "Phase 07 learning concurrency probe has no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    $runId = $argv[2] ?? '';
    $label = $argv[3] ?? '';
    $payload = json_decode(base64_decode($argv[4] ?? '', true) ?: '', true);
    $expectedRun = 'phase07-learning-'.$runId;
    if (! preg_match('/^[a-f0-9-]{36}$/', $runId) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Phase 07 learning worker rejected invalid metadata.\n");
        exit(2);
    }
    $operation = (string) ($payload['operation'] ?? '');
    $readyPath = $framework.DIRECTORY_SEPARATOR.$expectedRun.'-'.$operation.'-'.$label.'.ready';
    $goPath = $framework.DIRECTORY_SEPARATOR.$expectedRun.'-'.$operation.'.go';
    $resultPath = $framework.DIRECTORY_SEPARATOR.$expectedRun.'-'.$operation.'-'.$label.'.result';
    foreach ([$readyPath, $goPath, $resultPath] as $path) {
        if (dirname($path) !== $framework || is_link($path)) {
            fwrite(STDERR, "Phase 07 learning worker rejected an unsafe coordination path.\n");
            exit(2);
        }
    }
    if (file_exists($readyPath) || file_exists($resultPath) || file_put_contents($readyPath, (string) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id, LOCK_EX) === false) {
        fwrite(STDERR, "Phase 07 learning worker could not establish its barrier.\n");
        exit(2);
    }
    $deadline = microtime(true) + 30;
    while (! is_file($goPath) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($goPath) || is_link($goPath)) {
        fwrite(STDERR, "Phase 07 learning worker barrier timed out.\n");
        exit(2);
    }

    $stage = 'load_fixture';
    try {
        $stage = 'load_tenant';
        $tenant = Tenant::query()->findOrFail((int) $payload['tenant_id']);
        $stage = 'load_membership';
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->findOrFail((int) $payload['membership_id']);
        $stage = 'load_actor';
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        $stage = 'set_tenant_context';
        app(TenantContext::class)->set($tenant, $membership);
        $stage = 'load_workspace';
        $workspace = CourseDeliveryWorkspace::query()->findOrFail((int) $payload['workspace_id']);
        $stage = $operation;
        $outcome = match ($operation) {
            'complete' => (function () use ($actor, $workspace, $payload): string {
                app(CourseDeliveryWorkflow::class)->completeLesson($actor, $workspace, CourseDeliveryLesson::query()->findOrFail((int) $payload['lesson_id']));

                return 'success';
            })(),
            'confirm_or_respond' => (function () use ($actor, $workspace, $payload): string {
                if ($payload['kind'] === 'confirm') {
                    app(CourseDeliveryWorkflow::class)->confirmLessonCompletion($actor, $workspace, CourseDeliveryLesson::query()->findOrFail((int) $payload['lesson_id']), (int) $payload['learner_membership_id']);
                } else {
                    app(CourseDeliveryWorkflow::class)->saveActivityResponse(
                        $actor, $workspace, CourseDeliveryLesson::query()->findOrFail((int) $payload['lesson_id']),
                        (int) $payload['activity_id'], (string) Str::uuid(), 'submitted', ['text' => 'Concurrent final response'],
                    );
                }

                return 'success';
            })(),
            'release' => (function () use ($actor, $payload): string {
                app(CourseDeliveryWorkflow::class)->updateLesson(
                    $actor,
                    CourseDeliveryLesson::query()->findOrFail((int) $payload['lesson_id']),
                    ['lock_version' => 1, 'title' => 'Concurrent release policy '.$payload['kind'], 'summary' => null,
                        'learning_objectives' => 'Verify release policy update serialization.', 'estimated_minutes' => 10,
                        'blocks' => [['type' => 'release_policy', 'content' => ['progression_mode' => $payload['kind'] === 'a' ? 'free' : 'sequential']]]],
                );

                return 'success';
            })(),
            'copy' => (function () use ($actor, $workspace, $payload): string {
                $template = CourseDeliveryTemplate::query()->findOrFail((int) $payload['template_id']);
                $moduleId = (int) $payload['module_id'];
                app(CourseDeliveryTemplateWorkflow::class)->copyToWorkspace(
                    $actor, $template, 1, $workspace, [$moduleId], [$moduleId => [(int) $payload['source_lesson_id']]], (string) $payload['idempotency_key'],
                );

                return 'success';
            })(),
            'cancel' => (function () use ($actor, $payload): string {
                app(CourseDeliveryLiveSessionWorkflow::class)->cancel(
                    $actor, CourseDeliveryLiveSession::query()->findOrFail((int) $payload['session_id']), 'Concurrent cancellation '.$payload['kind'],
                );

                return 'success';
            })(),
            default => throw new RuntimeException('Unknown Phase 07 concurrency operation.'),
        };
        $connectionId = (int) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id;
        file_put_contents($resultPath, json_encode(['outcome' => $outcome, 'connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    } catch (HttpExceptionInterface $exception) {
        $status = $exception->getStatusCode();
        if ($status === 409) {
            file_put_contents($resultPath, json_encode(['outcome' => 'conflict', 'connection_id' => (int) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        }
        file_put_contents($resultPath, json_encode(['outcome' => 'failed', 'stage' => $stage, 'http_status' => $status], JSON_THROW_ON_ERROR), LOCK_EX);
        fwrite(STDERR, "Phase 07 learning worker denied unexpectedly at {$stage} (HTTP {$status}).\n");
        exit(1);
    } catch (Throwable $exception) {
        $code = $exception instanceof QueryException ? (string) ($exception->errorInfo[0] ?? 'db') : (string) $exception->getCode();
        $safe = preg_match('/^[A-Za-z0-9_-]{1,16}$/', $code) ? $code : 'unknown';
        file_put_contents($resultPath, json_encode(['outcome' => 'failed', 'stage' => $stage, 'error_type' => class_basename($exception), 'error_code' => $safe], JSON_THROW_ON_ERROR), LOCK_EX);
        fwrite(STDERR, "Phase 07 learning worker failed at {$stage} (".class_basename($exception).", code {$safe}).\n");
        exit(1);
    } finally {
        app(TenantContext::class)->clear();
    }
}

if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 07 learning concurrency mode.\n");
    exit(2);
}

$runId = strtolower((string) Str::uuid());
$runKey = 'phase07-learning-'.$runId;
$now = now()->format('Y-m-d H:i:s');
$ids = [];
$processes = [];
$coordination = [];
$failure = null;
$count = 0;

$race = static function (string $operation, array $left, array $right) use ($base, $framework, $runId, $runKey, &$processes, &$coordination): array {
    $goPath = $framework.DIRECTORY_SEPARATOR.$runKey.'-'.$operation.'.go';
    $entries = [];
    $coordination[] = $goPath;
    foreach (['a' => $left, 'b' => $right] as $label => $payload) {
        $payload['operation'] = $operation;
        $ready = $framework.DIRECTORY_SEPARATOR.$runKey.'-'.$operation.'-'.$label.'.ready';
        $result = $framework.DIRECTORY_SEPARATOR.$runKey.'-'.$operation.'-'.$label.'.result';
        foreach ([$ready, $result, $goPath] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('A Phase 07 learning coordination file already exists.');
            }
        }
        $coordination[] = $ready;
        $coordination[] = $result;
        $command = [PHP_BINARY, __FILE__, 'worker', $runId, $label, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $base, Phase06ProbeEnvironment::current(), ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start an independent Phase 07 learning worker.');
        }
        fclose($pipes[0]);
        $entry = ['process' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready, 'result' => $result];
        $entries[] = $entry;
        $processes[] = $entry;
    }
    $deadline = microtime(true) + 30;
    while ((! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) {
        throw new RuntimeException("Both independent {$operation} workers did not reach the start barrier.");
    }
    $connectionA = (int) file_get_contents($entries[0]['ready']);
    $connectionB = (int) file_get_contents($entries[1]['ready']);
    if ($connectionA < 1 || $connectionB < 1 || $connectionA === $connectionB) {
        throw new RuntimeException("The {$operation} workers did not establish distinct MySQL sessions.");
    }
    if (file_put_contents($goPath, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException("Could not release {$operation} barrier.");
    }
    $outcomes = [];
    foreach ($entries as $entry) {
        $deadline = microtime(true) + 45;
        do {
            $status = proc_get_status($entry['process']);
            if (! $status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            throw new RuntimeException("The {$operation} worker exceeded its bounded execution time.");
        }
        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exit = proc_close($entry['process']);
        $result = is_file($entry['result']) ? json_decode((string) file_get_contents($entry['result']), true) : null;
        if ($exit !== 0 || ! is_array($result) || ! in_array($result['outcome'] ?? null, ['success', 'conflict'], true)) {
            throw new RuntimeException("A {$operation} worker failed: ".(is_array($result) ? ($result['stage'] ?? $result['error_type'] ?? 'unknown') : 'no result').'. '.trim($stderr.$stdout));
        }
        $outcomes[] = $result;
    }
    if ((int) $outcomes[0]['connection_id'] === (int) $outcomes[1]['connection_id']) {
        throw new RuntimeException("{$operation} result sessions were not independent.");
    }

    return $outcomes;
};

try {
    $connection->transaction(function () use ($connection, $runKey, $now, &$ids): void {
        $actor = User::factory()->create(['name' => 'Phase 07 concurrency owner', 'email' => $runKey.'-owner@example.test']);
        $learner = User::factory()->create(['name' => 'Phase 07 concurrency learner', 'email' => $runKey.'-learner@example.test']);
        $teacher = User::factory()->create(['name' => 'Phase 07 concurrency instructor', 'email' => $runKey.'-teacher@example.test']);
        $ids['users'] = [(int) $actor->getKey(), (int) $learner->getKey(), (int) $teacher->getKey()];
        $tenantId = (int) $connection->table('tenants')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Phase 07 concurrency fixture', 'slug' => $runKey,
            'status' => 'active', 'created_by' => $actor->getKey(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $institutionId = (int) $connection->table('institutions')->insertGetId([
            'tenant_id' => $tenantId, 'official_name' => 'Phase 07 concurrency institution', 'display_name' => 'Phase 07 race fixture',
            'type' => 'academy', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $permissionKeys = ['learning.delivery.view', 'learning.delivery.manage', 'learning.delivery.teach', 'learning.delivery.review', 'learning.delivery.publish', 'learning.delivery.learn'];
        $permissionIds = $connection->table('permissions')->whereIn('key', $permissionKeys)->pluck('id', 'key');
        if ($permissionIds->count() !== count($permissionKeys)) {
            throw new RuntimeException('Required Phase 07 permissions are absent.');
        }
        $roleIds = [];
        foreach (['owner', 'student', 'teacher'] as $roleKey) {
            $roleIds[$roleKey] = (int) $connection->table('roles')->insertGetId([
                'tenant_id' => $tenantId, 'key' => 'phase07-race-'.$roleKey, 'name' => 'Phase 07 race '.$roleKey,
                'scope' => 'tenant', 'description' => 'Temporary guarded concurrency fixture.', 'is_system' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $grants = [
            'owner' => $permissionKeys,
            'student' => ['learning.delivery.view', 'learning.delivery.learn'],
            'teacher' => ['learning.delivery.view', 'learning.delivery.teach'],
        ];
        foreach ($grants as $role => $keys) {
            foreach ($keys as $key) {
                $connection->table('permission_role')->insert(['role_id' => $roleIds[$role], 'permission_id' => $permissionIds[$key], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $membershipIds = [];
        foreach (['owner' => $ids['users'][0], 'student' => $ids['users'][1], 'teacher' => $ids['users'][2]] as $role => $userId) {
            $membershipIds[$role] = (int) $connection->table('tenant_memberships')->insertGetId([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'user_id' => $userId,
                'status' => 'active', 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $connection->table('membership_role')->insert([
                'membership_id' => $membershipIds[$role], 'role_id' => $roleIds[$role], 'assigned_by' => $ids['users'][0],
                'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $courseId = (int) $connection->table('academic_courses')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'code' => 'RACE-'.substr(str_replace('-', '', $runKey), 0, 12),
            'title' => 'Phase 07 concurrency course', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $courseVersionId = (int) $connection->table('academic_course_versions')->insertGetId([
            'tenant_id' => $tenantId, 'academic_course_id' => $courseId, 'version_number' => 1, 'title' => 'Race fixture v1',
            'credit_bearing' => false, 'theory_credits' => 0, 'laboratory_credits' => 0, 'total_credits' => 0,
            'delivery_modes' => json_encode(['online'], JSON_THROW_ON_ERROR), 'status' => 'published', 'is_current' => true,
            'created_by' => $ids['users'][0], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $workspaceId = (int) $connection->table('course_delivery_workspaces')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'academic_course_id' => $courseId,
            'academic_course_version_id' => $courseVersionId, 'public_id' => (string) Str::uuid(), 'code' => 'RACE',
            'title' => 'Phase 07 race workspace', 'delivery_mode' => 'blended', 'status' => 'published', 'lock_version' => 1,
            'created_by' => $ids['users'][0], 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $moduleId = (int) $connection->table('course_delivery_modules')->insertGetId([
            'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'title' => 'Race module', 'sort_order' => 1, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ids += ['tenant' => $tenantId, 'institution' => $institutionId, 'workspace' => $workspaceId, 'module' => $moduleId,
            'membership_owner' => $membershipIds['owner'], 'membership_learner' => $membershipIds['student'], 'membership_teacher' => $membershipIds['teacher'],
            'course' => $courseId, 'course_version' => $courseVersionId, 'roles' => array_values($roleIds)];
        $connection->table('course_delivery_assignments')->insert([
            ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'membership_id' => $membershipIds['student'], 'assignment_role' => 'learner', 'sequence' => 1, 'status' => 'active', 'assigned_by' => $ids['users'][0], 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'membership_id' => $membershipIds['teacher'], 'assignment_role' => 'instructor', 'sequence' => 1, 'status' => 'active', 'assigned_by' => $ids['users'][0], 'created_at' => $now, 'updated_at' => $now],
        ]);
    });

    $tenantId = $ids['tenant'];
    $workspaceId = $ids['workspace'];
    $moduleId = $ids['module'];
    $ownerId = $ids['users'][0];
    $makeLesson = static function (string $suffix, string $status, int $author, array $snapshotBlocks) use ($connection, $now, $tenantId, $workspaceId, $moduleId): array {
        $lessonId = (int) $connection->table('course_delivery_lessons')->insertGetId([
            'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'title' => 'Race lesson '.$suffix,
            'learning_objectives' => 'Verify persisted concurrency invariants.', 'estimated_minutes' => 10, 'sort_order' => (int) (microtime(true) * 1000) % 100000,
            'status' => $status, 'lock_version' => 1, 'created_by' => $author, 'draft_author_user_id' => $author,
            'published_by' => $status === 'published' ? $author : null, 'published_at' => $status === 'published' ? $now : null,
            'published_version_number' => $status === 'published' ? 1 : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($status !== 'published') {
            return ['lesson' => $lessonId, 'blocks' => []];
        }
        $blocks = [];
        foreach ($snapshotBlocks as $position => $block) {
            $blockId = (int) $connection->table('course_delivery_blocks')->insertGetId([
                'tenant_id' => $tenantId, 'lesson_id' => $lessonId, 'sort_order' => $position + 1,
                'block_type' => $block['type'], 'content' => json_encode($block['content'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $blocks[] = ['id' => $blockId, ...$block];
        }
        $snapshot = ['title' => 'Race lesson '.$suffix, 'summary' => null, 'learning_objectives' => 'Verify persisted concurrency invariants.',
            'estimated_minutes' => 10, 'blocks' => $blocks];
        $checksum = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $connection->table('course_delivery_lesson_revisions')->insert([
            'tenant_id' => $tenantId, 'lesson_id' => $lessonId, 'version_number' => 1, 'lesson_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'content_checksum' => $checksum, 'authored_by' => $author, 'reviewed_by' => $author, 'published_by' => $author,
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['lesson' => $lessonId, 'blocks' => $blocks];
    };

    $simple = $makeLesson('completion', 'published', $ownerId, [['type' => 'text', 'content' => ['text' => 'Complete once.']]]);
    $completionLessonId = $simple['lesson'];
    $nowSql = $now;
    $connection->table('course_delivery_progress')->insert([
        'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'lesson_id' => $completionLessonId,
        'membership_id' => $ids['membership_learner'], 'revision_version' => 1, 'status' => 'started', 'resume_seconds' => 4,
        'started_at' => $nowSql, 'last_activity_at' => $nowSql, 'created_at' => $nowSql, 'updated_at' => $nowSql,
    ]);
    $shared = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'actor_id' => $ownerId, 'lesson_id' => $completionLessonId,
        'membership_id' => $ids['membership_learner']];
    $completion = $race('complete', [...$shared, 'actor_id' => $ids['users'][1], 'membership_id' => $ids['membership_learner']], [...$shared, 'actor_id' => $ids['users'][1], 'membership_id' => $ids['membership_learner']]);
    $count++;
    $progress = $connection->table('course_delivery_progress')->where('lesson_id', $completionLessonId)->where('membership_id', $ids['membership_learner'])->first();
    $completionAudits = $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'learning.delivery.lesson.completed')->where('subject_id', (string) $progress->id)->count();
    if ($progress->status !== 'completed' || $progress->completed_at === null || $completionAudits !== 1) {
        throw new RuntimeException('Simultaneous learner completion violated progress/audit idempotency.');
    }

    $activityLesson = $makeLesson('confirmation', 'published', $ownerId, [
        ['type' => 'activity', 'content' => ['activity_type' => 'reflection', 'prompt' => 'Record learning.', 'required' => true]],
        ['type' => 'completion_policy', 'content' => ['require_all_required_activities' => true, 'require_instructor_confirmation' => true]],
    ]);
    $activityBlockId = (int) $activityLesson['blocks'][0]['id'];
    $confirmationLessonId = $activityLesson['lesson'];
    $connection->table('course_delivery_progress')->insert([
        'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'lesson_id' => $confirmationLessonId,
        'membership_id' => $ids['membership_learner'], 'revision_version' => 1, 'status' => 'started', 'resume_seconds' => 0,
        'started_at' => $nowSql, 'last_activity_at' => $nowSql, 'learner_confirmed_at' => $nowSql,
        'created_at' => $nowSql, 'updated_at' => $nowSql,
    ]);
    $confirmationBase = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'lesson_id' => $confirmationLessonId,
        'learner_membership_id' => $ids['membership_learner']];
    $confirmation = $race('confirm_or_respond', [...$confirmationBase, 'kind' => 'confirm', 'actor_id' => $ids['users'][2], 'membership_id' => $ids['membership_teacher']],
        [...$confirmationBase, 'kind' => 'respond', 'activity_id' => $activityBlockId, 'actor_id' => $ids['users'][1], 'membership_id' => $ids['membership_learner']]);
    $count++;
    $submitted = $connection->table('course_delivery_activity_responses')->where('lesson_id', $confirmationLessonId)->where('membership_id', $ids['membership_learner'])->where('state', 'submitted')->count();
    $confirmationProgress = $connection->table('course_delivery_progress')->where('lesson_id', $confirmationLessonId)->where('membership_id', $ids['membership_learner'])->first();
    if ($submitted !== 1 || ($confirmationProgress->status === 'completed' && $confirmationProgress->instructor_confirmed_at === null)) {
        throw new RuntimeException('Instructor confirmation raced learner response into an invalid state.');
    }
    $responseAuditCount = $connection->table('audit_events')->where('tenant_id', $tenantId)
        ->where('event_type', 'learning.delivery.activity_response.saved')->count();
    $confirmationAuditCount = $connection->table('audit_events')->where('tenant_id', $tenantId)
        ->where('event_type', 'learning.delivery.lesson.instructor_completion_confirmed')->count();
    if ($responseAuditCount !== 1 || ($confirmationProgress->status === 'completed' && $confirmationAuditCount !== 1)) {
        throw new RuntimeException('Confirmation/response race did not preserve its audit evidence.');
    }

    $releaseDraft = $makeLesson('release-draft', 'draft', $ownerId, []);
    $release = $race('release', ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'lesson_id' => $releaseDraft['lesson'], 'actor_id' => $ownerId, 'membership_id' => $ids['membership_owner'], 'kind' => 'a'],
        ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'lesson_id' => $releaseDraft['lesson'], 'actor_id' => $ownerId, 'membership_id' => $ids['membership_owner'], 'kind' => 'b']);
    $count++;
    if (count(array_filter($release, static fn (array $result): bool => $result['outcome'] === 'success')) !== 1
        || count(array_filter($release, static fn (array $result): bool => $result['outcome'] === 'conflict')) !== 1
        || (int) $connection->table('course_delivery_lessons')->where('id', $releaseDraft['lesson'])->value('lock_version') !== 2
        || $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'learning.delivery.lesson.updated')
            ->where('subject_id', (string) $releaseDraft['lesson'])->count() !== 1) {
        throw new RuntimeException('Concurrent release policy edits did not preserve optimistic lock invariants.');
    }

    $destinationId = (int) $connection->table('course_delivery_workspaces')->insertGetId([
        'tenant_id' => $tenantId, 'institution_id' => $ids['institution'], 'academic_course_id' => $ids['course'], 'academic_course_version_id' => $ids['course_version'],
        'public_id' => (string) Str::uuid(), 'code' => 'COPY', 'title' => 'Template race destination', 'delivery_mode' => 'self_paced',
        'status' => 'draft', 'lock_version' => 1, 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sourceModuleId = $moduleId;
    $sourceLesson = (int) $simple['lesson'];
    $templateSnapshot = ['schema_version' => 1, 'provenance' => ['source_workspace_id' => $workspaceId, 'source_institution_id' => $ids['institution'], 'course_version_id' => $ids['course_version']],
        'modules' => [['source_id' => $sourceModuleId, 'title' => 'Race module', 'summary' => null, 'lessons' => [[
            'source_id' => $sourceLesson, 'title' => 'Copy source lesson', 'summary' => null, 'learning_objectives' => 'Copy safely.',
            'estimated_minutes' => 10, 'blocks' => [['type' => 'text', 'content' => ['text' => 'Copy-safe content.']]],
        ]]]], 'lesson_count' => 1];
    $canonicalize = static function (array $value) use (&$canonicalize): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };
    $snapshotCanonical = json_encode($canonicalize($templateSnapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $templateId = (int) $connection->table('course_delivery_templates')->insertGetId([
        'tenant_id' => $tenantId, 'institution_id' => $ids['institution'], 'name' => 'Race template', 'slug' => $runKey,
        'current_version' => 1, 'status' => 'published', 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $connection->table('course_delivery_template_versions')->insert([
        'tenant_id' => $tenantId, 'template_id' => $templateId, 'version_number' => 1, 'source_workspace_id' => $workspaceId,
        'content_snapshot' => json_encode($templateSnapshot, JSON_THROW_ON_ERROR), 'content_checksum' => hash('sha256', $snapshotCanonical),
        'authored_by' => $ownerId, 'published_by' => $ownerId, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $copyKey = (string) Str::uuid();
    $copyBase = ['tenant_id' => $tenantId, 'workspace_id' => $destinationId, 'actor_id' => $ownerId, 'membership_id' => $ids['membership_owner'],
        'template_id' => $templateId, 'module_id' => $sourceModuleId, 'source_lesson_id' => $sourceLesson, 'idempotency_key' => $copyKey];
    $copies = $race('copy', $copyBase, $copyBase);
    $count++;
    if (count(array_filter($copies, static fn (array $result): bool => $result['outcome'] === 'success')) !== 2
        || $connection->table('course_delivery_copy_operations')->where('tenant_id', $tenantId)->where('idempotency_key', $copyKey)->count() !== 1
        || $connection->table('course_delivery_modules')->where('workspace_id', $destinationId)->count() !== 1
        || $connection->table('course_delivery_lessons')->where('workspace_id', $destinationId)->count() !== 1
        || $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'learning.delivery.template.copied')->count() !== 1) {
        throw new RuntimeException('Simultaneous identical template copies were not idempotent.');
    }

    $sessionId = (int) $connection->table('course_delivery_live_sessions')->insertGetId([
        'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'instructor_membership_id' => $ids['membership_teacher'],
        'title' => 'Race session', 'delivery_type' => 'online', 'timezone' => 'UTC', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        'status' => 'scheduled', 'created_by' => $ownerId, 'updated_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sessionBase = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'actor_id' => $ownerId, 'membership_id' => $ids['membership_owner'], 'session_id' => $sessionId];
    $sessions = $race('cancel', [...$sessionBase, 'kind' => 'a'], [...$sessionBase, 'kind' => 'b']);
    $count++;
    if (count(array_filter($sessions, static fn (array $result): bool => $result['outcome'] === 'success')) !== 1
        || count(array_filter($sessions, static fn (array $result): bool => $result['outcome'] === 'conflict')) !== 1
        || $connection->table('course_delivery_live_sessions')->where('id', $sessionId)->value('status') !== 'cancelled'
        || $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'learning.delivery.session.cancelled')->count() !== 1) {
        throw new RuntimeException('Concurrent live-session cancellation did not serialize to one audited transition.');
    }

    fwrite(STDOUT, "Phase 07 two-worker MySQL races passed: {$count}/5 scenarios; completion idempotency, activity/confirmation invariant, release optimistic lock, identical template copy, and session transition.\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach ($processes as $entry) {
        if (is_resource($entry['process'])) {
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                proc_terminate($entry['process']);
                proc_close($entry['process']);
            }
        }
    }
    if (isset($ids['tenant'])) {
        try {
            $tenantId = (int) $ids['tenant'];
            $connection->transaction(function () use ($connection, $tenantId, $ids): void {
                $workspaceIds = $connection->table('course_delivery_workspaces')->where('tenant_id', $tenantId)->pluck('id');
                $lessonIds = $connection->table('course_delivery_lessons')->where('tenant_id', $tenantId)->pluck('id');
                $connection->table('audit_events')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_activity_responses')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_copy_operations')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_template_versions')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_template_shares')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_templates')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_live_sessions')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_release_exceptions')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_progress')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_lesson_revisions')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_blocks')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_assignments')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_lessons')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_modules')->where('tenant_id', $tenantId)->delete();
                $connection->table('course_delivery_workspaces')->whereIn('id', $workspaceIds)->delete();
                $connection->table('academic_course_versions')->where('tenant_id', $tenantId)->delete();
                $connection->table('academic_courses')->where('tenant_id', $tenantId)->delete();
                $connection->table('membership_role')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenant_memberships')->where('tenant_id', $tenantId)->delete();
                $connection->table('permission_role')->whereIn('role_id', $ids['roles'] ?? [])->delete();
                $connection->table('roles')->where('tenant_id', $tenantId)->delete();
                $connection->table('institutions')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenants')->where('id', $tenantId)->delete();
                $connection->table('users')->whereIn('id', $ids['users'] ?? [])->delete();
                foreach (['course_delivery_workspaces', 'course_delivery_lessons', 'course_delivery_modules', 'academic_courses', 'tenant_memberships', 'roles', 'institutions', 'tenants'] as $table) {
                    $ownershipColumn = $table === 'tenants' ? 'id' : 'tenant_id';
                    if ($connection->table($table)->where($ownershipColumn, $tenantId)->exists()) {
                        throw new RuntimeException('A Phase 07 race fixture row remained after cleanup.');
                    }
                }
                if ($connection->table('users')->whereIn('id', $ids['users'] ?? [])->exists()) {
                    throw new RuntimeException('A Phase 07 race fixture user remained after cleanup.');
                }
            });
        } catch (Throwable $cleanupError) {
            $failure ??= $cleanupError;
        }
    }
    foreach ($coordination as $path) {
        if (is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
    }
}

if ($failure instanceof Throwable) {
    $type = class_basename($failure);
    fwrite(STDERR, 'Phase 07 two-worker race probe failed ('.$type.'): '.$failure->getMessage().PHP_EOL);
    exit(1);
}

exit(0);
