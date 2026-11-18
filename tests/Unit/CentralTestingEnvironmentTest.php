<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CentralTestingEnvironment;
use Tests\Support\Phase15MySqlTestTarget;

final class CentralTestingEnvironmentTest extends TestCase
{
    public function test_testing_profiles_refuse_stale_laravel_configuration_cache(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'central-profile-'.bin2hex(random_bytes(8));
        $cache = $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        mkdir($cache, 0700, true);
        $compiledConfig = $cache.DIRECTORY_SEPARATOR.'config.php';
        file_put_contents($compiledConfig, '<?php return [];');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('configuration cache must be absent');
            CentralTestingEnvironment::assertNoCachedConfiguration($root);
        } finally {
            unlink($compiledConfig);
            rmdir($cache);
            rmdir(dirname($cache));
            rmdir($root);
        }
    }

    public function test_sqlite_profile_overrides_phpunit_ci_and_environment_database_values(): void
    {
        $environment = CentralTestingEnvironment::sqlite(
            dirname(__DIR__, 2),
            ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'techmentors_lms_dev', 'ADMISSIONS_DOCUMENT_SCANNER' => 'clamav'],
            ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'alamgirian_dev', 'ADMISSIONS_DOCUMENT_SCANNER' => 'unavailable'],
        );

        self::assertSame('sqlite', $environment['DB_CONNECTION']);
        self::assertSame(':memory:', $environment['DB_DATABASE']);
        self::assertSame('', $environment['DB_URL']);
        self::assertSame('', $environment['DB_PASSWORD']);
        self::assertSame('unavailable', $environment['ADMISSIONS_DOCUMENT_SCANNER']);
        self::assertSame('testing', $environment['APP_ENV']);
        self::assertSame('array', $environment['CACHE_STORE']);
        self::assertSame('sync', $environment['QUEUE_CONNECTION']);
        self::assertSame('array', $environment['SESSION_DRIVER']);
    }

    public function test_mysql_profile_pins_phase_database_account_and_erases_url_override(): void
    {
        $environment = CentralTestingEnvironment::mysqlPhase(
            dirname(__DIR__, 2),
            '15',
            Phase15MySqlTestTarget::DATABASE,
            Phase15MySqlTestTarget::ACCOUNT,
            Phase15MySqlTestTarget::AUTHORIZATION,
            [
                'TESTING_MYSQL_PHASE15_USERNAME' => Phase15MySqlTestTarget::ACCOUNT,
                'TESTING_MYSQL_PHASE15_PASSWORD' => 'unit-only-secret',
                'TESTING_MYSQL_PHASE15_DISPOSABLE' => 'true',
                'TESTING_MYSQL_PHASE15_AUTHORIZATION' => Phase15MySqlTestTarget::AUTHORIZATION,
            ],
            ['DB_DATABASE' => 'techmentors_lms_dev', 'DB_USERNAME' => 'root', 'DB_URL' => 'mysql://wrong-target'],
        );

        self::assertSame('mysql', $environment['DB_CONNECTION']);
        self::assertSame(Phase15MySqlTestTarget::DATABASE, $environment['DB_DATABASE']);
        self::assertSame(Phase15MySqlTestTarget::ACCOUNT, $environment['DB_USERNAME']);
        self::assertSame('unit-only-secret', $environment['DB_PASSWORD']);
        self::assertSame('', $environment['DB_URL']);
        self::assertSame('true', $environment['PHASE15_TEST_SCHEMA_DISPOSABLE']);
    }

    public function test_mysql_profile_fails_closed_without_central_phase_authorization(): void
    {
        $this->expectException(RuntimeException::class);

        CentralTestingEnvironment::mysqlPhase(
            dirname(__DIR__, 2),
            '15',
            Phase15MySqlTestTarget::DATABASE,
            Phase15MySqlTestTarget::ACCOUNT,
            Phase15MySqlTestTarget::AUTHORIZATION,
            [],
            [],
        );
    }

    public function test_mysql_profile_rejects_nonlocal_host_and_wrong_pinned_user(): void
    {
        $profile = [
            'TESTING_MYSQL_HOST' => '192.0.2.44',
            'TESTING_MYSQL_PORT' => '3306',
            'TESTING_MYSQL_PHASE15_USERNAME' => Phase15MySqlTestTarget::ACCOUNT,
            'TESTING_MYSQL_PHASE15_PASSWORD' => 'unit-only-secret',
            'TESTING_MYSQL_PHASE15_DISPOSABLE' => 'true',
            'TESTING_MYSQL_PHASE15_AUTHORIZATION' => Phase15MySqlTestTarget::AUTHORIZATION,
        ];

        try {
            $rejected = false;
            CentralTestingEnvironment::mysqlPhase(
                dirname(__DIR__, 2), '15', Phase15MySqlTestTarget::DATABASE,
                Phase15MySqlTestTarget::ACCOUNT, Phase15MySqlTestTarget::AUTHORIZATION,
                $profile, [], requireDisposable: true,
            );
            self::fail('A nonlocal MySQL endpoint must never be accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('local MySQL endpoint', $exception->getMessage());
        }

        $profile['TESTING_MYSQL_HOST'] = '127.0.0.1';
        $profile['TESTING_MYSQL_PHASE15_USERNAME'] = 'root';
        $this->expectException(RuntimeException::class);
        CentralTestingEnvironment::mysqlPhase(
            dirname(__DIR__, 2), '15', Phase15MySqlTestTarget::DATABASE,
            Phase15MySqlTestTarget::ACCOUNT, Phase15MySqlTestTarget::AUTHORIZATION,
            $profile, [], requireDisposable: true,
        );
    }

    public function test_alternate_mysql_port_is_available_only_for_owner_pinned_phase15_profile(): void
    {
        $profile = [
            'TESTING_MYSQL_HOST' => '127.0.0.1',
            'TESTING_MYSQL_PORT' => '3306',
            'TESTING_MYSQL_PHASE15_PORT' => '34321',
            'TESTING_MYSQL_PHASE15_USERNAME' => Phase15MySqlTestTarget::ACCOUNT,
            'TESTING_MYSQL_PHASE15_PASSWORD' => 'unit-only-secret',
            'TESTING_MYSQL_PHASE15_DISPOSABLE' => 'true',
            'TESTING_MYSQL_PHASE15_AUTHORIZATION' => Phase15MySqlTestTarget::AUTHORIZATION,
        ];

        $phase15 = CentralTestingEnvironment::mysqlPhase(
            dirname(__DIR__, 2), '15', Phase15MySqlTestTarget::DATABASE,
            Phase15MySqlTestTarget::ACCOUNT, Phase15MySqlTestTarget::AUTHORIZATION,
            $profile, [], requireDisposable: true, allowAlternateLoopbackPort: true,
        );
        self::assertSame('34321', $phase15['DB_PORT']);
        self::assertSame('34321', $phase15['TESTING_MYSQL_PHASE15_PORT']);

        $default = CentralTestingEnvironment::mysqlPhase(
            dirname(__DIR__, 2), '15', Phase15MySqlTestTarget::DATABASE,
            Phase15MySqlTestTarget::ACCOUNT, Phase15MySqlTestTarget::AUTHORIZATION,
            $profile, [], requireDisposable: true,
        );
        self::assertSame('3306', $default['DB_PORT'], 'The Phase 15 runner must explicitly opt into its alternate local port.');
    }
}
