<?php

namespace Tests\Support;

/** Allows resumption only from this V2 target's previously verified empty-schema manifest. */
final class Phase04V2OwnershipResumePolicy
{
    /** @param array<string,mixed> $stored
     * @param  array<string,mixed>  $current
     */
    public static function isOriginalEmptyManifest(array $stored, array $current, string $authorization): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', (string) ($stored['schema_fingerprint'] ?? '')) !== 1) {
            return false;
        }

        $emptyOrigin = [
            'database' => $current['database'] ?? null,
            'username' => $current['username'] ?? null,
            'host' => $current['host'] ?? null,
            'server_version' => $current['server_version'] ?? null,
            'tables' => [],
            'migrations' => [],
            'schema_fingerprint' => $stored['schema_fingerprint'],
            'server_identity' => $current['server_identity'] ?? null,
            'disposable_authorization' => $authorization,
            'object_inventory' => ['tables_and_views' => [], 'auxiliary' => []],
        ];

        return Phase04OwnershipManifestPolicy::differences($stored, $emptyOrigin) === [];
    }
}
