# Dual-track execution addendum — 2026-11-06

This addendum records work performed in the current execution. Earlier report results remain historical unless repeated below.

## Phase 13 gate

`PHASE13_ENGINEERING_GATE=BLOCKED`

Fresh command: `php tests/run-tests.php --sqlite --filter=Phase13ExaminationRouteAuthorizationMatrixTest` — **2 passed, 2,138 assertions, exit 0**. This verified the current 73-route manifest and the existing guest, unprivileged, foreign-tenant, revoked-member, and unauthorized-role denial sweep. It did not execute authorized positive actions for each route.

The manifest still records **0/73** standalone positive route evaluators. Campus applicability and foreign-resource ownership remain incompletely assessed route by route (see `PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md`). Existing MySQL, concurrency, and 78 theme screenshot results are historical in this execution. No Phase 13 MySQL schema was accessed.

## Phase 14 work performed

- Added a scoped grading-policy, gradebook, item, entry revision, and immutable publication snapshot foundation using three additive migration files.
- Added the grading calculation engine using integer-cent and basis-point arithmetic, with incomplete and excused-entry handling.
- Added gradebook domain workflows, HTTP routes/controller, and instructor/student Inertia pages.
- Grade entry is currently manual-only. Assessment-source imports are explicitly rejected until the Phase 11–13 released/approved source contracts are verified.
- Added tenant permission template mappings and SQLite feature coverage for calculation, policy activation, audit/revisions, separation of duties, publication snapshots, and student visibility.
- Fixed the student role template’s missing `assessment.regrade.request` permission, which caused the established Phase 12 regrade workflow to abort. The affected workflow now passes.
- Fixed the Phase 14 permission-upgrade feature test’s migration path.
- Scoped student grade lookups to the active institution and tenant and added a two-institution denial regression test.

## Fresh checks

| Command | Result |
|---|---|
| `php tests/run-tests.php --sqlite` | **333 passed, 10 skipped, 7,201 assertions**, exit 0. Skips include MySQL-only probes and scanner-dependent tests because this run did not configure those services. |
| `php artisan test --filter='AcademicFoundationTest\|Phase14GradebookFoundationTest\|test_question_review_publication_attempt_autosave_grading_and_result_release_are_scoped'` | **20 passed, 399 assertions**, exit 0. |
| `php artisan test --filter=Phase14GradebookFoundationTest` | **2 passed, 69 assertions**, exit 0. |
| `php tests/run-tests.php --sqlite --filter=Phase13ExaminationRouteAuthorizationMatrixTest` | **2 passed, 2,138 assertions**, exit 0. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | PASS, no errors after the final tenant-scope patch. |
| `vendor/bin/pint --test` | PASS after formatting changed PHP files. |
| `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check`, `npm.cmd run test:unit`, `npm.cmd run build` | PASS; Vitest **24 passed**, production build transformed **688 modules**. |
| `composer validate --strict`, `composer check-platform-reqs`, `composer audit` | PASS; installed platform requirements satisfied and no Composer advisories. |
| `npm.cmd audit --omit=dev` | PASS, 0 production vulnerabilities. |
| `npm.cmd audit` | FAIL: **10 moderate** development-tool dependency-chain findings; compatible non-breaking remediation not established and no owner risk approval exists. |
| PHP syntax checks on all touched PHP/migration/test files | PASS, 12 files checked after the final tenant-scope patch. |
| `git diff --check` | PASS after code and report edits. |

## Database and browser limits

No `.env.phase14.testing`, `tests/run-phase14-mysql.php`, Phase 14 ownership manifest, or owner-authorized Phase 14 test schema was present at inspection. No MySQL migration or test was run. The full Phase 13 Playwright suite and a Phase 14 authenticated browser journey/theme matrix were not run. The Vite production build is not browser acceptance evidence.

The SQLite runner completed its isolated schema lifecycle only. The development database, Phase 13 MySQL database, and unrelated schemas were not targeted.

## Source material

The repository search found Phase 13’s `PHASE_14_HANDOFF.md` and the current Phase 13/14 implementation prompt. The original product blueprint, approved 24-phase roadmap, Phase 14 R&D documents, and formal grading-source integration contracts were not present in the repository search. Requirements beyond verified existing contracts are treated as partial implementation targets, not as recovered approval.

## Gate decision

Phase 13 remains blocked by the route-specific positive and campus/resource matrix. Phase 14 is an in-progress foundation and is not accepted: MySQL isolation, end-to-end browser workflows, source synchronization, official records, GPA/CGPA, report cards, transcripts, certificates, approvals, appeals, notifications, and concurrency probes remain incomplete or unverified. Phase 15 was not started.
