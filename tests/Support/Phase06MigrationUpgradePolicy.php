<?php

namespace Tests\Support;

use RuntimeException;

/** Allows one explicitly reviewed trailing Phase 06 migration under an owned schema. */
final class Phase06MigrationUpgradePolicy
{
    private const GOVERNANCE_MIGRATION = '2026_10_26_000001_complete_phase06_governance_resources';

    /** @var list<string> */
    private const GOVERNANCE_ALTERED_TABLES = [
        'academic_competencies',
        'academic_competency_frameworks',
        'academic_curriculum_reviews',
        'academic_curriculum_versions',
    ];

    /** @return list<string> */
    public static function alteredTables(string $migration): array
    {
        if ($migration !== self::GOVERNANCE_MIGRATION) {
            throw new RuntimeException('The Phase 06 migration has no reviewed existing-table alteration contract.');
        }

        return self::GOVERNANCE_ALTERED_TABLES;
    }

    /**
     * @param  list<string>  $existingTables
     * @param  array<string,string>  $beforeFingerprints
     * @param  array<string,string>  $afterFingerprints
     */
    public static function assertOnlyDeclaredTableChanges(string $migration, array $existingTables, array $beforeFingerprints, array $afterFingerprints): void
    {
        $expectedAltered = array_values(array_intersect(self::alteredTables($migration), $existingTables));
        $changed = [];
        foreach ($existingTables as $table) {
            if (! array_key_exists($table, $beforeFingerprints) || ! array_key_exists($table, $afterFingerprints)) {
                throw new RuntimeException('The Phase 06 schema transition is missing an existing-table fingerprint.');
            }
            if (! hash_equals($beforeFingerprints[$table], $afterFingerprints[$table])) {
                $changed[] = $table;
            }
        }
        sort($expectedAltered);
        sort($changed);
        if ($changed !== $expectedAltered) {
            throw new RuntimeException('The Phase 06 migration changed a table outside its reviewed alteration contract.');
        }
    }

    /** @return array<string,array{columns:list<string>,foreign_keys:array<string,array{columns:list<string>,table:string,referenced_columns:list<string>}>}> */
    public static function requiredSchemaExtensions(string $migration): array
    {
        if ($migration !== self::GOVERNANCE_MIGRATION) {
            throw new RuntimeException('The Phase 06 migration has no reviewed schema extension contract.');
        }

        return [
            'academic_curriculum_reviews' => [
                'columns' => ['checklist_snapshot', 'checklist_responses', 'policy_version_id', 'review_round'],
                'foreign_keys' => ['curr_review_policy_fk' => ['columns' => ['tenant_id', 'policy_version_id'], 'table' => 'academic_curriculum_governance_policies', 'referenced_columns' => ['tenant_id', 'id']]],
            ],
            'academic_curriculum_versions' => ['columns' => ['review_round'], 'foreign_keys' => []],
            'academic_competency_frameworks' => [
                'columns' => ['based_on_framework_id', 'owner_user_id', 'submitted_by', 'reviewer_user_id', 'published_by', 'submitted_at', 'published_at', 'lock_version', 'review_round', 'is_shared_template'],
                'foreign_keys' => ['comp_frame_base_fk' => ['columns' => ['tenant_id', 'based_on_framework_id'], 'table' => 'academic_competency_frameworks', 'referenced_columns' => ['tenant_id', 'id']]],
            ],
            'academic_competencies' => ['columns' => ['category', 'mastery_levels', 'evidence_requirements'], 'foreign_keys' => []],
        ];
    }

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $local
     */
    public static function assertExactlyOnePending(array $applied, array $local): string
    {
        $names = array_column($applied, 'migration');
        $batches = array_map(static fn (array $row): int => (int) $row['batch'], $applied);
        $uniqueBatches = array_values(array_unique($batches));
        sort($uniqueBatches);
        if ($local === []
            || count($names) !== count($local) - 1
            || count($names) !== count(array_unique($names))
            || array_slice($local, 0, count($names)) !== $names
            || $applied === []
            || min($batches) !== 1
            || $uniqueBatches !== range(1, max($uniqueBatches))
            || self::hasDecreasingBatch($batches)) {
            throw new RuntimeException('A Phase 06 guarded upgrade requires a contiguous owned migration ledger and exactly one trailing local migration.');
        }

        return $local[array_key_last($local)];
    }

    /** @param list<array{migration:string,batch:int}> $before
     * @param  list<array{migration:string,batch:int}>  $after
     * @param  list<string>  $local
     */
    public static function assertCompletedExactlyOne(array $before, array $after, array $local): string
    {
        $migration = self::assertExactlyOnePending($before, $local);
        $afterNames = array_column($after, 'migration');
        $beforeCount = count($before);
        $lastBatch = max(array_column($before, 'batch'));
        if ($afterNames !== $local
            || count($afterNames) !== count(array_unique($afterNames))
            || array_slice($after, 0, $beforeCount) !== $before
            || (int) ($after[$beforeCount]['batch'] ?? 0) !== $lastBatch + 1
            || count($after) !== $beforeCount + 1) {
            throw new RuntimeException('The Phase 06 guarded upgrade did not apply exactly its one authorized trailing migration.');
        }

        return $migration;
    }

    /** @param list<int> $batches */
    private static function hasDecreasingBatch(array $batches): bool
    {
        $previous = 0;
        foreach ($batches as $batch) {
            if ($batch < $previous) {
                return true;
            }
            $previous = $batch;
        }

        return false;
    }
}
