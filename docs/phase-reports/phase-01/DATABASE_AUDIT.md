# Phase 01 database audit

**Updated:** 2026-10-22
**Database work:** Read-only verification first; migrations and tests then ran only on the isolated test schema.

## Development database

- Laravel default connection: MySQL.
- Server: MySQL 8.4.11 at 127.0.0.1:3306.
- Database: techmentors_lms_dev.
- Account: techmentors_app.
- Final read-only inventory: nine framework tables; three foundation migrations Batch 1 / Ran.
- No Phase 02 business tables exist.

No tests, migrations, rollback, seeder, DDL, reset, or destructive command targeted development.

## Isolated test identity and grants

Read-only checks preceded schema changes:

- DATABASE() returned techmentors_lms_test.
- CURRENT_USER() returned techmentors_test@127.0.0.1.
- Server: MySQL 8.4.11.
- Initial table count: zero.
- Sanitized SHOW GRANTS classification: ALL PRIVILEGES only on techmentors_lms_test; no grant option; only global grant was USAGE.
- The credential was read from ignored local .env.testing and never printed or committed.

The dedicated account has the DDL/DML needed by the disposable test schema and no effective privileges on unrelated schemas.

## Integration and final schema

Guarded Composer test suite passed: 23 tests / 83 assertions on MySQL. All three initial migrations show Batch 1 / Ran. Final inventory: nine foundation tables, 16 indexes, two unique non-primary indexes, and zero foreign keys. Row counts are three migration-history rows and zero in all other tables.

No student, course, fee, academic, HR, or tenant schema was added.

## Isolation verification

The runner refused techmentors_lms_dev, alamgirian_dev, alamgirian_test, and production with exit 2 before tests. SQLite memory is also an allowed isolated target. MySQL test settings require the exact test schema, loopback endpoint, dedicated account, empty DB_URL, and no socket.

## Development privilege note

A prior development grant audit recorded schema-scoped DROP for techmentors_app. This does not affect the separate testing account or other schemas. Before production, use a runtime DML account and a separate migration identity.
