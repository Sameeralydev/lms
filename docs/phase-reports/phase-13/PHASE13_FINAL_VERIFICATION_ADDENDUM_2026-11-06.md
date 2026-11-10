# Phase 13 final verification addendum — 2026-11-06

This addendum records the latest actual reruns. Earlier reports remain historical. Phase 14 was not started because the Phase 13 engineering gate remains incomplete.

## Fresh verification evidence

| Check | Result | Evidence |
|---|---:|---|
| Guarded MySQL preflight | PASS | `php tests/run-phase13-mysql.php --preflight`; MySQL 8.4.11, exact dedicated Phase 13 test schema/account, 253 objects, 69 migrations, 0 reviewed migrations pending. |
| Guarded MySQL suite | PASS | `php tests/run-phase13-mysql.php --run`, exit 0; 35 passed, 3,227 assertions, 1 scanner-gated skip. The scanner-gated quiz EICAR integration case was skipped because the guarded Phase 13 runner did not receive ClamAV environment configuration. |
| MySQL concurrency | PASS | Eight synchronized probes; 16 worker sessions; every pair printed distinct MySQL `CONNECTION_ID()` values. Candidate registration, seat allocation, paper publication, attempt start, autosave/finalization, script marking, result approval/amendment, and notification idempotency. |
| MySQL postflight | PASS | Repeated `--preflight` after the suite; 253 objects, 69 migrations, 0 reviewed pending. Ownership fingerprint remained `dea00d4011c05e37bbc229a803a7a5a16bc0ca82178ae5d65fc62e4e65640e7c`. |
| Full SQLite suite | PASS | Scanner configured transiently for this process only; `php tests/run-tests.php --sqlite`, exit 0; 337 passed, 7,252 assertions, 4 MySQL-only skips. Real ClamAV tests for admissions, assignments, attendance, learning and quiz assets/responses ran against the local scanner. |
| Full Chromium | PASS | `npm.cmd exec playwright -- test --project=chromium`, with `E2E_CLAMAV_ENABLED=true`; 43 passed, 0 failed, 0 skipped in 17.9 minutes. Examination theme matrix: 78/78 combinations across 13 role/screen cells × 6 themes, with 78 screenshots. |
| Focused route denial/manifest test | PASS | `php tests/run-tests.php --sqlite --filter=Phase13ExaminationRouteAuthorizationMatrixTest`; 2 passed, 2,138 assertions; exact live route inventory is 73 and the generic denial sweep passed 365 requests. |
| Static and frontend checks | PASS | PHPStan, Pint, PHP syntax (561 files), TypeScript, ESLint, Prettier, Vitest (24 tests), Composer validation/platform/audit, production npm audit, production build, and `git diff --check` passed. |
| Full npm audit | OWNER DISPOSITION PENDING | 10 moderate development-tooling findings remain in the ESLint/TypeScript-ESLint `minimatch` chain. Production-only audit reports 0 vulnerabilities. No forced dependency upgrade or self-approval was performed. |

## Authorization matrix disposition

The route inventory contains 73 examination and appeal routes. The test suite now checks live route identity and the 365 generic denial requests. The manifest has **73 links to workflow tests**, but those links are not an executable per-route positive-action evaluator; the documentation previously labeled the links as 73 positive PASS cases, which overstated what the matrix test asserted. The manifest and matrix have been corrected to distinguish `WORKFLOW_EVIDENCE_LINKED` from route-by-route execution.

- Systematic positive route evaluator: **0/73 completed**; 73 remain unverified as a matrix.
- Campus matrix: **2 wrong-campus denials and 2 same-campus positive operations verified** on the cycle-status workflow; **70 route applicability rows remain unassessed**; 1 route is explicitly N/A.
- Foreign-resource matrix: **1 passed** on paper-store foreign-question ownership, **61 of 62 applicable rows pending**, and 11 structurally N/A collection routes.
- Generic negative matrix: **365/365 passed** (guest, unauthorized role/member, foreign tenant, revoked membership); mutation sentinels unchanged.
- Six-theme role/screen matrix: **78/78 passed**.

No incomplete route or campus/resource row is treated as passed. The affected live routes and reasons remain itemized in `PHASE13_AUTHORIZED_ROUTE_MANIFEST.json` and `EXAMINATION_AUTHORIZATION_MATRIX.json`.

## Current gate

`PHASE13_ENGINEERING_GATE=BLOCKED` because the systematic route-positive evaluator and applicable campus/foreign-resource matrix are not complete. This is an engineering evidence gap, not an environment blocker. The ten moderate development-tooling npm findings separately remain `OWNER_NPM_RISK=PENDING`.

`PHASE14_ENGINEERING_GATE=NOT_STARTED`. The combined execution contract makes Phase 14 dependent on Phase 13 passing; no Phase 14 migrations, models, routes, or functionality were started.

Hosted CI and human assistive-technology testing were not run and are not claimed.
