# Phase 13 Test Report

## Authoritative fresh rerun â€” 2026-11-05

| Check | Result |
|---|---|
| Guarded MySQL preflight | PASS â€” `php tests/run-phase13-mysql.php --preflight`; MySQL 8.4.11, dedicated Phase 13 schema, 253 objects, 69 migrations, zero pending reviewed migrations. |
| Guarded MySQL suite | PASS â€” `php tests/run-phase13-mysql.php --run`; 31 passed / 1 scanner-gated skip / 1,043 assertions. Postflight ownership fingerprint unchanged (`dea00d4011c05e37bbc229a803a7a5a16bc0ca82178ae5d65fc62e4e65640e7c`). |
| Independent MySQL concurrency | PASS â€” 8 production-workflow probes, 16 worker sessions, distinct connection IDs, bounded synchronization. |
| SQLite | PASS â€” `php artisan test --compact`; 327 passed / 4,949 assertions / 10 skipped. |
| Chromium | PASS â€” full scanner-enabled `npm.cmd run test:e2e -- --project=chromium`; 42 passed / 0 failed / 0 skipped. The first full invocation found a formal-exam outbox event handling defect (41/42); after repair, the affected journey passed 1/1 and the full suite passed 42/42. |
| Frontend unit tests | PASS â€” `npm.cmd run test:unit -- --run`; 24 tests / 3 files. |
| Static/style/package checks | PASS â€” `composer analyse`, `vendor\bin\pint --test`, `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check`, `composer validate --no-check-publish`, `composer audit`. |
| PHP syntax | PASS â€” `php -l` across 560 PHP files; 0 failures. |
| Production build | PASS â€” `npm.cmd run build`; Vite 8.3.5, 685 modules transformed. |
| Dependency audit | FAIL at npm moderate threshold â€” `npm.cmd audit` exits 1 for 10 moderate development-tooling findings (GHSA-g3hv-75wg-m249). `npm.cmd audit --omit=dev` passes with 0 production dependency findings. Owner decision is pending; no breaking force fix applied. |
| Diff hygiene | PASS â€” `git diff --check`. |

The initial browser failure was traced to queued `exam.attempt_submitted` / `exam.attempt_expired` events being unrecognized by the shared outbox processor. The processor now validates the tenant-scoped formal attempt binding, session delivery and quiz relationship, then targets active instructors assigned to the delivered section within campus scope. It uses generic notification copy and existing idempotent notification keys. `QuizAssessmentWorkflowTest::test_formal_online_exam_binds_an_approved_paper_to_one_authoritative_attempt` now verifies submission/expiry notifications and duplicate-dispatch idempotency (1 test / 68 assertions).

## Historical implementation verification (superseded by the authoritative fresh rerun above) â€” 2026-11-05

This section supersedes prior counts only for checks listed here. The earlier full scanner-enabled 42-test Chromium run predates the final appeal history/transition UI and was not rerun as a full suite in this closure.

| Check | Result |
|---|---|
| Guarded Phase 13 MySQL | **25 passed / 655 assertions**; eight independent MySQL concurrency probes passed, including the exact-once submission outbox invariant; postflight 252 objects / 68 migrations / zero pending reviewed migrations; fingerprint `6b94ac80e063eeb06c0da0597f204cbd8ffbd1f379749fa10f8bb9fc629b8e7c`. |
| SQLite full suite | **327 passed / 4,874 assertions / 10 skipped**. |
| Focused examination-results Chromium | **1 passed** after appeal controls/history UI changes; six-theme automated axe/mobile checks are included for this screen. |
| Focused invigilator accept/decline feature | **1 passed / 47 assertions**, verifying transactional outbox events and authorized manager recipients. |
| PHPStan / Pint | **PASS**; PHPStan analyzed 556 files with no errors. |
| TypeScript / ESLint / Prettier | **PASS**. |
| Vitest | **24 passed**. |
| Composer validate / audit | **PASS**; no Composer advisories. |
| npm audit | **FAIL / owner disposition pending**: 10 moderate GHSA-g3hv-75wg-m249 advisories via `minimatch` in the ESLint/TypeScript-ESLint development chain. Breaking forced update not applied. |
| Production build | **PASS**, 685 modules transformed. |
| Full scanner-enabled Chromium | Prior result **42 passed**, but predates final appeal UI/history edits; not freshly rerun in this execution. |


## Final gate closure rerun - 2026-11-05

This addendum is the latest evidence. Prior 42/42 scanner-enabled browser results remain historical; the current run used the same 48-hour signature freshness policy and did not pass scanner-dependent journeys because the locally installed database was stale.

### Targeted ClamAV freshness investigation â€” 2026-11-05 08:30 UTC

