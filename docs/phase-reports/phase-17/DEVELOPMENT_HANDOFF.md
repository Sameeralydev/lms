# Phase 17 Development Handoff

Updated: 2026-11-18

## Implemented in the current uncommitted branch

- Fee-head create, rename and activate/deactivate operations.
- Fee-structure draft create/edit/activate. Activated structures reject edits; future changes require a new draft.
- Server-side invoice calculation using integer minor units, a payload-bound idempotency key, invoice-period uniqueness and immutable invoice-line snapshots.
- Unpaid invoice cancellation with reason and audit event. Payment collection/allocation remains outside this phase.
- Staff billing dashboard and invoice list/detail/private printable PDF.
- Learner and verified guardian invoice list/detail/PDF, with own-student, guardian-authority, institution and campus checks.
- Invoice report filters, summary measures, pagination and audited streamed CSV export with spreadsheet-formula escaping.
- Finance-manager role template correction and billing permissions.

## Key files

- Backend: `app/Domain/Billing/`, `app/Http/Controllers/Workspace/BillingController.php`
- Models: `app/Models/BillingFeeHead.php`, `BillingFeeStructure.php`, `BillingFeeStructureItem.php`, `BillingInvoice.php`, `BillingInvoiceItem.php`
- Migrations: `database/migrations/2026_11_19_000000_create_billing_foundation.php`, `2026_11_19_000001_backfill_billing_permissions.php`
- UI: `resources/js/pages/workspace/billing/`, `resources/js/layouts/workspace-layout.tsx`
- Routes and permissions: `routes/web.php`, `app/Domain/Identity/PermissionCatalog.php`
- Focused tests: `tests/Feature/Phase17BillingFoundationTest.php`

## Required next implementation work

Student fee assignments and lifecycle hooks; concessions/scholarships; bulk and recurring billing; installment schedules; adjustment/credit-note workflows; ledger/statements; billing notifications; additional reports and PDF output; formal Phase 18 payment contracts. See the traceability matrix for detailed status.

## Environment and database

No Phase 17 MySQL environment verification or migration was performed. The additive migration files have only been syntax-reviewed; target identity, ownership and migration status must be verified through the established guarded database process before execution. No other database was modified.

## Git handoff

- Branch: `developer-a/phase-17` (existing branch at the same base as `main`; no new commit created in this continuation).
- No files were staged, committed, pushed, or force-pushed. The worktree includes pre-existing uncommitted changes from other phases; preserve them during later integration.
- Do not merge Phase 17 into the integration branch until the owner reviews scope and outstanding financial workflows.

## Phase 18 boundary

The current Phase 17 code records issued invoice amounts and outstanding balances only. It does not claim payment acceptance, allocation, receipts, refund, settlement or reconciliation. Phase 18 should integrate confirmed payment-allocation references/events and recalculate invoice balances without rewriting immutable invoice-line snapshots.

## Current conclusion

**PHASE 17 — DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**
