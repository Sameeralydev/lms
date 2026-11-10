<?php

namespace Tests\Support;

/** Phase 08 has a dedicated schema and cannot alias earlier phase targets. */
final readonly class Phase08MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase08_test';

    public const ACCOUNT = 'techmentors_phase08_test';

    public const CONFIGURATION = '.env.phase08.testing';

    public const MANIFEST = '.phase08-test-db-ownership.json';

    public const LOCK_FILE = '.phase08-mysql-test-run.lock';

    public const RECOVERY_RECEIPT = '.phase08-mysql-migration-recovery.json';

    public const RETRY_RECEIPT = '.phase08-mysql-migration-retry.json';

    public const SERVER_LOCK = 'techmentors_phase08_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit disposable Phase 08 test-schema authorization in the Phase 08 implementation request';
}
