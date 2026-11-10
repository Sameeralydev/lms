<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase06MySqlGrantPolicy;
use Tests\Support\Phase06MySqlTestTarget;

class Phase06MySqlGrantPolicyTest extends TestCase
{
    #[DataProvider('validGrantFormats')]
    public function test_exact_schema_privileges_accept_supported_mysql_identifier_quoting(string $schemaGrant, string $usageGrant): void
    {
        Phase06MySqlGrantPolicy::assertAllowed([$usageGrant, $schemaGrant], Phase06MySqlTestTarget::ACCOUNT, '127.0.0.1');
        $this->assertCount(11, Phase06MySqlGrantPolicy::REQUIRED_PRIVILEGES);
    }

    /** @return iterable<string,array{string,string}> */
    public static function validGrantFormats(): iterable
    {
        $privileges = implode(', ', Phase06MySqlGrantPolicy::REQUIRED_PRIVILEGES);
        yield 'mysql 8 backtick format' => [
            'GRANT '.$privileges.' ON `'.Phase06MySqlTestTarget::DATABASE.'`.* TO `'.Phase06MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
            'GRANT USAGE ON *.* TO `'.Phase06MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
        ];
        yield 'single quoted format' => [
            'GRANT '.$privileges.' ON '.Phase06MySqlTestTarget::DATABASE.'.* TO \''.Phase06MySqlTestTarget::ACCOUNT.'\'@\'127.0.0.1\'',
            'GRANT USAGE ON *.* TO \''.Phase06MySqlTestTarget::ACCOUNT.'\'@\'127.0.0.1\'',
        ];
    }

    public function test_missing_test_privilege_is_rejected(): void
    {
        $privileges = array_values(array_diff(Phase06MySqlGrantPolicy::REQUIRED_PRIVILEGES, ['ALTER']));
        $this->expectException(RuntimeException::class);
        Phase06MySqlGrantPolicy::assertAllowed([
            'GRANT '.implode(', ', $privileges).' ON `'.Phase06MySqlTestTarget::DATABASE.'`.* TO `'.Phase06MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
        ], Phase06MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }

    public function test_unrelated_schema_or_global_privilege_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase06MySqlGrantPolicy::assertAllowed([
            'GRANT PROCESS ON *.* TO `'.Phase06MySqlTestTarget::ACCOUNT.'`@`127.0.0.1`',
        ], Phase06MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }

    public function test_grant_option_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase06MySqlGrantPolicy::assertAllowed([
            'GRANT '.implode(', ', Phase06MySqlGrantPolicy::REQUIRED_PRIVILEGES).' ON `'.Phase06MySqlTestTarget::DATABASE.'`.* TO `'.Phase06MySqlTestTarget::ACCOUNT.'`@`127.0.0.1` WITH GRANT OPTION',
        ], Phase06MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }

    public function test_wrong_schema_identity_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase06MySqlGrantPolicy::assertIdentity('techmentors_lms_phase05_test', Phase06MySqlTestTarget::ACCOUNT, '127.0.0.1');
    }
}
