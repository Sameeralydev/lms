# Phase 07 security audit

Verification date: 2026-10-27. This is a focused code and test review of the implemented slice, aligned to relevant OWASP ASVS concepts. It is not an ASVS assessment or certification.

Continuation security review: added template copying and live-session operations were reviewed for tenant membership, institution/workspace scope, permission checks, immutable template checksums, draft-only destinations, HTTPS meeting URLs, private media path/hash validation, rescan before copied assets are reusable, and audit writes. Focused tests passed on MySQL and SQLite; current full guarded MySQL passed 226 / 2,571, SQLite passed 220 / 2,481, and scanner-enabled Chromium passed 24/24. The required-reading acknowledgement test verifies false acknowledgement rejection and completion gating. One scanner execution failure occurred during a concurrently run SQLite+MySQL suite; isolated scanner and standalone full MySQL reruns passed. The failed run did not advance ownership evidence, and read-only database preflight remained clean.

Latest sprint recheck: release dependency validation is workspace-scoped, cycles are refused, and learner access validates prerequisite completion against current published revision IDs. Real ClamAV signature metadata passed the configured freshness rule; the guarded MySQL suite exercised clean learning-media approval and EICAR rejection, and the scanner-enabled browser suite completed its real clean-scanned SIS document journey. An earlier stale-signature result was resolved by the local signature update and is superseded by this evidence.

## Evidence reviewed

- Tenant-scoped CourseDeliveryWorkflow, CourseDeliveryMediaWorkflow, controller and route authorization.
- Private local filesystem configuration, immutable lesson snapshots and media download authorization.
- Phase 07 MySQL grant/ownership preflight and full guarded MySQL tests.
- Full guarded MySQL suite, current: 226 passed / 1 intentional skip / 2,571 assertions, including actual ClamAV clean/EICAR checks across admissions, SIS and learning media.
- Full SQLite suite, current: 220 passed / 7 expected skips / 2,481 assertions.
- Standard browser run: 23 passed / 1 scanner-gated skip. Scanner-enabled browser run: 24 passed / 0 skipped, including the real scanner-dependent SIS transfer journey and new analytics accessibility journey.
- PHPStan, Pint, TypeScript, ESLint, dependency audits and production build.

## Implemented controls evidenced

- Learner and authoring paths require authenticated tenant context, permissions, membership and explicit workspace assignment; tenant ID alone is not trusted for access.
- Published content is read from immutable revision snapshots. Direct learner routes recheck assignment, current published revision, release policy and progression.
- Media upload is size-limited and MIME/content checked, stored in private quarantine, and scanned through the accepted ClamAV adapter. Missing/unavailable/unfresh scanner results fail closed. Only clean assets can be published or served.
- Media delivery is bound to the published snapshot, tenant/workspace/lesson asset identity and learner authorization; path resolution is checked beneath private storage. Responses set nosniff, private/no-store, same-origin and sandbox-related headers.
- Template adoption is restricted to the same tenant and explicitly shared destination institution; copy targets must be managed draft workspaces. Snapshot checksum is verified and media is copied to a distinct tenant-owned private path, content-hash checked, then rescanned before the destination can use it.
- Session create/edit/cancel operations are tenant/workspace-scoped, validate assigned instructor and manager/teach permission, reject campus mismatch, validate HTTPS meeting URLs, persist timezone-normalized UTC instants, and write audit events. Learner sessions are restricted to published workspaces; cancelled sessions do not expose join links.
- Unsafe private paths, unsupported media types, unscanned and rejected media are not served. EICAR is rejected in the real scanner integration test.
- Lesson publication requires an independently approved revision and publish permission. Optimistic lock versions reject stale author edits; completion is idempotent and scoped to member and lesson.
- Required activity completion includes the `reading_acknowledgement` content block; false acknowledgement is rejected, completion remains blocked until a submitted acknowledgement exists, and current-revision gating is server-enforced.
- Structured content is finite and React output encoding is used; no arbitrary HTML rendering is introduced.
- Guarded MySQL preflight authenticated the dedicated test account/schema and verified grants/ownership. Current read-only postflight reports 135 schema objects and 40 migrations, with the ownership manifest and grant policy verified.

## Findings

| Severity | Finding | Required action |
|---|---|---|
| High release blocker (capability gap) | Learning media has real backend clean/EICAR and clean-only delivery coverage, but lacks browser-level learning-media clean/EICAR and failure-state verification; asset replacement/versioning, retention and archive/purge are missing. | Complete lifecycle and Playwright/test the actual upload-to-publish-to-authorized-delivery path. |
| High release blocker (capability gap) | Learner responses, required reading acknowledgement, revision-aware rollups and instructor confirmation are implemented, but policy-combination matrix, revision-history semantics, concurrent completion, retention execution and full authorization/browser coverage remain incomplete. | Complete activity concurrency, retention, revision-history and adversarial browser coverage. |
| High release blocker (capability gap) | Templates/copy/rollover and live-session lifecycle/calendar are implemented, but cross-institution adoption/concurrency rollback and full role/accessibility browser coverage are incomplete; analytics/quality lacks full release/engagement and broken-link coverage. | Complete missing feature-specific verification and analytics breadth before release. |
| Medium | Lesson/module prerequisites, cycle rejection, prior-module locking and enrollment-relative drip checks are now implemented, but institution-local time conversion, release exceptions and their approval/expiry/revocation workflow are absent. | Complete the exception lifecycle and timezone semantics; add concurrency and browser tests. |
| Medium | Hosted CI and production operational settings have not been verified. | Add/run guarded Phase 07 CI job and production configuration review. |

