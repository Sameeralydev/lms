<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/** Binds Phase 06 concurrency probes to the guarded runner, exact server, grants, and owned schema. */
final class Phase06ProbeAuthorization
{
    /** @param array<string,string> $environment */
    public static function assertAuthorized(PDO $connection, array $environment): void
    {
        TestDatabaseGuard::assertSafe($environment);
        if (($environment['PHASE06_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true'
            || ($environment['PHASE06_MYSQL_PREFLIGHT_PASSED'] ?? '') !== 'true'
            || ($environment['DB_CONNECTION'] ?? '') !== 'mysql'
            || ($environment['DB_URL'] ?? '') !== ''
            || ($environment['DB_HOST'] ?? '') !== '127.0.0.1'
            || ($environment['DB_PORT'] ?? '') !== '3306'
            || ($environment['DB_SOCKET'] ?? '') !== '') {
            throw new RuntimeException('Phase 06 concurrency probes require explicit authorization from the guarded runner.');
        }

        $identity = $connection->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, VERSION() AS server_version, @@server_uuid AS server_uuid, @@hostname AS server_hostname, @@port AS server_port')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($identity)) {
            throw new RuntimeException('The Phase 06 probe could not verify its authenticated database identity.');
        }
        [$username, $host] = array_pad(explode('@', (string) $identity['account_name'], 2), 2, '');
        Phase06MySqlGrantPolicy::assertIdentity((string) $identity['database_name'], trim($username, "`'\""), trim($host, "`'\""));
        if (strcasecmp(trim($username, "`'\""), (string) ($environment['DB_USERNAME'] ?? '')) !== 0
            || ! str_starts_with((string) $identity['server_version'], '8.4.')
            || (int) $identity['server_port'] !== 3306) {
            throw new RuntimeException('The Phase 06 probe server or authenticated account does not match the guarded configuration.');
        }

        $grants = array_map('strval', $connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        Phase06MySqlGrantPolicy::assertAllowed($grants, trim($username, "`'\""), trim($host, "`'\""));

        $manifestPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.Phase06MySqlTestTarget::MANIFEST;
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('The Phase 06 probe requires a regular current ownership manifest.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)
            || ($manifest['database'] ?? null) !== Phase06MySqlTestTarget::DATABASE
            || ($manifest['username'] ?? null) !== Phase06MySqlTestTarget::ACCOUNT
            || ($manifest['host'] ?? null) !== '127.0.0.1'
            || ($manifest['disposable_authorization'] ?? null) !== Phase06MySqlTestTarget::AUTHORIZATION
            || ($manifest['server_version'] ?? null) !== (string) $identity['server_version']
            || ($manifest['server_identity'] ?? null) !== [
                'uuid' => (string) $identity['server_uuid'],
                'hostname' => (string) $identity['server_hostname'],
                'port' => (int) $identity['server_port'],
            ]
            || ! is_array($manifest['tables'] ?? null)
            || ! is_array($manifest['migrations'] ?? null)
            || ($manifest['object_inventory']['auxiliary'] ?? null) !== []) {
            throw new RuntimeException('The Phase 06 probe ownership evidence does not match the authenticated server identity.');
        }

        $schemaName = Phase06MySqlTestTarget::DATABASE;
        $objectQuery = $connection->prepare("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE IN ('BASE TABLE', 'VIEW') ORDER BY TABLE_NAME");
        $objectQuery->execute(['schema' => $schemaName]);
        $objects = $objectQuery->fetchAll(PDO::FETCH_ASSOC);
        $schema = [$objects];
        $schemaQueries = [
            "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION,EXTRA,COLUMN_KEY,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$schemaName' ORDER BY TABLE_NAME,ORDINAL_POSITION",
            "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$schemaName' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
            "SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$schemaName' ORDER BY TABLE_NAME,CONSTRAINT_NAME",
            "SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = '$schemaName' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
            "SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$schemaName' ORDER BY TRIGGER_NAME",
            "SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '$schemaName' ORDER BY TABLE_NAME",
            "SELECT ROUTINE_NAME,ROUTINE_TYPE,DEFINER,SECURITY_TYPE,SQL_DATA_ACCESS,IS_DETERMINISTIC FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$schemaName' ORDER BY ROUTINE_NAME",
            "SELECT EVENT_NAME,DEFINER,STATUS,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '$schemaName' ORDER BY EVENT_NAME",
        ];
        foreach ($schemaQueries as $schemaQuery) {
            $schema[] = $connection->query($schemaQuery)->fetchAll(PDO::FETCH_ASSOC);
        }
        if (! hash_equals((string) ($manifest['schema_fingerprint'] ?? ''), hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('The Phase 06 probe schema fingerprint differs from its current ownership manifest.');
        }
        $actualTables = array_map(static fn (array $object): string => (string) $object['TABLE_NAME'], $objects);
        $actualInventory = [
            'tables_and_views' => array_map(static fn (array $object): array => [
                'name' => (string) $object['TABLE_NAME'], 'type' => (string) $object['TABLE_TYPE'],
            ], $objects),
            'auxiliary' => array_merge(
                array_map(static fn (array $row): string => 'trigger:'.(string) $row['TRIGGER_NAME'], $schema[5]),
                array_map(static fn (array $row): string => 'routine:'.(string) $row['ROUTINE_NAME'], $schema[7]),
                array_map(static fn (array $row): string => 'event:'.(string) $row['EVENT_NAME'], $schema[8]),
            ),
        ];
        if ($actualTables !== $manifest['tables'] || $actualInventory !== ($manifest['object_inventory'] ?? null)) {
            throw new RuntimeException('The Phase 06 probe schema inventory differs from its current ownership manifest.');
        }

        $migrations = array_map(static fn (array $row): array => [
            'migration' => (string) $row['migration'],
            'batch' => (int) $row['batch'],
        ], $connection->query('SELECT migration, batch FROM `migrations` ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC));
        if ($migrations !== $manifest['migrations']) {
            throw new RuntimeException('The Phase 06 probe migration ledger differs from its current ownership manifest.');
        }

        $lock = $connection->prepare('SELECT IS_USED_LOCK(:name)');
        $lock->execute(['name' => Phase06MySqlTestTarget::SERVER_LOCK]);
        $owner = $lock->fetchColumn();
        $connectionId = (int) $connection->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ($owner === false || $owner === null || (int) $owner === $connectionId) {
            throw new RuntimeException('The Phase 06 probe is not attached to an active exclusive guarded MySQL run.');
        }
    }
}