| Check | Result | Evidence |
|---|---|---|
| Installed scanner | PASS / ENVIRONMENT INVENTORY | ClamAV, FreshClam, and sigtool are 1.5.4 from `C:\\Program Files\\ClamAV`. The application uses `clamscan --database=<configured-directory>` for each scan; no resident `clamd` process is used, so signature reload is not a daemon concern. |
| Active local databases | STALE UNDER APPLICATION POLICY | User-scoped directory `C:\\Users\\lenovo\\AppData\\Local\\ClamAV\\database`: `daily.cld` 28173, signed build 2026-11-03 06:27 UTC; `main.cvd` 63; `bytecode.cvd` 339. `sigtool --info` returned `Verification OK` for the three installed archives. At the verification clock of 2026-11-05 08:30 UTC, daily is about 50 hours old. |
| Timestamp source and comparison | PASS / IMPLEMENTATION MATCHES DATABASE | The scanner reads the daily signed archive metadata with sigtool, checks its version/build timestamp against `clamscan --version`, then applies the 48-hour age check to the daily build time. `clamscan --version` reports local Karachi time 2026-11-03 11:27:52, equivalent to 06:27:52 UTC; the signed daily build is 06:27 UTC. Configured Playwright scanner timezone is `Asia/Karachi`. |
| Local clock | REASONABLE CROSS-CHECK; SYNC SERVICE UNVERIFIED | Windows reported 2026-11-05 08:29 UTC during verification; the official host's HTTP Date was 08:30 UTC. `w32tm /query /status` reported the Windows Time service is not started, so synchronized-clock configuration is not established. |
| FreshClam configuration | PASS / TARGET CORRECT | `C:\\Users\\lenovo\\AppData\\Local\\ClamAV\\freshclam.conf` sets the official `database.clamav.net` mirror and the same user-scoped signature directory passed to the application. The Program Files default config is absent, but it is not the application's configured path. |
| Official update availability | AVAILABLE, DOWNLOAD BLOCKED | Official DNS `current.cvd.clamav.net` advertised `1.4.6:63:28174:1793865600:1:90:49192:339`, i.e. daily 28174 built 2026-11-05 08:00 UTC. FreshClam 1.5.4 recognized local 28173 / remote 28174 and attempted the official `daily-28174.cdiff`; its bundled curl reported `Could not resolve host`. Windows DNS resolution/TCP 443 succeeded. A non-downloading HTTPS HEAD diagnostic to the official database host returned HTTP 403 from Cloudflare. The local archive was not changed. |
| Mirror/network policy | BLOCKED / NO BYPASS ATTEMPTED | No alternate mirror, direct binary download, proxy/IP rotation, timestamp edit, unsigned fixture, or quarantine bypass was used. Cisco-Talos ClamAV issue #1901 reports another FreshClam 1.5.4 Cloudflare 403 on 2026-11-03. Official FreshClam FAQ says 403 can indicate a block or unsupported request and directs users to FreshClam or cvdupdate. Network/vendor owner must resolve the official CDN denial; retry through the approved updater after that. |
| 48-hour policy provenance | HOLD POLICY CONSTANT | The 48-hour maximum is the admissions configuration default and is explicitly fixed in `playwright.config.ts`; no separate security-owner approval record was found in the repository. No threshold change was made. |
| Scanner-enabled Chromium | NOT RERUN | The existing latest complete invocation remains 24 passed / 4 failed / 14 not run. Its four scan-dependent failures were incident evidence, exam script, learning media, and quiz file response. Persisted scanner results reported `signature_database_stale`; files remained quarantined. Do not classify isolated tests or older browser/EICAR evidence as a current full-suite pass. |

The current issue is an official distribution/CDN access blocker, not evidence that upstream has failed to publish a fresh signature set. The code's timestamp source and daily version match checks agree with the signed local data. When official download access is restored, update only via the approved updater, re-verify `sigtool --info` and `clamscan --version`, and run the full scanner-enabled Chromium suite plus real clean/EICAR checks.

| Check | Current result | Evidence |
|---|---|---|
| Guarded MySQL | PASS | `php tests/run-phase13-mysql.php --run`: **25 passed / 591 assertions**. Eight independent-session probes passed (16 worker sessions, distinct MySQL connection IDs, bounded barriers). Postflight: MySQL 8.4.11, 251 objects, 67 migrations, zero pending reviewed migrations, fingerprint unchanged `f227a6779e13e8d2c3771314b93c58ce82c2642a3b7943a1c4fab5c0a1e3bc99`. |
| Guarded MySQL read-only preflight | PASS | `php tests/run-phase13-mysql.php --preflight`: exact Phase 13 schema/account; 251 objects, 67 migrations, 0 pending. |
| Examination operations feature suite | PASS | `php artisan test tests/Feature/ExaminationOperationsTest.php`: **13 passed / 469 assertions**. Includes session-level reporting, protected CSV export, audit recording, and rejection of a member lacking analytics permission; date-sheet publication notification is recipient-scoped and idempotent. |
| Full SQLite suite | PASS WITH SKIPS | `php artisan test --compact`: **327 passed / 4,810 assertions / 10 skipped**. Skips remain environment-dependent MySQL/live-scanner checks. |
| Full scanner-enabled Chromium | FAIL / ENVIRONMENT BLOCKED | `E2E_CLAMAV_ENABLED=true; E2E_CLAMAV_BINARY=C:\\Program Files\\ClamAV\\clamscan.exe; E2E_CLAMAV_SIGNATURE_DIRECTORY=C:\\Users\\lenovo\\AppData\\Local\\ClamAV\\database; npm.cmd exec -- playwright test --project=chromium --trace=off`: **24 passed / 4 failed / 14 not run**. All four failures were scan-dependent: incident evidence, exam script, learning media and quiz file response did not become clean. Read-only inspection of the isolated E2E SQLite evidence showed `scan_status=unavailable`, `result_code=signature_database_stale`, scanner id `clamav/1.5.4/db-28173/20261103T062700Z`. Unscanned files stayed unavailable. |
| Targeted authenticated browser | PASS | `E2E_CLAMAV_ENABLED=false; npm.cmd exec -- playwright test tests/e2e/examination-results.spec.ts --project=chromium --trace=off`: **1 passed**. Exercises real scoped result/operations workspace and responsive/theme checks. |
| Real scanner signature freshness | FAIL / ENVIRONMENT | `clamscan --database=<user-scoped database> --version` reports database 28173 built Nov 3 06:27 UTC. `freshclam --config-file=storage/framework/phase12-freshclam.local.conf --stdout` reports the installed databases up-to-date; its 1.5.4 binary has no `--force` option. The application correctly rejects this over-age signature set under the 48-hour policy. No freshness threshold was weakened. |
| Frontend quality and production build | PASS | TypeScript, ESLint, Prettier, Vitest (**3 files / 24 tests**) and `npm.cmd run build` (**685 modules**) passed. |
| PHP quality | PASS | PHPStan; Pint; PHP syntax across **553 files** passed. |
| Composer | PASS | `composer validate --strict`; `composer audit`: no advisories. |
| npm audit | OPEN / DEV TOOLCHAIN | `npm.cmd audit --audit-level=moderate`: exit 1, **10 moderate** GHSA-g3hv-75wg-m249 dependency-chain findings. `--audit-level=high`: exit 0; no high/critical findings reported. `npm ls --omit=dev --depth=0` shows only Inertia, React and ReactDOM direct runtime packages. The available forced fix changes ESLint major; none applied. |
| Route and diff checks | PASS | `php artisan route:list --path=academics/examinations --except-vendor`: **58 routes**. `git diff --check`: exit 0. |

