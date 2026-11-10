# Phase 08 guarded MySQL ownership recovery

**Date:** 2026-10-28  
**Scope:** Restore the isolated Phase 08 guarded MySQL migration/test lifecycle. This is not a Phase 08 functional acceptance or Phase 09 handoff.

## Initial evidence and root cause

The first guarded run began from the owner-authorized, empty Phase 08 test schema and wrote `storage/framework/.phase08-mysql-migration-intent.json` before migration. MySQL DDL is not transactional. The migration then failed while creating the academic-level pivot foreign key because Laravel's generated identifier `enrollment_policy_academic_levels_tenant_id_academic_level_id_foreign` exceeded MySQL's 64-character identifier limit. No migration ledger row was committed for that migration. A later `--run` correctly refused the existing intent, and read-only preflight correctly refused the nonempty schema without an ownership manifest.

The exact first refusal path was the existing-intent branch in `tests/run-phase08-mysql.php`, which runs before a fresh migration attempt. The intent timestamp followed the earlier verified empty-schema preflight. The intent records the exact database, authenticated account, MySQL server UUID, initial empty fingerprint, and explicit disposable-test authorization. No migration file was changed after the intent was created until this repair work.

## Read-only ownership and identity verification

The guarded runner authenticated to MySQL 8.4.11 as `techmentors_phase08_test@127.0.0.1` on the exact `techmentors_lms_phase08_test` schema and server UUID recorded in the intent. It validated the dedicated schema-scoped 11-privilege allowlist and obtained both the local exclusive lock and MySQL advisory lock before taking stable schema snapshots. The private `.env.phase08.testing` file was not printed and remains Git-ignored.

Before the first recovery transition, the schema fingerprint matched the hard-coded observed interrupted state: 144 objects and 43 applied migrations, all in batch 1. The only nonempty tables were the 43-row migration ledger, 76 migration-catalog permission rows, and the one initial `platform_bootstrap_states` row (`id=1`, incomplete, no completion timestamp). Permission keys, descriptions, and scopes matched the local permission catalog. The one existing Phase 08 table was the empty `enrollment_policy_academic_levels` pivot; its existing tenant and composite policy foreign keys were present, while the academic-level composite foreign key was the failed DDL operation. The 16 migration-added columns were present. No Phase 08 fixtures were present.

The repository had 44 migration files. The 43 recorded rows were the exact local prefix; the only pending migration was `2026_10_30_000000_expand_course_enrollment_lifecycle`.

## Migration and runner defects repaired

1. The migration now uses the explicit short constraint name `enroll_policy_level_academic_fk`, skips already-applied columns, and completes the existing pivot's missing composite key idempotently.
2. The first verified recovery attempt then exposed another MySQL 1059 error: `course_prerequisite_rules_tenant_id_prerequisite_course_id_foreign` was too long. This was a second DDL failure after the migration had created the empty prerequisite-rules table and its earlier constraints. Read-only inspection matched the authorized attempt's preserved receipt and found no rows in that table.
3. All composite foreign keys in the Phase 08 lifecycle migration now have explicit short names. The prerequisite-rules table path recognizes the exact already-created table and adds only a missing semantic composite FK.
4. The guarded runner now supports a single narrowly pinned `--resume-recovery` transition. It requires the original intent and first recovery receipt, exact DB/account/server identity, exact post-failure fingerprint (`a8362ed961a0acedfda234c83ee1b9eb1015263e7a18a4585938124017a176e4`), exact 145-object / 43-migration state, the exact two migration-created tables, expected migration-only rows, and the reviewed migration source hash. It creates a separate exclusive retry receipt; it does not remove or replace the original evidence.
5. The first policy implementation incorrectly omitted the populated migration ledger from its nonempty-table inventory. This was corrected before any schema transition and covered by the policy tests.

The retry path rejects any different schema fingerprint, migration prefix, row inventory, object set, identity, receipt, or migration source. It uses both exclusive locks and retains evidence on failure. There is no ownership bypass or manifest regeneration against an unknown schema.

## Commands and observed results

| Command | Result |
|---|---|
| `php artisan test tests/Unit/Phase08MigrationRecoveryPolicyTest.php` | PASS: 13 tests / 14 assertions |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | PASS: no errors |
| `php vendor/bin/pint --test` | PASS |
| `php -l tests/run-phase08-mysql.php` | PASS |
| `php -l tests/Support/Phase08MigrationRecoveryPolicy.php` | PASS |
| `php -l database/migrations/2026_10_30_000000_expand_course_enrollment_lifecycle.php` | PASS |
| `php tests/run-phase08-mysql.php --preflight` before repair | FAILS CLOSED: nonempty, unowned schema; no changes |
| `php tests/run-phase08-mysql.php --recover` before complete repair | FAILS CLOSED on the verified first long-FK DDL error; retained intent and recovery receipt |
| `php tests/run-phase08-mysql.php --resume-recovery` | PASS, exit 0: reviewed migration completed and guarded Phase 08 MySQL suite passed 9 tests / 175 assertions |
| `php tests/run-phase08-mysql.php --preflight` after recovery | PASS, exit 0: exact target verified; 150 objects / 44 migration rows; manifest matched |
| `php artisan test --group=phase08` | PASS: 9 tests / 175 assertions using the isolated SQLite test configuration |

The first recovery's console output identified the second failing generated constraint and showed migration failure; the retry run showed the pending migration `DONE` and the Phase 08 MySQL suite `9 passed (175 assertions)`. The runner's exit code was 0. There were no direct migration commands, database reset commands, drops, truncates, or seeders invoked outside the guarded runner.

## Final read-only postflight

The guarded preflight reported MySQL 8.4.11, `techmentors_lms_phase08_test`, `techmentors_phase08_test@127.0.0.1`, 150 schema objects, and 44 migrations. A separate read-only information-schema inspection confirmed the final Phase 08 migration is applied and all seven Phase 08 lifecycle tables exist. They contain zero rows after the test suite. Foreign-key constraints by table: policy academic levels 3; prerequisite rules 6; prerequisite exceptions 8; enrollment events 3; section transfers 6; enrollment batches 4; batch rows 3. Unique index counts (including primary keys): 1, 2, 1, 1, 1, 3, and 2 respectively.

The ownership manifest's schema fingerprint and row counts were revalidated by the successful guarded preflight. The initial migration intent was archived as `.phase08-mysql-migration-intent.recovered.json`; the first recovery receipt, retry receipt, and ownership manifest remain present. Their nonsecret metadata contains target identity, schema fingerprints, inventory snapshots, source hashes, authorization text, and timestamps.

No Phase 01–07 or unrelated database was targeted. The schema recovery gate is complete. The broader Phase 08 acceptance gate remains **BLOCKED** by the independent Phase 08 items documented in `PHASE_08_FINAL_GATE_REPORT.md`: barrier-based MySQL concurrency probes, durable learner waitlist notification delivery, and full registrar/instructor browser journeys. No Phase 09 work was started.
