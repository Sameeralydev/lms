# Phase 13 Architecture

Updated 2026-11-03 (Asia/Karachi).

## Entry gate

The current Phase 12 final gate and traceability record pass local acceptance. The older `PHASE_13_HANDOFF.md` was stale and has been replaced with a current handoff. The original product blueprint, 24-phase roadmap, R&D specification and post-Phase-12 R&D audit were not found in the checkout or supplied attachments. The provided Phase 13 master prompt is therefore the available detailed requirements source.

## Implemented boundary

Phase 13 begins as a modular-monolith domain under `App\Models\Exam*` and `ExaminationController`. It reuses tenant context, campus scope, academic periods, academic courses, SIS student identities, Phase 09 rooms, existing audit recording, and existing permission templates. The application retains one institution per tenant with multiple campuses. Phase 12 remains the owner of reusable question bank and quiz attempt functionality. Phase 14 remains the owner of official grading, GPA/CGPA, transcripts and certificates.

The current slice implements draft examination cycles, guarded lifecycle transitions, scheduled sessions, active SIS candidate registration, and room seat allocation. Seat allocation now requires confirmed enrollment in the exact session course and campus, still active on the session date after accounting for effective withdrawals. It does not yet implement the complete formal examination domain.

## Trade-offs and boundaries

- Exam session timestamps are stored in UTC; institution-local input conversion and timezone-aware date-sheet presentation remain incomplete.
- Candidate registration verifies active SIS status and matching institution. Seating additionally verifies confirmed course/campus enrollment and effective-withdrawal timing. Retake/supplementary rules, eligibility exceptions and curriculum prerequisites remain unimplemented.
- Room overlap checking covers existing scheduled exam sessions in the selected room. Staff, candidate, timetable, room-block and facility-resource conflicts are not fully integrated.
- Seat capacity is serialized by locking the room row and is backed by unique session/candidate and session/room/seat constraints. MySQL concurrency has not been executed.
- Cycle/schedule publication is audited, but versioned date-sheet snapshots and secure paper governance remain unimplemented.

