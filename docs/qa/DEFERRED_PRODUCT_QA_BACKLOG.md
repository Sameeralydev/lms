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
