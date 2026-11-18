# Phase 15 metric registry

The registry in `app/Domain/Analytics/AcademicMetricRegistry.php` currently exposes twelve aggregate metrics. Calculations are in `AcademicAnalyticsService`; queries are scoped by the active tenant and institution, with campus scope enforced from the membership or validated filter. Cache entries include membership, role permissions, and normalized filters and expire after 60 seconds.

| Key | Source and definition | Calculation and time basis |
|---|---|---|
| `students.active` | Active SIS profiles and effective placements | Count active profiles. Campus uses a currently effective placement; home campus is used only when no current placement exists. Current snapshot. |
| `enrollments.confirmed` | Confirmed course enrollments and sections | Count confirmed rows by `confirmed_at`. |
| `admissions.submitted` | Submitted applications and admission intakes | Count submitted applications by `submitted_at`. |
| `attendance.rate` | Finalized registers, entries, and status definitions | Expected attended marks / all expected marks × 100; null when denominator is zero. Register occurrence date. |
| `gradebooks.pending_review` | Gradebooks and course sections | Count submitted gradebooks; current workflow state. |
| `gradebooks.published` | Gradebooks and course sections | Count published gradebooks; current workflow state. |
| `records.issued` | Official record issues and SIS profiles | Count currently issued report card/transcript/certificate issue rows by `issued_at`. Does not count version-history rows. Campus currently uses the student's home campus. |
| `assignments.published` | Assignments, section links, course sections | Count distinct published assignments by `published_at` with a linked section from the assignment's own offering in the selected campus. |
| `assignments.submissions` | Assignment submissions, attempts, recipients/groups, sections | Count each submission once when it has a submitted attempt; campus is resolved through its actual recipient or group's section, constrained to the assignment offering. Uses attempt `submitted_at`. |
| `quizzes.published` | Quizzes, quiz-section links, course sections | Count published quizzes by `published_at`; campus filter requires a linked section. Includes no questions, responses, confidential items, or unreleased scores. |
| `exams.sessions.published` | Exam sessions and cycles | Count published sessions by scheduled start. Campus uses the session campus. No paper or candidate detail is returned. |
| `exams.results.released` | Exam results, sessions, cycles | Count current result rows with `released_at`; unique session/candidate rows prevent result-version double counting. Only released rows are included. |

All metrics require `academic.analytics.view`; the CSV route additionally requires `academic.analytics.export`. The workspace currently supports campus, date, and metric filters, an enrollment trend, accessible tables, and aggregate CSV export with an audit event. Empty finalized attendance is shown as “No finalized attendance data.”

Saved report filters are membership-private, limited to 20, and validated against the requesting member's campus scope and registry metrics; they do not snapshot source values. Academic period, course/section, and status filters; generalized drill-down/search/pagination; generated PDF output; queued snapshots; and dashboard-specific admissions/assignment/quiz/exam drill-downs remain incomplete. Browser print is available. No unsupported predictions, financial metrics, or production activity estimates are shown.
