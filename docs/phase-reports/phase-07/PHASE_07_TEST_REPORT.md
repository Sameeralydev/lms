# Phase 07 verification report

Verification date: 2026-10-27. Local Windows execution only; hosted CI was not run. This report does not claim production certification.

## Template and live-session continuation verification

This later continuation supersedes prior entries that described templates and live sessions as unimplemented.

| Command | Result |
|---|---|
| `php tests/run-tests.php --preflight-phase07` | PASS, exit 0; read-only identity and ownership preflight verified MySQL 8.4.11, dedicated Phase 07 schema/account, 135 schema objects / 40 migrations. |
| `php tests/run-tests.php --sqlite --filter='test_course_templates_are|test_live_sessions'` | PASS, 2 tests / 103 assertions. |
| `php tests/run-tests.php --mysql-phase07 --filter='test_course_templates_are|test_live_sessions'` | PASS, 2 tests / 103 assertions against the guarded MySQL schema. |
| Focused `npm.cmd run test:e2e -- --grep="platform administrator signs in|institution owner creates and authors a curriculum-linked course delivery workspace"` | PASS, 2 Chromium tests. The learning journey covers session schedule/reschedule/cancel, calendar date/status filter with no join action for cancelled sessions, template creation/adoption, selective module copy, version 2, history and analytics; desktop/mobile axe checks run on the analytics route. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0; 220 passed / 7 engine or local-scanner skips / 2,481 assertions. Includes the required reading acknowledgement gate case. |
| `php tests/run-tests.php --mysql-phase07` | PASS on latest standalone rerun, exit 0; 226 passed / 1 intentional skip / 2,571 assertions. Read-only preflight remained valid at 135 objects / 40 migrations. Includes the required reading acknowledgement gate case and real scanner checks. |
| Isolated guarded admissions scanner test `--filter=test_real_clamav_scans_private_admission_uploads_and_only_releases_clean_files` | PASS, 1 test / 30 assertions. |
| Full guarded suite run concurrent with SQLite suite | First attempt had 1 transient admissions scanner execution failure (225 passed / 1 skipped / 2,546 assertions); the MySQL runner did not advance ownership evidence. Read-only preflight passed afterward. The isolated scanner test and standalone full MySQL rerun passed. This is recorded as a transient host-load failure, not suppressed. |
| `npm.cmd run test:e2e` | PASS, 23 passed / 1 scanner-gated skip. |
| Scanner-enabled `npm.cmd run test:e2e` | PASS, 24 passed / 0 skipped, exit 0. The complete serial journey ran with the local ClamAV binary and signature directory explicitly enabled; clean-scanned SIS transfer flow passed. |
| `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` | PASS after fixing 13 session date-type findings by matching the `immutable_datetime` cast to `Carbon\CarbonImmutable` in the model contract and serializing non-null start/end timestamps directly. Focused and full analysis then reported no errors. |
| `vendor/bin/pint --test` / `php -l` traversal | PASS. Pint passed on changed PHP; 235 application PHP files were syntax checked with zero errors. |
| Composer/npm checks | PASS: `composer validate --strict`, `composer check-platform-reqs`, `composer audit --no-interaction`, TypeScript, ESLint, Prettier, Vitest (6), npm audit (0 vulnerabilities) and production Vite build (641 modules). |
| `git diff --check` | PASS, exit 0 after final documentation edits. |

Focused test counts rose from the historical MySQL 224 and SQLite 218 baselines to 226 and 220 passing tests respectively after the template and session coverage was added. The extended learning-delivery browser step covers scheduling, rescheduling, cancellation, calendar filtering and hiding join access after cancellation, template create/adoption/selective copy/version history, and the existing analytics checks. It does not cover all milestone requirements listed in traceability.

The required ungraded activity test now also publishes a required `reading_acknowledgement` block, verifies that a false acknowledgement is rejected, verifies that completion remains blocked until acknowledgement is submitted, then completes the learner/instructor confirmation path. This focused test passed on SQLite and guarded MySQL with 56 assertions each; the latest full-suite counts above include this coverage.

## Latest verification addendum — supersedes earlier scanner-failure figures

