# Phase 04 security audit — recovery rerun

## Final security integration audit (2026-10-23)

**Disposition: no known critical or high-severity unresolved Phase 04 application finding in the verified local paths. This is an engineering audit, not an OWASP ASVS certification.**

This final audit supersedes earlier blocker findings below when their stated conditions have since been remediated; those findings remain as dated history.

The isolated MySQL V2 grant/ownership preflight and final postflight passed. The account was verified against the dedicated test schema and exact documented grant allowlist; no credentials are recorded. The guarded suite passed 122 tests / 934 assertions. The final preflight observed 80 expected objects and 14 migrations without changing schema or data.

ClamAV 1.5.4 was exercised through the configured private test environment. The adapter supplies the configured signature directory to `clamscan`, validates signed archive metadata with `sigtool`, checks daily database version/timestamp consistency and freshness, limits process execution, and records only safe scanner/version identifiers. A real clean PDF was scanned, approved and downloaded by an authorized same-tenant user. A standard harmless EICAR test signature was detected and rejected in quarantine. Cross-tenant download was denied. Tests cover unavailable scanner/definitions, malformed or stale metadata, signature/CLI mismatch, timeout, process failure and unsupported upload type; failures do not release documents.

The concurrent capacity-one MySQL test initially exposed a repeatable-read snapshot race in offer issuance. The service now takes a bounded locking current read of existing reservations while holding the intake lock. The final two-session/two-call test passed with exactly one offer and one capacity rejection; no duplicate reservation was persisted.

| Severity | Finding / limit | Disposition |
|---|---|---|
| Critical / High | None identified in the tested Phase 04 local application paths. | No open finding. |
| Medium, production operational prerequisite | A local scanner is configured for testing, but production scanner installation, signature update scheduling, monitoring and alerting have not been provisioned or exercised. | Keep production document uploads disabled until production scanner operations are deployed and verified. |
| Low, external verification limit | Hosted CI and production mail/storage/deployment integrations were not run. | Local CI-equivalent checks passed; external integrations remain deployment tasks. |

Additional controls verified by the full suites include tenant-aware authorization, guardian authorization/revocation, clean-only private-file release, safe error behavior, input validation, database isolation safeguards, and audit events. No secrets or personal scanner paths are included in this report.

> **Historical V1 grant finding:** the earlier V1 account had an overbroad grant. Current V2 grant verification passes the exact 11-privilege policy; see the verification section below. No secret values are included here.

## Historical V2 security verification before final scanner integration (2026-10-23)

The V2 test account is restricted to the dedicated V2 schema with exactly the documented 11 privileges and no grant option; the guarded V2 runner verified this before migration and again during postflight. The V2 database identity and server UUID are recorded in `V2_MYSQL_RECOVERY_REPORT.md`. V1 ownership state and prior application databases were outside the runner's explicit V2 target.

The migration repair uses short explicit FK names only; the required tenant/application and verifier/revoker referential constraints are present and verified through MySQL `information_schema` assertions. Database lock behavior was exercised in a two-session probe. Application security tests cover tenant denial, authorization, guardian token/revocation, safe error handling, private quarantine, and current-version scan gating. No critical/high application-code issue was identified in the tested foundation paths; this is not a security certification.

**Medium, release-blocking operational finding:** ClamAV is absent (`clamscan`, `clamdscan`, `freshclam`, `sigtool` and service lookup all negative). The adapter fails closed and unscanned documents stay quarantined. No real scan or signature freshness verification was performed. Install the approved scanner and current definitions, configure the private runtime path, and verify clean/infected/unavailable/timeout/stale-signature cases before treating document scanning as complete.

**Verification limitation:** The capacity-one workflow and the underlying MySQL row-lock serialization each pass, but two concurrent full offer-issuance calls have not been run together. Hosted CI was not executed.

## 2026-10-23 verification addendum

| Severity | Finding | Evidence and disposition |
|---|---|---|
| Medium, release-blocking for destructive test execution | Phase 04 test account has excessive schema privileges | Read-only `SHOW GRANTS` and `information_schema` confirmed global `USAGE` and schema `ALL PRIVILEGES` for `techmentors_phase04_test@127.0.0.1`; 18 privileges vs the exact 11-item allowlist. No grant option. The runner exits 2 before migrations. DBA must correct the grant; no privilege was changed by this run. |
| Medium, fail-closed | Real malware scanner unavailable | Neither `clamscan` nor `clamdscan` was available/configured. Quarantine and clean-only access rules remain enforced. No document scan is claimed. The adapter does not check signature freshness/version. |
| Low, not release-blocking for local runs | Hosted CI and production mail not verified | No hosted runner or production delivery service was exercised. |

### Verified controls and boundaries

- `.env.phase04.testing` is locally present and Git-ignored; its contents and credentials were not printed. Read-only PDO identity matched the dedicated Phase 04 schema and `127.0.0.1` account; the schema was empty before any migration.
- The MySQL runner refuses before schema snapshot/manifest creation or migrations when the exact 11-privilege allowlist is not met. Grant-policy regression tests pass (7 tests / 12 assertions), including valid quoting/split-grant formats and rejection of `ALL PRIVILEGES`, extra privileges, missing privileges, wrong account, global grant, wrong schema, and grant option.
- SQLite backend suite passed 92 tests / 764 assertions; Playwright passed 20 Chromium tests. Those tests support application-level behavior but do not prove MySQL DDL/locking or concurrency.
- Guardian invitation tokens are hashed and single-use; the exercised authority flow requires verified email and separate institutional relationship verification, grants scoped read-only access, and revocation removes access. This is not government identity verification.
- Uploads remain private and quarantined until a current document version is positively scanned clean. No malware scanning infrastructure was available.
- No Phase 04 MySQL writes, grants, development database changes, unrelated database access, service changes, or Phase 05 work occurred.

