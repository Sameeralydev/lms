# Phase 24 — Integration Test Results

Date: 2026-11-19. Commands below were run in `C:\xampp\htdocs\lms-phase24` using PHP 8.3.35 / Node 24.21.0. No secrets are included.

## Passed

| Check | Command | Result |
|---|---|---|
| Full SQLite regression | `tests/run.php --phase=all --profile=sqlite --no-report` | 415 passed, 10 skipped, 9,180 assertions; exit 0 |
| Academic analytics feature tests | `php artisan test --compact tests/Feature/Phase15AcademicAnalyticsTest.php` | 15 passed, 649 assertions |
| Billing foundation feature tests | `php artisan test --compact tests/Feature/Phase17BillingFoundationTest.php` | 17 passed, 419 assertions |
| Vitest | `npm run test:unit` | 24 passed |
| Focused analytics browser journey | `npm run test:e2e -- --grep "authorized registrar filters real academic analytics"` | 1 passed (36.9s) |
| Focused PHPStan | `phpstan analyse` on the changed analytics, permissions, notification, and related test files | No errors |
| Focused Pint | `vendor/bin/pint --test` on the changed PHP, migration, and test files | Passed |
| Full-project PHP syntax for changed PHP | `php -l` on the listed changed PHP, migration, and test files | All checked files passed |
| Frontend static checks | `npm run lint`; `npm run format:check`; `npm run typecheck` | All passed |
| Production frontend build | `npm run build` | Passed; Vite transformed 717 modules |
| Composer validation | `composer validate --no-check-publish --no-interaction` | Valid |
| Composer audit | `composer audit --no-interaction` | No advisories |
| npm audit | `npm audit` | 0 vulnerabilities |
| Migration status | `php artisan migrate:status --no-interaction` | 115 ran, 0 pending on isolated SQLite |
| Route inventory | `php artisan route:list --json --except-vendor` | 670 routes, 0 duplicate route names |
| Patch whitespace | `git diff HEAD --check` | Passed |

The focused browser journey validates the authenticated registrar analytics filter/export/theme flow. The test fixes were followed by the focused run, not by another full browser campaign.

## Full Chromium run and remaining failures

The recorded full Chromium run executed 47 tests and ended with **43 passed, 1 skipped, 3 failed**. The analytics test failure was caused by blank optional form controls being rejected; the backend validation and responsive form were corrected, and that test passed on focused rerun. The two examination failures are scanner-dependent clean-file workflows: practical answer-script upload and appeal-evidence upload did not become available as clean because ClamAV signature metadata was older than the configured 48-hour freshness requirement. The full suite was not rerun after the analytics correction. Therefore full Chromium acceptance is **pending**, not passed.

ClamAV binary and signature files were present (reported engine 1.5.4, daily database 28185), but the signature database timestamp was stale against the application's freshness policy at this checkpoint. Scanner checks remained fail-closed; scanner policy was not weakened and no unscanned file was released.

## Static analysis

- Focused PHPStan for changed integration files: passed with no errors.
- Full-project PHPStan: failed with **107 diagnostics** across merged modules, including HR, library, tenancy entitlements, integration providers, communications, and model/controller typing. These are not hidden by a baseline and remain a quality gate.
- No full-project Pint command result is claimed in this report. Changed PHP files were syntax checked; the earlier targeted formatting run passed for files then in scope.

## Not run / not verified

- Guarded MySQL integration: not run because the pinned MySQL service was unavailable. No direct or unguarded MySQL operation was attempted.
- Hosted CI: not run.
- Scanner-enabled full Chromium rerun: not run after the analytics fix; fresh ClamAV signatures are required before the two scanner-dependent journeys can pass.
- Production deployment and production HTTP/security verification: not run.
