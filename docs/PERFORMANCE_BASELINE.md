# Performance baseline

## Measurement conventions

- Capture HTTP status and warm/cold response time with the runtime, operating system, PHP SAPI, and concurrency stated. Keep local synthetic timings separate from production p75 data.
- Use Laravel query listeners or Telescope/Pulse in non-production profiling environments to record query count, total query time, duplicate queries, and slow-query samples. Do not log full SQL bindings that contain personal data.
- Record compressed and raw production asset sizes from the Vite manifest. Track route-level JavaScript separately from the shared shell.
- Measure LCP, INP, and CLS with real-user monitoring after deployment; local Playwright smoke checks do not prove field Core Web Vitals.
- Measure queue throughput, retry/dead-letter rates, and cache hit/miss/latency once Redis-backed workloads exist.
- Track HTTP error rate and p50/p75/p95 response time by route, without high-cardinality personal identifiers.

## Future frontend goals

- LCP at or below 2.5 seconds at p75.
- INP at or below 200 milliseconds at p75.
- CLS at or below 0.1 at p75.

These are target thresholds, not local test results or production compliance claims. Phase-specific observations are in `docs/phase-reports/phase-01/PERFORMANCE_REPORT.md`.
