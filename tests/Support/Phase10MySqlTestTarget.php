<?php

namespace Tests\Support;

final readonly class Phase10MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase10_test';

    public const ACCOUNT = 'techmentors_phase10_test';

    public const CONFIGURATION = '.env.phase10.testing';

    public const MANIFEST = '.phase10-test-db-ownership.json';

    public const INTENT = '.phase10-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase10-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase10_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit owner authorization for disposable Phase 10 test schema';

    public const MIGRATION_CUTOFF = '2026_11_01_000003_create_attendance_justification_evidence';

    /** @var list<string> */
    public const FORWARD_MIGRATION_NEW_OBJECTS = ['attendance_justification_evidence'];

    /** @var list<string> */
    public const FORWARD_MIGRATION_MODIFIED_OBJECTS = ['attendance_justifications'];
}
