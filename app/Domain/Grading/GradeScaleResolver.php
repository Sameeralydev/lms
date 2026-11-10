<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

final class GradeScaleResolver
{
    /**
     * @param  list<array{label:string,minimum_percentage_basis_points:int,grade_points:?string,passing:?bool}>  $bands
     * @return array{scale_result:string,grade_points:?string,passed:?bool}
     */
    public function resolve(int $percentageBasisPoints, string $scaleType, array $bands): array
    {
        if ($percentageBasisPoints < 0 || $percentageBasisPoints > 10000) {
            throw new InvalidArgumentException('A grade percentage must be between 0 and 10000 basis points.');
        }

        if ($scaleType === 'percentage') {
            return ['scale_result' => sprintf('%d.%02d%%', intdiv($percentageBasisPoints, 100), $percentageBasisPoints % 100), 'grade_points' => null, 'passed' => null];
        }

        if ($scaleType === 'numeric') {
            return ['scale_result' => sprintf('%d.%02d', intdiv($percentageBasisPoints, 100), $percentageBasisPoints % 100), 'grade_points' => null, 'passed' => null];
        }

        $this->assertValidBands($scaleType, $bands);
        usort($bands, static fn (array $left, array $right): int => $right['minimum_percentage_basis_points'] <=> $left['minimum_percentage_basis_points']);
        $band = null;
        foreach ($bands as $candidate) {
            if ($candidate['minimum_percentage_basis_points'] <= $percentageBasisPoints) {
                $band = $candidate;
                break;
            }
        }

        if ($band === null) {
            throw new InvalidArgumentException('The configured grade bands do not cover this result.');
        }

        return [
            'scale_result' => $band['label'],
            'grade_points' => $band['grade_points'],
            'passed' => $scaleType === 'pass_fail' ? $band['passing'] : null,
        ];
    }

    /** @param list<array{label:string,minimum_percentage_basis_points:int,grade_points:?string,passing:?bool}> $bands */
    public function assertValidBands(string $scaleType, array $bands): void
    {
        if (! in_array($scaleType, ['letter', 'pass_fail'], true) || count($bands) < 2 || count($bands) > 20) {
            throw new InvalidArgumentException('Letter and pass/fail scales require between two and twenty grade bands.');
        }

        $thresholds = [];
        $labels = [];
        $hasPass = false;
        $hasFail = false;
        foreach ($bands as $band) {
            $threshold = $band['minimum_percentage_basis_points'];
            $label = trim($band['label']);
            if ($threshold < 0 || $threshold > 10000 || $label === '' || mb_strlen($label) > 48 || isset($thresholds[$threshold])) {
                throw new InvalidArgumentException('Grade bands need unique thresholds from 0 to 10000 and non-empty labels.');
            }
            if (isset($labels[mb_strtolower($label)])) {
                throw new InvalidArgumentException('Grade band labels must be unique within a scale.');
            }
            $thresholds[$threshold] = true;
            $labels[mb_strtolower($label)] = true;

            if ($scaleType === 'letter') {
                if ($band['grade_points'] === null || preg_match('/^(?:0|[1-9]\d?)(?:\.\d{1,3})?$/D', $band['grade_points']) !== 1 || (float) $band['grade_points'] > 20) {
                    throw new InvalidArgumentException('Letter bands require institution-configured grade points between zero and twenty.');
                }
                if ($band['passing'] !== null) {
                    throw new InvalidArgumentException('Letter bands cannot include a pass/fail outcome.');
                }
            } elseif ($band['grade_points'] !== null || ! is_bool($band['passing'])) {
                throw new InvalidArgumentException('Pass/fail bands require a boolean outcome and cannot include grade points.');
            }

            $hasPass = $hasPass || $band['passing'] === true;
            $hasFail = $hasFail || $band['passing'] === false;
        }

        if (! isset($thresholds[0]) || ($scaleType === 'pass_fail' && (! $hasPass || ! $hasFail))) {
            throw new InvalidArgumentException('Grade bands must cover results from zero and pass/fail scales must define both outcomes.');
        }
    }
}
