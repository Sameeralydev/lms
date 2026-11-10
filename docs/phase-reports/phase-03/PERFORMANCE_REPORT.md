# Performance Report

## Measurements

Local production build: Vite 8.3.5 transformed 605 modules. The generated `app` JavaScript entry was 352.77 kB (110.48 kB gzip); `app` CSS was 33.18 kB (7.40 kB gzip). Academic routes load code-split chunks: structure 34.73 kB, catalog 21.91 kB, calendar 20.49 kB, quality 19.54 kB, and policies 17.59 kB before gzip.

Runtime: local Windows workstation, PHP 8.3.35 CLI/development server, MySQL 8.4.11, Node 24.21.0. These are build artifact sizes; no page response latency, SQL query count profile, throughput/load, Core Web Vitals, queue throughput, or production CDN measurement was executed. No synthetic performance claims are made.

## Query/design observations

Academic year and catalog/structure directories use server pagination (typically 15–20 records), with eager-loaded relationships and counts for displayed labels. Dashboard totals are database counts scoped to the tenant model. Tenant cache key naming is inherited from Phase 02; this phase does not add academic cache entries.

## Next measurement gate

Before production planning, capture query count/duration on representative dashboard, year/period, program, and catalog pages with a seeded synthetic dataset; record hardware, dataset size, warm/cold state, and PHP/MySQL configuration. Browser RUM should measure LCP/INP/CLS at p75; local synthetic checks cannot establish those targets.
