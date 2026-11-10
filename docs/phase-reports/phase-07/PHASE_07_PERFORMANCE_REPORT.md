# Phase 07 performance report

Verification date: 2026-10-27. Local synthetic/build evidence only.

Latest measurement update: the successful production build transformed 638 modules; the main app bundle is 357.59 kB raw / 111.72 kB gzip and CSS is 45.62 kB raw / 9.59 kB gzip. The scanner-enabled browser suite took 6.9 minutes for 24 end-to-end journeys (test-suite duration, not page response latency). The MySQL curriculum synthetic baseline printed during the full suite was 220 nodes / 39 queries / 290.85 ms. These are local observations, not production performance claims.

## Measurements

- Production Vite build completed successfully and transformed 638 modules.
- Shared app JavaScript bundle: 357.59 kB raw / 111.72 kB gzip.
- Generated stylesheet: 45.62 kB raw / 9.59 kB gzip.
- Full Playwright run completed in about 5.3 minutes; this measures the serialized test workflows, not application request latency.
- Existing Phase 06 synthetic curriculum baseline was 220 nodes / 39 SQL queries; it does not measure Phase 07.
- MySQL baseline printed in the latest suite: 220 nodes / 39 queries / 290.85 ms; this is a local synthetic test measurement only.

## Not measured

No Phase 07 production-like workspace query count/latency, media stream throughput, completion throughput, cache isolation, queue processing, LCP/INP/CLS, realistic concurrent learner load or production p75 results were measured. No performance target compliance is claimed.

## Follow-up

Use a reproducible Phase 07-only MySQL dataset to measure paginated directory, bounded hierarchy, immutable snapshot, assignment, progress rollup and media paths. Record machine/runtime, dataset, query counts and p50/p95. Add concurrency/load measurements for completion, release and publishing. Measure production Core Web Vitals separately.

## Closure measurement note

The completed local test runs provide functional evidence, not production performance certification. The guarded MySQL regression reported its existing curriculum render baseline at 220 nodes / 39 queries / 81.10 ms in this run; SQLite reported 220 nodes / 39 queries / 63.24 ms. These are local test observations only and are not representative p75 production latency. No new learner-activity or full-course performance benchmark was run. Database and frontend optimizations for the remaining features are still required.
