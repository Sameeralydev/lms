# Application lifecycle

## 2026-10-23 verification update

The isolated SQLite admissions subset passes **14 tests / 202 assertions**, including offer approval separation and a capacity-one regression: of two approved applications, the second offer/reservation is refused. The Playwright suite passes 20 tests including guardian authority/revocation, offer acceptance, and SIS handoff queueing. This does not verify actual concurrent requests or MySQL `FOR UPDATE` behavior; the guarded MySQL run is blocked by an overbroad grant and no MySQL migrations/tests ran.

The current workflow accepts `draft → submitted → under_review → documents_required / assessment_pending / interview_pending / decision_pending`, then supports `waitlisted`, `rejected`, `offered`, `offer_accepted`, `offer_declined`, `offer_expired`, `withdrawn`, and `ready_for_sis_handoff` through restricted transitions or dedicated offer operations.

`AdmissionApplicationWorkflow` locks the application during submission, revision creation, transitions, decision stages, offer reservation, and offer response. Submission is idempotent; revisions and lifecycle events preserve snapshots and reasons. An approved decision is required before an admission offer may issue. Recommendations and approvals require distinct users.

Evidence: SQLite feature tests cover draft/revision/submission idempotency, tenant denial, decision separation, and offer gating. MySQL locking and concurrency behavior is not verified. Several brief-level transition prerequisites, appeal/correction history, and institution-configurable workflow profiles remain unimplemented.
