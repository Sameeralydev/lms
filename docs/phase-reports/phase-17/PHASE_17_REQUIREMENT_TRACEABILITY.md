# Phase 17 Fees & Billing — Development Traceability

Updated: 2026-11-18

Source: user-provided Phase 17 Fees & Billing development specification. No separate approved Phase 17 contract was found in the repository during this continuation; section references below point to that provided specification, not to an independently verified roadmap document.

| Specification area | Status | Implemented evidence | Remaining implementation |
|---|---|---|---|
| §4–5 Fee heads and fee structures | PARTIAL | Tenant/institution-scoped fee heads; draft structure creation and editing; activation; active structures and heads validated during invoice issuance; issued lines snapshot labels and amounts. `BillingController`, `BillingInvoiceIssuer`, billing migrations, billing UI. | Academic-session/program/course/batch applicability, fee groups, version/history UX, effective-dated replacement workflow, and richer applicability rules. |
| §6 Student fee assignment | MISSING | Invoice issuance validates an existing SIS student and campus. | Enrollment-driven fee assignments, admission/enrollment hooks, transfer/withdrawal proration and historical assignments. |
| §7 Discounts, scholarships, concessions | MISSING | `discount_total` is stored as zero in current invoice snapshots. | Concession records, approval/revocation, calculations, limits, and audit lifecycle. |
| §8 Invoice/voucher generation | PARTIAL | Single-student invoice issuance; integer minor-unit calculation; payload-bound idempotency; duplicate period prevention; unique invoice identifiers; immutable line snapshots; unpaid cancellation; printable PDF. | Bulk/recurring generation, invoice revision history, voucher workflows, and approved adjustment/credit-note paths. |
| §9 Installments | MISSING | No installment implementation. | Installment plans, schedules, amendments, lifecycle and notifications. |
| §10 Financial calculation | PARTIAL | `BillingMoney` parses non-negative two-decimal values into integer minor units; invoice line and total arithmetic stays in integer cents. | Discounts, credits, payment allocation, other approved adjustments, and broader reconciliation invariants. |
| §11 Invoice lifecycle | PARTIAL | `issued` and `cancelled` states; cancellation is limited to unpaid invoices and preserves items. | Partial/paid/overdue/adjusted state transitions require Phase 18 payment integration or approved Phase 17 adjustment behavior. |
| §12 Late fees | NOT IMPLEMENTED | No policy or rate was invented because no verified rules were available. | Implement only when the approved policy/rates and required workflow are confirmed. |
| §13 Adjustments/reversals | PARTIAL | Audited cancellation for unpaid issued invoices. | Credit/debit adjustments, approval and reversal history; no destructive edits to issued documents. |
| §14 Student/guardian portal | PARTIAL | Own learner invoices and active verified-guardian-authorized learner invoices; private invoice detail/PDF routes enforce identity, institution and campus scope. | Broader portal billing history, statements, payment status from Phase 18 and relevant notifications. |
| §15–16 Bulk/automatic billing | MISSING | Manual single-invoice issue only. | Bulk, recurring and admission/enrollment-triggered billing with bounded jobs, retries and concurrency controls. |
| §17 Student financial ledger | MISSING | Invoice snapshot only. | Immutable ledger/postings and statement reconciliation; do not duplicate Phase 18 payment allocation. |
| §18 Billing reports | PARTIAL | Invoice report with tenant/campus scope, date/status/campus/search filters, database-backed totals, pagination, audit event and streamed formula-safe CSV. | Broader aging, course/program, concessions, installments and generation reports; PDF report output and richer drilldowns. |
| §19 Notifications | MISSING | No billing notification event is emitted. | Internal notification/outbox integration for approved billing events; external delivery remains provider-dependent. |
| §20 RBAC | PARTIAL | Billing permission catalog, finance-manager template, owner/admin grants, learner/guardian own-invoice permission, server-side route checks. | Accountant/cashier separation, concession/adjustment permissions connected to functional workflows, separation-of-duties approvals. |
| §21 Frontend | PARTIAL | Billing dashboard, fee head management, structure create/edit/activate, invoice issue/list/detail/cancel, learner/guardian list/detail/print and report filters/export. | Screens for missing assignments, concessions, installments, adjustments, statements, bulk billing, notifications and richer reporting. |
| §22–23 Backend/schema | PARTIAL | Five additive foundation tables with tenant/institution/campus/student foreign keys, composite constraints, indexes and restrict-delete behavior. | Tables/contracts for the missing workflows above; MySQL migration/postflight remains unexecuted. |
| §24–25 Phase 18/cross-phase interfaces | PARTIAL | Invoice total/outstanding fields; current code does not fabricate payments. | Formal payment allocation events/contracts and balance recalculation when Phase 18 interfaces are available. |

## Focused implementation evidence

- `php tests/run.php --phase=17 --profile=sqlite --no-report` — PASS, 5 tests / 144 assertions (2026-11-18).
- Focused PHPStan over billing domain/controller/models — PASS after model/query annotations (2026-11-18).
- `npm.cmd run typecheck` — PASS after UI prop corrections (2026-11-18).
- Focused ESLint and Prettier checks — PASS (2026-11-18).
- Production Vite build — PASS after initial UI integration; rerun after final changes is recorded in the development handoff.
- PHP syntax checks and Pint — PASS for the listed billing PHP files (2026-11-18).
- No MySQL connection, migration execution, broad browser matrix, concurrency campaign, or full product regression is claimed by this continuation.

## Database and Git safety

The billing migrations are present but were not executed against any database. No `migrate`, `db`, reset, seed, staging, commit, push, or force operation was run. Current branch is `developer-a/phase-17`; the worktree contains substantial pre-existing changes across other phases and those were preserved.

## Development status

Phase 17 remains implementation-incomplete because multiple approved requirements above are not implemented. The external/comprehensive tests listed in `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md` are verification work only and do not convert missing functionality into QA deferrals.
