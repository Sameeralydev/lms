# Phase 05 test report

**Execution date:** 2026-10-24  
**Runtime:** Windows; PHP CLI 8.3.35; Laravel 13.38.0; Node 24.21.0; npm 11.19.0; Composer 2.10.3; Vite 8.3.5.  
**Secret handling:** .env.phase05.testing was never printed; it is ignored by Git and read only by the explicit guarded runner.  
**Database safety:** MySQL tests used only the guarded dedicated Phase 05 schema. SQLite and browser tests used isolated SQLite targets. No development, Phase 01/04, or unrelated schema was selected.

## Backend results

| Suite | Command | Result |
|---|---|---|
| MySQL 8.4 guarded integration | php tests/run-tests.php --mysql-phase05-upgrade | PASS — 169 passed / 1,603 assertions / 0 failures; exit 0 |
| SQLite regression | php tests/run-tests.php --sqlite | PASS — 166 passed / 1,562 assertions / 3 MySQL-only skips / 0 failures; exit 0 |
| MySQL pre-upgrade ownership | php tests/run-tests.php --preflight-phase05-upgrade | PASS — read-only identity, allowlist, ownership and single-trailing-migration check; prior schema 99 objects / 28 migrations; no writes |
| MySQL post-run ownership | php tests/run-tests.php --preflight-phase05 | PASS — read-only verification; final schema 102 objects / 29 migrations |

The guarded MySQL tests exercise database-enforced relationships and constraints, transaction rollback, tenant ownership, admissions seat-capacity locking/concurrency, guardian authority/revocation, SIS import chunking, lifecycle approval, archive/purge behavior and outbox retries. The SQLite suite skipped only the three MySQL-specific checks.

## Frontend and browser

| Check | Command | Result |
|---|---|---|
| Frontend unit tests | npm.cmd run test:unit | PASS — 6 tests |
| Chromium browser suite | npm.cmd run test:e2e -- --workers=1 | PASS — 22/22 tests; exit 0; 4.4 minutes |
| TypeScript | npm.cmd run typecheck | PASS |
| ESLint | npm.cmd run lint | PASS — zero warnings |
| Prettier | npm.cmd run format:check | PASS |
| Production assets | npm.cmd run build | PASS — 618 modules; main JS 354.57 kB / 110.95 kB gzip; CSS 39.36 kB / 8.32 kB gzip |

Browser coverage includes Laravel/Inertia/Vite boot, asset delivery, public liveness/readiness privacy, host-header rejection, private-file protections, keyboard navigation, responsive layouts, tenant/institution/SIS workflows, student lifecycle and guardian paths, import completion, theme persistence, audit events, cross-tenant denial, and ClamAV-backed document upload/download. Axe assertions passed on the public shell and four SIS views (overview, directory, Student 360, import mapping); the directory and profile also passed 390px overflow checks.

## PHP, dependency and source checks

- vendor/bin/phpstan analyse --no-progress --memory-limit=512M — PASS, no errors.
- vendor/bin/pint --test — PASS.
- PHP syntax loop across app, database/migrations, tests, routes, and config — PASS, 256 files.
- composer validate --strict — PASS.
- composer check-platform-reqs — PASS on PHP 8.3.35.
- composer audit — PASS, no advisories.
- npm.cmd audit --audit-level=low — PASS, zero vulnerabilities.
- git diff --check — PASS.
- git check-ignore .env.phase05.testing — PASS.

## Scanner results

ClamAV 1.5.4 was used by the actual backend and browser test workflows. Read-only sigtool metadata verified the signed main CVD (v63; 3,287,027 signatures) and daily CVD (v28162; 355,787 signatures; 2026-10-23 06:24 UTC; Verification OK). Clean files were accepted; harmless raw EICAR samples were rejected. Unit/integration coverage confirms unavailable scanner, missing/empty/stale/mismatched signatures, process error and timeout fail closed. This is local integration evidence, not production scanner operations certification.

## Database ownership evidence

The runner privately loaded .env.phase05.testing only in explicit Phase 05 modes. Its read-only upgrade preflight verified the dedicated target, the exact 11-privilege schema allowlist, current authenticated account, server identity, stable object/migration inventory, and authorization for exactly one trailing migration. The exclusive guarded run completed; the read-only postflight then verified the expected 102-object / 29-migration inventory. No destructive reset command was used. No development or unrelated database was selected.

## CI and deployment

Hosted CI was NOT RUN. The results above are local Windows executions. XAMPP Apache still runs PHP 8.2 and was not modified; the supported local runtime is PHP CLI 8.3.35 through Laravel's development server. Redis, production mail/scanner services, production backup/storage controls and production performance remain deployment decisions outside these local test results.

The traceability and release decision are in PHASE_05_REQUIREMENT_TRACEABILITY.md and PHASE_05_FINAL_GATE_REPORT.md.
