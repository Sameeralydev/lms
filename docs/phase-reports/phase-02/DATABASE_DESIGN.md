# Phase 02 Database Design and Migration Evidence

## Schema added

Three additive migrations add 16 tables to the nine Phase 01 framework tables:

| Area | Tables |
|---|---|
| Tenant and institution | `tenants`, `institutions`, `tenant_domains`, `campuses` |
| Access and invitations | `tenant_memberships`, `tenant_invitations`, `roles`, `permissions`, `permission_role`, `membership_role`, `platform_role_user`, `platform_bootstrap_states` |
| Settings and appearance | `tenant_settings`, `tenant_brandings`, `user_preferences` |
| Audit | `audit_events` |

The existing nine tables are `users`, `cache`, `cache_locks`, `failed_jobs`, `jobs`, `job_batches`, `password_reset_tokens`, `sessions` and `migrations`.

## Integrity rules

- Tenant slugs, public UUIDs and verified-domain names are unique.
- Each tenant has one institution profile; institution code is tenant-scoped.
- Campus code is unique within its institution. Campus, institution and tenant relationships use composite tenant-aware foreign keys.
- A user has at most one membership per tenant. Membership and campus foreign keys include tenant identity.
- Membership role pivots include `tenant_id`; composite foreign keys enforce that a role and its membership belong to the same tenant.
- Global and tenant roles have a generated scope key for unique role keys across nullable scopes. A unique `(id, tenant_id)` key supports the role-membership constraint.
- Invitations store only a SHA-256 token digest, status, expiry and the assigned tenant role key.
- Tenant lifecycle uses active/suspended/archived status and soft deletion. Foreign keys restrict hard deletion.
- Query indexes cover tenant/status listings, tenant memberships, domains, invitations, audit chronology and common unique lookups.

## Development database preflight and migration

Read-only preflight before migration reported:

- Default and authenticated database: `techmentors_lms_dev`.
- Authenticated application account: `techmentors_app`.
- MySQL server: 8.4.11 on the configured local endpoint.
- Grant scopes: global `USAGE` only and schema privileges scoped to `techmentors_lms_dev.*`; no other schema scope was present.
- Exactly the nine Phase 01 tables existed. Counts were zero in all data tables and three rows in `migrations`; no unexpected table was found.
- All three new migrations were pending.

Executed `C:\php83\php.exe artisan migrate --database=mysql --force`. It ran the three Phase 02 migrations successfully in batch 2. No reset, `migrate:fresh`, `db:wipe`, seeder or rollback was run.

Post-migration inspection reported all 25 expected tables, six recorded migrations, 42 foreign-key column references and the tenant-aware composite/unique indexes. Original Phase 01 data-table counts remain zero; the `migrations` count is six. The one new reference row is `platform_bootstrap_states.id=1`, which records that first-admin bootstrap has not completed. No tenants, users or business data were created in the development database.

## Testing database isolation

MySQL integration and browser setup use the dedicated `techmentors_lms_test` schema and `techmentors_test` account from ignored `.env.testing`. The guarded runner accepts only that local MySQL identity or in-memory SQLite. It rejects development/unrelated/production schemas, root/application accounts, remote endpoints, non-empty `DB_URL` and file-backed SQLite. Playwright's database preflight checks the authenticated database/account and schema-only grants before its synthetic fixtures are written.

See `TEST_REPORT.md` for executed MySQL/SQLite results. The local development DB and the isolated test DB are not interchangeable.

## Operations boundary

The local development account can run the additive migrations. Production should use a separate, short-lived migration identity with DDL privileges and a restricted runtime identity without schema-altering permissions. No production account or schema was configured or modified here.
