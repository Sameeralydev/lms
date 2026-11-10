# Phase 04 MySQL ownership recovery and reconciliation

## Final Phase 04 release update (2026-10-23)

The V2 database safety lifecycle completed successfully: final read-only preflight verified the dedicated identity, grant policy, ownership, 80 expected objects and 14 migrations. The guarded suite passed 122 tests / 934 assertions, exit 0. The ClamAV and concurrent offer work is completed as recorded in the final gate. The historical scanner-unavailable assessments below predate installation and verification of the local scanner and are superseded. No Phase 05 work was started.

**Historical V1 assessment:** 2026-10-23. **Result at that time:** BLOCKED; the V1 schema was preserved and this V1 inspection made no MySQL schema/data changes. The current V2 recovery outcome follows.

## V2 recovery update (2026-10-23)

The Phase 04 V2 runner uses `.env.phase04.v2.testing`, a dedicated V2 target, separate checkout/server locks, and `storage/framework/.phase04-v2-test-db-ownership.json`. Read-only preflight confirmed database `techmentors_lms_phase04_v2_test`, account `techmentors_phase04_v2@127.0.0.1`, MySQL 8.4.11, the exact documented 11 schema privileges, global `USAGE` only, and no grant option. The initial schema was confirmed empty under explicit user authorization. Final preflight confirms 80 expected objects, 14 migrations, a stable structural fingerprint, and an authorized V2 manifest.

The first V2 guarded run exposed MySQL 1059 from an overlong generated guardian FK name. It left a validated partial migration state, which the V2-only resume guard accepted only after checking identity, grants, original empty manifest, exact migration prefix, migration-owned rows, guardian columns, indexes/FKs, and object inventory. The migration now names its verifier/revoker FKs explicitly within MySQL's identifier limit. The guardian schema integration test passed 1 / 21 assertions and the guarded full suite passed 113 / 864. The V1 manifest was not overwritten; its separate path/hash remained preserved.

The final database identity, manifest, and suite evidence are in `V2_MYSQL_RECOVERY_REPORT.md`. ClamAV remains absent and real file scans remain unverified; no scanner-backed ownership or release state is claimed.

## Verified target and grants

The private ignored `.env.phase04.testing` configuration was read in-process without printing its contents. The values were checked against the one permitted target before connecting. MySQL returned:

- Database: `techmentors_lms_phase04_test`
- Authenticated account: `techmentors_phase04_test@127.0.0.1`
- Server: MySQL `8.4.11`
- Grant policy: global `USAGE` only; exactly `ALTER`, `CREATE`, `CREATE TEMPORARY TABLES`, `DELETE`, `DROP`, `INDEX`, `INSERT`, `LOCK TABLES`, `REFERENCES`, `SELECT`, and `UPDATE` on the dedicated schema; no grant option.
- Git ignore: `.env.phase04.testing` is excluded by `.gitignore` rule `.env.*`.

No connection was made to development or unrelated schemas. The read-only PDO scripts hard-checked the target name, host, port and account before connecting.

## Process and activity findings

Windows process inspection found no `tests/run-tests.php`, `artisan test`, or `artisan migrate` PHP process. Three local `artisan serve` parent processes and their PHP server children remain running; they were not terminated.

Five `mysql.exe` processes belong to the local Windows account. Four currently have established loopback TCP connections to MySQL, from local ports `51587`, `63807`, `62542`, and `53927`; the fifth has no established MySQL TCP connection. No full command lines, password arguments, or environment values were printed. The Phase 04 database account lacks global `PROCESS`, so its process-list view does not expose other-account sessions or their selected databases. These client processes were not terminated.

A prior investigation observed a Phase 04 account session in an ALTER commit state. That session is no longer visible to the current account. MySQL's available evidence cannot tie the historical connection to a Windows PID or conclusively identify its initiating runner. The evidence is consistent with an interrupted/overlapping test migration, but does not prove the actor.

Two read-only schema snapshots two seconds apart had identical objects, migration rows, table counts, and schema fingerprint. A further pair of structural snapshots in the guarded preflight also matched before it refused the manifest. This demonstrates no observed change during those intervals; it does not establish that the remaining uncorrelated MySQL clients could not write between observations.

## Manifest and schema comparison

Manifest path: `storage/framework/.phase04-test-db-ownership.json` (ignored by Git). Its recorded identity fields match the target: database, username, loopback host, and MySQL version `8.4.11`. The manifest records an empty table list and an empty migration list. The original comparison therefore differs in `tables` and `migrations`; it does not store or compare grants.

The current read-only snapshot contains 80 `BASE TABLE` objects and 13 migration rows, each in Batch 1. It showed zero views and zero visible triggers. The repository contains 14 migration files. The absent ledger row is `2026_10_22_000010_create_admission_guardian_authorizations`, although `admission_guardian_authorizations` exists. The snapshot includes all table names referenced by local migration source plus Laravel's `migrations` repository table; no other object name was unmatched. No migration row was unknown to the repository. Because the least-privilege account does not have `TRIGGER` or `SHOW VIEW`, its zero visible trigger/view counts cannot independently establish the absence of metadata hidden by MySQL privileges; the DBA must confirm this read-only before any future attestation.

The local migration defines 19 guardian-table columns; the live table has those column names, but its indexes/constraints do not match the migration. Expected and absent from the live definition are:

