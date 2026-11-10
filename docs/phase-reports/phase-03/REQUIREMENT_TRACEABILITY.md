# Phase 03 Requirement Traceability

| Requirement area | Implementation | Evidence | Status/known gap |
|---|---|---|---|
| Phase 02 dependency review | Phase 02 checkpoint and handoff reviewed; no blueprint/roadmap file present | `ACADEMIC_DOMAIN_ARCHITECTURE.md`; baseline commit `9060950` | Completed; scope source is user brief |
| Academic settings/operating models | `AcademicDefaults`, `AcademicConfiguration`, `RequireAcademicFeature`, calendar settings page, shared Inertia configuration | Feature-gate adversarial test; E2E enables credits/quality before workflow | Feature routes/content are gated and core headings adapt; detailed label substitution remains partial |
| Year/period lifecycle | `AcademicCalendar`, calendar controller, calendar page | MySQL/SQLite lifecycle/date tests; Playwright journey | Core create/edit/status/current/copy behavior; no printable preview |
| Hierarchy, levels, qualifications | Structure controller/models/page; campus-aware access; nested list + table | Cycle/campus tests; Playwright unit/level creation | Core structure present; advanced hierarchy search and full accessibility audit deferred |
| Programs and groups | `AcademicProgram`, `AcademicClass`, `AcademicSection` | Foreign-key and browser create tests | Generic cohort/batch types; section edit/deactivation and program cloning are not delivered |
| Subject/course catalog | Catalog controller, subject/course/version models/page | Exact-credit and lifecycle tests; browser flow | Catalog create/version/publish/retire supported; bulk import/export deferred |
| Credit policy | `AcademicCreditPolicy`, decimal validation/milli-credit arithmetic | MySQL/SQLite credit test; browser activation/version use | Supported; no GPA/fee calculation by design |
| Regional policy profiles | Profile/version/evidence/mapping/gap models and page | Evidence/mapping requirement test | Framework only; no preverified national law packs |
| Learning outcomes/CASE readiness | Outcome/version/program and course-version association | MySQL/SQLite tests; browser link | References only; CASE import/export deferred |
| Quality foundation | Objective/review/evidence/action/document models and workflow | Review approval/private document tests; browser objective create | Core quality records; no full certification/audit platform |
| Tenant/RBAC/campus isolation | `AcademicRecord`, `AcademicAccess`, `RequireAcademicScope`, explicit permission middleware | Adversarial tenant/campus tests; browser foreign tenant checks | Verified for implemented routes; new routes must extend the allowlist intentionally |
| Audit/history | `AuditRecorder` event writes and academic-only audit page | Audit feature assertions and browser explorer | Tenant-scoped read; operational retention/backup policy remains external |
| Theme/UI | Existing six theme tokens and shared workspace shell | E2E theme selection + axe checks; Vite build | Preserved; not a full design-system audit |
| Security/static/dependencies | PHPStan, audits, guarded suites, security review | `TEST_REPORT.md`, `SECURITY_AUDIT.md` | No known critical/high issue in reviewed scope; not certification |
| Performance | Code-split build and paginated/eager-loaded queries | Bundle evidence in `PERFORMANCE_REPORT.md` | Runtime benchmark not run |
| Hosted CI and final repository checkpoint | Workflow reviewed; local checkpoint to be committed after review | Git commit/status at final gate | Hosted CI cannot be claimed from local run |