The signature freshness issue is an environment prerequisite, not a scanner bypass: FreshClam must obtain and verify a daily database whose signed build time is within policy, after which the four affected scanner journeys must be rerun. No new EICAR verdict is claimed in this run. Earlier clean/EICAR evidence is retained as historical only.

## Fresh continuation verification - 2026-11-05

All commands below were executed locally. MySQL was accessed only through the guarded Phase 13 runner. No `migrate:fresh`, `db:wipe`, unguarded reset or earlier-phase database operation was run.

| Check | Result | Evidence |
|---|---|---|
| Guarded MySQL read-only preflight | PASS | Fresh `php tests/run-phase13-mysql.php --preflight`: MySQL 8.4.11, target `techmentors_lms_phase13_test`, dedicated account verified, **251 objects / 67 migrations / 0 pending reviewed migrations**. |
| Guarded Phase 13 MySQL suite | PASS | `php tests/run-phase13-mysql.php --run`: **25 passed / 568 assertions**. |
| Independent-session MySQL concurrency | PASS | Eight probes / 16 worker sessions, every pair used different MySQL connection IDs and bounded barrier synchronization. Registration, seat allocation, paper publication, attempt start, autosave/finalization, script marking, result approval/amendment, and notification idempotency invariants passed. |
| MySQL postflight ownership | PASS | **251 objects / 67 migrations**; fingerprint unchanged: `f227a6779e13e8d2c3771314b93c58ce82c2642a3b7943a1c4fab5c0a1e3bc99`. |
| Full SQLite suite | PASS WITH SKIPS | Fresh `php artisan test --compact`: **327 passed / 4,787 assertions / 10 skipped**. Skips are environment-dependent live scanner or MySQL checks; they are not reported as passes. |
| Candidate governance feature tests | PASS | `php artisan test --filter='ExaminationCandidateGovernanceTest'`: **5 passed / 106 assertions**, including independent review, privacy, tenant isolation, quarantine, outbox recipients and unauthorized self-review. |
| Full scanner-enabled Chromium suite | PASS | `E2E_CLAMAV_ENABLED=true; E2E_CLAMAV_BINARY=...; E2E_CLAMAV_SIGNATURE_DIRECTORY=...; npm.cmd exec -- playwright test --project=chromium --trace=off`: **42 passed / 0 failed / 0 skipped**, 21.7 minutes. Real ClamAV was enabled. |
| Real incident evidence EICAR verification | PASS | The full Chromium run uploaded clean PDF evidence and the harmless EICAR test signature embedded in a PDF through the actual incident evidence route. Clean evidence became downloadable to an authorized reviewer; EICAR remained quarantined and unavailable. The EICAR content is a standard harmless antivirus test signature, not live malware. |
| Incident lifecycle browser journey | PASS | `tests/e2e/examination-results.spec.ts`: authenticated assignment, escalation, investigation, resolution, closure and reopening; six-theme axe/mobile checks passed. |
| Vitest | PASS | `npm.cmd run test:unit`: 3 files / 24 passed. |
| PHPStan / Pint / PHP syntax | PASS | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: no errors; `vendor/bin/pint --test`: passed; PHP syntax scan across app, routes, migrations and tests: **553 files passed**. |
| TypeScript / ESLint / Prettier | PASS | `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check`: passed. |
| Composer validation and audit | PASS | `composer validate --strict`; `composer audit`: no advisories. |
| npm audit | FAIL / OPEN | `npm.cmd audit`: exit 1, 10 moderate `minimatch` transitive development-toolchain advisories. `npm.cmd audit fix --dry-run` found no non-breaking fix; available force fix changes ESLint major. |
| Production build | PASS | Fresh `npm.cmd run build`: **685 modules transformed**. |
| Whitespace/diff | PASS | `git diff --check`: exit 0. |

The first full scanner-enabled browser invocation recorded 35 passed, 2 failed and 5 not run. One failure was a strict-mode test selector matching both the result-policy and incident session fields; it now scopes to the first intended selector. The other used an Asia/Karachi date for a tenant configured with UTC, so a local same-day transfer could be tomorrow to the application. The fixture now uses its configured UTC date. Both cases passed focused reruns, followed by the full 42/42 rerun above. These historical failures are retained here, not relabeled as earlier passes.

The 42/42 scanner-enabled Chromium rerun was completed after the incident lifecycle, EICAR route assertions, and outbox recipient changes. The guarded MySQL and full SQLite suites were also rerun after those changes. `npm audit` remains the only failing quality command; see the owner-approval risk proposal. The suite verifies automated axe checks on selected examination journeys, not every role-by-screen combination or human assistive-technology behavior.

## Final continuation run â€” 2026-11-04 (authoritative for this worktree)

This section supersedes earlier historical counts for checks rerun below. Earlier execution records remain as history; they are not retroactively changed.

