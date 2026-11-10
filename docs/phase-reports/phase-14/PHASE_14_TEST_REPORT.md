# Phase 14 test report — 2026-11-06

## Fresh execution evidence

- `php artisan test --filter=Phase14GradebookFoundationTest`: **2 passed, 69 assertions**, exit 0. Includes weighted calculation, policy activation validation, gradebook/item and entry persistence, audit/revision history, separation of duties, publication snapshots, published-only student view, and cross-institution student denial.
- `php tests/run-tests.php --sqlite`: **333 passed, 10 skipped, 7,201 assertions**, exit 0. Skips are scanner-dependent or MySQL-only; they are not passes.
- `php tests/run-tests.php --sqlite --filter=Phase13ExaminationRouteAuthorizationMatrixTest`: **2 passed, 2,138 assertions**, exit 0.
- `php artisan test --filter='AcademicFoundationTest|Phase14GradebookFoundationTest|test_question_review_publication_attempt_autosave_grading_and_result_release_are_scoped'`: **20 passed, 399 assertions**, exit 0.
- PHPStan, TypeScript, ESLint, Prettier, Vitest (**24 passed**), Composer validation/platform checks/audit, production npm audit, and Vite production build passed in this execution. `vendor/bin/pint --test` passed after the route import order fix and source formatting.
- Full `npm audit`: exit 1, 10 moderate development-tool advisories. No forced fix.

## Not run

No Phase 14 MySQL test or concurrency probe; no Phase 14 authenticated Playwright journey or six-theme matrix; no real scanner-enabled Phase 14 document workflow. Phase 14 database credentials/schema/runner were not present. The existing Phase 13 MySQL and browser results remain historical for this run.

## Continuation evidence — 2026-11-06

This addendum records the continuation work and fresh checks; it does not replace earlier results.

### Implemented in this continuation

- Added immutable grade-source import and revision records plus `GradebookAssessmentSynchronizer` for scoped, authorized synchronization from released assignment, quiz, and examination outcomes. The assignment path is exercised end to end; quiz and examination adapters remain unverified integrations.
- Added the gradebook synchronization route and source controls in the authenticated gradebook UI. The index now offers only the supported percentage scale; policy precision is fixed at two decimals to match stored publication precision.
- Hardened published-grade corrections: approved overrides create a new publication snapshot and preserve revision history; uncontrolled republication and stale approvals are rejected.
- Added focused coverage for assignment score synchronization, idempotency, source amendments, manual-edit protection, and override history.

### Fresh verification

- `php artisan test --filter=Phase14GradebookFoundationTest`: **4 passed, 136 assertions**, exit 0.
- `php artisan test`: **335 passed, 10 skipped, 7,269 assertions**, exit 0. The skipped cases are scanner-gated or MySQL-only and are not counted as passes.
- `npm.cmd exec -- vitest run`: **24 passed**, exit 0.
- `npm.cmd exec -- playwright test --project=chromium --trace=off`: **40 passed, 2 failed, 1 skipped (43 total)**, exit 1. Both failures require the local ClamAV scanner workflow: examination marking did not reach the expected clean/available state, and appeal evidence showed unavailable rather than clean. The fail-closed behavior remains in place; this run is not scanner-enabled acceptance evidence.
- `vendor/bin/phpstan analyse app/Domain/Grading app/Http/Controllers/Workspace/GradebookController.php --no-progress --memory-limit=1G`: passed, no errors.
- `vendor/bin/pint --test`: passed for changed PHP files.
- `npm.cmd run typecheck`, `npm.cmd run lint`, and `npm.cmd run format:check`: passed.
- `npm.cmd run build`: passed; Vite built 688 modules.
- PHP syntax scan: **605 files checked, 0 failures**.
- Composer validation/platform check/audit and production npm audit passed; production npm audit has zero vulnerabilities. Full npm audit still reports **10 moderate development-tooling advisories**; no force update was applied and owner disposition remains pending.
- `git diff --check`: passed.

### Phase 14 gate status

**BLOCKED.** There is no `.env.phase14.testing`, guarded Phase 14 MySQL runner, or Phase 14 ownership manifest in the worktree, so no Phase 14 MySQL migration or integration test was attempted. No Phase 14 authenticated browser acceptance journey was executed. The work above does not complete the required official record calculations, GPA/CGPA and standing, report cards/transcripts/certificates, notification and approval workflows, complete source integrations, MySQL concurrency, or six-theme Phase 14 acceptance. Do not infer these from the gradebook foundation tests. Phase 15 was not started.

## Fresh continuation evidence — 2026-11-10

