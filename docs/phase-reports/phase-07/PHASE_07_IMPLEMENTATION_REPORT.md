# Phase 07 implementation report

Verification and implementation update: 2026-10-27. Phase 07 remains BLOCKED as a complete release; Phase 08 has not started.

## Current continuation update

- Implemented institution-scoped immutable course-delivery templates with versioned snapshots and checksum validation, controlled same-tenant sharing/adoption, selected module/lesson copying into draft workspaces, audit and idempotency records, and academic-year rollover. Copy operations exclude learner progress and response records. Reused media is copied into private tenant storage, source hash-verified and passed through the normal scan pipeline before the destination can use it.
- Added the guarded, additive migration `2026_10_28_000000_create_course_delivery_templates.php` and applied it only through the Phase 07 migration-transition runner. Current read-only preflight confirms the owned schema at 135 objects / 40 migrations.
- Implemented tenant-scoped live-session scheduling, lesson association, assigned-instructor validation, online/physical metadata, UTC persistence from unambiguous local time, DST gap/fold rejection, reschedule, cancellation, calendar filters, role-scoped visibility and audit events.
- Extended required-activity coverage for the `reading_acknowledgement` content block: false acknowledgements are rejected, completion stays blocked until a valid submitted acknowledgement exists, and the learner/instructor completion confirmation path then succeeds. Focused SQLite and MySQL runs each passed 56 assertions for this workflow.
- Added the guarded, additive migration `2026_10_28_000001_create_course_delivery_live_sessions.php` through the same approved transition path.
- Added functional template/version/copy/rollover forms and a live-session calendar plus workspace controls. The full scanner-enabled Chromium run passed 24/24; the learning-delivery journey covers schedule/reschedule/cancel, calendar filtering with no join link for a cancelled session, and template create/selective copy/version history.
- Focused feature suites passed on SQLite and guarded MySQL: 2 tests / 103 assertions each for templates/rollover and sessions. Existing course-delivery analytics and activity features remain unchanged.

This continuation does not complete Phase 07. Progress acknowledgements/rollups, full release exceptions, media lifecycle/retention, analytics breadth and broader browser/security coverage remain mandatory work; see the traceability and final gate reports.

## Final delivery sprint implementation delta

- Extended the existing immutable release-policy snapshot with same-workspace lesson/module prerequisite IDs, explicit cycle rejection, an earlier-module lock option, assignment-relative drip delay and an availability end instant.
- Learner route authorization now checks the published revision for each prerequisite and checks prerequisite modules by their published lessons and each learner's current completed revision. Workspace-scoped validation rejects nonexistent or cross-workspace dependencies. Dependency lookups are grouped to avoid per-lesson block queries.
- Updated the instructor lesson editor with prerequisite, lock, drip and availability controls.
- Added pure dependency graph regression tests and coverage of the prerequisite field in the direct learner sequencing test.
- Fixed an existing risky test in `Phase07MigrationUpgradePolicyTest` by asserting the reviewed table transition explicitly.

This is a partial release-control slice only. Date-time editor values are converted from the browser's local zone to UTC instants; institution-time-zone scheduling is not implemented. Instructor release exceptions with approval/expiry/revocation, a release preview/decision audit interface, and dedicated browser workflows also remain incomplete.

- Added a real tenant/campus/role-scoped analytics and content-quality page with validated course/instructor/year/status/date filters, paginated workspace data, SQL-backed delivery metrics, and actionable findings for missing objectives, curriculum mapping and accessibility metadata. A MySQL-specific aggregate-pagination incompatibility was found during integration and corrected by keeping the aggregate scope separate from the display paginator.
- Added analytics feature coverage for metrics, filters, tenant boundaries and learner denial, plus a browser journey that checks review backlog data, filter behavior, axe accessibility and mobile-width rendering. Adjusted the table-header color token and metric markup after axe detected low contrast and definition-list semantics issues.

## Implemented delivery slice

- Tenant-scoped delivery workspaces linked to institution-owned published course versions and optional published curriculum versions.
- Explicit instructor, author, reviewer, publisher and learner assignments with revocation, membership/permission checks and audit events.
- Ordered modules and lessons, structured text/callout/code/link/reflection blocks, version-checked draft editing and keyboard-operable ordering.
- Submission, independent review decisions, immutable checksummed published lesson snapshots, versioned revisions and workspace publication.
- Learner assignment-gated snapshot access, bounded resume position, revision-aware idempotent completion, module/course progress summaries.
- Free/sequential progression and UTC release time policy enforced server-side, including direct URL access.
- Learning media private quarantine, file/type/size and content checks, metadata for alt text/license/attribution/transcript, real ClamAV scanning with freshness, clean-only publication and authorized private delivery.
- Persisted encrypted, revision-scoped learner activity responses for eight ungraded activity types; response versioning/idempotency and safe audit metadata; required-activity validation; learner and authorized instructor completion confirmations.
- Additive migration `2026_10_27_000003_create_course_delivery_activity_responses.php`; exact Phase 07 guarded single-migration upgrade policy with stable ownership preflight, exclusive transition intent, migration hash, allowlisted object/table changes, preserved pre-upgrade manifest and verified postflight.
- Phase 07-specific guarded MySQL runner target, least-privilege grant and ownership validation.

## Main files

