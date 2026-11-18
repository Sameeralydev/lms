# Phase 18 Focused Test Report

Updated: 2026-11-18

## Freshly executed evidence

| Command | Result |
|---|---|
| `php tests/run.php --phase=18 --profile=sqlite --no-report` | PASS — 6 tests, 140 assertions; exit 0 (2026-11-18). |
| `vendor/bin/pint` on changed payment PHP files | PASS. |
| Focused PHPStan on payment controller, billing domain, relevant models/notification | PASS — no errors. |
| `npm.cmd run typecheck` | PASS — `tsc --noEmit`, exit 0. |
| Targeted ESLint and Prettier checks for billing and SIS guardian pages | PASS, exit 0. |
| `npm.cmd run build` | PASS — Vite production build completed; 704 modules transformed. |
| `php artisan route:list --path=billing --except-vendor` | PASS — 29 billing routes listed. |
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

- `php tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, 6 tests / 140 assertions, exit 0. This fresh run exercised the existing payment, refund, guardian-consent and reconciliation workflows after adding adjusted invoice totals and ledger relationships.
- This focused rerun did not execute MySQL, migrations against a persistent schema, browser tests, or the full Phase 18 static/frontend campaign.
