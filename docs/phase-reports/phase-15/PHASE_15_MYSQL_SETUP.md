# Phase 15 isolated MySQL setup

## Current verification

`tests/run-phase15-mysql.php` is a Phase 15-specific guarded runner with its own schema name, the centralized ignored `.env.testing` profile, local lock, MySQL advisory lock, exact grant validation, read-only schema snapshot, reviewed migration cutoff, intent record, ownership fingerprint, and postflight ownership assertion. It also requires the exact DBA-pinned `@@server_uuid` to ensure that the same port is not silently served by a recovered or unrelated MySQL instance. The current cutoff is `2026_11_13_000001_backfill_academic_saved_report_permission`.

Current local state (2026-11-17): guarded read-only preflight passes against a newly initialized, separate MySQL 8.4.11 instance on a pinned loopback port and new private data directory. It verifies the exact Phase 15 database and `127.0.0.1` account, exact 11 scoped privileges, UUID/data directory, and an empty target (0 objects, 0 migrations). There are 84 reviewed migrations pending. No migrations or MySQL integration tests have run. Do not repurpose the Phase 14 schema or recovered MariaDB instances/backups.

## DBA provisioning procedure

Run as an authorized DBA in a private MySQL client session. First perform these read-only checks:

```sql
SELECT VERSION() AS server_version, @@port AS server_port, @@server_uuid AS server_id;
SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'techmentors_lms_phase15_test';
SELECT User, Host FROM mysql.user WHERE User = 'techmentors_phase15_test';
```

If the schema already exists, stop and review its objects and migration history. Do not adopt or reset it. If absent, the DBA may create the dedicated disposable schema and account after recording that explicit authorization:

```sql
CREATE DATABASE `techmentors_lms_phase15_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'techmentors_phase15_test'@'127.0.0.1' IDENTIFIED BY '<generate and enter a unique secret in the private DBA session>';
GRANT ALTER, CREATE, CREATE TEMPORARY TABLES, DELETE, DROP, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, UPDATE
ON `techmentors_lms_phase15_test`.* TO 'techmentors_phase15_test'@'127.0.0.1';
SHOW GRANTS FOR 'techmentors_phase15_test'@'127.0.0.1';
```

The account must have exactly the listed schema-scoped privileges, no global privileges, and no grant option. Do not create a `localhost` fallback account; the runner requires authenticated `127.0.0.1` matching.

## Local private configuration

Use the existing Git-ignored `.env.testing`; do not create a phase-specific environment file. Set these values privately only after the DBA confirms the exact test target and authorizes it:

```dotenv
TESTING_MYSQL_HOST=127.0.0.1
TESTING_MYSQL_PHASE15_PORT=<owner-pinned alternate loopback port>
TESTING_MYSQL_PHASE15_USERNAME=techmentors_phase15_test
TESTING_MYSQL_PHASE15_PASSWORD=<enter privately>
TESTING_MYSQL_PHASE15_DISPOSABLE=true
TESTING_MYSQL_PHASE15_AUTHORIZATION=OWNER_APPROVED_DISPOSABLE_PHASE15_TEST_SCHEMA
TESTING_MYSQL_PHASE15_SERVER_UUID=<exact @@server_uuid observed in DBA preflight>
TESTING_MYSQL_PHASE15_DATA_DIRECTORY=<canonical private instance data directory>
```

Never include credentials in Git, terminal transcripts, reports, or chat. The UUID is not a credential; it pins the reviewed database service identity. The runner additionally verifies database, authenticated account, loopback host, port, MySQL 8.4, exact grants, ownership state, and locks.

Then run the read-only gate first:

```powershell
php tests/run-phase15-mysql.php --preflight
```

Do not run `--run` until the Phase 15 concurrency harness contains and passes all eight required independent-connection probes. The guarded runner currently rejects `--run` while that harness is missing.


## 2026-11-13 guarded-command evidence

`php tests/run-phase15-mysql.php --preflight` exited 2 before database contact because the centralized private profile does not contain the exact dedicated credentials, authorization, and server UUID. `php tests/run-phase15-mysql.php --run` exited 2 before database contact because the independent-session concurrency harness is incomplete. MySQL integration tests were not run; no schema or ownership manifest was changed.

## 2026-11-17 current preflight evidence

`php tests/run-phase15-mysql.php --preflight` exited 0: MySQL 8.4.11, exact target/account, owner-pinned port/server UUID/data directory, 0 objects, 0 migrations, 84 reviewed migrations pending. The environment file is the centralized Git-ignored `.env.testing`; no phase-specific environment file is used. `--run` remains intentionally blocked before database contact because `tests/Support/phase15-analytics-concurrency.php` does not exist. No MySQL migration, PHPUnit run, or ownership manifest creation has occurred.

## Latest verified target — 2026-11-17

The configured guarded target was revalidated with `php tests/run-phase15-mysql.php --preflight` (exit 0) and executed with `php tests/run-phase15-mysql.php --run` (exit 0). MySQL 8.4.11 target schema `techmentors_lms_phase15_test`; authenticated account `techmentors_phase15_test@127.0.0.1`; 272 objects, 84 reviewed migrations, zero pending. All eight probes ran using 16 distinct worker sessions and eight synchronized transaction barriers. Postflight fingerprint: `2d835263eb01ab344f53348fb77b9f84a0808ae3f0c2c97fd88e46e8353f8f0b`. See the closure addendum for evidence. Do not run migrations through direct Artisan commands or reuse this configuration for development.
