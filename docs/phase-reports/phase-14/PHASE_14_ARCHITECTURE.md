# Phase 14 architecture — current implementation status

This describes the partial foundation present after the 2026-11-06 execution. It is not a complete Phase 14 architecture acceptance.

## Implemented slice

The current module is a Laravel modular-monolith domain under `App\Domain\Grading`. `GradebookWorkflow` owns policy creation/activation, gradebook and manual item creation, versioned entry writes, submit/review/publish transitions, and student-visible published snapshots. `GradeCalculationEngine` performs deterministic arithmetic with integer cents and basis points. The controller is `GradebookController`; page components are under `resources/js/pages/workspace/academics/grades/`.

Identity remains global; tenant membership and institution context scope grading operations. A section must belong to the active institution/campus, and instructors without `academic.grades.book.manage-all` must be assigned to the section. Approval is independent of the gradebook creator. Published values are stored as publication snapshots rather than recomputed from mutable drafts.

## Deliberate limits

- Only manual gradebook items are accepted. No Phase 11 assignment, Phase 12 quiz, or Phase 13 examination score is imported as an official score until source ownership, approval/release, immutable version, and resynchronization contracts are implemented and tested.
- Only percentage weighting is currently calculated. Configurable grade bands, letters, grade points, pass/fail, competency scales, GPA/CGPA, standing, and progression are not implemented.
- No report-card, transcript, certificate, correction/appeal, guardian, or export workflow is implemented.
- No Phase 14 MySQL runner or dedicated test schema is present.

These decisions avoid treating unreleased or unapproved exam data as authoritative academic records.
