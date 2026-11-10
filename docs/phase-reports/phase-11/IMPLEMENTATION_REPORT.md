# Phase 11 Implementation Report

## Scope and result

Phase 11 assignment and submission workflows were verified locally. Phase 12 was not started.

## Delivered behavior

- Assignment authoring and publication use reviewed versions and confirmed learner recipients.
- Learners can draft and submit work, receive receipts, preserve revisions, and respond to integrity concerns.
- Instructor grading uses immutable rubric versions, server-calculated criterion totals, version checks, and explicit grade release.
- Extensions are tenant and assignment scoped, independently reviewed, audited, notified, and revocable before reliance; the approved extension determines the effective cutoff.
- Group submissions are idempotent and freeze eligible membership snapshots.
- File artifacts remain quarantined until a successful current-version scan. Infected or unscanned files cannot be downloaded or submitted. External submission URLs reject private network targets.
- Integrity decisions require a learner response and an independent human decision.
- Analytics use scoped persisted records and CSV exports neutralize spreadsheet formulas.
- Workflow responses return to the relevant assignment page with visible outcome feedback.

## Defect repaired

The real two-session MySQL draft-edit probe exposed a revision-number race under MySQL repeatable-read isolation. Revision selection used a stale aggregate maximum. The workflow now takes a locking read before allocating the next draft revision. A regression probe confirms two concurrent edits persist as two ordered revisions.

The concurrency fixture builder now creates fixtures transactionally and establishes tenant context before tenant-scoped reads. The guarded runner also has a narrowly authorized cleanup path for a verified partial harness fixture; its identity, expected fixture marker and exact inventory are checked before cleanup. No schema ownership policy was weakened.

## Phase 11 files changed in this continuation

- `app/Domain/Assignments/AssignmentWorkflow.php`
- `tests/Feature/AssignmentManagementTest.php`
- `tests/Support/phase11-assignment-concurrency.php`
- `tests/run-phase11-mysql.php`
- `docs/phase-reports/phase-11/`

The worktree contains other pre-existing modified and untracked files from earlier phases. They were not reset, staged, committed, or discarded in this continuation.

## Verification boundary

The guarded runner verified the dedicated Phase 11 test schema and all eight concurrency probes. Actual ClamAV clean-file and EICAR handling was exercised in a focused feature test and through the scanner-enabled browser suite. No Phase 12 functionality was implemented.
