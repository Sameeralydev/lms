<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\Phase04MySqlTestDataPolicy;
use Tests\Support\Phase04MySqlTestRunLock;
use Tests\Support\Phase04MySqlTestTarget;
use Tests\Support\Phase04OwnershipManifestPolicy;
use Tests\Support\Phase04SchemaSnapshotPolicy;
use Tests\Support\Phase04V2MigrationResumePolicy;
use Tests\Support\Phase04V2OwnershipResumePolicy;
use Tests\Support\Phase06MigrationUpgradePolicy;
use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase06OwnershipReconciliationPolicy;

final class Phase04TestSafetyLifecycleTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'database' => 'techmentors_lms_phase04_test',
            'username' => 'techmentors_phase04_test',
            'host' => '127.0.0.1',
            'server_version' => '8.4.11',
            'tables' => ['migrations'],
            'migrations' => [],
            'schema_fingerprint' => str_repeat('a', 64),
        ];
    }

    public function test_matching_ownership_evidence_has_no_differences(): void
    {
        $manifest = $this->manifest();

        self::assertSame([], Phase04OwnershipManifestPolicy::differences($manifest, $manifest));
    }

    public function test_ownership_manifest_tracks_per_table_schema_fingerprints(): void
    {
        $stored = $this->manifest() + ['table_schema_fingerprints' => ['academic_programs' => str_repeat('a', 64)]];
        self::assertSame([], Phase04OwnershipManifestPolicy::differences($stored, $stored));

        $changed = $stored;
        $changed['table_schema_fingerprints']['academic_programs'] = str_repeat('b', 64);
        self::assertSame(['table_schema_fingerprints'], Phase04OwnershipManifestPolicy::differences($stored, $changed));
        self::assertSame(['table_schema_fingerprints'], Phase04OwnershipManifestPolicy::differences($this->manifest(), $stored));
    }

    public function test_v2_uses_distinct_database_manifest_server_identity_and_locks(): void
    {
        $v1 = Phase04MySqlTestTarget::phase04();
        $v2 = Phase04MySqlTestTarget::phase04V2();

        self::assertSame('techmentors_lms_phase04_test', $v1->database);
        self::assertSame('techmentors_lms_phase04_v2_test', $v2->database);
        self::assertSame('techmentors_phase04_v2', $v2->account);
        self::assertNotSame($v1->manifestFilename, $v2->manifestFilename);
        self::assertNotSame($v1->runLockName, $v2->runLockName);
        self::assertNotSame($v1->fileLockFilename, $v2->fileLockFilename);
        self::assertTrue($v2->verifyServerIdentity);
        self::assertFalse($v1->verifyServerIdentity);
        self::assertStringContainsString('Explicit user authorization', $v2->disposableAuthorization);
    }

    public function test_phase05_uses_its_own_approved_database_manifest_locks_and_mysql_version_gate(): void
    {
        $phase04 = Phase04MySqlTestTarget::phase04V2();
        $phase05 = Phase04MySqlTestTarget::phase05();

        self::assertSame('techmentors_lms_phase05_test', $phase05->database);
        self::assertSame('techmentors_phase05_test', $phase05->account);
        self::assertNotSame($phase04->database, $phase05->database);
        self::assertNotSame($phase04->manifestFilename, $phase05->manifestFilename);
        self::assertNotSame($phase04->runLockName, $phase05->runLockName);
        self::assertNotSame($phase04->fileLockFilename, $phase05->fileLockFilename);
        self::assertTrue($phase05->verifyServerIdentity);
        self::assertSame('8.4.', $phase05->requiredServerVersionPrefix);
        self::assertStringContainsString('Explicit disposable Phase 05', $phase05->disposableAuthorization);
    }

    public function test_phase06_guarded_upgrade_accepts_only_one_trailing_local_migration(): void
    {
        $local = ['001_foundation', '002_curriculum', '003_completion_rules'];
        $before = [
            ['migration' => '001_foundation', 'batch' => 1],
            ['migration' => '002_curriculum', 'batch' => 1],
        ];
        $after = [...$before, ['migration' => '003_completion_rules', 'batch' => 2]];

        self::assertSame('003_completion_rules', Phase06MigrationUpgradePolicy::assertExactlyOnePending($before, $local));
        self::assertSame('003_completion_rules', Phase06MigrationUpgradePolicy::assertCompletedExactlyOne($before, $after, $local));

        foreach ([
            ['applied' => $before, 'local' => [...$local, '004_another']],
            ['applied' => [['migration' => '002_curriculum', 'batch' => 1]], 'local' => $local],
            ['applied' => [...$before, ['migration' => '001_unknown', 'batch' => 1]], 'local' => $local],
            ['applied' => [['migration' => '001_foundation', 'batch' => 2], ['migration' => '002_curriculum', 'batch' => 2]], 'local' => $local],
        ] as $invalid) {
            try {
                Phase06MigrationUpgradePolicy::assertExactlyOnePending($invalid['applied'], $invalid['local']);
                self::fail('Multiple pending, unknown, non-prefix, or separately batched migration states must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('exactly one trailing local migration', $exception->getMessage());
            }
        }

        foreach ([
            [...$before, ['migration' => '003_completion_rules', 'batch' => 3]],
            [...$before, ['migration' => '003_completion_rules', 'batch' => 1], ['migration' => '004_extra', 'batch' => 1]],
        ] as $invalidAfter) {
            try {
                Phase06MigrationUpgradePolicy::assertCompletedExactlyOne($before, $invalidAfter, $local);
                self::fail('A transition with a non-batch-one or unexpected migration ledger must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('did not apply exactly', $exception->getMessage());
            }
        }
    }

    public function test_phase06_guarded_upgrade_allows_only_reviewed_existing_table_changes(): void
    {
        $migration = '2026_10_26_000001_complete_phase06_governance_resources';
        $existingTables = [
            'academic_classes', 'academic_competencies', 'academic_competency_frameworks',
            'academic_curriculum_reviews', 'academic_curriculum_versions',
        ];
        $before = array_fill_keys($existingTables, str_repeat('a', 64));
        $after = $before;
        foreach (Phase06MigrationUpgradePolicy::alteredTables($migration) as $table) {
            $after[$table] = str_repeat('b', 64);
        }

        Phase06MigrationUpgradePolicy::assertOnlyDeclaredTableChanges($migration, $existingTables, $before, $after);

        $after['academic_classes'] = str_repeat('c', 64);
        $this->expectException(\RuntimeException::class);
        Phase06MigrationUpgradePolicy::assertOnlyDeclaredTableChanges($migration, $existingTables, $before, $after);
    }

    public function test_v2_manifest_requires_server_identity_and_object_inventory(): void
    {
        $stored = $this->manifest();
        $current = $stored + [
            'server_identity' => ['uuid' => 'server-a', 'hostname' => 'mysql-dev', 'port' => 3306],
            'object_inventory' => ['tables_and_views' => [], 'auxiliary' => []],
            'disposable_authorization' => 'Explicit user authorization in the Phase 04 V2 MySQL Recovery request',
        ];

        self::assertSame(
            ['server_identity', 'object_inventory', 'disposable_authorization'],
            Phase04OwnershipManifestPolicy::differences($stored, $current),
        );

        $stored = $current;
        $current['server_identity']['uuid'] = 'server-b';
        self::assertSame(['server_identity'], Phase04OwnershipManifestPolicy::differences($stored, $current));
    }

    public function test_v2_interrupted_resume_requires_the_original_empty_manifest_and_same_identity(): void
    {
        $current = [
            'database' => 'techmentors_lms_phase04_v2_test',
            'username' => 'techmentors_phase04_v2',
            'host' => '127.0.0.1',
            'server_version' => '8.4.11',
            'server_identity' => ['uuid' => 'server-a', 'hostname' => 'mysql-dev', 'port' => 3306],
        ];
        $authorization = 'explicit test authorization';
        $emptyManifest = $current + [
            'tables' => [],
            'migrations' => [],
            'schema_fingerprint' => str_repeat('a', 64),
            'disposable_authorization' => $authorization,
            'object_inventory' => ['tables_and_views' => [], 'auxiliary' => []],
        ];

        self::assertTrue(Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($emptyManifest, $current, $authorization));

        foreach (['database', 'username', 'host', 'server_version', 'server_identity', 'disposable_authorization'] as $field) {
            $tampered = $emptyManifest;
            $tampered[$field] = is_array($tampered[$field]) ? ['uuid' => 'other'] : 'wrong';
            self::assertFalse(Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($tampered, $current, $authorization), $field);
        }

        $notEmpty = $emptyManifest;
        $notEmpty['tables'] = ['users'];
        self::assertFalse(Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($notEmpty, $current, $authorization));
    }

    public function test_reports_exact_fields_changed_by_stale_or_tampered_manifest(): void
    {
        $stored = $this->manifest();
        $current = $stored;
        $current['tables'] = ['migrations', 'admission_guardian_authorizations'];
        $current['migrations'] = [['migration' => '2026_10_22_000010_create_admission_guardian_authorizations', 'batch' => 11]];

        self::assertSame(
            ['tables', 'migrations'],
            Phase04OwnershipManifestPolicy::differences($stored, $current),
        );

        $tampered = $stored;
        $tampered['database'] = 'techmentors_lms_dev';
        self::assertSame(['database'], Phase04OwnershipManifestPolicy::differences($stored, $tampered));

        $tampered = $stored;
        $tampered['approved'] = true;
        self::assertSame(['approved'], Phase04OwnershipManifestPolicy::differences($stored, $tampered));
    }

    public function test_missing_manifest_fields_are_reported_and_never_treated_as_owned(): void
    {
        $stored = $this->manifest();
        unset($stored['migrations']);

        self::assertSame(['migrations'], Phase04OwnershipManifestPolicy::differences($stored, $this->manifest()));
    }

    public function test_schema_structure_changes_are_ownership_differences(): void
    {
        $stored = $this->manifest();
        $current = $stored;
        $current['schema_fingerprint'] = str_repeat('b', 64);

        self::assertSame(['schema_fingerprint'], Phase04OwnershipManifestPolicy::differences($stored, $current));
    }

    public function test_schema_changes_between_read_only_snapshots_are_rejected(): void
    {
        $first = ['tables' => ['migrations'], 'schema_fingerprint' => str_repeat('a', 64)];
        Phase04SchemaSnapshotPolicy::assertStable($first, $first);

        try {
            Phase04SchemaSnapshotPolicy::assertStable($first, ['tables' => ['migrations', 'new_table'], 'schema_fingerprint' => str_repeat('b', 64)]);
            self::fail('A concurrent schema change must fail stable preflight.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('changed during read-only preflight', $exception->getMessage());
        }
    }

    public function test_incomplete_or_unknown_migration_inventories_are_rejected(): void
    {
        $applied = [
            ['migration' => '001_create_base', 'batch' => 1],
            ['migration' => '002_add_guardians', 'batch' => 1],
        ];

        Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($applied, ['001_create_base', '002_add_guardians']);

        try {
            Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory([$applied[0]], ['001_create_base', '002_add_guardians']);
            self::fail('An interrupted migration inventory must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('missing: 002_add_guardians', $exception->getMessage());
        }

        try {
            Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory([...$applied, ['migration' => '999_unknown', 'batch' => 2]], ['001_create_base', '002_add_guardians']);
            self::fail('Unknown migration rows must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unknown: 999_unknown', $exception->getMessage());
        }
    }

    public function test_only_a_strict_local_migration_prefix_is_eligible_for_v2_resume(): void
    {
        $expected = ['001_base', '002_academic', '003_guardian'];
        Phase04MySqlTestDataPolicy::assertAuthorizedMigrationPrefix([
            ['migration' => '001_base', 'batch' => 1],
            ['migration' => '002_academic', 'batch' => 1],
        ], $expected);

        foreach ([
            [['migration' => '002_academic', 'batch' => 1]],
            [
                ['migration' => '001_base', 'batch' => 1],
                ['migration' => '003_guardian', 'batch' => 1],
            ],
            [
                ['migration' => '001_base', 'batch' => 2],
                ['migration' => '002_academic', 'batch' => 3],
            ],
            array_map(static fn (string $migration): array => ['migration' => $migration, 'batch' => 1], $expected),
        ] as $invalidPrefix) {
            try {
                Phase04MySqlTestDataPolicy::assertAuthorizedMigrationPrefix($invalidPrefix, $expected);
                self::fail('Only a strict batch-one prefix can be resumed.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('exact interrupted prefix', $exception->getMessage());
            }
        }
    }

    public function test_v2_expected_object_inventory_is_derived_from_applied_migration_sources(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'phase04-migration-');
        $second = tempnam(sys_get_temp_dir(), 'phase04-migration-');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        file_put_contents($first, "<?php Schema::create('alpha_records', function () {});");
        file_put_contents($second, "<?php Schema::create('beta_records', function () {});");
        $applied = [
            ['migration' => '001_alpha', 'batch' => 1],
            ['migration' => '002_beta', 'batch' => 1],
        ];
        $migrationFiles = ['001_alpha' => $first, '002_beta' => $second];

        try {
            self::assertSame(
                ['alpha_records', 'beta_records', 'migrations'],
                Phase04MySqlTestDataPolicy::expectedCreatedTables($applied, $migrationFiles),
            );
            Phase04MySqlTestDataPolicy::assertExpectedTableInventory([
                ['name' => 'alpha_records', 'type' => 'BASE TABLE'],
                ['name' => 'beta_records', 'type' => 'BASE TABLE'],
                ['name' => 'migrations', 'type' => 'BASE TABLE'],
            ], $applied, $migrationFiles);

            foreach ([
                [
                    ['name' => 'alpha_records', 'type' => 'BASE TABLE'],
                    ['name' => 'beta_records', 'type' => 'BASE TABLE'],
                    ['name' => 'migrations', 'type' => 'BASE TABLE'],
                    ['name' => 'unexpected_table', 'type' => 'BASE TABLE'],
                ],
                [
                    ['name' => 'alpha_records', 'type' => 'BASE TABLE'],
                    ['name' => 'beta_records', 'type' => 'BASE TABLE'],
                    ['name' => 'migrations', 'type' => 'BASE TABLE'],
                    ['name' => 'extra_view', 'type' => 'VIEW'],
                ],
            ] as $invalidObjects) {
                try {
                    Phase04MySqlTestDataPolicy::assertExpectedTableInventory($invalidObjects, $applied, $migrationFiles);
                    self::fail('Unexpected schema objects must block V2 resume.');
                } catch (\RuntimeException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function test_v2_resume_accepts_only_the_known_empty_partial_guardian_creation(): void
    {
        $paths = glob(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($paths);
        $migrationFiles = [];
        foreach ($paths as $path) {
            $migrationFiles[basename($path, '.php')] = $path;
        }
        $applied = array_map(
            static fn (string $name): array => ['migration' => $name, 'batch' => 1],
            array_slice(array_keys($migrationFiles), 0, 13),
        );
        $objects = array_map(
            static fn (string $table): array => ['name' => $table, 'type' => 'BASE TABLE'],
            [...Phase04MySqlTestDataPolicy::expectedCreatedTables($applied, $migrationFiles), 'admission_guardian_authorizations'],
        );
        $columns = [
            ['id', 'bigint unsigned', 'NO', null, 'auto_increment'], ['tenant_id', 'bigint unsigned', 'NO', null, ''],
            ['application_id', 'bigint unsigned', 'NO', null, ''], ['applicant_user_id', 'bigint unsigned', 'NO', null, ''],
            ['guardian_user_id', 'bigint unsigned', 'YES', null, ''], ['guardian_name', 'varchar(180)', 'NO', null, ''],
            ['guardian_email', 'varchar(254)', 'NO', null, ''], ['relationship', 'varchar(48)', 'NO', null, ''],
            ['token_hash', 'char(64)', 'YES', null, ''], ['status', 'varchar(32)', 'NO', 'invited', ''],
            ['expires_at', 'datetime', 'YES', null, ''], ['email_verified_at', 'datetime', 'YES', null, ''],
            ['relationship_verified_by', 'bigint unsigned', 'YES', null, ''], ['relationship_verified_at', 'datetime', 'YES', null, ''],
            ['verification_method', 'varchar(32)', 'YES', null, ''], ['revoked_by', 'bigint unsigned', 'YES', null, ''],
            ['revoked_at', 'datetime', 'YES', null, ''], ['created_at', 'timestamp', 'YES', null, ''],
            ['updated_at', 'timestamp', 'YES', null, ''],
        ];
        $schema = [
            'rows' => 0,
            'columns' => array_map(static fn (array $column): array => [
                'COLUMN_NAME' => $column[0], 'COLUMN_TYPE' => $column[1], 'IS_NULLABLE' => $column[2],
                'COLUMN_DEFAULT' => $column[3], 'EXTRA' => $column[4],
            ], $columns),
            'indexes' => [
                ['INDEX_NAME' => 'admission_guardian_authorizations_applicant_user_id_foreign', 'NON_UNIQUE' => 1, 'columns_list' => 'applicant_user_id'],
                ['INDEX_NAME' => 'admission_guardian_authorizations_guardian_user_id_foreign', 'NON_UNIQUE' => 1, 'columns_list' => 'guardian_user_id'],
                ['INDEX_NAME' => 'admission_guardian_authorizations_tenant_id_foreign', 'NON_UNIQUE' => 1, 'columns_list' => 'tenant_id'],
                ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'columns_list' => 'id'],
            ],
            'foreign_keys' => [
                ['CONSTRAINT_NAME' => 'admission_guardian_authorizations_applicant_user_id_foreign', 'columns_list' => 'applicant_user_id', 'REFERENCED_TABLE_NAME' => 'users', 'referenced_columns' => 'id'],
                ['CONSTRAINT_NAME' => 'admission_guardian_authorizations_guardian_user_id_foreign', 'columns_list' => 'guardian_user_id', 'REFERENCED_TABLE_NAME' => 'users', 'referenced_columns' => 'id'],
                ['CONSTRAINT_NAME' => 'admission_guardian_authorizations_tenant_id_foreign', 'columns_list' => 'tenant_id', 'REFERENCED_TABLE_NAME' => 'tenants', 'referenced_columns' => 'id'],
            ],
        ];

        Phase04V2MigrationResumePolicy::assertKnownGuardianMigrationPrefix($objects, $applied, $migrationFiles, $schema);

        $withExtraTable = [...$objects, ['name' => 'unexpected_table', 'type' => 'BASE TABLE']];
        try {
            Phase04V2MigrationResumePolicy::assertKnownGuardianMigrationPrefix($withExtraTable, $applied, $migrationFiles, $schema);
            self::fail('An extra V2 schema table must prevent interrupted migration resume.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unexpected schema objects', $exception->getMessage());
        }

        $withRows = $schema;
        $withRows['rows'] = 1;
        try {
            Phase04V2MigrationResumePolicy::assertKnownGuardianMigrationPrefix($objects, $applied, $migrationFiles, $withRows);
            self::fail('A partially created guardian table with rows must not be resumed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('contains rows', $exception->getMessage());
        }

        $withExtraIndex = $schema;
        $withExtraIndex['indexes'][] = ['INDEX_NAME' => 'unexpected', 'NON_UNIQUE' => 1, 'columns_list' => 'status'];
        try {
            Phase04V2MigrationResumePolicy::assertKnownGuardianMigrationPrefix($objects, $applied, $migrationFiles, $withExtraIndex);
            self::fail('An unexpected partial table index must not be resumed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('indexes differ', $exception->getMessage());
        }
    }

    public function test_only_exact_migration_owned_permission_and_bootstrap_rows_are_accepted(): void
    {
        $permissionKeys = Phase04MySqlTestDataPolicy::migrationOwnedPermissionKeys();
        $permissions = array_map(static fn (string $key): array => ['key' => $key, 'scope' => 'tenant'], $permissionKeys);
        $tables = ['migrations', 'permissions', 'platform_bootstrap_states', 'tenants'];
        $counts = ['migrations' => 29, 'permissions' => count($permissionKeys), 'platform_bootstrap_states' => 1, 'tenants' => 0];
        $bootstrap = [['id' => 1, 'completed' => 0, 'completed_at' => null]];

        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($tables, $counts, $permissions, $bootstrap);

        try {
            Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($tables, [...$counts, 'tenants' => 1], $permissions, $bootstrap);
            self::fail('Persistent tenant fixtures must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('persistent non-migration rows', $exception->getMessage());
        }

        array_pop($permissions);
        try {
            Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($tables, [...$counts, 'permissions' => count($permissionKeys) - 1], $permissions, $bootstrap);
            self::fail('A partial permission baseline must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('permission rows do not match', $exception->getMessage());
        }
    }

    public function test_phase05_ownership_reconciliation_requires_a_complete_single_batch(): void
    {
        $applied = [
            ['migration' => '001_foundation', 'batch' => 1],
            ['migration' => '002_sis', 'batch' => 1],
        ];
        Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($applied);

        foreach ([
            [],
            [['migration' => '001_foundation', 'batch' => 2]],
            [['migration' => '001_foundation', 'batch' => 1], ['migration' => '002_sis', 'batch' => 2]],
        ] as $invalid) {
            try {
                Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($invalid);
                self::fail('Incomplete or separately batched migrations must not be ownership-reconciled.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('complete batch numbered one', $exception->getMessage());
            }
        }
    }

    public function test_phase06_interrupted_migration_state_can_only_be_reconciled_from_owned_empty_manifest_and_exact_baseline(): void
    {
        $migrationName = '001_create_foundation';
        $migrationPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase06-migration-'.bin2hex(random_bytes(8)).'.php';
        file_put_contents($migrationPath, "<?php Schema::create('permissions', fn () => null); Schema::create('platform_bootstrap_states', fn () => null); Schema::create('tenants', fn () => null);");
        $migrationFiles = [$migrationName => $migrationPath];
        $identity = [
            'database' => Phase06MySqlTestTarget::DATABASE,
            'username' => Phase06MySqlTestTarget::ACCOUNT,
            'host' => '127.0.0.1',
            'server_version' => '8.4.11',
            'server_identity' => ['uuid' => 'phase06-server', 'hostname' => 'mysql-test', 'port' => 3306],
        ];
        $snapshot = $identity + [
            'tables' => ['migrations', 'permissions', 'platform_bootstrap_states', 'tenants'],
            'migrations' => [['migration' => $migrationName, 'batch' => 1]],
            'schema_fingerprint' => str_repeat('b', 64),
            'object_inventory' => ['tables_and_views' => array_map(static fn (string $name): array => ['name' => $name, 'type' => 'BASE TABLE'], ['migrations', 'permissions', 'platform_bootstrap_states', 'tenants']), 'auxiliary' => []],
        ];
        $manifest = $identity + [
            'tables' => [],
            'migrations' => [],
            'schema_fingerprint' => str_repeat('a', 64),
            'disposable_authorization' => Phase06MySqlTestTarget::AUTHORIZATION,
            'object_inventory' => ['tables_and_views' => [], 'auxiliary' => []],
        ];
        $rowCounts = ['migrations' => 1, 'permissions' => 64, 'platform_bootstrap_states' => 1, 'tenants' => 0];
        $permissions = array_map(static fn (string $key): array => ['key' => $key, 'scope' => 'tenant'], Phase04MySqlTestDataPolicy::migrationOwnedPermissionKeys());
        $bootstrap = [['id' => 1, 'completed' => 0, 'completed_at' => null]];

        try {
            Phase06OwnershipReconciliationPolicy::assertAuthorizedInterruptedRun(
                $manifest, $snapshot, Phase06MySqlTestTarget::AUTHORIZATION, [$migrationName], $migrationFiles,
                $rowCounts, $permissions, $bootstrap,
            );

            foreach ([
                ['manifest' => [...$manifest, 'database' => 'techmentors_lms_dev']],
                ['snapshot' => [...$snapshot, 'migrations' => []]],
                ['snapshot' => [...$snapshot, 'object_inventory' => ['tables_and_views' => [...$snapshot['object_inventory']['tables_and_views'], ['name' => 'unexpected', 'type' => 'BASE TABLE']], 'auxiliary' => []]]],
                ['row_counts' => [...$rowCounts, 'tenants' => 1]],
                ['permissions' => array_slice($permissions, 1)],
            ] as $mutation) {
                try {
                    Phase06OwnershipReconciliationPolicy::assertAuthorizedInterruptedRun(
                        $mutation['manifest'] ?? $manifest,
                        $mutation['snapshot'] ?? $snapshot,
                        Phase06MySqlTestTarget::AUTHORIZATION,
                        [$migrationName],
                        $migrationFiles,
                        $mutation['row_counts'] ?? $rowCounts,
                        $mutation['permissions'] ?? $permissions,
                        $bootstrap,
                    );
                    self::fail('Tampered identity, incomplete migration state, unexpected schema, or unauthorized rows must be rejected.');
                } catch (\RuntimeException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        } finally {
            @unlink($migrationPath);
        }
    }

    public function test_lock_rejects_a_second_overlapping_runner_and_releases_cleanly(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase04-run-lock-'.bin2hex(random_bytes(12));
        $first = Phase04MySqlTestRunLock::acquireAtPath($path);

        try {
            try {
                Phase04MySqlTestRunLock::acquireAtPath($path);
                self::fail('A competing guarded runner must not acquire the same lock.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Another guarded Phase 04 MySQL test run is active', $exception->getMessage());
            }
        } finally {
            $first->release();
        }

        $released = Phase04MySqlTestRunLock::acquireAtPath($path);
        $released->release();
        @unlink($path);
    }
}
