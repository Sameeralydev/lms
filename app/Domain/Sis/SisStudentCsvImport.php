<?php

namespace App\Domain\Sis;

use App\Models\SisStudent;
use Generator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SisStudentCsvImport
{
    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'legal_first_name' => 'Legal first name',
        'legal_middle_name' => 'Legal middle name',
        'legal_last_name' => 'Legal family name',
        'preferred_name' => 'Preferred name',
        'registration_reference' => 'Registration reference',
        'date_of_birth' => 'Date of birth',
        'admission_date' => 'Admission date',
        'student_type' => 'Student type',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS = ['legal_first_name', 'legal_last_name', 'admission_date'];

    public const MAX_ROWS = 25000;

    public const CHUNK_SIZE = 250;

    public const MAX_PREVIEW_ERRORS = 500;

    /** @return list<string> */
    public function headers(string $path): array
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV could not be read.']);
        }

        $header = fgetcsv($stream);
        if (! is_array($header) || count($header) > 32) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'The CSV must have between 1 and 32 named columns.']);
        }

        $headers = array_map(static function (mixed $value): string {
            $header = trim((string) $value);
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            $header = preg_replace('/[\x00-\x1F\x7F]/u', '', $header) ?? $header;

            return mb_substr(trim($header), 0, 100);
        }, $header);
        $rowCount = 0;
        while (($values = fgetcsv($stream)) !== false) {
            if (count($values) === 1 && $values[0] === null) {
                continue;
            }
            if (++$rowCount > self::MAX_ROWS) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'A single import is limited to '.number_format(self::MAX_ROWS).' rows.']);
            }
        }
        fclose($stream);

        if ($rowCount === 0) {
            throw ValidationException::withMessages(['file' => 'The CSV file has no student rows.']);
        }
        if (in_array('', $headers, true) || count(array_unique(array_map(Str::lower(...), $headers))) !== count($headers)) {
            throw ValidationException::withMessages(['file' => 'CSV column names must be non-empty and unique.']);
        }

        return $headers;
    }

    /** @param list<string> $headers
     * @param  array<string, string|null>  $provided
     * @return array<string, string>
     */
    public function mapping(array $headers, array $provided = []): array
    {
        $mapping = [];
        if ($provided !== []) {
            $usedHeaders = [];
            foreach ($provided as $field => $sourceHeader) {
                if (! array_key_exists($field, self::FIELD_LABELS) || $sourceHeader === null || $sourceHeader === '') {
                    continue;
                }
                if (! in_array($sourceHeader, $headers, true)) {
                    throw ValidationException::withMessages(["column_map.{$field}" => 'Choose a column present in the uploaded CSV.']);
                }
                if (isset($usedHeaders[$sourceHeader])) {
                    throw ValidationException::withMessages(["column_map.{$field}" => 'A CSV column can only be mapped to one student field.']);
                }
                $mapping[$field] = $sourceHeader;
                $usedHeaders[$sourceHeader] = true;
            }

            return $mapping;
        }

        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalized = Str::lower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $header) ?? $header, '_'));
            $normalizedHeaders[$normalized] = $header;
        }
        $aliases = [
            'legal_first_name' => ['legal_first_name', 'first_name', 'given_name', 'forename'],
            'legal_middle_name' => ['legal_middle_name', 'middle_name'],
            'legal_last_name' => ['legal_last_name', 'last_name', 'family_name', 'surname'],
            'preferred_name' => ['preferred_name', 'display_name', 'nickname'],
            'registration_reference' => ['registration_reference', 'registration_id', 'external_reference'],
            'date_of_birth' => ['date_of_birth', 'birth_date', 'dob'],
            'admission_date' => ['admission_date', 'date_of_admission', 'start_date'],
            'student_type' => ['student_type', 'learner_type', 'category'],
        ];
        $usedHeaders = [];
        foreach ($aliases as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($normalizedHeaders[$candidate]) && ! isset($usedHeaders[$normalizedHeaders[$candidate]])) {
                    $mapping[$field] = $normalizedHeaders[$candidate];
                    $usedHeaders[$normalizedHeaders[$candidate]] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @param list<string> $headers
     * @param  array<string, string>  $mapping
     * @return list<array<string, string|null|int>>
     */
    public function rows(string $path, array $headers, array $mapping): array
    {
        return iterator_to_array($this->streamRows($path, $headers, $mapping), false);
    }

    /** @param list<string> $headers
     * @param  array<string,string>  $mapping
     * @return Generator<int,array<string,string|null|int>>
     */
    public function streamRows(string $path, array $headers, array $mapping): Generator
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV could not be read.']);
        }
        try {
            fgetcsv($stream);
            $headerIndexes = array_flip($headers);
            $line = 1;
            while (($values = fgetcsv($stream)) !== false) {
                $line++;
                if (count($values) === 1 && $values[0] === null) {
                    continue;
                }
                $row = ['source_row' => $line];
                foreach (self::FIELD_LABELS as $field => $_label) {
                    $index = isset($mapping[$field]) ? ($headerIndexes[$mapping[$field]] ?? null) : null;
                    $row[$field] = is_int($index) && isset($values[$index]) ? trim((string) $values[$index]) : null;
                }
                yield $row;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param list<array<string, string|null|int>> $rows
     * @return array{valid:int,invalid:int,errors:list<array{row:int,errors:list<string>}>,rows:list<array<string,mixed>>}
     */
    public function validate(array $rows, int $institutionId): array
    {
        $seenReferences = [];

        return $this->validateChunk($rows, $institutionId, $seenReferences);
    }

    /** @param list<array<string,string|null|int>> $rows
     * @param  array<string,true>  $seenReferences
     * @return array{valid:int,invalid:int,errors:list<array{row:int,errors:list<string>}>,rows:list<array<string,mixed>>}
     */
    public function validateChunk(array $rows, int $institutionId, array &$seenReferences): array
    {
        $valid = [];
        $errors = [];
        $references = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['registration_reference'] ?? '')),
            $rows,
        ), static fn (string $reference): bool => $reference !== '')));
        $existingReferences = $references === [] ? collect() : SisStudent::query()
            ->where('institution_id', $institutionId)->whereIn('registration_reference', $references)
            ->pluck('registration_reference')->flip();

        foreach ($rows as $index => $row) {
            $rowNumber = (int) ($row['source_row'] ?? ($index + 2));
            $issues = [];
            foreach (self::REQUIRED_FIELDS as $required) {
                if (trim((string) ($row[$required] ?? '')) === '') {
                    $issues[] = $required.' is required';
                }
            }
            if (! empty($row['admission_date']) && strtotime((string) $row['admission_date']) === false) {
                $issues[] = 'admission_date is invalid';
            }
            if (! empty($row['date_of_birth']) && strtotime((string) $row['date_of_birth']) === false) {
                $issues[] = 'date_of_birth is invalid';
            }
            foreach (['legal_first_name' => 100, 'legal_middle_name' => 100, 'legal_last_name' => 100, 'preferred_name' => 120, 'registration_reference' => 80, 'student_type' => 48] as $column => $maxLength) {
                if (isset($row[$column]) && mb_strlen((string) $row[$column]) > $maxLength) {
                    $issues[] = $column.' exceeds its allowed length';
                }
            }
            $reference = trim((string) ($row['registration_reference'] ?? ''));
            if ($reference !== '' && (isset($seenReferences[$reference]) || $existingReferences->has($reference))) {
                $issues[] = 'registration_reference is duplicated';
            }
            if ($issues !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $issues];
            } else {
                if ($reference !== '') {
                    $seenReferences[$reference] = true;
                }
                $valid[] = [
                    'source_row' => $rowNumber,
                    'legal_first_name' => trim((string) $row['legal_first_name']), 'legal_middle_name' => $row['legal_middle_name'] ?? null,
                    'legal_last_name' => trim((string) $row['legal_last_name']), 'preferred_name' => $row['preferred_name'] ?? null,
                    'registration_reference' => $reference !== '' ? $reference : null, 'date_of_birth' => $row['date_of_birth'] ?: null,
                    'admission_date' => $row['admission_date'], 'student_type' => $row['student_type'] ?: 'standard',
                ];
            }
        }

        return ['valid' => count($valid), 'invalid' => count($errors), 'errors' => $errors, 'rows' => $valid];
    }

    /** @param list<string> $headers
     * @param  array<string,string>  $mapping
     * @return array{row_count:int,valid:int,invalid:int,errors:list<array{row:int,errors:list<string>}>,errors_truncated:bool}
     */
    public function preview(string $path, array $headers, array $mapping, int $institutionId): array
    {
        $seenReferences = [];
        $chunk = [];
        $rowCount = 0;
        $validCount = 0;
        $invalidCount = 0;
        $errors = [];

        $consume = function (array $rows) use ($institutionId, &$seenReferences, &$validCount, &$invalidCount, &$errors): void {
            $result = $this->validateChunk($rows, $institutionId, $seenReferences);
            $validCount += $result['valid'];
            $invalidCount += $result['invalid'];
            $remaining = self::MAX_PREVIEW_ERRORS - count($errors);
            if ($remaining > 0) {
                array_push($errors, ...array_slice($result['errors'], 0, $remaining));
            }
        };

        foreach ($this->streamRows($path, $headers, $mapping) as $row) {
            $chunk[] = $row;
            $rowCount++;
            if (count($chunk) >= self::CHUNK_SIZE) {
                $consume($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $consume($chunk);
        }

        return [
            'row_count' => $rowCount,
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'errors' => $errors,
            'errors_truncated' => $invalidCount > count($errors),
        ];
    }
}
