# Frontend Audit Test Report

Audit date: 2026-10-30. This report covers the current working tree. Browser fixtures use a per-run isolated SQLite database and synthetic test identities. No MySQL schema, development database, or accepted phase ownership manifest was changed.

## Inventory and coverage counts

- **91** TypeScript/TSX files under `resources/js/pages/`.
- **89** direct Inertia page components are associated with Laravel controller renders and GET routes; **2** files are reusable page-folder composition components without direct routes.
- The generated route inventory contains **91 component rows**: 89 routed components plus the 2 composition entries. Of the routed entries, **34** map to a direct primary-navigation destination and **55** are workflow/detail routes. The two composition components are embedded in a parent page. These navigation classes are static route-map results, not proof that every workflow link was browser-clicked.
- The new visual audit directly exercised **15 named page/view states**: 9 role dashboard states (platform administrator, institution owner, campus administrator, academic administrator, registrar, admissions officer, instructor, learner, guardian), public homepage, guardian learner directory, guardian attendance detail, and assignment creation, submission, and grading. These states use **8 distinct React components** because tenant roles share the role-aware dashboard.
- The complete E2E run executed **32 cases: 31 passed, 1 skipped**. The passing suite includes public shell, platform/institution workflows, SIS/admissions, academic/timetable, enrollment, attendance, assignment, and the new role/theme audit. A passing workflow may visit multiple pages; the suite does not provide an exhaustive unique-component visit count for all 89 routed components.
- **17** PNG evidence artifacts are under `screenshots/`. No static source search match was found for “coming soon”, “not implemented”, “sample data”, “fake dashboard”, “mock dashboard”, “dummy data”, or “future phase” in the inspected page source. This is not a substitute for functional review of every page.
- No required role dashboard or missing direct route mapping was identified. The audit has not established that all 89 routed components are complete, responsive, accessible, and interactive in every role/theme state.

### Requested audit totals

| Measure | Audited result |
|---|---|
| Frontend page files discovered | 91 |
| Direct Inertia page components with route/controller render wiring | 89 |
| Distinct page/view states directly documented as verified by this audit | 15 (including nine role dashboard states) |
| Placeholder or mock pages confirmed by the source-copy scan | 0 |
| Missing required role dashboards or route mappings identified | 0 identified |
| Missing navigation connections identified | 0 identified; 34 rows map to primary navigation, 55 are classified as workflow/detail routes, and 2 are composed fragments |
| Routed page components not exhaustively browser-classified for every interaction/role/theme | 89 |

The zero counts mean no such defect was confirmed in the audited evidence; they do not mean every route was fully reviewed. In particular, a source-copy scan cannot prove that a page has no mock behavior, and route wiring cannot establish interaction completeness.

## Commands and results

| Command | Result |
|---|---|
| `php storage/framework/generate-frontend-inventory.php` | PASS, exit 0; inventory refreshed from Laravel route metadata and current controller render calls |
| `npm.cmd run test:e2e -- --workers=1` | PASS, exit 0; 31 passed, 1 skipped (32 total) |
| `php artisan test` | PASS, exit 0; 282 passed, 9 skipped, 3,828 assertions, SQLite in-memory as configured by `phpunit.xml` |
| `php artisan test tests/Feature/SisStudentInformationTest.php --filter=test_student_suspension_and_reactivation_follow_the_tenant_membership_policy` | PASS, 1 passed, 17 assertions |
| `composer analyse` | PASS, exit 0; PHPStan/Larastan reported no errors after correcting the guardian student-number property access |
| `composer format:check` | PASS, exit 0; Pint |
| `npm.cmd run typecheck` | PASS, exit 0; `tsc --noEmit` |
| `npm.cmd run lint` | PASS, exit 0; ESLint with zero warnings allowed |
| `npm.cmd run format:check` | PASS, exit 0; Prettier |
| `npm.cmd run test:unit` | PASS, exit 0; Vitest 6 passed |
| `npm.cmd run build` | PASS, exit 0; Vite 8.3.5 transformed 668 modules. Main app JS: 362.21 kB (112.83 kB gzip); app CSS: 54.68 kB (10.79 kB gzip) |
| `composer audit` | PASS, exit 0; no security vulnerability advisories reported |
| `npm.cmd audit --audit-level=high` | PASS, exit 0; 0 vulnerabilities reported |
| `php -l app/Http/Controllers/Workspace/AttendanceController.php` | PASS, no syntax errors |
| `php -l tests/Feature/SisStudentInformationTest.php` | PASS, no syntax errors |

