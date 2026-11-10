<?php

namespace Tests\Support;

final readonly class Phase13MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase13_test';

    public const ACCOUNT = 'techmentors_phase13_test';

    public const CONFIGURATION = '.env.phase13.testing';

    public const MANIFEST = '.phase13-test-db-ownership.json';

    public const INTENT = '.phase13-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase13-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase13_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit owner authorization for disposable Phase 13 test schema';

    public const MIGRATION_CUTOFF = '2026_11_06_000000_create_exam_appeal_evidence';
}
