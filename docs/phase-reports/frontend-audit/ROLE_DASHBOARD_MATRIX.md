# Role Dashboard and Workspace Matrix

Audit date: 2026-10-30. Browser identities are synthetic and provisioned only in the per-run isolated SQLite E2E database by `tests/e2e/prepare-database.php`. They are not application accounts or development database records.

| User role | Dashboard route and page | Data shown | Authorized navigation checked | Browser evidence | Result |
|---|---|---|---|---|---|
| Platform super administrator | `/platform/dashboard` → `platform/dashboard` | Tenant, institution, campus, active-membership counts and recent platform audit events from the database | Institutions, operators, audit history | `frontend-audit.spec.ts`; screenshot `screenshots/platform-administration.png` | Verified |
| Institution owner / administrator | `/t/{tenant}/dashboard` → `workspace/dashboard` | Institution/campus/member metrics and permission-authorized module links | Institution profile, campuses, members, academics, student registry | `frontend-audit.spec.ts`; screenshot `screenshots/institution-dashboard.png` | Verified |
| Campus administrator | Same tenant dashboard | Campus count constrained to the assigned campus | Institution profile and campuses; members, admissions, registry, and audit links absent | `frontend-audit.spec.ts`; screenshot `screenshots/campus-dashboard.png` | Verified |
| Academic administrator | Same tenant dashboard | Active course and academic-service summary | Academics, curriculum, timetables, enrollment administration; institution/member administration absent | `frontend-audit.spec.ts`; screenshot `screenshots/academic-administration.png` | Verified |
| Academic registrar | Same tenant dashboard | Open offerings and registration service summary, campus-scoped for the test membership | Student registry, enrollment administration, attendance operations; institution/member/admissions/academic-administration links absent | Dedicated `sis.registrar` fixture and live-metric assertions in `frontend-audit.spec.ts`; screenshot `screenshots/registrar-dashboard.png`; full Playwright run passed | Verified |
| Admissions officer | Same tenant dashboard | Intake/application counts available to the role and campus scope | Admissions only among business modules | `frontend-audit.spec.ts`; screenshot `screenshots/admissions-dashboard.png` | Verified |
| Instructor / teacher | Same tenant dashboard | Assigned section counts; teaching, attendance, and assessment entry points | Instructor schedule, course delivery, assignment management, attendance operations; institution/member/admissions/registry links absent | `frontend-audit.spec.ts` and assignment/timetable/attendance journeys; screenshot `screenshots/instructor-dashboard.png` | Verified |
| Student / learner | Same tenant dashboard | Own confirmed registrations and available authorized learning services | Course registration, my assignments, own attendance; administrative links absent | `frontend-audit.spec.ts` and registration/assignment/attendance journeys; screenshot `screenshots/student-dashboard.png` | Verified |
| Guardian / parent | Same tenant dashboard | Only verified, active profile-access grants | Family attendance only; student, institution, and staff administration links absent | `frontend-audit.spec.ts`; screenshot `screenshots/guardian-dashboard.png` | Verified |

## Guardian attendance workflow

The sidebar and dashboard link to `/t/{tenant}/attendance/guardian`. The controller lists only verified, unrevoked guardian grants with `can_view_profile`; each learner card links to its authorized attendance detail. Revocation is covered by the backend feature test. The browser journey opens the verified learner detail on a 390px viewport, checks the named learner, axe WCAG 2.2 AA rules and no page-level horizontal overflow. Screenshots are `screenshots/guardian-linked-learners.png` and `screenshots/guardian-attendance-mobile.png`.

## Scope notes

These checks prove the rendered role entry pages, actual dashboard metrics, selected navigation visibility, and the listed critical journeys. They do not prove that every route in the 89-component inventory is visually or functionally complete for every possible permission combination. See `FRONTEND_ROUTE_INVENTORY.md` for the complete route wiring list and `FRONTEND_TEST_REPORT.md` for executed suite results.
