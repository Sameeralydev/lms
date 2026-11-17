# Deferred product QA backlog

Items in this file are **QA PENDING** unless evidence is explicitly recorded after execution. Deferred items are reserved for final product-wide verification and do not represent passed checks.

## Phase 20 â€” Library Management

- **QA PENDING** â€” Run the additive Phase 20 migration on a verified isolated MySQL schema after owner, server, schema, and existing-data checks. Do not use destructive refresh commands.
- **QA PENDING** â€” Authenticated browser workflows for catalog search/edit/archive, copy registration and adjustments, membership activation/suspension, checkout, return, renewal, hold queue, hold expiry/cancellation, member self-service, and all six themes.
- **QA PENDING** â€” MySQL concurrent checkout of one copy and concurrent checkouts against a single member loan limit; verify exactly one allowed transaction and consistent inventory.
- **QA PENDING** â€” Cross-tenant and cross-campus authorization matrix for every library read and mutation, including forged IDs, inactive memberships, expired memberships, and member self-service.
- **QA PENDING** â€” Complete accessibility review and screen-reader checks for the library workspace and mobile layouts.
- **QA PENDING** â€” Product-wide regression and performance review of catalog queries and large circulation histories.
- **QA PENDING** â€” Notifications, email/SMS/push delivery, digital resource licensing/security, and any institution-specific fine/acquisition workflow if approved for a later contract.

## Phase 21 — SaaS and White-label Management

- **QA PENDING** — Apply the additive plan/subscription migration only after independently verifying an isolated, authorized Developer B database, its server, port, schema, and current records. Never use destructive migration commands.
- **QA PENDING** — Run authenticated platform browser workflows for plan creation/editing, assignment, pause/resume, plan-version immutability, tenant detail display, pagination, and platform permission denial.
- **QA PENDING** — Verify each configured feature route is blocked for a paused or non-entitled assignment and that legacy tenants without an assignment retain access; verify tenant membership and permission middleware still apply.
- **QA PENDING** — Run concurrent plan assignment and plan edit/assignment checks on MySQL and confirm assignment history and audit events stay consistent.
- **QA PENDING** — Complete the cross-tenant/campus adversarial matrix for all mapped module routes and confirm no route alias or custom-domain path bypasses entitlement checks.
- **QA PENDING** — Reconcile any later approved Phase 21 pricing, quotas, billing, deployment-provisioning, or data-portability specification before implementing those unsupported commercial workflows.
- **QA PENDING** — Verify domain TXT challenge, primary custom-domain routing, branding image delivery, display-name/logo rendering, and all six themes on the production host configuration.
- **QA PENDING** - Run concurrent campus and staff creation/reactivation against configured limits on MySQL; confirm tenant row locking prevents over-allocation and historical records remain intact after plan changes.

## Phase 22 - Standards and Integrations

- **QA PENDING** - Apply the additive integration credential and external identifier migration only after independently verifying an authorized isolated Developer B schema, database server, port, schema name, and existing records.
- **QA PENDING** - Verify authenticated CASE 1.1 provider responses against the official CASE 1.1 JSON schemas and conformance suite; the implemented subset is read-only CFDocuments, CFItems, and CFPackages for published competency frameworks and does not claim certification.
- **QA PENDING** - Exercise token creation, one-time secret display, hashed-at-rest storage, expiry, scope denial, revocation, malformed bearer rejection, tenant binding, subscription entitlement enforcement, audit records, and route rate limiting through authenticated HTTP requests.
- **QA PENDING** - Run cross-tenant and suspended-tenant adversarial API requests and prove no framework, competency, external identifier, or audit metadata crosses tenant boundaries.
- **QA PENDING** - Verify persistent CASE sourcedId mappings under concurrent reads and ensure mappings do not leak between tenants.
- **QA PENDING** - Confirm published-only filtering and exact CASE payload mapping against representative competency-framework records; verify unknown and malformed identifiers return protocol-appropriate errors.
- **QA PENDING** - Review the missing Phase 22 acceptance matrix with the product owner before adding any further standards, SSO, provider adapters, import/sync, or webhook scope. No external credentials or provider environments were available or used.

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