The currently configured ClamAV signature database passed freshness validation. The real scanner classified a benign file clean and the standard harmless EICAR test signature as infected/rejected. Scanner output and local credentials are not copied into this report.

| Command | Latest result |
|---|---|
| `php tests/run-tests.php --preflight-phase07` | PASS, exit 0 before and after MySQL suite; dedicated `techmentors_lms_phase07_test`, authenticated Phase 07 test account, MySQL 8.4.11, 130 schema objects / 38 migrations; no schema or ownership-manifest change. |
| `php tests/run-tests.php --mysql-phase07` | PASS, 224 tests passed / 1 intentional Phase 06-only skip / 2,465 assertions. Includes real ClamAV clean/EICAR admission and learning-media integration, offer-capacity concurrency, activity response, analytics, tenant isolation and migration/schema checks. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0; 218 passed / 7 expected MySQL/scanner-only skips / 2,375 assertions. |
| `npm.cmd run test:e2e` | PASS, 23 passed / 1 scanner-gated skip. |
| Scanner-enabled `npm.cmd run test:e2e` | PASS, 24 passed / 0 skipped. The isolated run used its generated per-run SQLite database and the local scanner binary/signature directory; the real-scanner transfer journey and analytics axe/mobile checks passed. |
| `npm.cmd run test:unit` | PASS, 6 Vitest tests. |
| `npm.cmd run typecheck`; `npm.cmd run lint`; `npm.cmd run format:check` | PASS, exit 0 after correcting analytics card markup and formatting. |
| `npm.cmd run build` | PASS, Vite transformed 638 modules. Main JS 357.59 kB raw / 111.72 kB gzip; stylesheet 45.62 kB raw / 9.59 kB gzip. |
| `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress`; targeted `vendor/bin/pint --test` | PASS, no PHPStan errors; Pint passed for changed PHP files. |
| `composer audit --no-interaction`; `npm.cmd audit --audit-level=high` | PASS, no reported advisories / zero npm vulnerabilities. |
| PHP syntax traversal; `git diff --check` | PASS, 335 PHP files syntax checked; no whitespace errors. |

Analytics feature test passed on SQLite and guarded MySQL (1 test / 42 assertions each). A focused analytics Playwright check exposed two accessibility defects (table-header contrast and metric-card definition-list structure); both were corrected, the production assets rebuilt, and the route subsequently passed axe with no violations at desktop and narrow viewport widths.

Earlier historical rows below are retained for troubleshooting context only. Where their values conflict with this addendum, this latest addendum is authoritative.

## Final delivery sprint delta

The latest run extended the existing release-policy block with lesson and module prerequisites, a dependency-cycle guard, module locking, enrollment-relative drip days, an end-of-availability instant, and current-published-revision completion checks. Instructor editing now exposes these policy fields. The change is application-only and required no migration.

The broader PASS rows below are the previously verified pre-sprint baseline; they are historical context. The latest outcomes in this section supersede them where the same suite was rerun. The latest SQLite full run reported no risky tests after the assertionless ownership-policy test was repaired.

| Command | Latest result |
|---|---|
| `php tests/run-tests.php --preflight-phase07` | PASS, exit 0; dedicated schema/account, MySQL 8.4.11, ownership and exact grant checks; 130 objects / 38 migrations. Read-only. |
| `php tests/run-tests.php --mysql-phase07 --filter=test_release_window_and_sequential_progression_are_enforced_on_direct_learner_routes` | PASS, 1 test / 51 assertions, exit 0. MySQL test covers the direct learner release and current-revision sequencing route, including a prerequisite reference. |
| `php tests/run-tests.php --mysql-phase07 --filter=CourseDeliveryTest` | FAIL, 6 passed / 1 failed / 255 assertions, exit 1. The real ClamAV clean-media case was quarantined because the configured signature database is stale. The suite failed closed; the ownership manifest was not advanced. |
| Direct scanner diagnostic using the ignored Phase 07 configuration | `unavailable / signature_database_stale`; no credential values were displayed. |
| `php tests/run-tests.php --sqlite` | PASS, 217 passed / 7 skipped / 2,332 assertions, exit 0. A previously existing risky migration-policy test was corrected to make explicit assertions; MySQL-only and real-scanner tests remain expected skips in SQLite. |
| `npm.cmd run test:unit` | PASS, 6 tests. |
| `npm.cmd run test:e2e` | PASS, 23 passed / 1 scanner-gated skip; exit 0. Existing shell, tenant/platform, admissions, SIS, curriculum, responsiveness and accessibility journeys. No new Phase 07 learning-delivery journey was added. |
| `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check`, `npm.cmd run build` | PASS. Production Vite build transformed 637 modules. |
| `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` | PASS after resolving a PHPDoc issue. Initial default 128 MiB run exhausted memory and was not treated as a pass. |
| `vendor/bin/pint --test ...` | PASS after formatting the new policy/test code. |
| `php -l` on changed release-policy/workflow/test PHP files | PASS, all four checked files report no syntax errors. |

