<?php

declare(strict_types=1);

namespace Tests\Support\Phase12ScannerProbe;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$configuration = $root.'/.env.phase12.testing';
if (! is_file($configuration) || is_link($configuration)) {
    fwrite(STDERR, "Phase 12 scanner probe requires the ignored local Phase 12 environment file.\n");
    exit(2);
}
$environment = Dotenv::parse((string) file_get_contents($configuration));
foreach (['ADMISSIONS_DOCUMENT_SCANNER', 'ADMISSIONS_CLAMAV_BINARY', 'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY', 'ADMISSIONS_SIGTOOL_BINARY', 'ADMISSIONS_CLAMAV_TIMEOUT', 'ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS', 'ADMISSIONS_CLAMAV_TIMEZONE'] as $key) {
    if (isset($environment[$key])) {
        putenv($key.'='.$environment[$key]);
        $_ENV[$key] = $_SERVER[$key] = $environment[$key];
    }
}
if (($environment['ADMISSIONS_DOCUMENT_SCANNER'] ?? '') !== 'clamav') {
    fwrite(STDERR, "Phase 12 scanner probe requires the real ClamAV adapter to be configured.\n");
    exit(2);
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$framework = realpath(storage_path('framework'));
if ($framework === false || is_link($framework)) {
    fwrite(STDERR, "Phase 12 scanner probe requires a private framework directory.\n");
    exit(2);
}
$token = strtolower((string) Str::uuid());
$cleanPath = $framework.DIRECTORY_SEPARATOR.'phase12-clean-'.$token.'.txt';
$infectedPath = $framework.DIRECTORY_SEPARATOR.'phase12-eicar-'.$token.'.txt';
$scanner = app(AdmissionDocumentScanner::class);
$status = 0;
try {
    if (file_put_contents($cleanPath, "TechMentors Phase 12 scanner acceptance clean document.\n", LOCK_EX) === false) {
        throw new RuntimeException('clean probe file could not be prepared');
    }
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    if (file_put_contents($infectedPath, $eicar, LOCK_EX) === false) {
        throw new RuntimeException('standard harmless test signature file could not be prepared');
    }
    $clean = $scanner->scan($cleanPath);
    $infected = $scanner->scan($infectedPath);
    fwrite(STDOUT, 'Phase 12 ClamAV integration: clean='.$clean->status.':'.($clean->code ?? 'none').' malware_signature='.$infected->status.':'.($infected->code ?? 'none').' scanner='.$scanner->name().PHP_EOL);
    if ($clean->status !== 'clean' || $infected->status !== 'rejected') {
        throw new RuntimeException('scanner did not return the expected clean and malware verdicts');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 12 ClamAV integration failed: '.class_basename($error).PHP_EOL);
    $status = 1;
} finally {
    foreach ([$cleanPath, $infectedPath] as $path) {
        if (is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
    }
}
exit($status);
