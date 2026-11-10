<?php

namespace App\Domain\Admissions\Documents;

final readonly class AdmissionDocumentScanResult
{
    private const ALLOWED_STATUSES = ['clean', 'rejected', 'unavailable'];

    private function __construct(public string $status, public ?string $code = null)
    {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('Admission document scanner returned an unsupported status.');
        }
    }

    public static function clean(): self
    {
        return new self('clean');
    }

    public static function rejected(string $code = 'malware_detected'): self
    {
        return new self('rejected', $code);
    }

    public static function unavailable(string $code = 'scanner_unavailable'): self
    {
        return new self('unavailable', $code);
    }
}