The installed FreshClam executable is present but its default machine configuration file is absent. A targeted update was not completed in this run. To re-enable real scanner integration, provision/update signed ClamAV databases in the configured local signature directory, then rerun the scanner probe and guarded MySQL learning-media tests. Do not increase the accepted maximum signature age to bypass staleness.

## Guarded database evidence

| Command | Result |
|---|---|
| `php tests/run-tests.php --preflight-phase07-upgrade` | PASS, exit 0. Read-only preflight authorized only `2026_10_27_000003_create_course_delivery_activity_responses` against the owned Phase 07 migration prefix. |
| `php tests/run-tests.php --mysql-phase07-upgrade --filter=CourseDeliveryTest` | PASS, exit 0. Verified the intent-backed migration transition, preserved the prior ownership manifest, and ran CourseDeliveryTest: 7 passed / 260 assertions. Includes real ClamAV clean and EICAR learning upload cases plus persisted activity completion. |
| `php tests/run-tests.php --preflight-phase07` | PASS, exit 0. Connected as the dedicated Phase 07 test account to `techmentors_lms_phase07_test`, MySQL 8.4.11; 130 objects / 38 migrations. Exact grant policy and ownership manifest passed. |
| `php tests/run-tests.php --mysql-phase07` | PASS, exit 0. 221 passed / 1 skipped / 2,419 assertions. The one skip is a Phase 06-only curriculum concurrency test. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0. 215 passed / 7 skipped / 2,329 assertions. MySQL/scanner-only tests intentionally skip under SQLite. |

The initial ordinary preflight correctly stopped before DDL when the new local migration was not yet present in the database. A Phase 07-only upgrade path was added with an exact migration allowlist, manifest match, exclusive lock, content-hashed intent, stable preflight, exact schema transition verification and postflight ownership attestation. The first postflight attempt stopped before tests because a temporary runner annotation was included in its manifest comparison. That code defect was corrected. The migration remained intact; recovery verified its existing intent and exact schema transition, preserved the previous manifest as local evidence and then ran the focused suite. No prior-phase or development schema was targeted or reset.

## Frontend and browser checks

| Command | Result |
|---|---|
| `npm.cmd run typecheck` | PASS, exit 0. |
| `npm.cmd run lint` | PASS, exit 0; ESLint reported no errors or warnings. |
| `npm.cmd run format:check` | PASS, exit 0; Prettier passed. |
| `npm.cmd run test:unit` | PASS, exit 0; 6 Vitest tests. |
| `npm.cmd run build` | PASS, exit 0; Vite transformed 637 modules. Main JS 357.44 kB raw / 111.68 kB gzip; CSS 45.54 kB / 9.58 kB gzip. |
| `npm.cmd audit --audit-level=high` | PASS, exit 0; zero reported vulnerabilities. |
| `npm.cmd run test:e2e` | PASS, exit 0; 23 passed / 1 scanner-gated skip in the standard run. |
| Full `npm.cmd run test:e2e` with locally configured ClamAV opt-in | PASS, exit 0; 24 passed, including the real scanner-dependent SIS transfer journey. Real HTTP Chromium with isolated unique SQLite fixture. |

The browser suite covered existing platform, institution, admissions, SIS, curriculum authoring, responsive, keyboard and shell accessibility workflows. It does not cover the required full Phase 07 learning delivery journeys for activities, prerequisites, media lifecycle, templates/copy, live sessions, analytics or all tenant/release cases.

