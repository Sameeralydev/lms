# Phase 04 requirement traceability — recovery update

## Final acceptance evidence (2026-10-23)

The rows below are the current status; historical rows later in this file preserve prior investigation and are superseded where they conflict.

| Mandatory requirement | Current evidence | Status |
|---|---|---|
| Dedicated MySQL V2 target, ownership and least privilege | Final read-only `--preflight-phase04-v2` exit 0: exact database/account, MySQL 8.4.11, grant policy, separate ownership manifest, 80 objects and 14 migrations. Existing V1/development schemas were not targets. | PASS |
| Guarded MySQL migrations/integration | `php tests/run-tests.php --mysql-phase04-v2`: 122 passed / 934 assertions, exit 0 with postflight. | PASS |
| SQLite regression | 119 passed, 3 MySQL-only skipped, 893 assertions, exit 0. | PASS |
| Tenant isolation, guardian verification/revocation, offer decisions and SIS handoff | Covered by passing backend and 20 Playwright Chromium tests; authorization and handoff journeys passed. | PASS |
| Intake, dynamic application forms, inquiry and applicant lifecycle, staff review | Existing Phase 04 feature and browser journeys remain in the full passing suites; forms and admission states use backend validation and persisted records. | PASS for tested workflows |
| Guardian authority | Verified guardian acceptance, institution relationship verification, scoped read-only access and audited revocation exercised in backend and browser journeys. External identity proof is not claimed. | PASS for local workflow |
| Decision/offer/waitlist/SIS snapshot | Separate decision approval, offer response, capacity reservation and idempotent SIS handoff exercised by backend/browser suites; no student record is created in Phase 04. | PASS for implemented scope |
| Real malware scan, quarantine and controlled release | ClamAV 1.5.4 plus verified main/daily/bytecode archives. Real clean PDF approved and authorized download succeeded; real EICAR signature rejected; cross-tenant access denied. | PASS (local integration) |
| Scanner failure-closed behavior | Unit/integration coverage for missing binary/definitions, process error, timeout, stale/mismatched definitions and unsupported input; failed scans remain unreleasable. | PASS |
| Concurrent offer capacity | Real concurrent MySQL service calls: 1 passed / 8 assertions; exactly one issue and one capacity rejection. | PASS |
| Responsive/accessibility smoke coverage | 20 Playwright Chromium cases include browser navigation, narrow layouts, keyboard interactions and sampled accessibility checks. This is not a full WCAG conformance audit. | PASS for sampled scope |
| Frontend and quality gates | Vitest 6; Playwright 20; TypeScript, ESLint, formatting, PHPStan, Pint, PHP syntax (205 files), dependency audits, platform checks and production build passed. | PASS |
| Hosted CI execution | No hosted runner executed. | NOT RUN (non-blocking for local Phase 05 handoff) |
| Production scanner operations | Production binary/signature updates/monitoring not provisioned or tested. | DEFERRED deployment prerequisite; keep production uploads disabled until verified |
| Phase boundary | No Phase 05 functionality was implemented. | PASS |

**Current local Phase 04 gate: PASS — ready for Phase 05 development.** This does not state production readiness or formal certification.

> **Historical acceptance mapping:** the earlier V1 mapping reflected a prior grant and schema-ownership state. The current V2 evidence is recorded in the section immediately below.

## Historical V2 recovery acceptance evidence before scanner and race closure (2026-10-23)

