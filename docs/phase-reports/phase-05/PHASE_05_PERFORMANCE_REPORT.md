# Phase 05 performance baseline

**Measurement date:** 2026-10-24  
**Environment:** Local Windows development machine, PHP 8.3.35, Node 24.21.0, MySQL 8.4.11. These measurements are local build/test observations, not production benchmarks.

## Frontend build

The production Vite build passed and transformed 618 modules:

- Main JavaScript: 354.57 kB raw / 110.95 kB gzip.
- CSS: 39.36 kB raw / 8.32 kB gzip.
- Student-facing page chunks are split from the main bundle.

Command: npm.cmd run build.

## Runtime/test observations

- Guarded MySQL suite: 169 passed / 1,603 assertions; full run includes migration and application test overhead and is not a request-latency benchmark.
- Guarded SQLite suite: 166 passed / 1,562 assertions / 3 MySQL-only skips.
- Chromium suite: 22 tests passed in 4.4 minutes serially. This is end-to-end suite duration, not page-load performance.
- SIS imports process 250-row database chunks and avoid retaining the entire parsed file or all validation errors in memory. The configured request/file bounds and 500-error inline cap constrain memory and response size.
- Archive/purge and outbox processing are bounded per worker invocation and scheduled locally.

## Performance claims and follow-up

No production hardware, p75 Core Web Vitals, throughput, queue latency, database query-count baseline or sustained concurrency benchmark was run. Therefore production performance targets are NOT VERIFIED. Future measurement should record hardware, browser/device, dataset size, concurrency and cold/warm cache conditions before comparing against LCP 2.5s, INP 200ms and CLS 0.1 targets.
