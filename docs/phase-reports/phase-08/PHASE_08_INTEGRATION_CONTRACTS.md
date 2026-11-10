# Phase 08 integration contracts

- Student registration preview: tenant registration route accepts a section identifier and returns the server-calculated eligibility, capacity and expected enrollment outcome without writing enrollment data.
- Registration submission: validates current SIS identity, tenant scope, enrollment policy and section capacity; an idempotency key protects retries.
- Registrar workspace: policy, offering and section create/update actions use Laravel validation, permission middleware, transactional workflow services and audit records.
- Instructor workspace: assigned section list and roster/CSV endpoints enforce active membership, instructor permission and campus/section scope; roster search is server-side and paginated.
- Phase 07 learning integration: confirmed enrollment can create only the matching existing delivery assignment; drop/revocation disables that assignment.
- Waitlist expiry: `enrollment:waitlist:expire` runs every minute with scheduler overlap protection; the task expires expired offers transactionally and promotes the next waiter.

No public API token or third-party integration contract is introduced in this phase. MySQL concurrency behavior remains pending guarded integration execution.
