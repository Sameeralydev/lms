# Phase 01 regression report

**Updated:** 2026-10-22

## Final results

| Suite/check | Result |
| --- | --- |
| Backend MySQL 8.4 | PASS — 23 tests / 83 assertions |
| Backend SQLite memory | PASS — 23 tests / 83 assertions |
| Frontend unit | PASS — 5 tests |
| Playwright Chromium | PASS — 7 tests |
| PHPStan/Larastan | PASS — no errors |
| PHP syntax | PASS — 40 files |
| TypeScript | PASS |
| ESLint | PASS — zero warnings |
| Prettier and Pint | PASS |
| Composer validation/platform/audit | PASS |
| npm audit | PASS — zero vulnerabilities |
| Vite production build | PASS — 898 ms |
| Development and test migrations | PASS — three migrations Batch 1 / Ran on both |
| Unsafe target guard | PASS — four forbidden schemas refused before tests |
| Hosted CI | NOT RUN |

## Defects repaired

MySQL integration showed two health failure tests depended on SQLite configuration and on a cache backend that was available in MySQL. HealthTest now mocks DB failure and selects a deliberately unavailable test-only cache store. This makes the error-path tests deterministic without changing database targets.

A parallel Chromium run intermittently missed initial skip-link focus. The E2E case now waits for shell rendering, brings the page forward, and focuses the body before keyboard input. Full browser tests passed after the repair. Prettier formatted the modified spec; final type, lint, format, and browser gates passed.

No application runtime behavior, migration files, UI design, Apache, Windows settings, MySQL service, or unrelated database was modified. Two test files and Phase 01 documentation changed.
