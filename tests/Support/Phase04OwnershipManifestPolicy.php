<?php

namespace Tests\Support;

/** Compares only non-secret ownership evidence; this policy never authorizes a mismatch. */
final class Phase04OwnershipManifestPolicy
{
    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'database', 'username', 'host', 'server_version', 'tables', 'migrations', 'schema_fingerprint',
    ];

    /** @var list<string> */
    private const OPTIONAL_FIELDS = ['server_identity', 'object_inventory', 'disposable_authorization', 'table_schema_fingerprints'];

    /** @param array<string, mixed> $stored
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public static function differences(array $stored, array $current): array
    {
        $differences = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $stored) || ! array_key_exists($field, $current) || $stored[$field] !== $current[$field]) {
                $differences[] = $field;
            }
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $current) && (! array_key_exists($field, $stored) || $stored[$field] !== $current[$field])) {
                $differences[] = $field;
            }
            if (array_key_exists($field, $stored) && ! array_key_exists($field, $current)) {
                $differences[] = $field;
            }
        }

        foreach (array_unique(array_merge(array_keys($stored), array_keys($current))) as $field) {
            if (! in_array($field, [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS], true)) {
                $differences[] = (string) $field;
            }
        }

        return $differences;
    }
}
