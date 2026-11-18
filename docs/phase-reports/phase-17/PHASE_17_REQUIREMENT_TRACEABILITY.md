# Phase 17 Fees & Billing â€” Development Traceability

Updated: 2026-11-18

Source: user-provided Phase 17 Fees & Billing development specification. No separate approved Phase 17 contract was found in the repository during this continuation; section references below point to that provided specification, not to an independently verified roadmap document.

| Specification area | Status | Implemented evidence | Remaining implementation |
|---|---|---|---|
| Â§4â€“5 Fee heads and fee structures | PARTIAL | Tenant/institution-scoped fee heads; draft structure creation and editing; activation; active structures and heads validated during invoice issuance; issued lines snapshot labels and amounts. `BillingController`, `BillingInvoiceIssuer`, billing migrations, billing UI. | Academic-session/program/course/batch applicability, fee groups, version/history UX, effective-dated replacement workflow, and richer applicability rules. |
| Â§6 Student fee assignment | PARTIAL | Tenant/institution/campus-scoped manual assignments; active structure and student validation; optional enrollment reference; idempotency and duplicate period constraints; staff lifecycle form. `BillingLifecycleService`, `BillingLifecycleController`, `BillingFeeAssignment`, lifecycle migration and page. | Enrollment/admission lifecycle hooks, transfer/withdrawal proration, assignment history UI, bulk/recurring assignment generation, and MySQL verification. |
| Â§7 Discounts, scholarships, concessions | PARTIAL | Pending concession requests, independent review, revocation for future invoices, fixed/percentage integer-minor-unit calculation and immutable invoice calculation snapshot/application; audit events and lifecycle UI. | Notification delivery, policy-specific eligibility/limits, additional approval matrix, MySQL and browser verification. |
| Â§8 Invoice/voucher generation | PARTIAL | Single-student invoice issuance; integer minor-unit calculation; payload-bound idempotency; duplicate period prevention; unique invoice identifiers; immutable line and concession snapshots; unpaid cancellation; printable PDF; fee assignment linkage. | Bulk/recurring generation, invoice revision history, voucher workflows, and MySQL verification. |
| Â§9 Installments | PARTIAL | Transactional draft schedules of 1â€“36 installments; positive amounts, ordered dates and exact invoice amount reconciliation; separate publication audit; lifecycle screen supports a two-part schedule. | Amendments/rescheduling history, payment/refund integration with per-installment balances, notifications, broader UI and MySQL verification. |
| Â§10 Financial calculation | PARTIAL | `BillingMoney` parses two-decimal values into integer minor units; subtotal, discounts, invoice balances and approved adjustments are calculated without binary floating point; focused test covers discount snapshot and a posted credit. | Transfer/withdrawal proration policy, additional currency rules, MySQL monetary/concurrency verification. |
| Â§11 Invoice lifecycle | PARTIAL | `issued` and `cancelled` states; unpaid cancellation preserves items; independently requested/reviewed/posted adjustments update `adjusted_total` and `outstanding_amount` without rewriting original total/payment history. | Adjustment reversal lifecycle, payment/installment reconciliation details, wider lifecycle reporting and MySQL verification. |
| Â§12 Late fees | NOT IMPLEMENTED | No policy or rate was invented because no verified rules were available. | Implement only when the approved policy/rates and required workflow are confirmed. |
| Â§13 Adjustments/reversals | PARTIAL | Credit/debit request, independent approval and third-person posting; append event history and audit; amount and overpayment guards. | Adjustment reversal/correction workflow and additional separation-of-duties policy; no destructive edit to issued documents. |
| Â§14 Student/guardian portal | PARTIAL | Own learner invoices and active verified-guardian-authorized learner invoices; private invoice detail/PDF routes enforce identity, institution and campus scope. | Broader portal billing history, statements, payment status from Phase 18 and relevant notifications. |
| Â§15â€“16 Bulk/automatic billing | MISSING | Manual single-invoice issue only. | Bulk, recurring and admission/enrollment-triggered billing with bounded jobs, retries and concurrency controls. |
| Â§17 Student financial ledger | PARTIAL | Tenant/institution/campus-scoped statement composes invoices, confirmed allocations, executed refund reversals, posted adjustments and informational unapplied balances; student ledger page; signed running balances. This is a derived statement, not a separate immutable posting ledger. | Own learner/guardian statement route, pagination/export, reconciliation controls and MySQL verification. |
| Â§18 Billing reports | PARTIAL | Existing invoice report remains tenant/campus scoped with date/status/campus/search filters, database-backed totals, pagination, audited formula-safe CSV. Lifecycle lists assignments, concessions, installment schedules and adjustments. | Dedicated aging, course/program, concession, installment, ledger and generation reports; PDF output and record-level drilldowns. |
| Â§19 Notifications | MISSING | No new billing lifecycle notification event is emitted. Phase 18 payment notifications remain unchanged. | Internal notification/outbox integration for assignment, invoice, concession, installment and adjustment events; external delivery remains provider-dependent. |
| Â§20 RBAC | PARTIAL | Billing permission catalog, finance-manager template, owner/admin grants, learner/guardian own-invoice permission, server-side route checks. | Accountant/cashier separation, concession/adjustment permissions connected to functional workflows, separation-of-duties approvals. |
| Â§21 Frontend | PARTIAL | Billing dashboard, fee head/structure management, invoice issue/list/detail/cancel, learner/guardian invoice list/detail/print, report filters/export, and lifecycle/ledger pages with permission-aware assignment, concession, installment and adjustment actions. | Bulk/recurring workflows, richer reports, student/guardian ledger, comprehensive browser/accessibility coverage. |
| Â§22â€“23 Backend/schema | PARTIAL | Existing foundation plus additive lifecycle tables for assignments, concessions/events/applications, installments, adjustments/events; invoice balance/snapshot fields and foreign keys; permission backfill. SQLite migration/test execution passes. | Guarded MySQL migration/postflight and MySQL constraint/index evidence remain pending; further policy-specific lifecycle/schema work remains. |
| Â§24â€“25 Phase 18/cross-phase interfaces | PARTIAL | Invoice total/outstanding fields; current code does not fabricate payments. | Formal payment allocation events/contracts and balance recalculation when Phase 18 interfaces are available. |

