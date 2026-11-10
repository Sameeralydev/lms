# Phase 04 test report — recovery rerun

## Final security integration and regression results (2026-10-23)

The following commands were executed against the isolated Phase 04 V2 test schema or local test environment. All exited 0 unless a count includes intentional MySQL-only skips:

This final results section supersedes all earlier test-status summaries below; those entries are retained as execution history.

| Command | Actual result |
|---|---|
| `php tests/run-tests.php --preflight-phase04-v2` | Exit 0; read-only preflight verified `techmentors_lms_phase04_v2_test`, `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11, 80 objects and 14 migration records; ownership/grant checks passed. |
| `php tests/run-tests.php --mysql-phase04-v2` | 122 passed / 934 assertions; exit 0; guarded migration lifecycle and postflight passed. |
| Guardian schema FK/index validation within the guarded MySQL test evidence | 1 passed / 21 assertions; tenant/application/user foreign keys, uniqueness and scoped indexes verified. |
| `php tests/run-tests.php --sqlite` | 119 passed, 3 skipped (MySQL-specific) / 893 assertions; exit 0. |
| `php tests/run-tests.php --mysql-phase04-v2 --filter=test_real_clamav_scans_private_admission_uploads_and_only_releases_clean_files` | 1 passed / 30 assertions; real clean and EICAR scan workflow. |
| `php tests/run-tests.php --mysql-phase04-v2 --filter=test_mysql_simultaneous_offer_issuance_preserves_capacity_one` | 1 passed / 8 assertions; actual concurrent offer calls preserved capacity one. |
| `php vendor/bin/phpunit tests/Unit/ClamAvSignatureMetadataTest.php` | 11 passed / 50 assertions; exit 0. |
| `npm.cmd run test:unit` | 6 passed. |
| `npm.cmd run test:e2e` | Playwright Chromium 20 passed. |
| `npm.cmd run typecheck`; `npm.cmd run lint`; `npm.cmd run format:check`; `npm.cmd run build` | All passed; production Vite build completed. |
| `composer.bat validate --strict`, `composer.bat check-platform-reqs`, `composer.bat audit` | All passed; no Composer advisories. |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`; `php vendor/bin/pint --test` | Both passed. |
| `npm.cmd audit --audit-level=high` | Passed; 0 reported vulnerabilities. |
| PowerShell `php -l` scan | 205 project PHP files checked (including `public` and `artisan`); 0 syntax errors. |

Scanner metadata observed during this verification: engine 1.5.4; main database 63, daily database 28162, bytecode database 339. `sigtool --info` verified each archive. The daily archive timestamp/version matched ClamAV CLI metadata and was within the configured 48-hour maximum at test time. No scanner output or credentials were written to reports. Frontend sources were unchanged after the frontend quality commands completed.

> **Historical execution note:** earlier entries describe the V1 test-schema grant and ownership investigation. The current V2 database, manifest, migration, and test status is in the section immediately below.

## Historical V2 recovery status before final scanner and capacity-race verification (2026-10-23)

