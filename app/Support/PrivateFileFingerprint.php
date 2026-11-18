<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/** Returns null when quarantine content is missing or cannot be read safely. */
final class PrivateFileFingerprint
{
    public static function sha256(string $absolutePath): ?string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        try {
            $hash = @hash_file('sha256', $absolutePath);

            return is_string($hash) ? $hash : null;
        } catch (Throwable) {
            return null;
        }
    }
}
