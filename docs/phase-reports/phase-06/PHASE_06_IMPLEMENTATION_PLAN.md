# Phase 06 implementation plan and compatibility matrix

## Inspection baseline

- Laravel 13 application with React, TypeScript, Inertia, Vite and Tailwind.
- Phase 03 owns institution programs, levels, academic years/periods, catalog courses and immutable course-version history.
- Phase 04 owns admissions; Phase 05 owns student identity, enrollment-adjacent placement and lifecycle history. Phase 06 must not write admissions or student records.
- Tenant context is request-scoped. `AcademicRecord` uses `ScopedToTenant`; academic workspace routes use `tenant.workspace`, `tenant.permission`, `tenant.academic.scope` and `tenant.academic.feature` middleware.
- `AuditRecorder` is the established safe metadata audit boundary. Six shared theme tokens and workspace navigation are already in place.
- Git baseline is `phase-04-admissions` at `b667202`. The worktree already contains 134 mixed Phase 04/05 changes and has no Phase 05 acceptance commit. No cleanup, reset or broad commit is authorized.
- The Phase 05 report says PASS while its original traceability retains partial profile, Student 360, guardian authority, custom-field and directory requirements. These remain visible and are not relabeled complete. This phase will not broaden into an SIS rewrite; exact dispositions are recorded in `PHASE_06_REQUIREMENT_TRACEABILITY.md`.
- At the initial inspection, `.env.phase06.testing` did not exist. It is now locally configured and Git-ignored; the current read-only preflight verified the dedicated Phase 06 schema/account and its empty state. Mutation remains blocked until `PHASE06_TEST_SCHEMA_DISPOSABLE=true` is set privately after owner confirmation. No Phase 04/05/development database may be used for Phase 06 MySQL verification.

## Compatibility matrix

| Concern | Existing source of truth to reuse | Phase 06 extension | Compatibility / risk |
|---|---|---|---|
| Institution and tenant | `Tenant`, `Institution`, `TenantContext` | Curriculum records carry tenant and institution keys | Composite foreign keys and `ScopedToTenant`; missing context fails closed |
| Program and academic level | `academic_programs`, `academic_levels` | Curriculum plan references a program and optional level | No duplicate program/course catalogs |
| Course catalog | `academic_courses`, `academic_course_versions` | Curriculum nodes reference course/version | Historical course versions remain authoritative |
| Calendar | `academic_years`, `academic_periods` | Plans and pacing reference year/period | No new academic-session table |
| Learning outcomes | `academic_learning_outcomes` | Curriculum-specific required outcomes and mappings | Existing outcome IDs are tenant constrained |
| Authorization | `PermissionCatalog`, academic middleware | `academic.curriculum.*` permissions and exact route feature mapping | Deny by default; campus scope remains explicit |
| Audit | `AuditRecorder`, `audit_events` | Safe curriculum lifecycle metadata | No raw request payloads or secrets |
| Themes and UI | Workspace shell and six semantic themes | Curriculum screens reuse shell and design tokens | No separate theme engine |
| Documents | Private Phase 04/05 storage/scanner | Curriculum material references may use URLs or existing controlled document IDs | No public upload path or bypass of malware scanning |
| Testing | Guarded SQLite runner, Phase04 MySQL support framework | Add isolated Phase 06 MySQL target, grant policy, ignored env convention and separate manifest/lock | Dedicated MySQL target and exact grants now pass read-only preflight; migration execution still requires explicit disposable authorization |
| Phase 05 gaps | Original Phase 05 brief and current traceability | Keep detailed disposition and any strictly bounded mandatory fixes separate | Do not rewrite SIS schema or hide unresolved acceptance gaps |

## Delivery sequence

1. Add an isolated Phase 06 target policy and runner mode; keep it unusable until the private config is present and valid.
2. Add additive curriculum schema using explicit composite tenant foreign keys and stable identifiers.
3. Implement versioned framework, curriculum plan, hierarchy, outcomes, competencies, prerequisites, pacing, governance and audit workflows behind tenant permissions.
4. Add a workspace experience with real database-backed listings, creation, detail, validation and lifecycle actions.
5. Add SQLite feature tests, component tests and browser workflows; do not claim MySQL evidence until the separate target passes its read-only preflight.
6. Run regression checks and write reports from observed results. Preserve the mixed worktree.

## Architecture decisions

- One curriculum plan is associated with an existing academic program; it references a framework, academic year, and existing catalog records without duplicating them.
- Each curriculum version owns its hierarchy and mappings. Draft edits are optimistic-version guarded. Review and publication are separate state transitions; publication freezes the version, and a new revision copies it.
- Nodes model bounded hierarchy types (`course`, `unit`, `topic`, `subtopic`, `objective`, `lesson`) with tenant-scoped self references and a configured maximum depth. Relationships such as prerequisites and outcome/competency mappings are normalized.
- Coverage analytics report curriculum design coverage only. They do not report learner attainment or assessment results.
- Shared templates are read-only source records adopted through a tenant-owned independent copy.
- Large-scale standards interoperability, teaching delivery, student enrollment, assessment, grade processing and learner analytics remain later-phase contracts.

## Final verification update — 2026-10-26

The earlier inspection notes above are a historical baseline, not current database status. The guarded Phase 06 MySQL suite has since passed 190 tests / 2,055 assertions on the dedicated schema; postflight verified 115 tables/views, 33 migrations and a matching ownership manifest. SQLite, Playwright and all recorded static/build/audit checks also passed. The final acceptance remains BLOCKED because the original Phase 06 scope still lacks delegated framework-administrator assignment, configurable approval/checklist policy and structured curriculum resource/material references. See PHASE_06_FINAL_GATE_REPORT.md and PHASE_06_REQUIREMENT_TRACEABILITY.md. No Phase 07 implementation was started.

