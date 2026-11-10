# Phase 06 test and verification report

Verification date: 2026-10-26. Local environment: Windows 11 workstation, Intel Core i7-12700H, 14 cores/20 logical processors, 15.7 GiB RAM; PHP 8.3.35 CLI, Laravel 13.38.0, MySQL 8.4.11, Node 24.21.0, npm 11.19.0, Composer 2.10.3. Results are local and are not hosted CI or production performance evidence.

## Independent regression rerun after the Phase 07 vertical slice

- `php tests/run-tests.php --mysql-phase06`: exit 0; **202 passed, 0 failed, 4 Phase 07-only skipped, 2,158 assertions**, 188.38 seconds.
- `php tests/run-tests.php --preflight-phase06`: exit 0; read-only identity/ownership check passed for `techmentors_lms_phase06_test`, authenticated account `techmentors_phase06_test@127.0.0.1`, MySQL 8.4.11, 122 objects and 34 migration rows. No manifest or schema/data was changed.
- `php tests/run-tests.php --sqlite`: exit 0; **200 passed, 0 failed, 6 skipped, 2,180 assertions**, 22.51 seconds. Skips are database-engine-specific MySQL metadata/locking or guarded ClamAV cases.
- `npm.cmd run test:e2e` with the local ClamAV variables loaded privately from the ignored Phase 04 V2 configuration: exit 0; **24 passed, 0 skipped**, Chromium, about 7.2 minutes. The real clean-scanned transfer-certificate journey passed and rejected an unsupported executable upload. The Phase 07 course-delivery authoring journey also passed; it stops at lesson submission for review.
- `npm.cmd run test:unit`: exit 0; Vitest **6 passed**.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`, `php vendor/bin/pint --test`, PHP syntax loop (323 files), TypeScript, ESLint, Prettier, Composer validation/platform/audit, npm audit, production build, and `git diff --check`: all exit 0.
- `php artisan route:list --path=learning --except-vendor`: exit 0; 16 tenant learning routes listed.
- `php tests/run-tests.php --preflight-phase07`: exit 2 with SQLSTATE HY000 / driver 1045. The private target configuration exists, but authentication fails before identity or schema checks. No Phase 07 MySQL operation beyond the read-only connection attempt was performed.

## Database safety and ownership

| Command | Result |
|---|---|
| php tests/run-tests.php --inspect-phase06 | PASS, exit 0; read-only identity/schema inspection. |
| php tests/run-tests.php --preflight-phase06 | PASS, exit 0; exact dedicated database/account/server/grant/ownership checks. |
| Final target | techmentors_lms_phase06_test; authenticated account techmentors_phase06_test@127.0.0.1; MySQL 8.4.11; 122 schema objects; 34 migration rows. |
| Ownership state | Valid manifest; no differing fields; zero auxiliary objects; stable fingerprint 6b6d91eec40727ce49a8265cdc5084f849a17ad84b47c68110660f3cd5aab6f9. |
| Residual application fixtures | None. Nonempty baseline tables are migrations (34), permissions (64), and platform_bootstrap_states (1). |
| Existing databases | Development, Phase 04/05, and unrelated databases were not targeted. No destructive reset was used. |

## Backend test results from original Phase 06 acceptance (historical)

- php tests/run-tests.php --mysql-phase06: **197 passed / 0 failed / 2,153 assertions**, 90.81 seconds. The guarded run covered the full project backend suite on the dedicated MySQL 8.4 schema.
- php tests/run-tests.php --sqlite: **191 passed / 0 failed / 6 skipped / 2,065 assertions**, 14.45 seconds; command exit 0.
- The six SQLite skips are intentional: MySQL information_schema metadata, MySQL row-lock serialization, simultaneous MySQL capacity-one offers, the Phase 06 MySQL curriculum race, and the admissions/SIS real ClamAV cases that require the guarded MySQL scanner environment. Corresponding MySQL locking/schema/scanner cases passed.
- php tests/run-tests.php --mysql-phase06 --filter=mysql_concurrent_curriculum_edits_approval_and_publication_are_serialized: PASS, exit 0, 1 test/11 assertions.
- php tests/run-tests.php --mysql-phase06 --filter=competency_framework_requires_independent_review_and_revision_preserves_published_snapshot: PASS, exit 0, 1 test/24 assertions.
- php tests/run-tests.php --sqlite --filter=curriculum_detail_keeps_query_count_bounded_for_a_realistic_syllabus: PASS, exit 0, 1 test/14 assertions.
- Database preflight after the full MySQL run: PASS, exit 0; ownership state remained valid and unchanged.

The MySQL feature suite includes guardian schema constraints, transaction/foreign-key behavior, concurrent admissions capacity/row locks, curriculum concurrent edit/approval/publication, program competency evidence/gaps, private document quarantine/scanning, real ClamAV clean/EICAR paths and tenant isolation.

## Browser and frontend results from original Phase 06 acceptance (historical)

- npm.cmd run test:e2e: scanner-enabled Playwright Chromium, **23 passed / 0 skipped**, approximately 3.0 minutes. The run used only scanner configuration from the ignored Phase 06 environment; Playwright's browser database was a unique guarded SQLite file.
- npm.cmd run test:unit: Vitest **6 passed**, exit 0.
- Scanner journey: a clean transfer certificate remained in quarantine until a fresh ClamAV clean result, then authorized download succeeded; an unsupported executable upload was rejected.
- Browser tests also covered shell/assets/health, authenticated provisioning, admissions and guardian verification/revocation, SIS handoff, curriculum authoring/review/publish, themes, responsive navigation, keyboard accessibility and selected axe checks.

## Static analysis, build and dependency audits

| Command | Result |
|---|---|
| composer validate --strict | PASS, exit 0. |
| composer check-platform-reqs | PASS, exit 0; PHP 8.3.35 requirements satisfied. |
| composer analyse | PASS, PHPStan reports no errors. |
| vendor/bin/pint --test | PASS. |
| PHP syntax loop across app, migrations, routes and tests | PASS, 289 files at original acceptance; latest independent rerun covered 323 files (see above). |
| npm.cmd run typecheck | PASS, TypeScript noEmit. |
| npm.cmd run lint | PASS, ESLint with zero warnings. |
| npm.cmd run format:check | PASS, Prettier. |
| npm.cmd audit --audit-level=high | PASS, zero vulnerabilities. |
| composer audit | PASS, no advisories. |
| npm.cmd run build | PASS, Vite 8.3.5 transformed 633 modules at original acceptance; latest build transformed 636 modules (see current regression evidence above). |
| php artisan route:list --path=academics/curriculum --json | PASS, 55 routes. |
| git diff --check | PASS. |

The PowerShell npm.ps1 shim was blocked by the machine's execution policy. The equivalent installed npm.cmd commands all ran successfully; machine policy was not changed.

## Scanner evidence

The ignored .env.phase06.testing supplies the private scanner configuration. It was confirmed Git-ignored and untracked without printing its contents. MySQL integration exercised clean-file release and EICAR rejection; unavailable, stale/missing-signature, timeout, process-failure and unsupported-file behaviors remain fail-closed. The scanner-enabled Playwright run exercised an actual clean document upload and authorized download. No scanner credentials or environment values were included in output or reports.

## Previous failure and repair

The initial full MySQL run reported 195 passed / 1 failed / 2,121 assertions. Read-only inspection showed the target database was stable and owned, with no residual fixtures. Root cause was incomplete authorization in the test helper: child users had no active tenant memberships/roles. The fixture now creates the scoped memberships and a temporary role with only required curriculum permissions and verifies cleanup. The focused race then passed 1/11, and the original full acceptance suite passed 197/2,153. The newer regression results are recorded at the top of this report and supersede those counts for the current worktree.

The test helper emits only safe stage/class/code diagnostics on future failures, not exception messages, SQL, secrets or user payloads.