| Requirement | Current evidence | Status |
|---|---|---|
| Isolated V2 database identity and 11 least-privilege grants | Guarded preflight confirmed V2 database/account, MySQL 8.4.11 and exact grant allowlist; final preflight stable at 80 objects / 14 migrations. | PASS |
| Ownership protection and migration lifecycle | Separate authorized V2 manifest, server identity/fingerprint, stable snapshots, exact migration inventory and exclusive locks; postflight updated V2 manifest only after successful suite; V1 manifest preserved. | PASS |
| Guardian schema correctness | MySQL information_schema test verified tenant-scoped FK, user FKs, unique keys and scoped indexes: 1 test / 21 assertions. | PASS |
| MySQL integration | Guarded suite 113 passed / 864 assertions, exit 0. | PASS |
| Admissions capacity | Capacity-one service test rejects the second offer; separate second-session probe proves row-lock serialization. Full concurrent dual `issueOffer` execution remains untested. | PARTIAL; race test not verified |
| SIS handoff idempotency | Backend calls handoff twice and verifies a single stable record; Playwright queues the pending handoff. | PASS |
| SQLite/frontend/browser/quality | SQLite 111 / 831 with two MySQL-only skips; Vitest 6; Playwright 20; PHPStan, Pint, TypeScript, ESLint, Prettier, audits, platform checks and build pass. | PASS |
| Genuine document malware scanning | No ClamAV executable/service/signature database present; fail-closed quarantine verified, but no file scan occurred. | BLOCKED |
| Hosted CI | No hosted runner execution evidence. | NOT RUN |
| Phase boundary | No Phase 05 work performed. | PASS |

**Current gate: BLOCKED.** The V2 database gate is resolved. Complete real ClamAV installation/signature and document-scan verification before declaring Phase 04 complete. See `V2_MYSQL_RECOVERY_REPORT.md`.

## Latest ownership recovery addendum (2026-10-23)

This addendum supersedes the earlier mapping and closure actions below where they still state that the grant is excessive, the schema is empty, the private configuration is missing, or signature freshness checks are absent.

| Requirement | Latest evidence | Status |
|---|---|---|
| Dedicated database identity | Read-only PDO verifies `techmentors_lms_phase04_test`, `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11. | PASS |
| Least-privilege grants | Global `USAGE` only and the exact documented 11 schema-scoped privileges; no grant option. | PASS |
| Ownership/schema integrity | Manifest records zero tables/migrations; current schema has 80 base tables and 13 migration rows. Migration 000010 is missing from the ledger; its guardian table exists with constraints/indexes missing versus local migration. The app account cannot enumerate all trigger/view definitions. | BLOCKED; preserved |
| Migration ownership attribution | Earlier active ALTER session and timestamps fit an interrupted guarded migration, but current MySQL account cannot see other users' sessions and historical connection cannot be tied to a Windows PID. | BLOCKED; DBA read-only correlation required |
| MySQL migrations, foreign keys, transactions, concurrency | Guarded read-only preflight exit 2 before Artisan tests. | NOT RUN / BLOCKED |
| SQLite backend regression | `php tests/run-tests.php --sqlite`: 105 tests / 797 assertions, exit 0. | PASS for SQLite only |
| Ownership/grant/scanner regression | Focused PHPUnit: 20 tests / 45 assertions, exit 0. | PASS |
| Static analysis/formatting | Full PHPStan and full Pint checks passed. | PASS |
| Scanner freshness/version control | Adapter validates parseable engine/database version metadata, 48-hour age limit by default, and future-time skew; unit tests pass. No ClamAV CLI/signature database is installed, so real scanning remains unavailable and quarantined. | Implementation PASS; real scan BLOCKED |
| Browser/hosted CI | Not run in this recovery. | NOT RUN |
| Data preservation and phase boundary | No MySQL data/schema writes, no process termination, no Phase 05 changes. | PASS for this run |

**Current acceptance remains BLOCKED.** Do not modify the ownership manifest or run MySQL tests until the owner/DBA resolves and authorizes recovery of the inconsistent dedicated test schema.

| Requirement | Latest evidence | Status |
|---|---|---|
| Exact isolated Phase 04 database/account | Read-only PDO: `techmentors_lms_phase04_test`, `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11; schema had zero objects. Private `.env.phase04.testing` is Git-ignored and secrets were not printed. | Identity/empty-state PASS; grants BLOCKED |
| Exact least-privilege 11-grant allowlist | `SHOW GRANTS`: global `USAGE`, schema `ALL PRIVILEGES`; 18 scoped privileges including seven extras. Guarded runner exits 2 before migration. | BLOCKED; actual overgrant, not parser error |
| MySQL migrations/integration/FK/transactions/concurrency | No migrations ran because grant check rejected. | NOT RUN / BLOCKED |
| SQLite backend regression | `php tests/run-tests.php --sqlite`: 92 tests / 764 assertions, exit 0; admissions subset: 14 / 202. | PASS for SQLite only |
| Grant policy parser regression | `Phase04MySqlGrantPolicyTest`: 7 tests / 12 assertions, exit 0; accepts valid quoted/loopback formats, rejects grant escalation and scope mismatch. | PASS |
| Guardian authority and revocation | Backend and 20-test Chromium suite cover separate verified guardian account, institutional relationship verification, scoped read-only access, tenant denial, and revocation. E2E email account is synthetic/pre-verified. | PASS for exercised local flow; no external identity provider |
| Offer approval and SIS handoff | Independent recommendation/approval, offer acceptance, and pending SIS snapshot handoff exercised by backend/browser journeys. | PASS for local flow; no SIS student record is created in this phase |
| Seat capacity | New sequential test with capacity one and two approved applications allows only one offer/reservation. Uses transaction/row-lock design. | Sequential PASS; MySQL concurrent locking NOT VERIFIED |
| Document scanner | No approved ClamAV binary/configuration found. Adapter is fail-closed; private documents remain quarantined. Signature freshness/version checking is not implemented. | BLOCKED for genuine scanning |
| Frontend / quality gates | Vitest 6; Playwright 20; TypeScript, ESLint, formatting, PHPStan, Composer/npm audits and production build all passed locally. | PASS, hosted CI NOT RUN |
| Database/project preservation and scope | No MySQL writes, no changes to development or unrelated schemas, no destructive reset and no Phase 05 work. | PASS for this run |