| Command | Result | Evidence |
|---|---|---|
| `php tests/run-phase13-mysql.php --preflight` before run | PASS, read-only | MySQL 8.4.11; exact `techmentors_lms_phase13_test` schema and `techmentors_phase13_test@127.0.0.1`; 243 objects, 63 migrations, 0 pending reviewed migrations. |
| `php tests/run-phase13-mysql.php --run` | PASS | Guarded `Phase13GuardPolicyTest` and `ExaminationOperationsTest`: 19 passed / 438 assertions. All eight two-worker probes passed using 16 distinct MySQL sessions and bounded barriers. |
| Concurrent result-release notification delivery | PASS | Both release workers exercised `ExaminationResultService`; one logical outbox event and one release audit persisted. The production `QuizOutboxProcessor` delivered the event, and the only `exam.result_released` notification belonged to the eligible candidate, not the releasing controller. |
| MySQL postflight `php tests/run-phase13-mysql.php --preflight` | PASS, read-only | 243 objects, 63 migrations, 0 pending; ownership fingerprint unchanged at `5ff757363cdfe8fc677db9c16d0e0939ca1107c70e5273d0cb5558da59f8e30c`. |
| `php artisan test --compact` | PASS | SQLite: 320 passed / 4,654 assertions / 10 skipped; 115.89 seconds. |
| `npm.cmd run test:e2e -- --project=chromium --trace=off` with local ClamAV variables | PASS | Full scanner-enabled Chromium suite: 39 passed, 0 failed, 0 skipped; 14.2 minutes. Includes date-sheet revision across six themes, exam delivery, marking, results and existing clean/EICAR script journeys. |
| `npm.cmd run test:unit` | PASS | Vitest: 3 files / 24 tests. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | PASS | No static-analysis errors. |
| `vendor/bin/pint --test` | PASS | Repository PHP formatting passed. |
| PHP syntax scan | PASS | `php -l` passed for 557 PHP files under `app`, `routes`, `database`, `tests`, `bootstrap`, and `config`. |
| `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check` | PASS | TypeScript, ESLint, and Prettier passed. |
| `composer validate --strict`, `composer audit` | PASS | Composer manifest valid; no Composer advisories. |
| `npm.cmd audit` | FAIL / MODERATE FINDINGS | Exit 1: 12 moderate advisories in the ESLint/TypeScript-ESLint transitive `minimatch` toolchain. npm's suggested forced fix downgrades `typescript-eslint` incompatibly; no forced change was applied. |
| `npm.cmd run build` | PASS | Vite production build completed; 682 modules transformed. |
| `php artisan route:list --path=academics/examinations --except-vendor` | PASS | 43 examination routes listed. |
| `git diff --check` | PASS | Exit 0. |

The eight MySQL probe outcomes were: candidate registration `success/success` (one idempotent winner); seat allocation `conflict/success`; paper publication `conflict/success`; exam attempt start `success/success` (single authoritative attempt); autosave versus finalization `conflict/success`; script marking `success/conflict`; result approval versus amendment `conflict/success`; and result notification release `conflict/success`. Each pair ran on distinct connection IDs. Probe fixtures are created in the dedicated owned test schema and cleaned by the guarded harness; the competing operations call the production domain workflows. The notification probe additionally dispatches through the production outbox processor and asserts recipient identity.

This evidence does not close Phase 13: the remaining functional and role/accessibility coverage gaps are enumerated in `PHASE_13_REQUIREMENT_TRACEABILITY.md` and the current gate report. The original product roadmap/R&D documents were not found in the checkout; scope reconciliation uses the supplied Phase 13 prompt and available phase reports.

Updated 2026-11-03. Results below are observed in the current worktree; historical counts are not substituted for current checks.

| Command                                                                                                                            | Result                            | Evidence / limits                                                                                                                                                                                                                                                                  |
| ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php tests/run-tests.php --sqlite`                                                                                                 | PASS                              | 311 passed, 4,285 assertions, 10 skipped; 33.17s. Skips are environment-specific MySQL/real-scanner tests.                                                                                                                                                                         |
| Focused `ExaminationOperationsTest`                                                                                                | PASS                              | 5 passed / 120 assertions after the latest Phase 13 changes.                                                                                                                                                                                                                       |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`                                                                       | PASS                              | No errors.                                                                                                                                                                                                                                                                         |
| `vendor/bin/pint --test <changed Phase 13 PHP list>`                                                                               | PASS                              | Formatting passed for changed Phase 13 PHP.                                                                                                                                                                                                                                        |
| PHP syntax (`php -l`) on three examination controllers, two new migrations and focused feature test                                | PASS                              | All six files reported no syntax errors.                                                                                                                                                                                                                                           |
| `npm.cmd run typecheck`                                                                                                            | PASS                              | `tsc --noEmit` completed successfully.                                                                                                                                                                                                                                             |
| `npm.cmd run lint`                                                                                                                 | PASS                              | ESLint completed successfully.                                                                                                                                                                                                                                                     |
| `npm.cmd run test:unit`                                                                                                            | PASS                              | Vitest: 3 test files / 24 tests passed.                                                                                                                                                                                                                                            |
| `npm.cmd run format:check`                                                                                                         | PASS                              | Repository-configured Prettier check passed.                                                                                                                                                                                                                                       |
| `npm.cmd run test:e2e`                                                                                                             | PARTIAL / 33 PASS, 1 SKIP, 1 FAIL | Existing full Chromium suite ran 35 cases. The skip was scanner-dependent. The failure was Phase 12 quiz E2E waiting for a PATCH after intentionally not uploading a file-response artifact with ClamAV disabled.                                                                  |
| `npx.cmd playwright test tests/e2e/quiz-assessment.spec.ts --grep "instructor authors all supported question formats"`             | PASS                              | After adding an explicit scanner-disabled `continue`, the affected authenticated journey passed: 1 passed in 2.3m. The full 35-case suite was not rerun after this test-only fix.                                                                                                  |
| `npx.cmd prettier --check tests/e2e/quiz-assessment.spec.ts` / `npx.cmd eslint tests/e2e/quiz-assessment.spec.ts --max-warnings=0` | PASS                              | Updated E2E test formatting and lint passed.                                                                                                                                                                                                                                       |
| `npx.cmd prettier --check resources/js/Pages/workspace/academics/examinations/index.tsx resources/js/Layouts/workspace-layout.tsx` | PASS                              | Both files match formatting.                                                                                                                                                                                                                                                       |
| `npm.cmd run build`                                                                                                                | PASS                              | Vite 8.3.5 production build; 677 modules transformed. Examination page chunk 26.92 kB / 5.36 kB gzip.                                                                                                                                                                              |
| `composer validate --no-check-publish`                                                                                             | PASS                              | Validated earlier in this continuation.                                                                                                                                                                                                                                            |
| `composer audit`                                                                                                                   | PASS                              | No security advisories.                                                                                                                                                                                                                                                            |
| `npm.cmd audit --audit-level=high`                                                                                                 | PASS WITH MODERATE FINDINGS       | Exit 0 at high threshold; 12 moderate `minimatch` advisories in ESLint/TypeScript-ESLint dev tooling. No forced breaking update applied.                                                                                                                                           |
| `git diff --check`                                                                                                                 | PASS                              | No whitespace errors reported.                                                                                                                                                                                                                                                     |
| `php tests/run-phase13-mysql.php --preflight`                                                                                      | PASS                              | Latest read-only preflight: MySQL 8.4.11; database `techmentors_lms_phase13_test`; authenticated `techmentors_phase13_test@127.0.0.1`; exact grant policy passed; 0 objects / 0 migrations / 59 reviewed migrations pending after the Batch 2 cutoff update. No secrets displayed. |
| `php tests/run-phase13-mysql.php --run`                                                                                            | BLOCKED BEFORE DB CONTACT         | Exit 1: `Phase 13 independent-session concurrency probes are incomplete; no database was contacted.` Required `tests/Support/phase13-exam-concurrency.php` does not exist. No migration or schema change occurred.                                                                 |
| MySQL migrations / integration / concurrency                                                                                       | NOT RUN                           | Guarded runner refuses `--run` until the eight-probe harness is implemented; dedicated schema remains empty.                                                                                                                                                                       |
| Phase 13 Playwright journeys / six-theme matrix                                                                                    | NOT RUN                           | No Phase 13 authenticated browser suite or examination-specific accessibility matrix exists. The general suite's six-theme checks are not Phase 13 examination acceptance.                                                                                                         |
| Phase 13 ClamAV document scan                                                                                                      | NOT RUN                           | No Phase 13 scripts/document integration exists. Other phases' scanner evidence is not Phase 13 evidence.                                                                                                                                                                          |