## Earlier billing-foundation evidence (historical)

- `php tests/run.php --phase=17 --profile=sqlite --no-report` â€” PASS, 5 tests / 144 assertions (2026-11-18).
- Focused PHPStan over billing domain/controller/models â€” PASS after model/query annotations (2026-11-18).
- `npm.cmd run typecheck` â€” PASS after UI prop corrections (2026-11-18).
- Focused ESLint and Prettier checks â€” PASS (2026-11-18).
- Production Vite build â€” PASS after initial UI integration; rerun after final changes is recorded in the development handoff.
- PHP syntax checks and Pint â€” PASS for the listed billing PHP files (2026-11-18).
- No MySQL connection, migration execution, broad browser matrix, concurrency campaign, or full product regression is claimed by this continuation.

## Cumulative branch continuation â€” 2026-11-18

- Source-only recovery copy created outside the repository before staging: `C:\xampp\htdocs\lms-preservation-20261118-134049`.
- Branch `developer-a/integrated` already existed and was checked out at `09f319c`, equal to `origin/developer-a/integrated`; no branch history was rewritten.
- New lifecycle code adds the focused staff workflow described above. It does not complete bulk/recurring billing, automatic enrollment hooks, notification delivery, the full reporting catalog, or all student/guardian UX.
- `php tests/run.php --phase=17 --profile=sqlite --no-report` â€” PASS, 6 tests / 188 assertions after this continuation. This isolated SQLite run also verified the new lifecycle migration and the assignment â†’ concession approval/application â†’ invoice â†’ installment publication â†’ independent adjustment approval/posting â†’ ledger flow.
- Focused PHPStan, Pint, PHP syntax, route registration, TypeScript, ESLint, Prettier, Vite production build, Composer validation/platform checks and `git diff --check` passed. No MySQL operation or migration against a persistent database was performed.
- New work is preserved in the cumulative branch commit; see `docs/DEVELOPER_A_HANDOFF.md` for branch/commit and cross-phase status.

## Database and Git safety

The billing migrations were exercised only through the isolated SQLite test runner. They were not run against MySQL or the development database. No `migrate` command or database reset was run. Git commits and a normal branch push are documented in the cumulative handoff.

## Development status

Phase 17 remains implementation-incomplete because multiple requirements above remain unimplemented or unverified. External/comprehensive tests listed in `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md` are verification work only and do not convert missing functionality into QA deferrals.

## 2026-11-18 continuation: billing delivery slices

This continuation added functional billing paths while retaining the earlier reports above as historical evidence:

- Enrollment assessment is now an opt-in tenant setting pointing to an active one-time fee structure. Confirmed enrollment, approval, waitlist acceptance, and confirmed bulk registration create an idempotent fee assignment only; they do not issue an invoice or collect money. Evidence: `BillingEnrollmentAssessment`, `CourseEnrollmentWorkflow`, and the enrollment feature assertion.
- Bulk invoice issuance accepts at most 200 distinct learner IDs, calls the existing invoice issuer per learner with deterministic idempotency keys, preserves successful rows when another learner fails, and returns a bounded failed-ID list.
- Recurring plans support explicit monthly/annual structures, dates, timezone, due offset, pause/resume/cancel, run history, failure recording, and scheduled idempotent generation. The processor only runs when the plan creator still has an active, scoped membership and invoice permission. There is no invented school-term cadence or late-fee policy.
- Adjustment reversals use a pending compensating adjustment, independent review/posting, immutable event/audit history, a uniqueness constraint against duplicate reversal, and preservation of the original record. Invoices with posted adjustment history cannot be cancelled.
- In-app database notifications now cover invoice issue and selected concession, installment, and adjustment events. Invoice issue notifications include only the learner and guardians whose financial authority is currently verified and not revoked.
- Learners and currently authorized guardians can view an explicitly scoped statement and download CSV; staff exports are audited. The report page now includes outstanding aging buckets.
- Recurring processing accepts either a tenant-wide membership or a campus-scoped membership matching the learner's campus. All changed PHP is checked by focused PHPStan/Pint and PHP syntax validation.

