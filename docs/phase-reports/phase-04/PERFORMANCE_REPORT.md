# Performance report

Vite production build passed: 614 modules transformed; CSS 34.85 kB raw / 7.65 kB gzip; largest JavaScript chunk 354.00 kB raw / 110.81 kB gzip. Build output is local and does not establish runtime Core Web Vitals.

Playwright completed 17 Phase 01–03 journeys in about 2.3 minutes on this Windows development environment. One run exposed an animation-cancellation race in the existing theme test; its wait now tolerates canceled animations, and the final full run passed 17/17. Those scenarios are not representative admissions throughput benchmarks. No synthetic applicant dataset, admissions query count, high-volume concurrency, LCP/INP/CLS, or production latency measurement was run. Do not infer production capacity from these results.

Before release, profile tenant-scoped application list, reviewer workload, dashboard aggregates, form saves, file metadata retrieval, merit calculation, and capacity locking on MySQL with representative fixtures; record hardware/runtime and query plans.