The backend run skipped nine environment-specific tests, including MySQL-only schema/locking checks and scanner-enabled ClamAV integration cases. Those were not reported as passing in this SQLite-only regression run. The Playwright suite skipped the clean-scanned transfer-certificate journey because the isolated browser run did not load the private scanner-enabled configuration. Scanner-dependent behavior therefore remains outside this report's pass evidence.

## Browser coverage

- All nine role dashboard states rendered over the supported PHP 8.3 Laravel HTTP runtime with real fixture-backed metric queries and permission-filtered navigation. The registrar used a distinct `sis.registrar` fixture and asserted scoped, non-zero offerings and confirmed registrations.
- The public homepage loaded through Laravel/Inertia and built Vite assets; desktop and narrow/mobile navigation were exercised. Public liveness remained generic, diagnostic readiness remained protected, untrusted host headers were rejected, and private source/storage endpoints were checked.
- Guardian learner directory and attendance detail were exercised with a verified fixture grant at a 390px viewport. The test checked the learner identity, axe violations, and horizontal overflow. Backend tests cover revocation and list/detail access boundaries.
- Assignment authoring, learner submission, grading and feedback were exercised in the existing interaction journey and captured as screenshots.
- The dashboard theme journey exercised all six registered themes, persisted a selected theme across reload, ran axe for each selection, checked a non-empty canvas token, and checked 390px reflow. Existing attendance, timetable, and learning-delivery E2E checks also include theme coverage as recorded in `SIX_THEME_AUDIT.md`.
- Keyboard navigation is covered for the public and institution shells. Automated axe checks are not a manual screen-reader or assistive-technology review.

## Failures found and repaired during this audit

1. The public homepage and shared public shell still advertised the Phase 01 foundation. They now show accurate platform capabilities and working sign-in/institution-workspace links without a fabricated logo or development-phase copy.
2. Tenant role dashboards were generic and had no useful role-specific entry points. The dashboard now selects a role-appropriate title/description, queries scoped database metrics, and renders only permission-authorized module cards. `tenant.workspace` continues to enforce active membership; module routes retain their own authorization middleware.
3. A valid active learner was previously treated as unauthorized for the workspace root in an older browser assertion. The workspace root intentionally accepts an active tenant membership and presents the learner's limited dashboard. The stale browser and feature-test expectations were updated; student suspension remains denied while the membership is suspended.
4. Guardians with a verified profile-access grant had no learner directory. A permission-protected list route and page now expose only verified, active grants. The detail page identifies the linked learner and provides a labeled, keyboard-focusable scroll region without a nested main landmark.
5. PHPStan found unnecessary nullsafe access on a relation proven present by the guardian query. The access was corrected; PHPStan and the complete SQLite suite passed afterward.
6. The first browser attempt used stale Vite assets and exposed old workspace-heading expectations. The production assets were rebuilt and stale assertions were aligned with the current role-aware UI. The final complete browser suite passed with the single documented scanner skip.

## Evidence artifacts

Screenshots: `public-homepage.png`, `mobile-navigation.png`, `platform-administration.png`, `institution-dashboard.png`, `campus-dashboard.png`, `academic-administration.png`, `registrar-dashboard.png`, `admissions-dashboard.png`, `instructor-dashboard.png`, `student-dashboard.png`, `guardian-dashboard.png`, `guardian-linked-learners.png`, `guardian-attendance-mobile.png`, `assignment-creation.png`, `assignment-submission.png`, `assignment-grading.png`, and `theme-switcher.png`.

## Findings and remaining coverage limits

- No critical/high-severity frontend authorization or sensitive-data exposure issue was identified in the routes and interactions exercised. This is a scoped audit, not a security certification.
- Local `.env`, `.env.testing`, and Phase 04–11 testing environment filenames were checked with `git check-ignore`; all were ignored and none were tracked. No environment contents or credentials were read into the report.
- All route/component mappings, page forms, accessibility states, error/empty/loading states, and six themes were **not** individually exercised for all 89 routed components. The remaining gap is exhaustive component-level browser/role/theme coverage, not a known missing page implementation.
- Manual screen-reader testing and comprehensive visual contrast review across every form/dialog/branding state were not performed.
- The ClamAV E2E skip and the nine backend environment-gated skips must be covered by the corresponding scanner-enabled and guarded MySQL runs before those areas are represented as verified here.
- No Phase 12 functionality was started.

## Audit disposition

The public homepage and all nine required role dashboard entry states have functional, permission-aware UI evidence, and the existing critical browser workflows pass. The audit does **not** declare the full Phases 01–11 frontend complete because 89 routed page components have not each received an exhaustive browser interaction, responsiveness, and theme assessment. Continue targeted component-level verification before making a completeness claim.
