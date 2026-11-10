# Phase 08 verification and test report

Verification run: 2026-10-28. Commands ran from the project root. Credentials were kept in private ignored configuration and were not printed or copied into this report.

## Database verification

| Command | Result |
|---|---|
| `php tests/run-phase08-mysql.php --preflight` (before run) | PASS, exit 0; dedicated Phase 08 MySQL 8.4.11 schema/account confirmed, 150 schema objects and 44 migrations; read-only preflight made no changes. |
| `php tests/run-phase08-mysql.php --run` | PASS, exit 0; 9 tests, 184 assertions, then all five independent-session enrollment race probes passed. |
| `php tests/run-phase08-mysql.php --preflight` (after run) | PASS, exit 0; same database identity, 150 objects, 44 migrations, ownership manifest and row-count baseline verified. |

No migration, reset, recovery, or manifest rewrite was needed during verification. The guarded run used its existing ownership checks, local exclusive lock, MySQL advisory lock, and explicit Phase 08 target. No other schema was targeted.

## Independent MySQL concurrency probes

The guarded runner executed five barrier-synchronized races using 10 worker sessions with distinct MySQL connection IDs. Persisted-state checks passed:

| Race | Persisted result |
|---|---|
| Two learners register for capacity one | 1 confirmed, 1 waitlisted, 1 occupied |
| Same learner submits one idempotency key concurrently | 1 enrollment row and one returned enrollment ID |
| Seat release promotes a waitlisted learner | 1 offered entry, 1 transition event, 1 outbox event |
| Same learner requests the same section transfer concurrently | 1 pending transfer; exactly 1 worker succeeds |
| Two registrar approvals race | Confirmed final state; 1 transition event and 1 audit event |

The probe creates run-scoped fixture records and cleans up only those records. It verifies final database state and audit/outbox invariants; it does not use a schema reset.

## Waitlist notification delivery

Offer creation, acceptance, and expiry write idempotent outbox events in the same transaction as their state changes. The consumer validates the tenant-scoped active membership before sending. Tests verify duplicate event suppression, local notification dispatch, retry after transient delivery failure, bounded retry behavior, expiry processing, and delivery without request tenant context. Audit and enrollment event history are asserted in the feature tests and concurrent probes.

## SQLite and browser verification

| Command | Result |
|---|---|
| `php artisan test` | PASS; 246 passed, 3,053 assertions, 7 environment/engine-dependent skips. |
| `npm.cmd run test:e2e` with process-scoped ClamAV E2E settings | PASS; 26 Chromium tests passed, 0 skipped. |

The browser suite covered student preview, registration, waitlisting, withdrawal, offer acceptance, Phase 07 lesson access, registrar instructor assignment, assigned instructor roster access, and unassigned instructor denial. The Phase 08 journeys also checked mobile reflow, focus handling, and Axe WCAG A/AA rules. The ClamAV-enabled run exercised the clean-scanned transfer certificate flow; all 26 browser tests passed.

## Quality and dependency checks

| Command | Result |
|---|---|
| `npm.cmd run test:unit` | PASS; 6 tests. |
| `npm.cmd run typecheck` | PASS. |
| `npm.cmd run lint` | PASS; zero warnings. |
| `npm.cmd run format:check` | PASS. |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | PASS; no errors. |
| `php vendor/bin/pint --test` | PASS. |
| `composer validate --strict` | PASS. |
| `composer check-platform-reqs` | PASS; PHP 8.3.35 and required extensions. |
| `composer audit` | PASS; no security advisories. |
| `npm.cmd audit --audit-level=high` | PASS; zero vulnerabilities. |
| `npm.cmd run build` | PASS; Vite transformed 645 modules. |
| PHP syntax check (`php -l` over `app`, `tests`, and `database`) | PASS; 357 PHP files. |
| `php artisan route:list --except-vendor --path=registration` | PASS; 8 registration routes listed. |

Hosted CI and production deployment were not executed. No test or static-check failure was suppressed. This report records local evidence and is not a production certification.
