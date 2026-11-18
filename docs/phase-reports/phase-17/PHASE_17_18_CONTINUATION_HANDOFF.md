# Phase 17/18 Continuation Handoff — 2026-11-18

## Changes in this continuation

- Added queue-backed recurring-plan processing via `ProcessBillingRecurringPlans`; the existing `billing:recurring:process` scheduler command dispatches the unique job.
- Added a full-payment reversal lifecycle: reasoned/idempotent request, independent review, distinct execution, payment/invoice locking, balance restoration, immutable source history, audit/payment events, and void-but-retained receipt.
- Added reversal actions/history to the tenant-scoped payments UI and three dedicated reversal permissions.
- Updated the derived student ledger to represent confirmed unapplied payment funds as credit and to account for reversal effects without rewriting source allocations.
- Added focused tests for recurring job dispatch and payment reversal segregation, replay safety, invoice restoration, receipt state, audit history, and ledger reconciliation.

## Current evidence

- `php tests/run.php --phase=17 --profile=sqlite --no-report`: **11 passed / 293 assertions** (fresh 2026-11-18 rerun).
- `php artisan test --filter=CourseEnrollmentTest`: **9 passed / 190 assertions**.
- `php tests/run.php --phase=18 --profile=sqlite --no-report`: **7 passed / 246 assertions** (fresh 2026-11-18 rerun).
- Focused PHPStan, Pint, PHP syntax, TypeScript, ESLint, Prettier, production build, Composer validation/platform checks, billing route listing, and `git diff --check`: passed as recorded in the latest Phase 18 test report.

The suites used isolated SQLite test profiles. No persistent MySQL database was contacted or migrated. MySQL ownership/integration, independent-session concurrency, full authenticated browser/accessibility, ClamAV/EICAR, external delivery/provider, hosted CI, and production deployment were not verified.

## Remaining implementation and policy work

Phase 17 still has functional gaps: academic/session/program fee applicability; owner-defined proration and billing cadence policies; installment amendments, settlement and reconciliation; separate transferable student-credit lifecycle; bulk billing preview/history and cohort filtering; broader financial reports with drill-down/PDF; notification history/retry lifecycle; and recurring-plan operator reassignment/recovery. Late-fee and term policy must be defined by the product owner.

Phase 18 still lacks provider-backed payments, external statement import/matching and reconciliation closure, verified payout rails, and approved cash/cheque control policy. Current refund execution records a manual payout reference only. Payment reversal is an internal compensating lifecycle, not a bank reversal or payout.

Both phases remain **DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**. Comprehensive environmental QA remains separately pending and does not convert missing functionality into a QA-only item.

## Currency-safe billing and collection reporting continuation — 2026-11-18

The billing report summary now groups invoice count and monetary totals by ISO currency. The page renders a separate set of invoiced, collected, outstanding, and overdue figures for each currency, preventing unlike currencies from being added into a misleading single total. Its new confirmed-collections table groups confirmed net collections by currency and payment method, subtracts only executed refunds, and excludes pending or reversed payments. Both reports are scoped by the current institution/campus and report dates; payment tests cover pending-transfer exclusion and refund-adjusted net totals. The invoice report regression creates USD and EUR invoices and asserts their totals remain separate. Internal billing notification payloads now include an explicit top-level tenant ID plus the existing tenant slug/detail metadata, providing a safe scope key for a future communications inbox. Delivery remains database-only; verified guardian recipients continue to be selected by the existing billing workflows.

Fresh focused verification after this continuation:

- `php tests/run.php --phase=17 --profile=sqlite --no-report` — **PASS, 11 tests / 293 assertions**.
- `php tests/run.php --phase=18 --profile=sqlite --no-report` — **PASS, 7 tests / 246 assertions** (fresh 2026-11-18 rerun).
- `php artisan test --filter=billing_notification_payload` — **PASS, 1 test / 2 assertions** (focused rerun; included in the Phase 17 suite above).
- `npm.cmd run typecheck` — **PASS**; `npm.cmd run lint -- --no-cache` — **PASS**; `npm.cmd run build` — **PASS, 706 modules transformed**.
- Focused PHPStan on the changed billing report and notification code — **PASS** with `--memory-limit=512M`. The first invocation at the repository default 128 MB crashed; rerun completed cleanly.
- Pint check, PHP syntax, and `git diff --check` — **PASS**.

These are focused SQLite/local development checks only. MySQL, payment-provider integrations, cross-phase notification inbox delivery, and browser/accessibility coverage remain unverified. No migration was added or executed and no persistent database was contacted. This continuation does not close the remaining Phase 17/18 implementation gaps listed above.

## Phase 24 integration handoff

See [FINANCE_INTEGRATION_CONTRACT.md](../phase-24/FINANCE_INTEGRATION_CONTRACT.md) for source-of-truth definitions, analytics extension points, enrollment/fee boundaries, guardian visibility, notification integration, recurring scheduler/worker operation, and migration/deployment constraints. This is a contract only; no Phase 24 implementation is included.
