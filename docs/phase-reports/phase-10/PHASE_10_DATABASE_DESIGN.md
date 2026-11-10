# Phase 10 Database Design

## Additive schema

`2026_11_01_000000_create_attendance_foundation.php` defines nine tenant-owned tables: `attendance_policies`, `attendance_policy_versions`, `attendance_status_definitions`, `attendance_registers`, `attendance_entries`, `attendance_correction_requests`, `attendance_entry_revisions`, `attendance_justifications`, and `attendance_outbox_events`.

`2026_11_01_000001_backfill_attendance_permissions.php` adds attendance permissions and role mappings. `2026_11_01_000002_add_attendance_outbox_delivery_leases.php` adds `locked_at` and a lease index for safe retry recovery. `2026_11_01_000003_create_attendance_justification_evidence.php` adds private evidence metadata and tenant-bound integrity constraints. Migrations are additive and use named foreign keys/indexes.

## Integrity conventions

- Tenant identity is explicit on attendance rows and composite foreign keys bind children to same-tenant parents.
- One register is unique per tenant, authoritative meeting occurrence and date.
- One learner entry is unique per tenant/register/student.
- Policy versions snapshot status semantics so later changes do not rewrite historical meaning.
- Revisions and audit events preserve changes; finalized values are changed only through controlled workflows.

## Verification status

SQLite migrations and behavior were exercised by the automated Laravel suite. Guarded MySQL 8.4.11 execution verified the Phase 10 migration inventory and schema: 171 objects / 50 migrations. Postflight ownership fingerprint was unchanged: `cc78ece2e30238d3f4818b50e0105f22511665f21b3b42bd363364a14cb13ad3`. Concurrent workflow invariants were exercised against independent MySQL sessions. See `PHASE_10_TEST_REPORT.md` for evidence and the preserved guarded-run lifecycle.
