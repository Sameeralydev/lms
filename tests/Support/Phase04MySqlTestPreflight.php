<?php

namespace Tests\Support;

use App\Domain\Learning\Phase07ProgressMigrationRecoveryPolicy;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Read-only identity, grant, and contents check before a guarded migration-based MySQL run. */
final class Phase04MySqlTestPreflight
{
    private PDO $connection;

    private bool $runLockAcquired = false;

    private bool $resumingAuthorizedPartialTransition = false;

    private ?string $resumeFailureReason = null;

    /** @var array<string,mixed>|null */
    private ?array $phase07RecoveryPreflight = null;

    /**
     * Validate a single reviewed Phase 07 migration against the current owned schema.
     *
     * @return array<string,mixed>
     */
    public function assertAuthorizedPhase07MigrationUpgrade(): array
    {
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE
            || $this->target->account !== Phase07MySqlTestTarget::ACCOUNT
            || ($this->environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true') {
            throw new RuntimeException('The Phase 07 schema upgrade requires its exact dedicated disposable test target.');
        }
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('A Phase 07 migration upgrade requires its regular current ownership manifest.');
        }
        $manifestContents = file_get_contents($manifestPath);
        $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        if (! is_array($manifest)) {
            throw new RuntimeException('The Phase 07 ownership manifest is invalid; upgrade was refused.');
        }
        $files = $this->localMigrationFiles();
        if (Phase04OwnershipManifestPolicy::differences($manifest, $snapshot) !== []) {
            $migration = Phase07MigrationUpgradePolicy::MIGRATION;
            $intentPath = dirname($manifestPath).DIRECTORY_SEPARATOR.'.phase07-migration-transition-'.$migration.'.json';
            $intent = is_link($intentPath) || ! is_file($intentPath) ? null : json_decode((string) file_get_contents($intentPath), true);
            if (! is_array($intent)
                || ($intent['database'] ?? null) !== Phase07MySqlTestTarget::DATABASE
                || ($intent['username'] ?? null) !== Phase07MySqlTestTarget::ACCOUNT
                || ($intent['host'] ?? null) !== '127.0.0.1'
                || ($intent['authorization'] ?? null) !== Phase07MySqlTestTarget::AUTHORIZATION
                || ($intent['server_identity'] ?? null) !== $snapshot['server_identity']
                || ($intent['migration'] ?? null) !== $migration
                || ($intent['migration_sha256'] ?? null) !== hash_file('sha256', $files[$migration] ?? '')
                || ($intent['previous_manifest_sha256'] ?? null) !== hash('sha256', $manifestContents)
                || ($intent['previous_schema_fingerprint'] ?? null) !== $manifest['schema_fingerprint']
                || ($intent['previous_migrations'] ?? null) !== $manifest['migrations']) {
                throw new RuntimeException('The Phase 07 schema differs from its manifest without a valid authorized migration intent. No ownership state was changed.');
            }
            Phase07MigrationUpgradePolicy::assertCompletedExactlyOne($manifest['migrations'], $snapshot['migrations'], array_keys($files));
            Phase04MySqlTestDataPolicy::assertExpectedTableInventory($snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $files);
            Phase07MigrationUpgradePolicy::assertObjectTransition($manifest['tables'], $snapshot['tables']);
            $changed = [];
            foreach ($manifest['tables'] as $table) {
                if (! isset($manifest['table_schema_fingerprints'][$table], $snapshot['table_schema_fingerprints'][$table])) {
                    throw new RuntimeException('The interrupted Phase 07 migration has incomplete table fingerprints.');
                }
                if (! hash_equals($manifest['table_schema_fingerprints'][$table], $snapshot['table_schema_fingerprints'][$table])) {
                    $changed[] = $table;
                }
            }
            Phase07MigrationUpgradePolicy::assertChangedTables($changed);
            if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
                throw new RuntimeException('The interrupted Phase 07 migration produced unowned auxiliary schema objects.');
            }
            $this->assertNoOtherTargetSessions();

