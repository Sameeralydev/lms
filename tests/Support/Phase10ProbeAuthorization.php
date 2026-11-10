<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase10ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $connection, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment);
        if (($environment['PHASE10_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true'
            || ($environment['PHASE10_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true'
            || ($environment['PHASE10_TEST_SCHEMA_AUTHORIZATION'] ?? '') !== Phase10MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('Phase 10 test process lacks guarded disposable-schema authorization.');
        }
        $identity = $connection->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 10 MySQL identity could not be verified.');
        }
        [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase10MySqlTestTarget::DATABASE, Phase10MySqlTestTarget::ACCOUNT);
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase10MySqlTestTarget::DATABASE);
        $lock = $connection->prepare('SELECT IS_USED_LOCK(?)');
        $lock->execute([Phase10MySqlTestTarget::SERVER_LOCK]);
        if ($lock->fetchColumn() === null) {
            throw new RuntimeException('Phase 10 worker requires the guarded runner to retain its MySQL advisory lock.');
        }
        $manifestPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.Phase10MySqlTestTarget::MANIFEST;
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Phase 10 ownership manifest is missing or unsafe.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $snapshot = Phase10OwnershipManifestPolicy::snapshot($connection);
        Phase10OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations']);
    }
}
