<?php

use App\Domain\Admissions\AdmissionApplicationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionApplication;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase06ProbeAuthorization;
use Tests\Support\Phase06ProbeEnvironment;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\Phase07ProbeAuthorization;
use Tests\Support\TestDatabaseGuard;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array{0:Connection,1:int} */
$assertGuardedTestDatabase = static function (): array {
    $environment = TestDatabaseGuard::currentEnvironment();
    TestDatabaseGuard::assertSafe($environment);
    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name');
    if (($identity->database_name ?? null) === Phase06MySqlTestTarget::DATABASE) {
        Phase06ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);

        return [$connection, (int) $connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn()];
    }
    if (($identity->database_name ?? null) === Phase07MySqlTestTarget::DATABASE) {
        Phase07ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);

        return [$connection, (int) $connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn()];
    }

    $allowedAccounts = [
        'techmentors_lms_phase04_v2_test' => 'techmentors_phase04_v2@127.0.0.1',
        'techmentors_lms_phase05_test' => 'techmentors_phase05_test@127.0.0.1',
        Phase07MySqlTestTarget::DATABASE => Phase07MySqlTestTarget::ACCOUNT.'@127.0.0.1',
    ];
    if (
        $connection->getDriverName() !== 'mysql'
        || ! is_string($identity->database_name ?? null)
        || ! isset($allowedAccounts[$identity->database_name])
        || ($identity->account_name ?? null) !== $allowedAccounts[$identity->database_name]
    ) {
        throw new RuntimeException('The concurrency probe is not connected to an authorized guarded test identity.');
    }

    return [$connection, (int) $connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn()];
};

$mode = $argv[1] ?? 'orchestrate';
try {
    [$connection] = $assertGuardedTestDatabase();
} catch (Throwable) {
    fwrite(STDERR, "Guarded offer concurrency probe refused its database identity.\n");
    exit(2);
}

