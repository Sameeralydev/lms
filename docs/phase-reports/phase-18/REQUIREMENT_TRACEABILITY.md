# Phase 18 Payments, Collections & Reconciliation — Development Traceability

Updated: 2026-11-18

## Source and scope

Source reviewed: user-provided Phase 18 specification pasted in the Codex attachment `ecad7035-38c4-42cc-bf4c-614ccfccce16/Pasted text.txt`. No independent approved provider selection, bank-statement format, reconciliation policy, cashier-session policy, cheque policy, or separate Phase 18 roadmap was present in the repository. Consequently, this implementation does not invent a gateway, bank settlement, cash-drawer, or cheque workflow.

## Requirement mapping

| Area | Status | Implementation evidence | Remaining implementation / dependency |
|---|---|---|---|
| Payment record and cash collection | IMPLEMENTED | `BillingPaymentWorkflow::record`; payment model; `/billing/payments`; idempotency key and immutable event; confirmed cash creates receipt and allocates transactionally. | Verify target MySQL deployment and broader browser journey. |
| Manual bank transfer submission | IMPLEMENTED | Transfer is `pending_verification`, requires a reference, cannot allocate or settle while pending. | External bank evidence upload and bank account directory are not implemented because no approved evidence/storage policy was found. |
| Independent verification/rejection | IMPLEMENTED | `verifyBankTransfer`/`rejectBankTransfer`; submitter cannot review; verification event and audit; retry-safe request keys. | MySQL concurrency verification remains pending. |
| Partial/multi-invoice allocation and unapplied balance | IMPLEMENTED | `applyAllocations` uses integer minor units, locks invoices, checks student/institution/campus/currency/status and outstanding balance; database uniqueness prevents duplicate allocation keys. | MySQL-specific locking/concurrency and wider Phase 17 installment/credit integration remain unverified. |
| Advance amount | IMPLEMENTED, LIMITED | Confirmed unallocated amount remains on the payment as an unapplied balance; it is not described as settled against an invoice. | A separate student credit ledger, transfer, and future-credit policy remain absent. |
| Receipt | IMPLEMENTED | Authorized staff receipt and own-portal receipt use a PDF response; receipt uses immutable confirmation-event allocation/unapplied snapshot and is private/no-store. | Institution branding and print/browser coverage remain pending. |
| Refund request/review/execution | IMPLEMENTED, MANUAL | Separate requester, approver, executor; request reserves eligible amount; audited events; immutable allocation reversals; manual payout reference; repeated matching execution is idempotent. | No external payout rail is invoked; refund edge cases and MySQL concurrency remain QA pending. |
| Refund notifications | IMPLEMENTED | Database notification to the learner and currently verified guardians who have explicit `can_view_financial_records`; emitted for request, approval, rejection, execution. | External email/SMS delivery is not configured. |
| Payment notifications | IMPLEMENTED, INTERNAL | Database notifications for cash confirmation, bank pending, verification and rejection; guardian financial consent is checked. | No external notification delivery claim. |
| Internal period reconciliation | IMPLEMENTED | `/billing/reconciliation` groups recorded payment amounts by method, status, and currency; executed refunds use their execution date; campus and period filters; paginated details; formula-safe CSV and export audit. | This is internal record reconciliation. It does not match bank statements, provider settlements, or close a reconciliation batch. |
| Reversal and correction | PARTIAL | Refund execution applies compensating invoice-balance changes while preserving payment and allocation history. | A separate non-refund reversal/adjustment lifecycle and approval policy are not implemented. |
| Student/guardian financial visibility | IMPLEMENTED | Own invoice/payment history and receipt; verified guardian relation requires explicit financial visibility; revocation removes access immediately. | Full portal/browser matrix remains pending. |
| Tenant/campus authorization and audit | IMPLEMENTED, FOCUSED | Tenant context, permission middleware and service assertions scope institution/campus/student/invoice; audit records cover payment/refund/reconciliation export operations. | Broader adversarial route matrix and MySQL cross-tenant test campaign remain pending. |
| Online gateway and callback processing | NOT IMPLEMENTED — APPROVAL/INTEGRATION DEPENDENT | No provider adapter, contract, approved provider or provider credentials were found; no client success flag is trusted. | Product owner must select/approve a provider and sandbox/webhook contract before implementation and live verification. |
| Cheques, cashier shifts, bank reconciliation batches, settlement matching | NOT IMPLEMENTED — REQUIREMENTS UNVERIFIED | No verified product policy/model/provider contract was found. | Supply approved lifecycle, roles, records, import formats, and closeout rules before implementation. |
| Database schema | IMPLEMENTED IN CODE; MYSQL NOT RUN | Additive migrations `2026_11_20_000000` through `000004` add payment, allocation, event, refund, refund-allocation and guardian-consent structures/permission backfills. Scoped FKs, unique keys, indexes, restrict-delete behavior. | No MySQL target ownership configured/verified in this continuation; migrations were exercised only through the centralized SQLite test runner. |
| Frontend collection/reconciliation | IMPLEMENTED, FOCUSED | Payment workspace, collection and review forms, allocation/refund operations, learner receipts, reconciliation filter/detail tables and CSV link. | Full authenticated browser role/theme/accessibility campaign remains pending. |

