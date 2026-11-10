# Database conventions

## Verified Phase 01 state

Laravel defaults to MySQL and connects to techmentors_lms_dev on 127.0.0.1:3306 using the dedicated techmentors_app account. The server is MySQL Community 8.4.11. Three Phase 01 migrations are Batch 1 / Ran; nine framework foundation tables exist. See the Phase 01 database audit for the read-only verification and grants summary.

Tests default to SQLite :memory: unless the ignored .env.testing selects the isolated MySQL test schema. Read-only verification confirmed techmentors_lms_test, the dedicated techmentors_test account, schema-scoped grants, and an empty schema before MySQL RefreshDatabase tests. The guard refuses development, unrelated, and production targets.

## Conventions for future schemas

- Production and development persistence target MySQL 8.4 LTS with InnoDB, utf8mb4, strict SQL mode, foreign keys, and explicit indexes.
- Store instants in UTC. Convert to institution/user timezone only at presentation boundaries.
- Use Laravel migrations as schema history. Review generated SQL and migration status before execution. Do not run destructive reset commands against development or production.
- Review schema identity and ownership before provisioning a database. Stop if existing tables or data are unexpected.
- Use explicit foreign keys, unique constraints, and indexes based on real query patterns. Tenant-owned constraints and lookup indexes must include the tenant discriminator where needed.
- Use exact decimal or integer minor units for money; never binary floating point for balances or posted amounts.
- Preserve audit/history for grades, payments, and other regulated records. Avoid destructive changes to posted facts.
- Minimize personal data, classify it, and document retention before schema creation.
- Separate runtime DML credentials from migration credentials in production; migration accounts may need DDL and DROP, runtime accounts should not.
- Use composite indexes based on actual access patterns and validate with representative EXPLAIN plans.

## Migration gates

1. Confirm host, port, database name, server version, and database owner.
2. Confirm the database is isolated for this project and has no unrelated schema/data.
3. Review migration status and proposed SQL.
4. Back up any non-disposable environment before a reviewed forward migration.
5. Run migration and rollback behavior on an isolated MySQL test schema first.

No student, course, fee, academic, HR, or tenant business tables are included in Phase 01.
