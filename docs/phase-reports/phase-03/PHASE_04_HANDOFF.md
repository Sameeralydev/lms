# Phase 04 Handoff

Phase 04 should consume stable tenant-scoped academic identifiers without adding admissions logic to the Phase 03 domain.

## Available contracts

- Institution and tenant context comes from Phase 02 `TenantContext`; do not accept client tenant IDs as authorization.
- Program, qualification, level, campus, academic year, and period records are stable institution-owned references.
- Academic year/period dates and current flags can be used to validate application windows; applicants/admission decisions remain Phase 04-owned.
- Course definitions and policy versions are catalog metadata only; they do not imply student enrollment or an offered course.
- `AcademicRecord` global scope and composite tenant FKs must be preserved. Campus-specific admission rules must use server-side membership campus scope.
- Important admission decisions should use separate Phase 04 permissions, domain actions, and audit events; do not reuse academic admin permission as applicant approval.

## Phase 04 should add

Applicant/person records or an explicit identity linkage model; application forms, submissions, attachments, review workflow, admission decisions, offer/acceptance, and conversion into Phase 05 student records. Define retention, privacy, deduplication, and applicant-to-user identity rules before storing personal documents. Do not make an application an enrollment.

## Prior to production

Complete hosted CI, delivery provider setup, deployment-specific DB privilege separation, file malware scanning and retention, backup/restore testing, monitoring, and a human review of jurisdictional requirements. Verify `FINAL_GATE_REPORT.md` commit checkpoint and exact test totals.
