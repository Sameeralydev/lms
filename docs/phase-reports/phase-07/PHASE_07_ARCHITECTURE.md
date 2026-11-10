# Phase 07 architecture

## Implemented boundary

Course delivery is a Laravel modular-monolith domain under App\Domain\Learning, integrated with existing academic course and curriculum records. Tenant HTTP routes and Inertia UI live in the workspace boundary. CourseDeliveryWorkflow owns lifecycle, scoped assignment, authoring, review, immutable publication, learner access, progress and release decisions. CourseDeliveryMediaWorkflow owns private upload quarantine, scanner invocation, clean promotion and learner media delivery checks.

Published learner content comes from immutable revision snapshots rather than mutable authoring rows. Release metadata is retained in the snapshot and stripped from learner-visible content. Media private paths, scanner internals and hashes are not exposed in UI payloads. The media endpoint rechecks tenant, assignment, published asset and private disk path before serving.

## Data boundary

Four additive Phase 07 migrations define eight delivery tables: workspaces, assignments, modules, lessons, blocks, immutable lesson revisions, member progress and activity responses. Composite tenant-owned foreign keys and unique indexes bind references. No future student, grading, finance or HR business table was added.

Current media metadata and release-policy blocks use the existing constrained course_delivery_blocks table. There is no independent asset library, version store, retention queue or template/session/analytics schema yet.

## Decisions

- Shared modular monolith and existing tenant context remain the boundary; no microservice or new package was introduced.
- Academic course/curriculum identities are referenced rather than copied.
- Structured content is finite and output-encoded; no arbitrary HTML execution.
- Tenant authorization is checked server-side and is not inferred from request IDs.
- MySQL verification is isolated to the Phase 07 guarded database. Post-migration read-only preflight verified the dedicated Phase 07 schema/account on MySQL 8.4.11, with 130 objects, 38 migrations, exact grant policy and ownership manifest.
- Guarded MySQL suite passed 221 tests / 2,419 assertions with one Phase 06-only skip; SQLite passed 215 / 2,329 with seven intentional skips. No Phase 08 work began.

## Architecture limitations

Further activity-response validation, completion policy combinations, complete rollups and revision invalidation, prerequisite graph, time-zone/drip/release exceptions, templates/copy, live sessions, analytics/quality, separate asset version/retention lifecycle and external integration adapters remain unimplemented. See PHASE_07_REQUIREMENT_TRACEABILITY.md. No production scale or certification claim is made.

## Closure architecture addition: learner interaction boundary

Learner responses are managed within the Learning Delivery domain workflow rather than a graded-assessment subsystem. An activity response references the immutable lesson revision and scoped activity block, is encrypted at rest through an application cast, and has independent version/idempotency identity. Completion policy evaluation stays server-side in CourseDeliveryWorkflow. A separately authorized instructor action can confirm completion; the learner cannot elevate their own completion state. This slice does not implement assessment mastery or the broader Phase 07 prerequisites/templates/session/analytics modules.



## Locked tenancy decision and Phase 07 architecture exception — 2026-10-28

The approved architecture remains **one institution per tenant, multiple campuses per institution**. Phase 07 supports institutional template ownership, authorized same-institution sharing across campuses and cross-tenant denial. It does not implement cross-institution sharing within one tenant because the approved model has no second institution in that tenant. The conflicting Phase 07 requirement is explicitly excepted under this locked architecture decision; it is not represented as a failed implementation test or as delivered functionality. If contractual acceptance requires multiple institutions in one tenant, the owner must approve a separate architecture/contract change before the invariant is reconsidered.
