# Phase 04 disposable MySQL test database provisioning

## 2026-10-23 effective grant check

The schema and account are now reachable from the private ignored test configuration and the dedicated schema is empty. However, the current effective account is `techmentors_phase04_test@127.0.0.1` and still has `ALL PRIVILEGES` on `techmentors_lms_phase04_test.*`. The guarded runner refuses safely; no migrations or writes were performed. Apply the exact 11-item allowlist to this host-matched account, removing the broad schema grant, then re-run the read-only preflight. Prior text below describing a missing `.env.phase04.testing` is historical and superseded.

**Status: DBA action required. This SQL has not been executed.**

The previously approved `techmentors_lms_test` schema contains Phase 01–03 data and is not a disposable target. The Phase 04 runner rejects it. This document describes a separate schema and account dedicated to Phase 04 destructive test migrations. Never point this account at development, production, or another test schema.

## DBA preflight

Run these read-only checks using an authorized DBA session. The first query must return no rows. If either name already exists, stop and investigate; do not reuse it or drop it.

```sql
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'techmentors_lms_phase04_test';

SELECT User, Host
FROM mysql.user
WHERE User = 'techmentors_phase04_test';
```

Create the new schema and a TCP-loopback-only account with a randomly generated password entered privately by the DBA. Replace the password placeholder locally in the DBA client; do not commit it, send it to Codex, or put it in a report.

```sql
CREATE DATABASE techmentors_lms_phase04_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'techmentors_phase04_test'@'127.0.0.1'
  IDENTIFIED BY '<GENERATE_AND_ENTER_PRIVATELY>';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
ON techmentors_lms_phase04_test.*
TO 'techmentors_phase04_test'@'127.0.0.1';

SHOW GRANTS FOR 'techmentors_phase04_test'@'127.0.0.1';
```

The scoped `DROP` privilege is required because Laravel's `RefreshDatabase` resets the disposable test schema before a suite. It is granted only on that schema. Do not grant global privileges, `GRANT OPTION`, access to any other schema, or use the account in the application runtime.

### Correcting the currently overbroad grant

The 2026-10-23 read-only check still found `ALL PRIVILEGES` on the target schema. An authorized DBA must remove that grant only for `'techmentors_phase04_test'@'127.0.0.1'` and this schema, then apply the exact `GRANT` above. For example, after confirming the current account and schema, the scoped correction is:

```sql
REVOKE ALL PRIVILEGES ON techmentors_lms_phase04_test.*
FROM 'techmentors_phase04_test'@'127.0.0.1';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
ON techmentors_lms_phase04_test.*
TO 'techmentors_phase04_test'@'127.0.0.1';

SHOW GRANTS FOR 'techmentors_phase04_test'@'127.0.0.1';
```

This DBA command has **not** been executed by this verification. Confirm the resulting grants read-only before running the guarded application command.

After provisioning, verify through the new account that the initial schema is empty and that the server/account/schema identity is exact:

```sql
SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_account, VERSION() AS server_version;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'techmentors_lms_phase04_test'
  AND TABLE_TYPE = 'BASE TABLE';

SHOW GRANTS;
```

The database name must be `techmentors_lms_phase04_test`; the authenticated account must be `techmentors_phase04_test` at host `127.0.0.1`; and the table query must initially return no rows.

## Local configuration

Copy `.env.phase04.testing.example` to `.env.phase04.testing` and enter the password directly in the ignored local file. Confirm the real file remains ignored with `git check-ignore .env.phase04.testing`. Never print or paste the file contents.

Before running the destructive integration suite, `php tests/run-tests.php --mysql-phase04` performs read-only checks of the configured host, schema, username, authenticated account, scoped grants, server, and pre-existing table inventory. It permits a first run only when the new schema contains no tables; later runs compare the schema and migration inventory to a local ownership manifest and refuse persistent application rows. The `RefreshDatabase` migration step therefore cannot operate against the populated former test schema or an unexpected schema. The manifest is local, ignored, and contains no credentials.

## Current status

At the time of this report, `.env.phase04.testing` is absent and the new schema/account have not been verified. The populated `techmentors_lms_test` schema was inspected read-only and left untouched. No MySQL migration or Phase 04 MySQL test has run.

## 2026-10-22 verification update

The prior unconfigured status is superseded: the ignored private file now exists and the dedicated account connects to the exact new schema. Read-only inspection confirmed zero objects. The actual grant is `ALL PRIVILEGES` on this schema, which exceeds the exact allowlist above by `ALTER ROUTINE`, `CREATE ROUTINE`, `CREATE VIEW`, `EVENT`, `EXECUTE`, `SHOW VIEW`, and `TRIGGER`. The guarded runner refuses this account; no MySQL migration/test ran and no grant was changed. See `GRANT_SAFETY_DIAGNOSIS.md` for the authorized DBA replacement procedure.
