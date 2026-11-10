<?php

namespace Tests\Unit;

use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Admissions\Documents\ClamAvSignatureMetadata;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClamAvSignatureMetadataTest extends TestCase
{
    private DateTimeZone $utc;

    private string $signatureDirectory;

    protected function setUp(): void
    {
        $this->utc = new DateTimeZone('UTC');
        $this->signatureDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase04-clamav-'.bin2hex(random_bytes(8));
        mkdir($this->signatureDirectory, 0700);
        file_put_contents($this->signatureDirectory.DIRECTORY_SEPARATOR.'main.cvd', 'synthetic CVD metadata fixture');
        file_put_contents($this->signatureDirectory.DIRECTORY_SEPARATOR.'daily.cvd', 'synthetic CVD metadata fixture');
    }

    protected function tearDown(): void
    {
        foreach (['main.cvd', 'daily.cvd'] as $database) {
            $path = $this->signatureDirectory.DIRECTORY_SEPARATOR.$database;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->signatureDirectory)) {
            rmdir($this->signatureDirectory);
        }

        parent::tearDown();
    }

    public function test_parses_engine_database_version_and_build_time(): void
    {
        $metadata = ClamAvSignatureMetadata::fromVersionOutput(
            "ClamAV 1.4.3/27780/Thu Oct 22 12:00:00 2026\n",
            $this->utc,
        );

        self::assertNotNull($metadata);
        self::assertSame('1.4.3', $metadata->engineVersion);
        self::assertSame(27780, $metadata->databaseVersion);
        self::assertSame('20261022T120000Z', $metadata->databaseBuiltAt->setTimezone($this->utc)->format('Ymd\THis\Z'));
        self::assertSame('clamav/1.4.3/db-27780/20261022T120000Z', $metadata->identifier());
    }

    public function test_accepts_fresh_signatures_and_rejects_stale_or_future_build_times(): void
    {
        $now = new DateTimeImmutable('2026-10-23 12:00:00', $this->utc);
        $fresh = ClamAvSignatureMetadata::fromVersionOutput(
            "ClamAV 1.4.3/27780/Thu Oct 22 12:00:00 2026\n",
            $this->utc,
        );
        $stale = ClamAvSignatureMetadata::fromVersionOutput(
            "ClamAV 1.4.3/27700/Mon Oct 19 12:00:00 2026\n",
            $this->utc,
        );
        $future = ClamAvSignatureMetadata::fromVersionOutput(
            "ClamAV 1.4.3/27781/Fri Oct 23 12:10:00 2026\n",
            $this->utc,
        );

        self::assertNotNull($fresh);
        self::assertNotNull($stale);
        self::assertNotNull($future);
        self::assertTrue($fresh->isFreshAt($now, 48));
        self::assertFalse($stale->isFreshAt($now, 48));
        self::assertFalse($future->isFreshAt($now, 48));
        self::assertFalse($fresh->isFreshAt($now, 0));
        self::assertFalse($fresh->isFreshAt($now, 169));
    }

    public function test_unrecognized_version_output_cannot_be_treated_as_fresh(): void
    {
        self::assertNull(ClamAvSignatureMetadata::fromVersionOutput('ClamAV 1.4.3', $this->utc));
        self::assertNull(ClamAvSignatureMetadata::fromVersionOutput('scanner output unavailable', $this->utc));
    }

    public function test_sigtool_metadata_requires_verified_database_version_build_time_and_signatures(): void
    {
        $metadata = ClamAvSignatureMetadata::fromSignatureToolOutput(
            "File: daily.cvd\nBuild time: 23 Oct 2026 06:24 +0000\nVersion: 28162\nSignatures: 355787\nVerification OK.\n",
            '1.5.4',
            $this->utc,
        );

        self::assertNotNull($metadata);
        self::assertSame(28162, $metadata->databaseVersion);
        self::assertSame(355787, $metadata->signatureCount);
        self::assertSame('20261023T062400Z', $metadata->databaseBuiltAt->format('Ymd\\THis\\Z'));
        self::assertNull(ClamAvSignatureMetadata::fromSignatureToolOutput(
            "Build time: 23 Oct 2026 06:24 +0000\nVersion: 28162\nSignatures: 355787\n",
            '1.5.4',
            $this->utc,
        ));
    }

    public function test_missing_scanner_binary_fails_closed_without_scanning(): void
    {
        $scanner = new ClamAvAdmissionDocumentScanner('', 48, 'UTC');
        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('scanner_unavailable', $result->code);
        self::assertSame('clamav', $scanner->name());
    }

    public function test_missing_signature_directory_and_unsupported_path_fail_closed(): void
    {
        $missingSignatures = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', __DIR__.'/missing-clamav-definitions');
        $unsupportedPath = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory);

        self::assertSame('unavailable', $missingSignatures->scan(__FILE__)->status);
        self::assertSame('unavailable', $unsupportedPath->scan(__DIR__.'/does-not-exist.pdf')->status);
    }

    public function test_empty_signature_directory_cannot_be_treated_as_scannable(): void
    {
        $emptyDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase04-clamav-empty-'.bin2hex(random_bytes(8));
        mkdir($emptyDirectory, 0700);
        $calls = 0;
        $builtAt = new DateTimeImmutable('now', $this->utc);
        $executor = function (array $arguments) use (&$calls, $builtAt): array {
            $calls++;

            return ['exit_code' => 0, 'output' => $this->versionMetadata($builtAt, 28162), 'error_output' => ''];
        };

        try {
            $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $emptyDirectory, PHP_BINARY, $executor);
            $result = $scanner->scan(__FILE__);
        } finally {
            rmdir($emptyDirectory);
        }

        self::assertSame('unavailable', $result->status);
        self::assertSame('signature_metadata_unavailable', $result->code);
        self::assertSame(1, $calls, 'No document scan may run without the configured main and daily databases.');
    }

    public function test_nonclean_process_exit_fails_closed_and_uses_explicit_signature_directory(): void
    {
        $commands = [];
        $builtAt = new DateTimeImmutable('now', $this->utc);
        $executor = function (array $arguments, int $timeout, string $timezone) use (&$commands, $builtAt): array {
            $commands[] = [$arguments, $timeout, $timezone];
            if (in_array('--version', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->versionMetadata($builtAt, 28162), 'error_output' => ''];
            }
            if (in_array('--info', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->sigtoolMetadata($arguments[2], $builtAt, 28162), 'error_output' => ''];
            }

            return ['exit_code' => 2, 'output' => '', 'error_output' => 'scanner process error'];
        };
        $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory, PHP_BINARY, $executor);

        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('scanner_execution_failed', $result->code);
        self::assertCount(4, $commands);
        foreach ($commands as [$arguments, $timeout, $timezone]) {
            self::assertSame('UTC', $timezone);
        }
        self::assertSame(15, $commands[0][1]);
        self::assertSame(15, $commands[1][1]);
        self::assertSame(15, $commands[2][1]);
        self::assertSame(120, $commands[3][1]);
        self::assertContains('--database='.$this->signatureDirectory, $commands[0][0]);
        self::assertContains('--database='.$this->signatureDirectory, $commands[3][0]);
    }

    public function test_scanner_timeout_fails_closed_without_clean_result(): void
    {
        $calls = 0;
        $builtAt = new DateTimeImmutable('now', $this->utc);
        $executor = function (array $arguments) use (&$calls, $builtAt): array {
            $calls++;
            if (in_array('--version', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->versionMetadata($builtAt, 28162), 'error_output' => ''];
            }
            if (in_array('--info', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->sigtoolMetadata($arguments[2], $builtAt, 28162), 'error_output' => ''];
            }

            throw new RuntimeException('simulated scanner timeout');
        };
        $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory, PHP_BINARY, $executor);

        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('scanner_execution_failed', $result->code);
        self::assertSame(4, $calls);
    }

    public function test_out_of_policy_document_scan_timeout_fails_closed_before_process_execution(): void
    {
        $calls = 0;
        $executor = function () use (&$calls): array {
            $calls++;

            return ['exit_code' => 0, 'output' => '', 'error_output' => ''];
        };
        $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory, PHP_BINARY, $executor, 5);

        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('signature_policy_invalid', $result->code);
        self::assertSame(0, $calls, 'Invalid scanner timeouts must be rejected before any subprocess starts.');
    }

    public function test_stale_signature_metadata_stops_before_document_scan(): void
    {
        $calls = 0;
        $builtAt = new DateTimeImmutable('2026-10-19 12:00:00', $this->utc);
        $executor = function (array $arguments) use (&$calls, $builtAt): array {
            $calls++;
            if (in_array('--version', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->versionMetadata($builtAt, 27700), 'error_output' => ''];
            }

            if (in_array('--info', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->sigtoolMetadata($arguments[2], $builtAt, 27700), 'error_output' => ''];
            }

            self::fail('A stale signature result must stop before the file scan process.');
        };
        $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory, PHP_BINARY, $executor);

        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('signature_database_stale', $result->code);
        self::assertSame(3, $calls);
    }

    public function test_version_output_must_match_verified_daily_database_metadata(): void
    {
        $calls = 0;
        $builtAt = new DateTimeImmutable('now', $this->utc);
        $executor = function (array $arguments) use (&$calls, $builtAt): array {
            $calls++;
            if (in_array('--version', $arguments, true)) {
                return ['exit_code' => 0, 'output' => $this->versionMetadata($builtAt, 28162), 'error_output' => ''];
            }
            if (in_array('--info', $arguments, true)) {
                $dailyVersion = str_ends_with($arguments[2], 'daily.cvd') ? 28161 : 63;

                return ['exit_code' => 0, 'output' => $this->sigtoolMetadata($arguments[2], $builtAt, $dailyVersion), 'error_output' => ''];
            }

            self::fail('A mismatched signature database must never scan a document.');
        };
        $scanner = new ClamAvAdmissionDocumentScanner(PHP_BINARY, 48, 'UTC', $this->signatureDirectory, PHP_BINARY, $executor);

        $result = $scanner->scan(__FILE__);

        self::assertSame('unavailable', $result->status);
        self::assertSame('signature_metadata_mismatch', $result->code);
        self::assertSame(3, $calls);
    }

    private function versionMetadata(DateTimeImmutable $builtAt, int $version): string
    {
        return 'ClamAV 1.5.4/'.$version.'/'.$builtAt->format('D M j H:i:s Y');
    }

    private function sigtoolMetadata(string $path, DateTimeImmutable $builtAt, int $dailyVersion): string
    {
        $isDaily = str_ends_with($path, 'daily.cvd');
        $version = $isDaily ? $dailyVersion : 63;
        $buildTime = $isDaily ? $builtAt : new DateTimeImmutable('2025-12-16 23:18:00', $this->utc);
        $signatures = $isDaily ? 355787 : 3287027;

        return sprintf(
            "File: %s\nBuild time: %s\nVersion: %d\nSignatures: %d\nVerification OK.\n",
            basename($path),
            $buildTime->format('j M Y H:i O'),
            $version,
            $signatures,
        );
    }
}
