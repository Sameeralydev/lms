<?php

namespace Tests\Support;

use RuntimeException;

/** Serializes guarded Phase 04 MySQL test runs on this checkout. */
final class Phase04MySqlTestRunLock
{
    /** @var resource */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(): self
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'.phase04-mysql-test-run.lock';

        return self::acquireAtPath($path);
    }

    public static function acquireAtPath(string $path): self
    {

        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a symlink for the Phase 04 MySQL test-run lock.');
        }

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open the local Phase 04 MySQL test-run lock.');
        }

        $resolvedPath = realpath($path);
        $resolvedDirectory = realpath(dirname($path));
        $expectedPath = $resolvedDirectory === false ? false : $resolvedDirectory.DIRECTORY_SEPARATOR.basename($path);

        if ($resolvedPath === false || $resolvedPath !== $expectedPath || ! is_file($path) || ! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another guarded Phase 04 MySQL test run is active, or the lock file is unsafe.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
