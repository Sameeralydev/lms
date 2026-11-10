<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase08MigrationRecoveryPolicy as Policy;
use Tests\Support\Phase08MySqlTestTarget as Target;

final class Phase08MigrationRecoveryPolicyTest extends TestCase
{
    public function test_clean_initial_schema_is_required_before_intent_creation_and_intent_is_bound_to_identity(): void
    {
        Policy::assertInitialEmpty([], []);
        $identity = ['database' => Target::DATABASE, 'account' => Target::ACCOUNT.'@127.0.0.1', 'server_uuid' => 'verified-server-uuid'];
        $intent = Policy::createIntent($identity, Policy::INITIAL_FINGERPRINT);
        Policy::assertIntent($intent, $identity);
        self::assertSame(Policy::INITIAL_FINGERPRINT, $intent['initial_fingerprint']);
        self::assertSame(Target::AUTHORIZATION, $intent['authorization']);
    }

    public function test_nonempty_initial_schema_cannot_receive_a_new_intent(): void
    {
        $this->expectException(RuntimeException::class);
        Policy::assertInitialEmpty(['users'], []);
    }

    public function test_known_interrupted_migration_state_is_accepted_for_explicit_recovery(): void
    {
        Policy::assertIntent($this->intent(), $this->identity());
        $state = $this->partialState();
        Policy::assertInterruptedState(...$state);
        self::assertSame(Policy::PARTIAL_FINGERPRINT, $state[0]);
    }

    public function test_partial_migration_with_any_unrecognized_schema_fingerprint_is_rejected(): void
    {
        $state = $this->partialState();
        $state[0] = str_repeat('a', 64);
        $this->expectException(RuntimeException::class);
        Policy::assertInterruptedState(...$state);
    }

    public function test_partial_migration_with_unexpected_fixture_rows_is_rejected(): void
    {
        $state = $this->partialState();
        $state[4]['users'] = 1;
        $this->expectException(RuntimeException::class);
        Policy::assertInterruptedState(...$state);
    }

    public function test_partial_migration_with_tampered_permission_metadata_is_rejected(): void
    {
        $state = $this->partialState();
        $state[7][0]['description'] = 'Unexpected permission meaning.';
        $this->expectException(RuntimeException::class);
        Policy::assertInterruptedState(...$state);
    }

    public function test_partial_migration_with_unexpected_bootstrap_rows_is_rejected(): void
    {
        $state = $this->partialState();
        $state[13] = [['id' => 2, 'completed' => 0, 'completed_at' => null]];
        $this->expectException(RuntimeException::class);
        Policy::assertInterruptedState(...$state);
    }

    public function test_wrong_database_or_account_identity_invalidates_the_intent(): void
    {
        $identity = $this->identity();
        $identity['database'] = 'techmentors_lms_dev';
        $this->expectException(RuntimeException::class);
        Policy::assertIntent($this->intent(), $identity);
    }

    public function test_tampered_ownership_manifest_is_rejected(): void
    {
        $manifest = [
            'database' => Target::DATABASE, 'server_uuid' => 'verified-server-uuid',
            'authorization' => Target::AUTHORIZATION, 'fingerprint' => str_repeat('0', 64),
        ];
        $this->expectException(RuntimeException::class);
        Policy::assertOwnedManifest($manifest, Target::DATABASE, 'verified-server-uuid', str_repeat('f', 64));
    }

    public function test_recovery_requires_both_exclusive_locks(): void
    {
        Policy::assertLocksAcquired(true, true);
        $this->expectException(RuntimeException::class);
        Policy::assertLocksAcquired(true, false);
    }

    public function test_exact_schema_after_authorized_interrupted_retry_is_accepted(): void
    {
        $identity = $this->identity();
        $intentHash = str_repeat('a', 64);
        $migrations = $this->appliedMigrations();
        $receipt = [
            'database' => Target::DATABASE, 'account' => Target::ACCOUNT.'@127.0.0.1',
            'server_uuid' => 'verified-server-uuid', 'intent_sha256' => $intentHash,
            'initial_fingerprint' => Policy::INITIAL_FINGERPRINT, 'partial_fingerprint' => Policy::PARTIAL_FINGERPRINT,
            'recovered_migration' => Policy::MIGRATION, 'migration_sha256' => Policy::FIRST_RECOVERY_MIGRATION_SHA256,
            'authorization' => Target::AUTHORIZATION, 'recovery_started_at_utc' => '2026-10-28T08:22:24+00:00',
            'migrations_before' => $migrations,
            'nonempty_tables_before' => ['migrations' => Policy::PARTIAL_MIGRATION_COUNT, 'permissions' => 76, 'platform_bootstrap_states' => 1],
        ];
        $objects = array_map(static fn (int $i): string => 'schema_object_'.$i, range(1, Policy::RETRY_OBJECT_COUNT));
        Policy::assertInterruptedRetry(
            $receipt, $identity, $intentHash, Policy::RETRY_FINGERPRINT, $objects, $migrations,
            ['course_prerequisite_rules', 'enrollment_policy_academic_levels'],
            ['migrations' => 43, 'permissions' => 76, 'platform_bootstrap_states' => 1],
            [...array_column($migrations, 'migration'), Policy::MIGRATION], Policy::RESUME_MIGRATION_SHA256,
        );
        self::assertSame(Policy::PARTIAL_MIGRATION_COUNT + 1, count([...array_column($migrations, 'migration'), Policy::MIGRATION]));
    }

