<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Dotenv\Dotenv;
use Tests\Support\Phase07MySqlGrantPolicy;
use Tests\Support\Phase07MySqlTestTarget;

$base = dirname(__DIR__, 2);
$configuration = Dotenv::createMutable($base, '.env.phase07.testing')->safeLoad();
$slug = $argv[1] ?? '';
if (! preg_match('/^phase07-learning-[a-f0-9-]{36}$/', $slug)) {
    fwrite(STDERR, "Refused cleanup: provide the exact generated Phase 07 learning probe tenant slug.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        'mysql:host='.$configuration['DB_HOST'].';port='.$configuration['DB_PORT'].';dbname='.$configuration['DB_DATABASE'].';charset=utf8mb4',
        (string) $configuration['DB_USERNAME'], (string) $configuration['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $identity = $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, VERSION() AS version, @@port AS port')->fetch();
    [$user, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
    $user = trim($user, "`'\"");
    $host = trim($host, "`'\"");
    Phase07MySqlGrantPolicy::assertIdentity((string) $identity['db'], $user, $host);
    if (strcasecmp($user, (string) $configuration['DB_USERNAME']) !== 0 || $host !== '127.0.0.1'
        || (int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Authenticated identity does not match the dedicated Phase 07 MySQL test target.');
    }
    Phase07MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $user, $host);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $lock->execute(['name' => Phase07MySqlTestTarget::SERVER_LOCK]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('The guarded Phase 07 MySQL runner lock is held; cleanup refused.');
    }

    $tenantQuery = $pdo->prepare('SELECT id, created_by FROM tenants WHERE slug = :slug');
    $tenantQuery->execute(['slug' => $slug]);
    $tenant = $tenantQuery->fetch();
    if (! is_array($tenant)) {
        throw new RuntimeException('The exact synthetic fixture tenant does not exist.');
    }
    $tenantId = (int) $tenant['id'];
    $users = $pdo->prepare('SELECT id, email FROM users WHERE email LIKE :prefix ORDER BY id');
    $users->execute(['prefix' => $slug.'-%@example.test']);
    $userRows = $users->fetchAll();
    $expectedEmails = [$slug.'-owner@example.test', $slug.'-learner@example.test', $slug.'-teacher@example.test'];
    if (array_column($userRows, 'email') !== $expectedEmails || count($userRows) !== 3
        || (int) $tenant['created_by'] !== (int) $userRows[0]['id']) {
        throw new RuntimeException('Synthetic user ownership does not exactly match the generated Phase 07 race fixture.');
    }
    $expected = [
        'institutions' => 1, 'roles' => 3, 'tenant_memberships' => 3, 'membership_role' => 3,
        'academic_courses' => 1, 'academic_course_versions' => 1, 'course_delivery_workspaces' => 2,
        'course_delivery_assignments' => 2, 'course_delivery_modules' => 2, 'course_delivery_lessons' => 4,
        'course_delivery_blocks' => 5, 'course_delivery_lesson_revisions' => 2, 'course_delivery_progress' => 2,
        'course_delivery_activity_responses' => 1, 'course_delivery_templates' => 1, 'course_delivery_template_versions' => 1,
        'course_delivery_template_shares' => 0, 'course_delivery_copy_operations' => 1, 'course_delivery_live_sessions' => 1,
        'course_delivery_release_exceptions' => 0, 'audit_events' => 10,
    ];
    foreach ($expected as $table => $expectedCount) {
        $query = $pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE tenant_id = :tenant');
        $query->execute(['tenant' => $tenantId]);
        if ((int) $query->fetchColumn() !== $expectedCount) {
            throw new RuntimeException('Fixture table inventory changed unexpectedly: '.$table.'.');
        }
    }

    $pdo->beginTransaction();
    try {
        foreach (['audit_events', 'course_delivery_activity_responses', 'course_delivery_copy_operations', 'course_delivery_template_versions',
            'course_delivery_template_shares', 'course_delivery_templates', 'course_delivery_live_sessions', 'course_delivery_release_exceptions',
            'course_delivery_progress', 'course_delivery_lesson_revisions', 'course_delivery_blocks', 'course_delivery_assignments',
            'course_delivery_lessons', 'course_delivery_modules', 'course_delivery_workspaces', 'academic_course_versions', 'academic_courses',
            'membership_role', 'tenant_memberships'] as $table) {
            $query = $pdo->prepare('DELETE FROM `'.$table.'` WHERE tenant_id = :tenant');
            $query->execute(['tenant' => $tenantId]);
        }
        $pdo->prepare('DELETE FROM permission_role WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = :tenant)')->execute(['tenant' => $tenantId]);
        $pdo->prepare('DELETE FROM roles WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
        $pdo->prepare('DELETE FROM institutions WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :tenant AND slug = :slug')->execute(['tenant' => $tenantId, 'slug' => $slug]);
        $deleteUsers = $pdo->prepare('DELETE FROM users WHERE id IN (:owner, :learner, :teacher)');
        $deleteUsers->execute(['owner' => (int) $userRows[0]['id'], 'learner' => (int) $userRows[1]['id'], 'teacher' => (int) $userRows[2]['id']]);
        foreach (['tenants', 'institutions', 'roles', 'tenant_memberships', 'course_delivery_workspaces', 'academic_courses', 'users'] as $table) {
            if ($table === 'users') {
                $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id IN (:owner, :learner, :teacher)');
                $check->execute(['owner' => (int) $userRows[0]['id'], 'learner' => (int) $userRows[1]['id'], 'teacher' => (int) $userRows[2]['id']]);
            } else {
                $where = $table === 'tenants' ? 'id' : 'tenant_id';
                $check = $pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE `'.$where.'` = :tenant');
                $check->execute(['tenant' => $tenantId]);
            }
            if ((int) $check->fetchColumn() !== 0) {
                throw new RuntimeException('Exact fixture cleanup postcondition failed for '.$table.'.');
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name' => Phase07MySqlTestTarget::SERVER_LOCK]);
    }
    fwrite(STDOUT, "Removed only the exact verified failed Phase 07 synthetic race fixture; database identity, grants, lock, owner rows and expected fixture inventory were verified.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Safe Phase 07 probe cleanup refused or failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
