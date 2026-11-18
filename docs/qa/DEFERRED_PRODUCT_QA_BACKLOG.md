# Deferred Product QA Backlog

Last updated: 2026-11-18

This backlog separates implementation work from verification that needs external infrastructure or a broader acceptance campaign. Each listed item uses the requested exact status.

## QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 15 guarded MySQL | Fresh guarded preflight, migrations/ownership postflight, focused Phase 15 MySQL suite, and analytics scheduling concurrency checks | The pinned MySQL endpoint was unavailable in the most recent recorded preflight. When the owner-provisioned Phase 15 endpoint is available, use `php tests/run-phase15-mysql.php --preflight` then its guarded `--run`; do not substitute a direct PHPUnit database run. |
| ClamAV/EICAR | Intact standard EICAR signature detection through each quarantine-integrated upload workflow | Windows endpoint protection intercepts or modifies the test signature before ClamAV receives it. Preserve 48-hour signature freshness and fail-closed quarantine; provide an owner-approved isolated scanner test runtime. |
| Broad browser acceptance | Full authenticated Phase 15 cross-role, screen, and six-theme action matrix after implementation integration | Existing dashboard and examination matrices are scoped evidence, not a complete Phase 01–15 screen/action matrix. Run the complete approved matrix in the browser environment after the implementation branches are integrated. |
| Whole-product regression | Full SQLite suite and full scanner-enabled Chromium suite | Historical full-suite failures include six intercepted EICAR cases, and the last broad browser runs were not a fresh all-green comprehensive campaign. This continuation intentionally ran only focused Phase 15 checks. |
| Hosted CI | Hosted workflow execution | No completed hosted run is evidenced in this continuation. Run after the repository integration checkpoint is prepared. |
| Manual assistive technology | Screen-reader and assistive-technology review | Automated accessibility checks are not a substitute for human assistive-technology testing. |
| External email delivery | Email delivery of scheduled reports | Scheduled reports currently notify authorized members through Laravel database notifications and private report downloads. Configure and verify an institution-approved mail provider before claiming email delivery. |

## Not deferred as implementation

The Phase 15 report-source catalog, drill-down, PDF generation, schedule management, queued generation, internal notifications, and role-aware dashboard foundations are implemented as recorded in the Phase 15 continuation addendum. Missing data owned by later finance or other absent modules is not represented as an operational source; integration points remain dependent on those modules.

## Phase 17 billing verification — QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 17 MySQL | Verify dedicated Phase 17 target identity/ownership and execute guarded migration plus MySQL integration tests | No Phase 17 guarded MySQL preflight or migration was executed in the billing implementation continuation. Use the established guarded runner only after its target is configured and verified; do not run direct database-mutating tests. |
| Phase 17 financial database behavior | Validate MySQL decimal aggregation, unique invoice constraints, transactions and concurrent duplicate issue | SQLite focused feature tests passed, but MySQL-specific execution and concurrency probes were not run. |
| Phase 17 browser workflows | Authenticated billing staff, learner and guardian workflows, responsive screens, PDF display and accessibility | Focused backend tests and frontend compilation do not replace browser verification. Run when the integrated product browser environment is available. |
| Phase 17 product regression | Full SQLite, complete Playwright, cross-phase regression and broad authorization matrix | This continuation ran the focused Phase 17 SQLite filter only; comprehensive product regression remains a later QA campaign. |

These QA entries do not defer missing implementation work. Phase 17 concessions, installments, ledger, bulk/recurring billing, adjustments, notifications, and broader reporting remain implementation gaps in `docs/phase-reports/phase-17/PHASE_17_REQUIREMENT_TRACEABILITY.md`.

## Phase 18 payments and collections — QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 18 guarded MySQL | Configure/authorize a dedicated Phase 18 disposable target and ownership guard, then run migration preflight, MySQL integration, postflight and concurrency checks | No Phase 18 MySQL target/ownership runner was verified in this continuation. SQLite focused tests are not MySQL evidence. Never reuse development or prior-phase test schemas. |
| Payment concurrency | Duplicate collection, concurrent invoice allocation, bank verification retry, over-refund reservation and simultaneous refund execution | SQLite focused suite verifies transactional outcomes sequentially; independent MySQL sessions and real locks were not tested. |
| External gateway | Sandbox initiation, signed callbacks, replay/out-of-order events, currency/amount validation and provider refunds | No provider is approved or configured. Owner must select provider and approve its contract before adapter or sandbox verification is claimed. |
| External settlement/reconciliation | Bank statement import, matching, unmatched/duplicate exceptions, approval, close/reopen and audit history | Current page reconciles internal records by date/campus and exports CSV. It does not ingest or verify an external statement or settlement. Approved source format and financial policies are required. |
| Refund payout | Validate execution through an approved payout rail/process and reconcile payout reference | Current workflow records a manual payout reference after an independently authorized operator acts; no bank/provider disbursement is performed. |
| Browser and accessibility | Authenticated cashier/finance/learner/guardian journeys; responsive, keyboard, accessibility and six-theme matrix | Only focused backend SQLite verification was run after the latest changes. |
| Multi-currency/MySQL finance | Validate decimal precision, currency segregation and aggregated totals under MySQL at realistic data volumes | Integer minor-unit workflow calculations are implemented; no MySQL-specific aggregation/concurrency evidence exists. |
| Full regression and external notifications | Full product SQLite/Chromium regression, email/SMS delivery, performance and security campaign | Development-first policy; no external credentials or broad campaign were used in this continuation. |

