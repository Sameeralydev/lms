# Phase 08 performance report

The local production Vite build completed with 645 modules transformed. The built registration page chunk was approximately 11.53 kB raw / 3.62 kB gzip; the shared application bundle was approximately 358.68 kB raw / 111.97 kB gzip. These are build artifact sizes from the local run, not network-transfer or Core Web Vitals measurements.

Registrar offerings are bounded to the latest 250 rows, instructor rosters are paginated and searchable, and section enrollment counts are eager-loaded. A local SQLite curriculum baseline from the full suite reported 220 nodes, 39 queries and approximately 192 ms; that is not an enrollment benchmark and is not production evidence. No MySQL or production latency/throughput claim is made.
