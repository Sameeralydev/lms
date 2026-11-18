# Phase 18 Focused Test Report

Updated: 2026-11-18

## Freshly executed evidence

| Command | Result |
|---|---|
| `php tests/run.php --phase=18 --profile=sqlite --no-report` | PASS â€” 6 tests, 140 assertions; exit 0 (2026-11-18). |
| `vendor/bin/pint` on changed payment PHP files | PASS. |
| Focused PHPStan on payment controller, billing domain, relevant models/notification | PASS â€” no errors. |
| `npm.cmd run typecheck` | PASS â€” `tsc --noEmit`, exit 0. |
| Targeted ESLint and Prettier checks for billing and SIS guardian pages | PASS, exit 0. |
| `npm.cmd run build` | PASS â€” Vite production build completed; 704 modules transformed. |
| `php artisan route:list --path=billing --except-vendor` | PASS â€” 29 billing routes listed. |
| `git diff --check` | PASS, exit 0; Git emitted existing CRLF normalization warnings for Phase 14 reports. |
| PHP syntax checks on changed payment files | PASS for the files checked. |

The six feature tests cover:

1. Cash payment allocation, duplicate replay, settlement, receipt, and notification idempotency.
2. Pending bank transfer, separate review, and idempotent verification.
3. Foreign invoice rejection without partial mutation.
4. Segregated and replay-safe refund execution, compensating invoice adjustment, immutable original allocation snapshot, audit, and refund notifications.
5. Explicit guardian financial grant and immediate revocation.
6. Scoped reconciliation totals and formula-safe CSV export.

## Not run / deferred

- Full cross-phase SQLite regression and authenticated Chromium/payment browser coverage were not run; only the Phase 18 focused suite was executed.
- Guarded Phase 18 MySQL, migrations/ownership postflight, and independent-session concurrency probes were not run; a verified Phase 18 target/ownership runner was unavailable in this continuation.
- Composer/npm audits were not rerun in this focused continuation.
- No real gateway, bank statement reconciliation, payout rail, external email/SMS, or human accessibility testing was performed.

## Reverification after Phase 17 billing lifecycle changes

- `php tests/run.php --phase=18 --profile=sqlite --no-report` â€” PASS, 6 tests / 140 assertions, exit 0. This fresh run exercised the existing payment, refund, guardian-consent and reconciliation workflows after adding adjusted invoice totals and ledger relationships.
- This focused rerun did not execute MySQL, migrations against a persistent schema, browser tests, or the full Phase 18 static/frontend campaign.

## Reverification update â€” payment reversal (2026-11-18)

- `php tests/run.php --phase=18 --profile=sqlite --no-report` â€” PASS, 7 tests / 207 assertions, exit 0.
- The new payment-reversal feature test covers request idempotency, requester/reviewer/executor separation, no balance mutation before execution, refund lockout, invoice restoration, replay-safe execution, immutable event/audit history, void receipt access, and ledger reconciliation.
- `vendor/bin/phpstan analyse` on changed billing services/controllers/models â€” PASS, no errors.
- `vendor/bin/pint --test` on changed PHP â€” PASS.
- `php -l` on changed PHP, model, migration, test, and routes â€” PASS.
- TypeScript, targeted ESLint, targeted Prettier, Vite build (706 modules), route inventory (51 billing routes), Composer validation/platform checks, and `git diff --check` â€” PASS.
- MySQL guarded test/ownership path, migration on a persistent database, concurrency, full Chromium, six-theme accessibility, Composer/npm audit, and hosted CI â€” NOT RUN.

The runner used the isolated SQLite profile. This evidence does not establish MySQL behavior or production readiness.

## Final billing checkpoint â€” 2026-11-18

- `C:\php83\php.exe tests/run.php --phase=17 --profile=sqlite --no-report` â€” PASS, **16 tests / 395 assertions**, exit 0.
- `C:\php83\php.exe tests/run.php --phase=18 --profile=sqlite --no-report` â€” PASS, **7 tests / 275 assertions**, exit 0.
- Focused PHPStan on billing domain/controller/model â€” PASS; Pint `--test` on changed billing PHP/migrations/tests â€” PASS; PHP syntax checks â€” PASS.
- `npm.cmd run typecheck`, targeted ESLint, targeted Prettier, and `npm.cmd run build` â€” PASS (706 modules transformed); `composer validate --no-check-publish` â€” PASS.
- `php artisan route:list --path=billing --except-vendor --json` â€” 59 billing routes registered. `git diff --check` â€” PASS (CRLF normalization warnings only).
- No guarded MySQL run, MySQL migration, browser suite, accessibility matrix, external gateway, bank-statement import, or EICAR/ClamAV test was executed.
## Focused billing-report integration refresh â€” 2026-11-18

The Phase 18 payment regression now exercises the shared billing reports page. Confirmed collection totals are grouped by currency and method, subtract executed refunds, and exclude pending transfers. Fresh command `php tests/run.php --phase=18 --profile=sqlite --no-report` passed **7 tests / 246 assertions**. This ran against the centralized isolated SQLite profile; it does not constitute MySQL/concurrency verification or browser acceptance.
