# Phase 05 implementation report

**Review date:** 2026-10-24  
**Status:** Substantial SIS implementation present; acceptance remains blocked.  
**Scope:** Student Information System only. No Phase 06 implementation was started.

## Delivered

- Tenant-scoped student registry with institutional numbering, encrypted government identifiers, campus association, profile updates, lifecycle transitions and durable audit history.
- Configurable student label and registration sequence; number-format changes are locked after the first student record, and sequence changes/allocation serialize on the institution row.
- Existing identity linking validates an active membership in the same institution, enforces uniqueness and assigns the student role transactionally.
- Eligible students can be provisioned through a seven-day signed single-use invitation. Acceptance normalizes the email, creates or activates one tenant membership, assigns only the student role, links the SIS record transactionally, audits the invitation and provisioning, rejects replay, and refuses to rewrite a membership tied to a different institution. The real browser journey covers acceptance and Student 360 link display.
- Effective-dated placement history with class, section, academic year, period, program, campus and capacity validation. Preview tokens are bound to the submitted payload and session.
- Bulk promotion preview and confirmation validates eligibility and destination capacity, requires an explicit student selection, records each placement atomically, and consumes its session token once. The feature test and authenticated Playwright journey cover preview, capacity rejection, confirmation and replay denial.
- Promotion governance now supports institution-configured independent approval, reasoned student progression holds, persisted per-student decisions/outcomes, revalidation at approval time, and controlled history-preserving placement correction. Generic placement requests cannot bypass promotion or correction controls. Feature tests and a Chromium journey cover separate requester/reviewer identities, hold release, approval, and corrected history; MySQL locking behavior remains unverified.
- External incoming/outgoing transfer cases require a clean current certificate, independent review, effective-date completion and audited lifecycle/history changes. Backend feature tests cover acceptance, rejection, self-review denial and tenant isolation; the ClamAV-enabled browser journey completes an outgoing case. Cross-institution record sharing is not implemented.
- Guardian links remain pending until verified; verification does not implicitly grant profile access, and revocation preserves history while disabling access. Existing verified accounts can be linked across sibling relationships only when active membership, campus scope and verified contact email match. A verified unlinked guardian can receive a seven-day signed single-use invitation; acceptance assigns only the parent.guardian role, links the guardian identity transactionally, records audit evidence and leaves student profile access disabled.
- Tenant-scoped family groups with editable labels and relationship details, audit history, explicit verification basis, safe reactivation of ended memberships, permission-gated/rate-limited name and student-number search, and institution/campus scoping. Membership alone does not grant sibling profile access.
- Transactional, idempotent approved-admission conversion checks the accepted offer and approval, records provenance, assigns the student role, copies verified guardians with access disabled, and references only clean/current private admission documents.
- Student profile surfaces identity, placement history, lifecycle events, guardians, family references, custom attributes, authorized document references, and a controlled identity-correction request/history flow with independent review.
- Private SIS document upload/replacement with PDF/JPEG/PNG signature checks, bounded size, private storage, version history, quarantine and scan events. Only the current clean version can be downloaded through an authorized tenant route. ClamAV tests cover clean and EICAR outcomes and fail-closed behavior.
- Institution-configured retention for new SIS private uploads. Each immutable version stores its expiry; expired versions lose their download link, are denied at the download endpoint, and cannot be used as transfer evidence. Expired files remain private; no automatic deletion or retroactive expiry is applied.
- Institution-defined custom fields with type/option validation and encryption for sensitive values.
- Paginated registry search, filters, controlled sorting, bounded CSV import preview/confirmation and formula-neutralized CSV export. Directory filters include current academic year, program, level, class, section and nonsensitive custom-field values, alongside campus, type and lifecycle status.
- CSV import auto-suggests recognized headings and supports explicit external-column mapping. Confirmed uploads are privately stored and dispatched to a bounded queue job; tenant/membership/permission is revalidated at execution, source rows are idempotent, partial success and downloadable permission-gated error CSV are recorded. Processing remains capped at 500 rows / 2 MiB; the browser now verifies the queued batch reaches persisted completion.
- Institution-scoped dashboard counters and recent records support admission date-window and current academic-year filters. Live distributions show students by home campus and current program; transfer statuses, promotion records, and guardian pending/verified/revoked counts use the selected scoped student set and event date window. Feature assertions cover campus and guardian aggregates, empty program/transfer/promotion results, and the browser journey confirms the analytics section renders. MySQL query compatibility remains unverified.
- The guarded test runner has a distinct Phase 05 read-only preflight and MySQL execution mode, private `.env.phase05.testing` loading, separate advisory/local locks, dedicated ownership-manifest path, and exact Phase 05 target authorization. The latest read-only preflight exits 1 with sanitized MySQL SQLSTATE 1045 before authentication; it has not migrated or written a manifest.

