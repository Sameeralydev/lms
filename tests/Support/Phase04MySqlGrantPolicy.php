<?php

namespace Tests\Support;

use RuntimeException;

/** Validates the exact loopback identity and schema-only grants used by Phase 04 migrations. */
final class Phase04MySqlGrantPolicy
{
    public const DATABASE = 'techmentors_lms_phase04_test';

    public const ACCOUNT = 'techmentors_phase04_test';

    private const REQUIRED_PRIVILEGES = [
        'ALTER', 'CREATE', 'CREATE TEMPORARY TABLES', 'DELETE', 'DROP', 'INDEX',
        'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'UPDATE',
    ];

    public static function assertIdentity(
        string $database,
        string $username,
        string $host,
        string $expectedDatabase = self::DATABASE,
        string $expectedAccount = self::ACCOUNT,
    ): void {
        if (
            $database !== $expectedDatabase
            || strcasecmp($username, $expectedAccount) !== 0
            || ! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)
        ) {
            throw new RuntimeException('Authenticated MySQL identity does not match the selected dedicated Phase 04 test schema/account/loopback host.');
        }
    }

    /** @param list<string> $grants */
    public static function assertAllowed(
        array $grants,
        string $username,
        string $host,
        string $database = self::DATABASE,
    ): void {
        $required = self::REQUIRED_PRIVILEGES;
        sort($required);
        $granted = [];
        $schemaGrantFound = false;

        foreach ($grants as $grant) {
            $grant = trim($grant);

            if (preg_match('/\AGRANT\s+USAGE\s+ON\s+\*\.\*\s+TO\s+(.+)\z/iD', $grant, $matches) === 1) {
                self::assertRecipient($matches[1], $username, $host);

                continue;
            }

            if (stripos($grant, 'WITH GRANT OPTION') !== false) {
                throw new RuntimeException('The Phase 04 test account has grant delegation, which is outside the documented test-only scope.');
            }

            if (preg_match(
                '/\AGRANT\s+(.+?)\s+ON\s+(?:`'.preg_quote($database, '/').'`|'.preg_quote($database, '/').')\.\*\s+TO\s+(.+)\z/iD',
                $grant,
                $matches,
            ) !== 1) {
                throw new RuntimeException('The Phase 04 test account has a grant outside its dedicated schema.');
            }

            self::assertRecipient($matches[2], $username, $host);
            $schemaGrantFound = true;

            $privileges = array_map(
                static fn (string $privilege): string => strtoupper(preg_replace('/\s+/', ' ', trim($privilege)) ?? trim($privilege)),
                explode(',', $matches[1]),
            );

            if ($privileges === ['ALL PRIVILEGES']) {
                throw new RuntimeException('The Phase 04 test account has ALL PRIVILEGES on its schema, which exceeds the documented test-only allowlist.');
            }

            foreach ($privileges as $privilege) {
                $granted[] = $privilege;
            }
        }

        if (! $schemaGrantFound) {
            throw new RuntimeException('The Phase 04 test account has no schema-scoped migration/test grant.');
        }

        $granted = array_values(array_unique($granted));
        sort($granted);
        $missing = array_values(array_diff($required, $granted));
        $extra = array_values(array_diff($granted, $required));

        if ($missing !== [] || $extra !== []) {
            $details = [];
            if ($missing !== []) {
                $details[] = 'missing: '.implode(', ', $missing);
            }
            if ($extra !== []) {
                $details[] = 'extra: '.implode(', ', $extra);
            }

            throw new RuntimeException('The Phase 04 test account privileges do not match the documented test-only scope ('.implode('; ', $details).').');
        }
    }

    private static function assertRecipient(string $recipient, string $username, string $host): void
    {
        $identifier = static fn (string $value): string => '(?:`'.preg_quote($value, '/').'`|\''.preg_quote($value, '/').'\'|'.preg_quote($value, '/').')';
        $pattern = '/\A\s*'.$identifier($username).'\s*@\s*'.$identifier($host).'\s*\z/iD';

        if (preg_match($pattern, $recipient) !== 1) {
            throw new RuntimeException('A Phase 04 grant does not target the authenticated test account.');
        }
    }
}