The full SQLite run includes all existing phase tests and focused Phase 13 operations. It does not establish MySQL locking/grant behavior, Phase 13 browser acceptance, scanner integration, or manual assistive-technology compliance.

## Online exam delivery Batch 1 evidence (2026-11-04)

| Command                                                                             | Result                    | Evidence                                                                                                                                                                                                                                                                                      |
| ----------------------------------------------------------------------------------- | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan test --compact`                                                        | PASS                      | Full SQLite suite: 312 passed / 4,349 assertions / 10 skipped; exit 0.                                                                                                                                                                                                                        |
| `npx.cmd playwright test tests/e2e/examination-delivery.spec.ts --project=chromium` | PASS                      | 1 authenticated controller-to-student journey passed. Published delivery binding, eligible exam listing/start, exam metadata, axe scan, 390px reflow, server-acknowledged answer persistence through refresh, timer recovery, manual submission/receipt and wrong-student 404 were exercised. |
| `npm.cmd run test:unit`                                                             | PASS                      | Vitest: 3 files / 24 tests passed.                                                                                                                                                                                                                                                            |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`                        | PASS                      | No errors after adding explicit timestamp property types to the exam-attempt binding model.                                                                                                                                                                                                   |
| `vendor/bin/pint --test <changed Phase 13 PHP list>`                                | PASS                      | Changed PHP formatting passed.                                                                                                                                                                                                                                                                |
| `npm.cmd run typecheck`, targeted ESLint, `npm.cmd run format:check`                | PASS                      | TypeScript, selected changed TS/TSX/E2E lint, and repository Prettier check passed.                                                                                                                                                                                                           |
| `npm.cmd run build`                                                                 | PASS                      | Vite production build passed; 678 modules transformed.                                                                                                                                                                                                                                        |
| `composer audit`                                                                    | PASS                      | No Composer advisories.                                                                                                                                                                                                                                                                       |
| `npm.cmd audit --audit-level=high`                                                  | PASS AT HIGH THRESHOLD    | Exit 0; 12 moderate advisories remain in ESLint/TypeScript-ESLint `minimatch` development dependency chain.                                                                                                                                                                                   |
| `git diff --check`                                                                  | PASS                      | No whitespace errors.                                                                                                                                                                                                                                                                         |
| Guarded Phase 13 MySQL `--run`                                                      | BLOCKED BEFORE DB CONTACT | Runner requires `tests/Support/phase13-exam-concurrency.php`, which remains absent. No Phase 13 test schema migration or mutation was attempted.                                                                                                                                              |

The earlier full-browser results above are historical and remain separately labeled. This batch ran the exam-delivery spec only, not the full Chromium suite.

## Batch 2 and latest verification (2026-11-04)

