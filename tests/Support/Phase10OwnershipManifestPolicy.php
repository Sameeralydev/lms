<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase10OwnershipManifestPolicy
{
    /** @return array{objects:list<string>,definitions:array<string,string>,migrations:list<array{migration:string,batch:int}>,fingerprint:string} */
    public static function snapshot(PDO $pdo): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $query = $pdo->prepare('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');
        $query->execute([$database]);
        $objects = [];
        $definitions = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['TABLE_NAME'];
            $objects[] = $name;
            $quoted = '`'.str_replace('`', '``', $name).'`';
            $create = $pdo->query('SHOW CREATE '.($row['TABLE_TYPE'] === 'VIEW' ? 'VIEW ' : 'TABLE ').$quoted)->fetch(PDO::FETCH_NUM);
            $definition = preg_replace('/\sAUTO_INCREMENT=\d+/i', '', (string) ($create[1] ?? '')) ?? (string) ($create[1] ?? '');
            $definitions[$name] = hash('sha256', $definition);
        }
        $migrations = [];
        if (in_array('migrations', $objects, true)) {
            $migrations = array_map(static fn (array $row): array => ['migration' => (string) $row['migration'], 'batch' => (int) $row['batch']], $pdo->query('SELECT migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }

        return ['objects' => $objects, 'definitions' => $definitions, 'migrations' => $migrations, 'fingerprint' => hash('sha256', json_encode(['objects' => $objects, 'definitions' => $definitions, 'migrations' => $migrations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    /**
     * @param  array<string,mixed>|null  $manifest
     * @param  list<string>  $objects
     * @param  list<array{migration:string,batch:int}>  $migrations
     */
    public static function assertOwned(?array $manifest, string $serverUuid, string $fingerprint, array $objects, array $migrations): void
    {
        if (! is_array($manifest)
            || ($manifest['database'] ?? null) !== Phase10MySqlTestTarget::DATABASE
            || ($manifest['account'] ?? null) !== Phase10MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ($manifest['server_uuid'] ?? null) !== $serverUuid
            || ($manifest['authorization'] ?? null) !== Phase10MySqlTestTarget::AUTHORIZATION
            || ! hash_equals((string) ($manifest['fingerprint'] ?? ''), $fingerprint)
            || ($manifest['objects'] ?? null) !== $objects
            || ($manifest['migrations'] ?? null) !== $migrations) {
            throw new RuntimeException('Phase 10 database state differs from its locally attested ownership manifest.');
        }
    }

    /**
     * Verify a narrowly approved forward-only migration transition before re-attesting ownership.
     *
     * @param  array{objects:list<string>,definitions:array<string,string>,migrations:list<array{migration:string,batch:int}>,fingerprint:string}  $before
     * @param  array{objects:list<string>,definitions:array<string,string>,migrations:list<array{migration:string,batch:int}>,fingerprint:string}  $after
     * @param  list<string>  $expectedMigrations
     * @param  list<string>  $newObjects
     * @param  list<string>  $modifiedObjects
     */
    public static function assertForwardMigrationTransition(array $before, array $after, array $expectedMigrations, array $newObjects, array $modifiedObjects): void
    {
        $priorNames = array_column($before['migrations'], 'migration');
        if ($priorNames === [] || count($priorNames) >= count($expectedMigrations)
            || array_slice($expectedMigrations, 0, count($priorNames)) !== $priorNames
            || array_column($after['migrations'], 'migration') !== $expectedMigrations) {
            throw new RuntimeException('Phase 10 migration transition does not match the authorized forward-only migration prefix.');
        }

        $expectedObjects = array_values(array_unique([...$before['objects'], ...$newObjects]));
        sort($expectedObjects, SORT_STRING);
        if ($after['objects'] !== $expectedObjects || $after['fingerprint'] === $before['fingerprint']) {
            throw new RuntimeException('Phase 10 migration transition produced an unexpected schema-object inventory.');
        }

        $modified = array_fill_keys($modifiedObjects, true);
        foreach ($before['objects'] as $object) {
            if (! isset($modified[$object]) && ($before['definitions'][$object] ?? null) !== ($after['definitions'][$object] ?? null)) {
                throw new RuntimeException('Phase 10 migration changed an unapproved existing schema object.');
            }
            if (isset($modified[$object]) && ($before['definitions'][$object] ?? null) === ($after['definitions'][$object] ?? null)) {
                throw new RuntimeException('Phase 10 migration did not apply an expected schema-object change.');
            }
        }
    }
}
