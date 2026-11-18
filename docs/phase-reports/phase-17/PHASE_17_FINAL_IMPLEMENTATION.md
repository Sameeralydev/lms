# Phase 17 — Final Implementation Checkpoint

**Status: PARTIAL IMPLEMENTATION | FUNCTIONAL WORK REMAINS**
Checkpoint: 2026-11-18. This records the current `developer-a/integrated` worktree. It is not a production-readiness or full-scope acceptance claim.

## Implemented in this checkpoint

- Academic fee applicability rules are institution/tenant scoped and can be created as drafts, activated/deactivated, resolved against campus/program/period/date, previewed for a learner, and audited. Overlapping rules with identical scope are rejected. Confirmed enrollment resolves a matching active rule before the legacy explicit enrollment fee setting and creates an assignment only; it does not issue an invoice. Evidence: `BillingFeeApplicability`, `BillingEnrollmentAssessment`, `BillingFeeApplicabilityRule`, `BillingLifecycleController`, `billing/lifecycle.tsx`, and migration `2026_11_23_000000_create_billing_fee_applicability_rules.php`.
- Proration is institution-configurable and disabled by default. Calendar-day preview uses integer minor units and is audited. It is a preview only: no invoice is changed and no proration snapshot is committed to an invoice.
- Installment schedules support creation, publication, amendments/rescheduling with idempotency and append-only version snapshots. Amounts reconcile to the invoice obligation; paid installments cannot be edited or removed. Displayed paid/outstanding/due state derives from the invoice's authoritative paid amount.
- Recurring plans support scheduled execution, pause/resume/cancel, failed-run visibility, retry, operator recovery by another authorized member, and period idempotency.
- Existing billing includes immutable invoice snapshots, concessions, adjustments and reversals; student statements; confirmed payment allocation; refunds; and full payment reversal. Reporting separates totals by currency and provides invoice/collection CSV and PDF exports. The Phase 15 analytics integration is documented in `../phase-24/FINANCE_INTEGRATION_CONTRACT.md`.
- Billing lifecycle notifications remain internal Laravel database notifications with tenant and record references; guardian recipients are checked against verified, active financial authority at send time.

## Fresh verification executed

