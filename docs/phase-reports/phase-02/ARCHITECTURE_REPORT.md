# Phase 02 Architecture Report

**Scope:** enterprise core, platform administration, tenant management and institution workspace. LMS/SIS business modules were not started.

## Implemented shape

The application remains a Laravel modular monolith. The platform control plane and institution workspace use the same Laravel deployment and MySQL schema, with separate route groups, permission scopes and data ownership rules.

- `app/Domain/Tenancy`: request tenant context, tenant/domain resolution, lifecycle and transactional provisioning.
- `app/Domain/Identity`: fixed permission catalog and default role templates.
- `app/Domain/Audit`: selected-field administrative audit events.
- `app/Domain/Appearance`: semantic theme registry and brand-color contrast validation.
- `app/Http/Controllers/Platform`: tenant directory/provisioning, operator assignment, platform metrics, runtime overview and platform audit.
- `app/Http/Controllers/Workspace`: institution profile, campuses, memberships, invitations, roles, settings, domains, themes and tenant audit.
- `app/Http/Controllers/Auth`: session login/logout, password reset and signed invitation acceptance.
- `app/Models/Concerns/ScopedToTenant`: fail-closed Eloquent tenant scope for tenant-owned records.
- `resources/js/pages/platform` and `resources/js/pages/workspace`: React/TypeScript/Inertia pages backed by Laravel routes.

Global identity is stored once in `users`. Platform role assignments use `platform_role_user`; institution membership and tenant role assignments use `tenant_memberships` and `membership_role`. Platform roles do not imply institution membership. Tenant creation sends a single-use owner invitation; the chosen owner receives a membership only after accepting it. The platform creator is recorded as creator/audit actor and cannot choose their own account as the initial owner.

One tenant currently owns one institution profile and may own multiple campuses. This makes the tenant the isolation boundary and the institution record the administrative profile. If a later product workflow requires one tenant to contain multiple separately governed legal institutions, revise the one-to-one tenant/institution relationship before that workflow is built.

## Tenant request paths

- Local and canonical application hosts use `/t/{tenant-slug}/...` routes.
- A custom domain is trusted only after its exact DNS TXT ownership token has been verified and its tenant remains active. Requests on that host are bound to that tenant; another slug returns 404.
- Platform routes are unavailable on verified institution domains.
- Host allowlists use the canonical `APP_URL`, local loopback hosts in local/testing, explicit `APP_TRUSTED_HOSTS`, or a verified tenant-domain record.

The deployment can later resolve connection/deployment strategy from a tenant registry. Dedicated databases and dedicated deployments are extension points only; no provisioning automation for them was added.

## Important trade-offs

- Shared-schema isolation enables simple local development and cross-tenant platform reporting, but every future tenant-owned table must carry `tenant_id`, scoped indexes and tenant-aware foreign keys. Dedicated-schema isolation is deferred.
- A `TenantContext` is a Laravel scoped service and is established by server-side routing plus active membership checks. User-supplied tenant IDs are never sufficient authorization.
- Custom permissions are not user-created. Custom roles may select only permissions that the actor already holds; platform super-admin assignment has a one-time interactive CLI bootstrap.
- The audit model blocks Eloquent update/delete operations, but a privileged database operator can still alter rows. External immutable audit export is a later operations control.
- Tenant, institution and campus deletion is not exposed. Lifecycle uses suspend/archive and foreign keys restrict removal.

## Out of scope

No students, guardians as academic relationships, admissions, courses, curriculum, results, attendance, fees, finance, HR, payroll, or analytics workflows were implemented. Their future boundaries and prerequisites are recorded in `PHASE_03_HANDOFF.md`.
