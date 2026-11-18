# Phase 18 Database Audit

Updated: 2026-11-18

## Additive migrations

- `2026_11_20_000000_create_payment_collection_foundation.php`: payment, allocation, and immutable payment event tables; tenant/institution/campus/student/invoice/user foreign keys; idempotency/reference/receipt uniqueness; reporting indexes; restrictive history deletion.
- `2026_11_20_000001_backfill_billing_payment_permissions.php`: payment permission backfill.
- `2026_11_20_000002_create_billing_payment_refunds.php`: refund and immutable refund allocation tables; scoped foreign keys; unique request, refund number, and payout reference constraints.
- `2026_11_20_000003_backfill_billing_refund_permissions.php`: refund permission backfill.
- `2026_11_20_000004_add_guardian_financial_visibility.php`: explicit fail-closed guardian financial-consent boolean, default false.

## Execution and safety

- SQLite migration execution occurred only through `php tests/run.php --phase=18 --profile=sqlite --no-report`, which runs the isolated test profile.
- MySQL server identity, grants, ownership, migration status, indexes, and foreign keys were not verified in this continuation. No MySQL connection or migration was attempted.
- No existing development or prior-phase database was touched.
- Migration rollback methods exist as framework conventions but were not invoked.

## MySQL verification prerequisite

Provision/authorize a dedicated Phase 18 disposable MySQL test schema, configure it through the approved centralized testing profile (not a phase-specific environment file), and add a guarded Phase 18 target/ownership lifecycle before invoking any destructive test setup. Then run read-only identity/grant/ownership preflight, review migration inventory, guarded migrations/tests, and postflight ownership checks. Do not point Phase 18 test commands at the development database.
