# Phase 18 Implementation Report

Updated: 2026-11-18

## Delivered in this continuation

- Added transactional payment recording, bank-transfer verification/rejection, unapplied payment allocation, receipt issue, event history, and database notifications using existing Phase 17 invoices.
- Added a separated refund lifecycle: request, independent approval/rejection, separately authorized manual execution, immutable refund-allocation reversal, audit history, and refund-status notifications.
- Added explicit guardian financial visibility (`can_view_financial_records`) independent of general profile visibility, with SIS management controls and immediate revocation behavior.
- Added payment administration and learner/guardian payment history/receipt surfaces.
- Added an internal date/campus reconciliation page and formula-safe streamed CSV export. Payment amounts are grouped by currency/method/status; refunds are classified by execution date. Export is authorized and audited.
- Added focused Phase 18 feature coverage for financial and consent invariants.

## Main files

- Domain: `app/Domain/Billing/BillingPaymentWorkflow.php`, `BillingPaymentRefundWorkflow.php`, `BillingMoney.php`.
- HTTP: `app/Http/Controllers/Workspace/BillingPaymentController.php`, existing `BillingController.php`.
- Models: `BillingPayment*`, `BillingPaymentRefund*`, `SisStudentGuardian.php`.
- Notifications: `BillingPaymentStatusNotification.php`, `BillingRefundStatusNotification.php`.
- UI: `resources/js/pages/workspace/billing/payments.tsx`, `reconciliation.tsx`, `my-invoices.tsx`, SIS student detail.
- Schema: additive `2026_11_20_000000` through `000004` migrations.
- Tests: `tests/Feature/Phase18PaymentWorkflowTest.php`.

## Financial safety decisions

- Internal cash collections are confirmed by the authorized collector and transactional invoice allocations occur before the request returns.
- A bank transfer submission remains pending and cannot settle any invoice until a different authorized reviewer verifies it.
- Amount arithmetic uses integer minor units and invoice rows are locked during allocation/refund adjustment.
- Refund request, review, and execution are distinct actors. Execution records an operator-supplied payout reference and does not call an external financial rail.
- Current verified guardians see payment and refund notifications only if their relationship is verified, not revoked, and explicit financial visibility is granted.
- Receipts are private PDF responses; the receipt reflects the immutable confirmation snapshot rather than current post-refund balances.
- Internal reconciliation is not bank-statement, provider-settlement, or ledger reconciliation.

## Database status

Migrations are additive in source and were applied by the Phase 18 SQLite test runner for each isolated test. No MySQL target was identified/authorized for Phase 18 in this continuation; no MySQL migrations or database writes were attempted. See `DATABASE_AUDIT.md`.