- Composite tenant/application foreign key to `admission_applications`.
- Foreign keys for `relationship_verified_by` and `revoked_by`.
- Unique index on `token_hash`.
- Unique composite index on `(tenant_id, id)`.
- Indexes on `(tenant_id, application_id, status)`, `(tenant_id, guardian_user_id, status)`, and `(tenant_id, guardian_email, status)`.

The live table exposes only its primary key and simple foreign-key indexes for tenant, applicant user, and guardian user. This is an unexplained schema-definition mismatch; the guardian table cannot be attributed to the checked-in migration solely by name or columns.

## Migration-owned row provenance

The read-only counts found 33 rows in `permissions` and one row in `platform_bootstrap_states`; all other non-migration tables have zero rows. The 33 permission keys are the 15 academic keys from the academic permission migration and 18 admissions keys from the admissions permission migration, all with tenant scope. The guardian key `admissions.guardians.verify`, which migration 000010 should create, is absent. The bootstrap row is `(id=1, completed=false, completed_at=NULL)`, matching the authorization migration. This data is consistent with migration-generated baseline rows, but it does not resolve the unexpected guardian DDL or missing migration record.

The manifest file was written at 00:18:47; MySQL reports the `migrations` table created at 00:34:55 and the guardian table created at 00:35:09, with the migration repository updated at 00:35:09. These timestamps establish that the schema changed after the empty manifest was recorded. They do not identify which process performed the DDL.

## Root cause assessment

The earlier runner wrote ownership evidence while the schema was empty, then ran `artisan test`. Laravel's database refresh behavior may execute migrations. Its postflight rejected all persistent rows, including the known migration-created permission and bootstrap rows, so it could not advance the manifest after successful migrations. It also attempted manifest refresh after a nonzero test exit and had no exclusive runner lock. That explains how a zero-state manifest can remain stale after a migration run and allows overlapping guarded runs from the same checkout.

The current guardian-table DDL has missing constraints despite matching local column names. The ledger is missing the migration record and its permission seed. It is not possible to prove whether this is an older migration definition, a partial/manual schema change, or an unauthorized writer. The local migration source is not a sufficient basis for re-attestation.

## Safe runner repairs in this checkout

The runner and preflight now:

- Acquire an exclusive checkout-local file lock and a MySQL server-level named lock before a guarded run; the latter serializes cooperating runners from other checkouts on the same MySQL server.
- Leave the manifest unchanged after a failing/interrupted test command.
- Revalidate identity and the exact grant allowlist at postflight.
- Compare two read-only schema snapshots and fail if they differ.
- Store a SHA-256 structural fingerprint covering object types, engines/collations, columns, indexes, constraints, foreign-key mappings, and trigger/view metadata visible to the account. The account's least-privilege grants do not expose all trigger/view definitions, so DBA review is still required for those objects.
- Require the applied migration name inventory to exactly match local migration files.
- Permit only the exact 34 migration-owned permission keys and singleton bootstrap row; all other non-migration rows must be absent.
- Report exact non-secret manifest fields that differ; no mismatch is automatically accepted or re-attested.

Regression coverage includes stale/tampered manifests, wrong identity, changed schema fingerprint, snapshot drift, unknown/missing migration records, migration-owned baseline rows, unexpected business rows, overlapping locks, and failed/interrupted runner outcomes.

## Verification executed

- `php vendor/bin/phpunit tests/Unit/Phase04TestSafetyLifecycleTest.php tests/Unit/Phase04MySqlGrantPolicyTest.php tests/Unit/ClamAvSignatureMetadataTest.php`: **20 tests, 45 assertions, exit 0**.
- Targeted PHPStan on the changed PHP files: **no errors, exit 0**.
- Targeted Pint format check on the changed PHP files: **passed, exit 0**.
- `php tests/run-tests.php --mysql-phase04`: **not run**. A direct invocation of the read-only ownership preflight acquired the MySQL named lock, then rejected before any Artisan testing because the schema differs from the manifest; its actual PHP exit code was 2. A subsequent read-only `IS_FREE_LOCK()` check returned true after session termination. No migration, reset or DB data/schema write was executed.
- Full MySQL integration/concurrency suite: **not run**.

## Scanner verification

The ClamAV adapter now parses engine/database version and build time from `clamscan --version`, rejects unparseable or stale metadata, defaults to a 48-hour maximum age, rejects timestamps more than five minutes in the future, and records an engine/database identifier when metadata is available. `ClamAvSignatureMetadataTest` covers freshness parsing and fail-closed cases.

Local executable lookup found no `clamscan`, `clamdscan`, `freshclam`, or `sigtool`; the local application configuration does not select or configure ClamAV. No malware scan or real signature freshness validation occurred. Documents remain quarantined when scanning is unavailable.

## Required next action

An authorized DBA must provide a sanitized read-only process/session view for current MySQL connections, including connection ID, account, host:port, selected database, command, elapsed time and state, without SQL text. Historical process ownership cannot be reconstructed from the current application account. The DBA must also confirm target-schema triggers and views read-only because the app account cannot enumerate all their definitions. The owner/DBA must separately decide how to recover this dedicated test schema after reviewing the DDL mismatch. Until those decisions and verified schema state exist, do not edit the manifest, migrate, reset, or run MySQL integration tests.

**Gate:** BLOCKED. Phase 05 has not started.
