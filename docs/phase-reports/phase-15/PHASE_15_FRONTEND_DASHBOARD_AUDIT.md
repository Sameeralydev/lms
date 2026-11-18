# Phase 15 frontend/dashboard audit

Laravel's runtime route collection generated a 494-row tenant route inventory; 471 records have an inferred React page component and 23 routes are mutations, exports, downloads, or other delegated responses. This verifies route/page inventory presence only, not full page action correctness. See `PHASE_15_FRONTEND_ROUTE_INVENTORY.md` and its JSON source.

| Area | Current finding |
|---|---|
| Platform control plane | Real aggregate overview and platform routes exist. The create-institution action is now permission hidden. Full operator/role dashboard browser matrix remains outstanding. |
| Institution/campus workspace | Shared membership-sensitive dashboard has real metrics/cards and now links to more existing workflows. It is not a distinct, complete screen for every role; campus isolation remains enforced by backend routes/services. |
| Academics | Shared academic administration and grades/records/GPA/timetable routes exist. Academic analytics has twelve aggregate metrics and is feature-flag filtered in cards/navigation. |
| Admissions/SIS | Existing workflows are linked when the permission and student-information feature allow; no dedicated dashboard rollups were added in this batch. |
| Learning/assignments/quizzes/attendance | Existing pages and reporting routes are now easier to discover from navigation/dashboard by permission. Separate Phase 15 domain analytics and comprehensive browser coverage remain incomplete. |
| Examinations | Existing controller, examiner, invigilator, and learner routes/cards are linked by permissions. The role-specific dashboards and six-theme role-by-screen matrix are not complete. |
| Student/guardian | Existing personal and verified guardian surfaces remain available. Current dashboard metrics do not yet aggregate every requested academic, assignment, assessment, record, and document category. |
| Navigation | Permission-filtered responsive sidebar includes additional analytics/report pages. It remains a flat list; nested groups, breadcrumb system, search, notification center, and full route discoverability audit remain outstanding. |

## Fresh frontend evidence for this continuation

- Playwright `phase15-analytics.spec.ts`: **2 passed**. Owner workflow checks dashboard links, analytics filters/aggregate export and six-theme axe, keyboard, persistence, and mobile behavior; learner role is denied analytics and does not receive the tested administrative cards.
- No complete role × screen × theme matrix was run. Six screenshots belong to the analytics page only and must not be read as the full LMS matrix.
- Full historical Chromium 46/46 is retained in the previous test report as historical evidence. It was not rerun in full in this continuation.
- Manual screen-reader testing was not performed.

Full Chromium verification update - 2026-11-16: 42 passed, 3 failed, 1 skipped. The role dashboard audit failed for the Academic administration workspace because the expected H1 was absent. The Phase15 analytics screens passed in six themes, but the full role × screen × theme matrix remains incomplete. Examination marking and results failures were scanner-disabled in the full run; targeted scanner-enabled results passed while marking failed on Defender-intercepted EICAR HTTP 500. No claim of complete dashboards or frontend acceptance is made.
# Dashboard verification update — 2026-11-16

The academic administrator dashboard title matching defect is fixed: identity now selects the academic administration workspace before the generic exam/analytics permission branch. The feature regression passed, and the targeted Chromium frontend audit passed **3/3**, including dashboard entry points for representative roles, guardian attendance on a mobile viewport, and persisted six-theme selection with axe checks.

This is a targeted result, not complete dashboard acceptance. Distinct role-specific dashboards, all requested database-backed summary/action coverage, and the full role × screen × theme browser matrix remain **PARTIAL/NOT RUN**. The frontend route inventory is route/component inventory evidence, not proof every page action is functional.


## Current status — 2026-11-17

The dashboard/report-builder scope remains **PARTIAL**. No full authenticated role workspace matrix was executed in this continuation. The transactional quota/audit repair applies only to saved analytics reports; it does not implement the broader report catalog, row-level drilldowns, general report tables, or complete dashboard actions requested for Phase 15.
## Fresh browser evidence — 2026-11-17

The full Chromium suite passed 47/47. The dashboard/theme smoke matrix passed 72/72 across 12 role contexts, and the examination matrix passed 78/78 across five roles and 13 examination screens. These checks verify authenticated headings/navigation and scoped database-backed page content; they do not assert every dashboard card, action, filter and module operation for every role. The frontend route inventory and general dashboard completeness therefore remain **PARTIAL**, not complete.

Academic analytics currently presents 12 source-backed aggregate metrics, campus/date/metric filters, saved membership-private configurations, aggregate CSV and print. The broader cross-domain report builder, full record-level drill-down, searchable/sortable/paginated result tables, PDF and complete action matrix are still outstanding.
## Latest browser evidence � 2026-11-18

Authenticated dashboard/theme matrix: **72/72 passed** (12 roles � one dashboard � six themes). Examination role/screen/theme matrix: **78/78 passed**. The scanner-enabled full Chromium run was **43 passed / 4 failed**, exit 1; all four failed journeys passed in a subsequent focused **7/7** rerun after increasing only the affected E2E wait bounds. The current analytics export-history UI passed the focused analytics browser run **2/2**. These results do not establish all-role/all-module coverage. See [fresh closure evidence](PHASE_15_FINAL_CLOSURE_ADDENDUM_2026-11-18.md).

## Report-builder implementation continuation — 2026-11-18

Added a selectable measure to the curated report builder. Enrollment reports can compare enrollment records with distinct learners; finalized-attendance reports can compare marks with distinct learners. Other datasets expose only their semantically valid record count. Expressions are fixed server-side allowlist entries; user input cannot supply SQL or select an unsupported measure. The selected measure persists in membership-owned saved reports, applies identically to preview and CSV export, is included in export audit metadata, and labels the result column. Grouped results also render a responsive bar visualization backed by the current page; the accessible results table remains the text equivalent. Requested pages clamp to the last available page.

Fresh evidence: `php tests/run.php --phase=15 --profile=sqlite --no-report` exited 0 with 16 passed / 382 assertions. Coverage verifies that distinct learner count differs from record count, invalid dataset/measure combinations return 422, saved measures reapply, CSV uses the matching aggregation and formula neutralization, and the attendance aggregation query is valid. `composer analyse`, focused Pint, TypeScript, ESLint, Prettier, Vitest (24 passed), and production build (697 modules) passed. Authenticated Phase 15 analytics Chromium passed 2/2 after rebuilding assets; the report control, empty state, export history, saved configuration, denial case and six-theme persistence/axe/mobile checks ran. Six screenshots are in `test-results/phase15-analytics-theme-evidence/`.

Remaining report scope: only four curated source datasets are available; record-level drill-down, generated PDF, scheduled reports and a full Phase 01–14 catalog remain incomplete. A new read-only guarded MySQL preflight exited 1 because the previously owner-pinned server refused connection; no MySQL operation ran. Prior MySQL postflight and 8/8 concurrency results remain historical. The last full SQLite suite remains 382 passed / 6 failed / 4 skipped, with six ClamAV/EICAR cases still failing due host interception; the full cross-phase Chromium suite was not rerun after this focused change. Phase 15 remains PARTIAL / BLOCKED; Phase 16 was not started.
