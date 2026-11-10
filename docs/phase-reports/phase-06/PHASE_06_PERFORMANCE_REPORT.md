# Phase 06 performance report

Verification date: 2026-10-26. Hardware/runtime: Windows 11 Lenovo 82TK, Intel Core i7-12700H (14 cores/20 logical processors), 15.7 GiB RAM; PHP 8.3.35 CLI; MySQL 8.4.11 on loopback; SQLite test driver; Node 24.21.0. These measurements describe this workstation and synthetic test data only.

## Representative curriculum rendering measurement

A guarded feature test generated a 220-node syllabus (20 units, 200 topics), requested the tenant-scoped curriculum detail page through Laravel's test HTTP stack, counted SQL queries, and asserted no more than 45 statements.

| Database | Nodes | SQL statements | Measured request time |
|---|---:|---:|---:|
| MySQL 8.4.11 | 220 | 39 | 85.33 ms |
| SQLite | 220 | 39 | 75.90 ms |

The measured time covers the Laravel/Inertia test request, not browser network latency. The test verifies the serialized page contains all 220 nodes. This is useful for regression and N+1 detection; it is not a production p50/p95, throughput or SLO result.

## Frontend and suite observations

- Vite 8.3.5 production build transformed 633 modules.
- Main application JavaScript: 356.84 kB raw / 111.52 kB gzip.
- Curriculum chunk: 20.15 kB raw / 4.32 kB gzip.
- MySQL backend suite: 197 tests / 2,153 assertions / 90.81 seconds.
- SQLite backend suite: 191 passed / 6 skipped / 2,065 assertions / 14.45 seconds.
- Scanner-enabled Playwright Chromium: 23 tests passed in approximately 3.0 minutes.
- The local CPU, MySQL service, antivirus process and synthetic fixtures influenced these timings.

## Implemented performance safeguards

Curriculum directories and review queues paginate data. Curriculum detail eager-loads node relationships. Import is bounded by file, item and hierarchy limits and uses queued batch processing for larger work. Query filters and tenant/version lookups use documented indexes. The detail regression test's 39 queries for 220 nodes guards against per-node query growth.

## Not measured

No production-like tenant-scale load, query p95, queue throughput, cache hit rate, route latency distribution, or p75 LCP/INP/CLS was measured. Targets remain LCP <= 2.5 s, INP <= 200 ms and CLS <= 0.1 at p75; no compliance is claimed.
