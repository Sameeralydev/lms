<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use RuntimeException;
use Tests\Support\Phase13MySqlTestTarget;
use Tests\Support\Phase13OwnershipManifestPolicy;
use Tests\Support\Phase13SchemaIdentifierPolicy;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

final class Phase13GuardPolicyTest extends TestCase
{
    public function test_example_environment_matches_the_phase13_target_without_credentials(): void
    {
        $environment = Dotenv::parse((string) file_get_contents(base_path('.env.phase13.testing.example')));
        self::assertSame('testing', $environment['APP_ENV']);
        self::assertSame(Phase13MySqlTestTarget::DATABASE, $environment['DB_DATABASE']);
        self::assertSame(Phase13MySqlTestTarget::ACCOUNT, $environment['DB_USERNAME']);
        self::assertSame('replace-locally', $environment['DB_PASSWORD']);
        self::assertSame('true', $environment['PHASE13_TEST_SCHEMA_DISPOSABLE']);
        self::assertSame(Phase13MySqlTestTarget::AUTHORIZATION, $environment['PHASE13_TEST_SCHEMA_AUTHORIZATION']);
    }

    public function test_phase13_test_database_requires_runner_authorization_and_exact_target(): void
    {
        $environment = $this->environment();
        TestDatabaseGuard::assertSafe($environment, allowPhase13Runner: true);
        try {
            TestDatabaseGuard::assertSafe($environment);
            self::fail('Direct MySQL test execution must be rejected without the guarded runner marker.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Phase 13 MySQL tests require', $exception->getMessage());
        }
    }

    public function test_phase13_guard_rejects_wrong_schema_and_account(): void
    {
        $wrongSchema = $this->environment();
        $wrongSchema['DB_DATABASE'] = 'techmentors_lms_dev';
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe($wrongSchema, allowPhase13Runner: true);
    }

    public function test_phase13_guard_rejects_wrong_account_even_with_disposable_marker(): void
    {
        $environment = $this->environment();
        $environment['DB_USERNAME'] = 'root';
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe($environment, allowPhase13Runner: true);
    }

    public function test_phase13_schema_identifiers_fit_mysql_limits(): void
    {
        self::assertNotEmpty(Phase13SchemaIdentifierPolicy::IDENTIFIERS);
        self::assertLessThanOrEqual(64, max(array_map('strlen', Phase13SchemaIdentifierPolicy::IDENTIFIERS)));
    }

    public function test_reviewed_migration_cutoff_includes_exam_cycle_source_lineage(): void
    {
        $migrationDirectory = base_path('database/migrations');
        $included = array_values(array_filter(
            glob($migrationDirectory.DIRECTORY_SEPARATOR.'*.php') ?: [],
            static fn (string $file): bool => basename($file, '.php') <= Phase13MySqlTestTarget::MIGRATION_CUTOFF,
        ));
        sort($included, SORT_STRING);

        self::assertNotEmpty($included);
        self::assertSame(Phase13MySqlTestTarget::MIGRATION_CUTOFF, basename((string) end($included), '.php'));
        self::assertFileExists($migrationDirectory.DIRECTORY_SEPARATOR.Phase13MySqlTestTarget::MIGRATION_CUTOFF.'.php');
    }

    public function test_phase13_manifest_policy_rejects_fingerprint_or_inventory_tampering(): void
    {
        $manifest = ['database' => Phase13MySqlTestTarget::DATABASE, 'account' => Phase13MySqlTestTarget::ACCOUNT.'@127.0.0.1', 'server_uuid' => 'server-a', 'authorization' => Phase13MySqlTestTarget::AUTHORIZATION, 'fingerprint' => 'fingerprint-a', 'objects' => ['migrations'], 'migrations' => [['migration' => 'one', 'batch' => 1]]];
        Phase13OwnershipManifestPolicy::assertOwned($manifest, 'server-a', 'fingerprint-a', ['migrations'], [['migration' => 'one', 'batch' => 1]]);
        $manifest['fingerprint'] = 'tampered';
        $this->expectException(RuntimeException::class);
        Phase13OwnershipManifestPolicy::assertOwned($manifest, 'server-a', 'fingerprint-a', ['migrations'], [['migration' => 'one', 'batch' => 1]]);
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => Phase13MySqlTestTarget::DATABASE,
            'DB_USERNAME' => Phase13MySqlTestTarget::ACCOUNT, 'DB_PASSWORD' => 'not-logged', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_SOCKET' => '', 'DB_URL' => '', 'PHASE13_TEST_SCHEMA_DISPOSABLE' => 'true',
            'PHASE13_TEST_SCHEMA_AUTHORIZATION' => Phase13MySqlTestTarget::AUTHORIZATION,
        ];
    }
}
