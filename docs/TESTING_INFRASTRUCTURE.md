# Central automated testing configuration

## Configuration contract

`.env` is for local application development. Automated tests use the one ignored `.env.testing` file; start from the committed `.env.testing.example`. Never commit credentials.

`tests/bootstrap.php` loads this file through the shared `CentralTestingEnvironment` helper. PHPUnit's `phpunit.xml` deliberately contains no database connection values. Effective precedence is:

1. A guarded runner selects a profile explicitly. A plain PHPUnit invocation defaults to the central `TESTING_PROFILE` (SQLite in the example).
2. For SQLite, process/CI settings may provide unrelated test services, but the helper forcibly pins `APP_ENV=testing`, `APP_DEBUG=false`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, clears DB URL/host/port/user/password/socket, and uses array cache/session plus sync queues.
3. For MySQL, the guarded phase runner reads namespaced credentials from `.env.testing`, then pins the database and user to that phase's reviewed `*MySqlTestTarget` constants and clears `DB_URL`. Phases using the reviewed shared MySQL endpoint remain restricted to `127.0.0.1:3306`; Phase 15 can opt into an owner-pinned alternate loopback port only when its exact private port, server UUID, and canonical data directory are configured in the central file. Existing per-phase preflight, exact-account/grant, disposable authorization, ownership manifest, migration-intent and lock rules remain mandatory. The runner refuses cached Laravel config before migration operations.
4. CI environment variables can select `TESTING_PROFILE` for an explicit runner, but cannot substitute another schema or user: target identity is pinned and guards reject mismatches. Never run a raw destructive command with CI-provided database variables.

MySQL profile fields use `TESTING_MYSQL_PHASENN_USERNAME`, `PASSWORD`, `DISPOSABLE`, and `AUTHORIZATION`. Phase 04 V2 uses `PHASE04V2` in the profile key. A password is only required locally for an explicitly selected phase, never for SQLite. Phase 15 is intentionally unconfigured in this checkout; its example fields are blank and no MySQL connection should be attempted until the owner supplies that dedicated account credential and authorization.

## Commands

```powershell
# Entire existing PHPUnit suite on isolated in-memory SQLite
php tests/run.php --phase=all --profile=sqlite

# One phase's SQLite tests, based on test-name filter
php tests/run.php --phase=15 --profile=sqlite

# Guarded phase MySQL run (04 through 15; runs that phase's own runner)
php tests/run.php --phase=15 --profile=mysql

# Global security-focused name filter on SQLite
php tests/run.php --phase=all --profile=sqlite --check=security

# Independent concurrency requires a guarded per-phase MySQL runner
php tests/run.php --phase=15 --profile=mysql --check=concurrency
```

Phase 04–07 use the existing `tests/run-tests.php` guarded modes; Phase 08–15 use their dedicated guarded runner. The runner appends command, UTC timestamps and exit code to `docs/phase-reports/phase-NN/CENTRAL_TEST_RUNS.md`. Console output remains the authoritative detailed evidence. `--phase=all --profile=mysql` is deliberately rejected: every schema has a separate owner authorization and migration lifecycle, so each MySQL phase must be invoked individually after its own preflight. SQLite security filtering is global; SQLite concurrency is refused because SQLite cannot prove independent database sessions. Phase-specific MySQL commands run that phase's complete guarded suite, including any available concurrency harness. Future phase runners are discovered using `tests/run-phaseNN-mysql.php` and the same `TESTING_MYSQL_PHASENN_*` profile convention.

SQLite security filtering is global. SQLite concurrency is refused because it cannot prove independent database sessions. Phase-specific MySQL commands run that phase's complete guarded suite, including any available concurrency harness. For SQLite, `--phase=all` runs the entire suite, not a collection of hand-picked phase filters. Phase-specific SQLite execution uses PHPUnit's `PhaseNN` test-name filter; an empty filter result must not be treated as coverage.

## Profile migration

`tests/centralize-testing-environments.php --check` validates legacy ignored phase files without displaying values. `--apply` copies only validated per-phase credentials/authorization and service settings into ignored `.env.testing`, keeping SQLite as the generic default. Legacy files are migration inputs only; no test runner should load them. After verifying the central profile and backing it up privately, remove obsolete ignored phase files locally. Never use the example file as credentials.

## CI

The generic CI workflow copies `.env.testing.example` to ignored `.env.testing` and runs SQLite tests. It no longer labels a test as MySQL-backed when it actually used a generic service account or ran SQLite. `phase07.yml` creates only `.env.testing` and invokes the existing Phase 07 guarded runner with a separately provisioned dedicated schema. Hosted CI is not verified by local execution.
# Phase 15 closure note — 2026-11-16

Runtime test runners load only the ignored `.env.testing`. Historical per-phase files are not runtime inputs; the migration utility `tests/centralize-testing-environments.php` can inspect them only as an explicit one-time import aid. The repository now ignores the complete `/.env.phase*.testing` pattern so a leftover local secret file cannot be staged accidentally. The centralized Phase 15 MySQL profile still requires the DBA-reviewed Phase 15 username/password, disposable and owner authorization values, and exact server UUID before it will connect.
