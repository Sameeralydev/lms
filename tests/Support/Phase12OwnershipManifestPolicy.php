<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class Phase12OwnershipManifestPolicy
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

        return [
            'objects' => $objects,
            'definitions' => $definitions,
            'migrations' => $migrations,
            'fingerprint' => hash('sha256', json_encode(['objects' => $objects, 'definitions' => $definitions, 'migrations' => $migrations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  list<string>  $objects
     * @param  list<array{migration:string,batch:int}>  $migrations
     */
    public static function assertOwned(?array $manifest, string $serverUuid, string $fingerprint, array $objects, array $migrations): void
    {
        if (! is_array($manifest)
            || ($manifest['database'] ?? null) !== Phase12MySqlTestTarget::DATABASE
            || ($manifest['account'] ?? null) !== Phase12MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ($manifest['server_uuid'] ?? null) !== $serverUuid
            || ($manifest['authorization'] ?? null) !== Phase12MySqlTestTarget::AUTHORIZATION
            || ! hash_equals((string) ($manifest['fingerprint'] ?? ''), $fingerprint)
            || ($manifest['objects'] ?? null) !== $objects
            || ($manifest['migrations'] ?? null) !== $migrations) {
            throw new RuntimeException('Phase 12 schema differs from its local ownership manifest.');
        }
    }
}
