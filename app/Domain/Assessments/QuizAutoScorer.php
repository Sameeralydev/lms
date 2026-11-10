<?php

namespace App\Domain\Assessments;

use App\Models\QuizQuestionVersion;

final class QuizAutoScorer
{
    /**
     * Returns integer hundredths of a point, or null when human review is required.
     *
     * @param  array<string, mixed>  $response
     */
    public function score(QuizQuestionVersion $question, array $response, string $points): ?int
    {
        $type = (string) $question->question_type;
        $answer = $question->answer_key;
        $correct = false;
        if ($type === 'short_text' && is_array($answer) && count((array) ($answer['accepted_answers'] ?? [])) > 0) {
            $caseSensitive = (bool) ($question->scoring_rules['case_sensitive'] ?? false);
            $normalize = static fn (string $text): string => $caseSensitive ? trim($text) : mb_strtolower(trim($text));
            $actual = $normalize((string) ($response['text'] ?? ''));
            $accepted = array_map(static fn (mixed $text): string => $normalize((string) $text), (array) $answer['accepted_answers']);
            $correct = in_array($actual, $accepted, true);
        } elseif (! is_array($answer) || in_array($type, ['short_text', 'long_response', 'file_response', 'instructor_review', 'case_passage'], true)) {
            return null;
        }
        $maximum = self::toCents($points);
        $partial = (bool) ($question->scoring_rules['partial_credit'] ?? false);
        $creditNumerator = 0;
        $creditDenominator = 1;

        if (in_array($type, ['single_choice', 'multiple_choice', 'image_based'], true)) {
            $expected = array_values(array_map('strval', (array) ($answer['correct_option_ids'] ?? [])));
            $selected = array_values(array_unique(array_map('strval', (array) ($response['selected_ids'] ?? []))));
            $expectedSet = $expected;
            $selectedSet = $selected;
            sort($expectedSet, SORT_STRING);
            sort($selectedSet, SORT_STRING);
            $correct = $selectedSet === $expectedSet;
            if ($partial && $expected !== []) {
                $matched = count(array_intersect($expected, $selected));
                $incorrect = count(array_diff($selected, $expected));
                $creditNumerator = max(0, $matched - $incorrect);
                $creditDenominator = count($expected);
            }
        } elseif ($type === 'true_false') {
            $correct = isset($response['value']) && is_bool($response['value']) && $response['value'] === ($answer['value'] ?? null);
        } elseif ($type === 'matching') {
            $expected = (array) ($answer['matches'] ?? []);
            $actual = (array) ($response['matches'] ?? []);
            $correctCount = 0;
            foreach ($expected as $key => $value) {
                if ((string) ($actual[$key] ?? '') === (string) $value) {
                    $correctCount++;
                }
            }
            $correct = $correctCount === count($expected) && count($actual) === count($expected);
            $creditNumerator = $correctCount;
            $creditDenominator = max(1, count($expected));
        } elseif ($type === 'ordering') {
            $expected = array_values(array_map('strval', (array) ($answer['order'] ?? [])));
            $actual = array_values(array_map('strval', (array) ($response['order'] ?? [])));
            $correct = $actual === $expected;
            $correctCount = 0;
            foreach ($expected as $index => $id) {
                if (($actual[$index] ?? null) === $id) {
                    $correctCount++;
                }
            }
            $creditNumerator = $correctCount;
            $creditDenominator = max(1, count($expected));
        } elseif (in_array($type, ['fill_blank', 'multiple_blanks'], true)) {
            $expected = (array) ($answer['values'] ?? []);
            $actual = (array) ($response['values'] ?? []);
            $correctCount = 0;
            foreach ($expected as $key => $alternatives) {
                $value = trim((string) ($actual[$key] ?? ''));
                $caseSensitive = (bool) ($question->scoring_rules['case_sensitive'] ?? false);
                $matcher = static fn (string $item): string => $caseSensitive ? trim($item) : mb_strtolower(trim($item));
                if (in_array($matcher($value), array_map($matcher, (array) $alternatives), true)) {
                    $correctCount++;
                }
            }
            $correct = $correctCount === count($expected) && count($actual) === count($expected);
            $creditNumerator = $correctCount;
            $creditDenominator = max(1, count($expected));
        } elseif ($type === 'numeric') {
            $expected = self::scaled((string) ($answer['value'] ?? ''));
            $actual = self::scaled((string) ($response['value'] ?? ''));
            $tolerance = self::scaled((string) ($question->scoring_rules['tolerance'] ?? '0'));
            $correct = $expected !== null && $actual !== null && $tolerance !== null && abs($actual - $expected) <= $tolerance;
        }

        if ($correct) {
            return $maximum;
        }
        if ($partial && $creditNumerator > 0) {
            return intdiv(($maximum * $creditNumerator) + intdiv($creditDenominator, 2), $creditDenominator);
        }
        if ((bool) ($question->scoring_rules['negative_marking'] ?? false)) {
            return -min($maximum, self::toCents((string) ($question->scoring_rules['penalty_points'] ?? '0')));
        }

        return 0;
    }

    public static function toCents(string $value): int
    {
        if (! preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $value, $matches)) {
            return 0;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '0', 2, '0');
    }

    public static function toSignedCents(string $value): int
    {
        $negative = str_starts_with(trim($value), '-');
        $cents = self::toCents($negative ? substr(trim($value), 1) : trim($value));

        return $negative ? -$cents : $cents;
    }

    private static function scaled(string $value): ?int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        if (! preg_match('/\A(\d{1,12})(?:\.(\d{1,6}))?\z/', $unsigned, $matches)) {
            return null;
        }
        $fraction = str_pad($matches[2] ?? '', 6, '0');
        if (strlen($matches[1]) > 12) {
            return null;
        }

        $scaled = ((int) $matches[1] * 1_000_000) + (int) $fraction;

        return $negative ? -$scaled : $scaled;
    }
}
