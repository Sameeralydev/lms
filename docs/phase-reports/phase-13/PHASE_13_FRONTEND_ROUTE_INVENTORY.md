# Phase 13 Frontend and Route Inventory

## Current live route/evidence inventory — 2026-11-05

`ROLE_SCREEN_THEME_MATRIX.json` was regenerated from `php artisan route:list --json` and now records **73 registered examination/appeal route entries**, their route names, controller actions and middleware. It also identifies five applicable role groups, four screen groups and all six registered themes. The inventory deliberately marks route-action-role/campus denial coverage as incomplete; route registration is not a test pass. The fresh full scanner-enabled Chromium run passed 42/42 selected journeys, but did not exhaustively verify every route/action/role/campus cell. See the current [final gate](PHASE_13_FINAL_GATE_REPORT.md) and [test report](PHASE_13_TEST_REPORT.md).

## Latest appeal lifecycle route update — 2026-11-05

Added authenticated `POST t/{tenant}/academics/examinations/appeals/{requestId}/transition`, gated by `exams.recheck.review`. It supports only validated escalation, closure and time-bounded reopening, revalidates result/appeal state, appends immutable appeal history and queues idempotent notices. The results workspace renders the appeal history/actions. `php artisan route:list --path=appeals --except-vendor` confirms one route. Focused authenticated results Chromium passed after this UI change; the complete role-by-screen/theme matrix remains open.

## Final reporting route update - 2026-11-05

## Latest route authorization and browser verification — 2026-11-05

- Current tenant examination/appeal inventory: **73 named routes**, asserted from Laravel's live route collection by `Phase13ExaminationRouteAuthorizationMatrixTest`.
- Negative route requests: **292** (guest, unprivileged active tenant member, foreign-tenant member, revoked member per route); test total **297 assertions** including inventory and unchanged mutation sentinels.
- Full scanner-enabled Chromium: **42/42** passed. A focused examiner marking run passed 1/1 after adding six theme-specific screenshots and axe/mobile/reduced-motion/keyboard assertions. Artifacts are in `test-results/examination-marking-contro-9e3e9-tudent-sees-completion-only-chromium/`.
- Positive role-specific route completion and exhaustive wrong-campus/resource ownership checks remain incomplete. Route-level denial evidence does not substitute for those cases. `EXAMINATION_AUTHORIZATION_MATRIX.json` records each route and these coverage limits.

`php artisan route:list --path=academics/examinations --except-vendor` reports **58 routes**. The results workspace now includes a responsive, labelled session operations table with campus/subject, registration, attendance, examiner marking, moderation backlog, result, recheck and incident counts. Its CSV action is shown only when `exams.analytics.view` is present; the route repeats that permission check server-side and exports only the current tenant/campus-visible report rows with no-store headers and an audit event. `ExaminationOperationsTest` passes 13/469, including unauthorized export denial.

Date-sheet publish now generates candidate-scoped notifications from its immutable revision snapshot; the feature test proves active registered recipient selection and idempotent delivery. Existing role-specific exam routes remain represented in the route collection, but an exhaustive route × role × theme matrix is not complete. Latest full browser result and scanner freshness limitation are documented in `PHASE_13_TEST_REPORT.md`.

## 2026-11-05 examination continuation

Fresh `php artisan route:list --path=examinations` returned **64 examination-related routes**, including candidate governance, admit-document verification, result workspaces, evidence download, and the newly added incident transition endpoint. Route filtering includes both the tenant `/academics/examinations` and student `/examinations` route families.

Added candidate-governance role pages (`workspace/examinations/candidate-governance` for student requests and authorized review) and private admit-document page (`workspace/examinations/admit-document`). Results workspace accepts appeal as a result recheck type and provides incident reporting/evidence upload; it links to evidence only after clean scanning. Authenticated routes cover student accommodation/eligibility-exception requests; authorized policy, review, revocation and admit-document issue/revoke; own admit-document display; public rate-limited code verification; and scoped incident evidence upload/download. The workflows enforce tenant/campus and server-side permissions.

Current evidence: full scanner-enabled Chromium passed 42/42 after incident lifecycle and EICAR integration; the two candidate-governance journeys passed again after the latest outbox change. This is not a route-by-route negative authorization matrix or an exhaustive role/theme matrix. Examiner, student, administrator, invigilator and controller screen coverage remains partial.

## Final continuation update — 2026-11-04

`php artisan route:list --path=academics/examinations --except-vendor` reports **43 routes**. The current workspace adds date-sheet revision creation, submission, independent decision and publication; invigilator self-response; result/recheck operations; and existing cycle, delivery, marking, script, attendance and component workflows. Retake/makeup source-cycle selection is part of cycle authoring. The date-sheet browser journey and result/marking journeys are authenticated and tested.

