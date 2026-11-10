# Phase 05 security audit

**Review date:** 2026-10-24  
**Reference:** OWASP ASVS-aligned application review. This is not a certification or formal compliance assessment.

## Result

No critical or high-severity application issue was identified in the executed Phase 05 verification scope. The guarded MySQL/SQLite suites, browser journeys, local scanner tests, configuration checks and dependency audits passed. This conclusion is limited to the tested local environment and code paths.

## Evidence reviewed

- Dedicated Phase 05 MySQL identity and schema ownership passed guarded preflight, migration, test and postflight checks. The runner accepted only the documented 11 schema-scoped privileges; it used separate test locks and an ownership manifest.
- Cross-tenant record access, role scoping, campus scope and composite ownership constraints are covered in the backend suites. MySQL integration passed 169 tests / 1,603 assertions.
- Login, validation, session regeneration, safe error handling, public liveness/readiness privacy, CSRF/session defaults, host-header checks and private storage routing pass backend/browser tests.
- Import rows are bounded/chunked, source-row retries are idempotent, row errors are persisted separately, and import authorization is revalidated when work runs.
- Lifecycle approval requires a distinct reviewer; decisions and event history are auditable.
- Retention archive rechecks checksum and clean current-version state, writes to private storage, and purge marks state before post-commit file cleanup. Expired, purging and purged files are not downloadable.
- Outbox processing uses claims/leases, bounded retries and safe error codes. Exception details are not copied into outbox error fields.
- ClamAV 1.5.4 ran in local tests. Signed main and daily CVD metadata verified successfully; clean samples were accepted, raw EICAR was rejected, and unavailable/stale/missing/failure/timeout cases fail closed.
- Composer audit reported no advisories; npm audit reported zero vulnerabilities.

## Findings and limits

| Severity | Finding | Disposition |
|---|---|---|
| Informational | Hosted CI was not run. | Local equivalents passed; hosted runner evidence remains deployment/release follow-up. |
| Informational | XAMPP Apache still uses PHP 8.2, below Laravel 13's runtime requirement. | No global Apache change was made. Supported local development uses PHP 8.3.35 through Laravel's server. |
| Informational | Production scanner installation, signature monitoring, backup/storage controls, and operational alerting were not assessed. | Local scanner integration is verified; production operations need a separate deployment review. |
| Informational | Broad screen-reader/RTL/manual assistive-technology verification and a valid infected supported-container specimen were not available in this run. | Raw EICAR is not misrepresented as a valid infected PDF/image; scanner failures remain fail-closed. |
| Informational | The combined worktree has uncommitted Phase 04/05 changes. | No broad commit was created. Review and checkpoint selectively before release. |

## Secret and data handling

The private testing environment contents were not printed or copied into reports. The guarded runner loaded the file only in explicit Phase 05 modes. No development or unrelated database was targeted by Phase 05 migration/testing. No compliance certification is claimed.
