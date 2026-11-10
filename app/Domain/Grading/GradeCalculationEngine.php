<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/** Deterministic weighted grade arithmetic using integer cents and basis points only. */
final class GradeCalculationEngine
{
    /**
     * @param  list<array{weight_basis_points:int,items:list<array{earned_points:?string,maximum_points:string,excluded?:bool}>}>  $categories
     * @return array{status:'complete'|'incomplete',percentage:?string,percentage_basis_points:?int}
     */
    public function calculate(array $categories, string $roundingMode = 'half_up'): array
    {
        if ($categories === []) {
            throw new InvalidArgumentException('At least one weighted category is required.');
        }
        if (! in_array($roundingMode, ['half_up', 'half_even', 'down'], true)) {
            throw new InvalidArgumentException('The grading rounding mode is not supported.');
        }

        $weightTotal = 0;
        foreach ($categories as $category) {
            if ($category['weight_basis_points'] < 0 || $category['weight_basis_points'] > 10000) {
                throw new InvalidArgumentException('Category weights must be between 0 and 10000 basis points.');
            }
            $weightTotal += $category['weight_basis_points'];
        }
        if ($weightTotal !== 10000) {
            throw new InvalidArgumentException('Active grade policy category weights must total exactly 10000 basis points.');
        }

        $percentageBasisPoints = 0;
        foreach ($categories as $category) {
            $weight = $category['weight_basis_points'];
            if ($category['items'] === []) {
                return ['status' => 'incomplete', 'percentage' => null, 'percentage_basis_points' => null];
            }

            $earnedCents = 0;
            $maximumCents = 0;
            foreach ($category['items'] as $item) {
                if ($item['excluded'] ?? false) {
                    continue;
                }
                $maximum = self::toCents($item['maximum_points']);
                if ($maximum < 1) {
                    throw new InvalidArgumentException('Maximum points must be greater than zero.');
                }
                if ($item['earned_points'] === null) {
                    return ['status' => 'incomplete', 'percentage' => null, 'percentage_basis_points' => null];
                }
                $earned = self::toCents($item['earned_points']);
                if ($earned > $maximum) {
                    throw new InvalidArgumentException('Earned points cannot exceed the item maximum.');
                }
                $earnedCents += $earned;
                $maximumCents += $maximum;
            }
            if ($maximumCents === 0) {
                return ['status' => 'incomplete', 'percentage' => null, 'percentage_basis_points' => null];
            }

            $categoryScore = self::divideRounded($earnedCents * 10000, $maximumCents, $roundingMode);
            $percentageBasisPoints += self::divideRounded($categoryScore * $weight, 10000, $roundingMode);
        }

        return [
            'status' => 'complete',
            'percentage' => sprintf('%d.%02d', intdiv($percentageBasisPoints, 100), $percentageBasisPoints % 100),
            'percentage_basis_points' => $percentageBasisPoints,
        ];
    }

    private static function toCents(string $value): int
    {
        if (preg_match('/^(?:0|[1-9]\d{0,6})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Grade points must be a non-negative decimal with at most two fractional digits.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function divideRounded(int $numerator, int $denominator, string $roundingMode): int
    {
        if ($denominator < 1) {
            throw new InvalidArgumentException('A grade denominator must be positive.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($roundingMode === 'down') {
            return $quotient;
        }
        if ($remainder * 2 < $denominator) {
            return $quotient;
        }
        if ($remainder * 2 > $denominator || $roundingMode === 'half_up') {
            return $quotient + 1;
        }

        return $quotient % 2 === 0 ? $quotient : $quotient + 1;
    }
}
