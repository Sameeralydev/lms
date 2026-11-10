# Phase 04 V2 MySQL recovery and final verification

## Final security integration closure (2026-10-23)

**Current result: local Phase 04 acceptance PASS — ready for Phase 05.** This final addendum supersedes the earlier scanner and offer-race blockers recorded below. The final read-only V2 preflight passed (database `techmentors_lms_phase04_v2_test`, account `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11, 80 objects, 14 migrations). The full guarded MySQL suite passed 122 tests / 934 assertions, exit 0. Real ClamAV 1.5.4 integration verified signature archives and fresh daily version 28162; clean/EICAR document workflow passed 1 test / 30 assertions. A concurrent capacity-one offer race passed 1 test / 8 assertions.

The V2 schema/ownership manifest remains protected and separately scoped; no V1, development, or unrelated schema was targeted in the final verification. Production scanner deployment and hosted CI remain unverified operational items, not local Phase 05 entry blockers. See `FINAL_GATE_REPORT.md` and `TEST_REPORT.md` for the complete gate evidence. Phase 05 implementation was not started.

**Assessment date:** 2026-10-23  
**Scope:** Phase 04 recovery only. Phase 05 was not started.  
**Historical result at original report time:** Phase 04 was blocked by the unavailable real malware scanner; this was superseded by the final integration update above.

## V2 database preflight and ownership

The guarded runner explicitly loads the Git-ignored `.env.phase04.v2.testing`; Laravel is not assumed to discover that filename. The test runner's private-file, target, grant, checkout-lock, MySQL named-lock, stable-snapshot, and migration-inventory checks all passed.

| Check | Verified result |
|---|---|
| Database | `techmentors_lms_phase04_v2_test` |
| Authenticated account | `techmentors_phase04_v2@127.0.0.1` |
| Server | MySQL 8.4.11, `DESKTOP-K7HEM4H:3306`, server UUID `8abf9644-c935-11f1-8460-70d8234b22af` |
| Grant policy | PASS: exactly the 11 documented schema privileges (`ALTER`, `CREATE`, `CREATE TEMPORARY TABLES`, `DELETE`, `DROP`, `INDEX`, `INSERT`, `LOCK TABLES`, `REFERENCES`, `SELECT`, `UPDATE`), global `USAGE` only, no grant option |
| Initial schema | Read-only preflight observed zero schema objects and zero migrations before the first guarded run; the request explicitly authorized this new schema as disposable test-only storage. The initial V2 manifest recorded that empty state |
| Current schema | 80 expected tables/objects and all 14 local migrations, stable across the guarded preflight; no unexpected object inventory reported. Read-only inspection found only migration-owned nonempty baseline rows: `migrations=14`, `permissions=34`, `platform_bootstrap_states=1`; the guardian table has zero rows |
| Current manifest | Separate V2 manifest at `storage/framework/.phase04-v2-test-db-ownership.json`; explicit disposable authorization recorded; 80 table entries, 14 migration entries, 64-character structural fingerprint |
| V1 preservation | V1 manifest remains at `storage/framework/.phase04-test-db-ownership.json`; V2 runner used its separate profile, manifest, and locks. The recorded V1 before/after hash comparison passed; current hash is `d6a37de817a1d43b8592758fc4b3087135dc12db92514f2e2023655d433fbdcb` |
| Private configuration | `git check-ignore --quiet -- .env.phase04.v2.testing` returned success; no credentials were printed |

The runner validates database/account identity and all required grants before migration or test reset. V2 migrations and tests were run only by `tests/run-tests.php` against the allowlisted V2 target. No connection or write was directed at `techmentors_lms_phase04_test`, `techmentors_lms_test`, `techmentors_lms_dev`, or the unrelated databases.

## Migration interruption and repair

The first guarded full run exposed MySQL error 1059 while creating migration `2026_10_22_000010_create_admission_guardian_authorizations`: Laravel's generated foreign-key identifier `admission_guardian_authorizations_relationship_verified_by_foreign` exceeded MySQL's 64-character identifier limit. That attempt reported **62 failed, 47 passed, 140 assertions, exit 2**; it did not count as a passing suite. The migration had created the guardian table but had not recorded migration 000010. The guarded postflight refused to bless the incomplete state.

Read-only inspection found only the exact authorized interrupted prefix: migrations through 000009, the migration-created 80-object inventory, and the known partial guardian table with its first three constraints. The V2-specific resume policy required the original empty V2 manifest, exact server/account identity, allowed grants, migration prefix, row baseline, guardian table columns, and exact partial constraint inventory. The V1 manifest was not changed.

The migration now uses explicit short constraint names `adm_guardian_verified_by_fk` and `adm_guardian_revoked_by_fk`. The guarded resume path reran the test database migration transition and verified the full guardian schema. Only after successful postflight did it advance the separate V2 manifest from its original authorized empty state to the verified 80-object/14-migration state.

The MySQL guardian schema test verified the token hash and tenant/id unique indexes, tenant/application/status and tenant/guardian/status indexes, tenant/email/status index, tenant-scoped application foreign key, and user foreign keys for applicant, guardian, relationship verifier, and revoker.

## Test and quality evidence

| Command | Result |
|---|---|
| `php tests/run-tests.php --preflight-phase04-v2` | PASS, exit 0; read-only identity, grants, stable schema, 80 objects, 14 migrations; no manifest/schema/data change |
| `php tests/run-tests.php --mysql-phase04-v2-resume --filter=mysql_guardian_authorization_schema_has_required_indexes_and_foreign_keys` | PASS, exit 0; 1 test, 21 assertions |
| `php tests/run-tests.php --mysql-phase04-v2 --filter=test_mysql_row_lock_serializes_a_competing_session_on_a_committed_record` | PASS, exit 0; 1 test, 12 assertions |
| `php tests/run-tests.php --mysql-phase04-v2` | PASS, exit 0; **113 passed, 864 assertions**, 32.06 seconds. Includes guardian migration constraints, tenant boundaries, capacity limit, guardian authorization/revocation, offer approval, and repeated SIS handoff creation |
| `php tests/run-tests.php --sqlite` | PASS, exit 0; **111 passed, 2 MySQL-only tests skipped, 831 assertions**, 5.45 seconds |
| `npm.cmd run test:unit` | PASS; **6 tests** |
| `npm.cmd run test:e2e` | PASS; **20 Playwright Chromium tests**, including real HTTP asset delivery, admissions workflows, guardian revocation, offer approval/acceptance and SIS handoff, responsive navigation, accessibility smoke checks, and error/security responses |
| `composer analyse` | PASS; PHPStan reported no errors |
| `composer format:check` | PASS; Pint |
| `composer check-platform-reqs` | PASS; PHP 8.3.35 and installed extensions satisfy package requirements |
| `composer validate --strict` / `composer audit --locked` | PASS; valid manifest, no Composer advisories |
| `npm.cmd run typecheck` / `npm.cmd run lint` / `npm.cmd run format:check` | PASS |
| `npm.cmd audit --audit-level=low` | PASS; zero vulnerabilities |
| `npm.cmd run build` | PASS; Vite 8.3.5 transformed 615 modules |
| Recursive PHP syntax check | PASS; 202 files, zero syntax failures |

The capacity service test sets intake capacity to one, prepares two approved applications, accepts one offer/reservation, and rejects the second. A separate two-session MySQL probe verified a competing locking read waits on a committed InnoDB row and resumes after rollback; it uses a random local readiness marker to avoid Windows pipe-blocking false positives. This verifies MySQL row-lock serialization and the application capacity guard separately. It does **not** simulate two simultaneous full `issueOffer` requests end-to-end.

The SIS handoff regression now calls `createSisHandoff` twice after acceptance and asserts the same handoff is returned, the application remains ready for SIS handoff, and only one row exists. This prevents a retry from failing after the first call advances application status.

## Security and infrastructure findings

- V2 database isolation passed with exact identity and privilege checks; a separate V2 manifest and run locks prevent adoption or modification of V1 ownership state.
- No application secrets or database credentials were printed or added to documentation.
- Tests cover cross-tenant access denial, CSRF/session/authentication foundations, input validation, safe errors, audit behavior, guardian invitation token use/revocation, and private document quarantine.
- Composer and npm audits reported no known dependency advisories at execution time. No compliance certification is claimed.
- Hosted CI was not executed. Browser tests used the project-local Laravel PHP 8.3 runtime and its isolated E2E SQLite database. Apache/XAMPP and machine-wide services were not changed.
- Real ClamAV scanning is **not verified**. `clamscan`, `clamdscan`, `freshclam`, and `sigtool` were not present on PATH; no ClamAV service or common installation path was found. No file was scanned by a real scanner. The document adapter remains unavailable/fail-closed, and unscanned files remain quarantined.

### External scanner prerequisite

Install an approved ClamAV CLI and its current signature database using the organization's approved Windows provisioning method. Configure private runtime values `ADMISSIONS_DOCUMENT_SCANNER=clamav` and `ADMISSIONS_CLAMAV_BINARY=<absolute path to clamscan>`. The adapter checks parseable engine/database version output and rejects signatures older than its configured maximum (48 hours by default). Before release, verify a clean file, EICAR/infected test file, unavailable executable, timeout, and stale/unparseable signature metadata through the quarantined document workflow. Until those checks pass, do not release unscanned files.

## Final gate

| Gate | Status | Evidence |
|---|---|---|
| V2 DB identity, grants, disposable authorization, lock and manifest | PASS | Guarded preflight and MySQL suite exited 0; separate V2 ownership record is complete |
| V2 migrations and MySQL-specific guardian constraints | PASS | 14 migrations; guardian schema test 1/21; full MySQL suite 113/864 |
| Local backend, frontend, browser and static-quality checks | PASS | SQLite, MySQL, Vitest, Playwright, PHPStan, Pint, TypeScript, ESLint, audits, build, PHP syntax and platform checks above |
| Two concurrent full offer-issuance requests | NOT VERIFIED | Capacity cap and MySQL row-lock serialization are tested separately; direct simultaneous workflow invocation remains untested |
| Real malware scan and current ClamAV signatures | BLOCKED | No scanner executable, service, or signatures installed; fail-closed quarantine retained |
| Hosted CI | NOT RUN | No hosted runner execution evidence |
| Phase 05 boundary | PASS | No Phase 05 implementation started |

**Decision: PHASE 04 BLOCKED — REQUIRED ACTION REMAINS.** The verified MySQL recovery and local development gates pass. Phase 04 cannot be called complete while genuine scanner availability and document-scan behavior remain unverified. Provision the approved ClamAV runtime/signatures and complete the listed clean/infected/unavailable/timeout/freshness tests; then rerun the final gate. The simultaneous offer-race test remains a separate verification limitation.
