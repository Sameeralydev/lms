# Tenant Isolation Report

## Request boundary

`ValidateTrustedHost` rejects unknown hosts. The canonical app host and local loopback hosts are trusted by configuration. Other hosts must resolve to an exact `tenant_domains.domain` record whose `status` is verified, `verified_at` is set, and tenant is active. The resolver catches database errors and fails closed. DNS ownership verification uses a tenant-specific TXT challenge; no tenant-supplied host header can create a trusted record.

Slug workspace routes resolve a tenant server-side. `ResolveTenantWorkspace` verifies active tenant state and an active membership for the authenticated global user. On a verified custom host, the route tenant must match the tenant bound to that domain. Platform permission middleware rejects requests on verified tenant hosts. A missing/invalid tenant context never grants access.

## Query and persistence boundary

- `ScopedToTenant` applies an Eloquent global scope returning no rows when context is absent, binds `tenant_id` on create and rejects mismatched tenant writes.
- The context is Laravel-scoped, so long-running request workers do not retain a singleton tenant from a prior request.
- Controllers explicitly scope non-trait models such as roles, domains, audit events and invitations.
- Campus administration adds an assigned-campus restriction for `campus.admin`; owner/admin permissions do not bypass tenant matching.
- Composite MySQL foreign keys protect institution/campus/membership and tenant-role relationships even if an application query is incorrect.
- User theme preferences are keyed by global user ID; tenant branding/settings are keyed by tenant. No tenant data is cached in this phase. Future cache keys must include tenant ID and invalidation must be tenant-scoped.
- No tenant-aware background jobs exist yet. Future jobs must serialize an immutable tenant identifier and establish/clear context in job middleware. File assets are stored under the tenant public UUID; the storage disk is private and only explicit branding image kinds are served.

## Adversarial verification

Automated tests exercise:

- Context-missing tenant query returns no rows; tenant-owned create fails without context.
- Membership and tenant slug substitution is denied.
- Tenant A cannot update Tenant B's campus, even by changing the campus ID.
- Cross-tenant institution/campus and membership/role combinations are rejected by MySQL constraints.
- Campus-admin role is limited to its assigned campus and cannot create campuses.
- Verified custom domain redirects to its tenant and rejects a different tenant slug, even when the account belongs to both.
- A verified institution host cannot access platform control-plane routes.
- Platform account has no institution membership after provisioning and cannot open the tenant dashboard; owner access begins only after signed invitation acceptance.
- An invited auditor cannot access another tenant or platform pages.
- Unknown Host headers receive a generic 400 without echoing the host.

Evidence is in `tests/Feature/TenantManagementTest.php`, `tests/Feature/ApplicationShellTest.php`, and the 15 browser tests described in `TEST_REPORT.md`.

## Limits

No real public domain was supplied, so the external DNS TXT lookup was not run against an owned domain. The resolver and host binding were tested with a verified-domain database fixture. Shared-schema isolation is application and relational-constraint based; it is not a claim of database-enforced row-level security or formal compliance certification.
