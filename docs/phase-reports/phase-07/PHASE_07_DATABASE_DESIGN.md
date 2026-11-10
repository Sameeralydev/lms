# Phase 07 database design

## Current additive schema

Three Phase 07 migrations define seven delivery tables.

| Table | Purpose | Safeguards |
|---|---|---|
| course_delivery_workspaces | Tenant/institution delivery offering for an existing course version | Tenant and institution/campus/program/course/version/year foreign keys; tenant/institution/code uniqueness; status index. |
| course_delivery_assignments | Effective, revocable member assignment | Tenant/workspace foreign key; assignment sequence uniqueness; role/status index. |
| course_delivery_modules | Ordered workspace sections | Tenant/workspace foreign key; unique order per workspace. |
| course_delivery_lessons | Ordered editable lesson lifecycle | Tenant/workspace/module foreign keys; unique order; optimistic lock version. |
| course_delivery_blocks | Ordered constrained content and media metadata | Tenant/lesson foreign key; unique order per lesson; JSON stores block payload, not relational identity. |
| course_delivery_lesson_revisions | Immutable published snapshots | Tenant/lesson foreign key; version uniqueness; content checksum and actor references. |
| course_delivery_progress | Member-specific lesson progress | Tenant-scoped workspace/lesson/membership foreign keys; unique member/lesson row and course/status index. |

The foundation migration adds only the Phase 07 permission definitions and system-role grants needed for delivery. No student, grade, finance or HR schema was introduced.

## Verified MySQL state

On 2026-10-27, guarded read-only preflight authenticated the dedicated account techmentors_phase07_test@127.0.0.1 to techmentors_lms_phase07_test on MySQL 8.4.11 at 127.0.0.1:3306. It verified 129 schema objects and 37 applied migrations; grant and ownership checks passed. Preflight exit code was 0 and it made no schema/data/manifest change.

The guarded Phase 07 suite then passed 215 tests / 2,361 assertions with one existing Phase 06-only test skipped. CourseDeliveryTest passed 6 tests / 208 assertions, including real ClamAV clean/EICAR behavior. SQLite suite passed 209 / 2,271 with seven skips. No development or unrelated database was used.

## Evolution limits

The current Phase 07 schema supports its implemented slice only. There is no normalized activity response, completion-rule, template/copy, live-session, analytics/quality, media version/retention or interoperability schema. Add these only with additive migrations, tenant-scoped keys, explicit retention and guarded Phase 07 preflight/postflight. Never use an earlier-phase database for destructive testing.

## Closure schema addition: learner activity evidence

Migration 2026_10_27_000003_create_course_delivery_activity_responses adds the tenant-scoped course_delivery_activity_responses table, a unique activity-version key and a membership/idempotency key. Composite foreign keys bind tenant, workspace, lesson, membership, published revision and activity block. course_delivery_progress receives learner and instructor confirmation timestamps plus the confirming user. course_delivery_blocks receives the composite scoped reference key required by the activity foreign key.

The Phase 07 test schema now has 130 schema objects and 38 applied migrations. The guarded migration upgrade preserved the previous manifest as local evidence and updated the current manifest only after exact migration and schema postflight checks. No development or unrelated schema was used.
The delivery-domain inventory now comprises eight tables (the seven original delivery tables plus course_delivery_activity_responses); migration 000003 also adds two confirmation timestamps and confirmer reference on course_delivery_progress and a composite block-scope key.
