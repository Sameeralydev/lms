# Phase 03 Database Schema

## Migration evidence

The schema is additive and scoped to the existing tenant/institution entities. Development migration status reported 10 migrations `Ran`; the four Phase 03 migration files are listed below. No reset, rollback, destructive migration, or unrelated database access was performed.

| Migration | Tables |
|---|---|
| `2026_10_22_000003_create_academic_calendar_and_structure_tables` | `academic_configurations`, `academic_years`, `academic_periods`, `academic_units`, `academic_unit_campuses`, `academic_levels`, `academic_qualification_types`, `academic_credit_policies`, `academic_programs`, `academic_program_campuses`, `academic_classes`, `academic_sections` |
| `2026_10_22_000004_create_academic_catalog_policy_and_outcome_tables` | `academic_subjects`, `academic_courses`, `academic_course_versions`, `academic_policy_profiles`, `academic_policy_profile_versions`, `academic_policy_evidence`, `academic_policy_mappings`, `academic_policy_gaps`, `academic_learning_outcomes`, `academic_program_outcomes`, `academic_course_version_outcomes` |
| `2026_10_22_000005_create_education_quality_and_controlled_document_tables` | `education_quality_objectives`, `education_quality_reviews`, `education_quality_evidence`, `education_corrective_actions`, `academic_documents`, `academic_document_approvals` |
| `2026_10_22_000006_backfill_academic_permissions_for_existing_tenants` | Permission/role grants only; no academic business tables |

The three schema migrations create 29 tables. Existing tenants receive idempotent grants on existing system roles; custom role grants are not rewritten.

## Integrity and indexing

Academic records inherit the tenant global scope and fail closed without `TenantContext`. Tenant-owned rows have tenant foreign keys; institution, parent, campus, academic-year, program, course, and version references use composite tenant-aware foreign keys where appropriate. Unique keys scope codes to tenant/institution, year, class, or version. Calendar, status, parent, and directory query paths have supporting indexes. Credit values use fixed-precision decimal columns, not floating-point persistence.

Published course versions are immutable by workflow; future changes create a new version. Policy profiles and credit policies are versioned. Deletion rules generally restrict removal of referenced academic records. No student, enrollment, curriculum, payment, or HR tables were introduced.

## Database-engine behavior

Both MySQL 8.4 and SQLite in-memory suites pass. Composite foreign-key constraints and transactions were exercised on both engines. Database collations can differ for string comparisons; input validation accepts ASCII identifier characters and the unique database index remains authoritative. A future normalization policy should be agreed before public/imported codes are exposed.

## Database targets

- Development: `techmentors_lms_dev` on the verified local MySQL 8.4.11 service.
- Destructive/integration tests: dedicated `techmentors_lms_test`, through the guarded test runner only.
- Protected unrelated schemas `alamgirian_dev` and `alamgirian_test` were not accessed.
