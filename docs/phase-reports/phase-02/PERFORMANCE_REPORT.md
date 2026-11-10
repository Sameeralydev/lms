# Phase 02 Performance Report

**Run date:** 2026-10-22

## Local measurements

| Measurement | Result | Conditions |
|---|---|---|
| Vite production build | 807 ms; 597 modules transformed | Local Windows workstation, Node 24.21.0, Vite 8.3.5. This is build time, not user-perceived runtime. |
| Main JavaScript bundle | 351.74 kB raw / 110.16 kB gzip | Production build output. |
| Main CSS bundle | 29.99 kB raw / 6.86 kB gzip | Production build output. |
| Public home HTTP response | HTTP 200; median 129.33 ms, maximum 159.34 ms | One warm-up followed by 12 serial PowerShell `Invoke-WebRequest` samples against the PHP 8.3 Laravel development server on loopback. No concurrency or network latency. |
| Built JavaScript asset delivery | HTTP 200; 351,749 bytes | Same local development server serving the generated Vite asset. |
| Liveness response | HTTP 200 JSON | Same local development server. |
| Tenant directory query shape | 10 read-only samples; 1 query per sample; median 0.615 ms, maximum 17.58 ms | Isolated MySQL 8.4 test schema, empty tenant result set, Eloquent pagination/count query as used by the platform directory. The result set contained zero tenants, so this is an empty-state query baseline only. |

## Interpretation and limits

These are local synthetic measurements, not production latency, throughput, or Core Web Vitals. The public response measurement does not exercise authentication or tenant data. The tenant directory sample ran against the isolated test schema with no tenants; it does not represent populated datasets or concurrent use. Query instrumentation was read-only and the temporary measurement script was removed afterward.

No queue throughput benchmark was run because the local queue driver is synchronous. Cache and session use local file drivers. Redis is not required for current Phase 02 local development; production cache/queue topology needs a separate capacity and isolation review.

No N+1 issue was observed in the reviewed list/dashboard query shapes: tenant directory uses eager-loaded institution data, aggregate counts, and 15-row pagination; workspace dashboard uses bounded aggregates and an eight-event recent list. Larger synthetic datasets and query plans remain a future performance gate before production.

Future production targets remain LCP ≤ 2.5 s, INP ≤ 200 ms, and CLS ≤ 0.1 at p75. They were not measured here.