| Command                                                                                                      | Result                                              | Evidence / limits                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan test --compact`                                                                                 | PASS                                                | Full SQLite suite: 314 passed / 4,449 assertions / 10 skipped; 33.16s.                                                                                                                                                                                                                                                                       |
| `php artisan test --compact tests/Feature/ExaminationOperationsTest.php --filter='practical_component        | answer_scripts'`                                    | PASS                                                                                                                                                                                                                                                                                                                                         | 2 tests / 100 assertions. Covers practical/oral setup, independent markings, explicit moderation, immutable result, denial for unassigned moderator, private script clean/infected/unavailable outcomes and unauthorized download.                                                |
| `npx.cmd playwright test tests/e2e/examination-marking.spec.ts --project=chromium --trace=off`               | PASS                                                | Authenticated controller creates and publishes component; assigns two markers and an explicit moderator; both mark; moderator finalizes; student sees completion without marks. Six theme selections, axe checks, reduced motion and mobile overflow checks. Passed in 18.5s in the full suite and separately in focused runs.               |
| `npm.cmd run test:e2e -- --project=chromium --trace=off`                                                     | INTERRUPTED BEFORE AGGREGATE                        | In the final fresh-fixture run, browser cases 1â€“35 completed: 34 passed and the one ClamAV-dependent upload case skipped. The runner was interrupted while case 36 was active; no full-run exit code or aggregate PASS was produced.                                                                                                         |
| `npx.cmd playwright test tests/e2e/quiz-assessment.spec.ts --project=chromium --grep='learner submits a quiz | scoped assessment analytics workspace' --trace=off` | PASS                                                                                                                                                                                                                                                                                                                                         | Follow-up covered the last two Chromium cases after the interrupted run: 2 passed in 1.7m. Thus all 37 cases were individually observed as 36 passed / 1 scanner-dependent skip across separate invocations; the full configured suite did not return one final aggregate result. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`                                                 | PASS                                                | No errors after Batch 2 and route-scope changes.                                                                                                                                                                                                                                                                                             |
| `vendor/bin/pint --test`                                                                                     | PASS                                                | Repository PHP formatting check passed after the route and permission changes.                                                                                                                                                                                                                                                               |
| `npm.cmd run typecheck` / `npm.cmd run lint` / `npm.cmd run format:check`                                    | PASS                                                | TypeScript, full ESLint and repository Prettier checks passed.                                                                                                                                                                                                                                                                               |
| PHP syntax checks                                                                                            | PASS                                                | `routes/web.php`, `ExamComponentWorkflow.php`, `ExamComponentController.php`, `PermissionCatalog.php`, Batch 2 migration and `ExaminationOperationsTest.php` all passed `php -l`.                                                                                                                                                            |
| `npm.cmd run build`                                                                                          | PASS                                                | Vite production build passed after Batch 2 frontend changes; 680 modules transformed and the marking/student component chunks were emitted. Subsequent edits affected backend routing, permissions and test fixtures only.                                                                                                                   |
| `npm.cmd run test:unit`                                                                                      | PASS                                                | Vitest: 3 files / 24 tests passed.                                                                                                                                                                                                                                                                                                           |
| `composer audit --locked`                                                                                    | PASS                                                | No security advisories.                                                                                                                                                                                                                                                                                                                      |
| `npm.cmd audit --audit-level=high`                                                                           | PASS AT HIGH THRESHOLD; MODERATE FINDINGS           | Exit 0 at configured high threshold; npm reported 12 moderate advisories in the ESLint/TypeScript-ESLint development dependency graph. No forced breaking dependency change was applied.                                                                                                                                                     |
| `php artisan route:list --name=academics.examinations.marking --except-vendor`                               | PASS                                                | Five marking/moderation routes listed with tenant academic scope/feature middleware; examiner routes are reachable with exam-specific permissions without broad `academic.view`.                                                                                                                                                             |
| `git diff --check`                                                                                           | PASS                                                | No whitespace errors.                                                                                                                                                                                                                                                                                                                        |
| `php tests/run-phase13-mysql.php --run`                                                                      | BLOCKED BEFORE DATABASE CONTACT                     | Process exit 1; runner reported independent-session concurrency probes are incomplete and no database was contacted. `tests/Support/phase13-exam-concurrency.php` is absent. No Phase 13 MySQL migration or mutation occurred.                                                                                                               |
| Live ClamAV Phase 13 script scan                                                                             | PASS, SCANNER-ENABLED BROWSER FLOW                  | The authenticated examination journey used the real Windows ClamAV adapter and current local signatures: a clean text script became downloadable to the assigned examiner; the EICAR test signature was rejected and stayed absent from the examiner's authorized-script list. Adapter identifier: `clamav/1.5.4/db-28173/20261103T062700Z`. |

At the time of this report section, the Phase 13 MySQL integration suite, eight independent-session probes, and live ClamAV verification had not run. The full Chromium command was interrupted and is therefore not recorded as a single-command pass despite the separate successful run of its remaining two cases. The scanner-enabled Batch 2 browser result is recorded in the dated follow-up below.

### Batch 2 cutoff follow-up (2026-11-04)

| Command                                                                                                        | Result          | Evidence / limits                                                                                                                            |
| -------------------------------------------------------------------------------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `php tests/run-phase13-mysql.php --preflight`                                                                  | PASS, READ ONLY | MySQL 8.4.11; exact dedicated Phase 13 database/account; 0 objects / 0 migrations; 59 reviewed migrations pending. No schema changes.        |
| `php artisan test --compact tests/Unit/Phase13GuardPolicyTest.php tests/Feature/ExaminationOperationsTest.php` | PASS            | 14 tests / 236 assertions, including guard that the explicit cutoff resolves to the current Batch 2 migration.                               |
| `npx.cmd playwright test tests/e2e/examination-marking.spec.ts --project=chromium --trace=off`                 | PASS            | 1 authenticated practical marking/moderation/student completion journey.                                                                     |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`                                                   | PASS            | No errors.                                                                                                                                   |
| `vendor/bin/pint --test`                                                                                       | PASS            | Formatting passed.                                                                                                                           |
| `git diff --check`                                                                                             | PASS            | No whitespace errors.                                                                                                                        |
| Guarded Phase 13 MySQL `--run`                                                                                 | NOT RUN         | Still refuses before database contact until `tests/Support/phase13-exam-concurrency.php` implements the required independent-session probes. |

Latest full regression after the cutoff-policy change: `php artisan test --compact` passed **315 tests / 4,452 assertions / 10 skips** in 85.43 seconds. The 10 skips remain environment-dependent scanner or MySQL cases.

The reviewed cutoff now ends at `2026_11_04_000000_create_exam_component_marking`, so the Phase 13 runner's eventual migration inventory includes the Batch 2 schema. This only updates the reviewed migration boundary; it does not authorize a MySQL migration or bypass the concurrency gate.

| Scanner-enabled practical marking journey | PASS | `E2E_CLAMAV_ENABLED=true npx.cmd playwright test tests/e2e/examination-marking.spec.ts --project=chromium --trace=off`: 1 passed in 2.1m. The flow uploaded clean and EICAR scripts through the real application endpoint, confirmed the clean-script download contents for its assigned examiner, persisted/reloaded rubric drafts, completed independent marking and moderation, and verified completion-only student visibility with six theme axe scans and mobile checks. |
| PHP scanner adapter real-file probe | PASS | Direct adapter run classified a harmless file `clean` and standard EICAR test signature `rejected`; temporary probe/test files were removed. Signatures met the configured 48-hour freshness policy. |
| Latest full SQLite suite | PASS | `php artisan test --compact`: 315 passed / 4,452 assertions / 10 skipped, 85.43s. |

## Batch 3 evidence update (2026-11-04)

This dated addendum supersedes earlier test counts for the checks rerun during the Batch 3 continuation; it does not supersede unresolved Phase 13 acceptance gates.

| Check | Result | Evidence |
|---|---|---|
| Focused result governance feature tests | PASS | `php artisan test tests/Feature/ExaminationOperationsTest.php`: 9 passed / 349 assertions. |
| Full SQLite regression | PASS | `php artisan test`: 317 passed / 4,581 assertions / 10 skipped. Skips are environment-dependent guarded MySQL or scanner cases. |
| Result governance browser journey | PASS | `npx.cmd playwright test tests/e2e/examination-results.spec.ts --project=chromium --timeout=60000`: 1 passed; authenticated controller and student views, responsive/mobile, keyboard and six-theme axe checks. |
| PHP static analysis | PASS | `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors. |
| PHP formatting / whitespace | PASS | Targeted Pint and `git diff --check` passed. |
| Frontend quality | PASS | `npm.cmd run typecheck`, `lint`, `format:check`, and `test:unit` (3 files / 24 tests) passed. |
| Production frontend build | PASS | `npm.cmd run build`: 682 modules transformed. |
| Dependency audits | PASS WITH MODERATE FINDINGS | `composer audit`: no advisories. `npm.cmd audit --audit-level=high`: exit 0 at high threshold; 12 moderate development-toolchain advisories remain. |
| Examination routes | PASS | `php artisan route:list --path=examinations`: 41 routes. |
| Guarded Phase 13 MySQL | NOT RUN | The runner remains intentionally blocked before database access until the eight independent-session concurrency harness exists. No MySQL schema was contacted or changed for this batch. |
| Full Chromium suite / live scanner | NOT RUN | Only the new result-governance journey was executed in this batch; the earlier scanner-enabled practical/oral evidence remains separately recorded. |

