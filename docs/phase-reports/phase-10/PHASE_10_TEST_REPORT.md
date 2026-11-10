# Phase 10 Test Report

## Guarded MySQL 8.4

- `php tests/run-phase10-mysql.php --run` — **PASS**, exit 0. The guarded runner verified the dedicated schema/account, allowed privileges, advisory lock, and existing ownership manifest before running tests.
- Attendance feature suite — **8 passed / 196 assertions**, including real ClamAV quarantine processing for a clean uploaded document and a canonical EICAR signature.
- Eight real concurrency probes — **8/8 passed**. Every probe used two independent worker processes and separate MySQL connections synchronized by a barrier, with bounded waits and persisted-state checks:

| Probe | Result evidence |
|---|---|
| Concurrent register creation | 2 sessions; 1 register; 2 unique entries |
| Competing edits | 2 sessions; version 2; 1 revision; atomic result |
| Overlapping bulk marking | 2 sessions; version 2; 2 revisions; atomic result |
| Submit versus correction | 2 sessions; submitted register; pending correction preserved |
| Lock versus update | 2 sessions; locked register; post-lock edit rejected |
| Duplicate correction approval | 2 sessions; one approved transition, one revision and one outbox event |
| Transfer versus submission | 2 sessions; submitted register; destination placement; historical entry preserved |
| Outbox generation | 2 sessions; one logical event; tenant-scoped payload |

- Postflight ownership — **PASS**, 171 objects / 50 migrations, unchanged fingerprint `cc78ece2e30238d3f4818b50e0105f22511665f21b3b42bd363364a14cb13ad3`.
- No schema reset or unguarded destructive database operation was used. Other phase and development databases were not targeted.

## SQLite and PHP checks

- `php artisan test --compact` — **264 passed / 3,455 assertions / 8 skipped**.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — **PASS**, no errors.
- `vendor/bin/pint --test` — **PASS**.
- PHP syntax check over `app`, `tests`, `database`, and `routes` — **PASS**, 417 PHP files.
- `git diff --check` — **PASS**.

## Frontend and browser checks

- `npm.cmd run test:unit` — **6 passed**.
- `npm.cmd run test:e2e -- --workers=1` — **28 passed / 0 skipped**, Chromium, scanner-enabled. Includes the scanner-dependent transfer-document journey and attendance workflow accessibility/responsive checks.
- `npm.cmd run typecheck` — **PASS**.
- `npm.cmd run lint` — **PASS**, zero warnings allowed.
- `npm.cmd run format:check` — **PASS**.
- `npm.cmd run build` — **PASS**, Vite transformed 656 modules; app JavaScript 360.35 kB / 112.39 kB gzip, CSS 51.87 kB / 10.43 kB gzip.

## Dependency checks

- `composer validate --no-check-publish` — **PASS**.
- `composer check-platform-reqs` — **PASS** on PHP 8.3.35.
- `composer audit` — **PASS**, no known advisories.
- `npm.cmd audit --audit-level=moderate` — **PASS**, zero vulnerabilities.

## Real scanner evidence

ClamAV 1.5.4, `sigtool`, and current user-local signatures were available. The daily database age was 0.33 hours, below the configured 48-hour limit. The real upload route left evidence quarantined and inaccessible pending scanning. The actual scanner marked a clean PNG clean; an authorized reviewer could then download it. The job marked a canonical EICAR signature rejected; the reviewer endpoint returned 404 and approval was denied. Scanner timezone was process-configured to `Asia/Karachi` because Windows ClamAV emits the version timestamp in local time while signature metadata is UTC-qualified. A UTC mismatch was observed to fail closed before any file scan.

## Remaining verification limits

- Hosted CI was not run because no hosted runner execution is available in this local run.
- Manual assistive-technology testing was not performed; automated Axe, keyboard and responsive checks are recorded separately.
