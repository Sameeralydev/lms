# Phase 13 Integration Contracts

Updated 2026-11-03.

## Current integrations

- Tenant identity: existing `TenantContext`; all examination records carry tenant scope.
- Campus authorization: existing `AcademicAccess` and academic scope middleware.
- Academic calendar: `academic_periods` reference is required for each cycle.
- Course catalog: sessions reference existing `academic_courses`.
- Student identity: candidate registration references existing `sis_students`; no duplicate identity is created.
- Facilities: sessions and seats reference existing Phase 09 `academic_rooms`.
- Audit: existing `AuditRecorder` receives examination event types.
- Questions: Phase 13 paper versions snapshot selected active Phase 12 question versions and mark allocations. The Phase 12 online attempt engine is not yet bound to an examination session or candidate; exam timer, delivery and attempt authorization integration remain open.

## Phase 14 contract status

A read-only released-result contract is now available at `GET /t/{tenant}/academics/examinations/results/{resultId}/grade-contract`, protected by `exams.analytics.view`, tenant/campus scoped, and served with private no-store caching. The response contains tenant/institution/student SIS identity, academic period and course, exam cycle/session/category, weighted component marks and maxima, released score/status/version, approval/release metadata, and immutable result-version history. Only released results are exposed. The controller authorizes access before the service returns data.

This is an internal integration boundary, not an official gradebook. Phase 14 owns consolidated official grades, report cards, GPA/CGPA, transcripts and certificates. The contract has SQLite feature coverage; Phase 13 MySQL foreign-key and concurrent-read behavior remain unverified until the guarded concurrency harness runs.

