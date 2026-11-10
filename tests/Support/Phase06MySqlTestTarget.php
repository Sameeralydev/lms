<?php

namespace Tests\Support;

/** Immutable Phase 06 disposable target; never aliases a prior phase schema. */
final readonly class Phase06MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase06_test';

    public const ACCOUNT = 'techmentors_phase06_test';

    public const CONFIGURATION = '.env.phase06.testing';

    public const MANIFEST = '.phase06-test-db-ownership.json';

    public const LOCK_FILE = '.phase06-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase06_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit disposable Phase 06 test-schema authorization from the Phase 06 implementation request';

    public const MIGRATION_CUTOFF = '2026_10_26_000001_complete_phase06_governance_resources';

    public static function create(): Phase04MySqlTestTarget
    {
        return Phase04MySqlTestTarget::phase06();
    }
}
