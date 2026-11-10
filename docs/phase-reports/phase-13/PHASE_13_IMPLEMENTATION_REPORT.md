# Phase 13 Implementation Report

## Four-blocker closure — current implementation

- **Appeal evidence:** Added a private appeal evidence model/workflow over the existing ClamAV scanner and private storage conventions. Uploads require candidate/reviewer authority, current tenant/campus context, open appeal state, file validation and hashed content identity. Evidence remains quarantined until a clean scan; downloads require clean state, matching content hash and authorized active membership. Infected, unavailable and failed scans remain unavailable. Audit history is recorded.
- **Operations and analytics:** Expanded the examination results workspace and session-scoped CSV with real tenant/campus-scoped schedule status, registration, attendance, marking/moderation, released/pending results, rechecks/appeals, incidents, seating utilization, invigilator duty state, exceptions, accommodations, admit documents and retake/makeup registrations. Added released subject/campus performance and session filtering.
- **Notifications:** Date-sheet revisions publish candidate-specific change/cancellation notifications. Invigilator assignment and accept/decline produce idempotent authorized notices. Added delivery support for formal online exam submission/expiry events in the shared outbox.
- **Outbox defect repair:** The full browser suite exposed that `exam.attempt_submitted` and `exam.attempt_expired` were queued but unsupported by the shared processor. Delivery now verifies tenant-scoped attempt binding, session delivery and quiz identity, then notifies active instructors assigned to the delivered section within campus scope. Notification text excludes answers and sensitive payloads. Regression coverage verifies submit/expiry notices and idempotent retries.
- **Runner coverage:** Extended the guarded Phase 13 test allowlist to include `QuizAssessmentWorkflowTest`, without changing database target, identity checks or ownership policy.
- **Role/theme evidence:** Generated a machine-readable inventory from Laravel's live route collection: 73 examination/appeal routes, five role groups, selected screen groups, and six themes. The inventory explicitly marks route/action/campus authorization as incomplete.

## Verification

Guarded MySQL: 31 passed / 1 scanner-gated skip / 1,043 assertions; eight independent-session concurrency probes passed. SQLite: 327/4,949/10 skipped. Scanner-enabled Chromium: 42/42. Vitest: 24 passed. PHPStan, Pint, TypeScript, ESLint, Prettier, Composer checks/audit, PHP syntax (560 files), production build (685 modules), and `git diff --check` passed. Full npm audit remains 10 moderate findings in development tooling pending owner disposition.

**Phase 13 remains engineering-incomplete** because the route/action/role/campus authorization matrix is not exhaustive. No Phase 14 work began.
