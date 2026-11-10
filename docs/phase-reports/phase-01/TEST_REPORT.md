# Phase 01 test report

**Updated:** 2026-10-22
**Scope:** Final isolated regression verification.

## Results

| Check | Result | Evidence |
| --- | --- | --- |
| PHPUnit on MySQL 8.4 | PASS | 23 tests / 83 assertions; techmentors_lms_test only |
| PHPUnit on SQLite | PASS | 23 tests / 83 assertions; SQLite memory |
| Database guard | PASS | techmentors_lms_dev, alamgirian_dev, alamgirian_test, and production each refused with exit 2 before tests |
| Frontend unit suite | PASS | Vitest 5 tests |
| Browser suite | PASS | Playwright Chromium 7 tests |
| PHP syntax | PASS | 40 files, zero errors |
| PHPStan/Larastan | PASS | No errors |
| Laravel Pint | PASS | composer format:check |
| TypeScript, ESLint, Prettier | PASS | All final checks exit 0 |
| Composer audit | PASS | No advisories |
| npm audit | PASS | Zero vulnerabilities |
| Production build | PASS | Vite 8.3.5, 568 modules, 898 ms |
| Migration status | PASS | Three migrations Batch 1 / Ran on both databases |

## Backend results and repairs

The first MySQL execution passed 21 tests and exposed two health-test assumptions: a database failure case changed a hard-coded SQLite connection, and a cache failure case selected a store that was available on MySQL. Tests were corrected to mock the DB facade failure and use an unavailable test-only cache store. The corrected suite passed on MySQL: 23 tests / 83 assertions. It also passed on SQLite memory: 23 / 83.

The earlier baseline was 19 tests / 61 assertions. The current suite adds four test cases and twenty-two assertions covering isolation policy, constraints/index behavior, transaction rollback, and deterministic failure simulation.

## Browser results and repair

A parallel run initially passed 6/7; the keyboard skip-link check intermittently missed initial focus. The isolated case passed. The spec now waits for shell rendering, brings the page to front, and focuses the body before keyboard input. The full suite passed 7/7 after repair, including the final formatted run. Coverage includes shell/assets, console/page errors, health, keyboard navigation, responsive layouts, axe smoke check, and sensitive/private paths.

## Database isolation

The test database had zero tables before migration tests. After tests, it had nine framework tables and three migration-history rows; all other tables had zero rows. The development database was not used for tests.
