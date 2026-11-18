<?php

namespace App\Domain\Billing;

use InvalidArgumentException;

/** Integer minor-unit arithmetic for two-decimal institutional currencies. */
final class BillingMoney
{
    public static function toMinor(string|int $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Money must be a non-negative decimal with at most two fractional digits.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function fromMinor(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Normalize aggregate decimal strings without binary floating-point or integer overflow. */
    public static function normalizeDecimal(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Database money aggregate is not a non-negative decimal.');
        }

        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException('Database money aggregate has unexpected sub-cent precision.');
        }

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
