<?php

namespace Tests\Support;

/** Phase-specific identity wrapper over the shared exact 11-privilege parser. */
final class Phase07MySqlGrantPolicy
{
    /** @var list<string> */
    public const REQUIRED_PRIVILEGES = [
        'ALTER', 'CREATE', 'CREATE TEMPORARY TABLES', 'DELETE', 'DROP', 'INDEX',
        'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'UPDATE',
    ];

    public static function assertIdentity(string $database, string $username, string $host): void
    {
        Phase04MySqlGrantPolicy::assertIdentity($database, $username, $host, Phase07MySqlTestTarget::DATABASE, Phase07MySqlTestTarget::ACCOUNT);
    }

    /** @param list<string> $grants */
    public static function assertAllowed(array $grants, string $username, string $host): void
    {
        Phase04MySqlGrantPolicy::assertAllowed($grants, $username, $host, Phase07MySqlTestTarget::DATABASE);
    }
}