Batch 3 adds a versioned result policy and immutable result snapshot/history flow; deterministic aggregation of graded written attempts and approved practical/oral components; fail-closed pending states for missing, unreviewed or moderation-pending sources; independent approval and controlled release; student released-only results and scoped recheck requests; review/amendment history; incident review; safe outbox recipients; scoped CSV export; and a read-only Phase 14 grade-consumption contract. These are implemented vertical slices, not completion of all Phase 13 examination operations.


Batch 4 evidence: see [PHASE_13_BATCH_4_EVIDENCE.md](PHASE_13_BATCH_4_EVIDENCE.md) for the superseding verification results and remaining gate.
## Fresh continuation verification - 2026-11-04

- Guarded preflight: PASS. MySQL 8.4.11; dedicated techmentors_lms_phase13_test and techmentors_phase13_test@127.0.0.1; 245 objects, 64 migrations, zero pending.
- Guarded MySQL --run: PASS; 20 tests / 462 assertions. Eight two-worker probes passed using 16 worker sessions, distinct MySQL connection IDs and bounded synchronization. Outcomes: registration success/success; seat allocation success/conflict; paper publication conflict/success; attempt start success/success; autosave/finalization conflict/success; script marking success/conflict; result approval/amendment success/conflict; notification idempotency success/conflict.
- Postflight preflight: PASS; 245 objects / 64 migrations / zero pending. Ownership fingerprint unchanged: 5333799e38b8b4cd81d53808ade7545d22fc8c1d619114e305b5e57092df51a5.
- Full SQLite: 322 passed, 10 skipped, 4,681 assertions.
- Focused examination and timetable tests: 17 passed / 645 assertions.
- Full scanner-enabled Chromium: 40 passed. The seating and invigilator journeys covered review/publication, mobile layout, all six themes and automated axe checks.
- Vitest: 24 passed across 3 files.
- PHPStan with 1 GiB memory: PASS, no errors. The default memory limit was insufficient once.
- Pint, TypeScript, ESLint, Prettier, PHP syntax scan, Composer validation, Composer audit, production build and git diff --check: PASS.
- Composer audit: no advisories.
- npm audit: FAIL / OPEN; 12 moderate dev-toolchain advisories, zero high/critical. See unapproved proposal.

## 2026-11-05 urgent recovery evidence

See [URGENT_RECOVERY_ADDENDUM_2026-11-05.md](URGENT_RECOVERY_ADDENDUM_2026-11-05.md) for the fresh ClamAV signature verification, full scanner-enabled Chromium result (42 passed), repaired E2E timeout/theme synchronization, current quality checks, and remaining Phase 13 gate classification. Historical results above remain historical where superseded; this addendum is the current source for the urgent recovery outcome.



## Current final gap-closure evidence â€” 2026-11-05
See [FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md](FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md) for the fresh scanner, guarded MySQL, SQLite, browser, reporting, quality-check evidence and exact remaining acceptance gaps. This addendum is authoritative for the current local verdict; historical results above are retained as historical evidence.



# Fresh route authorization verification — 2026-11-05

- `php artisan test --filter=Phase13ExaminationRouteAuthorizationMatrixTest`: **1 passed / 297 assertions**.
- The test derives tenant examination and appeal routes from Laravel's live route collection and asserts the reviewed inventory is 73 routes. It sends 292 real HTTP-kernel requests: guest, active member with no roles, member authenticated to a different tenant, and revoked member for each route. Denials are 302 for guest and 403/404 for authenticated unauthorized cases.
- Mutation sentinels for `exam_cycles`, `exam_candidates`, and `audit_events` were unchanged across the unauthorized requests.
- Guarded MySQL `php tests/run-phase13-mysql.php --preflight`: **PASS**, MySQL 8.4.11, 253 objects, 69 migrations, zero pending.
- Guarded MySQL `php tests/run-phase13-mysql.php --run`: **32 passed / 1 scanner-gated skip / 1,340 assertions**; all eight concurrent workflow probes passed, 16 worker sessions. Database fingerprint unchanged.
- `php artisan test --compact`: **328 passed / 5,246 assertions / 10 skipped**.
- The matrix does **not** assert positive authorized actions for every route or wrong-campus/foreign-record ownership for every resource route. Those are tracked as separate unresolved evidence requirements; this sweep is not represented as completing those cells.

