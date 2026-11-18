<?php

namespace App\Domain\Admissions\Documents;

use Throwable;

/** Keeps scanner integrations bound to the content fingerprint recorded at quarantine. */
final class AdmissionDocumentScanning
{
    public static function scan(AdmissionDocumentScanner $scanner, string $absolutePath, string $expectedSha256): AdmissionDocumentScanResult
    {
        if (preg_match('/\A[a-f0-9]{64}\z/iD', $expectedSha256) !== 1) {
            return AdmissionDocumentScanResult::unavailable('document_integrity_unavailable');
        }

        try {
            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                return AdmissionDocumentScanResult::unavailable('document_integrity_unavailable');
            }
            $before = hash_file('sha256', $absolutePath);
            if (! is_string($before) || ! hash_equals($expectedSha256, $before)) {
                return AdmissionDocumentScanResult::unavailable('document_changed_before_scan');
            }

            $result = $scanner instanceof ContentBoundAdmissionDocumentScanner
                ? $scanner->scanExpected($absolutePath, $expectedSha256)
                : $scanner->scan($absolutePath);

            // Legacy scanners cannot attest an expected digest during process execution. A changed
            // file is therefore never accepted even when a scanner returned clean.
            if ($result->status === 'clean') {
                $after = hash_file('sha256', $absolutePath);
                if (! is_string($after) || ! hash_equals($expectedSha256, $after)) {
                    return AdmissionDocumentScanResult::unavailable('document_changed_during_scan');
                }
            }

            return $result;
        } catch (Throwable) {
            return AdmissionDocumentScanResult::unavailable('document_integrity_unavailable');
        }
    }
}
