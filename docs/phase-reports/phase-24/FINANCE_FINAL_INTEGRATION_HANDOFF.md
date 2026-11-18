# Finance Final Integration Handoff â€” Phase 24

**Integration status: PARTIAL; billing functionality remains under development.** This handoff records source-of-truth contracts and current extension points; it does not assert full Phase 17/18 acceptance.

## Architecture and records

- Fee structures and their immutable issued invoice lines define charges. A fee assignment links a learner and academic enrollment/period to an active structure; it does not itself issue an invoice.
- `BillingFeeApplicability` resolves effective active institution/campus/program/period rules. Exact-scope overlap is rejected; ties at the highest specificity fail closed. A missing match does not invent or select a fee.
- Invoice totals, adjusted totals, paid amounts and outstanding amounts are authoritative for billing. Issued line/calc snapshots remain unchanged by later catalog edits.
- Payments are confirmed only by internal cash confirmation or separately authorized bank-transfer verification. Pending bank submissions are not collections.
- Payment allocations settle invoices; remaining confirmed amount is `unapplied_amount`. Refunds become effective only after controlled execution. Payment reversals are internal compensating records and do not represent a bank reversal/payout.
- Installment plans are schedules over an invoice obligation. The schedule renderer derives paid/outstanding amounts from invoice settlement in sequence and must not be treated as a separate ledger.

## Phase 15 analytics mappings

Use allowlisted sources in `AcademicReportBuilder` and `BillingInvoiceReport`, with current tenant/institution/campus restrictions. Current finance sources include invoice/confirmed-collection reporting and the current committed Phase 15 catalog extensions. Map measure semantics explicitly: assignments and planned installments are not collections; concession values are not monetary totals; pending transfers are excluded from confirmed collections; executed refunds/reversals reduce net collections; unapplied credit is current confirmed-only remainder. Require currency grouping for monetary measures. The broader requested catalog and record-level source drilldowns remain incomplete.

## Phase 16 communications mapping

Billing notifications currently use Laravel database notifications and include tenant scope plus event/reference details. Verified guardians are selected only when their active guardian authority allows financial visibility. The Phase 16 inbox integration and per-user billing preferences are not complete. Integrate into `communications_notifications` and its existing preference model; do not create another messaging system. A preference may suppress a notification, never revoke the underlying authorization boundary.

## Scheduler and queues

Recurring billing uses the existing application scheduler and queue. Run the scheduler and queue worker under the deployment service account; recurring jobs must preserve tenant identity, use period idempotency, record failures, and allow a differently authorized operator to retry. Deployment should verify queue failure handling and scheduler health before activating recurring plans.

## Migration and deployment notes

New local code adds `billing_fee_applicability_rules`, the nullable assignment link, and `billing_installment_events`. Migrations are additive with scoped foreign keys. They have been exercised by isolated SQLite tests only. Review and apply through the approved deployment migration process after an identified target, backup, rollback plan and migration lock are confirmed. No MySQL migration or production database operation was performed in this work.

## Permissions and route inventory

Billing permissions include fee/assignment/concession/installment/invoice/adjustment/payment/refund/reversal/report/ledger capabilities. New applicability routes are `POST /t/{tenant}/billing/fee-applicability`, `PATCH /t/{tenant}/billing/fee-applicability/{ruleId}/status`, and `POST /t/{tenant}/billing/fee-applicability/preview`, protected by `billing.fees.manage`. Existing billing routes are enumerated in `routes/web.php`; use `php artisan route:list --path=billing --except-vendor` for the current inventory.

## Missing functionality and external decisions

- Academic proration is preview-only; it is not yet applied to invoices or snapshotted.
- Neutral CSV statement import, batch review, exact manual matching/reasoned exclusion, duplicate-file detection, and resolved-batch closure are implemented; bank-specific adapters, reopen/reversal workflow, external settlement verification, and a dedicated credit subledger remain unimplemented.
- Reminder policies and Phase 16 inbox/preference integration are incomplete.
- Online payment provider, webhook signing contract, credentials and payout provider require an explicit product owner decision. No gateway success is fabricated.
- Full MySQL migration/concurrency verification and authenticated browser coverage remain pending.

## Verification evidence

- Phase 17 isolated SQLite: 16 passed / 395 assertions.
- Phase 18 isolated SQLite: 7 passed / 275 assertions.
- Focused PHPStan, TypeScript, targeted ESLint/Prettier, production build and Composer validation passed in this checkpoint.
- MySQL, hosted CI, external payment providers, bank import and external notifications were not run/verified.

## Git handoff

Branch: `developer-a/integrated`. Implementation checkpoint commit: `377702c09b75c7ed0c0a838fa8e8a812b0457920` (`Continue Phase 17 and 18 billing workflows`). Normal push to `origin/developer-a/integrated` succeeded. Post-push local HEAD and `origin/developer-a/integrated` both resolve to `377702c09b75c7ed0c0a838fa8e8a812b0457920`. No force push or Developer B branch operation occurred.

## Phase 18 statement reconciliation update — 2026-11-18

Phase 18 now provides a neutral CSV statement-review workflow in addition to internal payment/refund reconciliation. Imported statements are evidence only: matches do not settle payments or prove external bank settlement. Current focused SQLite results are Phase 17 16 tests / 395 assertions and Phase 18 8 tests / 294 assertions. Implementation commit `deb4f295437a167068b62309a5bb2e6657491ae4` was pushed normally to `origin/developer-a/integrated`; local and fetched remote HEAD matched at verification. No force push or Developer B branch operation occurred.