- `C:\php83\php.exe tests/run.php --phase=17 --profile=sqlite --no-report` — PASS, **16 tests / 395 assertions**.
- `C:\php83\php.exe tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, **7 tests / 275 assertions**.
- Focused fee-applicability test — PASS, **1 test / 15 assertions** (also included in Phase 17 suite).
- Focused PHPStan over billing domain and lifecycle controller/model — PASS.
- Pint `--test` on changed billing PHP, migrations, and tests — PASS after formatting.
- PHP syntax checks on changed domain, controller, model, migration, and test files — PASS.
- `npm.cmd run typecheck` — PASS; targeted ESLint — PASS; targeted Prettier check — PASS; `npm.cmd run build` — PASS (706 modules transformed).
- Composer validation — PASS (`composer validate --no-check-publish`).
- `php artisan route:list --path=billing --except-vendor --json` — 59 registered billing routes.
- `git diff --check` — PASS (exit 0; Git emitted CRLF normalization warnings for two traceability documents).
- SQLite test runner applies the new additive applicability and installment-event migrations in an isolated test database. No persistent MySQL database was contacted and no MySQL migration was run.

## Remaining functional requirements (not QA deferrals)

- Full enrollment/program fee rule administration history, editing drafts, and business cases beyond fee assignment resolution require additional implementation and test coverage.
- Proration is preview-only. Enrollment/withdrawal/course-change charges are not calculated into an invoice, and no immutable proration snapshot is stored on an invoice. Term billing and late fees require institution-approved calendars/policies; none are enabled or invented.
- Installment cancellation/status workflow, due reminders, and a persistent per-installment allocation ledger are incomplete. Settlement is derived from invoice totals and allocation order.
- Student credit remains the confirmed payment's unapplied amount, not a standalone immutable credit ledger with credit events/transfers. Reversal/refund protections are present, but dedicated credit reversals and student-to-student transfers are not implemented.
- Bank-statement CSV import, reconciliation batches, matching/reversal/review/close workflows, and settlement exceptions are missing. Current reconciliation compares internal payment/refund records only.
- Billing report catalog is incomplete relative to the requested scope: no complete applicability, installment, recurring, bulk, adjustment, reversal, credit, bank-reconciliation, campus/program/course drill-down catalog. Existing invoice and collection reports do not satisfy those missing sources.
- Billing reminders, notification administration/read history/retry queue and Phase 16 inbox preference integration remain incomplete. External SMS/email delivery is not configured or claimed.
- There is no live payment gateway implementation. A provider selection, sandbox contract, credentials, webhook signature/replay policy and approval remain externally required.
- Full MySQL schema/migration/concurrency verification, browser/role/theme/accessibility coverage, provider tests and comprehensive security verification remain pending QA. Do not infer these from SQLite/local checks.

## Workstream traceability

| Workstream | Backend | Frontend | Database | Focused evidence | Status |
|---|---|---|---|---|---|
| A — Academic applicability | Draft lifecycle, scoped resolver, ambiguity/overlap rejection, preview and enrollment assignment hook | Staff rule list/create/activate/deactivate and student preview on lifecycle screen | Additive rule table and assignment link migration | Applicability test, 14 assertions; full P17 suite | PARTIAL |
| B — Proration and term billing | Integer calendar-day preview; institution policy can be disabled and audited | Policy form and preview result | Policy remains tenant setting; no invoice snapshot column | Existing focused preview tests | PARTIAL — preview does not modify charges |
| C — Installments | Create/publish/amend; append-only version history; paid state derived from invoice; adjustment reconciliation | Edit, publish, balances, version/history | Additive append-only event migration | P17 lifecycle + P18 payment reconciliation assertions | PARTIAL — cancellation and due-reminder flows absent |
| D — Recurring recovery | Period idempotency, scheduler, failed-run visibility, retry, alternate authorized operator | Plan management and retry/status controls | Recurring-run history tables | Failed-run recovery and scheduler tests in P17 | PARTIAL — full crash/overlap recovery not proven |
| E — Credits and allocation | Confirmed unapplied amount, allocation, refunds/reversal protections, statement derivation | Payment allocation and ledger views | Existing payment/allocation/refund schema | P18 cash, allocation, refund and reversal tests | PARTIAL — no independent immutable credit subledger |
| F — Corrections and reconciliation | Segregated full payment reversals; internal payment/refund reconciliation | Correction actions and internal reconciliation views | Existing reversal/event schema | P18 reversal and internal reconciliation tests | PARTIAL — no bank statement CSV batch workflow |
| G — Reports and PDF | Currency-safe invoice/collection reports; CSV/PDF; Phase15 allowlisted finance mappings | Billing report filters and exports | Existing domain sources | P17 report tests; Phase15 finance tests are in separate report | PARTIAL — requested complete report catalog/drilldowns absent |
| H — Notifications | In-app billing notifications with tenant and reference metadata, verified guardian recipient checks | Existing notification framework; lifecycle UI reflects actions | Laravel notifications table | Notification payload and P18 notification tests | PARTIAL — reminders, administration and Phase16 preference/inbox integration absent |
| I — Provider readiness | No live provider; no false-success callback path | No fake gateway UI | No provider schema added | No provider-specific test executed | EXTERNAL CONTRACT REQUIRED |

## Phase 24 finance integration notes

Use the existing billing records as the source of truth. See `../phase-24/FINANCE_INTEGRATION_CONTRACT.md`. Confirmed collections exclude pending and reversed payments, refunds count only after execution, and every monetary analytics aggregation must remain partitioned by currency. Do not expose raw SQL or combine unlike currencies.
