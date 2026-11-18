# LMS owner demo guide

## Start the local application

Use the PHP 8.3 executable documented for this workstation and the project's private local `.env`:

```powershell
C:\php83\php.exe artisan serve --host=127.0.0.1 --port=8000
```

The configured local entry URL is `http://127.0.0.1:8000`. A persistent owner-demo server was not running or independently HTTP-checked during the current implementation batch; Playwright uses an ephemeral, isolated test server and database and is not an owner demo environment. Do not expose the local development server to a public network.

## Role workspaces and supported entry routes

All institution users enter through the same membership-scoped dashboard; the available cards and navigation are determined by active tenant membership, role permissions, campus assignment, and enabled academic features.

| Role | Entry route / representative pages |
|---|---|
| Platform operator | `/platform/dashboard`; tenant directory `/platform/tenants`; operator and audit links appear only with platform permissions |
| Institution owner/administrator | `/t/{tenant-slug}/dashboard`; institution, campuses, members, academics, admissions, SIS, registration, attendance, and reports according to permissions/features |
| Campus administrator | Same tenant dashboard, with assigned campus-scoped operations and analytics |
| Academic administrator/leadership | Same tenant dashboard; `/academics`, `/academics/analytics`, grades, GPA, records, timetables, and examination operations as allowed |
| Instructor/teacher | Same tenant dashboard; `/instructor/schedule`, `/instructor/sections`, `/learning`, `/assignments`, `/assessments`, attendance, gradebooks, and allocated examination work |
| Examination controller | Same tenant dashboard; `/academics/examinations`, candidate governance, result management, and other controller routes allowed by role |
| Examiner | Same tenant dashboard; `/academics/examinations/marking` and only allocated scripts/results |
| Invigilator | Same tenant dashboard; `/examination-duties` and assigned duty responses |
| Student | Same tenant dashboard; `/registration`, `/my-assignments`, `/assessments`, `/examinations/my`, `/examinations/results`, `/academic-records/my-grades`, and personal attendance |
| Guardian | Same tenant dashboard; linked learner attendance/grades/records only where a verified active authority grant permits it |

Use the actual tenant slug provisioned in the local database. The tenant workspace route pattern is `/t/{tenant-slug}`. No demo account or password is supplied or created by this guide.

## Analytics and reporting

- Academic analytics: `/t/{tenant-slug}/academics/analytics`
- Academic reports: `/t/{tenant-slug}/academics/reports`
- Assignment analytics: `/t/{tenant-slug}/assignments/analytics`
- Assessment analytics: `/t/{tenant-slug}/assessments/analytics`
- Attendance reports: `/t/{tenant-slug}/attendance/reports`
- Examination result/operations exports are linked from the authorized examination result workspace.

Analytics access depends on server-side permission and tenant/campus scoping. The Phase 15 academic workspace currently contains twelve aggregate metrics, an enrollment trend/table, filters and audited CSV export; it is not a general-purpose report builder.

## Suggested walkthrough

1. Sign in with an already provisioned local account whose role is known to the operator.
2. Confirm the selected institution and any campus assignment in the header.
3. Review the dashboard cards; unavailable permissions/features should not be linked.
4. Open a module, apply its available filters, then use the module's own authorized create/review/publish controls.
5. For analytics, filter by campus/date/metric, inspect the accessible table and export only aggregate rows.
6. Change the theme from the workspace header and verify it remains selected after navigation.
7. Sign out before handing the workstation to another user.

## Limitations

Phase 15 remains partial. The complete dashboards for all roles, cross-domain report builder, drill-down/search/pagination/PDF exports, broad six-theme role-screen matrix, Phase 15 MySQL suite and eight analytics concurrency probes are not complete. MySQL is not configured or run by this guide. Do not use synthetic browser fixtures as real demo data.

## Updated Phase 15 verification status — 2026-11-17

The guarded Phase 15 MySQL suite is now configured and passed (15 tests / 226 assertions); all eight analytics concurrency probes passed with independent session evidence. The analytics workspace remains an aggregate reporting slice, not a general report builder. Full Chromium passed 47/47, including scoped 72-case dashboard/theme and 78-case examination role/screen/theme matrices. The full SQLite run remains blocked by six real EICAR integration failures because host protection intercepted or replaced the fixture. See `PHASE_15_FINAL_CLOSURE_ADDENDUM_2026-11-17.md`. No demo credentials are included.
