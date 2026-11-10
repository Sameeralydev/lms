# Phase 04 database unblock and implementation recovery

## Final acceptance result (2026-10-23)

**Current result: PHASE 04 COMPLETE — READY FOR PHASE 05 (local development gate).** This section supersedes all earlier blockers in this recovery report. The dedicated V2 MySQL preflight passed with 80 objects and 14 migrations; the guarded MySQL suite passed 122 / 934, SQLite passed 119 / 893 with 3 MySQL-only skips, Playwright passed 20, and the real ClamAV clean/EICAR and concurrent-capacity tests passed. Detailed commands and limits are in `TEST_REPORT.md` and `FINAL_GATE_REPORT.md`. Hosted CI and production infrastructure remain unverified. Phase 05 was not started.

> **Historical result at original report time (2026-10-23): PHASE 04 BLOCKED — REQUIRED ACTION REMAINS.** This was superseded by the final acceptance result above; the original run found an overbroad V1 grant before V2 provisioning and repair.

## Final re-verification

Read-only PDO confirmed `techmentors_lms_phase04_test`, `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11, and zero schema objects. `SHOW GRANTS` and `information_schema.SCHEMA_PRIVILEGES` confirmed global `USAGE` plus schema `ALL PRIVILEGES` (18 scoped privileges; seven extra beyond the exact 11-item test allowlist; no grant option). `php tests/run-tests.php --mysql-phase04` exited 2 before ownership-manifest creation or migrations. No MySQL writes occurred.

Local checks pass: SQLite 92 tests / 764 assertions; admissions subset 14 / 202; Vitest 6; Playwright Chromium 20; PHPStan, syntax, Pint, TypeScript, ESLint, Prettier, Composer validation/platform/audit, npm audit and production build. A sequential seat-capacity test passes; real MySQL concurrency remains unverified. The scanner executable is absent; quarantine remains fail-closed, and no malware scan is claimed. Full command evidence is in `TEST_REPORT.md`.

The remaining sections below are retained as dated historical execution notes. Their statements that `.env.phase04.testing` is absent, identity is unknown, or credential rotation is still pending are superseded by the 2026-10-23 read-only verification above. The current blocker is the excessive grant on the authenticated `127.0.0.1` account.

**Assessment:** 2026-10-22

**Result:** **PHASE 04 BLOCKED — REQUIRED ACTION REMAINS**

**Boundary:** Phase 05 was not started.

## Database credential/configuration gate

The expected ignored `.env.phase04.testing` file is absent. `git check-ignore .env.phase04.testing` confirms the path is ignored. The guarded command:

```text
php tests/run-tests.php --mysql-phase04
```

exited 2 with the safe refusal that a regular ignored `.env.phase04.testing` is required. It returned before opening a MySQL connection. Thus database identity, account authentication, least-privilege grants, test-schema emptiness, migration state, and MySQL integration are not established by this run. A prior read-only authentication probe returned MySQL 1045.

During earlier recovery work, a dedicated test password was placed in a tracked example/worktree and emitted in tool output. In this run, I found a non-placeholder password line in the modified tracked example and replaced only that line with a placeholder; I did not print or copy the value. Other example settings matched the repository version, so I restored the tracked example to its clean repository version. The required ignored `.env.phase04.testing` file is absent. Rotate the account password before reuse. No MySQL migration/reset/write followed the exposure.

## Implementation continuation

- Added a guardian authorization workflow with one-time hashed invitation tokens, email account verification, separate institutional relationship verification, read-only scoped access, audited revocation, and an under-age submission gate.
- Added tenant-scoped inquiry operations and auditing, visual form editing/preview, and applicant save/submit redirect corrections.
- Added FIFO waitlist positions and a fresh independently approved promotion decision before offers.
- Added backend cross-tenant guardian checks and actual browser coverage for minor submission gating, guardian invitation acceptance, institutional relationship verification, read-only access, cross-tenant denial, revocation, and the staff review/decision/offer/handoff journey.
- The E2E-only guardian link header is gated by testing environment, E2E mode, array mail, and loopback IP, and is marked `no-store`; a per-process file cache isolates rate-limit state between disposable browser runs.
- The real review browser journey exposed a Laravel route/controller parameter mismatch returning 500. The missing tenant argument was added, and the final browser suite passed afterward.
- Confirmed the scan integration fails closed: no `clamscan` or `clamdscan` binary exists. No file was scanned by a real service.

## Final local test evidence

- `php tests/run-tests.php --sqlite`: **84 tests / 747 assertions**, exit 0.
- `php tests/run-tests.php --sqlite --filter=AdmissionsFoundationTest`: **13 tests / 197 assertions**, exit 0.
- `npm.cmd run test:unit`: **6 passed**.
- `npm.cmd run test:e2e`: **20 passed**, Chromium; guardian lifecycle and full staff decision/offer/handoff path included.
- PHPStan, 170 PHP syntax checks, Pint, TypeScript, ESLint, Prettier, Composer validation/platform/audit, npm audit, and production build passed. Vite transformed 615 modules.
- Admissions route inspection lists 40 routes. `git diff --check` passed.

## Data safety

No `migrate:fresh`, `db:wipe`, database reset, MySQL migration, or development-database write was run. The MySQL test mode was refused before connection. The E2E/browser suite uses the project’s isolated marker-checked SQLite database. No Apache/XAMPP or system service configuration was changed.

## Smallest required next actions

1. Rotate the dedicated Phase 04 test account password through the approved DBA credential process.
2. Put the rotated value only in a privately created ignored `.env.phase04.testing` file.
3. Require guarded read-only verification of the exact dedicated DB/account, scoped grants, and empty schema; then run guarded MySQL migrations/tests.
4. Configure an authorized malware scanner and validate the real scan path. Until then, quarantine remains enforced.

The scanner adapter currently supports ClamAV CLI: provide an approved installation and current signature database, privately set `ADMISSIONS_DOCUMENT_SCANNER=clamav` plus the absolute `ADMISSIONS_CLAMAV_BINARY` path, and exercise clean, infected/EICAR, unavailable, timeout, and stale-definition cases. No file is considered scanned until that gate is met.

See `TEST_REPORT.md`, `SECURITY_AUDIT.md`, `REQUIREMENT_TRACEABILITY.md`, and `FINAL_GATE_REPORT.md` for the current gate evidence. Hosted CI and production email transport were not executed.

## 2026-10-22 grant-safety diagnosis addendum

The owner supplied the private ignored `.env.phase04.testing` and reports that the exposed credential was rotated. A read-only connection verified the expected database/account on MySQL 8.4.11 and found zero schema objects. The runner reached the privilege check and refused because the dedicated account has schema-scoped `ALL PRIVILEGES`, rather than the 11 documented privileges. Read-only enumeration identified seven extras. No grants, tables, data, or migrations were changed.

The parser now has explicit coverage for MySQL 8.4 quoted output, split grants, loopback account-host aliases, exact privilege matching, and invalid global/schema/grant-option cases. Focused policy tests passed 7/12; the full SQLite suite passed 91/759; Pint and PHPStan passed. Guarded MySQL tests remain unrun. See `GRANT_SAFETY_DIAGNOSIS.md` for evidence and the minimal DBA grant replacement. Phase 05 remains out of scope.
