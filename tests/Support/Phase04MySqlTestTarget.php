<?php

namespace Tests\Support;

/** Immutable, allowlisted MySQL test targets with separate ownership state. */
final readonly class Phase04MySqlTestTarget
{
    private function __construct(
        public string $database,
        public string $account,
        public string $manifestFilename,
        public string $runLockName,
        public string $fileLockFilename,
        public bool $verifyServerIdentity = false,
        public string $disposableAuthorization = '',
        public string $requiredServerVersionPrefix = '',
    ) {}

    public static function phase04(): self
    {
        return new self(
            'techmentors_lms_phase04_test',
            'techmentors_phase04_test',
            '.phase04-test-db-ownership.json',
            'techmentors_phase04_mysql_test_runner',
            '.phase04-mysql-test-run.lock',
        );
    }

    public static function phase04V2(): self
    {
        return new self(
            'techmentors_lms_phase04_v2_test',
            'techmentors_phase04_v2',
            '.phase04-v2-test-db-ownership.json',
            'techmentors_phase04_v2_mysql_test_runner',
            '.phase04-v2-mysql-test-run.lock',
            true,
            'Explicit user authorization in the Phase 04 V2 MySQL Recovery request',
        );
    }

    public static function phase05(): self
    {
        return new self(
            'techmentors_lms_phase05_test',
            'techmentors_phase05_test',
            '.phase05-test-db-ownership.json',
            'techmentors_phase05_mysql_test_runner',
            '.phase05-mysql-test-run.lock',
            true,
            'Explicit disposable Phase 05 test database authorization in the Phase 05 implementation request',
            '8.4.',
        );
    }

    public static function phase06(): self
    {
        return new self(
            Phase06MySqlTestTarget::DATABASE,
            Phase06MySqlTestTarget::ACCOUNT,
            Phase06MySqlTestTarget::MANIFEST,
            Phase06MySqlTestTarget::SERVER_LOCK,
            Phase06MySqlTestTarget::LOCK_FILE,
            true,
            Phase06MySqlTestTarget::AUTHORIZATION,
            '8.4.',
        );
    }

    public static function phase07(): self
    {
        return new self(
            Phase07MySqlTestTarget::DATABASE,
            Phase07MySqlTestTarget::ACCOUNT,
            Phase07MySqlTestTarget::MANIFEST,
            Phase07MySqlTestTarget::SERVER_LOCK,
            Phase07MySqlTestTarget::LOCK_FILE,
            true,
            Phase07MySqlTestTarget::AUTHORIZATION,
            '8.4.',
        );
    }
}