## PHP, dependencies and repository checks

| Command | Result |
|---|---|
| `composer validate --strict` | PASS, exit 0. |
| `composer check-platform-reqs` | PASS, exit 0; PHP 8.3.35 and required installed platform dependencies satisfied. |
| `composer audit --no-interaction` | PASS, exit 0; no reported advisories. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | PASS, exit 0; no errors. |
| `vendor/bin/pint --test` | PASS, exit 0 after formatter fixes. |
| PHP syntax traversal over app, bootstrap, config, migrations, routes and tests | PASS, exit 0; 334 PHP files. Final traversal after code edits. |
| `git diff --check` | PASS, exit 0; no whitespace errors. |
| Hosted CI | NOT RUN; the new .github/workflows/phase07.yml passed local Prettier YAML-format/parser validation. Hosted execution is unavailable in this workspace. The workflow requires repository secrets PHASE07_MYSQL_ROOT_PASSWORD and PHASE07_MYSQL_PASSWORD; no secret values are in source. |

## Scanner and security evidence

The guarded MySQL CourseDeliveryTest used the locally configured real ClamAV scanner for clean and harmless EICAR learning uploads. Both behaved as expected. The complete scanner-enabled Chromium suite passed the existing SIS transfer certificate workflow. Scanner configuration values are private and are intentionally omitted here. Unit cases for missing binary/signatures, timeout, stale metadata, unsupported content and process failure are part of the scanner adapter test suite.

This is not an ASVS certification or production security assessment. Full release checks remain incomplete for advanced release authorization, response authorization matrices, course-copy boundaries, media replacement/purge controls and additional browser journeys.

## Gate

Phase 07 remains BLOCKED. The executed tests establish regression health for the implemented slice, not full acceptance. Outstanding requirements are listed in `PHASE_07_REQUIREMENT_TRACEABILITY.md` and `PHASE_07_FINAL_GATE_REPORT.md`.

## Final acceptance recovery run — 2026-10-27

The following results were executed after the latest learning workflow changes. They supersede older totals above.