**Security gate:** no evidence of a critical application-code defect in the executed local checks, but Phase 04 remains **BLOCKED** by mandatory least-privilege MySQL preflight and real scanner verification. This is not an OWASP ASVS certification.

**Scope:** Phase 04 admissions additions and changed routes, guardian authorization, document quarantine/scanner path, waitlist/decision workflow, guarded test runner, browser test flow, and their documented environment limits. This is a project-level security review informed by OWASP ASVS; it is not a certification or complete production penetration test.

## Findings

| Severity | Finding | Current disposition |
|---|---|---|
| **Medium — test account grant overreach** | Current authenticated account has global `USAGE` and schema-scoped `ALL PRIVILEGES`; 18 schema privileges are exposed, seven beyond the required 11. | Guarded runner exits 2 before migrations. DBA must reduce grants for the exact `127.0.0.1` account. No grant changes were made. |
| **Medium — malware scanner unavailable; access fails closed** | No `clamscan`/`clamdscan` executable or scanner configuration is available. The adapter does not validate signature freshness/version. | Files remain quarantined and unavailable until clean scan status. Provision/configure approved ClamAV with current definitions and verify clean, infected, unavailable, timeout, and freshness behavior before claiming scanning. |
| **Medium — guardian identity assurance limits** | Exercised workflow verifies invited email control and an independent institutional relationship-verification action; it does not establish government identity or use an external identity provider. | Guardian scope is read-only and application-specific; revocation removes access. Apply institution-approved evidence procedures where policy requires them. |
| **Medium — external delivery not verified** | Local automated delivery does not establish production mail transport, retry behavior, deliverability, or monitoring. | Configure and test production mail delivery before real invitations. |
| **Low — hosted CI not observed** | No hosted workflow execution was available. | Local checks are reported separately; hosted CI is not claimed as passed. |

## Controls verified locally

- The test runner defaults to isolated SQLite. Phase 04 MySQL mode requires a regular ignored `.env.phase04.testing`, then checks its exact allowlisted database/account/host, scoped grants, current identity, and safe schema ownership before allowing test migrations. Current read-only identity and empty-schema checks pass; the runner exits at the too-broad grant check before schema snapshot or migration.
- Private document storage is not served from the public root. File type/signature, size, random path, scanner status, version identity, and fail-closed access are enforced by application logic and backend tests.
- The scanner adapter supports ClamAV CLI, but no executable is present and signature freshness/version checks are not implemented by the adapter. Configure `ADMISSIONS_DOCUMENT_SCANNER=clamav` and `ADMISSIONS_CLAMAV_BINARY` privately, then verify clean, EICAR/infected, unavailable, timeout, and stale-definition/version behavior. No real scan occurred; unscanned files remain quarantined.
- Guardian tokens are random and stored as hashes, invitation links expire, email identity is checked, institution relationship verification is separate, and revocation is audited. Application ownership and tenant scoping are required for access.
- The guardian invitation bearer URL is returned in an HTTP response header only in the local E2E configuration: `APP_ENV=testing`, E2E mode enabled, array mailer, and loopback requester are all required; the response is `private`/`no-store`. Playwright verifies the real acceptance and revocation path. External mail and identity verification remain untested.
- The Playwright web server uses an isolated marker-checked SQLite database and per-process file-cache directory, keeping disposable data and rate-limit counters separate from development/MySQL schemas and shared local cache.
- Recommendation and approval require distinct actors. Offer issue requires an approved admission recommendation; waitlist promotion requires another recommendation/approval. Tenant-scoped tests cover cross-tenant ID attempts.
- Public liveness remains generic; readiness diagnostics are protected. Browser checks exercise host-header rejection and private-file/devtools surface restrictions.
- `composer.bat audit` reported no advisories; `npm.cmd audit --audit-level=high` reported zero vulnerabilities. These dependency checks are point-in-time results, not a guarantee of future dependency safety.
- Current private Phase 04 test environment file is present and ignored. The tracked example contains no credential; no password or token value is recorded in this report. The owner reports the previously exposed credential was rotated; current connection authentication works.
- No development database, older test database, unrelated database, Apache configuration, or system service was modified during this recovery rerun.

## Audit conclusion

At the time of the initial audit, the phase was **BLOCKED** by the exposed credential and unavailable test configuration. The follow-up below supersedes that credential/configuration status: the owner reports rotation and the private ignored configuration authenticates. The current database blocker is the overbroad schema grant. Malware scanning and production email delivery remain external deployment requirements. This audit makes no ISO, SOC 2, OWASP ASVS, or other certification claim.

## 2026-10-22 database-grant follow-up

The earlier finding that the private test configuration was absent is superseded. The owner reports rotating the previously exposed credential; the ignored local configuration now authenticates successfully to the exact Phase 04 schema. No password or environment contents were exposed in this verification.

**Medium — least-privilege test-account overgrant, unresolved.** The authenticated account has global `USAGE` only and `ALL PRIVILEGES` on `techmentors_lms_phase04_test.*`, with no grant option. Read-only privilege enumeration found seven schema privileges beyond the documented migration/test allowlist: `ALTER ROUTINE`, `CREATE ROUTINE`, `CREATE VIEW`, `EVENT`, `EXECUTE`, `SHOW VIEW`, and `TRIGGER`. The test runner correctly refuses the grant. No grant was revoked or changed. A DBA must replace it with the exact allowlist in `GRANT_SAFETY_DIAGNOSIS.md` before MySQL integration tests can run.

Read-only identity and empty-schema checks passed. The MySQL destructive test suite remains **BLOCKED**; no migrations or data changes occurred. Malware scanning remains unavailable and fail-closed. This remains a project security review, not certification.