**Closure actions:** DBA must replace `ALL PRIVILEGES` on `techmentors_lms_phase04_test.*` for `'techmentors_phase04_test'@'127.0.0.1'` with only the documented 11 grants; then rerun the unchanged guarded runner. Provision/configure approved ClamAV with current definitions and add real clean/infected/unavailable/timeout/signature-freshness verification. Do not waive either gate.

**Assessment date:** 2026-10-22. Full scope acceptance remains **BLOCKED**; no Phase 05 work was started.

| Requirement area | Current evidence | Status |
|---|---|---|
| Preserve project and database data | Phase 04 tests ran only with guarded SQLite. No MySQL migration, reset, or write was performed. | PASS for this recovery run |
| Guarded MySQL integration | `.env.phase04.testing` is absent and ignored. `php tests/run-tests.php --mysql-phase04` exits 2 before connecting. Prior read-only probe returned MySQL 1045. | BLOCKED; schema identity, account/grants, emptiness, migrations, and MySQL behavior unverified |
| SQLite backend suite | `php tests/run-tests.php --sqlite`: 84 tests / 747 assertions. Admissions-focused subset: 13 / 197. | PASS |
| Intake lifecycle and visual builder | Institution user creates, edits, opens, and publishes an intake/form; visual question editing, preview, responsive public rendering, and axe smoke check in Chromium. | PASS for exercised journey |
| Inquiry workflow | Tenant-scoped inquiry listing/qualification, status change and audit are covered by backend and browser workflows. | PASS for exercised workflow; delivery analytics remain out of scope |
| Applicant registration and submission | Signed-verification foundation remains; browser flow starts an existing verified applicant, saves a draft, submits, and opens the applicant directory. | PASS for exercised flow; external email provider not verified |
| Guardian/minor authority | New authorization record, hashed one-time token, separate verified guardian account, institution relationship-verification step, scoped read-only application access, audited revocation, and minor submission gate. Backend tests and Chromium verify blocked submission before verification, scoped invitation acceptance, staff verification, read-only cross-tenant denial, and revocation. | PASS for exercised backend/browser paths; E2E email identity uses pre-verified synthetic fixtures. Human relationship evidence, external identity provider, and production mail remain procedure/deployment requirements |
| Document quarantine and scanning | Private randomized storage, type/signature checks, scan job/adapter, current-version checks, and fail-closed download/review gates. Tests cover unscanned denial, scanner event audit, and stale-version denial. | Safe fail-closed implementation; **real scanner integration blocked**. The adapter requires authorized ClamAV CLI with current signatures, configured by `ADMISSIONS_DOCUMENT_SCANNER=clamav` and absolute `ADMISSIONS_CLAMAV_BINARY`, followed by clean, EICAR/infected, unavailable, timeout, and stale-definition tests |
| Staff review and application lifecycle | Chromium journey moves applicant through review to decision pending, with separate recommendation and approval accounts. | PASS for exercised journey |
| Independent decision approval | Distinct reviewer/recommender and approver enforced in the domain workflow, feature tests, and Playwright. | PASS for exercised paths |
| Waitlist | FIFO queue position plus a new independently recommended and approved promotion decision; test proves promotion does not implicitly issue an offer. | PASS in focused SQLite tests; MySQL/concurrency evidence pending |
| Offers and capacity | Offer requires approved admission decision; transactional seat reservation and applicant accept/decline/expiry workflow. Browser journey issues and accepts an offer. | PASS for exercised SQLite/browser paths; MySQL locking/concurrency pending |
| SIS handoff | Browser queues an idempotent pending snapshot after offer acceptance. No student record is created in Phase 04. | PASS for Phase 04 snapshot path; receiving SIS is future-phase work |
| Tenant isolation | Cross-tenant backend authorization tests and browser tenant-boundary denial pass. | PASS for tested paths; MySQL composite-key/constraint verification pending |
| Responsive UI/accessibility | Browser checks narrow shell navigation, intake viewport, keyboard navigation, and axe smoke checks. | PASS for sampled pages; not a full WCAG conformance assessment |
| Quality gates | PHPStan, PHP syntax, Pint, TypeScript, ESLint, Prettier, Vitest, Composer/npm audits, and production build pass. | PASS |
| Hosted CI | No hosted runner executed. | NOT RUN |
| Credential handling | The modified tracked example's non-placeholder password line was replaced with a placeholder without displaying its value; other settings matched `HEAD`, so the example was restored cleanly. Actual private Phase 04 config is absent. | HIGH finding remains until rotation and private reconfiguration |
| Documentation and release gate | Reports updated with current execution evidence and limitations. | PASS for evidence update; release remains blocked |

