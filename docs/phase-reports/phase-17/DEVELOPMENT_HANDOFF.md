# Phase 17 Development Handoff

Updated: 2026-11-18

## Implemented functionality

- Tenant-scoped fee heads and immutable activated fee structures.
- Single-learner invoice issuance with integer minor-unit calculation, request-bound idempotency, duplicate-period prevention and immutable line snapshots.
- Unpaid invoice cancellation with an audit record; printable private invoice documents.
- Learner and verified-guardian invoice visibility with institution/campus checks.
- Invoice reports with filters, pagination and formula-safe CSV export.
- Manual fee assignments linked to an eligible learner and active fee structure, with optional active enrollment reference and idempotency.
- Concession request, independent approval/rejection, revocation, fixed/percentage calculation, and per-invoice calculation snapshots.
- Draft installment plan creation, exact total reconciliation and publication.
- Credit/debit adjustment request, independent approval, third-operator posting, invoice balance update, immutable lifecycle event rows and audit events.
- Staff financial statement assembled from invoices, Phase 18 payment allocations/refund reversals, and posted adjustments; it is a derived statement, not an immutable posting ledger.
- Staff lifecycle and ledger screens connected to the corresponding tenant-scoped routes.

## Exact remaining implementation

- Enrollment/admission-triggered assignment, bulk assignment, transfer/withdrawal proration, bulk invoice issuance, recurring billing policies and retry-safe generation jobs.
- Concession and adjustment notifications; the Phase 18 payment notifications are unchanged.
- Installment amendments/history, per-installment payment status and payment-allocation integration.
- Adjustment reversals/corrections and any additional approved separation-of-duties matrix.
- Student/guardian ledger route and richer billing portal history.
- Aging, concession, installment, ledger and generation reports; report PDF and record drill-down.
- Dedicated billing lifecycle browser journeys, cross-campus adversarial tests, MySQL-specific locking/constraint checks and full regression.
- Applicable legal/tax/late-fee rules remain a product-policy dependency; no rate or rule has been invented.

## Evidence from this continuation

- `php tests/run.php --phase=17 --profile=sqlite --no-report` â€” PASS, 6 tests / 188 assertions. The lifecycle test covers assignment, concession request and independent approval, discounted invoice snapshot, installment publication, independently reviewed credit adjustment, posting, ledger balance and audit/event history.
- `vendor/bin/phpstan analyse` over changed billing domain, controller and model files â€” PASS.
- Focused Pint and PHP syntax checks â€” PASS.
- `php artisan route:list --path=billing --except-vendor` â€” PASS; 40 billing routes registered.
- `npm.cmd run typecheck`, focused ESLint and Prettier checks â€” PASS.
- `npm.cmd run build` â€” PASS; 706 modules transformed.
- `composer validate --no-check-publish` and `composer check-platform-reqs` â€” PASS.
- `git diff --check` â€” PASS.

The test suite used the repository's central isolated SQLite profile. No persistent MySQL database was contacted and no production/development migration was executed. No browser workflow or ClamAV verification was run for these new screens/workflows.

## Database migration state

Two additive migration files are included: lifecycle schema and permission backfill. Their migration chain was exercised by the SQLite test harness. MySQL migration, schema inspection and ownership postflight remain **NOT RUN** and must use the approved guarded Phase 17 process after current test-database ownership and migration safety are reverified.

## Phase status

**PHASE 17 â€” DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**

Missing implementation above is not categorized as QA pending. Broader QA remains outstanding independently.
