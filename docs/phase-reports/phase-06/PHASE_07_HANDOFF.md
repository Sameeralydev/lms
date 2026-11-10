# Phase 07 technical handoff

Phase 06 local acceptance passed on 2026-10-26. This document records integration contracts only; Phase 07 implementation did not start in this run.

## Stable curriculum contract

- Course delivery must reference an explicitly published AcademicCurriculumVersion, scoped to the same tenant and program.
- Where lessons or learning resources refer to curriculum nodes, store the published version ID and node ID together and validate their composite ownership.
- Treat published curriculum versions and review evidence as immutable. New curriculum changes create a new revision; delivery must not mutate the prior snapshot.
- Use the approved curriculum version's existing academic program, course catalog and calendar relationships. Do not duplicate those authoritative Phase 03 records.
- Curriculum resource references are design metadata and controlled academic documents. They are not a media streaming, course authoring, enrollment, or learner progress engine.
- Reuse TenantContext, current permission checks, audit conventions, private storage, scan status and test guards.

## Verified foundation

- Laravel 13.38.0 / PHP 8.3.35 / MySQL 8.4.11.
- Dedicated Phase 06 test schema ownership: 122 objects, 34 migrations, matching manifest.
- MySQL backend: 197 passed / 2,153 assertions.
- SQLite backend: 191 passed / 2,065 assertions / 6 documented engine/scanner skips.
- Playwright Chromium: 23 passed / 0 skipped with scanner mode enabled.
- Vitest: 6 passed; PHPStan, Pint, TypeScript, ESLint, Prettier, Composer/npm audits and production build passed.
- No Phase 07 or Phase 08+ feature was created by the Phase 06 verification work.

## Deferred deployment evidence

Hosted CI, production database and queue configuration, production secrets/backups/monitoring, production load/Core Web Vitals, and manual screen-reader/RTL review remain separate deployment or usability work. No standard certification is claimed.
