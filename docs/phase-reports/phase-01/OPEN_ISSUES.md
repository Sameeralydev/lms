# Phase 01 open issues and handoff

**Updated:** 2026-10-22

## Phase 02 entry blockers

None for local development. The isolated MySQL identity, schema, grants, contents, migrations, and integration suite are verified. All local backend, frontend, browser, analysis, formatting, build, and dependency audit gates pass.

## Non-blocking infrastructure and production follow-up

- XAMPP Apache was previously identified as PHP 8.2.12. Continue using PHP 8.3+ with php artisan serve; handle Apache separately without affecting other projects.
- Hosted CI was not run. The workflow is configured; hosted execution is a release/deployment follow-up.
- Redis was not run. File cache, file sessions, and synchronous local queues are adequate for current local Phase 02 work. Revalidate before Redis-dependent production paths.
- Development DB principal has schema-scoped DROP according to prior audit. Use a DML-only production runtime principal and a separate migration principal.
- Production hosting, backups/restore, production performance, tenant isolation, and future module controls remain future work.

## Phase 02 handoff

- Development connection: techmentors_lms_dev.
- Isolated test connection: techmentors_lms_test, guarded by tests/Support/TestDatabaseGuard.php.
- composer test loads the ignored local .env.testing and validates the target before booting Artisan.
- Keep local environment secrets out of commits.
