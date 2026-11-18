<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stages an HTTP upload in private quarantine and fingerprints the staged copy.
 *
 * Endpoint protection may remove a temporary upload while a request is being
 * handled. Treat that as an unavailable upload, never as a successful scan or
 * an unhandled filesystem error.
 */
final class PrivateQuarantineUpload
{
    /** @return array{path:string,sha256:string,size:int} */
    public static function stage(UploadedFile $file, string $path, string $field = 'file'): array
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/(?:^|[\\/])\.\.(?:[\\/]|$)/', $path) === 1) {
            throw new \InvalidArgumentException('Quarantine paths must be safe relative paths.');
        }

        $disk = Storage::disk('local');
        $storedPath = null;
        try {
            self::assertReadable($file, $field);
            $temporaryPath = $file->getRealPath();
            if (! is_string($temporaryPath)) {
                self::unavailable($field);
            }
            $size = filesize($temporaryPath);
            if (! is_int($size) || $size < 1) {
                self::unavailable($field);
            }
            $sourceHash = hash_file('sha256', $temporaryPath);
            if (! is_string($sourceHash)) {
                self::unavailable($field);
            }

            $storedPath = $disk->putFileAs(dirname($path), $file, basename($path));
            if (! is_string($storedPath) || $storedPath !== $path) {
                self::unavailable($field);
            }

            $stagedPath = $disk->path($storedPath);
            if (! is_file($stagedPath) || ! is_readable($stagedPath)) {
                self::unavailable($field);
            }

            $hash = hash_file('sha256', $stagedPath);
            $stagedSize = filesize($stagedPath);
            if (! is_string($hash) || ! hash_equals($sourceHash, $hash) || ! is_int($stagedSize) || $stagedSize !== $size) {
                self::unavailable($field);
            }

            return ['path' => $storedPath, 'sha256' => $hash, 'size' => $stagedSize];
        } catch (ValidationException $exception) {
            if (is_string($storedPath)) {
                $disk->delete($storedPath);
            }

            throw $exception;
        } catch (Throwable) {
            if (is_string($storedPath)) {
                $disk->delete($storedPath);
            }

            self::unavailable($field);
        }
    }

    public static function assertReadable(UploadedFile $file, string $field = 'file'): void
    {
        $temporaryPath = $file->getRealPath();
        if (! $file->isValid() || ! is_string($temporaryPath) || ! is_file($temporaryPath) || ! is_readable($temporaryPath)) {
            self::unavailable($field);
        }
    }

    public static function mimeType(UploadedFile $file, string $field = 'file'): string
    {
        self::assertReadable($file, $field);

        try {
            $mimeType = $file->getMimeType();
        } catch (Throwable) {
            self::unavailable($field);
        }

        if (! is_string($mimeType) || trim($mimeType) === '') {
            self::unavailable($field);
        }

        return $mimeType;
    }

    public static function size(UploadedFile $file, string $field = 'file'): int
    {
        self::assertReadable($file, $field);

        try {
            $size = $file->getSize();
        } catch (Throwable) {
            self::unavailable($field);
        }

        if (! is_int($size) || $size < 1) {
            self::unavailable($field);
        }

        return $size;
    }

    private static function unavailable(string $field): never
    {
        throw ValidationException::withMessages([
            $field => 'The upload could not be safely staged. Please select the file again and retry.',
        ]);
    }
}
