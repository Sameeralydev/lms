# Phase 03 Implementation Plan

This plan was written after verifying the Phase 02 final gate and checkpoint and before creating academic models or migrations. It follows the user-provided Phase 03 brief; no separate product blueprint/roadmap was present in the repository.

## Sequence

1. Additive schema for academic configuration, calendar/periods, units, levels/qualifications, programs, grades/classes/sections/cohorts, subjects/courses/course versions, credit-policy versions, regional profiles/evidence gaps, outcomes, quality objectives/reviews/actions, and controlled document metadata.
2. Typed domain actions and request validation for calendar transitions, hierarchy-cycle checks, scoped uniqueness, program/campus compatibility, decimal-safe credits, profile approvals, outcome hierarchy, review approvals, and document access.
3. Register explicit academic permissions; add tenant workspace routes and audit every important mutation.
4. Build a real academic dashboard and configuration pages using the existing six-theme shell, semantic terminology, accessible reusable controls, server-side pagination, and meaningful empty/error states.
5. Add adversarial MySQL/SQLite feature coverage, frontend component tests, and browser journeys with real persistence.
6. Run migrations first against guarded MySQL test database, run full suites, then inspect the development DB identity/status and apply only additive migrations.
7. Produce standards/accessibility/security traceability and performance measurements; record unsupported regional rules and external verification gaps honestly.
8. Run the Phase 01/02 regression suite, security review, documentation check, and local Git checkpoint. Stop before Phase 04.

## Non-negotiable safety gates

- MySQL test target must be the exact isolated `techmentors_lms_test` schema and dedicated account; use the guarded Composer runner for any test suite that migrates.
- Development target must remain `techmentors_lms_dev` on its verified local MySQL endpoint. Inspect migration status and schema before running `artisan migrate --database=mysql --force`.
- No `migrate:fresh`, `db:wipe`, rollback, destructive seeder, or unrelated database access.
- Never print or stage `.env`, `.env.testing`, credentials, bearer links, or private keys.
- No global PHP, Apache, MySQL, or Windows service changes.

## Verification gate

Phase 03 passes only with the acceptance criteria in the supplied brief: actual tenant-safe calendar, organization/program/class/catalog/policy/outcome/quality workflows; MySQL and supported SQLite tests; real browser journeys; frontend/static/dependency/build gates; six-theme regression; no unresolved critical/high security issue; complete evidence reports; and clean local Git checkpoint. Regional law and formal standards certification cannot be claimed from software behavior.