## Required closure evidence

1. Rotate the exposed dedicated Phase 04 test account password; do not send it in chat or store it in a tracked example.
2. Privately create the ignored `.env.phase04.testing` using the rotated credential.
3. Run the guarded preflight and verify exact server/database/account identity, database emptiness, and schema-scoped privileges. Stop if any table or unexpected record exists.
4. Only after that preflight passes, run the controlled Phase 04 migrations and full MySQL integration suite. Preserve the runner’s database guard.
5. Install/configure an authorized malware scanning service and test clean, infected, unavailable, and stale-version behavior before claiming scanning is available.
6. Configure a production-grade mail provider and verify delivery, retry, and failure handling before relying on external guardian invitations.

Hosted CI remains unverified, but it is not represented as a locally completed check. No `migrate:fresh`, `db:wipe`, production reset, or Phase 05 implementation was performed.

## 2026-10-22 MySQL grant verification update

The private ignored configuration and test-account authentication now verify successfully. Read-only identity and empty-schema checks pass. Least-privilege grant verification is **BLOCKED** because the account has `ALL PRIVILEGES` on the Phase 04 schema; seven extra schema privileges were enumerated. The runner refuses before any migration. Full MySQL integration evidence remains pending the DBA grant correction and a new passing preflight. See `GRANT_SAFETY_DIAGNOSIS.md`.
