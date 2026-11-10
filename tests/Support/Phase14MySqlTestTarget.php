<?php

namespace Tests\Support;

final readonly class Phase14MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase14_test';

    public const ACCOUNT = 'techmentors_phase14_test';

    public const CONFIGURATION = '.env.phase14.testing';

    public const MANIFEST = '.phase14-test-db-ownership.json';

    public const INTENT = '.phase14-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase14-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase14_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit owner authorization for disposable Phase 14 test schema';

    public const MIGRATION_CUTOFF = '2026_11_10_000007_grant_academic_workspace_to_teachers';
}