See [PHASE_14_CONTINUATION_2026-11-10.md](PHASE_14_CONTINUATION_2026-11-10.md) for exact commands, counts and limitations. Fresh key results: Phase 14 focused source/guard tests **9/175 pass**; scanner-enabled full Chromium **43 pass**; full SQLite **345 pass, 1 fail, 4 skipped, 7,424 assertions**. The failing full-suite ClamAV learner-response case passed when run alone (**1/22**), so the discrepancy remains unresolved. Phase 14 MySQL and concurrency suites were not run: preflight found no private `.env.phase14.testing`, and `--run` rejects until an eight-probe harness exists. This evidence does not promote Phase 14 to acceptance.

## Regression replay and grading slice — 2026-11-10

After adding the versioned scale-band migration and frontend controls, `php artisan test` completed with **353 passed, 4 skipped, 7,460 assertions**, exit 0, 235.70 seconds. The 4 skips are MySQL-specific schema/integration checks. The real ClamAV quiz asset/learner-response EICAR test passed in this full suite (42.11 seconds). This is a fresh full-run pass; the earlier full-run failure is retained as historical evidence because its cause was not reproduced.

`php artisan test --filter='GradeScaleResolverTest|Phase14GradebookFoundationTest'`: **13 passed / 196 assertions**. The test verifies decimal numeric/percentage outputs, institution threshold mapping, pass/fail boundaries, invalid scale rejection, HTTP policy creation, letter-grade publication and policy v2 band preservation.

Fresh checks after the slice: PHPStan **PASS**, Pint **PASS**, TypeScript **PASS**, ESLint **PASS**, Prettier **PASS**, Vitest **24 passed**, Vite production build **PASS**, Composer validation/platform checks/audit **PASS**, production `npm audit --omit=dev` **0 vulnerabilities**, PHP syntax for touched PHP files **PASS**, `git diff --check` **PASS**. Full npm audit was not freshly completed; previous 10 moderate development-toolchain findings and owner disposition pending remain in force.

The earlier isolated/full-suite scanner mismatch did not recur in the full run. No deterministic test-order or fixture-leak cause was identified; scanner code was not weakened or mocked.

## Fresh regression addendum � 2026-11-10, pass/fail publication

- Added an authenticated HTTP integration journey for institution-configured pass/fail policy creation, activation, gradebook scoring, independent approval, publication, and student result viewing.
- The 49.99% boundary result persisted as `Fail`, `passed=false`, and no grade points; the student saw the same released outcome.
- Focused grading tests: **14 passed / 218 assertions**, exit 0.
- Full SQLite suite: **354 passed, 4 MySQL-only skipped / 7,482 assertions**, exit 0, 496.88 seconds. Real scanner tests passed in this execution.
- Phase 14 MySQL preflight remained unavailable because `.env.phase14.testing` was absent; no database was contacted. Eight MySQL concurrency probes and Phase 14 browser/theme acceptance remain unimplemented or unverified.

## Current continuation � 2026-11-10

A Phase 14 gradebook browser journey now passes on the final changed code: `npm.cmd exec -- playwright test tests/e2e/phase14-gradebook.spec.ts --project=chromium --trace=off` ? **1 passed**. The authenticated workflow creates and activates an institution pass/fail policy, creates a gradebook, records two weighted assessments, submits for review, has a separate academic administrator approve and publish, then verifies the learner's released result. The browser journey asserts six themes on instructor/grade-manager, reviewer, and student pages, runs axe and responsive checks, confirms student theme persistence after reload, and captures 18 screenshots under `test-results/phase14-gradebook-theme-evidence/`.

The journey surfaced and verified a real usability defect: gradebook mutation actions used generic back redirects and could return users to the index. These endpoints now redirect to the scoped gradebook detail route; the E2E passes without forced navigation after item/entry edits. The student published-grade table is a keyboard-focusable named scroll region; axe found and verified this fix.

Fresh final-code backend regression: `php artisan test` ? **354 passed, 4 MySQL-only skipped, 7,482 assertions**, exit 0 (287.69 sec). Focused gradebook suite after redirect change: **8 passed / 210 assertions**. Browser journey: **1 passed**, including **18 role/theme screenshots** and axe checks. `npm.cmd run build`, typecheck, ESLint, Prettier, Vitest (**24 passed**), PHP syntax for the controller, Pint, and `git diff --check` passed. PHPStan for the touched controller is recorded as the next verification item if not rerun in this continuation.

