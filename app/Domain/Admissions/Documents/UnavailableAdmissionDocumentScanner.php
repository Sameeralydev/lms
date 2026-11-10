<?php

namespace App\Domain\Admissions\Documents;

final class UnavailableAdmissionDocumentScanner implements AdmissionDocumentScanner
{
    public function scan(string $absolutePath): AdmissionDocumentScanResult
    {
        return AdmissionDocumentScanResult::unavailable();
    }

    public function name(): string
    {
        return 'unavailable';
    }
}
