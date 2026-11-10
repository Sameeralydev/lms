# Phase 09 Architecture

## Delivered scheduling slice

Phase 09 adds a scheduling domain under `App\Domain\Academic\Timetabling` and leaves the application as a modular monolith. The workflow uses the accepted tenant context, institution/campus model, academic years and periods, course sections, assigned instructors, enrollments, RBAC, and audit recorder. No duplicate student, section, or enrollment identity was added.

The tenancy decision remains **one institution per tenant, multiple campuses**. Campus scope is checked in the workflow and on reads. Published timetable data is snapshotted and hashed when approved publication occurs.

## Domain boundaries

- Timetabling owns configuration, facilities, recurring meeting planning, conflict calculation, review, and publication.
- Existing Academic Calendar owns years, periods, and institution configuration.
- Existing Enrollment/SIS data supplies section, confirmed learner, and instructor assignment relationships.
- Existing Identity/Tenancy owns membership and permission decisions.
- Existing Audit owns immutable administrative event recording.

## Trade-offs and limits

Recurrence keeps local date/time, weekdays, and an IANA time zone; generated instances are converted to UTC at evaluation/export time. Ambiguous and nonexistent local daylight-saving times fail validation. Published versions are immutable snapshots, but editing/version rollover and occurrence changes are not yet implemented. The Phase 09 MySQL runner and test schema are not present; the new migration was exercised by isolated SQLite tests only.
