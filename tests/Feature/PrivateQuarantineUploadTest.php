<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanning;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Support\PrivateFileFingerprint;
use App\Support\PrivateQuarantineUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PrivateQuarantineUploadTest extends TestCase
{
    public function test_stages_private_upload_and_fingerprints_the_stored_copy(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.7\nprivate fixture\n%%EOF");
        $expected = file_get_contents($file->getRealPath());

        $staged = PrivateQuarantineUpload::stage($file, 'quarantine/test/report.pdf');

        self::assertSame('quarantine/test/report.pdf', $staged['path']);
        self::assertSame(hash('sha256', $expected), $staged['sha256']);
        self::assertSame(strlen($expected), $staged['size']);
        Storage::disk('local')->assertExists($staged['path']);
    }

    public function test_unavailable_temporary_upload_fails_as_validation_error_without_staging(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('report.pdf', 'temporary content');
        $temporaryPath = $file->getRealPath();
        self::assertIsString($temporaryPath);
        unlink($temporaryPath);

        try {
            PrivateQuarantineUpload::stage($file, 'quarantine/test/missing.pdf');
            self::fail('An upload whose temporary file disappeared must fail closed.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        }

        Storage::disk('local')->assertMissing('quarantine/test/missing.pdf');
    }

    public function test_mime_metadata_failure_after_file_inspection_fails_closed_as_validation(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'quarantine-mime-');
        self::assertIsString($temporaryPath);
        file_put_contents($temporaryPath, 'temporary content');
        $file = new class($temporaryPath, 'report.txt', 'text/plain', UPLOAD_ERR_OK, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                throw new \RuntimeException('Simulated endpoint-protection file removal.');
            }
        };

        try {
            PrivateQuarantineUpload::mimeType($file);
            self::fail('A failed content-type inspection must not escape as an HTTP 500 or be treated as clean.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function test_missing_quarantine_content_has_no_fingerprint_and_cannot_be_mistaken_for_clean(): void
    {
        self::assertNull(PrivateFileFingerprint::sha256(sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent-quarantine-'.bin2hex(random_bytes(8))));
    }

    public function test_clean_result_is_rejected_when_scanner_changes_the_staged_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scanner-content-bound-');
        self::assertIsString($path);
        file_put_contents($path, 'the exact staged bytes');
        $digest = hash_file('sha256', $path);
        self::assertIsString($digest);
        $scanner = new class($path) implements AdmissionDocumentScanner
        {
            public function __construct(private readonly string $path) {}

            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                file_put_contents($this->path, 'changed after quarantine');

                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-scanner';
            }
        };

        try {
            $result = AdmissionDocumentScanning::scan($scanner, $path, $digest);
            self::assertSame('unavailable', $result->status);
            self::assertSame('document_changed_during_scan', $result->code);
        } finally {
            @unlink($path);
        }
    }

    public function test_scan_is_not_started_when_quarantined_bytes_no_longer_match_recorded_digest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scanner-content-bound-');
        self::assertIsString($path);
        file_put_contents($path, 'current bytes');
        $scanner = new class implements AdmissionDocumentScanner
        {
            public bool $called = false;

            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                $this->called = true;

                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-scanner';
            }
        };

        try {
            $result = AdmissionDocumentScanning::scan($scanner, $path, hash('sha256', 'different original bytes'));
            self::assertSame('unavailable', $result->status);
            self::assertSame('document_changed_before_scan', $result->code);
            self::assertFalse($scanner->called);
        } finally {
            @unlink($path);
        }
    }
}
