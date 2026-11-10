<?php

namespace App\Domain\Admissions\Documents;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Process\Process;
use Throwable;

final class ClamAvAdmissionDocumentScanner implements AdmissionDocumentScanner
{
    private string $scannerIdentifier = 'clamav';

    /** @var Closure(list<string>, int, string): array{exit_code: int|null, output: string, error_output: string} */
    private readonly Closure $processExecutor;

    /**
     * @param  Closure(list<string>, int, string): array{exit_code: int|null, output: string, error_output: string}|null  $processExecutor
     */
    public function __construct(
        private readonly string $binary,
        private readonly int $maximumSignatureAgeHours = 48,
        private readonly string $timezone = 'UTC',
        private readonly string $signatureDirectory = '',
        private readonly string $signatureToolBinary = '',
        ?Closure $processExecutor = null,
        private readonly int $scanTimeoutSeconds = 120,
    ) {
        $this->processExecutor = $processExecutor ?? static function (array $arguments, int $timeout, string $timezone): array {
            $environment = PHP_OS_FAMILY === 'Windows' ? [] : ['TZ' => $timezone];
            $process = new Process($arguments, null, $environment);
            $process->setTimeout($timeout);
            // Web SAPIs commonly impose a 30-second request limit. Keep the
            // request alive only for this bounded scanner operation; the
            // Symfony process timeout remains the authoritative hard limit.
            if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
                set_time_limit($timeout + 5);
            }
            $process->run();

            return [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
            ];
        };
    }

    public function scan(string $absolutePath): AdmissionDocumentScanResult
    {
        if ($this->binary === '' || $this->signatureDirectory === '' || ! is_dir($this->signatureDirectory)
            || ! is_readable($this->signatureDirectory) || ! is_file($absolutePath) || ! is_readable($absolutePath) || ! is_file($this->binary)) {
            return AdmissionDocumentScanResult::unavailable();
        }

        try {
            if ($this->maximumSignatureAgeHours < 1 || $this->maximumSignatureAgeHours > 168
                || $this->scanTimeoutSeconds < 10 || $this->scanTimeoutSeconds > 600) {
                return AdmissionDocumentScanResult::unavailable('signature_policy_invalid');
            }

            $timezone = new DateTimeZone($this->timezone);
            $databaseOption = '--database='.$this->signatureDirectory;
            $signatureTool = $this->signatureToolBinary !== ''
                ? $this->signatureToolBinary
                : dirname($this->binary).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'sigtool.exe' : 'sigtool');
            if (! is_file($signatureTool) || ! is_readable($signatureTool)) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_unavailable');
            }

            $versionResult = ($this->processExecutor)([$this->binary, $databaseOption, '--version'], 15, $this->timezone);
            if ($versionResult['exit_code'] !== 0) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_unavailable');
            }

            $metadata = ClamAvSignatureMetadata::fromVersionOutput($versionResult['output'].$versionResult['error_output'], $timezone);
            if ($metadata === null) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_unavailable');
            }

            $mainDatabase = $this->databaseArchive('main');
            $dailyDatabase = $this->databaseArchive('daily');
            if ($mainDatabase === null || $dailyDatabase === null) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_unavailable');
            }

            $mainInfo = ($this->processExecutor)([$signatureTool, '--info', $mainDatabase], 15, $this->timezone);
            $dailyInfo = ($this->processExecutor)([$signatureTool, '--info', $dailyDatabase], 15, $this->timezone);
            if ($mainInfo['exit_code'] !== 0 || $dailyInfo['exit_code'] !== 0) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_unavailable');
            }

            $mainMetadata = ClamAvSignatureMetadata::fromSignatureToolOutput($mainInfo['output'].$mainInfo['error_output'], $metadata->engineVersion, $timezone);
            $dailyMetadata = ClamAvSignatureMetadata::fromSignatureToolOutput($dailyInfo['output'].$dailyInfo['error_output'], $metadata->engineVersion, $timezone);
            if ($mainMetadata === null || $dailyMetadata === null
                || $dailyMetadata->databaseVersion !== $metadata->databaseVersion
                || abs($dailyMetadata->databaseBuiltAt->getTimestamp() - $metadata->databaseBuiltAt->getTimestamp()) > 60) {
                return AdmissionDocumentScanResult::unavailable('signature_metadata_mismatch');
            }

            $this->scannerIdentifier = $dailyMetadata->identifier();
            if (! $dailyMetadata->isFreshAt(new DateTimeImmutable('now', $timezone), $this->maximumSignatureAgeHours)) {
                return AdmissionDocumentScanResult::unavailable('signature_database_stale');
            }

            $scanResult = ($this->processExecutor)([$this->binary, $databaseOption, '--no-summary', '--', $absolutePath], $this->scanTimeoutSeconds, $this->timezone);
            if ($scanResult['exit_code'] === 0) {
                return AdmissionDocumentScanResult::clean();
            }
            if ($scanResult['exit_code'] === 1) {
                return AdmissionDocumentScanResult::rejected();
            }
        } catch (Throwable) {
            return AdmissionDocumentScanResult::unavailable('scanner_execution_failed');
        }

        return AdmissionDocumentScanResult::unavailable('scanner_execution_failed');
    }

    public function name(): string
    {
        return $this->scannerIdentifier;
    }

    private function databaseArchive(string $name): ?string
    {
        foreach (['cld', 'cvd'] as $extension) {
            $path = rtrim($this->signatureDirectory, '\\/').DIRECTORY_SEPARATOR.$name.'.'.$extension;
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
