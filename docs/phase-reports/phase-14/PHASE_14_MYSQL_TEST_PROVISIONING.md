# Phase 14 MySQL test provisioning

This is a disposable integration-test target. It must be separate from the Phase 14 development database, Phase 13's test schema, and every production or unrelated schema.

## DBA action

The authorized DBA should create an empty schema named `techmentors_lms_phase14_test` on the local MySQL 8.4 instance and a dedicated account named `techmentors_phase14_test` restricted to host `127.0.0.1`. Generate and deliver its password through the owner's private secret channel; never place it in chat or this document.

Grant only the test migration allowlist on `techmentors_lms_phase14_test.*`: `ALTER`, `CREATE`, `CREATE TEMPORARY TABLES`, `DELETE`, `DROP`, `INDEX`, `INSERT`, `LOCK TABLES`, `REFERENCES`, `SELECT`, and `UPDATE`. Do not grant global privileges, wildcard host access, or `GRANT OPTION`. The guarded preflight compares the actual account and grant list.

### Executable DBA SQL

Run this only as an authorized DBA after confirming the schema name is unused and approved for disposable Phase 14 testing. Replace the password placeholder locally with a newly generated secret from the organization's secret manager. Never paste it into chat or commit it.

```sql
CREATE DATABASE `techmentors_lms_phase14_test`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'techmentors_phase14_test'@'127.0.0.1'
  IDENTIFIED BY '<generate-and-enter-a-unique-secret-locally>';

GRANT ALTER, CREATE, CREATE TEMPORARY TABLES, DELETE, DROP, INDEX,
      INSERT, LOCK TABLES, REFERENCES, SELECT, UPDATE
  ON `techmentors_lms_phase14_test`.*
  TO 'techmentors_phase14_test'@'127.0.0.1';

SHOW GRANTS FOR 'techmentors_phase14_test'@'127.0.0.1';
```

The DBA must privately deliver the generated password to the owner. The owner then copies the ignored example to `.env.phase14.testing`, configures the exact DB target/account/password, and sets `PHASE14_TEST_SCHEMA_DISPOSABLE=true` and the exact authorization phrase only after independently approving the schema as disposable. Validate file exclusion with `git check-ignore -- .env.phase14.testing`, then run `php tests/run-phase14-mysql.php --preflight`. Preflight must report the exact schema/account, MySQL 8.4, the 11-grant allowlist, and an empty initial inventory before `--run` is considered. The runner currently blocks `--run` until the separate concurrency harness exists; provisioning alone does not authorize a bypass.

The owner must independently confirm that this exact schema is disposable and authorize initial provisioning. That approval is represented by setting `PHASE14_TEST_SCHEMA_DISPOSABLE=true` and `PHASE14_TEST_SCHEMA_AUTHORIZATION=Explicit owner authorization for disposable Phase 14 test schema` in the ignored local environment file. The example intentionally leaves the authorization value blank.

## Local setup

Copy `.env.phase14.testing.example` to `.env.phase14.testing`, enter the DBA-delivered password privately, and enter the explicit owner authorization only after approving that the named schema is empty and disposable. The runner does not print environment values. The file is covered by `.env.*` ignore rules; verify with `git check-ignore -- .env.phase14.testing`.

Commands:

```powershell
php tests/run-phase14-mysql.php --preflight
php tests/run-phase14-mysql.php --run
```

The preflight verifies exact database/account identity, MySQL 8.4, the schema-scoped 11-privilege allowlist, a stable object/migration snapshot, the local ownership manifest, migration prefix and exclusive file/server locks. Initial setup is accepted only for a genuinely empty schema without an existing intent or manifest. Failed migration intent is preserved for read-only recovery; never delete it to resume.

The runner's `--run` mode intentionally remains disabled until `tests/Support/phase14-grade-concurrency.php` contains the required independent-session probes. A runner refusing at this gate is expected; do not bypass it or manually run migrations.
