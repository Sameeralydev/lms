# Phase 14 frontend and route inventory — current slice

| Route | Role / permission | Page / behavior | Evidence |
|---|---|---|---|
| `GET /t/{tenant}/academics/grades` | Member with `academic.grades.book.view` | `workspace/academics/grades/index`; scoped policies, sections, gradebooks | SQLite feature test exercises access denial and actual policy/book/item creation. |
| `POST .../academics/grades/policies` | `academic.grades.policy.manage` | Create draft percentage policy/categories | SQLite feature test. |
| `POST .../academics/grades/policies/{id}/activate` | `academic.grades.policy.manage` | Validate and activate weighted policy | SQLite feature test. |
| `POST .../academics/grades/gradebooks` | `academic.grades.book.manage` | Create a gradebook for eligible assigned section | SQLite feature test; full role-by-campus browser coverage absent. |
| `GET .../academics/grades/gradebooks/{id}` | `academic.grades.book.view` plus section scope | `workspace/academics/grades/show`; roster and grade entries | SQLite feature test. |
| `POST .../items`, `PUT .../enrollments/{id}` | `academic.grades.book.manage` | Create manual item and versioned grade entry | SQLite feature test verifies persistence/revision/stale-write rejection. |
| `POST .../transitions/{submit|approve|publish}` | submit/review/publish permissions | Workflow transitions and immutable publication | SQLite feature test verifies independent approval and student publication. |
| `GET /t/{tenant}/academic-records/my-grades` | `academic.grades.view-own` | `workspace/academics/grades/my-grades`; published records only | SQLite feature test verifies published/unpublished states and foreign institution denial. |

No Phase 14 Playwright authenticated browser route was executed. This inventory is not complete for the requested admin, registrar, guardian, report card, transcript, certificate, appeal, or reporting surfaces; those routes/pages do not yet exist.

## Academic GPA and official-record routes - 2026-11-10

Fresh `php artisan route:list --path=academics/gpa --except-vendor` reported 4 routes: workspace index, policy create, policy activate and snapshot calculation. `--path=academics/records` reported 8 routes: issue queue/index, template create/activate, issue prepare/submit/review/issue/revoke. `--path=academic-records` reported 5 routes: public certificate verification, learner records, my-grades, my-progress and private PDF download. Route listing confirms registration only, not complete positive/negative authorization coverage. The authenticated E2E verifies the principal grade publication and transcript issuance path; registrar/guardian and remaining report/certificate journeys remain partial.
