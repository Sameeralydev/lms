# Phase 09 Integration Contracts

## HTTP surface

Under `/t/{tenant}/academics/timetables`: list; create timetable; save operating settings; create building, room, instructor availability, calendar exception, room block; preview conflicts; add meeting; submit; review; publish; create a copy-on-write revision from a published version. All mutation endpoints use existing tenant middleware and relevant academic permissions.

Personal endpoints under the tenant workspace provide the published student/instructor schedule, CSV export, and iCalendar export.

## Domain contracts

- `TimetableWorkflow` owns authorized state changes and audit events.
- `TimetableConflictChecker` is shared by preview, meeting save, and review submission.
- `LocalScheduleTime::toUtc()` accepts date, local `HH:MM`, and IANA timezone; it returns a UTC instant or a validation error for invalid/ambiguous local times.
- Existing `CourseSection`, `CourseEnrollment`, `TenantMembership`, `AcademicYear`, `AcademicPeriod`, `Institution`, and `Campus` remain the source of truth.

No public API token, external webhook, notification/outbox schedule-change event, or Phase 09 database test-runner contract exists yet. Those must be implemented before consuming schedule changes from mobile or external integrations.