No known critical implementation vulnerability was found in the reviewed paths and executed tests. This statement is bounded to the reviewed slice and is not a declaration that the whole Phase 07 feature set is secure or complete. The capability gaps above prevent release acceptance. No compliance certification is claimed.

## Residual security verification

The standard browser run skips the optional SIS scanner journey by design when E2E_CLAMAV_ENABLED is absent. The scanner-enabled run passed all 24 journeys, including that scan. The guarded MySQL suite passed the learning-media clean/EICAR cases using current signature metadata. The adapter's unit suite covers timeout, process failure, unavailable/stale signature and unsupported content behavior. Never release an unscanned document.

## Closure security review: learner activity responses

Activity responses are stored through Laravel encrypted-array casting, are scoped through tenant/member/workspace/lesson/revision/activity composite constraints, and are not written into audit metadata. Idempotency is tenant membership scoped. Completion requirements are enforced by the workflow on the server, and instructor confirmation requires the confirmation capability, current revision and a different authorized actor. The focused MySQL feature suite verifies encrypted persistence, idempotency, required activity validation, tenant assignment checks and self-confirmation rejection.

Residual review items: expand authorization coverage for every activity response operation, test response history retention execution and current-revision invalidation, verify concurrent confirmation semantics, and test browser announcement/accessibility behavior. No compliance certification is claimed.

## Rapid recovery security review — 2026-10-27

The updated implementation was reviewed against the current Phase 07 security boundaries:

- Post-publication assignment controls remain gated by the workspace manager authorization computed server-side. Tenant-wide memberships may be considered for a campus workspace only where the existing assignment workflow permits null-campus membership; an explicitly different campus remains excluded.
- Release exceptions remain scoped to tenant, workspace, lesson and active learner assignment. Requests require a reason and a maximum 30-day expiry; approval is independent of the requester; revocation is reasoned and audited; expired/revoked exceptions do not grant access. Access decisions are audit-recorded. Feature and browser checks exercise approval and learner access; comprehensive endpoint/concurrency authorization matrices remain open.
- ClamAV subprocesses retain explicit per-process timeouts and fail-closed result handling. Web request execution is extended only to the configured process timeout plus five seconds, and scanner timeouts remain capped by existing configuration validation. Real clean/EICAR MySQL tests and scanner-enabled browser replacement/copy-rescan journeys passed. The scanner is not allowed to promote unavailable, timed-out, stale, rejected or unsupported files.
- Existing private-storage paths and tenant checks were not relaxed. No secret values were added to source, reports or logs.

No critical or high-severity issue was identified in the changes reviewed in this recovery. Residual Phase 07 risks remain in concurrent completion semantics, the full media archive/purge and access-revocation matrix, template cross-institution and concurrent-copy verification, complete learning-route accessibility, and full endpoint authorization coverage. Hosted CI was not executed. This is an implementation review, not an ASVS certification or production security certification.



## Final QA closure security update — 2026-10-27

Review scope includes the current completion/revision workflow, lesson release and exception authorization, media retention, analytics quality validation, template-copy rollback, live-session operations, and the changed browser journey. This is a scoped ASVS-aligned review, not a certification.

- Current guarded MySQL regression: 228 passed / 1 intentional skip / 2,743 assertions; SQLite: 222 passed / 7 expected skips / 2,653 assertions. Real ClamAV clean/EICAR MySQL checks passed. The final focused SQLite delivery suite reports 11 passed / 1 expected scanner-not-configured skip / 568 assertions.
- Final scanner-enabled Chromium run: 24 passed; last-run JSON status is `passed`, with an empty failed-tests list. It includes role-scoped session visibility/state and learning-route responsive/keyboard/axe smoke coverage.
- Final read-only Phase 07 database preflight: exit 0, dedicated Phase 07 schema/account, MySQL 8.4.11, 136 objects / 41 migrations; no ownership manifest or schema/data change.
- PHPStan, Pint, TypeScript, ESLint, Prettier, Composer/npm audits, and production build passed; `git diff --check` passed.

No critical or high severity issue was found in the reviewed changes. Remaining verification risk is material: there are no deterministic concurrent Phase 07 worker tests for completion/confirmation, release transitions, template copying or session transitions. Cross-tenant template access is denied, but legitimate sharing between two institutions in one tenant cannot currently be tested because the schema enforces one institution per tenant. Static broken-link findings avoid fetching arbitrary URLs; remote URL reachability is not claimed. Feature-specific authorization and accessibility matrices are incomplete. Hosted CI did not run. These acceptance gaps keep the release blocked; they are not security sign-offs or accepted waivers.