## Routes and permissions

Routes are under `/t/{tenant}/sis` and inherit tenant context, academic scope, the `student_information` feature gate and permission middleware. `php artisan route:list --path=sis --except-vendor` listed **40 routes**. External transfer request/review/completion permissions are separate; family search is rate-limited and requires family-management permission; edits, links, identity invitations, and verification are server-side authorized. Upload and download permissions are separate. Promotion decisions and hold operations use dedicated server-side permission checks.

## Database changes

- `2026_10_22_000011_add_tenant_key_to_academic_sections.php` adds a tenant-aware academic section parent key for composite references.
- `2026_10_23_000000_create_sis_student_core.php` creates the SIS registry, student, lifecycle, placement, guardian, family, custom-field, reference-document and import-batch tables with tenant ownership and constraints.
- `2026_10_23_000001_enable_sis_for_existing_tenants.php` adds SIS permission templates and enables the feature for existing tenants.
- `2026_10_23_000002_create_sis_private_document_tables.php` creates private document, version and scan-event tables.
- `2026_10_23_000003_enable_sis_private_document_management.php` adds the private-document permission/configuration support.
- `2026_10_23_000004_create_sis_student_transfer_cases.php` adds tenant-scoped incoming/outgoing transfer cases, evidence references, reviewer/completion provenance, composite ownership constraints, indexes and least-privilege role grants.
- `2026_10_23_000005_add_queued_student_import_tracking.php` adds tenant-scoped batch state and source-row provenance for idempotent asynchronous imports.
- `2026_10_23_000006_add_sis_document_retention_policy.php` adds the institution retention setting and version expiry metadata with an expiry lookup index.
- `2026_10_23_000007_link_tenant_invitations_to_sis_students.php` associates a tenant invitation with exactly one SIS student record so account acceptance can complete the identity link transactionally.
- `2026_10_23_000008_link_tenant_invitations_to_sis_guardians.php` adds a composite tenant-scoped guardian reference to invitations; invitation acceptance links the verified guardian record without granting SIS profile access.
- `2026_10_24_000001_create_sis_student_promotion_holds.php` adds reasoned, tenant/institution/student-scoped promotion holds, approval policy storage, and dedicated hold/approval/correction permissions.
- `2026_10_24_000002_create_sis_student_promotion_batches.php` adds tenant/institution-scoped promotion request and per-student outcome records with reviewer provenance and composite tenant constraints.

The guarded SQLite test suite exercised migration behavior. No MySQL migration was executed, and no development, Phase 01/04, or unrelated database was migrated or reset. Playwright used its separately marked SQLite E2E database.

## Verification snapshot

Latest local results are in `PHASE_05_TEST_REPORT.md`: guarded SQLite 159 tests / 1,502 assertions / 3 MySQL-only skips; Vitest 6; Chromium 22/22; PHPStan, Pint, PHP syntax (254 files), TypeScript, ESLint, Prettier, Composer validation/platform/audit, npm audit and production build passed. MySQL authentication remains blocked; hosted CI is not verified.

## Remaining acceptance gaps

This is not complete Phase 05 acceptance. MySQL integration and locking/concurrency are blocked by the failed authenticated preflight for the dedicated test account. Identity, grants, schema ownership and migration state are unverified. Guardian-facing profile access remains intentionally disabled pending a separately approved authority policy; streaming imports above current bounds, retention archive/purge, institution-wide lifecycle approval/outbox behavior, and broader SIS accessibility remain incomplete or unverified. Promotion approval/holds/correction are implemented and locally tested. Student identity reconciliation and policy-controlled account suspension/reactivation are also implemented and tested. See the requirement traceability and final gate reports.