Focused Phase 18 SQLite result on 2026-11-18: 6 tests / 134 assertions passed. This does not close the above pending verification or implementation items.

## Phase 17 continuation — latest evidence and remaining QA

**QA PENDING — EXTERNAL ENVIRONMENT**

- Guarded Phase 17 MySQL identity/ownership preflight, migrations, postflight and integration execution were not run in this continuation. Do not apply the new additive migrations to a persistent database outside the reviewed guarded runner.
- Full authenticated billing browser and responsive/accessibility journeys were not run. The Vite build and focused lint/type checks are not browser evidence.
- ClamAV/EICAR was not run. Do not disable quarantine or infer Phase 17 scan coverage from other phases.
- Full product regression was intentionally not run; only the focused Phase 17, enrollment, and Phase 18 compatibility suites ran.
- External email/SMS delivery remains unverified; implemented notifications use Laravel's database channel only.

Fresh test evidence: Phase 17 SQLite 9/266, course enrollment 9/190, and Phase 18 SQLite compatibility 6/140 all passed. Focused PHPStan, Pint, PHP syntax, TypeScript, ESLint, Prettier, production build, billing route registration and `git diff --check` passed. These results do not close the remaining functional and environmental work in the Phase 17 traceability report.

## Phase 17/18 latest focused verification (2026-11-18)

- Phase 17 SQLite: 10 tests / 268 assertions — PASS.
- Enrollment regression: 9 tests / 190 assertions — PASS.
- Phase 18 SQLite: 7 tests / 207 assertions — PASS.
- PHPStan/Pint/PHP syntax, TypeScript, ESLint, Prettier, Vite build, Composer validation/platform checks, billing route inventory and diff check — PASS.
- MySQL, full browser/accessibility, hosted CI, external payments/bank import, and full product regression — NOT RUN.

The ledger now includes current unapplied confirmed payment amounts as student credits; later allocation does not change the net statement. Payment reversals use independent request/review/execution and preserve source records. These latest results are focused development evidence only. They do not close implementation gaps listed in the phase traceability files.

---

## Developer B integration QA additions

# Deferred product QA backlog

Items in this file are **QA PENDING** unless evidence is explicitly recorded after execution. Deferred items are reserved for final product-wide verification and do not represent passed checks.

## Phase 20 — Library Management

- **QA PENDING** — Run the additive Phase 20 migration on a verified isolated MySQL schema after owner, server, schema, and existing-data checks. Do not use destructive refresh commands.
- **QA PENDING** — Authenticated browser workflows for catalog search/edit/archive, copy registration and adjustments, membership activation/suspension, checkout, return, renewal, hold queue, hold expiry/cancellation, member self-service, and all six themes.
- **QA PENDING** — MySQL concurrent checkout of one copy and concurrent checkouts against a single member loan limit; verify exactly one allowed transaction and consistent inventory.
- **QA PENDING** — Cross-tenant and cross-campus authorization matrix for every library read and mutation, including forged IDs, inactive memberships, expired memberships, and member self-service.
- **QA PENDING** — Complete accessibility review and screen-reader checks for the library workspace and mobile layouts.
- **QA PENDING** — Product-wide regression and performance review of catalog queries and large circulation histories.

## Phase 21 � SaaS and White-label Management

- **QA PENDING** � Apply the additive plan/subscription migration only after independently verifying an isolated, authorized Developer B database, its server, port, schema, and current records. Never use destructive migration commands.
- **QA PENDING** � Run authenticated platform browser workflows for plan creation/editing, assignment, pause/resume, plan-version immutability, tenant detail display, pagination, and platform permission denial.
- **QA PENDING** � Verify each configured feature route is blocked for a paused or non-entitled assignment and that legacy tenants without an assignment retain access; verify tenant membership and permission middleware still apply.
- **QA PENDING** � Run concurrent plan assignment and plan edit/assignment checks on MySQL and confirm assignment history and audit events stay consistent.
- **QA PENDING** � Complete the cross-tenant/campus adversarial matrix for all mapped module routes and confirm no route alias or custom-domain path bypasses entitlement checks.
- **QA PENDING** � Verify domain TXT challenge, primary custom-domain routing, branding image delivery, display-name/logo rendering, and all six themes on the production host configuration.
- **QA PENDING** - Run concurrent campus and staff creation/reactivation against configured limits on MySQL; confirm tenant row locking prevents over-allocation and historical records remain intact after plan changes.

