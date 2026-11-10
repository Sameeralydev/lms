# Phase 02 Security Audit

**Reference:** OWASP ASVS 5.0 control areas were used as a review checklist. This is not a certification or independent external penetration test.

## Findings

| Severity | Finding | Status/evidence |
|---|---|---|
| High, resolved | Platform tenant creation originally attached the operator as `tenant.owner`, crossing the global/tenant authorization boundary. | Replaced with a single-use, email-bound owner invitation; platform account receives no tenant membership; direct self-selection is rejected. MySQL feature and Playwright provisioning/acceptance tests verify the boundary. |
| Medium, open for deployment | SMTP is not configured locally. | Local `.env` uses an array mailer; ordinary owner/member invitation and reset delivery returns 503 until a real transport is configured. The E2E URL header is enabled only in `APP_ENV=testing`, explicit E2E mode and loopback requests. Configure private SMTP/Mailpit before real invitation flows. |
| Medium, open for production | Development migration account has DDL privileges within the LMS development schema. | Preflight confirms no grants outside the target schema (apart from global `USAGE`). Production must use a separate migration identity and a restricted runtime identity. No production DB was configured. |
| Medium, accepted limitation | Audit rows are append-only through the Eloquent model, not cryptographically or DBA immutable. | Eloquent update/delete are tested to fail. Production should forward events to a separately controlled immutable retention service if required. |
| Medium, unverified externally | No externally owned domain was available to test live DNS TXT propagation/TLS. | Verified-state resolver, tenant host binding and cross-tenant host denial are tested. Public DNS proof and certificate/renewal automation are deployment checks. |
| Informational, local runtime | XAMPP Apache uses PHP 8.2.12. | It was not changed. Laravel 13 runs and browser tests use PHP CLI 8.3.35 through the local development server. |
| Informational, deployment | Redis, hosted CI execution, production backups/restore, monitoring and production HTTPS are not verified locally. | File cache, file session and synchronous queues are adequate for the local foundation. Production infrastructure work is tracked in `PHASE_03_HANDOFF.md`. |

## Controls inspected and exercised

- Authentication: session regeneration, logout invalidation/CSRF token rotation, login throttling, generic credential errors, password reset enumeration protection, signed invitation acceptance and email binding.
- Authorization: separate platform and tenant permission tables/relations, scoped middleware, deny-by-default context, last-owner protection, no self-role change, platform super-admin CLI bootstrap, non-superadmin operator limits.
- Tenant isolation: exact verified host resolution, slug/member checks, tenant-aware global scope and composite database foreign keys; adversarial IDs/slugs/roles are tested.
- Request protection: Laravel web CSRF middleware, framework validation, SQL parameter binding, React/Inertia output encoding, trusted host checks and request throttles.
- Sessions/errors: encrypted JSON sessions, HttpOnly/SameSite cookies, production-only secure cookie default, safe readiness endpoint and production CSP/security headers. Local HTTP uses a non-secure cookie by configuration; production must run HTTPS.
- Files: private storage disk, image/MIME/size validation, no public storage link, restricted asset route and `nosniff` response.
- Secrets/logging: `.env` and `.env.testing` are ignored; no credentials are included in reports. Invitation/reset links are not sent to the log mailer outside tests.
- Dependencies: current Composer and npm audit runs reported zero advisories.

## Residual risks

Tenant data is logically isolated in a shared schema; every future tenant-owned table, query, cache key, queue job, export and file path needs the controls in `TENANT_ISOLATION_REPORT.md`. Sensitive academic/financial workflows will need their own threat and authorization review. No compliance certification is claimed.
