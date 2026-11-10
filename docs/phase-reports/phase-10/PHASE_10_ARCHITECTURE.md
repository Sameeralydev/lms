# Phase 10 Architecture

## Status

Partial implementation; Phase 10 is not accepted. The module follows the existing modular-monolith and tenant-context conventions. Phase 09 timetable occurrences remain authoritative; Phase 10 stores attendance decisions and roster snapshots against those occurrences.

## Implemented boundaries

- `App\Domain\Academic\Attendance\AttendanceWorkflow` owns attendance policy versions, register creation, marking, submission, finalization, reopening, justifications, corrections and summaries.
- `AttendanceController` resolves tenant routes and delegates decisions to the workflow.
- React/Inertia pages provide operations, register, student, guardian, correction, justification and report views.
- Attendance notification events use an outbox and a separate processor. Dispatch is at least once; consumers must de-duplicate by event ID.

## Trade-offs and limits

The initial implementation uses existing timetable, SIS, enrollment, tenant and audit records instead of duplicating those domains. The attendance outbox currently emits an internal event and supports bounded retry/lease recovery; user-facing notification channels and their recipient policies are not complete. No Phase 11 code has been started.
