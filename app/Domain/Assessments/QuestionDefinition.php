<?php

namespace App\Domain\Assessments;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class QuestionDefinition
{
    /** @var list<string> */
    public const TYPES = [
        'single_choice', 'multiple_choice', 'true_false', 'matching', 'ordering',
        'fill_blank', 'short_text', 'long_response', 'numeric', 'multiple_blanks',
        'case_passage', 'image_based', 'file_response', 'instructor_review',
    ];

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $answerKey
     * @param  array<string, mixed>  $scoringRules
     * @return array{content:array<string,mixed>,answer_key:array<string,mixed>|null,scoring_rules:array<string,mixed>}
     */
    public static function normalize(string $type, array $content, ?array $answerKey, array $scoringRules = []): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['question_type' => 'Choose a supported question type.']);
        }

        $prompt = self::plainText((string) ($content['prompt'] ?? ''), 20000);
        if ($type !== 'case_passage' && $prompt === '') {
            throw ValidationException::withMessages(['prompt' => 'Question instructions are required.']);
        }
        $content['prompt'] = $prompt;

        foreach (['passage', 'image_alt', 'instructions', 'code_language'] as $field) {
            if (isset($content[$field])) {
                $content[$field] = self::plainText((string) $content[$field], $field === 'passage' ? 30000 : 4000);
            }
        }

        if ($type === 'case_passage' && trim((string) ($content['passage'] ?? '')) === '') {
            throw ValidationException::withMessages(['passage' => 'A case or passage body is required.']);
        }
        if ($type === 'image_based' && trim((string) ($content['image_alt'] ?? '')) === '') {
            throw ValidationException::withMessages(['image_alt' => 'Provide an accessibility description for the image.']);
        }

        if (in_array($type, ['single_choice', 'multiple_choice', 'image_based'], true)) {
            $options = self::normalizeTextItems($content['options'] ?? [], 'options', 2, 20);
            $content['options'] = $options;
            $selected = array_values(array_unique(array_map('strval', (array) ($answerKey['correct_option_ids'] ?? []))));
            $optionIds = array_column($options, 'id');
            if ($selected === [] || array_diff($selected, $optionIds) !== [] || ($type === 'single_choice' && count($selected) !== 1)) {
                throw ValidationException::withMessages(['answer_key' => 'Select valid correct options for this question.']);
            }
            $answerKey = ['correct_option_ids' => $selected];
        } elseif ($type === 'true_false') {
            if (! is_bool($answerKey['value'] ?? null)) {
                throw ValidationException::withMessages(['answer_key' => 'Choose True or False as the correct response.']);
            }
            $answerKey = ['value' => $answerKey['value']];
        } elseif ($type === 'matching') {
            $pairs = self::normalizeTextItems($content['pairs'] ?? [], 'pairs', 2, 30, ['left', 'right']);
            $content['pairs'] = $pairs;
            $matches = (array) ($answerKey['matches'] ?? []);
            $pairIds = array_column($pairs, 'id');
            if (count($matches) !== count($pairs) || array_diff(array_keys($matches), $pairIds) !== [] || array_diff(array_map('strval', $matches), $pairIds) !== []) {
                throw ValidationException::withMessages(['answer_key' => 'Provide one valid matching target for every left-side item.']);
            }
            $answerKey = ['matches' => array_map('strval', $matches)];
        } elseif ($type === 'ordering') {
            $items = self::normalizeTextItems($content['items'] ?? [], 'items', 2, 30);
            $content['items'] = $items;
            $order = array_values(array_map('strval', (array) ($answerKey['order'] ?? [])));
            if (count($order) !== count($items) || array_diff($order, array_column($items, 'id')) !== []) {
                throw ValidationException::withMessages(['answer_key' => 'Set the correct order using each item exactly once.']);
            }
            $answerKey = ['order' => $order];
        } elseif (in_array($type, ['fill_blank', 'multiple_blanks'], true)) {
            $blanks = array_values(array_unique(array_map('strval', (array) ($content['blank_keys'] ?? []))));
            $values = (array) ($answerKey['values'] ?? []);
            if ($blanks === [] || count($blanks) > 20 || array_diff($blanks, array_map('strval', array_keys($values))) !== []) {
                throw ValidationException::withMessages(['answer_key' => 'Provide accepted answers for every named blank.']);
            }
            foreach ($blanks as $blank) {
                $accepted = array_values(array_filter(array_map(static fn (mixed $value): string => self::plainText((string) $value, 500), (array) ($values[$blank] ?? [])), static fn (string $value): bool => $value !== ''));
                if ($accepted === []) {
                    throw ValidationException::withMessages(['answer_key' => 'Every blank needs at least one accepted answer.']);
                }
                $values[$blank] = $accepted;
            }
            $content['blank_keys'] = $blanks;
            $answerKey = ['values' => $values];
        } elseif ($type === 'numeric') {
            $expected = self::decimal((string) ($answerKey['value'] ?? ''), true);
            $tolerance = self::decimal((string) ($scoringRules['tolerance'] ?? '0'));
            if ($expected === null || $tolerance === null) {
                throw ValidationException::withMessages(['answer_key' => 'Enter a valid expected number and non-negative tolerance.']);
            }
            $answerKey = ['value' => $expected];
            $scoringRules['tolerance'] = $tolerance;
        } elseif ($type === 'short_text') {
            $accepted = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => self::plainText((string) $value, 1000), (array) ($answerKey['accepted_answers'] ?? [])), static fn (string $value): bool => $value !== '')));
            if (count($accepted) > 20) {
                throw ValidationException::withMessages(['answer_key' => 'Provide no more than 20 accepted short-text answers.']);
            }
            $answerKey = $accepted === [] ? null : ['accepted_answers' => $accepted];
        } elseif (in_array($type, ['long_response', 'file_response', 'instructor_review', 'case_passage'], true)) {
            $answerKey = null;
        }

        $scoringRules['partial_credit'] = (bool) ($scoringRules['partial_credit'] ?? false);
        $scoringRules['case_sensitive'] = (bool) ($scoringRules['case_sensitive'] ?? false);
        $scoringRules['negative_marking'] = (bool) ($scoringRules['negative_marking'] ?? false);
        $scoringRules['penalty_points'] = self::points((string) ($scoringRules['penalty_points'] ?? '0'));
        $scoringRules = Arr::only($scoringRules, ['partial_credit', 'case_sensitive', 'negative_marking', 'penalty_points', 'tolerance']);

        return ['content' => $content, 'answer_key' => $answerKey, 'scoring_rules' => $scoringRules];
    }

    public static function plainText(string $value, int $maximumLength): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        if (mb_strlen($value) > $maximumLength) {
            throw ValidationException::withMessages(['content' => 'Question text is longer than the supported limit.']);
        }

        return $value;
    }

    /**
     * @param  list<string>  $textFields
     * @return list<array<string, string>>
     */
    private static function normalizeTextItems(mixed $items, string $field, int $minimum, int $maximum, array $textFields = ['text']): array
    {
        if (! is_array($items) || count($items) < $minimum || count($items) > $maximum) {
            throw ValidationException::withMessages([$field => "Provide between {$minimum} and {$maximum} items."]);
        }
        $normalized = [];
        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([$field => 'Each item must have a stable identifier and text.']);
            }
            $id = (string) ($item['id'] ?? '');
            if (! preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/', $id) || in_array($id, $ids, true)) {
                throw ValidationException::withMessages([$field => 'Item identifiers must be unique and use letters, numbers, underscores, or hyphens.']);
            }
            $row = ['id' => $id];
            foreach ($textFields as $textField) {
                $text = self::plainText((string) ($item[$textField] ?? ''), 4000);
                if ($text === '') {
                    throw ValidationException::withMessages([$field => 'Every item needs readable text.']);
                }
                $row[$textField] = $text;
            }
            $ids[] = $id;
            $normalized[] = $row;
        }

        return $normalized;
    }

    private static function points(string $value): string
    {
        if (! preg_match('/\A(\d{1,7})(?:\.(\d{1,2}))?\z/', $value, $matches)) {
            throw ValidationException::withMessages(['points' => 'Points must be a non-negative decimal with at most two fractional digits.']);
        }

        return $matches[1].'.'.str_pad($matches[2] ?? '', 2, '0');
    }

    private static function decimal(string $value, bool $allowNegative = false): ?string
    {
        $pattern = $allowNegative
            ? '/\A-?\d{1,12}(?:\.\d{1,6})?\z/'
            : '/\A\d{1,12}(?:\.\d{1,6})?\z/';
        if (! preg_match($pattern, $value)) {
            return null;
        }

        return $value;
    }
}
