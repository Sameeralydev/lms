<?php

declare(strict_types=1);

use App\Domain\Analytics\AcademicAnalyticsService;
use App\Domain\Analytics\AcademicReportBuilder;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Http\Controllers\Workspace\AcademicAnalyticsController;
use App\Http\Controllers\Workspace\SisStudentController;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Phase15MySqlTestTarget as Target;
use Tests\Support\Phase15ProbeAuthorization;
use Tests\Support\Phase15ProbeEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = Phase15ProbeEnvironment::current();

try {
    Phase15ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), $environment);
    $identity = DB::selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id');
    $lock = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [Target::SERVER_LOCK]);
    if ($identity?->database_name !== Target::DATABASE || $lock?->owner === null
        || ($environment['PHASE15_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true') {
        throw new RuntimeException('Only the exclusively guarded Phase 15 MySQL runner can authorize these probes.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase 15 analytics concurrency harness refused database identity, grants, or runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false || is_link(storage_path('framework'))) {
    fwrite(STDERR, "Phase 15 concurrency harness requires the private framework coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    phase15Worker($argv, $root, $framework, $environment);
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 15 analytics concurrency mode.\n");
    exit(2);
}

$fixture = null;
$coordinationPath = null;
$cachePath = null;
$exitStatus = 0;
try {
    phase15CleanupStaleFixtures();
    $fixture = phase15CreateFixture();
    $coordinationPath = $framework.DIRECTORY_SEPARATOR.'phase15-analytics-'.$fixture['run_id'];
    $cachePath = $framework.DIRECTORY_SEPARATOR.'phase15-cache-'.$fixture['run_id'];
    phase15PrivateDirectory($coordinationPath);
    phase15PrivateDirectory($cachePath);
    phase15RunAll($fixture, $coordinationPath, $cachePath, $environment);
    fwrite(STDOUT, "PHASE15_CONCURRENCY_RESULT=PASS probes=8 independent_sessions=16 transaction_barriers=8\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PHASE15_CONCURRENCY_RESULT=FAIL '.$error::class.': '.$error->getMessage().PHP_EOL);
    $exitStatus = 1;
} finally {
    if (is_array($fixture)) {
        try {
            phase15CleanupOwnedFixture($fixture);
            fwrite(STDOUT, 'PHASE15_FIXTURE_CLEANUP=PASS owned_tenant_id='.(int) $fixture['tenant_id'].PHP_EOL);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'PHASE15_FIXTURE_CLEANUP=INCOMPLETE owned_tenant_id='.(int) $fixture['tenant_id'].' reason='.$cleanupError->getMessage().PHP_EOL);
            $exitStatus = 1;
        }
    }
    foreach ([$coordinationPath, $cachePath] as $path) {
        if (is_string($path) && is_dir($path) && ! is_link($path)) {
            phase15RemoveDirectory($path);
        }
    }
}
exit($exitStatus);

/**
 * @param  list<string>  $argv
 * @param  array<string,string>  $environment
 */
function phase15Worker(array $argv, string $root, string $framework, array $environment): never
{
    $context = null;
    try {
        phase15Trace('worker bootstrapped');
        $run = (string) ($argv[2] ?? '');
        $label = (string) ($argv[3] ?? '');
        $payload = json_decode(base64_decode((string) ($argv[4] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        $coordinationPath = (string) ($argv[5] ?? '');
        $canonicalCoordinationPath = realpath($coordinationPath);
        if (! preg_match('/^[a-f0-9-]{36}$/', $run) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)
            || $canonicalCoordinationPath === false || is_link($coordinationPath)
            || realpath(dirname($canonicalCoordinationPath)) !== $framework
            || ! str_starts_with(basename($canonicalCoordinationPath), 'phase15-analytics-')) {
            throw new RuntimeException('Invalid Phase 15 worker invocation.');
        }
        Phase15ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), $environment);
        phase15Trace('database authorization passed');
        $lock = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [Target::SERVER_LOCK]);
        if ($lock?->owner === null || ($environment['PHASE15_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true') {
            throw new RuntimeException('Phase 15 worker lacks the guarded server lock.');
        }
        $prefix = 'phase15-analytics-'.$run;
        $ready = $canonicalCoordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
        $gate = $canonicalCoordinationPath.DIRECTORY_SEPARATOR.$prefix.'.go';
        $resultPath = $canonicalCoordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
        $tenant = Tenant::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['tenant_id']);
        phase15Trace('tenant loaded');
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['membership_id']);
        phase15Trace('membership loaded');
        if ((int) $membership->tenant_id !== (int) $tenant->getKey() || $membership->status !== 'active'
            || ! $membership->hasPermission('academic.analytics.view') || ! $membership->hasPermission('academic.analytics.reports.manage')) {
            throw new RuntimeException('Phase 15 concurrency actor is not an active, authorized member of its fixture tenant.');
        }
        phase15Trace('membership permissions passed');
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        phase15Trace('actor loaded');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        DB::beginTransaction();
        phase15Trace('transaction started');
        $anchor = DB::table('users')->where('id', (int) $payload['anchor_user_id'])->lockForUpdate()->first(['id']);
        phase15Trace('anchor locked');
        if ($anchor === null) {
            throw new RuntimeException('Worker could not acquire its owned InnoDB synchronization anchor.');
        }
        $session = DB::selectOne('SELECT CONNECTION_ID() AS id');
        phase15Trace('connection identity obtained');
        phase15WriteExclusive($ready, json_encode(['connection_id' => (int) $session->id, 'transaction_active' => true, 'anchor_user_id' => (int) $anchor->id], JSON_THROW_ON_ERROR));
        phase15Trace('ready barrier written');
        phase15WaitFor($gate, 45, 'worker did not receive the deterministic start barrier');
        $value = phase15Execute((string) $payload['operation'], $payload, $tenant, $membership, $actor);
        DB::commit();
        $result = ['outcome' => 'success', 'operation' => (string) $payload['operation'], 'actor_id' => (int) $actor->getKey(), 'connection_id' => (int) $session->id, 'transaction_active_at_barrier' => true, 'cache_store' => config('cache.default'), 'value' => $value];
    } catch (Throwable $error) {
        if (DB::connection()->transactionLevel() > 0) {
            try {
                DB::rollBack();
            } catch (Throwable) {
            }
        }
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
        $conflict = $error instanceof ValidationException || $status === 404 || $status === 409
            || $error instanceof QueryException && in_array((string) ($error->errorInfo[0] ?? ''), ['23000', '23505'], true);
        $result = ['outcome' => $conflict ? 'conflict' : 'error', 'operation' => (string) ($payload['operation'] ?? ''), 'actor_id' => (int) ($payload['actor_id'] ?? 0), 'connection_id' => isset($session) ? (int) $session->id : 0,
            'transaction_active_at_barrier' => isset($session), 'error_class' => $error::class, 'message' => $conflict ? 'expected concurrent conflict' : $error->getMessage()];
    } finally {
        $context?->clear();
    }
    if (isset($resultPath)) {
        phase15WriteExclusive($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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
function phase15Execute(string $operation, array $payload, Tenant $tenant, TenantMembership $membership, User $actor): array
{
    $filters = $payload['filters'] ?? ['campus_id' => null, 'from' => null, 'to' => null, 'metrics' => ['students.active']];

    return match ($operation) {
        'metric_report', 'cache_refresh', 'aggregate_report' => phase15ReportValue(app(AcademicAnalyticsService::class)->report($membership, $filters)),
        'save_report' => phase15SaveReport($tenant, $membership, $actor, (string) $payload['name'], $filters),
        'export' => phase15Export($tenant, $membership, $actor, $filters),
        'amend_student_campus' => phase15AmendStudentCampus($tenant, $membership, $actor, (int) $payload['student_id'], (int) $payload['campus_id']),
        default => throw new RuntimeException('Unknown Phase 15 application operation: '.$operation),
    };
}

/** @param array<string,mixed> $report
 * @return array<string,mixed>
 */
function phase15ReportValue(array $report): array
{
    return ['metrics' => array_map(static fn (array $metric): array => ['key' => $metric['key'], 'value' => $metric['value']], $report['metrics']), 'generated_at' => $report['generated_at']];
}

/** @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function phase15SaveReport(Tenant $tenant, TenantMembership $membership, User $actor, string $name, array $filters): array
{
    $request = Request::create('/', 'POST', ['name' => $name, ...$filters]);
    $request->setUserResolver(static fn (): User => $actor);
    $response = app(AcademicAnalyticsController::class)->storeReport($tenant, $request, app(TenantContext::class), app(AcademicAnalyticsService::class), app(AcademicReportBuilder::class), app(AuditRecorder::class));

    return ['status' => $response->getStatusCode(), 'name' => $name, 'owner_membership_id' => (int) $membership->getKey()];
}

/** @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function phase15Export(Tenant $tenant, TenantMembership $membership, User $actor, array $filters): array
{
    $request = Request::create('/', 'GET', $filters);
    $request->setUserResolver(static fn (): User => $actor);
    $response = app(AcademicAnalyticsController::class)->export($tenant, $request, app(TenantContext::class), app(AcademicAnalyticsService::class), app(AuditRecorder::class));
    $output = '';
    ob_start();
    try {
        $response->sendContent();
        $output = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if (! str_starts_with($output, 'metric_key,metric,value,')) {
        throw new RuntimeException('Production CSV export did not return its expected authorized aggregate format.');
    }

    $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($output)) ?: []);
    $studentValue = null;
    foreach ($rows as $row) {
        if (($row[0] ?? null) === 'students.active') {
            $studentValue = $row[2] ?? null;
            break;
        }
    }

    return ['sha256' => hash('sha256', $output), 'bytes' => strlen($output), 'contains_students_metric' => is_string($studentValue), 'student_value' => $studentValue, 'owner_membership_id' => (int) $membership->getKey()];
}

/** @return array<string,mixed> */
function phase15AmendStudentCampus(Tenant $tenant, TenantMembership $membership, User $actor, int $studentId, int $campusId): array
{
    $request = Request::create('/', 'PUT', ['home_campus_id' => $campusId]);
    $request->setUserResolver(static fn (): User => $actor);
    $response = app(SisStudentController::class)->update($request, $tenant, $studentId);

    return ['status' => $response->getStatusCode(), 'student_id' => $studentId, 'campus_id' => $campusId, 'membership_id' => (int) $membership->getKey()];
}

/** @return array<string,mixed> */
function phase15CreateFixture(): array
{
    $run = (string) Str::uuid();
    $creator = User::factory()->create();
    $owner = User::factory()->create();
    // These rows are dedicated synchronization anchors. They must not be
    // business principals (for example the audit actor) referenced by either
    // concurrent transaction, or the test itself can manufacture a deadlock.
    $anchorA = User::factory()->create();
    $anchorB = User::factory()->create();
    $context = app(TenantContext::class);
    try {
        $provisioned = app(TenantProvisioner::class)->provision([
            'slug' => 'phase15-concurrency-'.str_replace('-', '', $run), 'official_name' => 'Phase 15 concurrency '.$run, 'display_name' => 'Phase 15 concurrency '.$run,
            'code' => 'P15-'.strtoupper(substr(str_replace('-', '', $run), 0, 10)), 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $tenant = $provisioned->tenant;
        // Institution uses the tenant global scope; restore its context before
        // resolving the provisioned relation (TenantProvisioner clears it).
        $context->enterProvisioning($tenant);
        $institution = $tenant->institution()->firstOrFail();
        $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
        $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
        $context->set($tenant, $membership);
        foreach ([$anchorA, $anchorB] as $anchorUser) {
            TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $anchorUser->getKey(), 'status' => 'active', 'activated_at' => now()]);
        }
        $campusA = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'P15A'.strtoupper(substr(str_replace('-', '', $run), 0, 8)), 'name' => 'Concurrency Campus A', 'status' => 'active']);
        $campusB = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'P15B'.strtoupper(substr(str_replace('-', '', $run), 0, 8)), 'name' => 'Concurrency Campus B', 'status' => 'active']);
        $studentId = (int) DB::table('sis_students')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'home_campus_id' => $campusA->getKey(),
            'student_number' => 'P15-'.strtoupper(substr(str_replace('-', '', $run), 0, 12)), 'registration_reference' => 'P15R-'.strtoupper(substr(str_replace('-', '', $run), 0, 12)),
            'legal_first_name' => 'Analytics', 'legal_last_name' => 'Probe', 'admission_date' => today()->toDateString(), 'status' => 'active', 'student_type' => 'standard', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Phase 15 concurrency year', 'code' => 'P15Y'.substr(str_replace('-', '', $run), 0, 10), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Phase 15 concurrency term', 'code' => 'P15T'.substr(str_replace('-', '', $run), 0, 10), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'P15C'.substr(str_replace('-', '', $run), 0, 10), 'title' => 'Phase 15 concurrency course', 'status' => 'active']);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Phase 15 concurrency policy', 'maximum_courses' => 4, 'created_by' => $creator->getKey()]);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'P15O'.substr(str_replace('-', '', $run), 0, 10), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $creator->getKey()]);
        $sectionA = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campusA->getKey(), 'section_code' => 'P15A', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        $sectionB = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campusB->getKey(), 'section_code' => 'P15B', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        $assignmentId = (int) DB::table('assignments')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'offering_id' => $offering->getKey(), 'created_by' => $creator->getKey(),
            'title' => 'Phase 15 duplicate aggregation source', 'assignment_type' => 'coursework', 'collaboration_mode' => 'individual', 'maximum_attempts' => 1, 'maximum_points' => '10.00', 'timezone' => 'UTC', 'status' => 'published', 'review_required' => false, 'current_version' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assignment_sections')->insert([
            ['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'section_id' => $sectionA->getKey(), 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->getKey(), 'assignment_id' => $assignmentId, 'section_id' => $sectionB->getKey(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $context->clear();

        return ['run_id' => $run, 'tenant_id' => (int) $tenant->getKey(), 'institution_id' => (int) $institution->getKey(), 'membership_id' => (int) $membership->getKey(),
            'student_id' => $studentId, 'campus_a' => (int) $campusA->getKey(), 'campus_b' => (int) $campusB->getKey(), 'assignment_id' => $assignmentId,
            'users' => [(int) $creator->getKey(), (int) $owner->getKey(), (int) $anchorA->getKey(), (int) $anchorB->getKey()],
            'actor_id' => (int) $owner->getKey(), 'anchors' => [(int) $anchorA->getKey(), (int) $anchorB->getKey()]];
    } catch (Throwable $error) {
        $context->clear();
        throw $error;
    }
}

/** @param array<string,mixed> $fixture
 * @param  array<string,string>  $environment
 */
function phase15RunAll(array $fixture, string $coordinationPath, string $cachePath, array $environment): void
{
    $tenant = Tenant::query()->withoutGlobalScope('tenant')->findOrFail($fixture['tenant_id']);
    $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($fixture['membership_id']);
    $fresh = static fn (array $metrics, ?int $campus = null, ?string $from = null, ?string $to = null): array => ['campus_id' => $campus, 'from' => $from, 'to' => $to, 'metrics' => $metrics];

    $race = phase15Race('metric-refresh', $fixture, [
        ['operation' => 'metric_report', 'filters' => $fresh(['students.active'])], ['operation' => 'metric_report', 'filters' => $fresh(['students.active'])],
    ], $coordinationPath, $cachePath, $environment);
    phase15Assert(count(array_filter($race, static fn (array $r): bool => ($r['value']['metrics'][0]['value'] ?? null) === 1)) === 2, 'probe 1 concurrent metric refresh must return the committed active-student count');
    phase15Report(1, $race, 'two independent AcademicAnalyticsService report calls returned the scoped committed count');

    $race = phase15Race('duplicate-source-aggregation', $fixture, [
        ['operation' => 'aggregate_report', 'filters' => $fresh(['assignments.published'])], ['operation' => 'aggregate_report', 'filters' => $fresh(['assignments.published'])],
    ], $coordinationPath, $cachePath, $environment);
    phase15Assert(count(array_filter($race, static fn (array $r): bool => ($r['value']['metrics'][0]['value'] ?? null) === 1)) === 2, 'probe 2 must count the one assignment only once even though it has two source section rows');
    phase15Report(2, $race, 'distinct assignment aggregation remained idempotent under two concurrent recalculations');

    $race = phase15Race('source-amendment-vs-calculation', $fixture, [
        ['operation' => 'metric_report', 'filters' => $fresh(['students.active'], $fixture['campus_a'], '2026-01-01', '2026-01-02')],
        ['operation' => 'amend_student_campus', 'student_id' => $fixture['student_id'], 'campus_id' => $fixture['campus_b']],
    ], $coordinationPath, $cachePath, $environment);
    $observed = phase15ValueFor($race, 'metric_report', 'students.active');
    phase15Assert(in_array($observed, [0, 1], true), 'probe 3 report may observe only the complete pre- or post-campus-amendment aggregate');
    phase15Assert((int) DB::table('sis_students')->where('tenant_id', $fixture['tenant_id'])->where('id', $fixture['student_id'])->value('home_campus_id') === $fixture['campus_b'], 'probe 3 must commit the authorized source amendment');
    phase15Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'sis.student.updated')->where('subject_id', $fixture['student_id'])->count() === 1, 'probe 3 source amendment must retain its production audit event');
    phase15Report(3, $race, 'authorized SIS campus correction overlapped a report calculation; result was a complete old or new value');

    $cacheFilters = $fresh(['students.active', 'assignments.published'], null, '2026-02-01', '2026-02-02');
    $race = phase15Race('dashboard-cache-refresh', $fixture, [
        ['operation' => 'cache_refresh', 'filters' => $cacheFilters], ['operation' => 'cache_refresh', 'filters' => $cacheFilters],
    ], $coordinationPath, $cachePath, $environment);
    phase15Assert(($race[0]['value']['metrics'] ?? null) === ($race[1]['value']['metrics'] ?? null), 'probe 4 concurrent dashboard cache refreshes must publish identical metric values');
    phase15Assert(($race[0]['cache_store'] ?? null) === 'file' && ($race[1]['cache_store'] ?? null) === 'file', 'probe 4 requires the explicitly shared file-cache backend in both workers');
    phase15Report(4, $race, 'shared file cache received concurrent tenant-scoped report refreshes with identical values');

    $saveFilters = $fresh(['students.active']);
    $race = phase15Race('simultaneous-saved-report-generation', $fixture, [
        ['operation' => 'save_report', 'name' => 'Concurrent duplicate report', 'filters' => $saveFilters], ['operation' => 'save_report', 'name' => 'Concurrent duplicate report', 'filters' => $saveFilters],
    ], $coordinationPath, $cachePath, $environment);
    phase15AssertOutcomes($race, ['conflict', 'success']);
    phase15Assert(DB::table('academic_analytics_saved_reports')->where('tenant_id', $fixture['tenant_id'])->where('membership_id', $fixture['membership_id'])->where('name', 'Concurrent duplicate report')->count() === 1, 'probe 5 duplicate report generation must persist one row');
    phase15Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.report.saved')->where('subject_id', DB::table('academic_analytics_saved_reports')->where('membership_id', $fixture['membership_id'])->where('name', 'Concurrent duplicate report')->value('id'))->count() === 1, 'probe 5 must write one save audit for the authoritative report');
    phase15Report(5, $race, 'membership lock and unique constraint allowed exactly one authorized same-name saved report');

    $beforeExports = DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.exported')->count();
    $exportFilters = $fresh(['students.active'], null, '2026-03-01', '2026-03-02');
    $race = phase15Race('concurrent-csv-export', $fixture, [
        ['operation' => 'export', 'filters' => $exportFilters], ['operation' => 'export', 'filters' => $exportFilters],
    ], $coordinationPath, $cachePath, $environment);
    phase15Assert(count(array_filter($race, static fn (array $r): bool => ($r['value']['contains_students_metric'] ?? false) === true)) === 2, 'probe 6 both authorized CSV exports must contain the requested aggregate');
    phase15Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.exported')->count() === $beforeExports + 2, 'probe 6 one authorized export request must produce exactly one audit event');
    phase15Report(6, $race, 'synchronous export replacement for absent export-job queue returned two complete CSVs and two request audits');

    $now = now();
    // Isolate the quota boundary from probe 5 while retaining its audit evidence.
    DB::table('academic_analytics_saved_reports')->where('tenant_id', $fixture['tenant_id'])->where('membership_id', $fixture['membership_id'])->where('name', 'Concurrent duplicate report')->delete();
    $prefill = [];
    for ($i = 1; $i <= 19; $i++) {
        $prefill[] = ['tenant_id' => $fixture['tenant_id'], 'institution_id' => $fixture['institution_id'], 'membership_id' => $fixture['membership_id'], 'name' => 'Quota prefill '.$i,
            'filters' => json_encode($fresh(['students.active']), JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('academic_analytics_saved_reports')->insert($prefill);
    $beforeSavedAudits = DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.report.saved')->count();
    $race = phase15Race('concurrent-analytics-event-audit', $fixture, [
        ['operation' => 'save_report', 'name' => 'Concurrent quota report A', 'filters' => $fresh(['students.active'])], ['operation' => 'save_report', 'name' => 'Concurrent quota report B', 'filters' => $fresh(['assignments.published'])],
    ], $coordinationPath, $cachePath, $environment);
    phase15AssertOutcomes($race, ['conflict', 'success']);
    phase15Assert(DB::table('academic_analytics_saved_reports')->where('tenant_id', $fixture['tenant_id'])->where('membership_id', $fixture['membership_id'])->count() === 20, 'probe 7 concurrent saved-report writes must respect the membership quota');
    phase15Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.report.saved')->count() === $beforeSavedAudits + 1, 'probe 7 the successful report event must be audited once; rejected work must not emit a success audit');
    phase15Report(7, $race, 'no analytics notification queue exists; actual report-save audit/event generation was exercised at the quota boundary');

    $context = app(TenantContext::class);
    $context->set($tenant, $membership);
    try {
        phase15AmendStudentCampus($tenant, $membership, User::query()->findOrFail($fixture['actor_id']), $fixture['student_id'], $fixture['campus_a']);
    } finally {
        $context->clear();
    }
    $reportFilters = $fresh(['students.active'], $fixture['campus_a'], null, '2026-04-02');
    $beforeExports = DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.exported')->count();
    $race = phase15Race('export-vs-source-correction', $fixture, [
        ['operation' => 'export', 'filters' => $reportFilters], ['operation' => 'amend_student_campus', 'student_id' => $fixture['student_id'], 'campus_id' => $fixture['campus_b']],
    ], $coordinationPath, $cachePath, $environment);
    $export = phase15ResultFor($race, 'export');
    phase15Assert(($export['value']['contains_students_metric'] ?? false) === true && in_array((string) ($export['value']['student_value'] ?? ''), ['0', '1'], true), 'probe 8 export may contain only a complete old or new student aggregate');
    phase15Assert((int) DB::table('sis_students')->where('tenant_id', $fixture['tenant_id'])->where('id', $fixture['student_id'])->value('home_campus_id') === $fixture['campus_b'], 'probe 8 source correction must commit to its new campus');
    phase15Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'academic.analytics.exported')->count() === $beforeExports + 1, 'probe 8 authorized result generation must be auditable exactly once');
    phase15Report(8, $race, 'live report export overlapped an authorized source correction; complete output and correction audit persisted');
}

