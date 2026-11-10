<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase08ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $connection, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment);
        if (($environment['PHASE08_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true'
            || ($environment['PHASE08_TEST_SCHEMA_AUTHORIZATION'] ?? '') !== Phase08MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('Phase 08 MySQL test process lacks the guarded preflight authorization.');
        }
        $identity = $connection->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 08 MySQL connection identity is unavailable.');
        }
        [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase08MySqlTestTarget::DATABASE, Phase08MySqlTestTarget::ACCOUNT);
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase08MySqlTestTarget::DATABASE);
        $manifestPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.Phase08MySqlTestTarget::MANIFEST;
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Phase 08 ownership manifest is absent or unsafe.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ($manifest['database'] ?? null) !== Phase08MySqlTestTarget::DATABASE
            || ($manifest['server_uuid'] ?? null) !== ($identity['uuid'] ?? null)
            || ($manifest['authorization'] ?? null) !== Phase08MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('Phase 08 ownership manifest does not authorize this exact MySQL target.');
        }
    }
}