    public function test_retry_with_unrecognized_schema_fingerprint_is_rejected(): void
    {
        $identity = $this->identity();
        $receipt = [
            'database' => Target::DATABASE, 'account' => Target::ACCOUNT.'@127.0.0.1',
            'server_uuid' => 'verified-server-uuid', 'intent_sha256' => str_repeat('a', 64),
            'initial_fingerprint' => Policy::INITIAL_FINGERPRINT, 'partial_fingerprint' => Policy::PARTIAL_FINGERPRINT,
            'recovered_migration' => Policy::MIGRATION, 'migration_sha256' => Policy::FIRST_RECOVERY_MIGRATION_SHA256,
            'authorization' => Target::AUTHORIZATION, 'recovery_started_at_utc' => '2026-10-28T08:22:24+00:00',
            'migrations_before' => $this->appliedMigrations(),
            'nonempty_tables_before' => ['migrations' => 43, 'permissions' => 76, 'platform_bootstrap_states' => 1],
        ];
        $this->expectException(RuntimeException::class);
        Policy::assertInterruptedRetry(
            $receipt, $identity, str_repeat('a', 64), str_repeat('0', 64),
            array_map(static fn (int $i): string => 'schema_object_'.$i, range(1, Policy::RETRY_OBJECT_COUNT)),
            $this->appliedMigrations(), ['course_prerequisite_rules', 'enrollment_policy_academic_levels'],
            ['migrations' => 43, 'permissions' => 76, 'platform_bootstrap_states' => 1],
            [...array_column($this->appliedMigrations(), 'migration'), Policy::MIGRATION], Policy::RESUME_MIGRATION_SHA256,
        );
    }

    public function test_postflight_requires_exactly_one_migration_and_only_declared_tables(): void
    {
        $before = $this->appliedMigrations();
        $local = [...array_column($before, 'migration'), Policy::MIGRATION];
        $beforeObjects = ['enrollment_policy_academic_levels'];
        $afterObjects = [...$beforeObjects, ...array_slice(Policy::TABLES_CREATED_BY_MIGRATION, 1)];
        $after = [...$before, ['migration' => Policy::MIGRATION, 'batch' => 2]];
        Policy::assertPostflight($before, $after, $local, $beforeObjects, $afterObjects);

        $this->expectException(RuntimeException::class);
        Policy::assertPostflight($before, [...$after, ['migration' => 'unexpected', 'batch' => 3]], [...$local, 'unexpected'], $beforeObjects, [...$afterObjects, 'unexpected_table']);
    }

    /** @return array{database:string,account:string,server_uuid:string} */
    private function identity(): array
    {
        return ['database' => Target::DATABASE, 'account' => Target::ACCOUNT.'@127.0.0.1', 'server_uuid' => 'verified-server-uuid'];
    }

    /** @return array<string,mixed> */
    private function intent(): array
    {
        return [
            'database' => Target::DATABASE, 'account' => Target::ACCOUNT.'@127.0.0.1',
            'server_uuid' => 'verified-server-uuid', 'initial_fingerprint' => Policy::INITIAL_FINGERPRINT,
            'authorization' => Target::AUTHORIZATION, 'created_at_utc' => '2026-10-28T08:22:24+00:00',
        ];
    }

    /** @return array{string,list<string>,list<array{migration:string,batch:int}>,list<string>,array<string,int>,list<string>,list<string>,list<array{key:string,description:string,scope:string}>,array<string,string>,array<string,string>,array<string,list<string>>,list<array{columns:list<string>,table:string,referenced_columns:list<string>}>,list<string>,list<array{id:int,completed:int,completed_at:?string}>} */
    private function partialState(): array
    {
        $objects = array_map(static fn (int $i): string => 'schema_object_'.$i, range(1, Policy::PARTIAL_OBJECT_COUNT));
        $objects[0] = 'enrollment_policy_academic_levels';
        $applied = $this->appliedMigrations();
        $local = [...array_column($applied, 'migration'), Policy::MIGRATION];

        return [
            Policy::PARTIAL_FINGERPRINT, $objects, $applied, $local,
            ['migrations' => Policy::PARTIAL_MIGRATION_COUNT, 'permissions' => 76, 'platform_bootstrap_states' => 1],
            ['tenant.view'], ['tenant.view', 'campus.view'],
            [['key' => 'tenant.view', 'description' => 'View institution workspace and profile.', 'scope' => 'tenant']],
            ['tenant.view' => 'View institution workspace and profile.'], ['tenant.view' => 'tenant'],
            [
                'enrollment_policies' => ['require_prerequisites', 'require_withdrawal_approval', 'allow_section_transfer', 'allow_late_registration', 'waitlist_ordering'],
                'course_offerings' => ['late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at', 'institution_timezone', 'cancellation_reason', 'archived_at'],
                'course_enrollments' => ['credits_snapshot', 'withdrawal_reason', 'withdrawn_effective_at', 'archived_at'],
            ],
            [
                ['columns' => ['tenant_id'], 'table' => 'tenants', 'referenced_columns' => ['id']],
                ['columns' => ['tenant_id', 'policy_id'], 'table' => 'enrollment_policies', 'referenced_columns' => ['tenant_id', 'id']],
            ],
            ['enrollment_policy_academic_levels'], [['id' => 1, 'completed' => 0, 'completed_at' => null]],
        ];
    }

    /** @return list<array{migration:string,batch:int}> */
    private function appliedMigrations(): array
    {
        return array_map(static fn (int $i): array => ['migration' => 'migration_'.$i, 'batch' => 1], range(1, Policy::PARTIAL_MIGRATION_COUNT));
    }
}
