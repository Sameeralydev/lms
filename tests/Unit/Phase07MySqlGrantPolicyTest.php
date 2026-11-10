<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase07MySqlGrantPolicy;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\TestDatabaseGuard;

final class Phase07MySqlGrantPolicyTest extends TestCase
{
    #[DataProvider('validGrantFormats')]
    public function test_exact_schema_privileges_accept_mysql_identifier_quoting(string $schemaGrant, string $usageGrant): void
    {
        Phase07MySqlGrantPolicy::assertAllowed([$usageGrant, $schemaGrant], Phase07MySqlTestTarget::ACCOUNT, '127.0.0.1');
        $this->assertCount(11, Phase07MySqlGrantPolicy::REQUIRED_PRIVILEGES);
    }

    /** @return iterable<string,array{string,string}> */
    public static function validGrantFormats(): iterable
    {
        $privileges = implode(', ', Phase07MySqlGrantPolicy::REQUIRED_PRIVILEGES);
        yield 'backtick quoted MySQL grant' => [
            'GRANT '.$privileges.' ON `'.Phase07MySqlTestTarget::DATABASE.'`.* TO `'.Phase07MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
            'GRANT USAGE ON *.* TO `'.Phase07MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
        ];
        yield 'single quoted MySQL grant' => [
            'GRANT '.$privileges.' ON '.Phase07MySqlTestTarget::DATABASE.'.* TO \''.Phase07MySqlTestTarget::ACCOUNT.'\'@\'127.0.0.1\'',
            'GRANT USAGE ON *.* TO \''.Phase07MySqlTestTarget::ACCOUNT.'\'@\'127.0.0.1\'',
        ];
    }

    public function test_global_privileges_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase07MySqlGrantPolicy::assertAllowed([
            'GRANT PROCESS ON *.* TO `'.Phase07MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
        ], Phase07MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }

    public function test_grant_delegation_and_wrong_database_identity_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase07MySqlGrantPolicy::assertAllowed([
            'GRANT '.implode(', ', Phase07MySqlGrantPolicy::REQUIRED_PRIVILEGES).' ON `'.Phase07MySqlTestTarget::DATABASE.'`.* TO `'.Phase07MySqlTestTarget::ACCOUNT.'`@`127.0.0.1` WITH GRANT OPTION',
        ], Phase07MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }

    public function test_database_guard_only_accepts_the_phase07_disposable_identity(): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => Phase07MySqlTestTarget::DATABASE,
            'DB_USERNAME' => Phase07MySqlTestTarget::ACCOUNT,
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_SOCKET' => '',
            'PHASE07_TEST_SCHEMA_DISPOSABLE' => 'true',
        ];
        TestDatabaseGuard::assertSafe($environment, allowPhase07Runner: true);

        $environment['DB_DATABASE'] = 'techmentors_lms_dev';
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe($environment, allowPhase07Runner: true);
    }
}
