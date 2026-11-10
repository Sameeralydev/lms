# Phase 04 recovery closure plan — execution status

## Final acceptance update (2026-10-23)

The blockers described in the historical recovery status below have been resolved for local Phase 04 acceptance. The guarded V2 MySQL preflight and full suite passed (122 tests / 934 assertions); SQLite passed 119 / 893 with 3 MySQL-only skips; Playwright passed 20; real ClamAV clean/EICAR processing and concurrent capacity enforcement passed. The final gate is **PHASE 04 COMPLETE — READY FOR PHASE 05** for local development. Hosted CI, production scanner operations, mail and deployment infrastructure remain unverified production tasks. Do not treat historical blocked rows below as current status.

## Historical 2026-10-23 closure status before final verification

The database setup is no longer absent: the private ignored environment file authenticates read-only to the exact dedicated Phase 04 database, and the schema is empty. The actual authenticated account nevertheless has schema-scoped `ALL PRIVILEGES`; the runner refuses before migration. Update the older rows below that classify this as unprovisioned DBA setup: the current blocker is the effective account's excessive grants. Latest local evidence is SQLite 92/764, focused admissions 14/202, Vitest 6, Playwright 20, with static analysis, lint/format, audits and build passing. Real ClamAV and signature freshness verification remain external/unavailable. Overall Phase 04 remains blocked; no Phase 05 work occurred.

This recovery preserves the existing Phase 04 branch and builds from its implementation checkpoint. The Phase 03 handoff and supplied Phase 04 v2.0 specification were reviewed. No separate approved roadmap document was found; the handoff and supplied specification remain the scope authority.

## Requirement closure matrix

| Requirement | Current implementation / evidence | Classification | Remaining closure |
|---|---|---|---|
| Preserve code and data | Existing commits retained. `techmentors_lms_dev` migration status and `techmentors_lms_test` content were read-only | PASS for preservation | Continue additive-only migrations and protected DB allowlists |
| MySQL test isolation | Read-only preflight confirms the dedicated Phase 04 schema/account and zero objects; current effective account has schema-scoped `ALL PRIVILEGES` | BLOCKED by excessive grant | DBA must reduce the exact `127.0.0.1` account to the documented 11 privileges, then rerun the guarded preflight |
| MySQL migration/integration | The Phase 04 guarded command exits 2 on grant validation before ownership-manifest handling or migration | NOT RUN / BLOCKED | Run only after the unchanged identity, allowlist, and schema safety checks all pass |
| SQLite backend regression | Historical baseline: 80 tests / 660 assertions. Latest: 92 tests / 764 assertions; admissions-focused 14 / 202 | PASS, SQLite scope only | Keep MySQL claims separate |
| Intake lifecycle/edit/directory | Create/status, scoped update, audit, feature flag and year/program/campus checks, deadline/capacity validation, filter/pagination; browser scenario passes | PASS for tested workflows | MySQL/index/concurrency verification remains |
| Dynamic form builder | Safe server normalizer, conditional schema validation, draft/publish/versioning, visual editor and preview/public intake browser coverage | PASS for exercised paths; broader accessibility and workflow configurations remain | Continue expanded keyboard/axe coverage and institutional form-profile options in later scoped work |
| Public applicant | Draft/save/submit, minor submission gate, guardian authority path, offer response; browser journey covers an existing verified synthetic applicant | PASS for exercised paths; self-service registration and production email verification not established | Verify full registration and production delivery in the appropriate later phase/deployment work |
| Guardian/minor authorization | Hashed one-time invitation, verified separate guardian account, institutional relationship verification, scoped read-only access, audited revocation, submission gate, cross-tenant checks | PASS for exercised local flow; no external government identity provider | Define institution evidence policy and configure external identity verification if required |
| Document scanning | Private random paths, signature/type/size checks, quarantine state/event/job, clean-only access | PARTIAL / fail-closed | No scanner installed; configure/verify a real scanner and worker |
| Staff admissions operations | Intake and application review foundation | PARTIAL | Inquiry qualification, complete directory/workload, document/evaluation/merit/decision, waitlist, communications, handoff and analytics screens |
| Decision/offer/capacity | Independent recommendation/approval, approved-decision offer gate, sequential capacity-one refusal test, offer acceptance and waitlist path | PASS for exercised SQLite/browser paths; MySQL locking/concurrency unverified | Run MySQL integration/concurrency tests after the grant gate passes |
| Fees/outbox/SIS | Manual fee status, queued event rows, idempotent handoff record | PARTIAL | Provider/delivery worker/retries; handoff consumer/reconciliation evidence; no student registry |
| Accessibility/themes | 18 Chromium tests; new 390×844 intake detail and axe check; existing six-theme workspace test | PARTIAL | Full admissions pages, guardian, reviewer, form builder and application journeys across viewports/themes/keyboard |
| Dependencies and local quality | Static analysis, typing, lint, formatting, audits and build pass | PASS locally | Hosted CI not run |
| Reports/checkpoint | Recovery and gate reports refreshed; Git commit pending final review | IN PROGRESS | Commit only after final regression and diff review |

## Completed safe actions

1. Changed test runner default to SQLite and removed permission to destructively target the populated former test schema.
2. Added exact DBA instructions and a private config template; did not provision MySQL or read/print credentials.
3. Added tenant/campus-scoped intake update/search/pagination and a real browser scenario.
4. Added fail-closed upload quarantine and scanner-independent job/adapter structure. No malware-free claim is made.
5. Reran SQLite/backend/frontend/browser/static/security/dependency/build checks; evidence is in `TEST_REPORT.md` and `RECOVERY_AND_COMPLETION_REPORT.md`.

Phase 05 remains out of scope. Phase 04 stays **BLOCKED** until all mandatory workflows and security controls are implemented and verified and a new isolated MySQL test database passes integration checks.
