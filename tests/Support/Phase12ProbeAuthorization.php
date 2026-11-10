<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase12ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $pdo, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment, allowPhase12Runner: true);
        $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 12 test connection identity could not be verified.');
        }
        [$username, $host] = array_pad(explode('@', trim((string) $identity['account'], "`'\""), 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase12MySqlTestTarget::DATABASE, Phase12MySqlTestTarget::ACCOUNT);
        if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
            throw new RuntimeException('Phase 12 integration tests require the dedicated local MySQL 8.4 instance.');
        }
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase12MySqlTestTarget::DATABASE);
    }
}
