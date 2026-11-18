<?php

namespace Tests\Support;

final readonly class Phase15MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase15_test';

    public const ACCOUNT = 'techmentors_phase15_test';

    public const CONFIGURATION = '.env.testing';

    public const MANIFEST = '.phase15-test-db-ownership.json';

    public const INTENT = '.phase15-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase15-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase15_mysql_test_runner';

    public const AUTHORIZATION = 'OWNER_APPROVED_DISPOSABLE_PHASE15_TEST_SCHEMA';

    public const MIGRATION_CUTOFF = '2026_11_13_000001_backfill_academic_saved_report_permission';
}