/** @param array<string,mixed> $fixture
 * @param  list<array<string,mixed>>  $workers
 * @param  array<string,string>  $environment
 * @return list<array<string,mixed>>
 */
function phase15Race(string $name, array $fixture, array $workers, string $coordinationPath, string $cachePath, array $environment): array
{
    if (count($workers) !== 2) {
        throw new RuntimeException('Each Phase 15 probe must use two independent worker processes.');
    }
    $run = (string) Str::uuid();
    $prefix = 'phase15-analytics-'.$run;
    $gate = $coordinationPath.DIRECTORY_SEPARATOR.$prefix.'.go';
    $processes = [];
    $paths = [$gate];
    try {
        foreach (['a', 'b'] as $index => $label) {
            $ready = $coordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
            $result = $coordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
            $stdout = $coordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stdout';
            $stderr = $coordinationPath.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stderr';
            array_push($paths, $ready, $result, $stdout, $stderr);
            $payload = [...$workers[$index], 'tenant_id' => $fixture['tenant_id'], 'membership_id' => $fixture['membership_id'], 'actor_id' => $fixture['actor_id'], 'anchor_user_id' => $fixture['anchors'][$index]];
            $workerEnvironment = [...$environment, 'CACHE_STORE' => 'file', 'CACHE_FILE_STORE_PATH' => $cachePath, 'CACHE_FILE_LOCK_PATH' => $cachePath, 'PHASE15_WORKER_TRACE' => 'true'];
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $run, $label, $encoded, $coordinationPath], [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'wb'], 2 => ['file', $stderr, 'wb']], $pipes, dirname(__DIR__, 2), $workerEnvironment, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start Phase 15 worker '.$label.' for '.$name.'.');
            }
            fclose($pipes[0]);
            $processes[$label] = ['process' => $process, 'ready' => $ready, 'result' => $result, 'stdout' => $stdout, 'stderr' => $stderr];
        }
        $deadline = microtime(true) + 45;
        while (microtime(true) < $deadline && count(array_filter($processes, static fn (array $p): bool => is_file($p['ready']))) < 2) {
            foreach ($processes as $label => $p) {
                if (! proc_get_status($p['process'])['running'] && ! is_file($p['ready'])) {
                    throw new RuntimeException($name.' worker '.$label.' exited before its barrier: '.trim((string) @file_get_contents($p['stderr'])));
                }
            }
            usleep(10000);
        }
        if (! is_file($processes['a']['ready']) || ! is_file($processes['b']['ready'])) {
            $diagnostics = [];
            foreach ($processes as $label => $process) {
                $diagnostics[] = $label.' running='.(proc_get_status($process['process'])['running'] ? 'yes' : 'no')
                    .' stdout='.trim((string) @file_get_contents($process['stdout']))
                    .' stderr='.trim((string) @file_get_contents($process['stderr']));
            }
            throw new RuntimeException($name.' workers did not reach the bounded barrier: '.implode(' | ', $diagnostics));
        }
        $readyA = json_decode((string) file_get_contents($processes['a']['ready']), true, 512, JSON_THROW_ON_ERROR);
        $readyB = json_decode((string) file_get_contents($processes['b']['ready']), true, 512, JSON_THROW_ON_ERROR);
        phase15Assert(($readyA['transaction_active'] ?? false) && ($readyB['transaction_active'] ?? false), $name.' workers did not hold open transactions at the barrier');
        phase15Assert((int) $readyA['connection_id'] !== (int) $readyB['connection_id'], $name.' workers share a MySQL connection id');
        phase15AssertBarrierLock($name, (int) $readyA['anchor_user_id']);
        phase15AssertBarrierLock($name, (int) $readyB['anchor_user_id']);
        phase15WriteExclusive($gate, json_encode(['probe' => $name, 'released_at_ns' => hrtime(true)], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 90;
        while (microtime(true) < $deadline && (! is_file($processes['a']['result']) || ! is_file($processes['b']['result']))) {
            foreach ($processes as $label => $p) {
                if (! is_file($p['result']) && ! proc_get_status($p['process'])['running']) {
                    throw new RuntimeException($name.' worker '.$label.' ended without a result: '.trim((string) @file_get_contents($p['stderr'])));
                }
            }
            usleep(10000);
        }
        phase15Assert(is_file($processes['a']['result']) && is_file($processes['b']['result']), $name.' exceeded the 90 second worker bound');
        $results = [];
        foreach (['a', 'b'] as $label) {
            $result = json_decode((string) file_get_contents($processes[$label]['result']), true, 512, JSON_THROW_ON_ERROR);
            phase15Assert(($result['transaction_active_at_barrier'] ?? false) === true, $name.' result omitted transaction evidence');
            phase15Assert((int) $result['connection_id'] === (int) ($label === 'a' ? $readyA['connection_id'] : $readyB['connection_id']), $name.' connection changed during the probe');
            phase15Assert(in_array($result['outcome'] ?? '', ['success', 'conflict'], true), $name.' failed: '.($result['message'] ?? 'unknown'));
            $results[] = $result;
        }
        foreach ($processes as $p) {
            $status = proc_close($p['process']);
            if ($status !== 0) {
                throw new RuntimeException($name.' worker exited '.$status.'.');
            }
        }
        $processes = [];

        return $results;
    } finally {
        foreach ($processes as $p) {
            if (proc_get_status($p['process'])['running']) {
                proc_terminate($p['process']);
            } proc_close($p['process']);
        }
        foreach ($paths as $path) {
            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
    }
}

/** @param list<array<string,mixed>> $results
 * @param  list<string>  $expected
 */
function phase15AssertOutcomes(array $results, array $expected): void
{
    $actual = array_column($results, 'outcome');
    sort($actual);
    sort($expected);
    phase15Assert($actual === $expected, 'Unexpected concurrent outcomes: '.implode(',', $actual));
}

/** @param list<array<string,mixed>> $results
 * @return array<string,mixed>
 */
function phase15ResultFor(array $results, string $operation): array
{
    foreach ($results as $result) {
        if (($result['operation'] ?? null) === $operation) {
            return $result;
        }
    }

    return $results[0];
}

/** @param list<array<string,mixed>> $results */
function phase15ValueFor(array $results, string $operation, string $metric): int|float|null
{
    foreach ($results as $result) {
        foreach (($result['value']['metrics'] ?? []) as $row) {
            if (($row['key'] ?? null) === $metric) {
                return $row['value'];
            }
        }
    }
    throw new RuntimeException('Concurrent report did not return metric '.$metric.'.');
}

/** @param list<array<string,mixed>> $results */
function phase15Report(int $number, array $results, string $invariant): void
{
    $sessions = array_values(array_unique(array_map(static fn (array $result): int => (int) $result['connection_id'], $results)));
    phase15Assert(count($sessions) === 2, 'Probe '.$number.' did not use two independent sessions.');
    sort($sessions, SORT_NUMERIC);
    fwrite(STDOUT, sprintf("PROBE_%d=PASS sessions=%d session_ids=%s open_transactions_at_barrier=%d outcomes=%s invariant=%s\n", $number, count($sessions), implode(',', $sessions), count(array_filter($results, static fn (array $result): bool => ($result['transaction_active_at_barrier'] ?? false) === true)), implode(',', array_column($results, 'outcome')), $invariant));
}

function phase15Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase15AssertBarrierLock(string $name, int $anchorId): void
{
    try {
        DB::select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [$anchorId]);
    } catch (QueryException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
            return;
        } throw $error;
    }
    throw new RuntimeException($name.' worker did not hold its user-row barrier lock.');
}

