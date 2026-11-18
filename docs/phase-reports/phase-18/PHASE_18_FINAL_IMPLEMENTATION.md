# Phase 18 — Final Implementation Checkpoint

**Status: PARTIAL IMPLEMENTATION | FUNCTIONAL WORK REMAINS**
Checkpoint: 2026-11-18. The report distinguishes local SQLite evidence from unimplemented financial workflows and external provider dependencies.

## Implemented

- Cash collections and manual bank-transfer submissions; a transfer stays pending until independent verification.
- Payment allocation supports partial and multiple invoice allocation with tenant/student/currency checks, transaction locking, idempotency, and invoice balance updates.
- Confirmed excess payment remains as an unapplied balance. This is visible in the student ledger but is not a separate transferable credit account or immutable credit subledger.
- Receipts preserve the payment confirmation allocation snapshot. Refunds and full payment reversals use separate request/reviewer/executor permissions, immutable events, idempotency, audit and compensating balance changes. Refunded or reversed funds cannot be reused as confirmed credit.
- Verified guardians can view financial records only while their explicit financial authority remains active.
- Internal reconciliation reports payments/refunds by status, method, period, campus and currency; CSV export is scoped and audited. This does not import or match bank statements.
- Database notifications are emitted for payment/refund lifecycle events. External delivery is not claimed.

## Fresh verification executed

- `C:\php83\php.exe tests/run.php --phase=18 --profile=sqlite --no-report` — PASS, **7 tests / 275 assertions**.
- `C:\php83\php.exe tests/run.php --phase=17 --profile=sqlite --no-report` — PASS, **16 tests / 395 assertions**, including installment/payment reconciliation assertions.
- Composer validation — PASS. Focused PHPStan, TypeScript, targeted ESLint/Prettier and production Vite build — PASS.
- No MySQL database was contacted or modified; no persistent migration was run.

## Remaining implementation

- Dedicated immutable credit event ledger and credit refund/reversal workflow are incomplete; there is no cross-person/cross-tenant credit transfer.
- Neutral CSV bank statement template/import, duplicate statement detection, persisted batches/lines, deterministic suggestions, authorized manual matching/reversal, reviewed closure and exception exports are not implemented.
- Existing reconciliation is internal record reconciliation, not external settlement verification.
- No payment gateway provider, webhook handler, signature verifier, replay store or external payout implementation exists. `EXTERNAL PROVIDER DECISION REQUIRED`.
- Bank-specific formats, cash-drawer/shift, cheque handling, external payout rails and any bank verification policy require product/operations approval; no success behavior is fabricated.
- Full MySQL concurrency and browser/accessibility suites remain unverified QA work.

## Workstream traceability

| Area | Backend | Frontend | Database | Focused evidence | Status |
|---|---|---|---|---|---|
| Cash and manual transfer | Confirmed cash; pending transfer until independent review | Payment collection and review workspace | Payment and event tables | Phase 18 cash and transfer tests | IMPLEMENTED for internal workflow |
| Allocation and unapplied amount | Transactional partial/multi-invoice allocation with student/currency/scope checks | Allocation controls and student statement | Payment allocations, current unapplied balance | Phase 18 cash/allocation tests | PARTIAL — no independent credit event subledger |
| Refund/reversal corrections | Separate request/review/execute and immutable compensating history | Payment reversal/refund controls | Refund/reversal tables and events | Phase 18 refund/reversal tests | PARTIAL — manual payout reference; no external payout |
| Bank reconciliation | Internal records grouped by date, campus, method, status, currency | Internal reconciliation page and formula-safe CSV | Existing payment/refund sources | Phase 18 internal reconciliation test | PARTIAL — no statement import/matching/closure |
| Financial reports | Currency-separated internal summaries | Scoped report/CSV/PDF views | Existing billing records | Phase 17/18 local report assertions | PARTIAL — broader source catalog absent |
| Notifications | Internal database events; guardian authorization checked | Existing internal notifications | Laravel notifications | Payment/refund tests | PARTIAL — no reminder controls or Phase16 inbox integration |
| Online provider | No provider adapter or callback is enabled | No fake checkout UI | No provider state persisted | Not run | EXTERNAL CONTRACT REQUIRED |

## Integration boundary

Billing payment IDs, amounts, ISO currency, method/status, allocations, unapplied amount, executed refund amounts and reversal state are available from the existing billing domain. Phase 15 analytics must distinguish pending from collected funds, subtract executed refunds/reversals correctly, and group every monetary measure by currency. Phase 16 notification integration must reuse the existing communications inbox and preferences rather than introducing a second inbox. Details: `../phase-24/FINANCE_INTEGRATION_CONTRACT.md`.
