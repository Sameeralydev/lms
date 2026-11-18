# Phase 15 frontend and route inventory

## Generated inventory

The machine-readable inventory is [PHASE_15_FRONTEND_ROUTE_INVENTORY.json](PHASE_15_FRONTEND_ROUTE_INVENTORY.json). Regenerate it after route/page changes with:

```powershell
php tests/Support/generate-phase15-frontend-inventory.php
```

The generator bootstraps Laravel, reads the actual route collection, and records every tenant route (`/t/{tenant}...`), methods, route name, controller/action, middleware, inferred Inertia components, and whether the corresponding React page file exists. It does not connect to a database or infer that an endpoint's entire workflow is complete merely because a page exists.

At generation on 2026-11-13, Laravel exposed **494 tenant route records**. **471** records have an inferred React component file and **23** are non-Inertia or delegated responses (such as exports, mutations, downloads, and redirects). The inventory records component presence, not behavior; some controllers render multiple pages and the controller method still needs human review. This is not a 494-route functional acceptance matrix.

## Existing route families connected to the shared tenant workspace

| Workspace family | Representative existing routes/pages | Dashboard/navigation state |
|---|---|---|
| Academic administration | `/academics`, calendar, catalog, curriculum, grades, GPA, reports, official records, analytics, timetables | Permission gated; academic links also filtered by enabled feature flags |
| Admissions and SIS | `/admissions`, `/sis`, student profiles | Permission gated; SIS link respects the student-information feature flag |
| Registration | `/registration`, `/registrar/enrollment`, instructor sections/rosters, personal schedule | Student, registrar, and instructor links gated independently |
| Learning | `/learning`, workspace lessons, sessions, templates, analytics | Existing functional pages; discoverable to delivery roles |
| Assignments | `/my-assignments`, `/assignments`, submissions, grading, extensions, analytics, notifications | Learner and staff paths are permission gated |
| Quizzes | `/assessments`, attempts, grading, regrades, analytics, notifications | Learner/staff paths and analytics link are permission gated |
| Attendance | operations, personal attendance, guardian attendance, reports, corrections, justifications | Role/permission gated; reports link added |
| Examinations | schedule, candidate governance, date sheets, papers, seating, marking, invigilator duties, results | Role-specific dashboard entry points added; existing paper/result controls remain server authorized |
| Tenant administration | profile, campuses, members, roles, branding, domains, settings, audit | Existing shared sidebar and role permissions retained |
| Platform control | dashboard, tenants, operators, audit, settings | Separate platform sidebar and platform permission middleware |

## Changes made in this continuation

- Added missing shared navigation entries for timetable, assignment, assessment, and attendance analytics/report pages.
- Added permission-gated dashboard entry points for gradebooks and approvals, official records, GPA, calendar/timetables, assignment and quiz analytics, attendance reports, examination operations/results/marking/duties, and learner examination pages.
- Dashboard academic links are filtered against the tenant's enabled academic feature configuration. Campus memberships still receive only URLs whose server endpoints enforce their campus scope.
- Added a regression test proving an institution owner sees authorized operations and a student receives learner links without administrative examination/records links.

## Known inventory limits

The route generator does not currently derive action-level data dependencies, actual UI action completeness, or browser test coverage per route. The role × route × theme matrix remains incomplete. Existing Inertia pages in Phase 01–14 are not thereby certified complete or accessible. The focused Phase 15 E2E suite covers the analytics workspace only; the historical full Chromium suite is regression evidence, not a Phase 15 dashboard matrix.
