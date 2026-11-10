# Phase 08 architecture

Course enrollment is implemented as a Laravel modular-monolith domain in `App\\Domain\\Enrollment\\CourseEnrollmentWorkflow`, with thin tenant workspace controllers, tenant-scoped Eloquent records, scoped routes, and React/Inertia pages under `resources/js/pages/workspace/registration`. Existing identity/membership permission checks remain authoritative. Enrollment writes synchronize the existing Phase 07 course-delivery assignment; they do not grant missing membership permissions.

Capacity changes, registration decisions, waitlist promotion, policy edits, and waitlist expiration use transactions and row locks. Phase 07 tenancy and migration cutoffs are unchanged. The new additive schema is in `2026_10_29_000001_create_course_enrollment_foundation.php` and `2026_10_30_000000_expand_course_enrollment_lifecycle.php`.

MySQL lock semantics remain unverified until the guarded Phase 08 target is provisioned and the MySQL suite completes.