## Final rerun evidence — 2026-11-05

- `php tests/run-phase13-mysql.php --run`: **32 passed / 1 scanner-gated skip / 1,340 assertions**. Eight independent MySQL concurrency probes passed using 16 worker sessions. Postflight remains 253 objects / 69 migrations / 0 pending, fingerprint `dea00d4011c05e37bbc229a803a7a5a16bc0ca82178ae5d65fc62e4e65640e7c`.
- `php artisan test --compact`: **328 passed / 5,246 assertions / 10 skipped**.
- Full scanner-enabled `npm.cmd run test:e2e -- --project=chromium`: **42 passed / 0 failed / 0 skipped**. Focused post-run examiner evidence test: **1 passed**, generating six screenshots under `test-results/examination-marking-contro-9e3e9-tudent-sees-completion-only-chromium/` (one per theme).
- `npm.cmd run test:unit -- --run`: **24 passed**. `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check`, `composer analyse`, `vendor/bin/pint --test`, `composer validate --no-check-publish`, `composer audit`, production `npm.cmd audit --omit=dev`, Vite production build, PHP syntax checks, and `git diff --check`: **PASS**.
- Full `npm.cmd audit`: **exit 1**, 10 moderate development-only findings, 0 high/critical. The proposed fix requires a breaking ESLint major update. The owner disposition is unsigned and not self-approved.
- The route matrix has verified the four tested denial classes for each route but remains **partial** for successful role/action coverage, campus-scoped access, and resource-owner/foreign-resource checks. Manual assistive-technology and hosted CI evidence were not executed.

## One-shot closure rerun — 2026-11-05 (authoritative latest evidence)

| Check | Actual result |
|---|---|
| Route authorization | 1 focused test / 1,542 assertions. Live 73 routes; 365 denied HTTP requests across guest, no-role member, unauthorized parent.guardian, foreign-tenant member, and revoked member. Mutation sentinels unchanged. Positive route actions and per-resource campus/ownership cells remain partial. |
| Guarded MySQL | 32 passed / 1 scanner-gated skip / 2,585 assertions. Eight independent-session concurrency probes passed / 16 worker sessions. MySQL 8.4.11; 253 objects / 69 migrations / zero pending; ownership fingerprint unchanged: dea00d4011c05e37bbc229a803a7a5a16bc0ca82178ae5d65fc62e4e65640e7c. |
| SQLite | 328 passed / 6,491 assertions / 10 skipped. |
| Scanner-enabled Chromium | 42 passed / 0 failed / 0 skipped; completed after the final build, without concurrent asset changes. |
| Vitest | 24 passed (3 files). |
| PHPStan / Pint / PHP syntax | Passed; syntax checked across 490 files. |
| TypeScript / ESLint / Prettier | Passed. |
| Composer validate / audit | Passed; no Composer advisories. |
| npm audit | Full audit exit 1: 10 moderate development-tooling findings. npm audit --omit=dev: passed, zero findings. |
| Production build | Passed; Vite 8.3.5, 685 modules. |
| Diff hygiene | git diff --check passed. |

Earlier rows below are preserved as historical evidence and superseded by this section where checks overlap. Full local acceptance remains blocked by the uncompleted positive/campus/resource authorization matrix and exhaustive role-screen-theme matrix. The owner npm risk disposition remains unsigned.

# 2026-11-06 fresh verification addendum

- Guarded Phase 13 MySQL: **35 passed / 1 scanner-gated skip / 3,117 assertions**; preflight and postflight passed on MySQL 8.4.11, 253 schema objects, 69 migrations, zero pending reviewed migrations, unchanged ownership fingerprint. Eight independent-session probes passed with 16 distinct worker sessions.
- SQLite with ClamAV configured: **337 passed / 4 skipped / 7,142 assertions**.
- Full scanner-enabled Chromium: **43 passed / 0 failed / 0 skipped**. The focused results and role/theme specs also passed (2/2); role/screen/theme matrix is 78/78.
- Route denial matrix: 73 routes and 365 actual denial requests passed. Positive route-by-route matrix remains 0/73 manifest-bound; wrong-campus and foreign-owner evidence remains partial.
- PHPStan, Pint, 561-file PHP syntax check, TypeScript, ESLint, Prettier, Vitest (24), Composer validation/audit, production npm audit (0), Vite build (685 modules), and `git diff --check` passed.
- Full npm audit remains exit 1 with 10 moderate development-tool advisories; no forced upgrade was applied. See [final acceptance addendum](FINAL_ACCEPTANCE_EXECUTOR_2026-11-06.md).

## Fresh verification addendum — 2026-11-06
See [PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md](PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md) for fresh guarded MySQL, SQLite, Chromium, scanner, static-check and authorization evidence. The current route authorization gate remains blocked: 73 workflow references are linked, but systematic positive route probes are 0/73; 70 campus-applicability rows and 61 applicable foreign-resource rows remain unverified. Do not infer completion from linked workflow evidence.

## Fresh continuation evidence — 2026-11-06

php tests/run-tests.php --sqlite --filter=Phase13ExaminationRouteAuthorizationMatrixTest passed: 2 tests / 2,138 assertions, exit 0. The denial sweep matches the 73-route inventory; this run does not close the positive action, campus/resource, or full role-screen-theme matrix. The full SQLite regression including Phase 13 passed 335 tests / 7,269 assertions with 10 skips. Shared Chromium completed 40 passed / 2 scanner-dependent failed / 1 skipped; do not treat it as a clean Phase 13 browser regression. No Phase 13 database was accessed.
