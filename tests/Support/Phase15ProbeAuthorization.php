<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase15ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $pdo, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment, allowPhase15Runner: true);
        $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version,@@server_uuid AS uuid,@@datadir AS data_directory')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 15 test connection identity could not be verified.');
        }
        [$username, $host] = array_pad(explode('@', trim((string) $identity['account'], "`'\""), 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase15MySqlTestTarget::DATABASE, Phase15MySqlTestTarget::ACCOUNT);
        if ($host !== '127.0.0.1' || (int) $identity['port'] !== (int) ($environment['TESTING_MYSQL_PHASE15_PORT'] ?? 0)
            || ! str_starts_with((string) $identity['version'], '8.4.')) {
            throw new RuntimeException('Phase 15 MySQL tests require the exact local MySQL 8.4 test account.');
        }
        Phase15ServerIdentityPolicy::assertExpected((string) $identity['uuid'], $environment['TESTING_MYSQL_PHASE15_SERVER_UUID'] ?? '');
        Phase15ServerIdentityPolicy::assertDataDirectory((string) $identity['data_directory'], $environment['TESTING_MYSQL_PHASE15_DATA_DIRECTORY'] ?? '');
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase15MySqlTestTarget::DATABASE);
    }
}
