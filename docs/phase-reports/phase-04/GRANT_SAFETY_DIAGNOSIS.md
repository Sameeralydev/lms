# Phase 04 MySQL grant safety diagnosis

## 2026-10-23 independent recheck

The claimed DBA correction is not effective for the connection's authenticated account. A new read-only check again returned `DATABASE() = techmentors_lms_phase04_test`, `CURRENT_USER() = techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11, and no schema objects. `SHOW GRANTS` still shows `ALL PRIVILEGES` on this schema. The 18 actual schema privileges contain the seven extras documented below. The runner exits 2 before migrations. This confirms a real overgrant for the `127.0.0.1` account; do not adjust the parser or relax its exact 11-privilege policy. DBA action must apply to `'techmentors_phase04_test'@'127.0.0.1'` and be confirmed with read-only `SHOW GRANTS`/`information_schema` before retrying.

**Assessment date:** 2026-10-22  
**Result:** BLOCKED before migrations or MySQL integration tests.  
**Scope:** Read-only database inspection and project-local grant-policy regression coverage. No MySQL grants or data were changed.

## Root cause

The error is caused by an actual overbroad grant, not by MySQL 8.4 formatting. The database returns standard quoted `SHOW GRANTS` output. The authenticated account has `ALL PRIVILEGES` on the dedicated Phase 04 schema. The documented policy allows exactly the 11 schema privileges required by migrations and tests.

Read-only `information_schema.SCHEMA_PRIVILEGES` inspection returned 18 privileges for the current account. The 11 required privileges are present, along with these seven extras:

- `ALTER ROUTINE`
- `CREATE ROUTINE`
- `CREATE VIEW`
- `EVENT`
- `EXECUTE`
- `SHOW VIEW`
- `TRIGGER`

All 18 rows reported `IS_GRANTABLE = NO`. `SHOW GRANTS` showed global `USAGE` only and no `GRANT OPTION`; the overgrant is limited to `techmentors_lms_phase04_test.*`. It is still broader than the approved test-only allowlist, so the runner correctly refuses it.

## Read-only preflight evidence

- `.env.phase04.testing` exists locally and `git check-ignore -v .env.phase04.testing` returned `.gitignore:4:.env.*`; no configuration values or password were printed.
- The effective connection was made to `127.0.0.1:3306` using the configured dedicated test account. `DATABASE()` returned `techmentors_lms_phase04_test`; `CURRENT_USER()` returned `techmentors_phase04_test@127.0.0.1`; server version was MySQL 8.4.11.
- The target schema contained **zero tables or views** at the read-only inspection.
- `SHOW GRANTS` returned global `USAGE` and schema-scoped `ALL PRIVILEGES` only.
- The preflight does not access or modify `techmentors_lms_dev`, `techmentors_lms_test`, `alamgirian_dev`, `alamgirian_test`, or production schemas.

The `--mysql-phase04` runner connects, confirms identity, and rejects the privilege set before schema snapshot/ownership-manifest handling. Its final captured exit code was **2** with: `The Phase 04 test account has ALL PRIVILEGES on its schema, which exceeds the documented test-only allowlist.` No migration, schema reset, MySQL integration test, or manifest update occurred.

## Parser and regression changes

The previous parser recognized the quoted schema name and rejected the grant because `ALL PRIVILEGES` is not the exact documented set. Its message did not distinguish an overgrant from missing individual privileges. The preflight now delegates to a pure grant policy that:

- accepts MySQL backtick-quoted identifiers and account names, single-quoted account names, and bare schema identifiers;
- combines privileges split across multiple schema grants before checking the exact allowlist;
- accepts the authorized `localhost` or `127.0.0.1` authenticated-account host alias only when the configured connection remains on `127.0.0.1:3306`;
- rejects `ALL PRIVILEGES`, global or other-schema grants, grant delegation, wrong recipients, and privilege additions or omissions.

The exact allowlist was not weakened. Seven new regression tests cover these valid and invalid forms.

## Commands and results

| Command/check | Result |
|---|---|
| Read-only PHP/PDO identity, `SHOW GRANTS`, schema privilege, and schema-object inspection | PASS; exit 0; evidence above |
| `git check-ignore -v .env.phase04.testing` | PASS; ignored by `.gitignore:4:.env.*` |
| `php tests/run-tests.php --sqlite --filter=Phase04MySqlGrantPolicyTest` | PASS; 7 tests / 12 assertions; exit 0 |
| `php tests/run-tests.php --sqlite` | PASS; 91 tests / 759 assertions; exit 0 |
| `composer.bat format:check` | PASS; exit 0 |
| `composer.bat analyse` | PASS; no PHPStan errors; exit 0 |
| `php tests/run-tests.php --mysql-phase04` | BLOCKED as designed; captured runner exit 2 at grant validation, before migrations/tests |

The SQLite suite used isolated in-memory databases. It is not evidence that the MySQL integration suite passed.

## Required DBA correction

The authorized DBA should replace the schema-wide grant for the exact account below with the documented least-privilege grant. No grant was revoked or changed by this run.

```sql
REVOKE ALL PRIVILEGES ON `techmentors_lms_phase04_test`.*
FROM `techmentors_phase04_test`@`127.0.0.1`;

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
ON `techmentors_lms_phase04_test`.*
TO `techmentors_phase04_test`@`127.0.0.1`;
```

After the DBA change, rerun the guarded command. The runner must again verify the account, schema, grants, and empty/owned schema state before it can migrate or execute destructive integration tests. Do not edit or bypass the safety policy to accept `ALL PRIVILEGES`.

## Remaining Phase 04 blockers

- MySQL-backed migrations/integration tests remain unrun pending the exact grant correction and a passing guarded preflight.
- The existing Phase 04 final gate also records real malware-scanner verification as outstanding; this diagnosis does not change that requirement or start Phase 05.
