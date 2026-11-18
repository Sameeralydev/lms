<?php

namespace App\Domain\Admissions\Documents;

/** A scanner that verifies it scans the exact immutable staged upload. */
interface ContentBoundAdmissionDocumentScanner extends AdmissionDocumentScanner
{
    public function scanExpected(string $absolutePath, string $expectedSha256): AdmissionDocumentScanResult;
}