## Essential verification evidence

- `php tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, 6 tests / 134 assertions (2026-11-18). Covers cash idempotency and settlement, bank-transfer review, foreign invoice rejection with no mutation, segregated refund execution and invoice reversal, guardian consent/revocation, and scoped reconciliation/CSV export.
- `vendor/bin/pint ...` on changed PHP files — PASS.
- PHP syntax checks on payment controller, payment/refund services, migrations, notification, and feature tests — PASS.
- `npm.cmd run typecheck` — ran before final reconciliation page was added; final rerun is recorded in the continuation handoff, not inferred from that earlier run.
- PHPStan, ESLint, Prettier and Vite production build were invoked during development. Their final exit evidence is pending until the final checks are rerun after the last edits.
- No MySQL connection, MySQL migration, production payment, gateway request, bank matching, or external notification was executed.

## Database and Git safety

- Current branch: `developer-a/phase-18`.
- No environment file contents or secrets were read into output.
- No MySQL service/schema was contacted or mutated; no migrations were run against development or MySQL test schemas.
- No `migrate:fresh`, `db:wipe`, reset, destructive seed, staging, commit, push, or force operation was performed.
- Pre-existing Phase 15/17 and other worktree changes were preserved.

## Development status

Phase 18 is not declared complete. Internal cash/manual-bank collection, scoped allocations, receipts, manual refunds, consent-gated portal visibility, notifications, and internal period reporting are implemented. Provider payments, bank statement/settlement reconciliation, separate reversal policy, and any additional requirement that is absent from the verifiable source contract remain unimplemented or dependent on product-owner decisions; broad MySQL/browser verification is also pending.

## Verification refresh — 2026-11-18

Supersedes the earlier assertion count above: the latest focused run is 6 tests / 140 assertions, exit 0. Focused PHPStan, TypeScript, ESLint, Prettier, Pint, Vite production build, billing route listing, and `git diff --check` passed. See `TEST_REPORT.md` for the exact commands and limitations.

## Latest payment reversal and ledger continuation (2026-11-18)

- **Payment reversal/correction:** IMPLEMENTED for a full reversal of a confirmed payment. `BillingPaymentWorkflow` provides reasoned, idempotent request; independent approve/reject; distinct execution; tenant/campus assertions; row locks; balance restoration; replay safety; immutable payment events and audit events. Pending refunds/reversals block conflicting operations. New permission keys: `billing.payments.reverse.request`, `.approve`, `.execute`; new migration `2026_11_20_000005_create_billing_payment_reversals.php`; payment UI displays history and role-gated actions. Executed refunds are reflected by reversing only the remaining net allocated amounts. No source payment/allocation/receipt is deleted or edited; receipts remain visible as void after reversal.
- **Student credit:** PARTIAL. The statement now includes current unapplied confirmed payment balance as a credit and avoids counting it again once allocated. It does not create transferable independent credit accounts or credit transfers; those remain a policy-backed feature.
- **Financial reconciliation:** Focused SQLite proves invoice balance is restored from 80 paid to 0 paid / 100 outstanding after reversal, and the ledger returns to 100 net receivable. Full MySQL locking and independent-session race validation remain pending.
- **Fresh tests:** `php tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, 7 tests / 207 assertions. `Phase18PaymentWorkflowTest` now covers separation of requester/reviewer/executor, refund lockout, duplicate execution, immutable payment history, receipt preservation, audit and ledger balance.
- **Fresh code checks:** Focused PHPStan, Pint, PHP syntax, TypeScript, targeted ESLint/Prettier, build, route listing, and `git diff --check` passed; production build transformed 706 modules.

Remaining verified omissions include persisted student credit transfers/reversals, installment-level reconciliation/amendment history, external bank statement batches, gateway callbacks, cashier/cheque workflows, and comprehensive MySQL/browser verification. External gateway and bank import work depend on owner-approved providers, formats, controls, and credentials.

Phase 18 remains **DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**; this update does not claim complete Phase 18 acceptance.
