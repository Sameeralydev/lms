<?php

namespace Tests\Support;

final readonly class Phase09MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase09_test';

    public const ACCOUNT = 'techmentors_phase09_test';

    public const CONFIGURATION = '.env.phase09.testing';

    public const MANIFEST = '.phase09-test-db-ownership.json';

    public const INTENT = '.phase09-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase09-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase09_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit disposable Phase 09 test-schema authorization in the Phase 09 implementation request';

    public const MIGRATION_CUTOFF = '2026_10_31_000001_add_replacement_date_to_academic_timetable_changes';
}
