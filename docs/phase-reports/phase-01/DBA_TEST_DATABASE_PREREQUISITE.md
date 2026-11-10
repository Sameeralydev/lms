# Isolated MySQL test database verification

**Updated:** 2026-10-22
**Status:** SATISFIED

## Verified identity

- Server: MySQL 8.4.11 on 127.0.0.1:3306.
- Active schema: techmentors_lms_test.
- Authenticated principal: techmentors_test@127.0.0.1.
- Credential is stored only in ignored local .env.testing; it is not printed or tracked.
- Before migration tests, the schema had zero tables.
- Sanitized SHOW GRANTS inspection found ALL PRIVILEGES only on techmentors_lms_test, no grant option, and only global USAGE.

## Test result

The guarded Composer suite passed: 23 tests / 83 assertions on MySQL. Three framework migrations are Batch 1 / Ran. The final schema has nine framework tables, 16 indexes, two unique non-primary indexes, zero foreign keys, and no rows outside migration history.

The runner refused techmentors_lms_dev, alamgirian_dev, alamgirian_test, and production before tests or connection.

## Safe operating rules

Keep .env.testing ignored and private. Use the dedicated test account and exact test schema. Inspect the target before migration-capable runs. Never use root or the development account for tests; never run destructive tests against development, production, or unrelated databases.
