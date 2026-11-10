<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase04MySqlGrantPolicy;

final class Phase04MySqlGrantPolicyTest extends TestCase
{
    private const USER = 'techmentors_phase04_test';

    private const HOST = '127.0.0.1';

    private const DATABASE = 'techmentors_lms_phase04_test';

    /** @var list<string> */
    private const PRIVILEGES = [
        'ALTER', 'CREATE', 'CREATE TEMPORARY TABLES', 'DELETE', 'DROP', 'INDEX',
        'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'UPDATE',
    ];

    public function test_accepts_mysql_84_quoted_show_grants_output(): void
    {
        $this->expectNotToPerformAssertions();

        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT USAGE ON *.* TO `techmentors_phase04_test`@`127.0.0.1`',
            'GRANT ALTER, CREATE, CREATE TEMPORARY TABLES, DELETE, DROP, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, UPDATE ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`127.0.0.1`',
        ], self::USER, self::HOST);

    }

    public function test_accepts_only_the_v2_database_and_dedicated_v2_account(): void
    {
        Phase04MySqlGrantPolicy::assertIdentity(
            'techmentors_lms_phase04_v2_test',
            'techmentors_phase04_v2',
            self::HOST,
            'techmentors_lms_phase04_v2_test',
            'techmentors_phase04_v2',
        );
        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT USAGE ON *.* TO `techmentors_phase04_v2`@`127.0.0.1`',
            'GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase04_v2_test`.* TO `techmentors_phase04_v2`@`127.0.0.1`',
        ], 'techmentors_phase04_v2', self::HOST, 'techmentors_lms_phase04_v2_test');

        $this->assertRejected(static function (): void {
            Phase04MySqlGrantPolicy::assertIdentity(
                self::DATABASE,
                'techmentors_phase04_v2',
                self::HOST,
                'techmentors_lms_phase04_v2_test',
                'techmentors_phase04_v2',
            );
        });
        $this->assertRejected(static function (): void {
            Phase04MySqlGrantPolicy::assertAllowed([
                'GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_v2`@`127.0.0.1`',
            ], 'techmentors_phase04_v2', self::HOST, 'techmentors_lms_phase04_v2_test');
        });
    }

    public function test_phase05_accepts_only_its_dedicated_schema_and_exact_privilege_allowlist(): void
    {
        Phase04MySqlGrantPolicy::assertIdentity(
            'techmentors_lms_phase05_test',
            'techmentors_phase05_test',
            self::HOST,
            'techmentors_lms_phase05_test',
            'techmentors_phase05_test',
        );
        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT USAGE ON *.* TO `techmentors_phase05_test`@`127.0.0.1`',
            'GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase05_test`.* TO `techmentors_phase05_test`@`127.0.0.1`',
        ], 'techmentors_phase05_test', self::HOST, 'techmentors_lms_phase05_test');

        $this->assertRejected(static function (): void {
            Phase04MySqlGrantPolicy::assertAllowed([
                'GRANT ALL PRIVILEGES ON `techmentors_lms_phase05_test`.* TO `techmentors_phase05_test`@`127.0.0.1`',
            ], 'techmentors_phase05_test', self::HOST, 'techmentors_lms_phase05_test');
        });
    }

    public function test_accepts_split_schema_grants_with_bare_identifier_and_single_quoted_account(): void
    {
        $this->expectNotToPerformAssertions();

        Phase04MySqlGrantPolicy::assertAllowed([
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER ON techmentors_lms_phase04_test.* TO 'techmentors_phase04_test'@'127.0.0.1'",
            "GRANT DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON `techmentors_lms_phase04_test`.* TO 'techmentors_phase04_test'@'127.0.0.1'",
        ], self::USER, self::HOST);

    }

    public function test_accepts_the_authorized_localhost_account_alias(): void
    {
        $this->expectNotToPerformAssertions();

        Phase04MySqlGrantPolicy::assertIdentity(self::DATABASE, self::USER, 'localhost');
        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT USAGE ON *.* TO `techmentors_phase04_test`@`localhost`',
            'GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`localhost`',
        ], self::USER, 'localhost');

    }

    public function test_rejects_all_privileges_even_when_scoped_to_the_test_schema(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALL PRIVILEGES on its schema');

        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT USAGE ON *.* TO `techmentors_phase04_test`@`127.0.0.1`',
            'GRANT ALL PRIVILEGES ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`127.0.0.1`',
        ], self::USER, self::HOST);
    }

    public function test_rejects_extra_or_missing_schema_privileges(): void
    {
        try {
            Phase04MySqlGrantPolicy::assertAllowed([
                'GRANT '.implode(', ', [...self::PRIVILEGES, 'TRIGGER']).' ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`127.0.0.1`',
            ], self::USER, self::HOST);
            $this->fail('An extra schema privilege must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('extra: TRIGGER', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing: DROP');
        Phase04MySqlGrantPolicy::assertAllowed([
            'GRANT '.implode(', ', array_values(array_diff(self::PRIVILEGES, ['DROP']))).' ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`127.0.0.1`',
        ], self::USER, self::HOST);
    }

    public function test_rejects_global_other_schema_grant_option_and_wrong_account(): void
    {
        $invalidGrantSets = [
            ['GRANT SELECT ON *.* TO `techmentors_phase04_test`@`127.0.0.1`'],
            ['GRANT SELECT ON `another_schema`.* TO `techmentors_phase04_test`@`127.0.0.1`'],
            ['GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase04_test`.* TO `techmentors_phase04_test`@`127.0.0.1` WITH GRANT OPTION'],
            ['GRANT '.implode(', ', self::PRIVILEGES).' ON `techmentors_lms_phase04_test`.* TO `another_user`@`127.0.0.1`'],
        ];

        foreach ($invalidGrantSets as $grants) {
            $this->assertRejected(static function () use ($grants): void {
                Phase04MySqlGrantPolicy::assertAllowed($grants, self::USER, self::HOST);
            });
        }
    }

    public function test_rejects_non_test_database_user_and_non_loopback_identity(): void
    {
        foreach ([
            [self::DATABASE, self::USER, '192.0.2.10'],
            [self::DATABASE, 'root', self::HOST],
            ['techmentors_lms_dev', self::USER, self::HOST],
        ] as [$database, $username, $host]) {
            $this->assertRejected(static function () use ($database, $username, $host): void {
                Phase04MySqlGrantPolicy::assertIdentity($database, $username, $host);
            });
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());

            return;
        }

        $this->fail('The invalid Phase 04 grant or identity must be rejected.');
    }
}
