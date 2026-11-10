# Phase 02 implementation plan

## Accepted architecture

Use a Laravel modular monolith and shared MySQL database. Tenant ownership is an explicit `tenant_id` on institution-owned records. Request tenant context is resolved from a canonical tenant-slug route; a verified custom host is trusted only after DNS TXT verification and is bound to the same tenant slug. Authenticated membership is checked before workspace controllers run. Platform administration uses separate global role assignments and is blocked on institution domains.

## Delivery order

1. Preserve the Phase 01 working tree and verify the active development and testing database identities.
2. Additive schema for tenants, institutions, campuses, domains, memberships, roles, permissions, invitations, settings, branding, and audit events.
3. Tenant context, scoped models, lifecycle/provisioning services, authorization middleware, and a secure first-platform-admin CLI bootstrap.
4. Session authentication, invitation acceptance, password reset, platform operations, and institution administration routes.
5. Inertia pages and a semantic design-token theme registry.
6. MySQL and SQLite behavior tests, browser workflows, static analysis, dependency checks, and production build.
7. Independent review, migration/schema inspection, regression checks, reports, and gate decision.

## Final review notes

- The independent audit found and closed a verified-domain host-resolution gap. Only an active tenant with a verified domain record can use that host; platform routes are rejected there, and a tenant URL that names another tenant is rejected.
- Development migrations were applied additively after verifying the live database and dedicated account. The original nine Phase 01 tables contained zero application records and those counts remain unchanged.
- See `FINAL_GATE_REPORT.md` and `TENANT_ISOLATION_REPORT.md` for final executed evidence.

## Data safety

Development migrations are forward-only/additive for this phase. Before running them, verify Laravel's live database identity and inspect the existing schema. Automated tests use only `.env.testing` and the Phase 01 fail-fast database guard. Never reset development or unrelated schemas.

## Scope boundary

This phase delivers reusable enterprise platform and tenant administration infrastructure only. Student, academic, learning, assessment, finance, HR, payroll, and other product workflows remain out of scope.
