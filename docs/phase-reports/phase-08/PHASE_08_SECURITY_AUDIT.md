# Phase 08 security review

## Evidence checked

- Tenant-scoped workflow ownership checks and server-side permission middleware.
- Registration preview and writes resolve a student's active SIS identity and server-side tenant context.
- Instructor roster routes require scoped active membership/assignment; unauthorized roster access is covered by feature tests.
- Policy academic-level identifiers are checked against the current institution before transactional pivot synchronization.
- Policy, offering, section, enrollment, waitlist, and expiry mutations emit audit/event records.
- The scheduled offer-expiry task rechecks state while holding row locks and is idempotent for entries already processed.
- Local SQLite regression and PHPStan passed; dependency audits reported no advisories.

## Findings

- **High acceptance blocker, environment:** MySQL-specific tenant constraints, grants, and concurrent behavior have not been verified. The guarded runner refuses to start without the private ignored Phase 08 environment file.
- **Medium, verification gap:** Full independent-session race probes and a complete registrar/instructor browser authorization journey are not evidenced.
- No Phase 08 security certification or production security review is claimed. Keep MySQL isolation safeguards enabled and do not enable release until the listed verification gaps close.
