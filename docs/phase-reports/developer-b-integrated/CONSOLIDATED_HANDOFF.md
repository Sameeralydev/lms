# Developer B Consolidated Handoff

## Git baseline

The integrated branch is based on cumulative Phase 23 commit `15bdc33` and preserves Phase 19 (`8d2868a`), Phase 20 (`a668eea`), Phase 21 (`c6a27cd`, `46d57cd`), Phase 22 (`a93d9fc`), and Phase 23 (`15bdc33`). Phase 16 recovery commit `2da6301`, based on `02497d2`, is merged as a separate history-preserving parent. The original recovery stash remains untouched.

## Phase 16 communication implementation

Existing role-specific portals and module notification feeds were retained. The recovered announcement module is extended with author drafts, future scheduling, publication, archive controls, role/campus targeting, read receipts, and an in-app inbox. A minute scheduler publishes due announcements and creates tenant-scoped inbox entries.

Direct messages use one-to-one tenant conversations. Participants must have active membership and the message-view permission; a campus-scoped sender can select only members in the same campus or institution-wide membership. Every read/write query is tenant and participant scoped. Message bodies are never copied into audit metadata. Inbox rows are generated in the same transaction as message writes, and announcements use idempotent recipient notification inserts.

No authoritative Phase 16 acceptance matrix was found. In-app announcement and direct-message preferences are now stored per tenant/user/event and enforced by immediate and scheduled in-app dispatch. External email/SMS/WhatsApp/push channels remain unimplemented because no approved provider, consent, retry or delivery-state contract or credentials exist. Existing role-scoped portals and assessment/assignment notification feeds remain separate and are not duplicated.


## Phase 19 configurable attendance and leave

The HR operations addition provides staff self-service daily clock-in/out using institution-local work dates, UTC clock timestamps converted for display, institution-configurable leave types, optional per-request calendar-day limits, configured approval or no-approval behavior, review/rejection, cancellation, overlap prevention, tenant/campus scoping, and audit events. It deliberately does not infer working days, accrued balances, statutory entitlement, shifts, payroll, compensation or tax rules. Recruitment/onboarding, document lifecycle, performance review and expanded HR reporting still need their approved workflow and privacy/business policy contracts.
## Phase 19–21 and 23 preservation

The original cumulative branch is retained, including the staff lifecycle, library catalog/circulation, tenant plans and entitlements, and Phase 23 request correlation/rate-limit/pagination changes. Permission templates, dashboard cards, tenant relationships, and navigation were merged additively.

## Phase 22 supported boundary

The repository's approved standards map names CASE 1.1 and records that CASE exchange support was missing. The integrated branch provides a tenant-bound, read-only subset for published `CFDocuments`, `CFItems`, and `CFPackages`, plus Phase 23 collection pagination. The available contract does not define CASE import/consumer writes, associations, or additional resource mappings; those remain scope dependencies pending an approved exchange contract. No other standard or formal conformance is claimed.

## Functional scope reconciliation

The detailed traceability and source references are in `REQUIREMENTS_COVERAGE.md`. That matrix distinguishes implemented workflows from QA-only verification and from functional scope dependencies. The available briefs do not provide formal requirement IDs or standalone acceptance matrices. Conditional payroll, billing, external message delivery, fines, digital-library, and other standards examples are not treated as approved until their business/provider contracts are defined. The Phase 22 CASE API is an implemented read-only subset, not complete CASE provider conformance; CASE import/consumer behavior remains a product-scope dependency because the repository does not define its source, mapping, persistence, conflict, or publication rules.

The application-side Phase 16 communication workflows include announcements, scheduled publication, direct messages, an in-app inbox, and personal in-app alert preferences for announcements and messages. External channels remain a product/provider dependency; live delivery is not claimed.


## Phase 24 integration contracts

- **Identity and tenant scope:** communication records address `users` within a `tenant_id`; HR operations anchor staff through the existing tenant membership and `hr_staff_records`; leave and attendance retain tenant and campus snapshots with same-tenant foreign keys.
- **Permissions:** add the new communication preference and HR operations keys through `PermissionCatalog`; migration grants are additive. `tenant.owner`/`tenant.admin` get the configured HR attendance/leave permissions, campus admins get attendance permission, and `hr.manager` gets both. Personal communication preferences are assigned to roles that can use the existing communication inbox.
- **Notifications and audit:** preferences affect in-app announcements and direct messages only; the inbox remains recipient-scoped. HR lifecycle operations audit actor, target, date range/status, and configured decision without copying private reasons into audit metadata.
- **Library, SaaS and CASE:** existing module contracts remain unchanged: library circulation uses tenant membership and library permissions; SaaS entitlements remain middleware/service enforced; the CASE endpoint remains a read-only scoped provider subset. No duplicate billing, identity, notification, or standards service was added.
- **Routes/navigation:** Phase 24 should preserve the new `tenant.hr.operations.*` routes and the HR operations navigation check alongside existing HR staff routes. No Developer A branch or shared integration branch was changed.
## Database and verification

Additive migrations cover announcements, conversation/message/inbox tables, HR, library, SaaS subscriptions, and CASE integration. No migration or database-changing command has been run; local database identity and authorization were not independently verified.

Focused verification executed for the remaining additions:

- `C:\tools\php84-bin\php.exe -l` on all 13 modified/new PHP files: passed.
- `C:\tools\php84-bin\php.exe artisan route:list --path=communications/notifications` and `--path=hr/operations`: passed; 3 communication and 8 HR routes registered.
- `npm.cmd run typecheck`: passed.
- `npx.cmd eslint` on the changed layout and notification/HR pages: passed.
- `npm.cmd run build`: passed (Vite production build).
- `vendor/bin/pint --test` on changed PHP files: passed.
- `git diff --check`: passed. Migration ordering, foreign keys, indexes, permission registration/grants, and additive-only behavior were reviewed statically.

No migration or data-changing command was executed because the active database/schema ownership has not been verified. These checks do not establish authenticated workflow, concurrency, or tenant-adversarial correctness; those remain **QA PENDING**. The local Composer autoloader was generated from committed dependencies without scripts; no temporary PSR-4 override was used. Product-wide adversarial tenant checks, authenticated browser workflows, concurrency and scheduler verification, CASE schema conformance, and comprehensive security/accessibility/performance tests remain **QA PENDING** in `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md`.

**The integrated code is preserved. Functional scope dependencies are listed in the coverage matrix; all remaining verification is separately marked QA PENDING. Do not interpret this handoff as CASE conformance certification or external-provider delivery verification.**
