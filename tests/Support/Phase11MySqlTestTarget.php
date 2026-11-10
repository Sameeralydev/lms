<?php

namespace Tests\Support;

final readonly class Phase11MySqlTestTarget
{
    public const DATABASE = 'techmentors_lms_phase11_test';

    public const ACCOUNT = 'techmentors_phase11_test';

    public const CONFIGURATION = '.env.phase11.testing';

    public const MANIFEST = '.phase11-test-db-ownership.json';

    public const INTENT = '.phase11-mysql-migration-intent.json';

    public const LOCK_FILE = '.phase11-mysql-test-run.lock';

    public const SERVER_LOCK = 'techmentors_phase11_mysql_test_runner';

    public const AUTHORIZATION = 'Explicit owner authorization for disposable Phase 11 test schema';

    public const MIGRATION_CUTOFF = '2026_11_02_000000_create_assignment_management_foundation';

    /** @var list<string> */
    public const FORWARD_MIGRATION_NEW_OBJECTS = [
        'assignment_rubrics', 'assignment_rubric_versions', 'assignment_rubric_criteria', 'assignments',
        'assignment_versions', 'assignment_sections', 'assignment_recipients', 'assignment_groups',
        'assignment_group_members', 'assignment_submissions', 'assignment_attempts', 'assignment_attempt_revisions', 'assignment_artifacts',
        'assignment_extensions', 'assignment_grades', 'assignment_integrity_cases', 'assignment_outbox_events',
        'assignment_notifications',
    ];

    /** @var list<string> */
    public const FORWARD_MIGRATION_MODIFIED_OBJECTS = ['tenant_memberships'];
}
