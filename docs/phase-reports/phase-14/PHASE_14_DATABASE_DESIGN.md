# Phase 14 database design — current implementation status

Three additive migrations define the partial gradebook foundation:

1. `2026_11_06_000001_create_phase14_grading_foundation.php`: grading policies/categories, gradebooks, items, entries, and immutable entry revisions.
2. `2026_11_06_000002_backfill_phase14_grading_permissions.php`: adds implemented grade permissions to applicable system role templates without granting custom roles new authority.
3. `2026_11_06_000003_create_immutable_grade_publications.php`: publication headers and per-enrollment percentage snapshots.

The records are institution/tenant scoped, relate to existing academic periods, course sections, and course enrollments, and use unique keys and foreign keys for category, item, entry, revision, and publication identity. Entry updates use an expected lock version and append revision history. Publication snapshots are separate from mutable working entries.

The migrations have been exercised by the isolated SQLite test suite only. No Phase 14 MySQL schema identity, ownership fingerprint, migration state, MySQL constraints, or MySQL concurrency behavior was verified in this execution. Do not infer MySQL consistency from SQLite.

## Versioned grade-scale extension — 2026-11-10

Reviewed additive migration: `2026_11_10_000001_create_versioned_grade_scale_bands.php`. It adds tenant-scoped `academic_grade_scale_bands` with unique per-policy keys and thresholds plus a composite tenant/policy foreign key, and adds derived scale outcome, grade points, and pass state to immutable publication entries. SQLite migrations and workflow tests passed. The Phase 14 guarded cutoff was advanced to this migration; the MySQL schema and migration fingerprint remain unverified until the isolated owner-approved environment is configured and preflighted.

## 2026-11-10 additive foundation status

The reviewed Phase 14 migration cutoff is `2026_11_10_000005_add_official_record_permissions`. The local inventory includes versioned scale bands, GPA policies/standing/snapshots, GPA permission grants, official record templates/issues, and official-record permission grants. These migrations ran under the SQLite test suite only. The guarded MySQL preflight stopped before any connection because `.env.phase14.testing` is absent; therefore the MySQL object count, migration status, grants and ownership fingerprint are UNKNOWN/NOT VERIFIED. Do not infer application to the Phase 14 MySQL schema from SQLite results.