The V2 isolated test schema has now passed the guarded MySQL migration and integration gate. `php tests/run-tests.php --mysql-phase04-v2` exited 0 with **113 passed / 864 assertions**; the final read-only preflight confirmed `techmentors_lms_phase04_v2_test`, `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11, 80 schema objects and all 14 local migrations. The exact 11-privilege schema grant policy passed. The separate V2 ownership manifest was advanced only after verified postflight; the V1 manifest was preserved.

The MySQL guardian schema verification passed 1 test / 21 assertions after replacing MySQL-overlong generated FK names with explicit short names. The final lock serialization test passed 1 / 12 assertions using a second PDO process and a local readiness marker; the capacity-one service test independently confirmed only one of two approved applications gets a reservation. This does not constitute an end-to-end race between two simultaneous `issueOffer` calls.

Latest SQLite suite: 111 passed, 2 MySQL-only skipped, 831 assertions. Vitest: 6 passed. Playwright Chromium: 20 passed. PHPStan, Pint, TypeScript, ESLint, Prettier, Composer platform/validation/audit, npm audit, 202-file PHP syntax scan, and the 615-module Vite production build passed. See `V2_MYSQL_RECOVERY_REPORT.md` for commands and details. Real ClamAV scanning remains blocked: no scanner or signatures are installed; files remain quarantined.

## Latest ownership recovery addendum (2026-10-23)

This addendum supersedes the earlier statement above that MySQL is blocked by `ALL PRIVILEGES` or missing credentials. Read-only verification now confirms the dedicated account and database identity, exact 11-privilege schema allowlist, and MySQL 8.4.11. The MySQL test suite remains **not run** because the ownership manifest records an empty schema while the database has 80 objects/13 migration rows; migration 000010 is absent from the ledger and its guardian table's DDL does not match the local migration. The direct read-only preflight refused before tests or Artisan commands. No MySQL migration/reset/write occurred.

New focused regression files pass together: `php vendor/bin/phpunit tests/Unit/Phase04TestSafetyLifecycleTest.php tests/Unit/Phase04MySqlGrantPolicyTest.php tests/Unit/ClamAvSignatureMetadataTest.php` — **20 tests / 45 assertions, exit 0**. `php tests/run-tests.php --sqlite` passed **105 tests / 797 assertions, exit 0**. Full `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` and `php vendor/bin/pint --test` passed. These results do not replace MySQL integration verification. ClamAV metadata freshness parsing is unit-tested; no ClamAV executable or real signature scan is available.

## 2026-10-23 final regression results

| Command | Result |
|---|---|
| `php tests/run-tests.php --sqlite` | PASS — 92 tests, 764 assertions, exit 0 |
| `php tests/run-tests.php --sqlite --filter=AdmissionsFoundationTest` | PASS — 14 tests, 202 assertions, exit 0 |
| `php tests/run-tests.php --sqlite --filter=Phase04MySqlGrantPolicyTest` | PASS — 7 tests, 12 assertions, exit 0; covers quoted MySQL 8.4 grants, allowed localhost/loopback formatting, and rejects excessive/missing/wrong-scope grants |
| `php tests/run-tests.php --mysql-phase04` | BLOCKED safely — exit 2 because the authenticated account has `ALL PRIVILEGES` on the dedicated schema; no migration or MySQL test started |
| `composer.bat validate --strict` | PASS — exit 0 |
| `composer.bat check-platform-reqs` | PASS — exit 0 |
| `composer.bat analyse` | PASS — PHPStan no errors, exit 0 |
| `composer.bat format:check` | PASS — Pint, exit 0 |
| Recursive `php -l` across `app`, `database/migrations`, `tests`, and `tools` | PASS — 170 PHP files, 0 syntax failures |
| `composer.bat audit` | PASS — no advisories, exit 0 |
| `npm.cmd run test:unit` | PASS — 6 tests, exit 0 |
| `npm.cmd run typecheck` / `npm.cmd run lint` / `npm.cmd run format:check` | PASS — each exit 0 |
| `npm.cmd run build` | PASS — production Vite build, exit 0 |
| `npm.cmd audit --audit-level=high` | PASS — 0 vulnerabilities, exit 0 |
| `npm.cmd run test:e2e` | PASS — 20 Playwright Chromium tests, 0 failures, exit 0 |
| `git diff --check` | PASS — exit 0 |

The Playwright run used the supported PHP 8.3 Laravel development server and isolated marker-checked SQLite E2E database. It exercised guardian invitation/acceptance, institutional relationship verification, scoped read-only access and revocation; independent admission recommendation/approval; offer issuance/acceptance; SIS handoff queueing; tenant denial; responsive and accessibility smoke paths. It is not MySQL evidence. Hosted CI was not executed.

### MySQL read-only preflight (2026-10-23)

Using private ignored `.env.phase04.testing` configuration without printing values, read-only PDO checks returned `DATABASE() = techmentors_lms_phase04_test`, `CURRENT_USER() = techmentors_phase04_test@127.0.0.1`, and MySQL 8.4.11. The schema had zero objects. `SHOW GRANTS` showed global `USAGE` and schema-scoped `ALL PRIVILEGES`; no grant option was present. `information_schema.SCHEMA_PRIVILEGES` returned 18 non-delegable schema privileges, seven beyond the documented allowlist: `ALTER ROUTINE`, `CREATE ROUTINE`, `CREATE VIEW`, `EVENT`, `EXECUTE`, `SHOW VIEW`, and `TRIGGER`.

The guarded runner exited 2 on the grant check before creating its ownership manifest or running migrations. No database writes were made. This is an actual privilege overgrant for the authenticated `127.0.0.1` account; the grant parser regression passes on valid and invalid grant formats. Do not weaken the policy.

### Seat-capacity evidence

The new sequential capacity regression passed within the focused admissions run: for capacity 1 and two approved applications, one offer/seat is reserved and the other offer attempt is refused. The workflow uses transaction/row locks, but production-MySQL lock and concurrent-request behavior remain unverified until the guarded MySQL suite can run.

**Run date:** 2026-10-22 (local project environment)

**Runtime:** Windows PowerShell, Laravel 13.38.0, PHP CLI 8.3.35, Composer 2.10.3, Node 24.21.0, npm 11.19.0. Browser checks used Chromium, the Laravel PHP development server, built Vite assets, and the isolated marker-checked SQLite E2E database. XAMPP Apache was not used or changed.

## Results

| Check | Result | Evidence |
|---|---|---|
| Guarded backend suite | **PASS** | `php tests/run-tests.php --sqlite`: 84 tests / 747 assertions, exit 0 |
| Focused admissions suite | **PASS** | `php tests/run-tests.php --sqlite --filter=AdmissionsFoundationTest`: 13 tests / 197 assertions, exit 0 |
| MySQL Phase 04 suite | **BLOCKED, safely refused** | `php tests/run-tests.php --mysql-phase04`: exit 2; requires regular ignored `.env.phase04.testing`; runner exited before connecting. No database identity query or migration occurred |
| Vitest | **PASS** | `npm.cmd run test:unit`: 6 tests passed, 1 file |
| Playwright Chromium | **PASS** | `npm.cmd run test:e2e`: 20 passed, 0 failed. Includes public shell, accessibility/responsive checks, intake and form builder, guardian invitation and relationship verification, minor submission gating, read-only guardian access, guardian revocation, separated staff recommendation/approval, offer acceptance, SIS handoff queueing, and tenant-boundary checks |
| PHPStan | **PASS** | `composer.bat analyse`: no errors |
| PHP syntax | **PASS** | `php -l` recursively across 170 PHP files under `app`, `database/migrations`, `tests`, and `tools`; 0 failures |
| Pint | **PASS** | `composer.bat format:check` |
| TypeScript | **PASS** | `npm.cmd run typecheck` |
| ESLint | **PASS** | `npm.cmd run lint`, zero warnings |
| Prettier | **PASS** | `npm.cmd run format:check` |
| Composer validation | **PASS** | `composer.bat validate --strict` |
| Composer platform | **PASS** | `composer.bat check-platform-reqs`; PHP 8.3.35 and installed extensions satisfy requirements |
| Composer audit | **PASS** | `composer.bat audit`: no advisories |
| npm audit | **PASS** | `npm.cmd audit --audit-level=high`: 0 vulnerabilities |
| Production frontend build | **PASS** | `npm.cmd run build`: Vite 8.3.5 transformed 615 modules |
| Admissions route inspection | **PASS** | `php artisan route:list --except-vendor --path=admissions`: 40 routes |
| Diff whitespace check | **PASS** | `git diff --check`: exit 0 |
| Hosted CI | **NOT RUN** | No hosted runner execution was available as evidence |

## Browser journey coverage

The real HTTP browser run verified a minor applicant can open a published intake and save a draft; the server rejects submission until a separate verified guardian accepts the scoped invitation and institution staff records relationship verification. The applicant then submits, the guardian has read-only access, cross-tenant access returns 404, and revocation removes access. Staff later reviews the submitted application, submits a recommendation, approves it with a different authorized account, issues an offer, records acceptance, and queues a pending SIS handoff. The handoff remains a Phase 05 snapshot only; this run created no student record. The E2E guardian account is a synthetic fixture marked email-verified in the isolated SQLite setup; external email/identity delivery is not verified.

The suite also exercised visual form editing and publication, public intake rendering, inquiries, keyboard navigation, narrow viewport rendering, axe smoke checks, theme persistence, health endpoint behavior, host-header rejection, and tenant-boundary denial. Guardian verification and document scanning remain covered by backend tests and fail-closed behavior; those two workflows were not exercised end-to-end in Chromium.

## Regression and repair notes

The final SQLite suite has 84 tests / 747 assertions. The final focused admissions suite has 13 / 197. The 20-test Playwright suite now exercises the full guardian authority and revocation path as well as the staff lifecycle. It exposed and led to correction of a route/controller parameter mismatch, test selectors that matched combined status text incorrectly, and persistent browser-test rate-limit counters across disposable SQLite resets. The guardian invitation URL is exposed only in testing when E2E mode, array mail, and a loopback requester are all confirmed, and the response is marked private/no-store. Playwright uses a per-process file-cache directory to retain rate limiting within a run without carrying state between runs. The final SQLite suite, PHPStan/Pint, type/lint/format checks, and full Chromium suite passed after repairs.

## Database isolation

The ignored `.env.phase04.testing` file is absent; `git check-ignore .env.phase04.testing` confirms the target path is ignored. The tracked `.env.phase04.testing.example` had a modified, non-placeholder password line. I replaced only that line with a placeholder without displaying or copying its prior value, confirmed its other configuration lines matched `HEAD`, then restored the tracked example to its repository version. The example is now clean and is not a private test config. The guarded MySQL runner refused before opening a connection. Therefore the test schema identity, account authentication, scoped grants, pre-existing contents, migration inventory, MySQL migrations, and MySQL integration behavior are **not verified**. No MySQL migration or destructive command was run during this rerun. The SQLite suite and E2E database are isolated from MySQL; the former Phase 01–03 test database and development database were not used by the runner.

The previously recorded read-only MySQL authentication probe returned error 1045. It is not evidence that the new test account or database are correctly configured. The test password must be rotated before a new private configuration is supplied because its earlier exposure is unresolved.

## Scanner and mail limits

No `clamscan` or `clamdscan` executable is installed. The default scan adapter stays unavailable, private uploads remain quarantined, and file access/review remains denied unless the current file version has a clean scan result. No files were scanned by a real malware scanner. Local test delivery does not establish a production email provider or retrying delivery service.

## 2026-10-22 grant-safety follow-up

This section supersedes the earlier statements in this report that `.env.phase04.testing` was absent and MySQL identity had not been checked. The private file is now present and ignored by `.gitignore:4:.env.*`; no values were printed. Read-only PDO verification confirmed `DATABASE() = techmentors_lms_phase04_test`, `CURRENT_USER() = techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11, and zero schema objects.

The guarded command `php tests/run-tests.php --mysql-phase04` now reaches the authenticated preflight and refuses with exit 2 because the account has `ALL PRIVILEGES` on the target schema. `information_schema.SCHEMA_PRIVILEGES` shows seven privileges beyond the documented 11-item allowlist. No migrations or MySQL integration tests ran; no MySQL grants or data were changed. See `GRANT_SAFETY_DIAGNOSIS.md` for exact grants and the DBA correction.

Grant parser regressions: `php tests/run-tests.php --sqlite --filter=Phase04MySqlGrantPolicyTest` passed 7 tests / 12 assertions. Full SQLite regression: `php tests/run-tests.php --sqlite` passed 91 tests / 759 assertions. `composer.bat format:check` and `composer.bat analyse` both passed. These SQLite results do not constitute MySQL integration evidence.
