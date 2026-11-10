# Phase 03 Technical Handoff

Phase 02 establishes the shared infrastructure for subsequent LMS/SIS business modules. Do not bypass its tenant context, membership, permission, audit, migration, or test-isolation boundaries.

## Required implementation conventions

1. Every tenant-owned table carries an explicit `tenant_id`; use tenant-aware composite foreign keys when a relation can cross tenant ownership.
2. Use `TenantContext` for request-scoped tenant resolution. Tenant routes resolve through verified slug or verified active domain, then require an active membership. Missing context must fail closed.
3. Add tenant-scoped Eloquent relationships/scopes and retain server-side authorization. A client-supplied tenant, institution, campus, role, or record ID is never proof of access.
4. Register only permissions needed by real behavior in `PermissionCatalog`; platform permissions and tenant permissions remain separate. Do not allow tenant roles to grant platform capabilities.
5. Use transactions for multi-record operations and `AuditRecorder` for important administrative mutations. Audit safe metadata, never credentials, tokens, or full sensitive request bodies.
6. Namescope cache keys, queue payloads, exports, and private object paths by tenant. Restore and clear tenant context around background work.
7. Add migrations only; do not reset the development database. Verify the database identity before applying schema changes.
8. Run backend suites through the guarded `composer.bat test` runner (isolated MySQL by default) and `composer.bat test -- --sqlite`. Never invoke test migrations against the development schema.

## Phase 02 foundation available

- Tenant and institution profile with lifecycle state, slug, verified-domain records, and campus ownership.
- Global users with tenant memberships, campus scope, tenant/platform roles, permissions, and signed invitations.
- Platform control center and institution workspace with real queries.
- Persisted tenant settings, branding, six implemented themes, user theme preference, and private image storage.
- Append-only-at-model-layer administrative audit records.
- Authentication, session protections, authorization middleware, trusted-host handling, and safe health endpoints.

The 16 Phase 02 tables are documented in [DATABASE_DESIGN.md](DATABASE_DESIGN.md), and permission semantics are in [RBAC_MATRIX.md](RBAC_MATRIX.md).

## Suggested next domain sequence

1. Student/admission identity and enrollment boundaries, reusing membership and tenant isolation without confusing student records with login membership.
2. Academic structure (programs, terms, cohorts, courses) with institution-specific configuration and explicit campus applicability.
3. Learning/course delivery and instructor/student assignments.
4. Assessments and results, including grade-change authorization and audit controls.

These are sequencing recommendations, not implemented Phase 03 scope. Finance, HR, and other sensitive domains require separate threat modeling and permission matrices before implementation.

## Gates to retain

- Add MySQL and SQLite feature coverage for each new domain, with MySQL authoritative for composite constraints.
- Add adversarial cross-tenant URL/ID/header tests before exposing new tenant-owned resources.
- Add Playwright tests for completed user journeys, not mock-only navigation.
- Keep platform administration explicitly separate from institution permissions.
- Re-run audits, PHPStan, formatting, TypeScript, frontend unit/browser suites, and the production build before merging any phase work.

## Operational tasks before production

Configure SMTP, HTTPS, production PHP 8.3+, distinct migration/runtime database users, external domain DNS/TLS, backup/restore, monitoring, retention, and production cache/queue/storage isolation. Hosted CI is configured but has not yet been run by a hosted service.