The worktree was already dirty with Phase 04 changes before this work. Those changes were preserved; no commit was made. Review the complete diff before release.


## Latest verification update — 2026-10-23

The browser suite now includes verified guardian identity invitation acceptance and confirms that the new account receives only the `parent.guardian` role and cannot open the student profile (HTTP 403). The latest guarded SQLite run passed 153 tests / 1,402 assertions with three MySQL-only skips. The latest serial Playwright run passed 22/22 in 2.2 minutes. PHP syntax passed for 245 files; the SIS route inventory contains 34 routes. Local MySQL preflight still exits 1 with SQLSTATE HY000 / driver 1045 before identity or ownership checks, and no Phase 05 database changes were made.

## Dashboard analytics implementation — 2026-10-23

The SIS overview now displays real campus and current-program distributions plus transfer status, promotion, and guardian authority counts. Aggregates are constrained to the institution, the request-visible student set, campus scope, the selected academic year, and the selected admission/event date window. Empty states are explicit. SQLite feature tests assert campus and guardian values and zero transfer/promotion rows; the browser journey asserts the analytics heading.
## Current implementation and regression update - 2026-10-23

The additive migration 2026_10_23_000009_add_sis_student_account_suspension_policy.php adds the configurable institution policy and membership provenance. Policy suspension is restricted to a membership with exactly the student role. Multi-role staff access remains active; reactivation is permitted only when identity, role set, policy provenance and non-revoked state still match. Manual membership revocation is preserved. Decisions are audited and feature-tested.

SIS browser accessibility coverage now includes axe on overview, directory and Student 360, plus a 390px profile overflow check. The full valid lifecycle browser path passed. Current full SQLite evidence is 153 tests / 1,402 assertions / 3 MySQL-only skips; current Chromium evidence is 22/22 in 2.2 minutes. MySQL authentication remains blocked by sanitized SQLSTATE 1045; the Phase 05 test target was not modified.
## Current implementation and verification update - 2026-10-24

The SIS now includes an independently reviewed student identity reconciliation workflow. Requests bind the current and proposed identities; approval requires a different authorized reviewer, revalidates the proposed active institution membership and student link, and applies role/link changes transactionally with audit history. Stale and self-approval attempts are rejected. SQLite feature tests cover successful role reconciliation, stale proposals, self-review denial, already-linked targets, and preservation of nonstudent roles. Playwright completed the request and independent approval flow and verified the linked account change and old student membership access denial.

The repository contains 26 migration files, including `2026_10_24_000000_create_sis_identity_reconciliation_cases.php`. The full guarded SQLite suite passed 156 tests / 1,444 assertions with 3 MySQL-only skips; Chromium passed 22/22 in 5.7 minutes. The async E2E launcher now kills the Windows server process tree, and the import browser flow waits for the queued batch's stored completed state.

The latest Phase 05 MySQL read-only preflight still exits 1 with SQLSTATE HY000 / driver 1045 and reports no database changes. MySQL constraints, identity, grants, migration history and concurrency remain unverified. Phase 05 acceptance is blocked; Phase 06 was not started.
# Phase 05 implementation report

## Final completion update — 2026-10-24

The Phase 05 continuation implemented the remaining requested operations on top of the existing SIS:

- Streaming CSV parsing and bounded previews; row errors persist separately; confirmation processes 250-row transactions and is idempotent through source-row provenance.
- Lifecycle policy settings can require independent review. Request and decision actors, outcomes, effective dates and audit history persist.
- SIS retention processes only eligible clean current document versions, checksums private archive copies, and supports policy-controlled purge with safe post-commit cleanup.
- The admissions outbox claims rows with leases, retries bounded failures with backoff, stores safe error codes and stops at its configured attempt limit.
- Accessibility regression coverage now includes Axe on five views, keyboard workflows, and narrow-layout checks on directory/profile.

Final verification: guarded MySQL 169 tests / 1,603 assertions; guarded SQLite 166 / 1,562 with 3 MySQL-only skips; Vitest 6; Chromium 22/22; PHPStan, Pint, PHP syntax (256 files), TypeScript, ESLint, Prettier, Composer validation/platform/audit, npm audit and Vite production build all passed. See the final gate and test reports for exact commands and limits.

No Phase 06 functionality was implemented.
