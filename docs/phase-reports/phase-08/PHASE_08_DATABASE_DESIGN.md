# Phase 08 database design

The additive Phase 08 schema includes enrollment policies and policy-to-academic-level eligibility, course offerings, sections, enrollments, registration holds, waitlist entries, immutable enrollment events, section transfer requests, prerequisite exceptions, and bulk registration batches. Tenant-owned rows use tenant identifiers, institution ownership, composite foreign keys/unique keys, and indexes for common status and section lookups. Schema changes are additive; no existing database was reset during this work.

SQLite feature tests exercise migrations and constraints in the local isolated test harness. Phase 08 MySQL schema migration, actual MySQL indexes/foreign keys, engine behavior, and migration history are **not verified** because the guarded private environment is missing. Do not infer MySQL acceptance from SQLite.