if ($mode === 'worker') {
    $tenantId = (int) ($argv[2] ?? 0);
    $applicationId = (int) ($argv[3] ?? 0);
    $actorId = (int) ($argv[4] ?? 0);
    $readyPath = (string) ($argv[5] ?? '');
    $goPath = (string) ($argv[6] ?? '');
    $resultPath = (string) ($argv[7] ?? '');
    $frameworkRoot = realpath(storage_path('framework'));
    foreach ([$readyPath, $goPath, $resultPath] as $path) {
        if (
            $frameworkRoot === false
            || realpath(dirname($path)) !== $frameworkRoot
            || ! str_starts_with(basename($path), 'phase04-offer-race-')
        ) {
            fwrite(STDERR, "Phase 04 offer concurrency worker refused an unsafe coordination path.\n");
            exit(2);
        }
    }

    try {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $actor = User::query()->findOrFail($actorId);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        file_put_contents($readyPath, 'ready', LOCK_EX);
        $deadline = microtime(true) + 20;
        while (! is_file($goPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (! is_file($goPath)) {
            throw new RuntimeException('The simultaneous offer barrier timed out.');
        }

        try {
            $application = AdmissionApplication::query()->findOrFail($applicationId);
            $offer = app(AdmissionApplicationWorkflow::class)->issueOffer($application, $actor, null, now()->addDay());
            $result = ['outcome' => 'issued', 'offer_id' => (int) $offer->getKey()];
        } catch (ValidationException $exception) {
            $message = $exception->errors()['application'][0] ?? '';
            $outcome = str_contains($message, 'no unreserved seat') ? 'capacity_rejected' : 'unexpected_rejection';
            $result = ['outcome' => $outcome, 'offer_id' => null];
        } finally {
            $context->clear();
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
        exit($result['outcome'] === 'unexpected_rejection' ? 1 : 0);
    } catch (Throwable) {
        @file_put_contents($resultPath, '{"outcome":"worker_failed","offer_id":null}', LOCK_EX);
        exit(1);
    }
}

if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 04 offer concurrency probe mode.\n");
    exit(2);
}

$runId = Str::lower(Str::random(12));
$frameworkRoot = storage_path('framework');
$goPath = $frameworkRoot.DIRECTORY_SEPARATOR.'phase04-offer-race-'.$runId.'.go';
$readyPaths = [
    $frameworkRoot.DIRECTORY_SEPARATOR.'phase04-offer-race-'.$runId.'-a.ready',
    $frameworkRoot.DIRECTORY_SEPARATOR.'phase04-offer-race-'.$runId.'-b.ready',
];
$resultPaths = [
    $frameworkRoot.DIRECTORY_SEPARATOR.'phase04-offer-race-'.$runId.'-a.result',
    $frameworkRoot.DIRECTORY_SEPARATOR.'phase04-offer-race-'.$runId.'-b.result',
];
$processes = [];
$created = [];
$summary = null;
$failure = null;

try {
    foreach ([
        'tenants', 'institutions', 'admission_intakes', 'admission_form_templates', 'admission_form_versions',
        'admission_applicant_profiles', 'admission_applications', 'admission_decisions', 'admission_offers',
        'admission_seat_reservations', 'admission_lifecycle_events', 'admission_outbox_events', 'audit_events',
    ] as $table) {
        if ((int) $connection->table($table)->count() !== 0) {
            throw new RuntimeException('The dedicated Phase 04 test schema is not free of persistent fixture data.');
        }
    }

    $now = now()->format('Y-m-d H:i:s');
    $userIds = [];
    $connection->beginTransaction();
    try {
        foreach (['operator', 'reviewer', 'applicant-a', 'applicant-b'] as $label) {
            $userIds[$label] = (int) $connection->table('users')->insertGetId([
                'name' => 'Phase 04 race '.$label,
                'email' => 'phase04-race-'.$runId.'-'.$label.'@example.test',
                'email_verified_at' => $now,
                'password' => password_hash(Str::random(48), PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $tenantId = (int) $connection->table('tenants')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Phase 04 concurrency fixture',
            'slug' => 'phase04-race-'.$runId,
            'status' => 'active',
            'created_by' => $userIds['operator'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $created['tenant_id'] = $tenantId;
        $institutionId = (int) $connection->table('institutions')->insertGetId([
            'tenant_id' => $tenantId,
            'official_name' => 'Phase 04 concurrency institution',
            'display_name' => 'Concurrency fixture',
            'type' => 'academy',
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $created['institution_id'] = $institutionId;
        $intakeId = (int) $connection->table('admission_intakes')->insertGetId([
            'tenant_id' => $tenantId,
            'institution_id' => $institutionId,
            'code' => 'RACE-'.$runId,
            'name' => 'Capacity race fixture',
            'status' => 'open',
            'is_public' => false,
            'capacity' => 1,
            'delivery_mode' => 'on_campus',
            'locale' => 'en',
            'timezone' => 'UTC',
            'created_by' => $userIds['operator'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $created['intake_id'] = $intakeId;
        $templateId = (int) $connection->table('admission_form_templates')->insertGetId([
            'tenant_id' => $tenantId,
            'institution_id' => $institutionId,
            'intake_id' => $intakeId,
            'code' => 'race-form',
            'name' => 'Race test form',
            'status' => 'published',
            'created_by' => $userIds['operator'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $formVersionId = (int) $connection->table('admission_form_versions')->insertGetId([
            'tenant_id' => $tenantId,
            'form_template_id' => $templateId,
            'version_number' => 1,
            'definition' => json_encode(['title' => 'Race test form', 'sections' => []], JSON_THROW_ON_ERROR),
            'status' => 'published',
            'created_by' => $userIds['operator'],
            'published_by' => $userIds['operator'],
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $created['form_template_id'] = $templateId;
        $created['form_version_id'] = $formVersionId;

        $applicationIds = [];
        foreach (['applicant-a', 'applicant-b'] as $index => $label) {
            $profileId = (int) $connection->table('admission_applicant_profiles')->insertGetId([
                'tenant_id' => $tenantId,
                'institution_id' => $institutionId,
                'user_id' => $userIds[$label],
                'given_name' => 'Race Applicant '.($index + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $applicationId = (int) $connection->table('admission_applications')->insertGetId([
                'tenant_id' => $tenantId,
                'institution_id' => $institutionId,
                'intake_id' => $intakeId,
                'applicant_profile_id' => $profileId,
                'form_version_id' => $formVersionId,
                'applicant_user_id' => $userIds[$label],
                'application_number' => 'RACE-'.$runId.'-'.($index + 1),
                'status' => 'decision_pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $decisionId = (int) $connection->table('admission_decisions')->insertGetId([
                'tenant_id' => $tenantId,
                'application_id' => $applicationId,
                'version_number' => 1,
                'decision' => 'admit',
                'applicant_reason' => 'Synthetic concurrency acceptance fixture.',
                'private_reason' => 'Synthetic test fixture.',
                'recommended_by' => $userIds['reviewer'],
                'approved_by' => $userIds['operator'],
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $applicationIds[] = $applicationId;
            $created['profile_ids'][] = $profileId;
            $created['application_ids'][] = $applicationId;
            $created['decision_ids'][] = $decisionId;
        }
        $connection->commit();

        foreach ($applicationIds as $index => $applicationId) {
            $resultPath = $resultPaths[$index];
            if (file_exists($readyPaths[$index]) || file_exists($resultPath)) {
                throw new RuntimeException('The Phase 04 concurrency coordination file already exists.');
            }
            $process = proc_open(
                [PHP_BINARY, __FILE__, 'worker', (string) $tenantId, (string) $applicationId, (string) $userIds['operator'], $readyPaths[$index], $goPath, $resultPath],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $basePath,
                Phase06ProbeEnvironment::current(),
                ['bypass_shell' => true],
            );
            if (! is_resource($process)) {
                throw new RuntimeException('A Phase 04 concurrent workflow process could not start.');
            }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $readyDeadline = microtime(true) + 20;
        while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $readyDeadline) {
            usleep(20000);
        }
        if (! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) {
            throw new RuntimeException('Both independent Laravel sessions did not reach the concurrency barrier.');
        }
        file_put_contents($goPath, 'go', LOCK_EX);

        $results = [];
        $exitCodes = [];
        foreach ($processes as $index => $entry) {
            $deadline = microtime(true) + 30;
            do {
                $status = proc_get_status($entry['process']);
                if (! $status['running']) {
                    $exitCodes[$index] = (int) $status['exitcode'];
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if (! isset($exitCodes[$index])) {
                proc_terminate($entry['process']);
                throw new RuntimeException('A concurrent Phase 04 offer operation timed out.');
            }
            $stdout = stream_get_contents($entry['stdout']);
            $stderr = stream_get_contents($entry['stderr']);
            fclose($entry['stdout']);
            fclose($entry['stderr']);
            $closeCode = proc_close($entry['process']);
            if ($exitCodes[$index] < 0) {
                $exitCodes[$index] = $closeCode;
            }
            $result = is_file($resultPaths[$index]) ? json_decode((string) file_get_contents($resultPaths[$index]), true) : null;
            if ($exitCodes[$index] !== 0 || ! is_array($result) || ! in_array($result['outcome'] ?? null, ['issued', 'capacity_rejected'], true)) {
                throw new RuntimeException('A concurrent Phase 04 offer worker failed its guarded outcome. '.trim((string) $stderr.' '.(string) $stdout));
            }
            $results[] = $result;
        }

        $outcomes = array_column($results, 'outcome');
        $issued = count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'issued'));
        $rejected = count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'capacity_rejected'));
        $activeReservations = (int) $connection->table('admission_seat_reservations')
            ->where('tenant_id', $tenantId)->where('intake_id', $intakeId)->whereIn('status', ['pending', 'accepted'])->count();
        $applicationStatuses = $connection->table('admission_applications')->whereIn('id', $applicationIds)->orderBy('id')->pluck('status')->all();
        if ($issued !== 1 || $rejected !== 1 || $activeReservations !== 1 || count(array_filter($applicationStatuses, static fn (string $status): bool => $status === 'offered')) !== 1) {
            throw new RuntimeException('The concurrent Phase 04 offer race did not preserve capacity one.');
        }

        $summary = [
            'issued' => $issued,
            'capacity_rejected' => $rejected,
            'offer_rows' => (int) $connection->table('admission_offers')->where('tenant_id', $tenantId)->count(),
            'active_reservations' => $activeReservations,
            'offered_applications' => count(array_filter($applicationStatuses, static fn (string $status): bool => $status === 'offered')),
        ];
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if (isset($created['tenant_id'])) {
            try {
                $connection->transaction(function () use ($connection, $created, $userIds): void {
                    $tenantId = (int) $created['tenant_id'];
                    $connection->table('audit_events')->where('tenant_id', $tenantId)->delete();
                    foreach ([
                        'admission_seat_reservations', 'admission_outbox_events', 'admission_lifecycle_events',
                        'admission_offers', 'admission_decisions', 'admission_applications', 'admission_applicant_profiles',
                    ] as $table) {
                        $connection->table($table)->where('tenant_id', $tenantId)->delete();
                    }
                    $connection->table('admission_form_versions')->where('tenant_id', $tenantId)->delete();
                    $connection->table('admission_form_templates')->where('tenant_id', $tenantId)->delete();
                    $connection->table('admission_intakes')->where('tenant_id', $tenantId)->delete();
                    $connection->table('institutions')->where('tenant_id', $tenantId)->delete();
                    $connection->table('tenants')->where('id', $tenantId)->delete();
                    $connection->table('users')->whereIn('id', array_map('intval', $userIds))->delete();
                    foreach ([
                        'institutions', 'admission_intakes', 'admission_form_templates', 'admission_form_versions',
                        'admission_applicant_profiles', 'admission_applications', 'admission_decisions', 'admission_offers',
                        'admission_seat_reservations', 'admission_lifecycle_events', 'admission_outbox_events', 'audit_events',
                    ] as $table) {
                        if ($connection->table($table)->where('tenant_id', $tenantId)->exists()) {
                            throw new RuntimeException('A Phase 04 concurrency fixture row remains after cleanup.');
                        }
                    }
                    if ($connection->table('tenants')->where('id', $tenantId)->exists()) {
                        throw new RuntimeException('A Phase 04 concurrency tenant remains after cleanup.');
                    }
                    if ($connection->table('users')->whereIn('id', array_map('intval', $userIds))->exists()) {
                        throw new RuntimeException('A Phase 04 concurrency fixture identity remains after cleanup.');
                    }
                });
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }
        foreach ([$goPath, ...$readyPaths, ...$resultPaths] as $path) {
            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
        foreach ($processes as $entry) {
            if (is_resource($entry['process'])) {
                $status = proc_get_status($entry['process']);
                if ($status['running']) {
                    proc_terminate($entry['process']);
                    proc_close($entry['process']);
                }
            }
        }
    }

    if ($failure !== null) {
        fwrite(STDERR, 'Phase 04 offer capacity race failed: '.get_class($failure)."\n");
        exit(1);
    }
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Phase 04 offer concurrency probe failed safely.\n");
    exit(1);
}
