<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class Phase15ServerIdentityPolicy
{
    public static function assertExpected(string $actualUuid, string $expectedUuid): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $expectedUuid) !== 1
            || ! hash_equals(strtolower($expectedUuid), strtolower($actualUuid))) {
            throw new RuntimeException('Connected MySQL server UUID does not match the owner-pinned Phase 15 test server.');
        }
    }

    public static function assertDataDirectory(string $actualDirectory, string $expectedDirectory): void
    {
        $actual = self::normalizeDirectory($actualDirectory);
        $expected = self::normalizeDirectory($expectedDirectory);
        if ($actual === null || $expected === null || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Connected MySQL data directory does not match the owner-pinned Phase 15 test instance.');
        }
    }

    private static function normalizeDirectory(string $directory): ?string
    {
        if (trim($directory) === '') {
            return null;
        }

        $resolved = realpath($directory);
        if ($resolved === false || ! is_dir($resolved)) {
            return null;
        }

        return strtolower(rtrim(str_replace('\\', '/', $resolved), '/'));
    }
}
