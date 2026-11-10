<?php

namespace App\Domain\Academic\Curriculum;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCurriculumImportBatch;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Bounded, version-safe curriculum outline import. It never accepts external IDs. */
final class CurriculumImportService
{
    private const MAX_ROWS = 500;

    private const MAX_BYTES = 2_097_152;

    private const CHILD_TYPES = [
        'course' => ['unit', 'topic', 'objective', 'lesson'],
        'unit' => ['topic', 'subtopic', 'objective', 'lesson'],
        'topic' => ['subtopic', 'objective', 'lesson'],
        'subtopic' => ['objective', 'lesson'],
        'objective' => [],
        'lesson' => [],
    ];

    private const COLUMNS = [
        'code', 'parent_code', 'type', 'title', 'description', 'is_required', 'estimated_minutes',
        'workload_unit', 'period_code', 'planned_starts_on', 'planned_ends_on',
    ];

    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @return array{format:string,rows:list<array<string,mixed>>,errors:list<array{row:int,errors:list<string>}>,valid_count:int,total_count:int} */
    public function preview(UploadedFile $file, AcademicCurriculumVersion $version): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'The curriculum import is limited to 2 MB.']);
        }

        $format = strtolower($file->getClientOriginalExtension());
        $rows = match ($format) {
            'csv' => $this->readCsv($file),
            'json' => $this->readJson($file),
            default => throw ValidationException::withMessages(['file' => 'Choose a CSV or version 1 JSON curriculum file.']),
        };
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'An import may contain at most 500 syllabus items.']);
        }

        return $this->validateRows($rows, $version, $format);
    }

    public function import(UploadedFile $file, AcademicCurriculumVersion $version, int $expectedLock, User $actor): int
    {
        $preview = $this->preview($file, $version);

        return DB::transaction(function () use ($preview, $version, $expectedLock, $actor): int {
            $locked = AcademicCurriculumVersion::query()->with('curriculum')->lockForUpdate()->findOrFail($version->getKey());
            if (! in_array($locked->status, ['draft', 'changes_requested'], true) || $locked->lock_version !== $expectedLock) {
                throw new ConflictHttpException('The curriculum draft changed after the import preview. Refresh and preview the file again.');
            }

            return $this->persistPreview($preview, $locked, $actor);
        }, 3);
    }

    public function importBatch(int $batchId, User $actor): int
    {
        return DB::transaction(function () use ($batchId, $actor): int {
            $batch = AcademicCurriculumImportBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($batch->status === 'completed') {
                return (int) $batch->imported_count;
            }
            if ($batch->status !== 'processing' || (int) $batch->requested_by_user_id !== (int) $actor->getKey()) {
                throw new ConflictHttpException('The curriculum import batch is no longer eligible for processing.');
            }

            $version = AcademicCurriculumVersion::query()->with('curriculum')->lockForUpdate()->findOrFail($batch->curriculum_version_id);
            if ((int) $version->tenant_id !== (int) $batch->tenant_id
                || (int) $version->curriculum_id !== (int) $batch->curriculum_id
                || ! in_array($version->status, ['draft', 'changes_requested'], true)
                || (int) $version->lock_version !== (int) $batch->expected_lock_version) {
                throw new ConflictHttpException('The curriculum draft changed while this import was queued. Run a new dry-run.');
            }

            $preview = $batch->payload;
            if (! in_array($preview['format'] ?? null, ['csv', 'json'], true)
                || ($preview['errors'] ?? null) !== []
                || ! is_array($preview['rows'] ?? null)
                || count($preview['rows']) !== (int) $batch->total_count
                || (int) ($preview['valid_count'] ?? -1) !== (int) $batch->total_count) {
                throw new ConflictHttpException('The persisted curriculum import payload failed integrity validation.');
            }

            $count = $this->persistPreview($preview, $version, $actor);
            $batch->forceFill([
                'status' => 'completed', 'processed_count' => $count, 'imported_count' => $count,
                'payload' => null, 'failure_code' => null, 'completed_at' => now(),
            ])->save();

            return $count;
        }, 3);
    }

    /** @param array{format:string,rows:list<array<string,mixed>>,errors:list<array{row:int,errors:list<string>}>,valid_count:int,total_count:int} $preview */
    private function persistPreview(array $preview, AcademicCurriculumVersion $locked, User $actor): int
    {
        if ($preview['errors'] !== []) {
            throw ValidationException::withMessages(['file' => 'The import has validation errors. Review the latest dry-run report before confirming.']);
        }
        if (AcademicCurriculumNode::query()->where('curriculum_version_id', $locked->getKey())->count() + $preview['valid_count'] > 2000) {
            throw ValidationException::withMessages(['file' => 'This import would exceed the 2,000-item curriculum version limit.']);
        }

        $createdIds = [];
        $pending = $preview['rows'];
        $siblingCounts = DB::table('academic_curriculum_nodes')
            ->where('tenant_id', $this->context->tenant()->getKey())
            ->where('curriculum_version_id', $locked->getKey())
            ->selectRaw('COALESCE(parent_id, 0) AS parent_key, COUNT(*) AS sibling_count')
            ->groupBy('parent_key')
            ->pluck('sibling_count', 'parent_key')
            ->map(static fn (int|string $count): int => (int) $count)
            ->all();
        while ($pending !== []) {
            $madeProgress = false;
            foreach ($pending as $index => $row) {
                $parentId = $row['parent_existing_id'] ?? null;
                if (($row['parent_import_code'] ?? null) !== null) {
                    $key = mb_strtolower((string) $row['parent_import_code']);
                    if (! isset($createdIds[$key])) {
                        continue;
                    }
                    $parentId = $createdIds[$key];
                }

                $parentKey = (string) ($parentId ?? 0);
                $siblingCounts[$parentKey] = ($siblingCounts[$parentKey] ?? 0) + 1;
                $node = AcademicCurriculumNode::query()->create([
                    'institution_id' => $locked->curriculum->institution_id,
                    'curriculum_version_id' => $locked->getKey(),
                    'parent_id' => $parentId,
                    'academic_period_id' => $row['academic_period_id'] ?? null,
                    'node_type' => $row['type'],
                    'code' => $row['code'] ?? null,
                    'title' => $row['title'],
                    'description' => $row['description'] ?? null,
                    'sort_order' => $siblingCounts[$parentKey] * 10,
                    'is_required' => (bool) $row['is_required'],
                    'estimated_minutes' => $row['estimated_minutes'] ?? null,
                    'workload_unit' => $row['workload_unit'],
                    'planned_starts_on' => $row['planned_starts_on'] ?? null,
                    'planned_ends_on' => $row['planned_ends_on'] ?? null,
                ]);
                if (($row['code'] ?? null) !== null) {
                    $createdIds[mb_strtolower((string) $row['code'])] = (int) $node->getKey();
                }
                unset($pending[$index]);
                $madeProgress = true;
            }
            if (! $madeProgress) {
                throw new ConflictHttpException('The imported hierarchy could not be resolved safely. No items were written.');
            }
        }

        $locked->increment('lock_version');
        $this->audit->record('academic.curriculum.imported', $actor, (int) $this->context->tenant()->getKey(), $locked, [
            'format' => $preview['format'], 'item_count' => $preview['valid_count'],
        ]);

        return $preview['valid_count'];
    }

    /** @return list<mixed> */
    private function readCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || ($handle = fopen($path, 'rb')) === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV could not be read.']);
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['file' => 'The CSV needs a header row.']);
            }
            $headers = array_map(static fn ($header): string => trim((string) $header), $headers);
            if (count($headers) !== count(array_unique($headers)) || array_diff($headers, self::COLUMNS) !== [] || ! in_array('type', $headers, true) || ! in_array('title', $headers, true)) {
                throw ValidationException::withMessages(['file' => 'CSV headers must use the supported template columns, with type and title required.']);
            }

            $rows = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    $rows[] = ['__shape_error' => 'The CSV row does not have the same number of cells as the header.'];

                    continue;
                }
                $rows[] = array_combine($headers, $values);
                if (count($rows) > self::MAX_ROWS) {
                    break;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<mixed> */
    private function readJson(UploadedFile $file): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($file->getRealPath()), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The JSON document is invalid or too deeply nested.']);
        }
        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1 || ! is_array($decoded['nodes'] ?? null) || array_diff(array_keys($decoded), ['schema_version', 'nodes']) !== []) {
            throw ValidationException::withMessages(['file' => 'JSON must contain only schema_version: 1 and a nodes array.']);
        }

        return array_is_list($decoded['nodes']) ? $decoded['nodes'] : throw ValidationException::withMessages(['file' => 'The JSON nodes value must be an array.']);
    }

    /** @param list<mixed> $inputRows
     * @return array{format:string,rows:list<array<string,mixed>>,errors:list<array{row:int,errors:list<string>}>,valid_count:int,total_count:int}
     */
    private function validateRows(array $inputRows, AcademicCurriculumVersion $version, string $format): array
    {
        $version->loadMissing('curriculum');
        $existingNodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->get(['id', 'parent_id', 'node_type', 'code']);
        $existingNodesById = $existingNodes->keyBy('id');
        $existingCodeMap = [];
        foreach ($existingNodes as $node) {
            if ($node->code !== null && $node->code !== '') {
                $existingCodeMap[mb_strtolower($node->code)][] = ['id' => (int) $node->getKey(), 'type' => $node->node_type, 'depth' => $this->existingDepth($node, $existingNodesById)];
            }
        }

        $periods = $version->curriculum->academic_year_id === null
            ? collect()
            : AcademicPeriod::query()->where('academic_year_id', $version->curriculum->academic_year_id)->get(['id', 'code', 'name']);
        $periodMap = $periods->keyBy(fn (AcademicPeriod $period): string => mb_strtolower($period->code));
        $normal = [];
        $errors = [];
        $codes = [];
        $titles = [];

        foreach ($inputRows as $offset => $row) {
            $line = $offset + ($format === 'csv' ? 2 : 1);
            $issues = [];
            if (! is_array($row)) {
                $errors[] = ['row' => $line, 'errors' => ['Each JSON node must be an object.']];

                continue;
            }
            if (isset($row['__shape_error'])) {
                $errors[] = ['row' => $line, 'errors' => [(string) $row['__shape_error']]];

                continue;
            }
            if (array_diff(array_keys($row), self::COLUMNS) !== []) {
                $issues[] = 'The row contains unsupported fields.';
            }
            foreach (['code', 'parent_code', 'type', 'title', 'description', 'workload_unit', 'period_code'] as $stringField) {
                if (array_key_exists($stringField, $row) && $row[$stringField] !== null && ! is_string($row[$stringField])) {
                    $issues[] = $stringField.' must be text.';
                }
            }

            $type = is_string($row['type'] ?? null) ? trim($row['type']) : '';
            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $code = is_string($row['code'] ?? null) && trim($row['code']) !== '' ? trim($row['code']) : null;
            $parentCode = is_string($row['parent_code'] ?? null) && trim($row['parent_code']) !== '' ? trim($row['parent_code']) : null;
            $description = is_string($row['description'] ?? null) && trim($row['description']) !== '' ? trim($row['description']) : null;
            if (! array_key_exists($type, self::CHILD_TYPES) || $title === '' || mb_strlen($title) > 180) {
                $issues[] = 'Provide a supported item type and a title of 1 to 180 characters.';
            }
            if ($code !== null && (mb_strlen($code) > 64 || ! preg_match('/^[A-Za-z0-9._-]+$/', $code))) {
                $issues[] = 'Codes may contain only letters, numbers, dots, underscores, and hyphens (64 characters maximum).';
            }
            if ($description !== null && mb_strlen($description) > 5000) {
                $issues[] = 'Descriptions may contain at most 5,000 characters.';
            }
            $codeKey = $code === null ? null : mb_strtolower($code);
            if ($codeKey !== null && (isset($codes[$codeKey]) || isset($existingCodeMap[$codeKey]))) {
                $issues[] = 'This code is duplicated in the file or already used in this curriculum version.';
            }
            if ($codeKey !== null) {
                $codes[$codeKey] = $offset;
            }

            $required = $this->parseBoolean($row['is_required'] ?? 'true');
            if ($required === null) {
                $issues[] = 'is_required must be true or false.';
                $required = true;
            }
            $minutes = null;
            if (($row['estimated_minutes'] ?? '') !== '' && ($row['estimated_minutes'] ?? null) !== null) {
                $minutes = filter_var($row['estimated_minutes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
                if ($minutes === false) {
                    $issues[] = 'estimated_minutes must be an integer between 1 and 100,000.';
                    $minutes = null;
                }
            }
            $unit = is_string($row['workload_unit'] ?? null) && trim($row['workload_unit']) !== '' ? trim($row['workload_unit']) : 'minutes';
            if (! in_array($unit, ['minutes', 'hours', 'credits', 'sessions', 'weeks', 'competency'], true)) {
                $issues[] = 'workload_unit is unsupported.';
            }

            $periodCode = is_string($row['period_code'] ?? null) && trim($row['period_code']) !== '' ? trim($row['period_code']) : null;
            $period = $periodCode === null ? null : $periodMap->get(mb_strtolower($periodCode));
            if ($periodCode !== null && $period === null) {
                $issues[] = 'period_code must identify a period in this curriculum academic year.';
            }

            $start = $this->dateValue($row['planned_starts_on'] ?? null, $issues, 'planned_starts_on');
            $end = $this->dateValue($row['planned_ends_on'] ?? null, $issues, 'planned_ends_on');
            if ($start !== null && $end !== null && $end < $start) {
                $issues[] = 'planned_ends_on must be on or after planned_starts_on.';
            }

            $parentKey = $parentCode === null ? null : mb_strtolower($parentCode);
            $normal[] = [
                'row' => $line, 'code' => $code, 'code_key' => $codeKey, 'parent_code' => $parentCode,
                'parent_key' => $parentKey, 'parent_existing_id' => null, 'parent_import_code' => null,
                'type' => $type, 'title' => $title, 'description' => $description, 'is_required' => $required,
                'estimated_minutes' => $minutes, 'workload_unit' => $unit, 'academic_period_id' => $period?->getKey(),
                'planned_starts_on' => $start, 'planned_ends_on' => $end,
            ];
            if ($issues !== []) {
                $errors[] = ['row' => $line, 'errors' => array_values(array_unique($issues))];
            }
            $titleKey = ($parentKey ?? '<root>').'|'.mb_strtolower($title);
            if (isset($titles[$titleKey])) {
                $errors[] = ['row' => $line, 'errors' => ['This title duplicates another item at the same parent level.']];
            }
            $titles[$titleKey] = true;
        }

        $rowByCode = [];
        foreach ($normal as $index => $row) {
            if ($row['code_key'] !== null) {
                $rowByCode[$row['code_key']] = $index;
            }
        }
        $state = [];
        $resolve = function (int $index, array $stack = []) use (&$resolve, &$normal, &$state, &$errors, $rowByCode, $existingCodeMap): array {
            if (($state[$index] ?? 0) === 2) {
                return $normal[$index]['resolved_parent'];
            }
            if (($state[$index] ?? 0) === 1) {
                $cycleStart = array_search($index, $stack, true);
                $members = $cycleStart === false ? [$index] : array_slice($stack, $cycleStart);
                foreach (array_unique($members) as $member) {
                    $errors[] = ['row' => $normal[$member]['row'], 'errors' => ['The parent references create a hierarchy cycle.']];
                }

                return ['type' => null, 'depth' => 0, 'id' => null, 'import_code' => null];
            }
            $state[$index] = 1;
            $parentKey = $normal[$index]['parent_key'];
            if ($parentKey === null) {
                $parent = ['type' => null, 'depth' => -1, 'id' => null, 'import_code' => null];
            } elseif (isset($rowByCode[$parentKey])) {
                $parentIndex = $rowByCode[$parentKey];
                $parent = $resolve($parentIndex, [...$stack, $index]);
                if ($parent['id'] === null) {
                    if ($normal[$parentIndex]['code'] === null) {
                        $errors[] = ['row' => $normal[$index]['row'], 'errors' => ['An imported parent referenced by code must itself have a code.']];
                    } else {
                        $parent['import_code'] = $normal[$parentIndex]['code'];
                    }
                }
            } elseif (count($existingCodeMap[$parentKey] ?? []) === 1) {
                $existing = $existingCodeMap[$parentKey][0];
                $parent = ['type' => $existing['type'], 'depth' => $existing['depth'], 'id' => $existing['id'], 'import_code' => null];
            } else {
                $parent = ['type' => null, 'depth' => 0, 'id' => null, 'import_code' => null];
                $errors[] = ['row' => $normal[$index]['row'], 'errors' => ['parent_code must refer to one existing item or one coded item in this import.']];
            }

            if ($parent['type'] === null && ! in_array($normal[$index]['type'], ['course', 'unit'], true)) {
                $errors[] = ['row' => $normal[$index]['row'], 'errors' => ['Only a course or unit can be a top-level curriculum item.']];
            } elseif ($parent['type'] !== null && ! in_array($normal[$index]['type'], self::CHILD_TYPES[$parent['type']] ?? [], true)) {
                $errors[] = ['row' => $normal[$index]['row'], 'errors' => ['This item type is not valid under the selected parent.']];
            }
            if ($parent['type'] !== null && $parent['depth'] >= 5) {
                $errors[] = ['row' => $normal[$index]['row'], 'errors' => ['Curriculum hierarchy depth is limited to six levels.']];
            }

            $normal[$index]['parent_existing_id'] = $parent['id'];
            $normal[$index]['parent_import_code'] = $parent['import_code'];
            $normal[$index]['resolved_parent'] = ['type' => $normal[$index]['type'], 'depth' => $parent['depth'] + 1, 'id' => null, 'import_code' => null];
            $state[$index] = 2;

            return $normal[$index]['resolved_parent'];
        };

        foreach (array_keys($normal) as $index) {
            $resolve($index);
        }
        foreach ($normal as &$row) {
            unset($row['code_key'], $row['parent_key'], $row['resolved_parent']);
        }
        unset($row);

        $uniqueErrors = $this->deduplicateErrors($errors);
        $invalidLines = array_unique(array_column($uniqueErrors, 'row'));

        return [
            'format' => $format,
            'rows' => $normal,
            'errors' => $uniqueErrors,
            'valid_count' => count($inputRows) - count($invalidLines),
            'total_count' => count($inputRows),
        ];
    }

    /** @param Collection<int|string,AcademicCurriculumNode> $nodes */
    private function existingDepth(AcademicCurriculumNode $node, Collection $nodes): int
    {
        $depth = 0;
        $parent = $node->parent_id;
        while ($parent !== null && $depth <= 6) {
            $depth++;
            $parent = $nodes->get($parent)?->parent_id;
        }

        return $depth;
    }

    /** @param list<string> $issues */
    private function dateValue(mixed $value, array &$issues, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $issues[] = $name.' must use a valid YYYY-MM-DD date.';

            return null;
        }

        return $value;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || (is_string($value) && mb_strtolower(trim($value)) === 'true')) {
            return true;
        }
        if ($value === 0 || $value === '0' || (is_string($value) && mb_strtolower(trim($value)) === 'false')) {
            return false;
        }

        return null;
    }

    /** @param list<array{row:int,errors:list<string>}> $errors
     * @return list<array{row:int,errors:list<string>}>
     */
    private function deduplicateErrors(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error['row'].'|'.implode('|', $error['errors'])] = $error;
        }

        return array_values($unique);
    }

    /** @return array{format:string,rows:list<array<string,mixed>>,errors:list<array{row:int,errors:list<string>}>,valid_count:int,total_count:int} */
    public static function emptyPreview(): array
    {
        return ['format' => '', 'rows' => [], 'errors' => [], 'valid_count' => 0, 'total_count' => 0];
    }
}
