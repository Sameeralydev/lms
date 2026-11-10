<?php

namespace Tests\Support;

use RuntimeException;

/** Refuses ownership checks when two read-only schema observations differ. */
final class Phase04SchemaSnapshotPolicy
{
    /** @param array<string, mixed> $first
     * @param  array<string, mixed>  $second
     */
    public static function assertStable(array $first, array $second): void
    {
        if ($first !== $second) {
            throw new RuntimeException('The Phase 04 test schema changed during read-only preflight; no database changes were made.');
        }
    }
}
