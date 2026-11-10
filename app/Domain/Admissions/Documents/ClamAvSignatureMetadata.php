<?php

namespace App\Domain\Admissions\Documents;

use DateTimeImmutable;
use DateTimeZone;

final readonly class ClamAvSignatureMetadata
{
    private function __construct(
        public string $engineVersion,
        public int $databaseVersion,
        public DateTimeImmutable $databaseBuiltAt,
        public int $signatureCount = 0,
    ) {}

    public static function fromVersionOutput(string $output, DateTimeZone $timezone): ?self
    {
        if (preg_match('/\bClamAV\s+(\d+(?:\.\d+){1,3})\/(\d+)\/([^\r\n]+)/', $output, $matches) !== 1) {
            return null;
        }

        $databaseVersion = (int) $matches[2];
        $date = preg_replace('/\s+/', ' ', trim($matches[3]));
        if ($databaseVersion < 1 || ! is_string($date)) {
            return null;
        }

        $builtAt = DateTimeImmutable::createFromFormat('D M j H:i:s Y', $date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($builtAt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return new self($matches[1], $databaseVersion, $builtAt);
    }

    public static function fromSignatureToolOutput(string $output, string $engineVersion, DateTimeZone $timezone): ?self
    {
        if (
            preg_match('/^Version:\s*(\d+)\s*$/m', $output, $versionMatches) !== 1
            || preg_match('/^Build time:\s*(\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}\s+[+-]\d{4})\s*$/m', $output, $buildMatches) !== 1
            || preg_match('/^Signatures:\s*(\d+)\s*$/m', $output, $signatureMatches) !== 1
            || ! str_contains($output, 'Verification OK.')
        ) {
            return null;
        }

        $databaseVersion = (int) $versionMatches[1];
        $signatureCount = (int) $signatureMatches[1];
        $builtAt = DateTimeImmutable::createFromFormat('!j M Y H:i O', trim($buildMatches[1]), $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $databaseVersion < 1
            || $signatureCount < 1
            || $builtAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        return new self($engineVersion, $databaseVersion, $builtAt, $signatureCount);
    }

    public function isFreshAt(DateTimeImmutable $now, int $maximumAgeHours, int $allowedFutureSkewSeconds = 300): bool
    {
        if ($maximumAgeHours < 1 || $maximumAgeHours > 168) {
            return false;
        }

        $ageSeconds = $now->getTimestamp() - $this->databaseBuiltAt->getTimestamp();

        return $ageSeconds >= -$allowedFutureSkewSeconds && $ageSeconds <= $maximumAgeHours * 3600;
    }

    public function identifier(): string
    {
        return sprintf('clamav/%s/db-%d/%s', $this->engineVersion, $this->databaseVersion, $this->databaseBuiltAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
    }
}
