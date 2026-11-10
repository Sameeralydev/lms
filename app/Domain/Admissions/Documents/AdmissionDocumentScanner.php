<?php

namespace App\Domain\Admissions\Documents;

interface AdmissionDocumentScanner
{
    public function scan(string $absolutePath): AdmissionDocumentScanResult;

    public function name(): string;
}
