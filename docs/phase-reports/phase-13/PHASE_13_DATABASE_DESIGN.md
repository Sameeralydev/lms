# Phase 13 Database Design

Updated 2026-11-04.

## Additive migrations in the repository

- `2026_11_03_000003_create_examination_operations_foundation.php`: cycles, sessions, candidates and seat allocations.
- `2026_11_03_000004_create_exam_paper_governance.php`: papers, immutable paper versions and independent review records.
- `2026_11_03_000005_create_exam_candidate_operations.php`: accommodation revisions, invigilator assignments, attendance records and attendance revisions.

The records are tenant scoped. Composite tenant foreign keys enforce tenant ownership; explicit unique keys protect cycle codes, candidate registration, session seats, paper version/set numbers and revision histories. Paper snapshots, paper instructions, reviewer notes and confidential accommodation details use encrypted casts. Seat allocation now requires a registered candidate with confirmed enrollment in the session course and campus; effective withdrawals on or before the exam start do not qualify.

`Phase13SchemaIdentifierPolicy` is used by the guarded target policy. `tests/run-phase13-mysql.php` has an explicit migration cutoff at migration `000005`, with separate intent, manifest, file lock and server advisory lock. Phase 12's cutoff and ownership evidence were not modified by the Phase 13 work.

## Verification and limits

The focused and full SQLite feature suites exercised the migrations and constraints. The latest full SQLite result is 311 passed / 4,285 assertions / 10 environment-dependent skips. The latest guarded read-only MySQL preflight passed against MySQL 8.4.11: exact target database/account and grants verified; schema has 0 objects / 0 migration rows, with 57 reviewed migrations pending. `--run` exited 1 before database contact because the required eight-probe harness is absent; no migration ran and the schema remains unchanged. MySQL schema catalog, migration, foreign-key/index, lock and postflight evidence remain NOT RUN.

Migrations for delivery bindings, centers, scripts, marking, results, appeals, incidents, outbox and retention are not present. No claim is made that the examination schema is complete.
