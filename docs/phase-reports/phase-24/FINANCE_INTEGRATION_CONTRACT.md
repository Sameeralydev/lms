# Phase 17/18 Finance Integration Contract for Phase 24

Status: finance integration contract and historical baseline. Phase 24 has since merged the Developer A/B branches; the current integration status and evidence are recorded in `FINAL_PRODUCT_HANDOFF.md` and `INTEGRATION_TEST_RESULTS.md` in this directory.

This document describes the currently implemented finance interfaces and the boundaries downstream modules must preserve. It does not claim full Phase 17/18 acceptance or production readiness.

## Authoritative records and reporting

- `billing_invoices` is the authoritative issued obligation, including adjusted total, paid amount, outstanding amount, status, due date, tenant, institution, campus, and student scope.
- `billing_payments` records confirmed or pending collection. A pending bank transfer is not collected revenue and must not settle an invoice.
- `billing_payment_allocations` records invoice application. Reports should aggregate allocations and compensating refund/reversal history rather than infer collections from an invoice's current paid amount alone.
- `billing_payment_refunds` and `billing_payment_reversals` are separate reviewed lifecycles. A payment in reversed state is excluded from active confirmed collections. Executed refunds/reversals must be represented as adjustments to the original financial event; source receipts, payment events, and allocation history are retained.
- `billing_adjustments` and `billing_concessions` represent independently reviewed changes. Posted adjustments affect invoice balances; concessions are reflected in invoice snapshots.
- `billing_installment_plans` and `billing_installments` currently describe a planned payment schedule. They are not a source of per-installment settlement state; invoice balances remain authoritative for paid/outstanding totals.
- `billing_recurring_plans` and `billing_recurring_runs` represent scheduled invoice generation and its idempotent run history. They do not imply payment collection.
- Learner statement/ledger output is derived from these records. It is not a separate immutable general ledger and must be labeled accordingly.

All reads and aggregates must scope by authenticated tenant and the caller's institution/campus permissions. Never accept a tenant, campus, or student identifier from a request as proof of access. Cross-tenant financial aggregation requires a separately authorized platform-level capability and is not part of this contract.

## Phase 15 analytics extension points

Analytics may add allowlisted read models for:

1. Issued billed amount: invoice adjusted total, grouped by issue date and authorized institution/campus.
2. Current receivable: sum of invoice outstanding amount for non-cancelled invoices, with currency kept separate.
3. Confirmed collection: confirmed payment amounts net of executed refund and reversal effects; pending transfers excluded.
4. Allocation: applied amounts from payment allocations, reconciled to refund/reversal history.
5. Aging: current outstanding invoice amounts grouped by due-date buckets using an explicit report as-of date.
6. Fee assignment and concession counts/amounts: report assignment and approved concession records without treating assignment as an invoice or collection.
7. Recurring billing: active/due plans and run outcomes, not projected revenue presented as collected revenue.

Financial amounts must retain currency as a grouping dimension; do not sum unlike currencies. Drill-downs must resolve source records server-side using the same tenant/campus scope as the aggregate. Export and report generation require their own authorization checks and audit trail.

The currently implemented Phase 17 report evidence includes outstanding-aging buckets and CSV statement exports. A complete analytics catalog, generic record drill-down, branded PDF reports, and scheduled report delivery remain future integration work unless implemented and verified separately.

## Enrollment and fees boundary

`BillingEnrollmentAssessment` is opt-in through the tenant's `billing.enrollment_fee_assessment` setting, which selects an active one-time fee structure. Confirmed enrollment can create an idempotent fee assignment only. Enrollment does not issue an invoice, collect funds, decide proration, or determine term/program applicability. A downstream enrollment/admissions workflow must not imply that a charge was invoiced or paid merely because an assignment exists.

## Notifications and communications

Current finance notifications use Laravel's database notification channel. The application has not verified external email/SMS delivery. A communications integration may consume Laravel notifications, but must preserve tenant scope, recipient authorization, idempotency, and financial visibility checks. Guardians are eligible only when the student relationship is verified, not revoked, and `can_view_financial_records` is enabled at delivery/access time. Recheck authorization when a queued notification is rendered or followed to a financial resource; do not rely solely on permission captured at enqueue time.

## Recurring schedule and worker contract

- Artisan command: `billing:recurring:process`.
- Laravel scheduler cadence: every minute with `withoutOverlapping()`.
- The command dispatches `App\\Jobs\\ProcessBillingRecurringPlans` to the configured queue.
- The queued job is unique (`billing-recurring-plans`), has bounded execution settings, and delegates to `BillingRecurringProcessor`.
- `billing_recurring_runs` has a unique tenant/plan/period key and records success/failure attempts. Invoice period uniqueness is an additional idempotency boundary.
- Deployment must configure a scheduler process and queue worker and monitor failed jobs. Queue delivery/external notification is not proven by local focused SQLite tests.

## Authorization and audit boundaries

Payment reversal permissions are `billing.payments.reverse.request`, `billing.payments.reverse.approve`, and `billing.payments.reverse.execute`. Request, approval/rejection, and execution are separated across actors; requesters cannot review, and neither requester nor reviewer can execute. Reversal execution locks the payment and impacted invoices, restores balances through compensating state, and records audit/payment events without deleting the original record.

Any Phase 24 consumer must use existing billing policies/services or a documented read-only interface; it must not write billing tables directly, bypass payment/refund/reversal state transitions, expose private receipts, or mutate finalized source history. Use explicit service APIs for new financial commands.

## Deployment and migration notes

The new finance schema changes are additive in source. No persistent MySQL migration or production deployment was performed as part of the latest continuation. Apply pending migrations only through the project's approved guarded environment process after target identity and ownership checks. The new reversal history is audit-sensitive; rollback after records exist is not a safe operational recovery. Prefer a forward corrective migration and retain reversal/event history.

Production setup additionally requires a configured queue worker, Laravel scheduler, private receipt/document storage controls, backups, monitoring, and an approved external provider contract if gateway or payout functionality is added. No payment gateway, bank statement importer, automated payout rail, or external delivery provider is configured by this handoff.

## Current verification evidence and limitations

Latest focused local results recorded on 2026-11-18:

- Phase 17 central SQLite profile: 10 tests / 268 assertions passed.
- Enrollment regression: 9 tests / 190 assertions passed.
- Phase 18 central SQLite profile: 7 tests / 207 assertions passed.
- Focused static/frontend/build checks passed as listed in the Phase 17/18 test reports.

These are development checks, not MySQL, concurrency, browser/accessibility, external-provider, hosted-CI, or production evidence. Phase 17 and Phase 18 retain substantive unimplemented requirements documented in their traceability reports. No Phase 24 code is started by this contract.

## Phase 24 update — 2026-11-19

The preceding sentence is historical and is superseded by the Phase 24 merge report. The current analytics catalog includes billing-specific sources and the finance notification opt-out integration. Those changes have focused SQLite and browser evidence, but do not constitute full finance acceptance; MySQL, complete browser regression, hosted CI, and production integrations remain separate verification gates. Refer to the Phase 24 final handoff and release-blocker report for current status.
