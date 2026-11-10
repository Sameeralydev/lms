# Phase 09 Requirement Traceability

**Evidence updated:** 2026-10-29. Status is based on the current local verification, not historical counts.

| Requirement | Status | Evidence |
|---|---|---|
| Tenant, institution and campus ownership | PASS | Timetable feature and browser tests enforce server-resolved tenant/institution/campus context; negative tests cover foreign institution, wrong campus, revoked membership and forged resource IDs. |
| Academic settings, timezone and teaching periods | PASS, tested scope | Browser journey configures teaching periods and scheduling settings; recurrence/local-time unit and feature tests cover time conversion and DST behavior. |
| Buildings, rooms, capacity and room blocks | PASS, tested scope | Browser creates a campus room and exercises a conflicting allocation; guarded MySQL independent-session room race commits one allocation and rejects the competing conflict. |
| Instructor eligibility and availability | PASS, tested scope | Availability is configured in the browser journey; substitute eligibility and time availability are validated. Independent-session instructor allocation and substitution probes passed. |
| Closures and calendar exceptions | PASS, tested scope | Conflict checks include calendar exceptions/closures; schedule conflict tests and timetable editor journey exercise the applicable behavior. |
| Weekly recurrence, local time and DST | PASS | Recurring class is created and draft series edit/cancel is browser-tested; schedule-time tests cover local-time conversion and DST gap/fold behavior. MySQL recurring-series conflict probe passed. |
| Room, instructor and section conflict detection | PASS | Browser rejects duplicate/conflicting allocation. Independent MySQL room, instructor and recurring-series probes passed with final database invariants. |
| Draft, review, publish and immutable snapshot | PASS | Browser submits an independent review, approves and publishes. MySQL publication race confirms one consistent published state and corresponding audit evidence. Version race confirms one revision/audit outcome. |
| Reschedule, substitution, cancellation and makeup | PASS | Browser submits/reviews occurrence changes for reschedule, substitution, cancellation and makeup, then verifies the effective schedule and analytics. MySQL reschedule/substitution races passed with persisted-state checks. |
| Notifications and idempotency | PASS, tested scope | Change workflow records durable outbox evidence; browser checks corresponding event evidence. MySQL competing operations assert audit/event consistency. |
| Student/instructor views and exports | PASS | Browser verifies published schedule for the assigned instructor and enrolled learner, and downloads CSV and ICS exports. Wrong-role and unenrolled access is denied/empty as applicable. |
| Analytics and quality findings | PASS, tested scope | Browser verifies analytics against persisted schedule changes and open-section findings; query remains institution-scoped. Large-scale production performance is not claimed. |
| Visual scheduling studio | PASS, tested scope | Browser exercises settings, room, availability, draft meeting, recurrence, conflict feedback, review/publication, change workflow and revision/cancellation. |
| Theme/accessibility/responsive coverage | PASS, automated scope | Axe checks run against timetable editor, instructor schedule, learner schedule and analytics under six persisted themes. Keyboard focus visibility, mobile reflow, reduced-motion preference, accessible names/errors exposed in tested flows and browser console are checked. Manual screen-reader testing was not performed. |
| Negative authorization | PASS | Feature test: 1 test / 53 assertions across guest, foreign tenant, revoked membership, campus boundary, wrong role/instructor and forged timetable/meeting/room identifiers; unauthorized mutations are checked. Browser verifies cross-tenant 404 non-disclosure. |
| Guarded MySQL target, ownership and migrations | PASS | Guarded runner exit 0; dedicated Phase 09 database/account and ownership verified; 161 objects / 46 migrations; fingerprint unchanged. |
| Deterministic independent-session concurrency | PASS | Seven two-session probes passed: room allocation, instructor allocation, recurring conflict, publication, rescheduling, substitution and version change. Coordination is synchronized and bounded; final state/audit/revision invariants asserted. |
| Phase 09 browser journeys | PASS, local suite | Full Chromium: 26 passed, 1 scanner-gated skip, exit 0. The scheduling journey completes the required real workflows and persisted-outcome checks. |
| Backend/frontend/dependency quality | PASS | SQLite 254 passed / 3,265 assertions; MySQL 6 passed / 199 assertions plus 7 concurrency probes; Vitest 6; PHPStan, Pint, PHP syntax, TypeScript, ESLint, Prettier, Composer/npm audits, production build, route inspection and diff check passed. |

## Release decision

**Phase 09 local acceptance passes.** The single E2E skip requires the unrelated ClamAV-enabled transfer-document environment. Hosted CI and manual assistive-technology review remain unverified and must be recorded as follow-up verification; no success claim is made for either.
