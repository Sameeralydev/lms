<?php

namespace Tests\Support;

use RuntimeException;

/** Exact schema-only privilege policy for the dedicated Phase 06 test account. */
final class Phase06MySqlGrantPolicy
{
    /** @var list<string> */
    public const REQUIRED_PRIVILEGES = [
        'ALTER', 'CREATE', 'CREATE TEMPORARY TABLES', 'DELETE', 'DROP', 'INDEX',
        'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'UPDATE',
    ];

    public static function assertIdentity(string $database, string $username, string $host): void
    {
        if ($database !== Phase06MySqlTestTarget::DATABASE || strcasecmp($username, Phase06MySqlTestTarget::ACCOUNT) !== 0 || ! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Authenticated MySQL identity must match the dedicated Phase 06 schema, account, and loopback host.');
        }
    }

    /** @param list<string> $grants */
    public static function assertAllowed(array $grants, string $username, string $host): void
    {
        $granted = [];
        $schemaGrantFound = false;
        foreach ($grants as $grant) {
            $grant = trim($grant);
            if (preg_match('/\AGRANT\s+USAGE\s+ON\s+\*\.\*\s+TO\s+(.+)\z/iD', $grant, $matches) === 1) {
                self::assertRecipient($matches[1], $username, $host);

                continue;
            }
            if (stripos($grant, 'WITH GRANT OPTION') !== false) {
                throw new RuntimeException('The Phase 06 test account has grant delegation beyond the test-only privilege scope.');
            }
            if (preg_match('/\AGRANT\s+(.+?)\s+ON\s+(?:`'.preg_quote(Phase06MySqlTestTarget::DATABASE, '/').'`|'.preg_quote(Phase06MySqlTestTarget::DATABASE, '/').')\.\*\s+TO\s+(.+)\z/iD', $grant, $matches) !== 1) {
                throw new RuntimeException('The Phase 06 test account has a grant outside its dedicated schema.');
            }
            self::assertRecipient($matches[2], $username, $host);
            $schemaGrantFound = true;
            $privileges = array_map(static fn (string $privilege): string => strtoupper(preg_replace('/\s+/', ' ', trim($privilege)) ?? trim($privilege)), explode(',', $matches[1]));
            if ($privileges === ['ALL PRIVILEGES']) {
                throw new RuntimeException('The Phase 06 test account has ALL PRIVILEGES, which is outside its documented test-only allowlist.');
            }
            $granted = [...$granted, ...$privileges];
        }
        if (! $schemaGrantFound) {
            throw new RuntimeException('The Phase 06 test account has no dedicated schema grant.');
        }
        $granted = array_values(array_unique($granted));
        $required = self::REQUIRED_PRIVILEGES;
        sort($required);
        sort($granted);
        $missing = array_values(array_diff($required, $granted));
        $extra = array_values(array_diff($granted, $required));
        if ($missing !== [] || $extra !== []) {
            $differences = [];
            if ($missing !== []) {
                $differences[] = 'missing: '.implode(', ', $missing);
            }
            if ($extra !== []) {
                $differences[] = 'extra: '.implode(', ', $extra);
            }
            throw new RuntimeException('The Phase 06 grant list differs from the exact 11-privilege schema-only allowlist ('.implode('; ', $differences).').');
        }
    }

    private static function assertRecipient(string $recipient, string $username, string $host): void
    {
        $quote = static fn (string $value): string => '(?:`'.preg_quote($value, '/').'`|\''.preg_quote($value, '/').'\'|'.preg_quote($value, '/').')';
        if (preg_match('/\A\s*'.$quote($username).'\s*@\s*'.$quote($host).'\s*\z/iD', $recipient) !== 1) {
            throw new RuntimeException('A Phase 06 grant does not match the authenticated account and host.');
        }
    }
}