The old full shared Chromium count is not substituted for the focused Phase 14 evidence. Full Phase 14 MySQL and concurrency remain not run: `.env.phase14.testing` is absent and the guarded preflight refused before database contact. GPA/CGPA/standing, official report cards/transcripts/certificates, broad role screens and notifications remain incomplete. Phase 15 was not started.

Post-suite verification addendum: PHPStan on `app/Domain/Grading` and `GradebookController` passed with no errors; Pint passed; PHP syntax passed for the changed controller, grade feature test and guarded E2E fixture; Composer audit found no advisories; `npm audit --omit=dev` found zero production vulnerabilities; `git diff --check` passed. The full npm development audit remains owner-risk pending based on the previously verified moderate ESLint/TypeScript-ESLint chain.

## Implementation finisher evidence - 2026-11-10

Implemented and exercised this continuation:
- GPA policy versions, repeat-course rules, integer milli-point/credit calculations, published-grade-only term/cumulative snapshots, standing bands, idempotency and superseding recalculation history.
- Versioned report-card/transcript/certificate templates and immutable record snapshots; draft, submit, independent review, issue, revoke and privacy-safe certificate verification; private PDF download checks student, active verified guardian authority, tenant/institution and campus scope.
- Fixed a campus-scoped staff download authorization gap and added a regression on the real download route.
- Corrected the academic permission-upgrade test to execute the current official-record permission migration and verify student/guardian grants remain role-specific rather than being granted to staff.
- Authenticated transcript browser workflow now prepares from published grade data, receives independent approval, issues the record and downloads a private PDF. The six-theme axe matrix passed and captured 30 screenshots across grade-manager, reviewer, GPA and student record screens.

Fresh results:
- `php artisan test --compact`: **355 passed, 4 skipped, 7,598 assertions**, exit 0, 399.49 seconds. Skips are MySQL-gated checks.
- `php artisan test --filter=Phase14GradebookFoundationTest`: **9 passed, 322 assertions**, exit 0 before the added campus-download assertion. The updated GPA/record feature was rerun alone: **1 passed, 113 assertions**; permission upgrade regression: **1 passed, 10 assertions**.
- `npm.cmd run test:e2e -- --grep "Phase 14 grade policy"`: **1 passed**, exit 0; 30 screenshots under `test-results/phase14-gradebook-theme-evidence/`.
- `npm.cmd run test:unit`: **24 passed**. Production Vite build passed (693 modules); TypeScript, ESLint, Prettier, PHPStan, Pint, PHP syntax and `git diff --check` passed.
- Composer validation and audit passed with no advisories. `npm audit --omit=dev`: zero vulnerabilities. Full `npm audit`: 10 moderate development-tooling advisories; a forced breaking downgrade was not applied and owner disposition remains pending.
- `php tests/run-phase14-mysql.php --preflight`: exit 1, exact result: missing regular private `.env.phase14.testing`; no database was contacted. `git check-ignore` confirms the filename is ignored. The Phase 14 concurrency harness is absent, so guarded MySQL execution remains disabled.

Not accepted as complete: Phase 14 MySQL integration and all eight independent-session probes; end-to-end report-card and certificate journeys; a generated QR image; teacher-owned comment workflow; durable academic notification/outbox; full registrar/guardian administration workspaces and their role-by-screen journeys. Full phase acceptance remains blocked. Phase 15 was not started.

The immediately preceding full run before permission-test repair had 354 passed, 1 failed, 4 skipped / 7,591 assertions. Its sole failure was the upgrade test expecting all academic permissions, including student/guardian-only visibility, on staff roles. The test now executes the additive official-record grants and separately checks student/guardian grants and staff denial; its focused rerun passed (1 test / 10 assertions), and the subsequent full run above passed. The role grant boundaries were preserved.


Latest functional completion evidence (2026-11-10): see PHASE_14_FINAL_FUNCTIONAL_COMPLETION_2026-11-10.md. This addendum supersedes prior status statements only where it records newer evidence; earlier historical results are retained.


## Fresh closure evidence ? 2026-11-10

See [PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md](PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md) for this pass's detailed evidence. Key disposition: focused guardian published-grade workflow passed (9 tests / 447 assertions); focused guardian browser test passed after asset rebuild; TypeScript, ESLint, Prettier, Vitest (24), Composer audit, production npm audit, and Vite production build passed. The earlier full Chromium run remains 42 passed / 2 failed and was not rerun as a complete suite. Phase 14 MySQL preflight exited 2 because private `.env.phase14.testing` is absent; MySQL tests and all eight concurrency probes were not run. Phase 14 remains BLOCKED; no Phase 15 work started.