Fresh focused evidence from this continuation:

- `php tests/run.php --phase=17 --profile=sqlite --no-report` — PASS, 9 tests / 266 assertions.
- `php artisan test --filter=CourseEnrollmentTest` — PASS, 9 tests / 190 assertions.
- `php tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, 6 tests / 140 assertions; Phase 18 sources were not changed.
- Focused PHPStan — PASS, no errors; Pint — PASS; PHP syntax checks — PASS.
- `npm.cmd run typecheck`, focused ESLint, focused Prettier, and `npm.cmd run build` — PASS (706 modules transformed).
- `php artisan route:list --path=billing --except-vendor` — PASS, 48 billing routes registered. `git diff --check` — PASS.

No MySQL preflight/migration or persistent database change, ClamAV/EICAR check, full browser suite, or broad product regression was run. Those items remain **QA PENDING — EXTERNAL ENVIRONMENT** where they require external infrastructure, and otherwise pending comprehensive QA. No claim of full Phase 17 completion is made.

Remaining functional policy/product work includes transfer/withdrawal proration, academic/program applicability and installment amendments/reconciliation, wider billing report catalog and record drill-down/PDF, complete billing notification lifecycle/read history, staff recurring-plan recovery/ownership handoff, and product-approved late-fee/term-billing rules. The original approved Phase 17 source document is not present in the repository, so no requirement count is inferred beyond the user-provided specification and repository evidence.

Current phase status: **PHASE 17 — DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**.

## Focused integration continuation (2026-11-18)

The existing invoice report summary now emits currency-partitioned monetary aggregates and the billing report UI labels each amount with its currency. This closes the currency-mixing defect for the current invoice summary only; it does not complete the missing report catalog, record drill-down, or PDF workflow. Regression evidence: the Phase 17 isolated SQLite suite passed 11 tests / 293 assertions, including USD/EUR invoice summary separation. Billing database notifications now carry explicit tenant identity for future inbox integration, with a focused payload test; this remains database-only delivery and does not add a communications inbox or due/overdue reminder lifecycle. See `PHASE_17_18_CONTINUATION_HANDOFF.md` for fresh local check results and outstanding scope. Phase 17 remains **DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**.

## 2026-11-18 financial integration continuation

This continuation adds a controlled payment-reversal lifecycle to the existing Phase 18 payment engine. Requests require a reason and idempotency key; review and execution are separate permissions and actors. Execution locks the payment and affected invoices, reverses only the net allocated amount after completed allocation refunds, clears the remaining unapplied balance, and marks the original payment reversed without editing or deleting its receipt, events, or allocations. Pending refunds and reversals block conflicting operations. The issued receipt stays available and is labeled void after reversal. New reversal events reconcile in the derived student statement.

The student statement now counts the current unapplied payment amount as a credit. A regression confirms a later allocation moves that amount from unapplied credit to invoice allocation without changing total credit or net balance.

Fresh focused verification (2026-11-18):

- `php tests/run.php --phase=17 --profile=sqlite --no-report` — PASS, 10 tests / 268 assertions.
- `php tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, 7 tests / 207 assertions.
- `php artisan test --filter=CourseEnrollmentTest` — PASS, 9 tests / 190 assertions.
- Focused PHPStan — PASS, no errors; Pint — PASS; changed PHP syntax — PASS.
- `npm.cmd run typecheck`, focused ESLint, focused Prettier, and `npm.cmd run build` — PASS; Vite transformed 706 modules.
- `composer validate --no-check-publish` and `composer check-platform-reqs` — PASS.
- `php artisan route:list --path=billing --except-vendor` — PASS, 51 routes listed; `git diff --check` — PASS.

The Phase 17/18 SQLite runners exercise isolated test databases. No development or MySQL schema was contacted, and no persistent migration was executed. No full Chromium journey, six-theme accessibility matrix, MySQL concurrency/ownership validation, hosted CI, external bank statement import, payment gateway, or external payout was tested.

Still incomplete: academic/session/program fee applicability; configured proration policy and previews; installment amendments/history and due reminders; student credit transfers and reversals as a separately persisted credit ledger; bulk billing preview/history and cohort filters; wider financial report catalog/drill-down/PDF; automated due/overdue reminders and delivery history; and recurring-plan operator reassignment/recovery. Late fees, term cadence, external bank formats/matching, cheque/cashier policy, and gateway integration remain policy/provider decisions. Existing installment schedules are not yet a source of per-installment settlement balances; invoice balances remain authoritative.

Phase 18 reversal capability is now implemented for full payment reversal through a compensating lifecycle; it is not a bank transfer reversal, refund payout, or partial correction. Tenant/campus scoped routes and three payment reversal permissions enforce request, independent review, and third-party execution.

Current status remains **PHASE 17 — DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS** and **PHASE 18 — DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**. MySQL and browser verification remain pending; missing functionality above is not classified as QA-only work.
