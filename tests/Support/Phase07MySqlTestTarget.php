<?php

namespace Tests\Support;

/** Immutable Phase 07 target. It cannot alias any earlier phase database. */
final readonly class Phase07MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase07_test';

    public const ACCOUNT = 'techmentors_phase07_test';

    public const CONFIGURATION = '.env.phase07.testing';

    public const MANIFEST = '.phase07-test-db-ownership.json';

    public const LOCK_FILE = '.phase07-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase07_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit disposable Phase 07 test-schema authorization in the Phase 07 implementation request';

    /** The Phase 07 schema ends before later-phase enrollment migrations. */
    public const MIGRATION_CUTOFF = '2026_10_29_000000_create_course_delivery_release_exceptions';

    public static function includesMigration(string $name): bool
    {
        return strcmp($name, self::MIGRATION_CUTOFF) <= 0;
    }

    public static function create(): Phase04MySqlTestTarget
    {
        return Phase04MySqlTestTarget::phase07();
    }
}