| Command | Result |
|---|---|
| `php tests/run-tests.php --mysql-phase07` | PASS, exit 0; 228 passed / 1 intentional skip / 2,775 assertions. Guarded dedicated MySQL schema only; real ClamAV clean/EICAR learning-document tests passed. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0; 222 passed / 7 expected engine/scanner skips / 2,685 assertions. |
| `php tests/run-tests.php --mysql-phase07 --filter=CourseDeliveryTest` | PASS, exit 0; 12 passed / 613 assertions. |
| `php tests/run-tests.php --sqlite --filter=CourseDeliveryTest` | PASS, exit 0; 11 passed / 1 expected scanner-not-configured skip / 600 assertions. |
| Scanner-enabled `npm.cmd run test:e2e` with `E2E_CLAMAV_ENABLED=true` and the verified local binary/signature directory | PASS, exit 0; 24 passed / 0 skipped. |
| `npm.cmd run test:unit` | PASS, exit 0; 6 Vitest tests. |
| `npm.cmd run typecheck`; `npm.cmd run lint`; `npm.cmd run format:check` | PASS, exit 0 for each. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`; `composer format:check` | PASS, exit 0. |
| `composer validate --strict`; `composer check-platform-reqs`; `composer audit`; `npm.cmd audit --audit-level=moderate` | PASS, exit 0; Composer valid/platform-compatible and no dependency advisories/vulnerabilities reported. |
| `npm.cmd run build` | PASS, exit 0; Vite transformed 641 modules; primary app bundle 358.05 kB raw / 111.80 kB gzip; CSS 46.20 kB raw / 9.67 kB gzip. |
| PHP syntax traversal (`app`, `routes`, `database`, `tests`) | PASS, exit 0; no parse errors. |
| `php tests/run-tests.php --preflight-phase07` | PASS, exit 0; MySQL 8.4.11; exact dedicated schema/account; 136 objects / 41 migrations; read-only, no schema/data/manifest changes. |
| `php artisan route:list --path=learning --except-vendor` | PASS, exit 0; 39 routes listed for review. |
| `git diff --check` | PASS, exit 0. |

Hosted CI was NOT RUN. No configured Git remote is available in this checkout. The five specified Phase 07 two-worker races were NOT RUN and remain a mandatory blocker; no test count above includes such race coverage.

## Rapid recovery final verification — 2026-10-27

| Command | Result | Evidence |
|---|---|---|
| `php tests/run-tests.php --mysql-phase07` | PASS, exit 0 | 226 passed / 1 skipped / 2,617 assertions; 165.24 sec. The guarded runner used only the owned Phase 07 test schema. The single skip is an existing non-Phase-07 test marked for another engine/phase. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0 | 220 passed / 7 expected engine/scanner skips / 2,527 assertions; 36.14 sec. |
| `php tests/run-tests.php --preflight-phase07` | PASS, read-only | MySQL 8.4.11; dedicated Phase 07 test schema/account; 136 schema objects / 41 migrations; ownership and grant checks passed. No manifest/schema/data change. |
| Scanner-enabled `npm.cmd run test:e2e` | PASS | 24 Chromium tests passed, including learner/instructor completion, independent release exception approval, clean-media replacement and template-copy rescan, analytics filters, and the existing admissions/SIS/platform workflows. |
| `npm.cmd run test:unit` | PASS | 6 Vitest tests. |
| `npm.cmd run typecheck`; `npm.cmd run lint`; `npm.cmd run format:check` | PASS | TypeScript, ESLint and Prettier exit 0. |
| `npm.cmd run build` | PASS | Vite transformed 641 modules. Main JS 358.05 kB raw / 111.82 kB gzip; CSS 46.04 kB raw / 9.62 kB gzip. |
| `vendor\bin\phpstan analyse --no-progress --memory-limit=512M` | PASS | No static-analysis errors. |
| `vendor\bin\pint --test` on changed PHP files; `php -l` changed PHP files; `git diff --check` | PASS | Formatting, syntax and whitespace checks passed. |
| `composer validate --strict`; `composer check-platform-reqs --no-interaction`; `composer audit --no-interaction` | PASS | Composer metadata/platform valid; no reported advisories. |
| `npm.cmd audit --audit-level=moderate` | PASS | Zero reported vulnerabilities. |

Browser defect/remediation evidence: the workspace assignment panel was hidden after publication despite the backend supporting authorized post-publication assignment; the UI now exposes it only to the same manager permission. Campus member discovery now includes tenant-wide (null-campus) members as permitted by the assignment service, while excluding other campuses. The E2E assertions now verify the actual workspace publication status, completion status, analytics columns by accessible headers, and publication filter result. The real ClamAV request limit now agrees with its enforced per-process timeout plus a five-second margin, preventing PHP’s default 30-second limit from killing a legitimate bounded scan.

These checks establish regression health for the implemented slices; they do not close the Phase 07 mandatory items enumerated in the current requirement traceability.



## Final QA closure evidence — 2026-10-27

This is the latest result set and supersedes earlier counts for the same complete suites. Local Windows execution only; hosted CI was not run.

| Command | Result |
|---|---|
| `php tests/run-tests.php --preflight-phase07` (final read-only postflight) | PASS, exit 0. MySQL 8.4.11; dedicated Phase 07 schema/account; 136 schema objects / 41 migrations. Output confirmed no schema or ownership-manifest changes. |
| `php tests/run-tests.php --mysql-phase07` | PASS, exit 0; 228 passed / 1 intentional Phase 06-only skip / 2,743 assertions. Guarded test execution verified real ClamAV clean/EICAR behavior for scanner-enabled integration cases. |
| `php tests/run-tests.php --sqlite` | PASS, exit 0; 222 passed / 7 expected engine/scanner skips / 2,653 assertions. |
| `php tests/run-tests.php --sqlite --filter=CourseDeliveryTest` | PASS after formatting; 11 passed / 1 scanner-not-configured skip / 568 assertions. The skipped test requires a real scanner; it is not counted as a pass. |
| Scanner-enabled `npm.cmd run test:e2e` | PASS; Playwright `test-results/.last-run.json` reports `status: passed` and `failedTests: []`; 24 Chromium tests passed. |
| `npm.cmd run test:unit` | PASS; 6 Vitest tests. |
| `npm.cmd run typecheck`; `npm.cmd run lint`; `npm.cmd run format:check` | PASS for each. |
| PHPStan with 1 GiB memory | PASS, no analysis errors. |
| `vendor/bin/pint --test` | PASS after formatting the changed PHP test file. |
| Composer validation/platform/audit | PASS. |
| `npm.cmd audit --audit-level=moderate` | PASS; 0 vulnerabilities. |
| `npm.cmd run build` | PASS; Vite transformed 641 modules; main JS 358.05 kB raw / 111.82 kB gzip, CSS 46.04 kB / 9.62 kB gzip. |
| `git diff --check` | PASS, exit 0. |

The post-format focused SQLite suite confirms the test-file formatting repair did not change behavior. Browser coverage includes role-specific live-session visibility, cancellation with join access hidden, 390px layout/keyboard focus, and axe smoke checks on the learning path. These checks do not establish full accessibility conformance or production performance.

New regression cases cover completion-policy combinations and revision history, instructor scoping and duplicate confirmation, retention/purge of unreferenced verified media while preserving quarantined assets, optional progression, malformed-reference detection/remediation, and template-copy rollback. Copy rollback is induced through a missing source private asset and verifies destination/copy-operation rollback. Full MySQL execution includes this delivery coverage on MySQL 8.4.11.

Not verified: deterministic multi-process Phase 07 races for completion/confirmation, release transitions, template copy and live-session transitions; positive cross-institution template sharing (the current schema allows only one institution per tenant); remote URL availability checking (intentionally no arbitrary outbound fetch); hosted CI. These are not reported as passes.

## Final acceptance recovery verification — latest run

Verification date: 2026-10-27. These commands were executed locally in Windows; hosted CI was not run.

| Command | Result |
|---|---|
| php tests/run-tests.php --mysql-phase07 | PASS, exit 0; 228 passed / 1 intentional Phase 06-only skip / 2,763 assertions. |
| php tests/run-tests.php --sqlite | PASS, exit 0; 222 passed / 7 expected skips / 2,673 assertions. |
| php tests/run-tests.php --mysql-phase07 --filter=CourseDeliveryTest | PASS, exit 0; 12 passed / 601 assertions, including real ClamAV clean/EICAR learning-media tests and same-institution cross-campus template visibility. |
| php tests/run-tests.php --sqlite --filter=CourseDeliveryTest | PASS, exit 0; 11 passed / 1 expected scanner-not-configured skip / 588 assertions. |
| Scanner-enabled npm.cmd run test:e2e | PASS, exit 0; 24 Chromium tests passed. test-results/.last-run.json reports passed with no failed tests. |
| npm.cmd run test:unit | PASS; 6 Vitest tests. |
| npm.cmd run typecheck; npm.cmd run lint; npm.cmd run format:check | PASS, exit 0 each. |
| php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G | PASS; no analysis errors. |
| vendor/bin/pint --test | PASS. |
| php -l on changed PHP workflow/model/test files | PASS; no syntax errors. |
| composer validate --strict; composer check-platform-reqs --no-interaction; composer audit --no-interaction | PASS. |
| npm.cmd audit --audit-level=moderate | PASS; 0 vulnerabilities. |
| npm.cmd run build | PASS; 641 modules transformed; final CSS 46.20 kB / 9.67 kB gzip; main JS 358.05 kB / 111.80 kB gzip. |
| php tests/run-tests.php --preflight-phase07 | PASS, exit 0; MySQL 8.4.11, dedicated test schema/account, 136 objects / 41 migrations; read-only, no manifest/schema change. |
| git diff --check | PASS, exit 0. |

The isolated Playwright filter for the learning journey alone is not a supported run because that journey depends on the preceding platform provisioning test to create and accept the owner invitation. Running those two tests together passed. A first full browser attempt exposed a dark-theme warning-text contrast defect in the SIS import mapping screen; after replacing fixed amber text classes with theme-specific warning tokens and rebuilding, the full 24-test suite passed.

New learning journey axe assertions cover course builder, media authoring, learner lesson/progress, session calendar, templates and analytics. The six-theme check runs on learning analytics. It does not constitute exhaustive manual screen-reader or every-route/every-theme verification.

Deterministic two-process Phase 07 tests for concurrent completion, confirmation/update, release mutation, template copy and session transitions were not implemented or run. Sequential idempotency/conflict tests are not concurrency evidence.

## Critical gate closure update — 2026-10-28

The earlier “not implemented” statements above are historical and are superseded by these final runs.

| Command / check | Result |
|---|---|
| `php tests/run-tests.php --mysql-phase07` | Exit 0; 229 passed, 1 intentional Phase 06-only skip, 2,941 assertions. The guarded runner executed five deterministic two-worker probes after PHPUnit while retaining the exclusive lock. |
| Two-worker MySQL probes | 5/5 passed: learner completion; instructor confirmation vs response update; concurrent release-policy edits; same-key template copies; live-session transition. Workers had distinct `CONNECTION_ID()` values, synchronized barrier start and bounded completion. Persisted business/audit invariants and cleanup were checked. |
| `php tests/run-tests.php --sqlite` | Exit 0; 223 passed, 7 expected skips, 2,851 assertions. MySQL-only races and real scanner integration are intentionally not SQLite evidence. |
| Scanner-enabled `npm.cmd run test:e2e` | Exit 0; 24 Chromium tests passed. Includes 36 screen/theme accessibility combinations and real configured scanner journey. |
| `npm.cmd run test:unit` | Exit 0; 6 Vitest tests passed. |
| Static/build/security checks | PASS: PHPStan with 512M memory limit (no errors); Pint; PHP syntax for 354 PHP files; typecheck; ESLint; Prettier; Composer validate/platform/audit; npm audit (0 vulnerabilities); production build; `git diff --check`. |
| `php tests/run-tests.php --preflight-phase07` | Exit 0; read-only MySQL 8.4.11 identity preflight, dedicated Phase 07 schema/account, 136 objects / 41 migrations, ownership manifest unchanged and no persistent probe fixture. |

The 39-route adversarial feature test is built from Laravel's actual route collection. Applicable denials include guest, active nonmember, revoked member, wrong tenant, forged IDs and wrong workflow role; wrong-campus cases apply to workspace-scoped read routes. It asserts no learning/audit row-count mutation. Cross-institution-within-tenant is not testable under the locked one-institution-per-tenant model and is an explicit architecture exception; same-institution cross-campus and cross-tenant denial are tested.

Automated accessibility results are limited to axe, keyboard/focus visibility, responsive reflow and native form errors. No manual screen-reader or assistive-technology audit was run. Hosted CI is NOT RUN because no remote exists.

## Latest rerun evidence — 2026-10-28

- `php tests/run-tests.php --preflight-phase07`: exit 0; read-only MySQL 8.4.11 preflight reported the dedicated Phase 07 schema, 136 objects and 41 migrations.
- `php tests/run-tests.php --mysql-phase07 --phase07-baseline`: PHPUnit reported 230 passed, 1 intentional skip and 2,945 assertions. Five guarded two-worker probes then passed. The phase baseline option excludes only PHPUnit group `phase08`, because those two newer migrations are intentionally not applied to the Phase 07 target.
- `php tests/run-tests.php --sqlite`: 227 passed, 7 expected skips and 2,913 assertions.
- Scanner-enabled `npm.cmd run test:e2e`: exit 0, 25/25 Chromium tests. A subsequent dependency-aware focused rerun passed 2/2 and exercised the new visible-focus-indicator assertion in the 36-combination learning screen/theme matrix.
- `npm.cmd run test:unit`: 6 Vitest tests passed.
- PHPStan: 348 files, no errors; Pint passed; PHP syntax passed across 365 files.
- TypeScript, ESLint, Prettier, Composer validate/platform/audit, npm audit (0 vulnerabilities), Vite production build (644 modules), and `git diff --check` passed.
- Live route collection: 39 named Phase 07 learning routes. The parameterized route test passed on MySQL and asserted no unauthorized learning/audit row-count changes.

The Phase 07 target migration inventory is explicitly capped at `2026_10_29_000000_create_course_delivery_release_exceptions`. Later enrollment migrations remain outside this target and unapplied; the safety guard continues exact inventory and ownership checks for every migration inside the target boundary.
