# Phase 04 final gate — database unblock and implementation continuation

## Final security integration and release gate (2026-10-23)

This section supersedes the historical V2 blocker rows below. Final local evidence now includes real ClamAV scans and a two-session offer-capacity race test.

| Gate | Result | Evidence |
|---|---|---|
| Guarded V2 MySQL identity, grants, ownership and schema | PASS | `php tests/run-tests.php --preflight-phase04-v2`: exit 0; database `techmentors_lms_phase04_v2_test`, account `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11, 80 objects, 14 migrations; exact grant allowlist and V2 ownership checks passed. Final preflight was read-only. |
| MySQL integration suite | PASS | `php tests/run-tests.php --mysql-phase04-v2`: 122 passed, 934 assertions, exit 0; guarded runner postflight passed. |
| SQLite regression suite | PASS | `php tests/run-tests.php --sqlite`: 119 passed, 3 MySQL-only skipped, 893 assertions, exit 0. |
| Guardian schema constraints | PASS | MySQL `information_schema` integration assertions verified tenant/application and user foreign keys, unique constraints and scoped indexes: 1 test / 21 assertions. |
| Real ClamAV scanning and document release controls | PASS | ClamAV 1.5.4; `sigtool --info` verified main/daily/bytecode signature archives; daily database 28162 was fresh and matched CLI metadata. Real workflow test: 1 passed / 30 assertions on SQLite and MySQL. Clean PDF was approved and authorized download succeeded; standard EICAR fixture was rejected and remained unavailable; cross-tenant download denied. |
| Simultaneous offer capacity | PASS | `test_mysql_simultaneous_offer_issuance_preserves_capacity_one`: 1 passed / 8 assertions; two competing calls produced one issued offer and one capacity rejection, with one reservation. |
| Frontend and quality regression | PASS | Vitest 6; Playwright Chromium 20; `npm.cmd run typecheck`, `lint`, `format:check`, `build`; PHPStan, Pint, Composer validation/platform/audit, npm audit, PHP syntax (205 files); all passed. |
| Hosted CI | NOT RUN | No hosted runner execution was available; local equivalents passed. |
| Production scanner deployment | DEFERRED | Local private test configuration is verified. Production requires an approved scanner deployment, current signature update process, monitoring, and operational alerting before production document uploads are enabled. |

The real scanner verification used the configured signature directory, explicit `--database` argument and `sigtool` verification. It checked engine/database metadata freshness and consistency; clean and EICAR outcomes were exercised. Failure-closed behavior for unavailable binary, missing/empty definitions, unsupported files, process failure, timeout, stale metadata and CLI/signature mismatch is covered by tests. No actual credentials, private paths or secrets are recorded here.

**Final decision: PHASE 04 COMPLETE — READY FOR PHASE 05 (local development gate).** No Phase 05 implementation was started. This is not a production readiness certification; production scanner operations, mail delivery, deployment infrastructure and hosted CI remain deployment/operations work.

> **Historical gate:** the earlier V1 test-schema grant and ownership findings are superseded by the current V2 gate below. V2 MySQL identity, grants, migrations, and ownership are now verified; the remaining blocker is real ClamAV scanning.

## Historical V2 gate before scanner and capacity-race closure (2026-10-23)

The new isolated V2 MySQL test schema has passed guarded preflight, exact grant validation, migration validation, strict postflight and the full MySQL suite. Final read-only identity: `techmentors_lms_phase04_v2_test`, authenticated account `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11. The schema contains the 80 expected objects and all 14 repository migrations. Its separate V2 ownership manifest records the verified structure and explicit disposable authorization; the V1 manifest remains distinct and preserved.

| Gate | Result | Evidence |
|---|---|---|
| V2 schema identity, grants, isolation and ownership | PASS | `--preflight-phase04-v2`, final postflight; 80 objects, 14 migrations; exact 11 grants |
| V2 MySQL suite | PASS | 113 tests, 864 assertions, exit 0 |
| SQLite suite | PASS | 111 passed, 2 MySQL-only skipped, 831 assertions, exit 0 |
| Browser/frontend/static quality | PASS | Playwright 20; Vitest 6; type/lint/format/PHPStan/Pint/audits/platform/build/syntax passed |
| Guardian MySQL schema | PASS | 1 test, 21 assertions for required indexes and foreign keys |
| Capacity concurrency | PARTIAL | Capacity-one offer flow and independent MySQL row-lock serialization pass; full concurrent dual-offer workflow was not executed |
| Real ClamAV file scanning | BLOCKED | Scanner tools/service/signatures absent; quarantine remains fail-closed |
| Hosted CI | NOT RUN | No hosted execution evidence |
| Phase 05 boundary | PASS | No Phase 05 implementation started |

