# Phase 09 Performance Report

**Measurement date:** 2026-10-28

## Measured locally

- Vite production build passed with 649 modules transformed.
- Timetables page bundle: 29.20 kB (6.16 kB gzip).
- Main application bundle: 359.33 kB (112.13 kB gzip).
- Full Chromium suite completed 26 passing tests and one scanner-gated skip in 5.1 minutes. This is suite wall time, not a user-facing latency benchmark.
- SQLite full suite completed in 21.06 seconds: 253 passed, 7 skipped, 3,212 assertions.
- Guarded MySQL feature suite completed in 1.99 seconds: 5 passed, 146 assertions. This test-suite time is not a throughput or production latency claim.

## Not measured

No production latency, Core Web Vitals, scaled meeting inventory, query-count, concurrent booking throughput, queue delay, cache behavior, or production error-rate data was collected. The analytics conflict-finding path has not been load-tested across large timetable inventories. Avoid inferring production capacity from the local browser/build/test results.

## Follow-up

Add representative synthetic institution/timetable fixtures, measure query counts and response distributions, inspect analytics query scaling, and profile MySQL concurrency probes after their implementation. Collect LCP/INP/CLS from a production-like deployment before making performance acceptance claims.
