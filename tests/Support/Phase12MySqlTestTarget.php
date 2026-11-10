<?php

namespace Tests\Support;

final readonly class Phase12MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase12_test';

    public const ACCOUNT = 'techmentors_phase12_test';

    public const CONFIGURATION = '.env.phase12.testing';

    public const MANIFEST = '.phase12-test-db-ownership.json';

    public const INTENT = '.phase12-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase12-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase12_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit owner authorization for disposable Phase 12 test schema';

    public const MIGRATION_CUTOFF = '2026_11_03_000002_create_quiz_file_artifacts';
}
