# Phase 09 Test Report

**Verification date:** 2026-10-29. All commands below were run in the current local worktree. Secrets were not printed.

## Database verification

| Command | Result |
|---|---|
| `php tests/run-phase09-mysql.php --run` | PASS, exit 0. Guarded MySQL 8.4.11 target `techmentors_lms_phase09_test`, authenticated account `techmentors_phase09_test@127.0.0.1`; 6 tests / 199 assertions. Final schema inventory 161 objects / 46 migrations. Ownership fingerprint `a5d8b108b7e49c3ecca4b6b29b40a8050e3c2c2f5cd0affbea86fca552e2c9ef` remained valid. |
| Guarded independent-session probes (within Phase 09 run) | PASS: 7 probes—room allocation, instructor allocation, recurring-series conflict, competing publication, occurrence rescheduling, substitute instructor assignment and timetable version change. Each used two independent MySQL sessions and synchronization with bounded waits; final persisted-state and applicable audit/revision invariants passed. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0: 254 passed, 7 skipped, 3,265 assertions; 48.18 seconds. Skips include database-engine-specific and real-scanner tests gated by environment. |
| `php artisan route:list --path=academics/timetable --except-vendor` | PASS, exit 0: 19 timetable routes listed. |
| PHP syntax scan over `app/`, `routes/`, and `tests/` | PASS, exit 0: all scanned PHP files passed `php -l`. |

An earlier SQLite attempt overlapped with the Vite production build and one Inertia-rendering test could not find the temporarily replaced Vite manifest. After the build completed, the isolated SQLite rerun above passed. This was a test-process overlap, not a database or application regression.

## Frontend and browser

| Command | Result |
|---|---|
| `npm.cmd run test:e2e` | PASS, exit 0: 26 passed, 1 skipped in 8.8 minutes. The skipped test is the ClamAV-gated transfer document journey; scanner E2E was not enabled for this run. |
| `npm.cmd run test:unit` | PASS: 6 Vitest tests. |
| `npm.cmd run typecheck` | PASS: TypeScript `tsc --noEmit`. |
| `npm.cmd run lint` | PASS: ESLint with zero warnings. |
| `npm.cmd run format:check` | PASS: all matched files formatted. |
| `npm.cmd run build` | PASS: Vite production build, 649 modules transformed. Main JS bundle 359.33 kB (112.15 kB gzip); timetable chunk 29.27 kB (6.18 kB gzip). Local bundle observations only. |

The timetable browser flow persisted timetable setup, room/availability, recurring meeting, review/publication, occurrence changes, instructor and learner schedule views, exports and analytics; it asserted conflict rejection and cross-tenant non-disclosure. Accessibility assertions are detailed in `PHASE_09_UX_ACCESSIBILITY_REPORT.md`.

## Static analysis, audits and repository checks

| Command | Result |
|---|---|
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | PASS: no errors. |
| `php vendor/bin/pint --test` | PASS. |
| `composer validate --no-check-publish` | PASS: `composer.json` valid. |
| `composer check-platform-reqs` | PASS: installed PHP 8.3.35 and required platform packages satisfied. |
| `composer audit` | PASS: no security vulnerability advisories. |
| `npm.cmd audit --audit-level=moderate` | PASS: zero vulnerabilities. |
| `git diff --check` | PASS. |

Hosted CI was NOT RUN. Manual assistive-technology testing was NOT RUN. The full browser suite’s single scanner-dependent skip is not counted as a pass.