**Final decision: PHASE 04 BLOCKED — REQUIRED ACTION REMAINS.** The remaining external requirement is an approved ClamAV installation with current signatures, configured for the application, followed by clean, infected/EICAR, unavailable, timeout, and freshness integration tests. The direct simultaneous offer-race workflow also remains an unverified test limitation. See `V2_MYSQL_RECOVERY_REPORT.md`.

## Latest ownership recovery update (2026-10-23)

This update supersedes earlier grant and empty-schema statements in this historical report. Read-only PDO verification confirms MySQL 8.4.11, `DATABASE() = techmentors_lms_phase04_test`, and `CURRENT_USER() = techmentors_phase04_test@127.0.0.1`. The grant policy passes: global `USAGE` only and exactly the documented 11 schema-scoped privileges, without grant option. The private `.env.phase04.testing` remains ignored; no credential was printed.

The schema is **not** eligible for migration or re-attestation. The local ownership manifest records zero tables and zero migrations. A stable pair of read-only snapshots found 80 base tables and 13 migration rows, all Batch 1; no views or triggers were visible, but the dedicated account lacks `SHOW VIEW`/`TRIGGER` privileges, so DBA confirmation is still required. The repository contains 14 migration files; `2026_10_22_000010_create_admission_guardian_authorizations` is absent from the migration ledger even though its table exists. That table's columns match the migration's names, but the live DDL is missing the composite application foreign key, two user foreign keys, two unique indexes, and three tenant-scoped indexes required by the checked-in migration. The permission seed contains 33 tenant-scoped keys from the academic/admissions migrations; the guardian permission from migration 000010 is absent. The bootstrap row matches its migration; other application tables have zero rows. The schema is preserved.

No PHP test runner or migration process is active. Four `mysql.exe` processes owned by the local Windows account have established loopback connections to MySQL; their database selections cannot be correlated because the dedicated account has no global `PROCESS` visibility. A previous ALTER session was observed during earlier diagnosis, but its Windows PID and owner cannot be established retrospectively. No process was terminated. The guarded runner now uses a checkout-local file lock and a MySQL server-level named lock, advances the manifest only after a successful suite, takes stable schema snapshots with a structural fingerprint, checks the complete local migration inventory, and permits only exact migration-owned baseline rows. It rejects the present state without changing the database or manifest.

The read-only ownership preflight acquired its transient MySQL named lock, then rejected the schema on manifest fields `tables`, `migrations`, and (under the strengthened policy) missing `schema_fingerprint`, exit 2; a subsequent `IS_FREE_LOCK()` check confirmed release. The guarded MySQL suite was **not run**; no migration, reset, or database data/schema write was executed. Full isolated SQLite regression passed: 105 tests / 797 assertions, exit 0. Full PHPStan and Pint passed. The focused safety/grant/scanner suites passed: 20 tests / 45 assertions. ClamAV freshness/version parsing now fails closed at 48 hours by default, but no ClamAV executable or signatures are installed, so no malware scan is claimed. Phase 05 remains out of scope.

**Latest decision: BLOCKED.** Required next evidence is a DBA-side read-only process/session correlation and an explicit owner/DBA recovery decision for this dedicated schema. If historical ownership cannot be proven, do not adopt its DDL by manifest re-attestation; obtain authorization for a controlled test-schema recovery path. Real MySQL integration and real ClamAV scanning remain unverified.

## 2026-10-23 final verification scorecard

> **Historical, superseded by the latest ownership recovery update above.** This scorecard predates the current read-only grant and schema investigation. Its grant, empty-schema, and MySQL-runner entries are not current evidence.