function phase15WaitFor(string $path, int $seconds, string $failure): void
{
    $deadline = microtime(true) + $seconds;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($path)) {
        throw new RuntimeException($failure);
    }
}

function phase15WriteExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 15 barrier/result file already exists or cannot be created.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Phase 15 barrier evidence could not be persisted.');
        }
    } finally {
        fclose($handle);
    }
}

function phase15PrivateDirectory(string $path): void
{
    if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
        throw new RuntimeException('Phase 15 private worker directory is unsafe.');
    }
    if (! is_dir($path) && ! mkdir($path, 0700) && ! is_dir($path)) {
        throw new RuntimeException('Cannot create Phase 15 private worker directory.');
    }
}

function phase15Trace(string $message): void
{
    if ((getenv('PHASE15_WORKER_TRACE') ?: '') === 'true') {
        fwrite(STDERR, '[phase15-worker] '.$message.PHP_EOL);
        fflush(STDERR);
    }
}

/** @param array<string,mixed> $fixture */
function phase15CleanupOwnedFixture(array $fixture): void
{
    $tenantId = (int) $fixture['tenant_id'];
    $database = (string) DB::selectOne('SELECT DATABASE() AS name')->name;
    $tables = DB::select('SELECT TABLE_NAME AS table_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? GROUP BY TABLE_NAME ORDER BY TABLE_NAME', [$database, 'tenant_id']);
    $tableNames = array_values(array_filter(array_map(static fn (object $row): string => (string) $row->table_name, $tables), static function (string $table) use ($tenantId): bool {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Unexpected identifier in owned-fixture cleanup.');
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
            foreach ($foreignKeys as $fk) {
                if ($fk->parent_table === $table && $fk->child_table !== $table && isset($remaining[$fk->child_table])) {
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
            throw new RuntimeException('Owned Phase 15 cleanup encountered a tenant-table FK cycle; no broad deletion attempted.');
        }
        $ordered[] = $candidate;
        unset($remaining[$candidate]);
    }
    foreach ($ordered as $table) {
        if (array_filter($foreignKeys, static fn (object $fk): bool => $fk->child_table === $table && $fk->parent_table === $table) !== []) {
            $ids = DB::table($table)->where('tenant_id', $tenantId)->orderByDesc('id')->pluck('id');
            foreach ($ids as $id) {
                DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->delete();
            }
        } else {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    }
    if (DB::table('tenants')->where('id', $tenantId)->delete() !== 1) {
        throw new RuntimeException('Owned Phase 15 tenant was not removed exactly once.');
    }
    foreach (array_unique(array_map('intval', $fixture['users'])) as $userId) {
        DB::table('users')->where('id', $userId)->delete();
    }
}

function phase15CleanupStaleFixtures(): void
{
    $rows = DB::table('tenants')->whereRaw('slug REGEXP ?', ['^phase15-concurrency-[0-9a-f]{32}$'])->where('name', 'like', 'Phase 15 concurrency %')->get(['id']);
    foreach ($rows as $row) {
        $tenantId = (int) $row->id;
        $users = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id')
            ->merge(DB::table('audit_events')->where('tenant_id', $tenantId)->whereNotNull('actor_user_id')->pluck('actor_user_id'))->unique()->map(static fn ($id): int => (int) $id)->all();
        if ($users === []) {
            throw new RuntimeException('Stale generated tenant has no owned actors; preserve it for review.');
        }
        phase15CleanupOwnedFixture(['tenant_id' => $tenantId, 'users' => $users]);
    }
}

function phase15RemoveDirectory(string $path): void
{
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            phase15RemoveDirectory($entry->getPathname());
        } elseif ($entry->isFile() && ! $entry->isLink()) {
            unlink($entry->getPathname());
        } else {
            throw new RuntimeException('Unexpected symlink in Phase 15 private cache cleanup.');
        }
    }
    rmdir($path);
}
