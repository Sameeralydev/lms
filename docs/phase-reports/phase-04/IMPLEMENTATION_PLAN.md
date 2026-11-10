# Phase 04 Implementation Plan

## Scope authority

The approved scope is the Phase 04/24 admissions brief supplied for this execution, together with the Phase 03 final gate, academic handoff, standards mapping, and requirement traceability. No separate product blueprint or roadmap file exists in this repository.

## Preserved contracts

- Tenant records are selected only through verified route slug and `TenantContext`.
- Tenant-owned data uses tenant-scoped models and composite tenant foreign keys.
- Campus membership scope is checked server-side on admissions routes.
- Existing academic year, campus, and program identities are referenced; they are not recreated.
- Global user identities are reused for applicants and staff. An application is not an enrollment.
- Admission permissions are added separately from academic permissions.
- Development migrations are additive. Automated tests use the guarded isolated test database or SQLite in-memory.
- Applicant documents remain on the private local disk. No malware scanning or external delivery is claimed.

## Delivery order

1. Add admissions settings, intakes, form versions, applicant profiles, applications, lifecycle events, documents/reviews, reviewer assignments, assessments, decisions, offers, seats, fee obligations, outbox records, inquiries, and SIS handoff tables.
2. Add tenant permissions and feature gates while preserving custom role grants.
3. Implement tenant-scoped intake and admissions administration, public discovery, applicant registration/application, and review/offer/handoff actions.
4. Implement dynamic form validation, application state transitions, private document authorization, seat locking, idempotency, and safe audit recording.
5. Add backend and frontend/browser tests and phase reports, then run cross-phase regressions and independent review.

## Gate discipline

Every shipped capability is traced to a test or explicitly marked incomplete. Phase 05 and student record creation remain out of scope. Local tests and browser checks do not establish hosted CI, external message delivery, malware scanning, legal policy verification, or standards certification.