## Phase 22 - Standards and Integrations

- **QA PENDING** - Apply the additive integration credential and external identifier migration only after independently verifying an authorized isolated Developer B schema, database server, port, schema name, and existing records.
- **QA PENDING** - Verify authenticated CASE 1.1 provider responses against the official CASE 1.1 JSON schemas and conformance suite; the implemented subset is read-only CFDocuments, CFItems, and CFPackages for published competency frameworks and does not claim certification.
- **QA PENDING** - Exercise token creation, one-time secret display, hashed-at-rest storage, expiry, scope denial, revocation, malformed bearer rejection, tenant binding, subscription entitlement enforcement, audit records, and route rate limiting through authenticated HTTP requests.
- **QA PENDING** - Run cross-tenant and suspended-tenant adversarial API requests and prove no framework, competency, external identifier, or audit metadata crosses tenant boundaries.
- **QA PENDING** - Verify persistent CASE sourcedId mappings under concurrent reads and ensure mappings do not leak between tenants.
- **QA PENDING** - Confirm published-only filtering and exact CASE payload mapping against representative competency-framework records; verify unknown and malformed identifiers return protocol-appropriate errors.

## Phase 23 - Security, Performance, Scalability and Hardening

- **QA PENDING** - Run the full authentication/session and authorization matrix, including role escalation attempts, platform-versus-tenant boundaries, password recovery, session fixation, and campus-scoped access.
- **QA PENDING** - Execute adversarial tenant-isolation tests across all module reads, writes, exports, integrations, queued jobs, document routes and tenant-specific caches; verify platform-wide operations remain explicitly authorized.
- **QA PENDING** - Verify request correlation IDs are server-generated, unique per request, returned in `X-Request-Id`, and persisted in audit events; confirm caller-supplied IDs cannot be used to spoof audit correlation.
- **QA PENDING** - Exercise integration API per-token and per-IP rate limits under valid, revoked, expired, malformed and distributed-source requests; verify throttling key cardinality remains bounded.
- **QA PENDING** - Validate CASE 1.1 `CFDocuments` pagination (`limit`, `offset`, `X-Total-Count`, and `Link`) against official schema/conformance tooling and representative large tenant catalogs.
- **QA PENDING** - Profile the CASE 1.1 list endpoint and other high-use dashboard/report queries with production-like data; record query counts, memory use, response sizes and p50/p95 before and after focused optimizations.
- **QA PENDING** - Execute focused concurrency checks for token last-use updates, external identifier creation, file scan state changes, imports, and other idempotent/retryable jobs.
- **QA PENDING** - Complete secure upload and authorization verification for each document workflow, including EICAR/ClamAV scanner behavior, quarantine transitions, stale signatures, unavailable scanner, and private download boundaries. Do not disable fail-closed behavior.
- **QA PENDING** - Review CSP, secure cookies, trusted proxies/hosts, HTTPS/HSTS, secret management and deployment configuration in each real deployment environment; local configuration review is not a production security certification.
- **QA PENDING** - Complete dependency vulnerability review, penetration testing, load/stress testing, complete PHPUnit/browser regression, accessibility certification, queue/cache outage testing, and disaster-recovery restore drills.

## Phase 16 communications

- **QA PENDING** — Review and execute the additive announcement migration only against an independently verified, isolated Developer B schema.
- **QA PENDING** — Verify tenant, campus, role, author and manager authorization for announcement reads, drafts, scheduled publication, archive, edits and read receipts, including forged identifiers and cross-tenant requests.
- **QA PENDING** — Verify recipient resolution, active membership, participant-only thread access, campus boundaries, unread state, and message/inbox transaction consistency under authenticated journeys.
- **QA PENDING** — Verify read-receipt idempotency, inbox pagination, and announcement/message journeys across supported themes and responsive layouts.
- **QA PENDING** — Verify scheduled publication command locking, retry/idempotency, audience selection, audit event creation, and due-time notification delivery on MySQL.

## Phase 16 and Phase 19 additions

- **QA PENDING** — Apply the two additive Nov 18 migrations only after confirming the authorized Developer B database server, port, schema and ownership; verify existing records and migration ordering before execution.
- **QA PENDING** — Authenticated communication preference checks for each supported role, event-specific opt-out, message/announcement history retention, recipient tenant isolation, and immediate/scheduled dispatch idempotency.
- **QA PENDING** — Staff attendance check-in/out and leave request/review/cancellation browser journeys, timezone/date-boundary behavior, tenant/campus authorization, duplicate clock operations, concurrent overlapping leave submissions, and audit attribution on a verified isolated MySQL database.