The route inventory is not equivalent to complete role coverage. Separate end-to-end operations workspaces for a full center/seating administrator and all invigilator operational duties are outstanding; not every route has a route-by-route negative authorization test or six-theme browser check. See current requirement traceability for the bounded acceptance gaps.

Updated 2026-11-04. Verified using `php artisan route:list --path=academics/examinations --except-vendor`; Laravel reports 14 routes.

| Route family | Operations | Current authorization / behavior |
|---|---|---|
| `tenant.academics.examinations.index` | GET workspace | Academic scope plus `academic.view` and `exams.view`; paginated cycles and scoped operational data. |
| `cycles` | POST create; PATCH status | `exams.manage`, with server-checked approve/publish transition permissions and transaction/audit. |
| `cycles/{cycle}/sessions` | POST schedule | `exams.manage`; validates course, campus, room, duration, overlap and cycle lifecycle. |
| `cycles/{cycle}/candidates` | POST register | `exams.candidates.manage`; active same-institution SIS identity and open registration window. |
| `sessions/{session}/seats` | POST allocate | `exams.manage`; candidate/cycle, room/campus, active confirmed course enrollment, effective withdrawal and capacity checks. |
| `sessions/{session}/papers` | POST create version | `exams.paper.manage`; encrypted immutable snapshot and audited authoring. |
| `paper-versions/{version}` | POST submit-review, review, publish; GET confidential-preview | Separate paper permissions and author/reviewer/publisher controls; preview is no-store and integrity checked. |
| `sessions/{session}/invigilators` | POST assign | `exams.invigilators.manage`; institution/campus scoped staff assignment. |
| `cycles/{cycle}/candidates/{candidate}/accommodations` | POST authorize/revise | `exams.accommodation.manage`; encrypted details and revision/supersession history. |
| `sessions/{session}/attendance` | POST record/revise | `exams.attendance.record`; assigned duty or manager scope and audited revision history. |

Frontend: `resources/js/pages/workspace/academics/examinations/index.tsx`, rendered inside the shared workspace shell. It supports the currently listed controller workflows. No Phase 13 student player, examiner/result workspace, dedicated administrator journey, authenticated Phase 13 Playwright journey or six-theme examination matrix exists. The 35-test Chromium regression covers other application areas and is not exam workflow acceptance.


Batch 4 evidence: see [PHASE_13_BATCH_4_EVIDENCE.md](PHASE_13_BATCH_4_EVIDENCE.md) for the superseding verification results and remaining gate.
## Continuation route/UI inventory - 2026-11-04

- Added the examination page seating planner for assignment, submission, independent review/publication and history.
- Added the self-only workspace/academics/exam-duties.tsx page.
- Added tenant GET /t/{tenant}/examination-duties and POST /t/{tenant}/examination-duties/{assignmentId}/response, gated by exams.invigilators.view-own.
- Added four seating routes under /academics/examinations.
- php artisan route:list --path=academics/examinations reports 47 routes; the two duty routes are separate.
- Full scanner-enabled Chromium: 40 passed. Seating and duty journeys checked six themes and axe; invigilator viewport was 390x844.
## Incident workflow route update - 2026-11-05

Added authenticated `POST t/{tenant}/academics/examinations/incidents/{incidentId}/transition`, protected by `exams.incidents.review`, for assignment, escalation, investigation, resolution, closure, and controlled reopening. The examination results workspace integrates reviewer assignment, transition controls and the incident history. The focused authenticated browser journey passed with six-theme axe and mobile checks; full role-by-screen route coverage remains incomplete.

## 2026-11-05 urgent recovery evidence

See [URGENT_RECOVERY_ADDENDUM_2026-11-05.md](URGENT_RECOVERY_ADDENDUM_2026-11-05.md) for the fresh ClamAV signature verification, full scanner-enabled Chromium result (42 passed), repaired E2E timeout/theme synchronization, current quality checks, and remaining Phase 13 gate classification. Historical results above remain historical where superseded; this addendum is the current source for the urgent recovery outcome.



## Current final gap-closure evidence — 2026-11-05
See [FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md](FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md) for the fresh scanner, guarded MySQL, SQLite, browser, reporting, quality-check evidence and exact remaining acceptance gaps. This addendum is authoritative for the current local verdict; historical results above are retained as historical evidence.

## Latest verified inventory � 2026-11-05

Laravel's live route collection contains 73 tenant examination/appeal routes and is checked against EXAMINATION_AUTHORIZATION_MATRIX.json. The focused route test executed 365 denied requests and passed 1,542 assertions. Five denial identities were tested per route; mutation sentinels remained unchanged. Positive route/action cases and concrete campus/foreign-resource pairs are still incomplete.

The full scanner-enabled browser suite passed 42/42. It provides authenticated coverage for applicable role journeys and representative theme/accessibility checks, not an exhaustive 73-route authorization or role � screen � six-theme matrix. See the final gate for the unresolved acceptance scope.
