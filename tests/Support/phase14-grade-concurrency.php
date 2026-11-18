<?php

declare(strict_types=1);

use App\Domain\Grading\GradebookAssessmentSynchronizer;
use App\Domain\Grading\GradebookWorkflow;
use App\Domain\Grading\OfficialAcademicRecordWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicGradeEntry;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecordIssue;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Phase14MySqlTestTarget;
use Tests\Support\Phase14ProbeAuthorization;
use Tests\Support\Phase14ProbeEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = Phase14ProbeEnvironment::current();

try {
    Phase14ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), $environment);
    $identity = DB::selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id');
    if ($identity?->database_name !== Phase14MySqlTestTarget::DATABASE) {
        throw new RuntimeException('Phase 14 probe connection is not the exact authorized test schema.');
    }
    $lock = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [Phase14MySqlTestTarget::SERVER_LOCK]);
    if ($lock?->owner === null || ($environment['PHASE14_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true') {
        throw new RuntimeException('The Phase 14 guarded runner authorization and server lock are required.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase 14 concurrency harness refused database identity, grant policy, or guarded-runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false || is_link($framework)) {
    fwrite(STDERR, "Phase 14 harness requires the private framework coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    phase14Worker($argv, $root, $framework, $environment);
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 14 concurrency probe mode.\n");
    exit(2);
}

$fixture = null;
$exitStatus = 0;
try {
    phase14CleanupStaleFixtures();
    $fixture = phase14CreateFixture();
    phase14RunAll($fixture, $framework, $environment);
    fwrite(STDOUT, "PHASE14_CONCURRENCY_RESULT=PASS probes=8 independent_sessions=16 transactional_barriers=8\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PHASE14_CONCURRENCY_RESULT=FAIL '.$error::class.': '.$error->getMessage().PHP_EOL);
    $exitStatus = 1;
} finally {
    if (is_array($fixture)) {
        try {
            phase14CleanupOwnedFixture($fixture);
            fwrite(STDOUT, 'PHASE14_FIXTURE_CLEANUP=PASS owned_tenant_id='.$fixture['tenant_id'].PHP_EOL);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'PHASE14_FIXTURE_CLEANUP=INCOMPLETE owned_tenant_id='.$fixture['tenant_id'].' reason='.$cleanupError->getMessage().PHP_EOL);
            $exitStatus = 1;
        }
    }
}
exit($exitStatus);

/**
 * @param  array<int,string>  $argv
 * @param  array<string,string>  $environment
 */
function phase14Worker(array $argv, string $root, string $framework, array $environment): never
{
    try {
        $run = (string) ($argv[2] ?? '');
        $label = (string) ($argv[3] ?? '');
        $payload = json_decode(base64_decode((string) ($argv[4] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (! preg_match('/^[a-f0-9-]{36}$/', $run) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
            throw new RuntimeException('Invalid worker invocation data.');
        }
        $prefix = 'phase14-grade-'.$run;
        $ready = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
        $gate = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
        $resultPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['tenant_id']);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['membership_id']);
        if ((int) $membership->tenant_id !== (int) $tenant->getKey() || $membership->status !== 'active') {
            throw new RuntimeException('Worker tenant membership is inactive or belongs to another tenant.');
        }
        $context->set($tenant, $membership);
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        DB::beginTransaction();
        $anchor = DB::table('users')->where('id', (int) $payload['barrier_lock_user_id'])->lockForUpdate()->first(['id']);
        if ($anchor === null) {
            throw new RuntimeException('Worker could not lock its owned InnoDB barrier row.');
        }
        $session = DB::selectOne('SELECT CONNECTION_ID() AS id');
        phase14WriteExclusive($ready, json_encode(['connection_id' => (int) $session->id, 'transaction_active' => true, 'lock_anchor_user_id' => (int) $anchor->id], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 45;
        while (! is_file($gate) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($gate)) {
            throw new RuntimeException('Worker did not receive the synchronized start barrier.');
        }

        $startedAt = hrtime(true);
        $value = phase14Execute((string) $payload['operation'], $payload, $actor);
        DB::commit();
        $result = ['outcome' => 'success', 'actor_id' => (int) $payload['actor_id'], 'connection_id' => (int) $session->id, 'transaction_active_at_barrier' => true, 'elapsed_ns' => hrtime(true) - $startedAt, 'value' => $value];
    } catch (Throwable $error) {
        if (DB::connection()->transactionLevel() > 0) {
            try {
                DB::rollBack();
            } catch (Throwable) {
            }
        }
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
        $isConflict = $error instanceof ConflictHttpException || $status === 409 || $error instanceof QueryException && str_contains($error->getMessage(), 'Duplicate entry');
        $result = ['outcome' => $isConflict ? 'conflict' : 'error', 'actor_id' => (int) ($payload['actor_id'] ?? 0), 'connection_id' => isset($session) ? (int) $session->id : 0, 'transaction_active_at_barrier' => isset($session), 'error_class' => $error::class, 'status' => $status, 'message' => $isConflict ? 'concurrent state conflict' : $error->getMessage()];
    } finally {
        if (isset($context)) {
            $context->clear();
        }
    }

    if (isset($resultPath)) {
        phase14WriteExclusive($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    if ($result['outcome'] === 'error') {
        fwrite(STDERR, $result['error_class'].': '.$result['message'].PHP_EOL);
        exit(1);
    }
    exit(0);
}

/**
 * @param  array<string,mixed>  $payload
 * @return array<string,mixed>
 */
function phase14Execute(string $operation, array $payload, User $actor): array
{
    return match ($operation) {
        'save_entry' => phase14EntryResult(app(GradebookWorkflow::class)->saveEntry(
            (int) $payload['gradebook_id'], (int) $payload['item_id'], (int) $payload['enrollment_id'],
            ['earned_points' => (string) $payload['points'], 'entry_status' => 'graded', 'feedback' => null,
                'expected_lock_version' => (int) $payload['expected_version'], 'reason' => (string) $payload['reason']], $actor)),
        'synchronize' => app(GradebookAssessmentSynchronizer::class)->synchronize((int) $payload['gradebook_id'], (int) $payload['item_id'], $actor),
        'workspace_calculate' => phase14WorkspaceResult(app(GradebookWorkflow::class)->gradebookWorkspace((int) $payload['gradebook_id'], $actor)),
        'approve' => ['status' => app(GradebookWorkflow::class)->transition((int) $payload['gradebook_id'], 'approve', $actor)->status],
        'publish' => ['status' => app(GradebookWorkflow::class)->transition((int) $payload['gradebook_id'], 'publish', $actor)->status],
        'decide_override' => ['status' => app(GradebookWorkflow::class)->decideOverride((int) $payload['override_id'], true, 'Phase 14 concurrent probe decision.', $actor)->status],
        'issue_record' => ['status' => app(OfficialAcademicRecordWorkflow::class)->issue((int) $payload['issue_id'], $actor)->status],
        'revoke_record' => ['status' => app(OfficialAcademicRecordWorkflow::class)->revoke((int) $payload['issue_id'], 'Phase 14 concurrent probe revocation.', $actor)->status],
        'prepare_record' => phase14IssueResult(app(OfficialAcademicRecordWorkflow::class)->prepareIssue((int) $payload['student_id'], (int) $payload['template_id'], null, null, $actor)),
        default => throw new RuntimeException('Unknown Phase 14 production operation: '.$operation),
    };
}

/** @return array<string,mixed> */
function phase14EntryResult(AcademicGradeEntry $entry): array
{
    return ['entry_id' => (int) $entry->getKey(), 'points' => (string) $entry->earned_points, 'lock_version' => (int) $entry->lock_version];
}

/**
 * @param  array<string,mixed>  $workspace
 * @return array<string,mixed>
 */
function phase14WorkspaceResult(array $workspace): array
{
    $row = $workspace['roster'][0] ?? null;

    return ['status' => (string) ($row['calculation']['status'] ?? 'missing'), 'percentage' => $row['calculation']['percentage'] ?? null];
}

/**
 * @return array<string,mixed>
 */
function phase14IssueResult(AcademicRecordIssue $issue): array
{
    return ['issue_id' => (int) $issue->getKey(), 'issue_number' => (int) $issue->issue_number, 'supersedes_id' => $issue->supersedes_id === null ? null : (int) $issue->supersedes_id, 'status' => $issue->status];
}

/**
 * Create one fully isolated synthetic institution and eight real gradebooks.
 *
 * @return array<string,mixed>
 */
function phase14CreateFixture(): array
{
    $run = (string) Str::uuid();
    $slug = 'phase14-concurrency-'.str_replace('-', '', $run);
    $creator = User::factory()->create();
    $owner = User::factory()->create();
    $reviewer = User::factory()->create();
    $operator = User::factory()->create();
    $studentUser = User::factory()->create();
    $studentUsers = [1 => $studentUser];
    for ($index = 2; $index <= 5; $index++) {
        $studentUsers[$index] = User::factory()->create();
    }
    $context = app(TenantContext::class);
    try {
        $provisioned = app(TenantProvisioner::class)->provision([
            'slug' => $slug, 'official_name' => 'Phase 14 concurrency '.$run, 'display_name' => 'Phase 14 concurrency '.$run,
            'code' => 'PH14-'.strtoupper(substr(str_replace('-', '', $run), 0, 10)), 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
    } catch (Throwable $failure) {
        foreach ([$creator, $owner, $reviewer, $operator, ...array_values($studentUsers)] as $fixtureUser) {
            DB::table('users')->where('id', $fixtureUser->getKey())->delete();
        }
        throw $failure;
    }
    $tenant = $provisioned->tenant;
    $fixture = ['run_id' => $run, 'tenant_id' => (int) $tenant->getKey(), 'slug' => $tenant->slug,
        'users' => ['creator' => (int) $creator->getKey(), 'owner' => (int) $owner->getKey(), 'reviewer' => (int) $reviewer->getKey(), 'operator' => (int) $operator->getKey(), ...array_combine(array_map(static fn (int $index): string => 'student'.$index, array_keys($studentUsers)), array_map(static fn (User $user): int => (int) $user->getKey(), $studentUsers))],
        'memberships' => [], 'student_id' => 0, 'enrollments' => [], 'gradebooks' => [], 'templates' => [], 'issues' => [],
        'barrier_user_ids' => [1 => (int) $studentUsers[1]->getKey(), 2 => (int) $studentUsers[2]->getKey()]];
    try {
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $fixture['institution_id'] = (int) $institution->getKey();
            foreach (['owner' => [$owner, 'tenant.owner'], 'reviewer' => [$reviewer, 'academic.admin'], 'operator' => [$operator, 'tenant.admin']] as $key => [$user, $roleKey]) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
                $fixture['memberships'][$key] = (int) $membership->getKey();
            }
            foreach ($studentUsers as $index => $user) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
                $fixture['memberships']['student'.$index] = (int) $membership->getKey();
            }
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Concurrency year', 'code' => 'Y-'.substr(str_replace('-', '', $run), 0, 10), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Concurrency term', 'code' => 'T-'.substr(str_replace('-', '', $run), 0, 10), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'C-'.substr(str_replace('-', '', $run), 0, 10), 'title' => 'Phase 14 concurrency course', 'status' => 'active']);
            $enrollmentPolicy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Phase 14 concurrency registration', 'maximum_courses' => 10, 'created_by' => $owner->getKey()]);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $enrollmentPolicy->getKey(), 'offering_code' => 'O-'.substr(str_replace('-', '', $run), 0, 10), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $owner->getKey()]);
            $fixture['period_id'] = (int) $period->getKey();
            $fixture['offering_id'] = (int) $offering->getKey();
            $fixture['course_id'] = (int) $course->getKey();
            $ownerMembership = TenantMembership::query()->whereKey($fixture['memberships']['owner'])->firstOrFail();
            $students = [];
            foreach ($studentUsers as $index => $user) {
                $students[$index] = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'student_number' => 'S'.$index.'-'.substr(str_replace('-', '', $run), 0, 10), 'registration_reference' => 'R'.$index.'-'.substr(str_replace('-', '', $run), 0, 10), 'legal_first_name' => 'Concurrent', 'legal_last_name' => 'Learner '.$index, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            }
            $fixture['student_id'] = (int) $students[5]->getKey();
            $sections = [];
            for ($index = 1; $index <= 5; $index++) {
                $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'section_code' => 'S'.$index, 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
                $sections[$index] = (int) $section->getKey();
                $enrollmentId = (int) DB::table('course_enrollments')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'student_id' => $students[$index]->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'credits_snapshot' => '3.000', 'requested_at' => now(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $fixture['enrollments'][$index] = $enrollmentId;
            }

            $context->set($tenant, $ownerMembership);
            $workflow = app(GradebookWorkflow::class);
            $policy = $workflow->createPolicy(['name' => 'Concurrent grading policy', 'policy_key' => 'PH14-'.$slug, 'academic_period_id' => (int) $period->getKey(), 'scale_type' => 'percentage', 'rounding_mode' => 'half_up', 'precision' => 2, 'effective_from' => today()->toDateString(), 'effective_until' => null, 'categories' => [['category_key' => 'assessment', 'name' => 'Assessment', 'weight_basis_points' => 10000, 'required' => true]]], $owner);
            $policy = $workflow->activatePolicy((int) $policy->getKey(), $owner);
            $fixture['policy_id'] = (int) $policy->getKey();
            $categoryId = (int) $policy->categories->firstOrFail()->getKey();
            $assignmentSources = [
                2 => phase14CreateReleasedAssignment($fixture, $students[2], TenantMembership::query()->whereKey($fixture['memberships']['student2'])->firstOrFail(), $owner, $sections[2], $run.'-2'),
                3 => phase14CreateReleasedAssignment($fixture, $students[3], TenantMembership::query()->whereKey($fixture['memberships']['student3'])->firstOrFail(), $owner, $sections[3], $run.'-3'),
            ];
            for ($index = 1; $index <= 5; $index++) {
                $book = $workflow->createGradebook($sections[$index], (int) $policy->getKey(), $owner);
                $source = $assignmentSources[$index] ?? null;
                $item = $workflow->createItem((int) $book->getKey(), ['item_key' => 'ASSESSMENT-'.$index, 'title' => 'Concurrency assessment '.$index, 'category_id' => $categoryId, 'maximum_points' => '100.00', 'source_type' => $source === null ? 'manual' : 'assignment', 'source_key' => $source === null ? null : (string) $source['assignment_id']], $owner);
                $fixture['gradebooks'][$index] = ['id' => (int) $book->getKey(), 'item_id' => (int) $item->getKey(), 'enrollment_id' => (int) $fixture['enrollments'][$index], 'assignment_id' => $source['assignment_id'] ?? null, 'attempt_id' => $source['attempt_id'] ?? null];
                if (in_array($index, [1, 4, 5], true)) {
                    $workflow->saveEntry((int) $book->getKey(), (int) $item->getKey(), (int) $fixture['enrollments'][$index], ['earned_points' => '60.00', 'entry_status' => 'graded', 'feedback' => null, 'expected_lock_version' => null, 'reason' => 'Baseline entry for guarded concurrency verification.'], $owner);
                }
            }
            $context->clear();
        } finally {
            $context->clear();
        }

        return $fixture;
    } catch (Throwable $failure) {
        $context->clear();
        try {
            phase14CleanupOwnedFixture($fixture);
        } catch (Throwable $cleanupError) {
            throw new RuntimeException('Phase 14 fixture setup failed ('.$failure->getMessage().') and exact tenant-scoped cleanup was incomplete ('.$cleanupError->getMessage().'); preserve the dedicated schema for review.', 0, $failure);
        }
        throw $failure;
    }
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array{assignment_id:int,attempt_id:int}
 */
function phase14CreateReleasedAssignment(array $fixture, SisStudent $student, TenantMembership $studentMembership, User $owner, int $sectionId, string $suffix): array
{
    $now = now();
    $assignmentId = (int) DB::table('assignments')->insertGetId(['tenant_id' => $fixture['tenant_id'], 'institution_id' => $fixture['institution_id'], 'offering_id' => $fixture['offering_id'], 'created_by' => $owner->getKey(), 'title' => 'Concurrency source '.$suffix, 'assignment_type' => 'coursework', 'collaboration_mode' => 'individual', 'maximum_attempts' => 1, 'maximum_points' => '20.00', 'timezone' => 'UTC', 'status' => 'published', 'review_required' => false, 'current_version' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('assignment_versions')->insert(['tenant_id' => $fixture['tenant_id'], 'assignment_id' => $assignmentId, 'version_number' => 1, 'snapshot' => json_encode(['title' => 'Concurrency source'], JSON_THROW_ON_ERROR), 'status' => 'published', 'created_by' => $owner->getKey(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $recipientId = (int) DB::table('assignment_recipients')->insertGetId(['tenant_id' => $fixture['tenant_id'], 'assignment_id' => $assignmentId, 'membership_id' => $studentMembership->getKey(), 'student_id' => $student->getKey(), 'section_id' => $sectionId, 'eligible_from' => $now, 'status' => 'eligible', 'created_at' => $now, 'updated_at' => $now]);
    $submissionId = (int) DB::table('assignment_submissions')->insertGetId(['tenant_id' => $fixture['tenant_id'], 'assignment_id' => $assignmentId, 'recipient_id' => $recipientId, 'status' => 'graded', 'attempt_count' => 1, 'lock_version' => 1, 'updated_by' => $owner->getKey(), 'submitted_at' => $now, 'finalized_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $attemptId = (int) DB::table('assignment_attempts')->insertGetId(['tenant_id' => $fixture['tenant_id'], 'submission_id' => $submissionId, 'attempt_number' => 1, 'status' => 'submitted', 'receipt_reference' => 'PH14-'.strtoupper(Str::random(12)), 'is_late' => false, 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('assignment_grades')->insert(['tenant_id' => $fixture['tenant_id'], 'attempt_id' => $attemptId, 'score_points' => '15.00', 'maximum_points' => '20.00', 'status' => 'released', 'marked_by' => $owner->getKey(), 'released_by' => $owner->getKey(), 'released_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);

    return ['assignment_id' => $assignmentId, 'attempt_id' => $attemptId];
}

/**
 * @param  array<string,mixed>  $fixture
 * @param  array<string,string>  $environment
 */
function phase14RunAll(array $fixture, string $framework, array $environment): void
{
    $book1 = $fixture['gradebooks'][1];
    $entry1 = phase14EntryId($fixture, $book1);
    $baseAudit = phase14AuditCount($fixture, 'academic.grade_entry.saved');
    $race = phase14Race('grade-edit', $fixture, [
        ['operation' => 'save_entry', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book1['id'], 'item_id' => $book1['item_id'], 'enrollment_id' => $book1['enrollment_id'], 'expected_version' => 1, 'points' => '71.00', 'reason' => 'Concurrent edit A.'],
        ['operation' => 'save_entry', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book1['id'], 'item_id' => $book1['item_id'], 'enrollment_id' => $book1['enrollment_id'], 'expected_version' => 1, 'points' => '72.00', 'reason' => 'Concurrent edit B.'],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'conflict']);
    $entry = phase14ReadEntry($fixture, $entry1);
    phase14Assert((int) $entry['lock_version'] === 2 && in_array((string) $entry['earned_points'], ['71.00', '72.00'], true), 'probe 1 must retain exactly one conflict-checked grade change');
    phase14Assert($entry['revision_count'] === 2 && phase14AuditCount($fixture, 'academic.grade_entry.saved') === $baseAudit + 1, 'probe 1 revision and audit history must have one committed edit');
    phase14Report(1, $race, 'one version-checked edit committed; second was rejected');

    $book2 = $fixture['gradebooks'][2];
    $baseAudit = phase14AuditCount($fixture, 'academic.grade_source.imported');
    $race = phase14Race('duplicate-source-import', $fixture, [
        ['operation' => 'synchronize', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book2['id'], 'item_id' => $book2['item_id']],
        ['operation' => 'synchronize', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book2['id'], 'item_id' => $book2['item_id']],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'success']);
    phase14Assert(DB::table('academic_grade_source_imports')->where('tenant_id', $fixture['tenant_id'])->where('item_id', $book2['item_id'])->count() === 1, 'probe 2 must produce one logical source import');
    phase14Assert(DB::table('academic_grade_source_import_revisions')->where('tenant_id', $fixture['tenant_id'])->count() === 1, 'probe 2 duplicate import must not duplicate source revision');
    phase14Assert(phase14AuditCount($fixture, 'academic.grade_source.imported') === $baseAudit + 1, 'probe 2 must write one import audit event');
    phase14Assert(DB::table('academic_grade_entries')->where('tenant_id', $fixture['tenant_id'])->where('item_id', $book2['item_id'])->value('earned_points') === '75.00', 'probe 2 imported score must be committed once');
    phase14Report(2, $race, 'both service calls completed idempotently; one import/revision/audit committed');

    $book3 = $fixture['gradebooks'][3];
    phase14As($fixture, 'owner', fn (User $owner) => app(GradebookAssessmentSynchronizer::class)->synchronize($book3['id'], $book3['item_id'], $owner));
    $amendedSource = DB::table('assignment_grades')->where('tenant_id', $fixture['tenant_id'])->where('attempt_id', $book3['attempt_id'])->where('status', 'released')
        ->update(['score_points' => '17.00', 'lock_version' => 2, 'updated_at' => now()]);
    phase14Assert($amendedSource === 1, 'probe 3 must prepare exactly one owned source amendment before the concurrent import');
    $race = phase14Race('source-amendment-vs-calculation', $fixture, [
        ['operation' => 'synchronize', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book3['id'], 'item_id' => $book3['item_id']],
        ['operation' => 'workspace_calculate', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book3['id']],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'success']);
    $calculation = array_values(array_filter(array_column($race, 'value'), static fn ($value): bool => isset($value['status'])))[0] ?? [];
    phase14Assert(in_array($calculation['status'] ?? '', ['incomplete', 'complete'], true), 'probe 3 calculation must observe a complete committed snapshot or the prior incomplete snapshot');
    if (($calculation['status'] ?? '') === 'complete') {
        phase14Assert(in_array((string) ($calculation['percentage'] ?? ''), ['75.00', '85.00'], true), 'probe 3 calculation may observe only the complete old or amended source score');
    }
    phase14Assert(DB::table('academic_grade_source_imports')->where('tenant_id', $fixture['tenant_id'])->where('item_id', $book3['item_id'])->value('imported_points') === '85.00', 'probe 3 final source import must match the committed amended source version');
    $sourceImportId = DB::table('academic_grade_source_imports')->where('tenant_id', $fixture['tenant_id'])->where('item_id', $book3['item_id'])->value('id');
    phase14Assert(DB::table('academic_grade_source_import_revisions')->where('tenant_id', $fixture['tenant_id'])->where('import_id', $sourceImportId)->count() === 2, 'probe 3 source amendment must append a second immutable import revision');
    phase14Report(3, $race, 'source-version amendment synchronization and domain calculation overlapped; calculation observed one complete point-in-time version');

    $book4 = $fixture['gradebooks'][4];
    $entry4 = phase14EntryId($fixture, $book4);
    phase14As($fixture, 'owner', function (User $owner) use ($book4): void {
        app(GradebookWorkflow::class)->transition($book4['id'], 'submit', $owner);
    });
    $baseAudit = phase14AuditCount($fixture, 'academic.grade_entry.saved');
    $race = phase14Race('approval-vs-edit', $fixture, [
        ['operation' => 'approve', 'actor_id' => $fixture['users']['reviewer'], 'membership_id' => $fixture['memberships']['reviewer'], 'gradebook_id' => $book4['id']],
        ['operation' => 'save_entry', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book4['id'], 'item_id' => $book4['item_id'], 'enrollment_id' => $book4['enrollment_id'], 'expected_version' => 1, 'points' => '67.00', 'reason' => 'Concurrent edit before approval.'],
    ], $framework, $environment);
    phase14Assert(count(array_filter($race, static fn (array $result): bool => $result['outcome'] === 'success' && ($result['value']['status'] ?? null) === 'approved')) === 1, 'probe 4 must commit one authorized approval');
    phase14Assert(count(array_filter($race, static fn (array $result): bool => in_array($result['outcome'], ['success', 'conflict'], true))) === 2, 'probe 4 edit must commit in a valid order or be rejected as stale');
    phase14Assert(DB::table('academic_gradebooks')->where('tenant_id', $fixture['tenant_id'])->where('id', $book4['id'])->value('status') === 'approved', 'probe 4 gradebook must end approved');
    $entry = phase14ReadEntry($fixture, $entry4);
    phase14Assert(in_array((float) $entry['earned_points'], [60.0, 67.0], true) && (int) $entry['lock_version'] === ((float) $entry['earned_points'] === 60.0 ? 1 : 2), 'probe 4 approved grade must be a complete pre- or post-edit version');
    phase14Assert(phase14AuditCount($fixture, 'academic.gradebook.approved') === 1 && phase14AuditCount($fixture, 'academic.grade_entry.saved') === $baseAudit + (int) ((float) $entry['earned_points'] === 67.0), 'probe 4 audit state must match the committed edit ordering');
    phase14Report(4, $race, 'approval serialized against the versioned score edit');

    $book5 = $fixture['gradebooks'][5];
    phase14As($fixture, 'owner', function (User $owner) use ($book5): void {
        app(GradebookWorkflow::class)->transition($book5['id'], 'submit', $owner);
    });
    phase14As($fixture, 'reviewer', function (User $reviewer) use ($book5): void {
        app(GradebookWorkflow::class)->transition($book5['id'], 'approve', $reviewer);
    });
    $race = phase14Race('concurrent-publication', $fixture, [
        ['operation' => 'publish', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'gradebook_id' => $book5['id']],
        ['operation' => 'publish', 'actor_id' => $fixture['users']['operator'], 'membership_id' => $fixture['memberships']['operator'], 'gradebook_id' => $book5['id']],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'conflict']);
    phase14Assert(DB::table('academic_grade_publications')->where('tenant_id', $fixture['tenant_id'])->where('gradebook_id', $book5['id'])->count() === 1, 'probe 5 must publish once');
    phase14Assert(DB::table('academic_grade_publication_entries')->where('tenant_id', $fixture['tenant_id'])->where('gradebook_id', $book5['id'])->count() === 1, 'probe 5 must persist one official learner result');
    phase14Assert(phase14AuditCount($fixture, 'academic.grade_publication.created') === 1, 'probe 5 must produce one publication audit');
    phase14Assert(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.grade.published')->count() === 1, 'probe 5 must enqueue one logical grade publication event');
    phase14Report(5, $race, 'exactly one official publication and outbox event committed');

    $records = phase14PrepareOfficialRecords($fixture);
    $overrideId = phase14As($fixture, 'owner', function (User $owner) use ($book5, $fixture): int {
        $entryId = phase14EntryIdFor($book5, (int) $fixture['tenant_id']);

        return (int) app(GradebookWorkflow::class)->requestOverride($book5['id'], $entryId, '70.00', 'Concurrency correction request with evidence.', $owner)->getKey();
    });
    $baseRecordAudit = phase14AuditCount($fixture, 'academic.record.issued');
    $race = phase14Race('transcript-issue-vs-grade-correction', $fixture, [
        ['operation' => 'issue_record', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'issue_id' => $records['transcript_issue_id']],
        ['operation' => 'decide_override', 'actor_id' => $fixture['users']['reviewer'], 'membership_id' => $fixture['memberships']['reviewer'], 'override_id' => $overrideId],
    ], $framework, $environment);
    $outcomes = array_column($race, 'outcome');
    sort($outcomes);
    phase14Assert(in_array($outcomes, [['success', 'success'], ['conflict', 'success']], true), 'probe 6 must either issue the old immutable transcript before correction or reject its stale issuance');
    phase14Assert(phase14RaceOutcomeFor($race, $fixture['users']['reviewer'])['outcome'] === 'success', 'probe 6 authorized grade correction must commit');
    phase14Assert(DB::table('academic_grade_publications')->where('tenant_id', $fixture['tenant_id'])->where('gradebook_id', $book5['id'])->count() === 2, 'probe 6 correction must preserve the earlier official publication');
    $transcript = DB::table('academic_record_issues')->where('tenant_id', $fixture['tenant_id'])->where('id', $records['transcript_issue_id'])->first();
    if ($transcript->status === 'issued') {
        $snapshot = json_decode((string) $transcript->record_snapshot, true, 512, JSON_THROW_ON_ERROR);
        phase14Assert(($snapshot['courses'][0]['grade'] ?? null) === '60.00%', 'probe 6 issued transcript must match the pre-correction immutable grade snapshot');
    } else {
        phase14Assert($transcript->status === 'approved', 'probe 6 may leave the issue approved only when source correction won and issuance was rejected');
        $ownerResult = phase14RaceOutcomeFor($race, $fixture['users']['owner']);
        phase14Assert($ownerResult['outcome'] === 'conflict', 'probe 6 source correction must cause a stale transcript issuance to conflict');
    }
    phase14Assert(DB::table('academic_grade_entries')->where('tenant_id', $fixture['tenant_id'])->where('id', phase14EntryId($fixture, $book5))->value('earned_points') === '70.00', 'probe 6 corrected source must be current while historical publication remains unchanged');
    phase14Assert(phase14AuditCount($fixture, 'academic.grade_override.approved') === 1 && phase14AuditCount($fixture, 'academic.record.issued') === $baseRecordAudit + (int) ($transcript->status === 'issued'), 'probe 6 correction and transcript audit history must match committed state');
    phase14Assert(phase14AuditCount($fixture, 'academic.grade_publication.corrected') === 1 && DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.grade.published')->count() === 2, 'probe 6 correction must preserve revision audit and enqueue one additional publication notification');
    phase14Report(6, $race, 'transcript issue linearized before or rejected after the immutable grade correction');

    $certificate = phase14PrepareCertificate($fixture, $records['certificate_template_id']);
    $race = phase14Race('duplicate-certificate-issuance', $fixture, [
        ['operation' => 'issue_record', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'issue_id' => $certificate],
        ['operation' => 'issue_record', 'actor_id' => $fixture['users']['operator'], 'membership_id' => $fixture['memberships']['operator'], 'issue_id' => $certificate],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'conflict']);
    phase14Assert(DB::table('academic_record_issues')->where('tenant_id', $fixture['tenant_id'])->where('id', $certificate)->where('status', 'issued')->count() === 1, 'probe 7 certificate must be issued exactly once');
    phase14Assert(phase14AuditCountForSubject($fixture, 'academic.record.issued', AcademicRecordIssue::class, $certificate) === 1, 'probe 7 certificate must have exactly one issue audit');
    phase14Assert(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.record.issued')->where('safe_payload->record_issue_id', $certificate)->count() === 1, 'probe 7 must create one issuance outbox event');
    phase14Assert(app(OfficialAcademicRecordWorkflow::class)->verify((string) DB::table('academic_record_issues')->where('id', $certificate)->value('verification_code'))['valid'], 'probe 7 issued certificate must be publicly verifiable');
    phase14Report(7, $race, 'certificate lifecycle lock allowed one issue audit and one idempotent notification');

    $oldCertificate = phase14PrepareCertificate($fixture, $records['certificate_template_id']);
    phase14As($fixture, 'owner', fn (User $owner) => app(OfficialAcademicRecordWorkflow::class)->issue($oldCertificate, $owner));
    $oldCode = (string) DB::table('academic_record_issues')->where('id', $oldCertificate)->value('verification_code');
    $race = phase14Race('certificate-revoke-vs-reissue-prepare', $fixture, [
        ['operation' => 'revoke_record', 'actor_id' => $fixture['users']['operator'], 'membership_id' => $fixture['memberships']['operator'], 'issue_id' => $oldCertificate],
        ['operation' => 'prepare_record', 'actor_id' => $fixture['users']['owner'], 'membership_id' => $fixture['memberships']['owner'], 'student_id' => $fixture['student_id'], 'template_id' => $records['certificate_template_id']],
    ], $framework, $environment);
    phase14AssertOutcomes($race, ['success', 'success']);
    $newIssue = DB::table('academic_record_issues')->where('tenant_id', $fixture['tenant_id'])->where('record_type', 'certificate')->where('supersedes_id', $oldCertificate)->first();
    phase14Assert($newIssue !== null && (int) $newIssue->issue_number === (int) DB::table('academic_record_issues')->where('id', $oldCertificate)->value('issue_number') + 1 && $newIssue->status === 'draft', 'probe 8 reissue must preserve exact sequential lineage');
    phase14Assert(DB::table('academic_record_issues')->where('id', $oldCertificate)->value('status') === 'revoked', 'probe 8 previous certificate must end revoked');
    phase14As($fixture, 'owner', fn (User $owner) => app(OfficialAcademicRecordWorkflow::class)->submit((int) $newIssue->id, $owner));
    phase14As($fixture, 'reviewer', fn (User $reviewer) => app(OfficialAcademicRecordWorkflow::class)->decide((int) $newIssue->id, true, 'Independent reissue approval.', $reviewer));
    phase14As($fixture, 'operator', fn (User $operator) => app(OfficialAcademicRecordWorkflow::class)->issue((int) $newIssue->id, $operator));
    phase14Assert(! app(OfficialAcademicRecordWorkflow::class)->verify($oldCode)['valid'], 'probe 8 old verification code must remain invalid after revocation');
    $newCode = (string) DB::table('academic_record_issues')->where('id', $newIssue->id)->value('verification_code');
    phase14Assert(app(OfficialAcademicRecordWorkflow::class)->verify($newCode)['valid'], 'probe 8 only the newly issued certificate must verify as current');
    phase14Assert(phase14AuditCountForSubject($fixture, 'academic.record.revoked', AcademicRecordIssue::class, $oldCertificate) === 1 && phase14AuditCountForSubject($fixture, 'academic.record.issued', AcademicRecordIssue::class, (int) $newIssue->id) === 1, 'probe 8 revocation and reissue must both remain auditable');
    phase14Assert(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.record.revoked')->where('safe_payload->record_issue_id', $oldCertificate)->count() === 1
        && DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.record.issued')->where('safe_payload->record_issue_id', (int) $newIssue->id)->count() === 1, 'probe 8 must enqueue one revocation and one reissue notification for the exact records');
    phase14Report(8, $race, 'student lock serialized revocation and reissue preparation; verification lineage is correct');
}

/**
 * Start two real PHP workers and release both active MySQL transactions together.
 *
 * @param  array<string,mixed>  $fixture
 * @param  list<array<string,mixed>>  $workers
 * @param  array<string,string>  $environment
 * @return list<array<string,mixed>>
 */
function phase14Race(string $name, array $fixture, array $workers, string $framework, array $environment): array
{
    phase14Assert(count($workers) === 2, 'each Phase 14 probe must use exactly two independent workers');
    $run = (string) Str::uuid();
    $prefix = 'phase14-grade-'.$run;
    $gate = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $processes = [];
    $paths = [$gate];
    try {
        foreach (['a', 'b'] as $index => $label) {
            $ready = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
            $resultPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
            $stdoutPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stdout';
            $stderrPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stderr';
            array_push($paths, $ready, $resultPath, $stdoutPath, $stderrPath);
            $payload = [...$workers[$index], 'tenant_id' => (int) $fixture['tenant_id'], 'barrier_lock_user_id' => (int) $fixture['barrier_user_ids'][$index + 1]];
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'wb'], 2 => ['file', $stderrPath, 'wb']];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $run, $label, $encoded], $descriptors, $pipes, dirname(__DIR__, 2), $environment, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start '.$label.' worker for '.$name.'.');
            }
            fclose($pipes[0]);
            $processes[$label] = ['process' => $process, 'ready' => $ready, 'result' => $resultPath, 'stdout' => $stdoutPath, 'stderr' => $stderrPath];
        }

        $deadline = microtime(true) + 45;
        while (microtime(true) < $deadline) {
            $readyCount = count(array_filter($processes, static fn (array $entry): bool => is_file($entry['ready'])));
            if ($readyCount === 2) {
                break;
            }
            foreach ($processes as $label => $entry) {
                $status = proc_get_status($entry['process']);
                if (! $status['running'] && ! is_file($entry['ready'])) {
                    throw new RuntimeException($name.' worker '.$label.' exited before reaching the transaction barrier: '.trim((string) @file_get_contents($entry['stderr'])));
                }
            }
            usleep(10000);
        }
        phase14Assert(is_file($processes['a']['ready']) && is_file($processes['b']['ready']), $name.' workers did not both reach the bounded barrier');
        $readyA = json_decode((string) file_get_contents($processes['a']['ready']), true, 512, JSON_THROW_ON_ERROR);
        $readyB = json_decode((string) file_get_contents($processes['b']['ready']), true, 512, JSON_THROW_ON_ERROR);
        phase14Assert(($readyA['transaction_active'] ?? false) === true && ($readyB['transaction_active'] ?? false) === true, $name.' workers must both hold open transactions before release');
        phase14Assert((int) $readyA['connection_id'] !== (int) $readyB['connection_id'], $name.' must use distinct MySQL sessions');
        phase14AssertBarrierLock($name, (int) $readyA['lock_anchor_user_id']);
        phase14AssertBarrierLock($name, (int) $readyB['lock_anchor_user_id']);
        phase14WriteExclusive($gate, json_encode(['probe' => $name, 'released_at_ns' => hrtime(true)], JSON_THROW_ON_ERROR));

        $deadline = microtime(true) + 90;
        while (microtime(true) < $deadline) {
            if (is_file($processes['a']['result']) && is_file($processes['b']['result'])) {
                break;
            }
            foreach ($processes as $label => $entry) {
                if (! is_file($entry['result']) && ! proc_get_status($entry['process'])['running']) {
                    $stderr = trim((string) @file_get_contents($entry['stderr']));
                    $stdout = trim((string) @file_get_contents($entry['stdout']));
                    throw new RuntimeException($name.' worker '.$label.' exited without a result; stderr='.$stderr.' stdout='.$stdout);
                }
            }
            usleep(10000);
        }
        if (! is_file($processes['a']['result']) || ! is_file($processes['b']['result'])) {
            $diagnostics = [];
            foreach ($processes as $label => $entry) {
                $status = proc_get_status($entry['process']);
                $diagnostics[] = $label.'(running='.($status['running'] ? 'yes' : 'no').',stderr='.trim((string) @file_get_contents($entry['stderr'])).',stdout='.trim((string) @file_get_contents($entry['stdout'])).')';
            }
            throw new RuntimeException($name.' workers exceeded the bounded execution timeout: '.implode(' ', $diagnostics));
        }
        $results = [];
        foreach (['a', 'b'] as $label) {
            $result = json_decode((string) file_get_contents($processes[$label]['result']), true, 512, JSON_THROW_ON_ERROR);
            phase14Assert(is_array($result) && ($result['transaction_active_at_barrier'] ?? false) === true, $name.' worker result omitted active transaction evidence');
            phase14Assert((int) ($result['connection_id'] ?? 0) === (int) ($label === 'a' ? $readyA['connection_id'] : $readyB['connection_id']), $name.' worker session changed after the barrier');
            phase14Assert(in_array($result['outcome'] ?? '', ['success', 'conflict'], true), $name.' worker failed: '.($result['message'] ?? 'unknown worker error'));
            $results[] = $result;
        }
        foreach ($processes as $entry) {
            proc_close($entry['process']);
        }
        $processes = [];

        return $results;
    } finally {
        foreach ($processes as $entry) {
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                proc_terminate($entry['process']);
            }
            proc_close($entry['process']);
        }
        foreach ($paths as $path) {
            if (is_file($path) && ! is_link($path) && str_starts_with(realpath(dirname($path)) ?: '', $framework)) {
                @unlink($path);
            }
        }
    }
}

/**
 * Run a real service as a fixture-scoped actor and always clear ambient tenant context.
 *
 * @param  array<string,mixed>  $fixture
 * @param  Closure(User):mixed  $callback
 */
function phase14As(array $fixture, string $actorKey, Closure $callback): mixed
{
    $tenant = Tenant::query()->withoutGlobalScope('tenant')->findOrFail((int) $fixture['tenant_id']);
    $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail((int) $fixture['memberships'][$actorKey]);
    if ((int) $membership->tenant_id !== (int) $tenant->getKey() || $membership->status !== 'active') {
        throw new RuntimeException('Phase 14 fixture actor is outside the owned tenant or inactive.');
    }
    $context = app(TenantContext::class);
    $context->set($tenant, $membership);
    try {
        return $callback(User::query()->findOrFail((int) $fixture['users'][$actorKey]));
    } finally {
        $context->clear();
    }
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array<string,mixed>
 */
function phase14PrepareOfficialRecords(array $fixture): array
{
    $templateIds = phase14As($fixture, 'owner', function (User $owner) use ($fixture): array {
        $workflow = app(OfficialAcademicRecordWorkflow::class);
        $ids = [];
        foreach (['transcript', 'certificate'] as $type) {
            $template = $workflow->createTemplate(['name' => 'Phase 14 '.$type, 'template_key' => 'PH14-'.$type.'-'.str_replace('-', '', $fixture['run_id']), 'record_type' => $type, 'configuration' => []], $owner);
            $ids[$type] = (int) $workflow->activateTemplate((int) $template->getKey(), $owner)->getKey();
        }

        return $ids;
    });
    $transcriptId = phase14As($fixture, 'owner', function (User $owner) use ($fixture, $templateIds): int {
        $workflow = app(OfficialAcademicRecordWorkflow::class);
        $issue = $workflow->prepareIssue((int) $fixture['student_id'], $templateIds['transcript'], null, null, $owner);
        $workflow->submit((int) $issue->getKey(), $owner);

        return (int) $issue->getKey();
    });
    phase14As($fixture, 'reviewer', fn (User $reviewer) => app(OfficialAcademicRecordWorkflow::class)->decide($transcriptId, true, 'Approved for Phase 14 immutable snapshot concurrency.', $reviewer));

    return ['transcript_issue_id' => $transcriptId, 'certificate_template_id' => $templateIds['certificate']];
}

/** @param array<string,mixed> $fixture */
function phase14PrepareCertificate(array $fixture, int $templateId): int
{
    return phase14As($fixture, 'owner', function (User $owner) use ($fixture, $templateId): int {
        $workflow = app(OfficialAcademicRecordWorkflow::class);
        $issue = $workflow->prepareIssue((int) $fixture['student_id'], $templateId, null, null, $owner);
        $workflow->submit((int) $issue->getKey(), $owner);
        phase14As($fixture, 'reviewer', fn (User $reviewer) => app(OfficialAcademicRecordWorkflow::class)->decide((int) $issue->getKey(), true, 'Independent certificate approval for concurrency probe.', $reviewer));

        return (int) $issue->getKey();
    });
}

/**
 * @param  array<string,mixed>  $fixture
 * @param  array<string,mixed>  $gradebook
 */
function phase14EntryId(array $fixture, array $gradebook): int
{
    return phase14EntryIdFor($gradebook, (int) $fixture['tenant_id']);
}

/** @param array<string,mixed> $gradebook */
function phase14EntryIdFor(array $gradebook, int $tenantId): int
{
    return (int) DB::table('academic_grade_entries')->where('tenant_id', $tenantId)->where('gradebook_id', $gradebook['id'])->where('item_id', $gradebook['item_id'])->where('enrollment_id', $gradebook['enrollment_id'])->value('id');
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array{earned_points:?string,lock_version:int,revision_count:int}
 */
function phase14ReadEntry(array $fixture, int $entryId): array
{
    return phase14As($fixture, 'owner', function () use ($entryId): array {
        $entry = AcademicGradeEntry::query()->findOrFail($entryId);

        return ['earned_points' => $entry->earned_points === null ? null : (string) $entry->earned_points, 'lock_version' => (int) $entry->lock_version, 'revision_count' => $entry->revisions()->count()];
    });
}

/** @param array<string,mixed> $fixture */
function phase14AuditCount(array $fixture, string $eventType): int
{
    return (int) DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', $eventType)->count();
}

/** @param array<string,mixed> $fixture */
function phase14AuditCountForSubject(array $fixture, string $eventType, string $subjectType, int $subjectId): int
{
    return (int) DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', $eventType)->where('subject_type', $subjectType)->where('subject_id', (string) $subjectId)->count();
}

/**
 * @param  list<array<string,mixed>>  $results
 * @return array<string,mixed>
 */
function phase14RaceOutcomeFor(array $results, int $actorId): array
{
    foreach ($results as $result) {
        if ((int) ($result['actor_id'] ?? 0) === $actorId) {
            return $result;
        }
    }

    return [];
}

/**
 * @param  list<array<string,mixed>>  $results
 * @param  list<string>  $expected
 */
function phase14AssertOutcomes(array $results, array $expected): void
{
    $actual = array_column($results, 'outcome');
    sort($actual);
    sort($expected);
    phase14Assert($actual === $expected, 'unexpected concurrent outcomes: '.implode(',', $actual));
}

function phase14Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase14AssertBarrierLock(string $name, int $anchorUserId): void
{
    try {
        DB::select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [$anchorUserId]);
    } catch (QueryException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 3572) {
            return;
        }
        throw $exception;
    }

    throw new RuntimeException($name.' worker barrier did not hold an InnoDB row lock for fixture anchor '.$anchorUserId.'.');
}

/** @param list<array<string,mixed>> $results */
function phase14Report(int $number, array $results, string $invariant): void
{
    $sessions = array_values(array_unique(array_map(static fn (array $result): int => (int) $result['connection_id'], $results)));
    phase14Assert(count($sessions) === 2, 'Probe '.$number.' did not use independent MySQL sessions.');
    fwrite(STDOUT, sprintf("PROBE_%d=PASS sessions=%d outcomes=%s invariant=%s\n", $number, count($sessions), implode(',', array_column($results, 'outcome')), $invariant));
}

function phase14WriteExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 14 coordination file already exists or could not be created.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Could not persist the Phase 14 coordination barrier.');
        }
    } finally {
        fclose($handle);
    }
}

/** Delete only rows carrying this run's tenant id, then the exact tenant and fixture users. */
/** @param array<string,mixed> $fixture */
function phase14CleanupOwnedFixture(array $fixture): void
{
    $tenantId = (int) $fixture['tenant_id'];
    $database = (string) DB::selectOne('SELECT DATABASE() AS name')->name;
    $tables = DB::select('SELECT TABLE_NAME AS table_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? GROUP BY TABLE_NAME ORDER BY TABLE_NAME', [$database, 'tenant_id']);
    $tableNames = array_map(static fn (object $row): string => (string) $row->table_name, $tables);
    $tableNames = array_values(array_filter($tableNames, static function (string $table) use ($tenantId): bool {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Unexpected table identifier in Phase 14 fixture cleanup.');
        }

        return DB::table($table)->where('tenant_id', $tenantId)->exists();
    }));
    $foreignKeys = DB::select('SELECT TABLE_NAME AS child_table, REFERENCED_TABLE_NAME AS parent_table FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$database]);
    $remaining = array_fill_keys($tableNames, true);
    $ordered = [];
    while ($remaining !== []) {
        $candidate = null;
        foreach (array_keys($remaining) as $table) {
            $isParent = false;
            foreach ($foreignKeys as $foreignKey) {
                if ($foreignKey->parent_table === $table && $foreignKey->child_table !== $table && isset($remaining[$foreignKey->child_table])) {
                    $isParent = true;
                    break;
                }
            }
            if (! $isParent) {
                $candidate = $table;
                break;
            }
        }
        if ($candidate === null) {
            throw new RuntimeException('Owned fixture cleanup found a foreign-key cycle among these populated tenant tables: '.implode(',', array_keys($remaining)).'; no broad deletion attempted.');
        }
        $ordered[] = $candidate;
        unset($remaining[$candidate]);
    }
    foreach ($ordered as $table) {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Unexpected table identifier in Phase 14 fixture cleanup.');
        }
        $selfReference = array_filter($foreignKeys, static fn (object $foreignKey): bool => $foreignKey->child_table === $table && $foreignKey->parent_table === $table);
        if ($selfReference !== []) {
            $hasId = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', $table)->where('COLUMN_NAME', 'id')->exists();
            if (! $hasId) {
                throw new RuntimeException('Owned self-referencing fixture table '.$table.' has no stable id for child-first deletion.');
            }
            // This fixture creates self-referencing revisions with increasing ids, so descending ids delete dependents first.
            $ids = DB::table($table)->where('tenant_id', $tenantId)->orderByDesc('id')->pluck('id');
            foreach ($ids as $id) {
                DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->delete();
            }
        } else {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    }
    $tenantDeleted = DB::table('tenants')->where('id', $tenantId)->delete();
    if ($tenantDeleted !== 1) {
        throw new RuntimeException('Owned Phase 14 fixture tenant was not removed exactly once.');
    }
    $userIds = array_values(array_unique(array_map('intval', $fixture['users'])));
    foreach ($userIds as $userId) {
        DB::table('users')->where('id', $userId)->delete();
    }
}

/** Recover only tenants whose UUID slug and official name are generated by this harness. */
function phase14CleanupStaleFixtures(): void
{
    $tenants = DB::table('tenants')->whereRaw('slug REGEXP ?', ['^phase14-concurrency-[0-9a-f]{32}$'])
        ->where('name', 'like', 'Phase 14 concurrency %')->orderBy('id')->get(['id']);
    foreach ($tenants as $tenant) {
        $tenantId = (int) $tenant->id;
        $userIds = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id')
            ->merge(DB::table('sis_students')->where('tenant_id', $tenantId)->whereNotNull('user_id')->pluck('user_id'))
            ->merge(DB::table('audit_events')->where('tenant_id', $tenantId)->whereNotNull('actor_user_id')->pluck('actor_user_id'))
            ->unique()->map(static fn ($id): int => (int) $id)->values()->all();
        if ($userIds === []) {
            throw new RuntimeException('Generated Phase 14 tenant '.$tenantId.' has no fixture actors; preserve it for review.');
        }
        phase14CleanupOwnedFixture(['tenant_id' => $tenantId, 'users' => $userIds]);
        fwrite(STDOUT, 'PHASE14_STALE_FIXTURE_RECOVERY=PASS owned_tenant_id='.$tenantId.PHP_EOL);
    }
}
