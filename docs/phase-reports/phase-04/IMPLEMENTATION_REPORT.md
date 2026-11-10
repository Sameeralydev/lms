# Phase 04 implementation report — recovery update

> **Verification update (2026-10-23):** the private Phase 04 MySQL test configuration is present and ignored, and read-only identity/empty-schema checks pass. The active account still has schema `ALL PRIVILEGES`, so the runner correctly stops before migration. The scanner remains unavailable and fail-closed. Phase 04 is not complete.

## Status

Phase 04 work has continued and the verified local application workflows are materially broader. The final phase gate remains **BLOCKED** because the authenticated dedicated test account has a schema-wide overgrant preventing the guarded MySQL integration suite, and no real malware scanner is installed. The owner reports the previously exposed credential was rotated; the current private config authenticates without secrets being displayed. Phase 05 was not started.

## Implemented and repaired in this continuation

- Added a guardian authorization lifecycle with app- and tenant-scoped records, hashed single-use invitations, verified separate guardian email, institution relationship verification, read-only guardian application access, auditing, minor submission enforcement, and revocation. No external identity provider is claimed.
- Fixed applicant mutation redirects so draft save, submit, guardian, and document actions return to the application view rather than losing the application workflow page.
- Completed tenant-scoped admissions inquiry status management and auditing.
- Added/finished visual admissions form editing, field preview, and publication flow.
- Added FIFO waitlist position data and require a new independently approved recommendation for waitlist promotion. A promoted application stays waitlisted until an offer is separately issued.
- Expanded cross-tenant authorization feature coverage for guardian/application boundaries.
- Added a real browser guardian journey: the minor's submit is blocked while authority is incomplete; a separate verified guardian accepts a scoped invitation; an institution administrator verifies the relationship; the applicant then submits; guardian read-only access is checked against cross-tenant access and revocation. The same suite covers intake administration and independent staff review/decision/offer/accept/handoff.
- Added a tightly gated local E2E invitation-link response header for test delivery only (testing environment, E2E mode, array mailer, loopback address), with `no-store`; the production invitation route does not expose the bearer link in a response header.
- Isolated persistent browser-test cache files by Playwright process and added configurable file-cache paths; the application default path remains unchanged.
- Corrected workspace guardian status copy so it reflects active relationships, and exposed validation errors in the editable applicant form where present.
- The new real browser review journey exposed a controller route signature defect: Laravel supplied the tenant route model where the numeric application ID was expected. `AdmissionsController::showApplication` now accepts the tenant argument first. PHPStan, the focused admissions suite, and the full Playwright suite passed after the fix.
- Confirmed document quarantine remains fail-closed. A local scanner executable is not available, so no document has been represented as malware-scanned by real infrastructure.

## Current verification

Latest regression evidence supersedes the older counts below: SQLite **92 tests / 764 assertions**, admissions-focused SQLite **14 / 202**, grant-policy regression **7 / 12**, Vitest **6**, and Playwright Chromium **20 passed**. PHPStan, Composer validation/platform/audit, Pint, TypeScript, ESLint, Prettier, npm audit, production build, and `git diff --check` passed. See the 2026-10-23 section in `TEST_REPORT.md` for commands and exit codes. MySQL suite exited 2 at the least-privilege guard; it did not migrate.

The guarded MySQL runner reaches the authenticated preflight and exits 2 at grant validation because the effective account has schema-scoped `ALL PRIVILEGES`. Read-only identity and empty-schema checks pass; no MySQL migration or MySQL integration test ran. See the 2026-10-23 current verification section above and `TEST_REPORT.md` for complete evidence.

## Changed files

Application code changed in the admissions workflow/domain, guardian authorization result/model/workflow/migration/email/page, applicant and workspace controllers, inquiry model, permission catalog, cache path configuration, admissions routes, and admissions React pages. Tests changed in `tests/Feature/AdmissionsFoundationTest.php` and the Playwright fixture/spec/config files. `.gitignore` now excludes per-process browser-test cache directories; Phase 04 reports were refreshed. Detailed names are available from `git status --short` in the project worktree. No `.env`, `.env.testing`, or `.env.phase04.testing` value is included here.

## Deferred or externally dependent

- DBA: replace the effective account's schema-scoped `ALL PRIVILEGES` with exactly the documented 11 test privileges; then pass the unchanged guarded preflight and MySQL integration.
- Install/configure and exercise an authorized malware scanner with clean, malicious, unavailable, and stale-version fixtures.
- Configure a production-grade email provider and validate delivery/retry behavior. The isolated browser journey uses pre-verified synthetic email accounts and does not establish production email or external identity-provider readiness.
- Execute hosted CI when a runner is available.

These are explicitly represented in `REQUIREMENT_TRACEABILITY.md` and `FINAL_GATE_REPORT.md`. No Phase 05 module or student record lifecycle was implemented.
