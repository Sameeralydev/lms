# Phase 09 Security Audit

**Review date:** 2026-10-29  
**Scope:** Scheduling authorization, MySQL ownership and concurrency lifecycle, browser workflow, dependency checks. This is an engineering review, not a security certification.

## Verified controls

- Guarded Phase 09 runner exited 0 after validating the dedicated MySQL target/account, allowed scoped grants, reviewed migration cutoff, locks and ownership fingerprint. Final state remained 161 schema objects / 46 migrations with fingerprint `a5d8b108b7e49c3ecca4b6b29b40a8050e3c2c2f5cd0affbea86fca552e2c9ef`.
- Seven synchronized two-session MySQL probes passed for room assignment, instructor allocation, recurring-series conflict, publication, occurrence rescheduling, substitution and version changes. Final persisted-state and applicable audit/revision invariants were asserted. No race defect was observed in these tested paths.
- Server-side authorization tests deny guest, wrong-tenant, wrong-campus, revoked-member, wrong-role/unassigned-instructor and forged-resource cases; unauthorized mutation assertions passed (1 test / 53 assertions). Browser cross-tenant access returned 404 to avoid confirming resource existence.
- Scheduling mutations use existing conflict validation, independent review where required, audit/outbox records and immutable published timetable snapshots. Substitution eligibility/availability and schedule ownership are checked server-side.
- PHPStan and syntax checks passed. Composer and npm audits reported no advisories/vulnerabilities. No credentials were placed in reports. No unguarded migration or reset was run.

## Findings and residual verification

| Severity | Finding | Disposition |
|---|---|---|
| Critical / High | No unresolved finding identified by the Phase 09 local checks. | No critical/high release blocker observed in the reviewed scope. This does not certify the full platform or production deployment. |
| Medium | Hosted CI has not run. | Follow-up required before relying on hosted pipeline status; local checks are not represented as hosted evidence. |
| Medium | Manual assistive-technology review was not run. | Follow-up with keyboard and screen-reader users before production release; automated axe and keyboard checks are not a substitute for that review. |
| Low | MySQL concurrency probes cover the listed Phase 09 scheduling races, not every workload or production load pattern. | Preserve bounded transactional design and rerun probes when relevant locking/schema logic changes. |

## Security disposition

The local Phase 09 authorization, ownership and tested concurrency gates passed. No security certification, production readiness, hosted CI result, or manual assistive-technology result is claimed. The scanner-dependent E2E transfer-document workflow was skipped because scanner-enabled E2E configuration was absent; that flow is outside timetable functionality and remains separately environment-gated.