- Migrations: 2026_10_27_000000_create_course_delivery_foundation.php; 2026_10_27_000001_create_course_delivery_progress.php; 2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key.php; 2026_10_27_000003_create_course_delivery_activity_responses.php.
- Domain: app/Domain/Learning/CourseDeliveryWorkflow.php; CourseDeliveryMediaWorkflow.php; Phase07ProgressMigrationRecoveryPolicy.php.
- HTTP: app/Http/Controllers/Workspace/CourseDeliveryController.php and tenant-scoped routes.
- Models: CourseDeliveryWorkspace, Assignment, Module, Lesson, Block, LessonRevision and Progress.
- UI: resources/js/pages/workspace/learning/index.tsx, show.tsx and lesson.tsx; shared layout and design tokens.
- Verification: tests/Feature/CourseDeliveryTest.php; Phase 07 MySQL guard, grant and migration-upgrade policies; tests/Unit/Phase07MigrationUpgradePolicyTest.php; tests/e2e/platform-workspace.spec.ts.
- Reports: all PHASE_07 reports in this directory.

## Verification

- Guarded Phase 07 CourseDeliveryTest on MySQL 8.4.11: 7 passed / 260 assertions, including real ClamAV clean and EICAR learning-media tests and activity completion.
- Full guarded MySQL suite: 224 passed / 1 skipped / 2,465 assertions.
- Full SQLite suite: 218 passed / 7 skipped / 2,375 assertions.
- Standard Playwright Chromium regression: 23 passed / 1 scanner-gated skip. Full real-ClamAV-enabled Chromium run: 24 passed, including the scanner-dependent SIS transfer journey and analytics journey.
- TypeScript, ESLint, Prettier, Vitest (6), Composer checks/audit, npm audit and Vite production build passed. PHPStan passed; Pint passed after applying its required formatter fix. PHP syntax traversal passed for 334 files.
- Guarded Phase 07 read-only preflight before and after suite: exact dedicated database/account/server, 130 objects and 38 migrations verified, exit 0.

See PHASE_07_TEST_REPORT.md for exact commands and scope limits.

## Not implemented or incomplete

Further activity validation, required content-acknowledgement rules, complete policy rollups, institution-time-zone policies, optional paths and release exceptions; templates and controlled course copy/rollover; live sessions; complete delivery analytics and content-quality coverage; comprehensive media replacement/version/retention lifecycle and learning-media browser scans; full Phase 07 browser journeys; external standards implementation; hosted CI.

Some sequencing primitives are now implemented as recorded above. The list remains release-blocking for the remaining scope; in particular timezone-aware scheduling and exception lifecycle are not complete.

These are mandatory Phase 07 scope. They are not accepted deferrals.

A separate guarded Phase 07 CI workflow was added at .github/workflows/phase07.yml. It provisions an isolated MySQL 8.4 schema and exact least-privilege grant, runs guarded MySQL/SQLite and frontend checks, installs ClamAV signatures and runs scanner-enabled Chromium. It requires PHASE07_MYSQL_ROOT_PASSWORD and PHASE07_MYSQL_PASSWORD repository secrets and has not been executed by a hosted runner.

## Rapid recovery implementation delta — 2026-10-27

Added the learner-specific release exception lifecycle and independent approval workflow, institution-time-zone instructor release preview, published-workspace assignment management for authorized managers, and tenant-wide member eligibility for campus workspaces in line with the existing server-side assignment rule. The scanner now keeps web requests within the configured bounded ClamAV process timeout plus a five-second margin. Browser coverage now exercises the learner/instructor completion journey, exception approval, media replacement/rescan, template copy/rescan, analytics counts and publication filtering.

Verification and remaining acceptance are recorded in the dated sections of `PHASE_07_TEST_REPORT.md`, `PHASE_07_SECURITY_AUDIT.md`, `PHASE_07_REQUIREMENT_TRACEABILITY.md` and `PHASE_07_FINAL_GATE_REPORT.md`. Phase 07 remains BLOCKED; these changes do not complete all required lifecycle, concurrency, authorization and accessibility matrices.

## Final QA closure implementation delta — 2026-10-27

- Fixed session reschedule/cancel state handling so the locked fresh row, authorization/status checks, state write, and audit write share one retried database transaction. Repeated cancellation/rescheduling now returns a conflict instead of applying a stale transition.
- Preserved learner activity responses across lesson revisions: response-linked old activity blocks are archived out of active lesson content rather than deleted under a foreign-key reference. Learners on the new revision no longer receive old activity blocks, while audit/revision evidence remains.
- Added coverage for completion-policy combinations, historical activity evidence and scoped confirmation; media archive/retention/reference-safe purge; optional progression; malformed-reference detection/remediation; template-copy rollback and cross-tenant denial; role-specific session visibility and cancelled-session join-link hiding.
- Expanded the existing Chromium journey with mobile viewport, keyboard focus and axe smoke assertions for the learning analytics/session paths.
- Latest guarded MySQL and SQLite regression, scanner-enabled browser, static analysis, audit and build evidence is recorded in `PHASE_07_TEST_REPORT.md`. Reports do not claim deterministic concurrent-worker coverage or positive cross-institution sharing absent a schema that can represent two institutions in a tenant.

Phase 07 remains blocked on the acceptance actions listed in the final gate report. No Phase 08 work was started.

## Final acceptance recovery implementation delta — 2026-10-27

- Added same-institution template/version visibility coverage for an academic administrator whose membership is scoped to another campus. The test passes on guarded MySQL and SQLite. Cross-tenant denial remains asserted. The one-institution-per-tenant tenancy invariant was preserved.
- Expanded Playwright axe checks across course builder, media authoring, learner lesson/progress, session calendar, templates and analytics. Learning analytics is checked under all six registered themes.
- Fixed muted text token contrast on light surfaces and replaced fixed amber warning classes in SIS import/status views with theme-aware warning tokens. The full scanner-enabled browser suite passed after rebuilding assets.
- Latest complete MySQL and SQLite counts and all final verification commands are in PHASE_07_TEST_REPORT.md.

No schema migration was made. The remaining concurrency matrices and the Phase 02/Phase 07 cross-institution architecture conflict remain release gates. No Phase 08 work started.
