<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase11ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $connection, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment);
        if (($environment['PHASE11_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true'
            || ($environment['PHASE11_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true'
            || ($environment['PHASE11_TEST_SCHEMA_AUTHORIZATION'] ?? '') !== Phase11MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('Phase 11 test process lacks guarded disposable-schema authorization.');
        }
        $identity = $connection->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 11 MySQL identity could not be verified.');
        }
        [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase11MySqlTestTarget::DATABASE, Phase11MySqlTestTarget::ACCOUNT);
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase11MySqlTestTarget::DATABASE);
        $lock = $connection->prepare('SELECT IS_USED_LOCK(?)');
        $lock->execute([Phase11MySqlTestTarget::SERVER_LOCK]);
        if ($lock->fetchColumn() === null) {
            throw new RuntimeException('Phase 11 worker requires the guarded runner to retain its MySQL advisory lock.');
        }
        $manifestPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.Phase11MySqlTestTarget::MANIFEST;
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Phase 11 ownership manifest is missing or unsafe.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $snapshot = Phase11OwnershipManifestPolicy::snapshot($connection);
        Phase11OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations']);
    }
}