## Final acceptance recovery security update — 2026-10-27

Latest security-relevant evidence: MySQL and SQLite complete suites passed; guarded MySQL preflight remains bound to the Phase 07 test schema with 136 objects / 41 migrations. Real scanner clean/EICAR tests passed. The scanner-enabled browser suite passed 24 tests. PHPStan, Pint, TypeScript, ESLint, Prettier, dependency audits and production build passed. The hosted workflow was inspected: it uses isolated MySQL 8.4 and requires PHASE07_MYSQL_ROOT_PASSWORD and PHASE07_MYSQL_PASSWORD; it was not executed because this checkout has no configured remote.

Axe coverage added to learning builder, media authoring, learner lesson/progress, session calendar, templates and analytics. All six themes are verified on learning analytics. Testing exposed insufficient muted-text contrast and fixed amber status contrast on dark theme; semantic muted and warning tokens were darkened/lightened by theme, and the full scanner-enabled browser suite passed after rebuild. No critical or high severity vulnerability was identified in this scoped review.

Residual security verification: the full route-by-route authorization matrix and every route/theme combination are not complete; true competing-worker operations are not tested for course completion, release policy, copy, and live-session transitions. Existing lock/transaction code and sequential conflict assertions do not prove concurrency safety. Tenant isolation representative negative tests passed. No manual screen-reader verification or security certification is claimed.

## Final recovery review — 2026-10-27

Post-change verification: guarded MySQL 228 passed / 1 intentional skip / 2,775 assertions; SQLite 222 passed / 7 expected skips / 2,685 assertions; scanner-enabled Chromium 24/24; PHPStan, Pint, typecheck, lint, formatting, dependency audits and production build passed. Read-only Phase 07 preflight verified MySQL 8.4.11, the dedicated test schema/account, 136 schema objects and 41 migrations; it reports no database or ownership-manifest change. No credential values were included in reports.

The template-copy service now locks the destination row in the transaction before checking the idempotency record. Sequential replay/conflicting-key and rollback tests pass. The service and workflow tests also exercise tenant boundaries, campus scope, independent review/publish roles, revoked membership, media scan/download restrictions, release-exception approval/revocation, and learner/instructor completion. The scoped review found no known critical or high-severity security defect in the exercised code paths. This is not an ASVS certification.

Unresolved assurance: the five requested two-worker MySQL races remain untested; route coverage is representative rather than an independent negative test for every one of the 39 learning routes; axe checks cover the learning builder, media, learner progress, sessions, templates and analytics paths, with six theme scans on analytics, not every route/theme pair. Manual screen-reader testing and hosted CI are NOT RUN. The one-institution-per-tenant invariant remains unchanged; cross-institution same-tenant behavior needs an architecture decision. These are acceptance gaps, not waived controls.

## Critical gate closure update — 2026-10-28

The earlier residual findings above are historical and superseded for local Phase 07 acceptance by this verification. Guarded MySQL passed 229 tests / 2,941 assertions plus five independent-session race probes; SQLite passed 223 / 2,851; scanner-enabled Playwright passed 24. Route-negative coverage is generated from all 39 registered learning routes, with applicable guest, nonmember, revoked, wrong-tenant, forged-resource and wrong-role cases, wrong-campus checks for workspace-scoped reads, and a no-mutation assertion. MySQL preflight verified the dedicated Phase 07 test schema/account, 136 objects / 41 migrations, and unchanged ownership manifest. No secrets were included.

The races assert final persisted state and audit invariants rather than relying on sequential conflicts. Same-institution cross-campus template use is allowed only where authorization permits; cross-tenant access is denied. The one-institution-per-tenant rule remains locked, so cross-institution sharing within a tenant is an explicit architecture exception and is not claimed as implemented. No critical or high-severity defect was found in the exercised Phase 07 scope. This scoped review is not an ASVS certification. Hosted CI and manual assistive-technology review remain NOT RUN.

## Latest security verification — 2026-10-28

The current guarded MySQL baseline reported 230 passed / 1 intentional skip / 2,945 assertions and all five independent-session Phase 07 races passed. The MySQL postflight verified the dedicated Phase 07 target at 136 objects / 41 migrations. SQLite reported 227 passed / 7 expected skips / 2,913 assertions. Scanner-enabled Chromium passed 25 tests; all 39 learning routes were covered by applicable negative authorization cases with no business/audit row-count mutation. No critical or high-severity issue was identified in the exercised scope; this is not an ASVS certification.

The Phase 07 inventory cutoff is target-specific and ends at the reviewed Phase 07 release-exception migration. Later enrollment migrations are excluded from Phase 07 ownership inventory and remain unapplied to its schema. The PHPUnit phase baseline excludes only the explicitly tagged later-phase enrollment tests. This keeps the test schema and test selection aligned without weakening exact ownership, route authorization, grant, or tenant-isolation checks. Hosted CI and manual assistive-technology review remain NOT RUN.
