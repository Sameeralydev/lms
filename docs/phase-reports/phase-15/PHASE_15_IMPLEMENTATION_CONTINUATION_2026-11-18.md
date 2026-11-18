# Phase 15 Implementation Continuation — 2026-11-18

This addendum records development performed on 2026-11-18. It supplements prior Phase 15 reports; prior test outcomes remain historical and are not rewritten.

## A. Report Builder implementation

`AcademicReportBuilder` now contains 14 curated, tenant/institution-scoped source definitions backed by existing tables and joins:

1. Course enrollments
2. Admission applications
3. Finalized attendance
4. Examination sessions
5. Student profiles
6. Academic gradebooks
7. Current GPA and academic-standing snapshots
8. Published coursework assignments
9. Submitted coursework
10. Published quizzes
11. Released quiz attempts
12. Released examination results
13. Issued official academic records, including transcript/certificate/report-card record types
14. Admission application fee obligations

Each source has a fixed permission, field/dimension allowlist, measure allowlist, safe filters, date basis, tenant/institution joins, and campus scope. User input is never treated as a table, SQL expression, or arbitrary column. The catalog shown to a member is restricted to data sources the member can access, and an omitted dataset defaults to the first source authorized for that member.

GPA analytics select the latest snapshot for each student/policy/scope and the latest active version of each GPA policy key. The report exposes snapshot count, unique learners, and average snapshot GPA; it does not mislabel this average as a student-weighted institution GPA. The underlying older immutable snapshot remains unchanged.

Grouped reports retain bounded pagination, search, sorting, export caps, preview/CSV consistency, and private saved configurations. Drill-down applies the same source permission, tenant/institution/campus/date/status/filter scope as the aggregate. It returns privacy-limited records, paginates them, and includes each record's measure contribution. Unique-learner drill-down groups by student so detail counts reconcile to the distinct learner aggregate.

The report UI now changes its initial dimension and status-filter controls based on the selected source. The report measure is preserved when opening drill-down. PDF export is server-generated through the existing `AcademicRecordPdf` renderer, carries source, measure, filters, date range, institution/campus context, generation time, and grouped values, and uses private/no-store headers plus export authorization/audit.

The finance source is limited to existing admission-fee obligations. A general tuition ledger/finance source is not claimed because that owning module is not present in the integrated source tree.

## B. Scheduled reports

The existing Phase 15 schedule implementation now has focused coverage of the actual due-schedule dispatcher path as well as the generation job. Schedule rows are timezone-aware and support daily, weekly, and monthly recurrence. The dispatcher creates one run row for each `(schedule, scheduled_for)` occurrence, advances the next run under a row lock, and dispatches after commit. The job claims a queued run under a row lock, rechecks schedule owner and authorized recipients at execution time, rebuilds the saved report inside tenant context, writes the PDF to private local storage, delivers an idempotent database notification, and audits completion. Repeated job invocation does not create duplicate notifications or repeat a completed run.

Schedule creation, editing, pause/resume/cancel, recipient selection, recent run history, and authorized private downloads are connected to the existing Inertia workspace. Mail delivery was not configured or claimed; see [the deferred QA backlog](../../qa/DEFERRED_PRODUCT_QA_BACKLOG.md).

## C. Role dashboards

The existing shared dashboard controller remains data-driven and permission-filtered. It selects a role-specific title/description and provides real links only for granted module permissions. Its database-backed work summaries include institution/campus membership and course counts, admissions intakes/applications, instructor-assigned sections and submitted work, learner-owned registrations/assignments/workspaces/attendance/examinations/results, verified guardian links, examination controller queues, examiner allocations, and invigilator duties. Platform administration uses actual tenant/institution/campus/membership counts and recent audit data. Existing empty states remain in the dashboard component.

No dashboard redesign or fake metrics were added. Earlier scoped dashboard/theme screenshots remain historical. A comprehensive newly executed role × screen × theme action matrix was not run during this development continuation.

## D. Changed source and UI files