            return $manifest + [
                'phase07_upgrade_migration' => $migration,
                'phase07_upgrade_already_applied' => true,
                'phase07_upgrade_current_object_count' => count($snapshot['object_inventory']['tables_and_views']),
                'phase07_upgrade_current_migration_count' => count($snapshot['migrations']),
            ];
        }
        $migration = Phase07MigrationUpgradePolicy::assertExactlyOnePending($snapshot['migrations'], array_keys($files));
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory($snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $files);
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 migration upgrade rejects unowned auxiliary schema objects.');
        }
        $this->assertMigrationOwnedBaselineForSnapshot($snapshot);
        $this->assertNoOtherTargetSessions();

        return $snapshot + ['phase07_upgrade_migration' => $migration];
    }

    /** Record an exclusive, content-addressed transition intent before DDL. */
    /** @param array<string,mixed> $before */
    public function prepareAuthorizedPhase07MigrationUpgrade(array $before): string
    {
        $migration = (string) ($before['phase07_upgrade_migration'] ?? '');
        $files = $this->localMigrationFiles();
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE
            || $migration !== Phase07MigrationUpgradePolicy::MIGRATION || ! isset($files[$migration])) {
            throw new RuntimeException('The Phase 07 upgrade intent does not identify its reviewed local migration.');
        }
        $this->assertRunLockHeld();
        if (($before['phase07_upgrade_already_applied'] ?? false) === true) {
            $filename = '.phase07-migration-transition-'.$migration.'.json';
            $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$filename;
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException('The interrupted Phase 07 migration has no valid pre-migration transition intent.');
            }

            return $filename;
        }
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $current = $this->stableSnapshot($identity);
        $expected = $before;
        unset($expected['phase07_upgrade_migration']);
        Phase04SchemaSnapshotPolicy::assertStable($expected, $current);
        $manifestContents = file_get_contents($this->manifestPath());
        if (Phase04OwnershipManifestPolicy::differences(json_decode($manifestContents, true) ?? [], $current) !== []) {
            throw new RuntimeException('The Phase 07 ownership manifest changed before the reviewed migration.');
        }
        if (Phase07MigrationUpgradePolicy::assertExactlyOnePending($current['migrations'], array_keys($files)) !== $migration) {
            throw new RuntimeException('The Phase 07 pending migration changed after preflight.');
        }
        $intent = [
            'schema_version' => 1,
            'database' => $current['database'], 'username' => $current['username'], 'host' => $current['host'],
            'server_version' => $current['server_version'], 'server_identity' => $current['server_identity'],
            'authorization' => Phase07MySqlTestTarget::AUTHORIZATION,
            'migration' => $migration, 'migration_sha256' => hash_file('sha256', $files[$migration]),
            'previous_manifest_sha256' => hash('sha256', $manifestContents),
            'previous_schema_fingerprint' => $current['schema_fingerprint'],
            'previous_table_schema_fingerprints' => $current['table_schema_fingerprints'] ?? [],
            'previous_migrations' => $current['migrations'], 'started_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        $filename = '.phase07-migration-transition-'.$migration.'.json';
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$filename;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('A Phase 07 transition intent already exists; inspect the schema before any resume.');
        }
        $this->writeExclusiveLocalEvidence($filename, json_encode($intent, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

        return $filename;
    }

    /** Verify exact migration postconditions then preserve old ownership evidence and advance the manifest. */
    /** @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    public function completeAuthorizedPhase07MigrationUpgrade(array $before, string $intentFilename): array
    {
        $migration = (string) ($before['phase07_upgrade_migration'] ?? '');
        $files = $this->localMigrationFiles();
        $expectedIntentName = '.phase07-migration-transition-'.$migration.'.json';
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE || $migration !== Phase07MigrationUpgradePolicy::MIGRATION
            || $intentFilename !== $expectedIntentName || ! isset($files[$migration])) {
            throw new RuntimeException('The Phase 07 migration postflight does not match the authorized transition.');
        }
        $this->assertRunLockHeld();
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();
        $manifestContents = file_get_contents($manifestPath);
        $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        $intentPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$intentFilename;
        $intent = is_link($intentPath) || ! is_file($intentPath) ? null : json_decode((string) file_get_contents($intentPath), true);
        $beforeManifest = $before;
        unset($beforeManifest['phase07_upgrade_migration'], $beforeManifest['phase07_upgrade_already_applied'], $beforeManifest['phase07_upgrade_current_object_count'], $beforeManifest['phase07_upgrade_current_migration_count']);
        if (! is_array($manifest) || ! is_array($intent)
            || ($intent['database'] ?? null) !== Phase07MySqlTestTarget::DATABASE
            || ($intent['username'] ?? null) !== Phase07MySqlTestTarget::ACCOUNT
            || ($intent['host'] ?? null) !== '127.0.0.1'
            || ($intent['authorization'] ?? null) !== Phase07MySqlTestTarget::AUTHORIZATION
            || ($intent['server_identity'] ?? null) !== $before['server_identity']
            || ($intent['migration'] ?? null) !== $migration
            || ($intent['migration_sha256'] ?? null) !== hash_file('sha256', $files[$migration])
            || ($intent['previous_manifest_sha256'] ?? null) !== hash('sha256', $manifestContents)
            || ($intent['previous_schema_fingerprint'] ?? null) !== $before['schema_fingerprint']
            || ($intent['previous_migrations'] ?? null) !== $before['migrations']
            || Phase04OwnershipManifestPolicy::differences($manifest, $beforeManifest) !== []) {
            throw new RuntimeException('The Phase 07 postflight could not verify the original manifest and migration intent.');
        }
        Phase07MigrationUpgradePolicy::assertCompletedExactlyOne($before['migrations'], $snapshot['migrations'], array_keys($files));
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory($snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $files);
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 transition produced an unowned auxiliary schema object.');
        }
        Phase07MigrationUpgradePolicy::assertObjectTransition($before['tables'], $snapshot['tables']);
        $changed = [];
        foreach ($before['tables'] as $table) {
            if (! isset($before['table_schema_fingerprints'][$table], $snapshot['table_schema_fingerprints'][$table])) {
                throw new RuntimeException('Phase 07 migration fingerprints are incomplete.');
            }
            if (! hash_equals($before['table_schema_fingerprints'][$table], $snapshot['table_schema_fingerprints'][$table])) {
                $changed[] = $table;
            }
        }
        Phase07MigrationUpgradePolicy::assertChangedTables($changed);
        $this->assertNoOtherTargetSessions();
        $previousFilename = '.phase07-test-db-ownership.pre-upgrade-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($previousFilename, $manifestContents);
        $evidence = ['event' => 'phase07.test_schema.authorized_migration_applied', 'database' => $snapshot['database'],
            'migration' => $migration, 'migration_sha256' => hash_file('sha256', $files[$migration]),
            'previous_manifest_file' => $previousFilename, 'previous_manifest_sha256' => hash('sha256', $manifestContents),
            'previous_schema_fingerprint' => $before['schema_fingerprint'], 'current_schema_fingerprint' => $snapshot['schema_fingerprint'],
            'migration_count' => count($snapshot['migrations']), 'object_count' => count($snapshot['object_inventory']['tables_and_views']),
            'verified_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z')];
        $evidenceFilename = '.phase07-test-db-ownership.upgrade-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($evidenceFilename, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->writeManifest($snapshot);
        if (Phase04OwnershipManifestPolicy::differences(json_decode((string) file_get_contents($manifestPath), true) ?? [], $snapshot) !== []) {
            throw new RuntimeException('The Phase 07 migration ownership manifest failed read-back verification.');
        }

        return $snapshot + ['phase07_upgrade_evidence_file' => $evidenceFilename, 'phase07_previous_manifest_file' => $previousFilename];
    }

    private readonly Phase04MySqlTestTarget $target;

    /** @param array<string, string> $environment */
    public function __construct(
        private readonly array $environment,
        ?Phase04MySqlTestTarget $target = null,
        private readonly bool $readOnlyPhase06Preflight = false,
    ) {
        $this->target = $target ?? Phase04MySqlTestTarget::phase04();
        if ($this->readOnlyPhase06Preflight && $this->target->database !== Phase06MySqlTestTarget::DATABASE) {
            throw new RuntimeException('Read-only Phase 06 preflight authorization cannot be used for another database target.');
        }
        TestDatabaseGuard::assertSafe(
            $environment,
            allowPhase05Runner: $this->target->database === 'techmentors_lms_phase05_test',
            allowPhase06Runner: $this->target->database === Phase06MySqlTestTarget::DATABASE,
            allowPhase06ReadOnlyPreflight: $this->readOnlyPhase06Preflight,
            allowPhase07Runner: $this->target->database === Phase07MySqlTestTarget::DATABASE,
        );

        if (! extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The PHP pdo_mysql extension is required for Phase 04 MySQL tests.');
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $environment['DB_HOST'],
                $environment['DB_PORT'],
                $this->target->database,
            );
            $this->connection = new PDO($dsn, $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            $candidateState = is_array($exception->errorInfo ?? null) && isset($exception->errorInfo[0])
                ? (string) $exception->errorInfo[0]
                : (string) $exception->getCode();
            $sqlState = preg_match('/^[A-Z0-9]{5}$/i', $candidateState) === 1 ? strtoupper($candidateState) : 'unknown';
            $driverCode = is_array($exception->errorInfo ?? null) && isset($exception->errorInfo[1]) && ctype_digit((string) $exception->errorInfo[1])
                ? (string) $exception->errorInfo[1]
                : null;
            $safeCode = $driverCode === null ? $sqlState : $sqlState.' / driver '.$driverCode;
            throw new RuntimeException('Could not verify the dedicated MySQL test database (SQLSTATE '.$safeCode.'). No database changes were made.');
        } catch (Throwable) {
            throw new RuntimeException('Could not verify the dedicated MySQL test database. No database changes were made.');
        }
    }

    /** @return array<string, mixed> Verified read-only snapshot. */
    public function assertOwnedSchemaSafe(bool $createManifestForEmptySchema = true, bool $allowVerifiedPartialTransition = false): array
    {
        if ($this->readOnlyPhase06Preflight && $createManifestForEmptySchema) {
            throw new RuntimeException('Read-only Phase 06 preflight cannot create or update an ownership manifest.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $current = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();

        if (is_link($manifestPath)) {
            throw new RuntimeException('The local Phase 04 database ownership manifest cannot be a symlink.');
        }

        if (! file_exists($manifestPath)) {
            if ($current['tables'] !== [] || ($current['object_inventory']['auxiliary'] ?? []) !== []) {
                if ($allowVerifiedPartialTransition && $this->target->database === Phase07MySqlTestTarget::DATABASE) {
                    return $this->assertAuthorizedPhase07InterruptedInitialMigration($current);
                }

                throw new RuntimeException('The new Phase 04 test schema is not empty and has no local ownership manifest; no database changes were made.');
            }

            if ($createManifestForEmptySchema) {
                $this->writeManifest($current);
            }

            return $current;
        }

        if (! is_file($manifestPath)) {
            throw new RuntimeException('The local Phase 04 database ownership manifest is not a regular file.');
        }

        $stored = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($stored)) {
            throw new RuntimeException('The local Phase 04 database ownership manifest is invalid; no database changes were made.');
        }

        $differences = Phase04OwnershipManifestPolicy::differences($stored, $current);
        if ($differences !== []) {
            if ($allowVerifiedPartialTransition && $this->isAuthorizedInterruptedV2Transition($stored, $current)) {
                $this->resumingAuthorizedPartialTransition = true;

                return $current;
            }

            $reason = $this->resumeFailureReason === null ? '' : ' V2 resume validation: '.$this->resumeFailureReason;
            throw new RuntimeException('The Phase 04 schema differs from its local ownership manifest (fields: '.implode(', ', $differences).').'.$reason.' No database changes were made.');
        }

        $this->assertMigrationInventory($current['migrations']);
        $this->assertV2ExpectedTableInventory($current);
        $this->assertNoPersistentFixtureRows($current['tables']);

        return $current;
    }

    /** @return array{snapshot:array<string,mixed>,row_counts:array<string,int>,permission_keys:list<string>,permission_rows:list<array{key:string,scope:string}>,bootstrap_rows:list<array{id:int,completed:int,completed_at:?string}>,guardian_schema:array<string,mixed>|null,sessions:list<array{id:int,user:string,database:?string,command:string,seconds:int,state:?string,query_kind:string}>} */
    /**
     * @param  array<string,mixed>  $before
     * @return array<string,mixed>
     */
    public function completeAuthorizedPhase07InitialMigration(array $before): array
    {
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE
            || $this->target->account !== Phase07MySqlTestTarget::ACCOUNT
            || ($this->environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true') {
            throw new RuntimeException('Initial ownership attestation is available only for the explicitly disposable Phase 07 target.');
        }

        $this->assertRunLockHeld();
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $empty = $this->stableSnapshot($identity);
        Phase04SchemaSnapshotPolicy::assertStable($before, $empty);
        if (($empty['tables'] ?? []) !== [] || ($empty['object_inventory']['auxiliary'] ?? []) !== [] || ($empty['migrations'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 target was not empty immediately before its authorized initial migration.');
        }

        $manifestPath = $this->manifestPath();
        $previousManifestContents = null;
        if (is_link($manifestPath)) {
            throw new RuntimeException('The Phase 07 ownership manifest cannot be a symlink.');
        }
        if (is_file($manifestPath)) {
            $previousManifestContents = file_get_contents($manifestPath);
            $previousManifest = is_string($previousManifestContents) ? json_decode($previousManifestContents, true) : null;
            if (! is_array($previousManifest) || Phase04OwnershipManifestPolicy::differences($previousManifest, $empty) !== []) {
                throw new RuntimeException('The Phase 07 pre-migration ownership manifest no longer matches the verified empty schema.');
            }
        } elseif (file_exists($manifestPath)) {
            throw new RuntimeException('The Phase 07 ownership manifest path is not a regular file.');
        }

        $snapshot = $this->stableSnapshot($identity);
        $localMigrations = $this->localMigrationFiles();
        Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($snapshot['migrations'], array_keys($localMigrations));
        Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($snapshot['migrations']);
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $localMigrations,
        );
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 initial migration produced an unowned auxiliary schema object.');
        }
        $this->assertMigrationOwnedBaselineForSnapshot($snapshot);
        $this->assertNoOtherTargetSessions();

        if ($previousManifestContents === null) {
            if (file_exists($manifestPath)) {
                throw new RuntimeException('The Phase 07 ownership manifest appeared concurrently during initial migration.');
            }
        } elseif (! $this->isRegularLocalFile($manifestPath)
            || ! hash_equals(hash('sha256', $previousManifestContents), hash_file('sha256', $manifestPath) ?: '')) {
            throw new RuntimeException('The Phase 07 ownership manifest changed during initial migration.');
        }

        $previousEvidence = null;
        if ($previousManifestContents !== null) {
            $previousEvidence = '.phase07-test-db-ownership.pre-migration-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
            $this->writeExclusiveLocalEvidence($previousEvidence, $previousManifestContents);
        }
        $evidence = [
            'event' => 'phase07.test_schema.initial_migration_attested',
            'recorded_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'database' => $snapshot['database'],
            'username' => $snapshot['username'],
            'host' => $snapshot['host'],
            'server_version' => $snapshot['server_version'],
            'server_identity' => $snapshot['server_identity'],
            'authorization' => Phase07MySqlTestTarget::AUTHORIZATION,
            'migration_count' => count($snapshot['migrations']),
            'object_count' => count($snapshot['object_inventory']['tables_and_views']),
            'schema_fingerprint' => $snapshot['schema_fingerprint'],
            'previous_empty_manifest_file' => $previousEvidence,
            'previous_empty_manifest_sha256' => $previousManifestContents === null ? null : hash('sha256', $previousManifestContents),
        ];
        $evidenceFilename = '.phase07-test-db-ownership.initial-attestation-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($evidenceFilename, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->writeManifest($snapshot);
        $stored = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($stored) || Phase04OwnershipManifestPolicy::differences($stored, $snapshot) !== []) {
            throw new RuntimeException('The Phase 07 initial ownership manifest failed its read-back verification.');
        }

        return $snapshot + ['phase07_attestation_evidence_file' => $evidenceFilename];
    }

    /** @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    public function completeAuthorizedPhase07InterruptedMigration(array $before): array
    {
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE
            || $this->target->account !== Phase07MySqlTestTarget::ACCOUNT
            || ($this->environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true'
            || $this->phase07RecoveryPreflight === null) {
            throw new RuntimeException('Interrupted migration attestation requires the exact guarded Phase 07 recovery preflight.');
        }

        $this->assertRunLockHeld();
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $snapshot = $this->stableSnapshot($identity);
        $migrationFiles = $this->localMigrationFiles();
        Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($snapshot['migrations'], array_keys($migrationFiles));
        if (($this->phase07RecoveryPreflight['resume_state'] ?? null) === 'complete_unattested_schema') {
            if ($snapshot['migrations'] !== ($this->phase07RecoveryPreflight['applied_migration_rows'] ?? null)) {
                throw new RuntimeException('The full Phase 07 schema changed its migration ledger during ownership recovery.');
            }
            Phase07MigrationBatchPolicy::assertRecoveredCompletion($snapshot['migrations'], array_keys($migrationFiles));
        } else {
            Phase07MigrationBatchPolicy::assertAuthorizedTransition(
                $this->phase07RecoveryPreflight['applied_migration_rows'] ?? [],
                $snapshot['migrations'],
                array_keys($migrationFiles),
            );
        }
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory($snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $migrationFiles);
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 interrupted migration produced an unowned auxiliary schema object.');
        }

        $inspection = $this->inspectCurrentStateReadOnly();
        Phase04SchemaSnapshotPolicy::assertStable($snapshot, $inspection['snapshot']);
        $this->assertMigrationOwnedBaselineForInspection($inspection);
        Phase07ProgressMigrationRecoveryPolicy::assertComplete(
            $inspection['phase07_progress_schema'] ?? [],
            (int) ($inspection['row_counts']['course_delivery_progress'] ?? -1),
            $inspection['phase07_progress_schema']['membership_indexes'] ?? [],
        );
        $this->assertNoOtherTargetSessions();

        $manifestPath = $this->manifestPath();
        if (file_exists($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('A Phase 07 ownership manifest appeared during interrupted migration recovery; no ownership state was changed.');
        }

        $evidence = [
            'event' => 'phase07.test_schema.interrupted_initial_migration_recovered',
            'recorded_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'database' => $snapshot['database'],
            'username' => $snapshot['username'],
            'host' => $snapshot['host'],
            'server_version' => $snapshot['server_version'],
            'server_identity' => $snapshot['server_identity'],
            'authorization' => Phase07MySqlTestTarget::AUTHORIZATION,
            'recovery_preflight' => $this->phase07RecoveryPreflight,
            'completed_migration_count' => count($snapshot['migrations']),
            'completed_object_count' => count($snapshot['object_inventory']['tables_and_views']),
            'completed_schema_fingerprint' => $snapshot['schema_fingerprint'],
            'ownership_manifest_before_recovery' => 'absent',
        ];
        $evidenceFilename = '.phase07-test-db-ownership.recovery-attestation-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($evidenceFilename, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->writeManifest($snapshot);
        $stored = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($stored) || Phase04OwnershipManifestPolicy::differences($stored, $snapshot) !== []) {
            throw new RuntimeException('The Phase 07 recovered ownership manifest failed its read-back verification.');
        }

        return $snapshot + ['phase07_attestation_evidence_file' => $evidenceFilename];
    }

    /**
     * @return array{
     *   snapshot: array<string,mixed>, row_counts: array<string,int>, permission_keys: list<string>,
     *   permission_rows: list<array{key:string,scope:string}>, bootstrap_rows: list<array{id:int,completed:int,completed_at:?string}>,
     *   guardian_schema: array<string,mixed>|null,
     *   sessions: list<array{id:int,user:string,database:?string,command:string,seconds:int,state:?string,query_kind:string}>,
     *   phase07_progress_schema: array<string,mixed>|null
     * }
     */
    public function inspectCurrentStateReadOnly(): array
    {
        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();

        $snapshot = $this->stableSnapshot($identity);
        $rowCounts = $this->readTableRowCounts($snapshot['tables']);

        $permissionRows = in_array('permissions', $snapshot['tables'], true)
            ? array_map(static fn (array $row): array => ['key' => (string) $row['key'], 'scope' => (string) $row['scope']], $this->connection->query('SELECT `key`, `scope` FROM `permissions` ORDER BY `key`')->fetchAll())
            : [];
        $permissionKeys = array_column($permissionRows, 'key');
        $bootstrapRows = in_array('platform_bootstrap_states', $snapshot['tables'], true)
            ? array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'completed' => (int) $row['completed'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            ], $this->connection->query('SELECT `id`, `completed`, `completed_at` FROM `platform_bootstrap_states` ORDER BY `id`')->fetchAll())
            : [];
        $guardianSchema = $this->readGuardianSchema($snapshot['tables'], $rowCounts);

        usleep(500_000);
        $confirmedSnapshot = $this->stableSnapshot($this->identity());
        Phase04SchemaSnapshotPolicy::assertStable($snapshot, $confirmedSnapshot);
        if ($rowCounts !== $this->readTableRowCounts($confirmedSnapshot['tables'])) {
            throw new RuntimeException('The Phase 06 schema row counts changed during read-only inspection.');
        }

        return [
            'snapshot' => $snapshot,
            'row_counts' => $rowCounts,
            'permission_keys' => $permissionKeys,
            'permission_rows' => $permissionRows,
            'bootstrap_rows' => $bootstrapRows,
            'guardian_schema' => $guardianSchema,
            'sessions' => $this->targetSessionInventory(),
            'phase07_progress_schema' => $this->target->database === Phase07MySqlTestTarget::DATABASE
                ? $this->readPhase07ProgressSchema($snapshot['tables'])
                : null,
        ];
    }

    /** @param list<string> $tables
     * @return array{exists:bool,columns:list<array{name:string,type:string,nullable:string,default:mixed}>,indexes:list<array{name:string,columns:list<string>,unique:bool}>,foreign_keys:list<array{name:string,column:string,referenced_table:string,referenced_column:string}>,membership_indexes:list<array{name:string,columns:list<string>,unique:bool}>}
     */
    private function readPhase07ProgressSchema(array $tables): array
    {
        if (! in_array('course_delivery_progress', $tables, true)) {
            $membershipQuery = $this->connection->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $membershipQuery->execute(['table' => 'tenant_memberships']);
            $membershipIndexes = $this->groupIndexes($membershipQuery->fetchAll());

            return ['exists' => false, 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'membership_indexes' => $membershipIndexes];
        }

        $columnsQuery = $this->connection->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION');
        $columnsQuery->execute(['table' => 'course_delivery_progress']);
        $columns = array_map(static fn (array $row): array => [
            'name' => (string) $row['COLUMN_NAME'],
            'type' => strtolower((string) $row['COLUMN_TYPE']),
            'nullable' => (string) $row['IS_NULLABLE'],
            'default' => $row['COLUMN_DEFAULT'],
        ], $columnsQuery->fetchAll());

        $indexQuery = $this->connection->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $indexQuery->execute(['table' => 'course_delivery_progress']);
        $indexesByName = [];
        foreach ($indexQuery->fetchAll() as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexesByName[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) $row['NON_UNIQUE'] === 0];
            $indexesByName[$name]['columns'][] = (string) $row['COLUMN_NAME'];
        }

        $foreignKeyQuery = $this->connection->prepare('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION');
        $foreignKeyQuery->execute(['table' => 'course_delivery_progress']);
        $foreignKeys = array_map(static fn (array $row): array => [
            'name' => (string) $row['CONSTRAINT_NAME'],
            'column' => (string) $row['COLUMN_NAME'],
            'referenced_table' => (string) $row['REFERENCED_TABLE_NAME'],
            'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
        ], $foreignKeyQuery->fetchAll());

        $membershipQuery = $this->connection->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $membershipQuery->execute(['table' => 'tenant_memberships']);

        return [
            'exists' => true,
            'columns' => $columns,
            'indexes' => array_values($indexesByName),
            'foreign_keys' => $foreignKeys,
            'membership_indexes' => $this->groupIndexes($membershipQuery->fetchAll()),
        ];
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array{name:string,columns:list<string>,unique:bool}>
     */
    private function groupIndexes(array $rows): array
    {
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexes[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) $row['NON_UNIQUE'] === 0];
            $indexes[$name]['columns'][] = (string) $row['COLUMN_NAME'];
        }

        return array_values($indexes);
    }

    /** @return list<array{id:int,user:string,database:?string,command:string,seconds:int,state:?string,query_kind:string}> */
    private function targetSessionInventory(): array
    {
        $query = $this->connection->prepare('SELECT ID, USER, DB, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST WHERE USER = :account OR DB = :database ORDER BY ID');
        $query->execute(['account' => $this->target->account, 'database' => $this->target->database]);
        $sessions = [];
        foreach ($query->fetchAll() as $row) {
            $statement = strtoupper(ltrim((string) ($row['INFO'] ?? '')));
            $queryKind = preg_match('/\A(CREATE|ALTER|DROP|RENAME|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE|SELECT|SHOW|SET|COMMIT|ROLLBACK)\b/', $statement, $matches) === 1
                ? strtolower($matches[1])
                : ($statement === '' ? 'idle' : 'other');
            $sessions[] = [
                'id' => (int) $row['ID'],
                'user' => (string) $row['USER'],
                'database' => $row['DB'] === null ? null : (string) $row['DB'],
                'command' => (string) $row['COMMAND'],
                'seconds' => (int) $row['TIME'],
                'state' => $row['STATE'] === null ? null : (string) $row['STATE'],
                'query_kind' => $queryKind,
            ];
        }

        return $sessions;
    }

    public function refreshOwnershipManifest(): void
    {
        if ($this->readOnlyPhase06Preflight) {
            throw new RuntimeException('Read-only Phase 06 preflight cannot refresh an ownership manifest.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->assertRunLockHeld();

        $snapshot = $this->stableSnapshot($identity);
        $this->assertMigrationInventory($snapshot['migrations']);
        $this->assertV2ExpectedTableInventory($snapshot);
        $this->assertNoPersistentFixtureRows($snapshot['tables']);
        $this->writeManifest($snapshot);
    }

    /**
     * Re-attest only the fully applied migration-owned Phase 06 schema from its original empty manifest.
     * This does not mutate MySQL. It preserves the old manifest and local audit evidence before advancing ownership.
     *
     * @return array<string,mixed>
     */
    public function reconcileAuthorizedPhase06MigrationTransition(): array
    {
        if ($this->target->database !== Phase06MySqlTestTarget::DATABASE) {
            throw new RuntimeException('Ownership reconciliation is available only for the Phase 06 test target.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Phase 06 reconciliation requires the original regular ownership manifest.');
        }

        $originalManifest = file_get_contents($manifestPath);
        $stored = is_string($originalManifest) ? json_decode($originalManifest, true) : null;
        if (! is_array($stored)) {
            throw new RuntimeException('Phase 06 reconciliation requires a valid original ownership manifest.');
        }

        $migrationFiles = $this->localMigrationFiles();
        $rowCounts = $this->readTableRowCounts($snapshot['tables']);
        $permissionRows = in_array('permissions', $snapshot['tables'], true)
            ? array_map(static fn (array $row): array => ['key' => (string) $row['key'], 'scope' => (string) $row['scope']], $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll())
            : [];
        $bootstrapRows = in_array('platform_bootstrap_states', $snapshot['tables'], true)
            ? array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'completed' => (int) $row['completed'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll())
            : [];

        Phase06OwnershipReconciliationPolicy::assertAuthorizedInterruptedRun(
            $stored,
            $snapshot,
            $this->target->disposableAuthorization,
            array_keys($migrationFiles),
            $migrationFiles,
            $rowCounts,
            $permissionRows,
            $bootstrapRows,
        );

        $connectionId = (int) $this->connection->query('SELECT CONNECTION_ID()')->fetchColumn();
        $otherSessions = array_values(array_filter(
            $this->targetSessionInventory(),
            static fn (array $session): bool => $session['id'] !== $connectionId,
        ));
        if ($otherSessions !== []) {
            throw new RuntimeException('Phase 06 reconciliation found another visible target session; no ownership state was changed.');
        }

        usleep(500_000);
        $confirmedSnapshot = $this->stableSnapshot($this->identity());
        Phase04SchemaSnapshotPolicy::assertStable($snapshot, $confirmedSnapshot);
        $confirmedCounts = $this->readTableRowCounts($confirmedSnapshot['tables']);
        $confirmedPermissionRows = array_map(static fn (array $row): array => ['key' => (string) $row['key'], 'scope' => (string) $row['scope']], $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll());
        $confirmedBootstrapRows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'completed' => (int) $row['completed'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll());
        if ($rowCounts !== $confirmedCounts || $permissionRows !== $confirmedPermissionRows || $bootstrapRows !== $confirmedBootstrapRows) {
            throw new RuntimeException('Phase 06 schema data changed during reconciliation; no ownership state was changed.');
        }

        $this->assertRunLockHeld();
        $backupPath = $this->preservePhase06ManifestForReconciliation($originalManifest);
        $evidence = [
            'event' => 'phase06.interrupted_test_run.ownership_reconciled',
            'recorded_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'database' => $snapshot['database'],
            'username' => $snapshot['username'],
            'host' => $snapshot['host'],
            'server_version' => $snapshot['server_version'],
            'server_identity' => $snapshot['server_identity'],
            'authorization' => $this->target->disposableAuthorization,
            'previous_manifest_file' => basename($backupPath),
            'previous_manifest_sha256' => hash('sha256', $originalManifest),
            'new_schema_fingerprint' => $snapshot['schema_fingerprint'],
            'migration_count' => count($snapshot['migrations']),
            'table_count' => count($snapshot['tables']),
            'migration_owned_nonempty_rows' => [
                'migrations' => $rowCounts['migrations'] ?? 0,
                'permissions' => $rowCounts['permissions'] ?? 0,
                'platform_bootstrap_states' => $rowCounts['platform_bootstrap_states'] ?? 0,
            ],
            'verified_checks' => [
                'original_empty_manifest_and_same_server', 'exact_dedicated_database_account_and_mysql_8_4',
                'exact_schema_scoped_grants', 'complete_local_migration_inventory', 'single_batch_one',
                'exact_migration_created_table_inventory', 'no_auxiliary_objects', 'exact_migration_owned_baseline_rows',
                'stable_schema_and_row_counts', 'exclusive_local_and_mysql_run_locks',
            ],
        ];
        $evidencePath = $this->writeExclusiveLocalEvidence(
            '.phase06-test-db-ownership.reconciliation-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json',
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        $this->writeManifest($snapshot);
        $written = json_decode((string) file_get_contents($manifestPath), true);
        if ($written !== $snapshot) {
            throw new RuntimeException('Phase 06 ownership manifest read-back failed after reconciliation.');
        }

        return $snapshot + ['reconciliation_evidence_file' => basename($evidencePath), 'previous_manifest_file' => basename($backupPath)];
    }

    /**
     * Re-attest only a complete migration-owned Phase 05 schema after an interrupted guarded run.
     * The original empty manifest is preserved beside the new manifest as local audit evidence.
     *
     * @return array<string,mixed>
     */
    public function reconcileAuthorizedPhase05MigrationTransition(): array
    {
        if ($this->target->database !== 'techmentors_lms_phase05_test') {
            throw new RuntimeException('Ownership reconciliation is available only for the Phase 05 test target.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();

        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Phase 05 reconciliation requires the original regular ownership manifest.');
        }

        $originalManifest = file_get_contents($manifestPath);
        $stored = is_string($originalManifest) ? json_decode($originalManifest, true) : null;
        if (! is_array($stored) || ! Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($stored, $snapshot, $this->target->disposableAuthorization)) {
            throw new RuntimeException('Phase 05 reconciliation is allowed only from this target’s original empty-schema ownership manifest.');
        }

        $this->assertMigrationInventory($snapshot['migrations']);
        Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($snapshot['migrations']);
        $this->assertV2ExpectedTableInventory($snapshot);
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('Phase 05 reconciliation rejects unowned triggers, routines, or scheduled events.');
        }
        $this->assertNoPersistentFixtureRows($snapshot['tables']);
        $this->preserveManifestForReconciliation($originalManifest);
        $this->writeManifest($snapshot);

        return $snapshot;
    }

    /**
     * Permit exactly one newly added local Phase 05 migration after verifying the owned prior schema.
     * The normal Laravel test bootstrap performs the migration on this guarded disposable target.
     *
     * @return array<string,mixed>
     */
    public function assertAuthorizedPhase05MigrationUpgrade(): array
    {
        if ($this->target->database !== 'techmentors_lms_phase05_test') {
            throw new RuntimeException('Schema upgrades are available only for the Phase 05 test target.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('A Phase 05 schema upgrade requires a regular ownership manifest for the current schema.');
        }
        $stored = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($stored) || Phase04OwnershipManifestPolicy::differences($stored, $snapshot) !== []) {
            throw new RuntimeException('The current Phase 05 schema does not match its ownership manifest; upgrade was refused.');
        }

        $localMigrations = $this->localMigrationFiles();
        $localNames = array_keys($localMigrations);
        $appliedNames = array_column($snapshot['migrations'], 'migration');
        if (
            count($localNames) !== count($appliedNames) + 1
            || array_slice($localNames, 0, count($appliedNames)) !== $appliedNames
        ) {
            throw new RuntimeException('A Phase 05 guarded upgrade accepts exactly one new trailing local migration.');
        }
        Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($snapshot['migrations']);
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $localMigrations,
        );
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('A Phase 05 guarded upgrade rejects unowned triggers, routines, or scheduled events.');
        }

        $rowCounts = $this->readTableRowCounts($snapshot['tables']);
        $permissionRows = $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll();
        $bootstrapRows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'completed' => (int) $row['completed'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll());
        Phase04MySqlTestDataPolicy::assertAuthorizedMigrationPrefixBaseline(
            $snapshot['tables'], $rowCounts, $permissionRows, $bootstrapRows, $snapshot['migrations'],
        );

        return $snapshot;
    }

    /**
     * Validate one reviewed additive migration against the exact current Phase 06 ownership state.
     * This path is intentionally separate from the normal exact-inventory preflight.
     *
     * @return array<string,mixed>
     */
    public function assertAuthorizedPhase06MigrationUpgrade(): array
    {
        if ($this->target->database !== Phase06MySqlTestTarget::DATABASE) {
            throw new RuntimeException('Schema upgrades are available only for the Phase 06 test target.');
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $this->acquireRunLock();
        $snapshot = $this->stableSnapshot($identity);
        $manifestPath = $this->manifestPath();
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('A Phase 06 guarded upgrade requires a regular ownership manifest for the current schema.');
        }
        $manifestContents = file_get_contents($manifestPath);
        $stored = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        $localMigrations = $this->localMigrationFiles();
        if (! is_array($stored)) {
            throw new RuntimeException('The Phase 06 ownership manifest is invalid; upgrade was refused.');
        }
        $manifestSnapshot = $snapshot;
        if (! array_key_exists('table_schema_fingerprints', $stored)) {
            unset($manifestSnapshot['table_schema_fingerprints']);
        }
        if (Phase04OwnershipManifestPolicy::differences($stored, $manifestSnapshot) !== []) {
            $migrationName = $this->assertAuthorizedAppliedPhase06MigrationTransition($stored, (string) $manifestContents, $snapshot, $localMigrations);
            $intentContents = file_get_contents($this->phase06MigrationIntentPath($migrationName));
            $transitionIntent = is_string($intentContents) ? json_decode($intentContents, true) : null;

            $result = $stored + [
                'phase06_upgrade_migration' => $migrationName,
                'phase06_upgrade_already_applied' => true,
                'phase06_upgrade_current_object_count' => count($snapshot['object_inventory']['tables_and_views']),
                'phase06_upgrade_current_migration_count' => count($snapshot['migrations']),
            ];
            if (is_array($transitionIntent['previous_table_schema_fingerprints'] ?? null)) {
                $result['table_schema_fingerprints'] = $transitionIntent['previous_table_schema_fingerprints'];
            }

            return $result;
        }

        $migrationName = Phase06MigrationUpgradePolicy::assertExactlyOnePending($snapshot['migrations'], array_keys($localMigrations));
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $localMigrations,
        );
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('A Phase 06 guarded upgrade rejects unowned triggers, routines, or scheduled events.');
        }
        $this->assertMigrationOwnedBaselineForSnapshot($snapshot);
        $this->assertNoOtherTargetSessions();

        return $snapshot + [
            'phase06_upgrade_migration' => $migrationName,
            'phase06_upgrade_already_applied' => false,
        ];
    }

    /** @param array<string,mixed> $before
     * Write an exclusive local intent record immediately before applying the authorized migration.
     */
    public function prepareAuthorizedPhase06MigrationUpgrade(array $before): string
    {
        $migrationName = (string) ($before['phase06_upgrade_migration'] ?? '');
        $localMigrations = $this->localMigrationFiles();
        if ($this->target->database !== Phase06MySqlTestTarget::DATABASE
            || ! isset($localMigrations[$migrationName])
            || ! is_file($localMigrations[$migrationName])) {
            throw new RuntimeException('The Phase 06 migration transition intent does not identify a local migration.');
        }
        $this->assertRunLockHeld();

        if (($before['phase06_upgrade_already_applied'] ?? false) === true) {
            $path = $this->phase06MigrationIntentPath($migrationName);
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException('The interrupted Phase 06 migration has no valid pre-migration transition intent.');
            }

            return basename($path);
        }

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $current = $this->stableSnapshot($identity);
        $expectedBefore = $before;
        unset($expectedBefore['phase06_upgrade_migration'], $expectedBefore['phase06_upgrade_already_applied']);
        Phase04SchemaSnapshotPolicy::assertStable($expectedBefore, $current);
        $manifestContents = file_get_contents($this->manifestPath());
        $stored = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        $manifestSnapshot = $current;
        if (is_array($stored) && ! array_key_exists('table_schema_fingerprints', $stored)) {
            unset($manifestSnapshot['table_schema_fingerprints']);
        }
        if (! is_array($stored) || Phase04OwnershipManifestPolicy::differences($stored, $manifestSnapshot) !== []) {
            throw new RuntimeException('The Phase 06 ownership manifest changed before its authorized migration transition.');
        }
        if (Phase06MigrationUpgradePolicy::assertExactlyOnePending($current['migrations'], array_keys($localMigrations)) !== $migrationName) {
            throw new RuntimeException('The Phase 06 pending migration changed after preflight.');
        }

        $intent = [
            'schema_version' => 2,
            'database' => $current['database'],
            'username' => $current['username'],
            'host' => $current['host'],
            'server_version' => $current['server_version'],
            'server_identity' => $current['server_identity'],
            'disposable_authorization' => Phase06MySqlTestTarget::AUTHORIZATION,
            'migration' => $migrationName,
            'migration_sha256' => hash_file('sha256', $localMigrations[$migrationName]),
            'previous_manifest_sha256' => hash('sha256', $manifestContents),
            'previous_schema_fingerprint' => $current['schema_fingerprint'],
            'previous_table_schema_fingerprints' => $current['table_schema_fingerprints'] ?? [],
            'previous_migrations' => $current['migrations'],
            'started_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        $path = $this->phase06MigrationIntentPath($migrationName);
        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink for the Phase 06 migration transition intent.');
        }
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            $expected = $intent;
            unset($expected['started_at_utc']);
            if (! is_array($existing)) {
                throw new RuntimeException('An existing Phase 06 migration transition intent is invalid.');
            }
            $existingComparable = $existing;
            unset($existingComparable['started_at_utc']);
            if ($existingComparable !== $expected) {
                throw new RuntimeException('An existing Phase 06 migration transition intent does not match the current ownership state.');
            }

            return basename($path);
        }

        $this->writeExclusiveLocalEvidence(basename($path), json_encode($intent, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

        return basename($path);
    }

    /** @param array<string,mixed> $before
     * @return array<string,mixed>
     *                             Verify and attest precisely the one migration recorded in the pre-migration intent.
     */
    public function completeAuthorizedPhase06MigrationUpgrade(array $before, string $intentFilename): array
    {
        $migrationName = (string) ($before['phase06_upgrade_migration'] ?? '');
        $localMigrations = $this->localMigrationFiles();
        if ($this->target->database !== Phase06MySqlTestTarget::DATABASE
            || ! isset($localMigrations[$migrationName])
            || $intentFilename !== basename($this->phase06MigrationIntentPath($migrationName))) {
            throw new RuntimeException('The Phase 06 migration completion does not match its authorized transition intent.');
        }
        $this->assertRunLockHeld();

        $identity = $this->identity();
        $this->assertTargetIdentity($identity);
        $this->assertSchemaScopedGrants($identity['username'], $identity['host']);
        $snapshot = $this->stableSnapshot($identity);
        $previousManifestContents = file_get_contents($this->manifestPath());
        $previousManifest = is_string($previousManifestContents) ? json_decode($previousManifestContents, true) : null;
        $intentPath = $this->phase06MigrationIntentPath($migrationName);
        $intent = is_link($intentPath) || ! is_file($intentPath) ? null : json_decode((string) file_get_contents($intentPath), true);
        $intentMatches = is_array($intent)
            && in_array($intent['schema_version'] ?? null, [1, 2], true)
            && ($intent['database'] ?? null) === Phase06MySqlTestTarget::DATABASE
            && ($intent['username'] ?? null) === Phase06MySqlTestTarget::ACCOUNT
            && ($intent['host'] ?? null) === '127.0.0.1'
            && ($intent['server_version'] ?? null) === $before['server_version']
            && ($intent['server_identity'] ?? null) === $before['server_identity']
            && ($intent['disposable_authorization'] ?? null) === Phase06MySqlTestTarget::AUTHORIZATION
            && ($intent['migration'] ?? null) === $migrationName
            && ($intent['migration_sha256'] ?? null) === hash_file('sha256', $localMigrations[$migrationName])
            && ($intent['schema_version'] !== 2 || ($intent['previous_table_schema_fingerprints'] ?? null) === ($before['table_schema_fingerprints'] ?? null))
            && is_string($previousManifestContents)
            && ($intent['previous_manifest_sha256'] ?? null) === hash('sha256', $previousManifestContents)
            && ($intent['previous_schema_fingerprint'] ?? null) === $before['schema_fingerprint']
            && ($intent['previous_migrations'] ?? null) === $before['migrations'];
        $beforeManifestSnapshot = array_diff_key($before, [
            'phase06_upgrade_migration' => true,
            'phase06_upgrade_already_applied' => true,
            'phase06_upgrade_current_object_count' => true,
            'phase06_upgrade_current_migration_count' => true,
        ]);
        if (is_array($previousManifest) && ! array_key_exists('table_schema_fingerprints', $previousManifest)) {
            unset($beforeManifestSnapshot['table_schema_fingerprints']);
        }
        if (! $intentMatches || ! is_array($previousManifest)
            || Phase04OwnershipManifestPolicy::differences($previousManifest, $beforeManifestSnapshot) !== []) {
            throw new RuntimeException('The Phase 06 migration completion could not verify its original manifest and transition intent.');
        }

        $expectedMigration = Phase06MigrationUpgradePolicy::assertCompletedExactlyOne(
            $before['migrations'], $snapshot['migrations'], array_keys($localMigrations),
        );
        if ($expectedMigration !== $migrationName) {
            throw new RuntimeException('The Phase 06 migration ledger does not match the exact authorized trailing migration.');
        }
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $localMigrations,
        );
        if (($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 06 migration transition produced an unowned auxiliary schema object.');
        }
        $newObjects = array_values(array_diff($snapshot['tables'], $before['tables']));
        $removedObjects = array_values(array_diff($before['tables'], $snapshot['tables']));
        $migrationOwnedObjects = array_values(array_diff(
            Phase04MySqlTestDataPolicy::expectedCreatedTables([['migration' => $migrationName, 'batch' => 1]], $localMigrations),
            ['migrations'],
        ));
        sort($newObjects);
        sort($removedObjects);
        sort($migrationOwnedObjects);
        if ($removedObjects !== [] || $newObjects !== $migrationOwnedObjects) {
            throw new RuntimeException('The Phase 06 migration transition added or removed schema objects outside its declared migration inventory.');
        }
        $this->assertDeclaredPhase06SchemaExtensions($migrationName);
        if (is_array($before['table_schema_fingerprints'] ?? null)) {
            Phase06MigrationUpgradePolicy::assertOnlyDeclaredTableChanges(
                $migrationName,
                $before['tables'],
                $before['table_schema_fingerprints'],
                $snapshot['table_schema_fingerprints'] ?? [],
            );
        } elseif (($intent['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('The Phase 06 migration transition has no complete pre-migration table fingerprint inventory.');
        }
        $this->assertMigrationOwnedBaselineForSnapshot($snapshot);
        $this->assertNoOtherTargetSessions();

        $previousEvidence = '.phase06-test-db-ownership.pre-upgrade-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($previousEvidence, $previousManifestContents);
        $evidence = [
            'event' => 'phase06.test_schema.authorized_migration_applied',
            'recorded_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'database' => $snapshot['database'],
            'username' => $snapshot['username'],
            'host' => $snapshot['host'],
            'server_version' => $snapshot['server_version'],
            'server_identity' => $snapshot['server_identity'],
            'authorization' => Phase06MySqlTestTarget::AUTHORIZATION,
            'migration' => $migrationName,
            'migration_sha256' => hash_file('sha256', $localMigrations[$migrationName]),
            'previous_manifest_file' => $previousEvidence,
            'previous_manifest_sha256' => hash('sha256', $previousManifestContents),
            'previous_schema_fingerprint' => $before['schema_fingerprint'],
            'current_schema_fingerprint' => $snapshot['schema_fingerprint'],
            'migration_count' => count($snapshot['migrations']),
            'object_count' => count($snapshot['object_inventory']['tables_and_views']),
        ];
        $evidenceFilename = '.phase06-test-db-ownership.upgrade-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence($evidenceFilename, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->writeManifest($snapshot);
        if (Phase04OwnershipManifestPolicy::differences(
            json_decode((string) file_get_contents($this->manifestPath()), true) ?? [],
            $snapshot,
        ) !== []) {
            throw new RuntimeException('The Phase 06 migration ownership manifest failed its read-back verification.');
        }

        return $snapshot + ['phase06_upgrade_evidence_file' => $evidenceFilename, 'phase06_previous_manifest_file' => $previousEvidence];
    }

    public function isResumingAuthorizedPartialTransition(): bool
    {
        return $this->resumingAuthorizedPartialTransition;
    }

    public function __destruct()
    {
        if (! $this->runLockAcquired) {
            return;
        }

        try {
            $query = $this->connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $query->execute(['lock_name' => $this->target->runLockName]);
        } catch (Throwable) {
            // Closing the PDO session also releases the server-level advisory lock.
        }
    }

    private function acquireRunLock(): void
    {
        $query = $this->connection->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $query->execute(['lock_name' => $this->target->runLockName]);

        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Another Phase 04 guarded MySQL test run holds the server-level lock.');
        }

        $this->runLockAcquired = true;
    }

    private function assertRunLockHeld(): void
    {
        $query = $this->connection->prepare('SELECT IS_USED_LOCK(:lock_name) = CONNECTION_ID()');
        $query->execute(['lock_name' => $this->target->runLockName]);

        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('The Phase 04 server-level test lock was lost before postflight.');
        }
    }

    /** @return array{database:string,username:string,host:string,server_version:string,server_identity:array{uuid:string,hostname:string,port:int}} */
    private function identity(): array
    {
        $row = $this->connection->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, VERSION() AS server_version, @@server_uuid AS server_uuid, @@hostname AS server_hostname, @@port AS server_port')->fetch();
        if (! is_array($row)) {
            throw new RuntimeException('MySQL did not return a database identity.');
        }

        [$username, $host] = array_pad(explode('@', (string) $row['account_name'], 2), 2, '');

        $serverVersion = (string) $row['server_version'];
        if ($this->target->requiredServerVersionPrefix !== '' && ! str_starts_with($serverVersion, $this->target->requiredServerVersionPrefix)) {
            throw new RuntimeException('The dedicated MySQL test target does not run the required server version. No database changes were made.');
        }

        return [
            'database' => (string) $row['database_name'],
            'username' => trim($username, "`'\""),
            'host' => trim($host, "`'\""),
            'server_version' => $serverVersion,
            'server_identity' => [
                'uuid' => (string) $row['server_uuid'],
                'hostname' => (string) $row['server_hostname'],
                'port' => (int) $row['server_port'],
            ],
        ];
    }

    private function assertSchemaScopedGrants(string $username, string $host): void
    {
        $grants = array_map('strval', $this->connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        if ($this->target->database === Phase06MySqlTestTarget::DATABASE) {
            Phase06MySqlGrantPolicy::assertAllowed($grants, $username, $host);
        } elseif ($this->target->database === Phase07MySqlTestTarget::DATABASE) {
            Phase07MySqlGrantPolicy::assertAllowed($grants, $username, $host);
        } else {
            Phase04MySqlGrantPolicy::assertAllowed($grants, $username, $host, $this->target->database);
        }
    }

    /** @param array{database:string,username:string,host:string,server_version:string,server_identity:array{uuid:string,hostname:string,port:int}} $identity */
    private function assertTargetIdentity(array $identity): void
    {
        if ($this->target->database === Phase06MySqlTestTarget::DATABASE) {
            Phase06MySqlGrantPolicy::assertIdentity($identity['database'], $identity['username'], $identity['host']);

            return;
        }
        if ($this->target->database === Phase07MySqlTestTarget::DATABASE) {
            Phase07MySqlGrantPolicy::assertIdentity($identity['database'], $identity['username'], $identity['host']);

            return;
        }
        Phase04MySqlGrantPolicy::assertIdentity($identity['database'], $identity['username'], $identity['host'], $this->target->database, $this->target->account);
    }

    /** @param array{database:string,username:string,host:string,server_version:string,server_identity:array{uuid:string,hostname:string,port:int}} $identity
     * @return array<string, mixed>
     */
    private function snapshot(array $identity): array
    {
        $query = $this->connection->prepare("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE IN ('BASE TABLE', 'VIEW') ORDER BY TABLE_NAME");
        $query->execute(['schema' => $this->target->database]);
        $objects = $query->fetchAll();
        $tables = array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $objects);
        $migrations = [];

        if (in_array('migrations', $tables, true)) {
            $migrations = array_map(static fn (array $row): array => [
                'migration' => (string) $row['migration'],
                'batch' => (int) $row['batch'],
            ], $this->connection->query('SELECT migration, batch FROM `migrations` ORDER BY migration')->fetchAll());
        }

        $schema = [$objects];
        $schemaQueries = [
            "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION,EXTRA,COLUMN_KEY,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '".$this->target->database."' ORDER BY TABLE_NAME,ORDINAL_POSITION",
            "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '".$this->target->database."' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
            "SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '".$this->target->database."' ORDER BY TABLE_NAME,CONSTRAINT_NAME",
            "SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = '".$this->target->database."' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
            "SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '".$this->target->database."' ORDER BY TRIGGER_NAME",
            "SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '".$this->target->database."' ORDER BY TABLE_NAME",
        ];
        if ($this->target->verifyServerIdentity) {
            $schemaQueries[] = "SELECT ROUTINE_NAME,ROUTINE_TYPE,DEFINER,SECURITY_TYPE,SQL_DATA_ACCESS,IS_DETERMINISTIC FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '".$this->target->database."' ORDER BY ROUTINE_NAME";
            $schemaQueries[] = "SELECT EVENT_NAME,DEFINER,STATUS,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '".$this->target->database."' ORDER BY EVENT_NAME";
        }
        foreach ($schemaQueries as $schemaQuery) {
            $schema[] = $this->connection->query($schemaQuery)->fetchAll();
        }
        $schemaFingerprint = hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));

        $snapshot = [
            'database' => $this->target->database,
            'username' => $this->target->account,
            'host' => $identity['host'],
            'server_version' => $identity['server_version'],
            'tables' => $tables,
            'migrations' => $migrations,
            'schema_fingerprint' => $schemaFingerprint,
        ];
        if ($this->target->verifyServerIdentity) {
            $snapshot['server_identity'] = $identity['server_identity'];
            $snapshot['disposable_authorization'] = $this->target->disposableAuthorization;
            $snapshot['table_schema_fingerprints'] = $this->tableSchemaFingerprints($tables, $schema);
            $snapshot['object_inventory'] = [
                'tables_and_views' => array_map(static fn (array $object): array => [
                    'name' => (string) $object['TABLE_NAME'],
                    'type' => (string) $object['TABLE_TYPE'],
                ], $objects),
                'auxiliary' => array_merge(
                    array_map(static fn (array $row): string => 'trigger:'.(string) $row['TRIGGER_NAME'], $schema[5]),
                    array_map(static fn (array $row): string => 'routine:'.(string) $row['ROUTINE_NAME'], $schema[7]),
                    array_map(static fn (array $row): string => 'event:'.(string) $row['EVENT_NAME'], $schema[8]),
                ),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  list<string>  $tables
     *
     * @phpstan-param list<list<array<string,mixed>>> $schema
     *
     * @return array<string,string>
     */
    private function tableSchemaFingerprints(array $tables, array $schema): array
    {
        $fingerprints = [];
        foreach ($tables as $table) {
            $definition = [];
            foreach ($schema as $index => $rows) {
                if (in_array($index, [7, 8], true)) {
                    continue;
                }
                $tableColumn = $index === 5 ? 'EVENT_OBJECT_TABLE' : 'TABLE_NAME';
                $definition[] = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row[$tableColumn] ?? '') === $table));
            }
            $fingerprints[$table] = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
        }
        ksort($fingerprints);

        return $fingerprints;
    }

    /** @param array{database:string,username:string,host:string,server_version:string,server_identity:array{uuid:string,hostname:string,port:int}} $identity
     * @return array<string, mixed>
     */
    private function stableSnapshot(array $identity): array
    {
        $first = $this->snapshot($identity);
        usleep(500_000);
        $second = $this->snapshot($identity);

        Phase04SchemaSnapshotPolicy::assertStable($first, $second);

        return $second;
    }

    /** @param list<array{migration:string,batch:int}> $migrations */
    private function assertMigrationInventory(array $migrations): void
    {
        $localMigrations = $this->localMigrationNamesForTarget();
        Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($migrations, $localMigrations);
    }

    /** @return list<string> */
    private function localMigrationNamesForTarget(): array
    {
        $names = array_map(static fn (string $path): string => basename($path, '.php'), glob(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: []);
        if ($this->target->database === Phase06MySqlTestTarget::DATABASE) {
            $names = array_values(array_filter($names, static fn (string $name): bool => strcmp($name, Phase06MySqlTestTarget::MIGRATION_CUTOFF) <= 0));
        } elseif ($this->target->database === Phase07MySqlTestTarget::DATABASE) {
            $names = array_values(array_filter($names, static fn (string $name): bool => Phase07MySqlTestTarget::includesMigration($name)));
        }
        sort($names);

        return $names;
    }

    /** @param array<string,mixed> $stored
     * @param  array<string,mixed>  $current
     */
    private function isAuthorizedInterruptedV2Transition(array $stored, array $current): bool
    {
        if (! $this->target->verifyServerIdentity) {
            return false;
        }

        if (! Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($stored, $current, $this->target->disposableAuthorization)) {
            return false;
        }

        $localMigrations = $this->localMigrationFiles();
        try {
            Phase04MySqlTestDataPolicy::assertAuthorizedMigrationPrefix($current['migrations'], array_keys($localMigrations));
            if ($current['object_inventory']['auxiliary'] !== []) {
                return false;
            }
            $guardianSchema = $this->readGuardianSchema($current['tables'], $this->readTableRowCounts($current['tables']));
            if ($guardianSchema === null) {
                return false;
            }
            Phase04V2MigrationResumePolicy::assertKnownGuardianMigrationPrefix(
                $current['object_inventory']['tables_and_views'],
                $current['migrations'],
                $localMigrations,
                $guardianSchema,
            );
            $this->assertAuthorizedPrefixRows($current);
        } catch (RuntimeException $exception) {
            $this->resumeFailureReason = $exception->getMessage();

            return false;
        }

        return true;
    }

    /** @return array<string,string> */
    private function localMigrationFiles(): array
    {
        $files = [];
        foreach (glob(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $name = basename($path, '.php');
            if ($this->target->database !== Phase07MySqlTestTarget::DATABASE || Phase07MySqlTestTarget::includesMigration($name)) {
                $files[$name] = $path;
            }
        }
        ksort($files);

        return $files;
    }

    /** @param array<string,mixed> $snapshot */
    private function assertV2ExpectedTableInventory(array $snapshot): void
    {
        if (! $this->target->verifyServerIdentity) {
            return;
        }

        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'],
            $snapshot['migrations'],
            $this->localMigrationFiles(),
        );
    }

    /** @param array<string,mixed> $snapshot */
    private function assertAuthorizedPrefixRows(array $snapshot): void
    {
        $tables = $snapshot['tables'];
        $rowCounts = $this->readTableRowCounts($tables);

        $permissionRows = $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll();
        $bootstrapRows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'completed' => (int) $row['completed'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll());

        Phase04MySqlTestDataPolicy::assertAuthorizedMigrationPrefixBaseline(
            $tables,
            $rowCounts,
            $permissionRows,
            $bootstrapRows,
            $snapshot['migrations'],
        );
    }

    /** @param list<string> $tables
     * @param  array<string,int>  $rowCounts
     * @return array<string,mixed>|null
     */
    private function readGuardianSchema(array $tables, array $rowCounts): ?array
    {
        if (! in_array('admission_guardian_authorizations', $tables, true)) {
            return null;
        }

        return [
            'columns' => $this->connection->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '".$this->target->database."' AND TABLE_NAME = 'admission_guardian_authorizations' ORDER BY ORDINAL_POSITION")->fetchAll(),
            'indexes' => $this->connection->query("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '".$this->target->database."' AND TABLE_NAME = 'admission_guardian_authorizations' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME")->fetchAll(),
            'foreign_keys' => $this->connection->query("SELECT CONSTRAINT_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS columns_list,REFERENCED_TABLE_NAME,GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS referenced_columns FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '".$this->target->database."' AND TABLE_NAME = 'admission_guardian_authorizations' AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY CONSTRAINT_NAME,REFERENCED_TABLE_NAME ORDER BY CONSTRAINT_NAME")->fetchAll(),
            'rows' => $rowCounts['admission_guardian_authorizations'] ?? 0,
        ];
    }

    /** @param list<string> $tables
     * @return array<string,int>
     */
    private function readTableRowCounts(array $tables): array
    {
        $rowCounts = [];
        foreach ($tables as $table) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('The Phase 04 V2 schema contains an unexpected table identifier.');
            }
            $rowCounts[$table] = (int) $this->connection->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        }

        return $rowCounts;
    }

    /** @param list<string> $tables */
    private function assertNoPersistentFixtureRows(array $tables): void
    {
        $rowCounts = [];
        foreach ($tables as $table) {
            if ($table === 'migrations') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('The Phase 04 schema contains an unexpected table identifier.');
            }

            $rowCounts[$table] = (int) $this->connection->query('SELECT COUNT(*) FROM `'.str_replace('`', '``', $table).'`')->fetchColumn();
        }

        $permissionRows = in_array('permissions', $tables, true)
            ? $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll()
            : [];
        $bootstrapRows = in_array('platform_bootstrap_states', $tables, true)
            ? array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'completed' => (int) $row['completed'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll())
            : [];

        $applied = in_array('migrations', $tables, true)
            ? array_map(static fn (array $row): array => ['migration' => (string) $row['migration'], 'batch' => (int) $row['batch']], $this->connection->query('SELECT migration, batch FROM `migrations` ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC))
            : [];
        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($tables, $rowCounts, $permissionRows, $bootstrapRows, $applied);
    }

    /** @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function assertAuthorizedPhase07InterruptedInitialMigration(array $current): array
    {
        if ($this->target->database !== Phase07MySqlTestTarget::DATABASE
            || $this->target->account !== Phase07MySqlTestTarget::ACCOUNT
            || ($this->environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true') {
            throw new RuntimeException('Interrupted-migration recovery is available only for the explicitly disposable Phase 07 target.');
        }

        $inspection = $this->inspectCurrentStateReadOnly();
        $snapshot = $inspection['snapshot'];
        Phase04SchemaSnapshotPolicy::assertStable($current, $snapshot);
        $migrationFiles = $this->localMigrationFiles();
        $migrationNames = array_keys($migrationFiles);
        $isCompleteUnattestedSchema = count($snapshot['migrations']) === count($migrationNames);
        if ($isCompleteUnattestedSchema) {
            Phase07MigrationBatchPolicy::assertRecoveredCompletion($snapshot['migrations'], $migrationNames);
        } else {
            Phase07MigrationBatchPolicy::assertAuthorizedPrefix($snapshot['migrations'], $migrationNames);
        }

        $appliedNames = array_column($snapshot['migrations'], 'migration');
        $nextMigration = $migrationNames[count($appliedNames)] ?? null;
        if (! $isCompleteUnattestedSchema && ! in_array($nextMigration, [
            '2026_10_27_000001_create_course_delivery_progress',
            '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key',
        ], true)) {
            throw new RuntimeException('The Phase 07 interrupted run is not paused at a specifically reviewed progress migration.');
        }

        $expectedTables = Phase04MySqlTestDataPolicy::expectedCreatedTables($snapshot['migrations'], $migrationFiles);
        if (! $isCompleteUnattestedSchema && ! in_array('course_delivery_progress', $expectedTables, true)) {
            $expectedTables[] = 'course_delivery_progress';
        }
        $expectedTables = array_values(array_unique($expectedTables));
        sort($expectedTables);
        $actualTables = [];
        foreach ($snapshot['object_inventory']['tables_and_views'] as $object) {
            if (($object['type'] ?? null) !== 'BASE TABLE') {
                throw new RuntimeException('The Phase 07 interrupted schema contains an unexpected view or non-table object.');
            }
            $actualTables[] = (string) $object['name'];
        }
        sort($actualTables);
        if ($actualTables !== $expectedTables || ($snapshot['object_inventory']['auxiliary'] ?? []) !== []) {
            throw new RuntimeException('The Phase 07 interrupted schema objects do not match the applied migration prefix and the single expected partial table.');
        }

        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline(
            $snapshot['tables'],
            $inspection['row_counts'],
            $inspection['permission_rows'],
            $inspection['bootstrap_rows'],
            $snapshot['migrations'],
        );
        if ($isCompleteUnattestedSchema) {
            Phase07ProgressMigrationRecoveryPolicy::assertComplete(
                $inspection['phase07_progress_schema'] ?? [],
                (int) ($inspection['row_counts']['course_delivery_progress'] ?? -1),
                $inspection['phase07_progress_schema']['membership_indexes'] ?? [],
            );
        } else {
            Phase07ProgressMigrationRecoveryPolicy::assertRecoverable(
                $inspection['phase07_progress_schema'] ?? [],
                (int) ($inspection['row_counts']['course_delivery_progress'] ?? -1),
                $inspection['phase07_progress_schema']['membership_indexes'] ?? [],
            );
        }
        $this->assertNoOtherTargetSessions();

        $manifestPath = $this->manifestPath();
        if (file_exists($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Interrupted Phase 07 recovery requires the absent initial ownership manifest to remain absent.');
        }

        $this->phase07RecoveryPreflight = [
            'database' => $snapshot['database'],
            'username' => $snapshot['username'],
            'host' => $snapshot['host'],
            'server_version' => $snapshot['server_version'],
            'server_identity' => $snapshot['server_identity'],
            'schema_fingerprint_before_resume' => $snapshot['schema_fingerprint'],
            'applied_migrations' => $appliedNames,
            'applied_migration_rows' => $snapshot['migrations'],
            'next_migration' => $nextMigration,
            'resume_state' => $isCompleteUnattestedSchema ? 'complete_unattested_schema' : 'authorized_partial_prefix',
            'object_count_before_resume' => count($actualTables),
            'row_counts_before_resume' => $inspection['row_counts'],
            'progress_table_schema' => $inspection['phase07_progress_schema'],
            'ownership_manifest' => 'absent',
            'other_target_sessions' => 0,
        ];

        $this->resumingAuthorizedPartialTransition = true;

        return $snapshot;
    }

    /**
     * @param array{
     *   snapshot: array{tables:list<string>,migrations:list<array{migration:string,batch:int}>},
     *   row_counts: array<string,int>,
     *   permission_rows: list<array{key:string,scope:string}>,
     *   bootstrap_rows: list<array{id:int,completed:int,completed_at:?string}>
     * } $inspection
     */
    private function assertMigrationOwnedBaselineForInspection(array $inspection): void
    {
        $snapshot = $inspection['snapshot'];
        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline(
            $snapshot['tables'],
            $inspection['row_counts'],
            $inspection['permission_rows'],
            $inspection['bootstrap_rows'],
            $snapshot['migrations'],
        );
    }

    private function manifestPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.$this->target->manifestFilename;
    }

    private function isRegularLocalFile(string $path): bool
    {
        return is_file($path) && ! is_link($path);
    }

    private function phase06MigrationIntentPath(string $migrationName): string
    {
        if (preg_match('/\\A[0-9A-Za-z_]+\\z/D', $migrationName) !== 1) {
            throw new RuntimeException('The Phase 06 migration name is not a safe local identifier.');
        }

        return dirname($this->manifestPath()).DIRECTORY_SEPARATOR.'.phase06-migration-transition-'.$migrationName.'.json';
    }

    /** @param array<string,mixed> $snapshot */
    private function assertMigrationOwnedBaselineForSnapshot(array $snapshot): void
    {
        $tables = $snapshot['tables'];
        $rowCounts = $this->readTableRowCounts($tables);
        $permissionRows = in_array('permissions', $tables, true)
            ? $this->connection->query('SELECT `key`, scope FROM `permissions` ORDER BY `key`')->fetchAll()
            : [];
        $bootstrapRows = in_array('platform_bootstrap_states', $tables, true)
            ? array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'completed' => (int) $row['completed'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            ], $this->connection->query('SELECT id, completed, completed_at FROM `platform_bootstrap_states` ORDER BY id')->fetchAll())
            : [];

        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($tables, $rowCounts, $permissionRows, $bootstrapRows, $snapshot['migrations']);
    }

    private function assertNoOtherTargetSessions(): void
    {
        $connectionId = (int) $this->connection->query('SELECT CONNECTION_ID()')->fetchColumn();
        $others = array_values(array_filter(
            $this->targetSessionInventory(),
            static fn (array $session): bool => $session['id'] !== $connectionId,
        ));
        if ($others !== []) {
            throw new RuntimeException('The Phase 06 migration transition found another visible target session; no migration or ownership state was advanced.');
        }
    }

    /**
     * Allow recovery only when the migration completed under a recorded intent and every old object remains identical.
     * No schema is changed here; the caller may later perform the audited manifest transition.
     *
     * @param  array<string,mixed>  $stored
     * @param  array<string,mixed>  $current
     * @param  array<string,string>  $localMigrations
     */
    private function assertAuthorizedAppliedPhase06MigrationTransition(
        array $stored,
        string $manifestContents,
        array $current,
        array $localMigrations,
    ): string {
        if (($stored['database'] ?? null) !== Phase06MySqlTestTarget::DATABASE
            || ($stored['username'] ?? null) !== Phase06MySqlTestTarget::ACCOUNT
            || ($stored['host'] ?? null) !== '127.0.0.1'
            || ($stored['server_version'] ?? null) !== $current['server_version']
            || ($stored['server_identity'] ?? null) !== $current['server_identity']
            || ($stored['disposable_authorization'] ?? null) !== Phase06MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('The Phase 06 stored ownership evidence does not match this server and authorized disposable target.');
        }

        $localNames = array_keys($localMigrations);
        $migrationName = Phase06MigrationUpgradePolicy::assertCompletedExactlyOne(
            $stored['migrations'] ?? [], $current['migrations'], $localNames,
        );
        $expectedDifferences = ['tables', 'migrations', 'schema_fingerprint', 'object_inventory'];
        $manifestCurrent = $current;
        if (! array_key_exists('table_schema_fingerprints', $stored)) {
            unset($manifestCurrent['table_schema_fingerprints']);
        }
        if (Phase04OwnershipManifestPolicy::differences($stored, $manifestCurrent) !== $expectedDifferences) {
            throw new RuntimeException('The stale Phase 06 manifest differs in fields beyond the single authorized migration transition.');
        }
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $stored['object_inventory']['tables_and_views'] ?? [], $stored['migrations'], $localMigrations,
        );
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $current['object_inventory']['tables_and_views'], $current['migrations'], $localMigrations,
        );
        if (($stored['object_inventory']['auxiliary'] ?? null) !== []
            || ($current['object_inventory']['auxiliary'] ?? null) !== []) {
            throw new RuntimeException('The interrupted Phase 06 migration transition contains unowned auxiliary schema objects.');
        }
        $storedTables = array_map(static fn (array $object): string => (string) $object['name'], $stored['object_inventory']['tables_and_views'] ?? []);
        if (($stored['tables'] ?? null) !== $storedTables) {
            throw new RuntimeException('The original Phase 06 ownership manifest table inventory is inconsistent.');
        }

        $added = array_values(array_diff($current['tables'], $storedTables));
        $removed = array_values(array_diff($storedTables, $current['tables']));
        $expectedAdded = array_values(array_diff(
            Phase04MySqlTestDataPolicy::expectedCreatedTables([['migration' => $migrationName, 'batch' => 1]], $localMigrations),
            ['migrations'],
        ));
        sort($added);
        sort($removed);
        sort($expectedAdded);
        if ($removed !== [] || $added !== $expectedAdded) {
            throw new RuntimeException('The Phase 06 interrupted migration changed an unexpected schema object or an existing table definition.');
        }

        $intentPath = $this->phase06MigrationIntentPath($migrationName);
        $intent = is_link($intentPath) || ! is_file($intentPath) ? null : json_decode((string) file_get_contents($intentPath), true);
        if (! is_array($intent)
            || ! in_array($intent['schema_version'] ?? null, [1, 2], true)
            || ($intent['database'] ?? null) !== Phase06MySqlTestTarget::DATABASE
            || ($intent['username'] ?? null) !== Phase06MySqlTestTarget::ACCOUNT
            || ($intent['host'] ?? null) !== '127.0.0.1'
            || ($intent['server_version'] ?? null) !== $current['server_version']
            || ($intent['server_identity'] ?? null) !== $current['server_identity']
            || ($intent['disposable_authorization'] ?? null) !== Phase06MySqlTestTarget::AUTHORIZATION
            || ($intent['migration'] ?? null) !== $migrationName
            || ($intent['migration_sha256'] ?? null) !== hash_file('sha256', $localMigrations[$migrationName])
            || ($intent['schema_version'] === 2 && ($intent['previous_table_schema_fingerprints'] ?? null) !== ($stored['table_schema_fingerprints'] ?? null))
            || ($intent['previous_manifest_sha256'] ?? null) !== hash('sha256', $manifestContents)
            || ($intent['previous_schema_fingerprint'] ?? null) !== ($stored['schema_fingerprint'] ?? null)
            || ($intent['previous_migrations'] ?? null) !== ($stored['migrations'] ?? null)) {
            throw new RuntimeException('The Phase 06 ownership mismatch has no matching pre-migration intent record.');
        }

        $this->assertDeclaredPhase06SchemaExtensions($migrationName);
        if ($intent['schema_version'] === 2) {
            Phase06MigrationUpgradePolicy::assertOnlyDeclaredTableChanges(
                $migrationName,
                $storedTables,
                $intent['previous_table_schema_fingerprints'],
                $current['table_schema_fingerprints'] ?? [],
            );
        }

        $this->assertMigrationOwnedBaselineForSnapshot($current);
        $this->assertNoOtherTargetSessions();

        return $migrationName;
    }

    private function assertDeclaredPhase06SchemaExtensions(string $migrationName): void
    {
        foreach (Phase06MigrationUpgradePolicy::requiredSchemaExtensions($migrationName) as $table => $requirements) {
            foreach ($requirements['columns'] as $column) {
                $query = $this->connection->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column');
                $query->execute(['schema' => $this->target->database, 'table' => $table, 'column' => $column]);
                if ((int) $query->fetchColumn() !== 1) {
                    throw new RuntimeException('The Phase 06 migration is missing a reviewed column extension on '.$table.'.');
                }
            }
            foreach ($requirements['foreign_keys'] as $constraint => $expected) {
                $query = $this->connection->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint ORDER BY ORDINAL_POSITION');
                $query->execute(['schema' => $this->target->database, 'table' => $table, 'constraint' => $constraint]);
                $actual = $query->fetchAll();
                $actualColumns = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $actual);
                $actualReferencedColumns = array_map(static fn (array $row): string => (string) $row['REFERENCED_COLUMN_NAME'], $actual);
                if ($actualColumns !== $expected['columns']
                    || $actualReferencedColumns !== $expected['referenced_columns']
                    || count(array_unique(array_column($actual, 'REFERENCED_TABLE_NAME'))) !== 1
                    || (string) ($actual[0]['REFERENCED_TABLE_NAME'] ?? '') !== $expected['table']) {
                    throw new RuntimeException('The Phase 06 migration is missing or has altered a reviewed tenant-scoped foreign key.');
                }
            }
        }
    }

    /** @param list<string> $allowedTables
     * Recompute the catalog fingerprint for a previously attested subset of tables.
     */
    /** @param array<string, mixed> $snapshot */
    private function writeManifest(array $snapshot): void
    {
        $path = $this->manifestPath();
        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink for the Phase 04 database ownership manifest.');
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the local Phase 04 database ownership manifest.');
        }
    }

    private function preserveManifestForReconciliation(string $contents): void
    {
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.'.phase05-test-db-ownership.reconciled-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.json';
        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink for Phase 05 reconciliation evidence.');
        }
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Could not preserve the original Phase 05 ownership manifest before reconciliation.');
        }

        try {
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not completely preserve the original Phase 05 ownership manifest.');
            }
        } finally {
            fclose($handle);
        }

        if (file_get_contents($path) !== $contents) {
            throw new RuntimeException('The preserved Phase 05 reconciliation evidence failed its read-back check.');
        }
    }

    private function preservePhase06ManifestForReconciliation(string $contents): string
    {
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.'.phase06-test-db-ownership.pre-reconciliation-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $this->writeExclusiveLocalEvidence(basename($path), $contents);

        return $path;
    }

    private function writeExclusiveLocalEvidence(string $filename, string $contents): string
    {
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$filename;
        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink for Phase 06 ownership evidence.');
        }
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Could not preserve Phase 06 ownership evidence before reconciliation.');
        }

        try {
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not completely write Phase 06 ownership evidence.');
            }
        } finally {
            fclose($handle);
        }
        if (file_get_contents($path) !== $contents) {
            throw new RuntimeException('Phase 06 ownership evidence failed its read-back check.');
        }

        return $path;
    }
}