| Gate | Status | Evidence |
|---|---|---|
| Dedicated database identity and server | PASS, read-only | PDO reported database `techmentors_lms_phase04_test`, authenticated account `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11. `.env.phase04.testing` is ignored; no values were printed. |
| Disposable schema contents | PASS, read-only | Zero schema objects before any migration. No MySQL schema changes were made. |
| Required 11-privilege least-privilege grant | BLOCKED | Effective account has schema-scoped `ALL PRIVILEGES`; `information_schema` lists 18 privileges, including seven extras. Global grant is `USAGE`; no `GRANT OPTION`. |
| Guarded MySQL suite and MySQL migration/concurrency evidence | BLOCKED / NOT RUN | `php tests/run-tests.php --mysql-phase04` safely exited 2 at grant validation. No migration or MySQL test ran. |
| SQLite backend suite | PASS | `php tests/run-tests.php --sqlite`: 92 tests / 764 assertions, exit 0. Focused admissions suite: 14 / 202, exit 0. |
| Browser and frontend | PASS | Vitest: 6 tests; Playwright Chromium: 20 passed. Browser journeys cover guardian authority/revocation, independent offer approval, offer acceptance, SIS handoff queueing, tenant denial, responsive and accessibility smoke paths. |
| Static analysis, types, lint, format, build, dependency audits | PASS | PHPStan, Composer validation/platform/audit, Pint, TypeScript, ESLint, Prettier, npm audit and production build all exited 0. Composer audit reported no advisories; npm audit reported 0 vulnerabilities. |
| Sequential seat-capacity guard | PASS | New isolated SQLite test proved that with capacity 1 only one of two approved applications receives an offer/reservation. MySQL locking/concurrency remains unverified. |
| Real ClamAV scanning | BLOCKED; fail-closed | No `clamscan`/`clamdscan` executable or configured scanner was available. No file scan is claimed; files remain quarantined. |
| Hosted CI / production mail | NOT RUN / NOT VERIFIED | No hosted-runner execution or production mail transport evidence. |
| Preservation / phase boundary | PASS for this run | No MySQL writes, no changes to development or unrelated databases, no destructive resets, and no Phase 05 implementation. |

### Minimum actions to close the remaining gates

1. DBA: replace schema-scoped `ALL PRIVILEGES` for the authenticated account `'techmentors_phase04_test'@'127.0.0.1'` with exactly `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES` on `techmentors_lms_phase04_test.*`. Do not change unrelated accounts or schemas. Recheck `SHOW GRANTS` and `information_schema.SCHEMA_PRIVILEGES` read-only.
2. After the grant passes the unchanged runner policy, rerun `php tests/run-tests.php --mysql-phase04`. The runner must verify identity, grant, schema ownership and contents before it migrates. This is required for MySQL migration, foreign-key, transaction and concurrency evidence.
3. For genuine scanning, provision an approved ClamAV executable with current definitions, set `ADMISSIONS_DOCUMENT_SCANNER=clamav` and the private absolute `ADMISSIONS_CLAMAV_BINARY`, and verify clean, infected/EICAR, unavailable, timeout and signature freshness/version behavior through the application queue path. The adapter currently does not establish signature freshness/version.

**Decision: BLOCKED. Do not start Phase 05.** No mandatory MySQL acceptance criterion is waived. See `TEST_REPORT.md`, `SECURITY_AUDIT.md`, `REQUIREMENT_TRACEABILITY.md`, and `GRANT_SAFETY_DIAGNOSIS.md` for supporting evidence.

**Verdict: BLOCKED — REQUIRED ACTION REMAINS. Do not start Phase 05.**

**Assessment date:** 2026-10-22.

## Gate scorecard

| Gate | Status | Evidence |
|---|---|---|
| Laravel boot on supported PHP | PASS | Laravel 13.38.0 on PHP CLI 8.3.35; Playwright uses the Laravel development server |
| Existing Phase 04 database untouched | PASS for this run | Phase 04 MySQL runner did not connect; no MySQL migration/reset/write executed |
| Isolated Phase 04 MySQL identity, grants, and empty state | BLOCKED | `.env.phase04.testing` is absent. Runner refused with exit 2 before connection. Earlier read-only authentication probe returned 1045. Exact database/account/grants/contents remain unverified |
| MySQL migrations and integration tests | NOT RUN / BLOCKED | Cannot safely establish target identity, credentials, grants, or emptiness without private config and passing preflight |
| Guarded backend regression | PASS | SQLite: 84 tests / 747 assertions, exit 0; focused admissions: 13 / 197, exit 0 |
| Browser journeys | PASS | Playwright Chromium: 20 passed. Includes minor submission gate, guardian invitation/acceptance, staff relationship verification, read-only access, cross-tenant denial, revocation, intake builder, independent decision approval, offer issue/accept, handoff queue, responsive/accessibility checks |
| Frontend unit tests | PASS | Vitest: 6 passed |
| Static analysis, typing, lint, formatting | PASS | PHPStan, 170-file PHP syntax pass, Pint, TypeScript, ESLint, and Prettier passed |
| Dependency audits and build | PASS | Composer audit: no advisories; npm audit: 0 vulnerabilities; Vite production build transformed 615 modules |
| Security: tested flows | PASS with limits | No critical/high application-code defect found in this focused review. Controls and limitations are in `SECURITY_AUDIT.md` |
| Dedicated test credential exposure | BLOCKED / HIGH | The password was previously exposed. In this run, the non-placeholder value in the tracked example was replaced with a placeholder without printing it, then the example was restored to the repository version. Rotate before reuse; private test config remains absent |
| Real malware scanning | BLOCKED / fail-closed | No `clamscan` or `clamdscan` installed; all unverified documents remain quarantined. No real scan claimed |
| Guardian authorization | PASS for exercised local flows | Backend and Chromium verified minor submission gating, scoped guardian invitation acceptance using a pre-verified synthetic account, independent staff relationship verification, read-only scope, cross-tenant denial, and revocation. Production identity/email delivery remains unverified |
| External email delivery | NOT VERIFIED | Local test delivery only; production transport, retries, and deliverability not tested |
| Hosted CI | NOT RUN | No hosted runner result available |
| Documentation / Phase 05 boundary | PASS | Phase 04 evidence refreshed; no Phase 05 functionality implemented |

## Exact remaining actions

1. Rotate the dedicated `techmentors_phase04_test` credential because the former value must be treated as exposed. Do not disclose it in chat. The tracked example has been restored to its clean repository version.
2. Privately configure the rotated value in the ignored `.env.phase04.testing` file.
3. Run `php tests/run-tests.php --mysql-phase04` and require the read-only preflight to verify the exact dedicated DB/account, scoped grants, and genuinely empty schema. Stop if any unexpected table or data is found.
4. Only after that check succeeds, run the controlled Phase 04 migrations and full MySQL integration suite. Do not change the guard or target another database.
5. Provide/configure an authorized malware scanner and verify clean, malicious, unavailable, and stale-version behavior before reporting scanning complete.

The existing scanner adapter is ClamAV CLI. Provision an authorized ClamAV installation with current signature definitions, set `ADMISSIONS_DOCUMENT_SCANNER=clamav` and `ADMISSIONS_CLAMAV_BINARY` to the absolute `clamscan` executable path in private runtime configuration, then verify clean, EICAR/infected, unavailable, timeout, and stale-definition/version cases. Until then, uploaded documents remain quarantined and inaccessible.

Hosted CI and production mail transport remain unverified. No Apache/XAMPP configuration was changed. No unrelated or prior-phase database was accessed by the guarded MySQL rerun. No `migrate:fresh`, `db:wipe`, or Phase 05 work occurred.

See `TEST_REPORT.md`, `SECURITY_AUDIT.md`, and `REQUIREMENT_TRACEABILITY.md` for detailed evidence and implemented scope. This is not a compliance certification.

## 2026-10-22 current grant-safety status

> **Historical, superseded by the latest ownership recovery update above.** The grant and environment findings below describe an earlier state and must not be used as the current gate decision.

The prior report's absent-configuration blocker is superseded by a private ignored configuration and successful read-only authentication. Current verified scorecard:

| Gate | Status | Evidence |
|---|---|---|
| Exact Phase 04 DB/account/server identity | PASS (read-only) | `techmentors_lms_phase04_test`, `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11 |
| Credential rotation/private config | MITIGATED PER OWNER | Owner reports rotation; ignored config authenticates; no secret was displayed |
| Initial schema empty | PASS (read-only) | Zero schema objects before migrations |
| Least-privilege grants | BLOCKED | `ALL PRIVILEGES` on the dedicated schema; seven extras beyond the required 11; no global grants beyond `USAGE`, no grant option |
| Guarded MySQL integration suite | NOT RUN / BLOCKED | Runner exits 2 at grant preflight before schema ownership handling or migrations |
| SQLite regression | PASS | 91 tests / 759 assertions |
| Grant-policy regression | PASS | 7 tests / 12 assertions |
| Pint / PHPStan | PASS | `composer.bat format:check`; `composer.bat analyse` |
| Malware scanner | BLOCKED | No approved scanner evidence; fail-closed quarantine remains active |

**Current verdict remains BLOCKED.** The exact DBA correction is documented in `GRANT_SAFETY_DIAGNOSIS.md`. No database privilege or data change was made. Phase 05 is not authorized by this report.
