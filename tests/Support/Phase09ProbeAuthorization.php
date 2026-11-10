<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase09ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $connection, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment, allowPhase09Runner: true);
        if (($environment['PHASE09_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true' || ($environment['PHASE09_TEST_SCHEMA_AUTHORIZATION'] ?? '') !== Phase09MySqlTestTarget::AUTHORIZATION) {
            throw new RuntimeException('Phase 09 test process lacks explicit guarded-runner authorization.');
        }
        $identity = $connection->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 09 MySQL identity could not be verified.');
        }
        [$user, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $user, $host, Phase09MySqlTestTarget::DATABASE, Phase09MySqlTestTarget::ACCOUNT);
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $user, $host, Phase09MySqlTestTarget::DATABASE);
        $lock = $connection->prepare('SELECT IS_USED_LOCK(:name)');
        $lock->execute(['name' => Phase09MySqlTestTarget::SERVER_LOCK]);
        if ($lock->fetchColumn() === null) {
            throw new RuntimeException('Phase 09 worker requires the guarded runner to retain its MySQL advisory lock.');
        }
        $manifest = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.Phase09MySqlTestTarget::MANIFEST;
        if (is_link($manifest) || ! is_file($manifest)) {
            throw new RuntimeException('Phase 09 ownership manifest is unavailable or unsafe.');
        }
        $contents = json_decode((string) file_get_contents($manifest), true);
        $snapshot = Phase09OwnershipManifestPolicy::snapshot($connection);
        Phase09OwnershipManifestPolicy::assertOwned(is_array($contents) ? $contents : null, (string) $identity['uuid'], $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations']);
    }
}
