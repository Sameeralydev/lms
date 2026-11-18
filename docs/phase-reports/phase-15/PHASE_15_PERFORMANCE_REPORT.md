# Phase 15 performance report

The current report uses SQL counts and grouped `DATE(confirmed_at)` aggregation; it does not hydrate whole student or enrollment tables into React. The enrollment timeline is bounded to at most 366 days. The analytics response is cached for 60 seconds using a key scoped by tenant, institution, membership, user, campus, permission set and filters.

No representative large synthetic dataset, query-count baseline, load test, queue/export benchmark, p75 Core Web Vitals, or production throughput measurement was collected. No performance-compliance claim is made. A production report builder should add approved snapshot/queue processing only after real query-cost evidence.

Local Vite build observation with Node **24.21.0** and PHP CLI **8.3.35**: the main application bundle was **366.50 kB raw / 113.86 kB gzip** and the generated stylesheet was **57.65 kB raw / 11.21 kB gzip**. These are build artifact sizes, not browser transfer/CWV or production performance results.
