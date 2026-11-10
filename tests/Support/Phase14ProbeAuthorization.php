<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase14ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $pdo, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment, allowPhase14Runner: true);
        $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('Phase 14 test connection identity could not be verified.');
        }

        [$username, $host] = array_pad(explode('@', trim((string) $identity['account'], "`'\""), 2), 2, '');
        $host = trim($host, "`'\"");
        Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Phase14MySqlTestTarget::DATABASE, Phase14MySqlTestTarget::ACCOUNT);
        if ($host !== '127.0.0.1') {
            throw new RuntimeException('Phase 14 MySQL tests require the exact 127.0.0.1 account, not a host fallback.');
        }
        if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
            throw new RuntimeException('Phase 14 integration tests require the dedicated local MySQL 8.4 instance.');
        }
        Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Phase14MySqlTestTarget::DATABASE);
    }
}