- `app/Domain/Analytics/AcademicReportBuilder.php` — source catalog, permission filtering, default source, GPA source, privacy-limited drill-down and contribution values.
- `app/Http/Controllers/Workspace/AcademicAnalyticsController.php` — per-member catalog default, PDF context, drill-down/report measures, schedule actions and authorized downloads.
- `app/Domain/Analytics/AcademicAnalyticsScheduleService.php` — explicit validated data contracts.
- `app/Jobs/GenerateAcademicAnalyticsReport.php` — scheduled PDF generation context, private path, recipient revalidation and idempotent notification behavior.
- `app/Models/AcademicAnalyticsReportSchedule.php` — schedule casts and typed relation metadata.
- `resources/js/pages/workspace/academics/analytics/index.tsx` — dynamic dimensions/status fields, source catalog, report/schedule interactions.
- `resources/js/pages/workspace/academics/analytics/drilldown.tsx` — privacy-limited records and measure contributions.
- `tests/Feature/Phase15AcademicAnalyticsTest.php` — source authorization, latest GPA snapshots, drill-down reconciliation, PDFs, due dispatch, run idempotency.

No database migration was executed against the development database. The focused SQLite runner exercised the Phase 15 tests and their isolated schema setup. No credentials or environment files were included in this addendum.

## E. Essential development verification

Fresh evidence from this continuation:

| Command | Result |
|---|---|
| `php tests/run.php --phase=15 --profile=sqlite --no-report` | **PASS** — 20 tests, 571 assertions, exit 0 |
| Focused PHP syntax checks for the report builder, controller, job, dispatcher, and Phase 15 feature tests | **PASS** — all checked files parsed |
| `vendor/bin/phpstan analyse app/Domain/Analytics/AcademicReportBuilder.php app/Domain/Analytics/AcademicAnalyticsScheduleService.php app/Domain/Analytics/AcademicAnalyticsScheduleDispatcher.php app/Http/Controllers/Workspace/AcademicAnalyticsController.php app/Jobs/GenerateAcademicAnalyticsReport.php app/Models/AcademicAnalyticsReportSchedule.php tests/Feature/Phase15AcademicAnalyticsTest.php --no-progress --memory-limit=512M` | **PASS** — no errors |
| Focused `vendor/bin/pint --test` after formatting touched PHP files | **PASS** — see final command verification below |
| Focused TypeScript, ESLint, Prettier and production build | **PASS** after final edits; exact current command outcomes are recorded in the final gate refresh |
| `git diff --check` | **PASS** — only existing CRLF normalization warnings in Phase 14 reports |

The first focused run found and repaired a drill-down SQL expression selection issue. The first static/format checks found type annotations and formatting issues; these were corrected, then checks were rerun. No failure was suppressed.

## F. Remaining implementation boundaries and QA

The complete original Phase 15 product contract/R&D roadmap was not found as a separate authoritative repository document; source coverage is based on current Phase 15 reports, the existing route/schema inventory, and present domain modules. Later finance-module integrations cannot be completed against absent source models. No arbitrary report query facility is provided. Broad browser/theme acceptance, guarded MySQL execution, full regression, EICAR, hosted CI, and human assistive-technology review are listed with the exact status **QA PENDING — EXTERNAL ENVIRONMENT** in `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md`.

**PHASE15_IMPLEMENTATION=PARTIAL**
**PHASE15_ENGINEERING=QA_PENDING**
**PHASE16=NOT_STARTED**

## Status clarification — 2026-11-18

The Report Builder work in this addendum is implemented and focused checks pass. Overall phase status is **PHASE 15 — DEVELOPMENT IN PROGRESS | IMPLEMENTATION WORK REMAINS**, because the original approved Phase 15 contract was unavailable and the integrated source tree lacks general finance data needed to establish/implement the full analytics source scope. External verification items remain **QA PENDING — EXTERNAL ENVIRONMENT** as itemized in the backlog; they are not being mislabeled as completed development.
