# Deferred product QA backlog

Items in this file are **QA PENDING** unless evidence is explicitly recorded after execution. Deferred items are reserved for final product-wide verification and do not represent passed checks.

## Phase 20 — Library Management

- **QA PENDING** — Run the additive Phase 20 migration on a verified isolated MySQL schema after owner, server, schema, and existing-data checks. Do not use destructive refresh commands.
- **QA PENDING** — Authenticated browser workflows for catalog search/edit/archive, copy registration and adjustments, membership activation/suspension, checkout, return, renewal, hold queue, hold expiry/cancellation, member self-service, and all six themes.
- **QA PENDING** — MySQL concurrent checkout of one copy and concurrent checkouts against a single member loan limit; verify exactly one allowed transaction and consistent inventory.
- **QA PENDING** — Cross-tenant and cross-campus authorization matrix for every library read and mutation, including forged IDs, inactive memberships, expired memberships, and member self-service.
- **QA PENDING** — Complete accessibility review and screen-reader checks for the library workspace and mobile layouts.
- **QA PENDING** — Product-wide regression and performance review of catalog queries and large circulation histories.
- **QA PENDING** — Notifications, email/SMS/push delivery, digital resource licensing/security, and any institution-specific fine/acquisition workflow if approved for a later contract.
