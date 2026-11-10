# Phase 01 performance report

**Updated:** 2026-10-22

## Local measurements

The production Vite build completed in 898 ms locally using Node.js 24.21.0 and npm 11.19.0. Vite 8.3.5 transformed 568 modules:

| Asset | Size | Gzip |
| --- | ---: | ---: |
| app CSS | 19.70 kB | 4.87 kB |
| app JS | 348.42 kB | 109.06 kB |
| welcome JS | 5.87 kB | 1.89 kB |

The final Playwright run completed seven Chromium tests in 4.0 seconds. These are local measurements only. No production-like LCP, INP, CLS, response latency, query count, queue throughput, or error-rate benchmark was performed.

## Measurement conventions

For future performance work, record hardware/runtime/browser/network conditions, representative page and data volumes, response latency and query counts, frontend bundle and p75 Core Web Vitals, queue/cache behavior, and error rates. Targets remain LCP <= 2.5 s, INP <= 200 ms, and CLS <= 0.1 at p75. No production performance compliance is claimed.
