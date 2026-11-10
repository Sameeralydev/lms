# Phase 14 requirement traceability — initial execution

| Requirement group | Status | Current evidence / next work |
|---|---|---|
| Percentage policy and weighted categories | PARTIAL | `GradebookWorkflow::createPolicy/activatePolicy`, `GradeCalculationEngine`; SQLite feature test. Add other scales, dated scale versions, and MySQL validation. |
| Manual gradebook and roster | PARTIAL | `GradebookWorkflow`, `GradebookController`, gradebook Inertia pages; SQLite feature test. Add instructor assignment/roster workflows and pagination/bulk behaviors. |
| Decimal-safe calculation | PARTIAL | Integer cents and basis points; incomplete/excused handling tested. Add rounding modes, grade-band mapping, and approved override history. |
| Phase 11–13 source integrations | NOT IMPLEMENTED | Non-manual `source_type` is rejected pending verified source contracts; build versioned/idempotent import with approved/released-source checks. |
| Review, approval, publication | PARTIAL | Submit, independent approval, immutable percentage publication snapshots tested in SQLite. Add policy-configured approval stages, MySQL races, durable outbox and amended publication workflow. |
| Student privacy and tenant isolation | PARTIAL | Active institution/tenant scoping and two-institution denial regression tested in SQLite. Add MySQL adversarial cases, guardian relationship access, and full route matrix. |
| GPA/CGPA, standing and progression | NOT IMPLEMENTED | Requires institution-approved versioned rules and human review controls. |
| Report cards and transcripts | NOT IMPLEMENTED | No generation, PDF, template, issuance, correction, or download workflow. |
| Certificates and verification | NOT IMPLEMENTED | No issuance, QR-safe public verification, revocation, or reissue workflow. |
| Appeals, correction, audit/outbox | PARTIAL | Grade edits, revision history, and gradebook lifecycle audit exist. Academic appeals, outbox notification, exports/download audit are absent. |
| Role workspaces and six themes | PARTIAL | A real owner/grade-manager → academic reviewer → student grade-release Chromium journey passed. Grade detail and released-grade screens were verified under six themes with 18 screenshots. Instructor, registrar, guardian screens and remaining role journeys are unverified. |
| MySQL and eight independent-session probes | NOT RUN | No private `.env.phase14.testing`, guarded runner, or authorized Phase 14 schema present. |
| Full browser acceptance | PARTIAL | `tests/e2e/phase14-gradebook.spec.ts` passes one authenticated grade policy, scoring, independent review/publication, and student result workflow. Full Phase 14 role and functional journey coverage remains incomplete. |

Source spec: user-provided combined Phase 13/14 development prompt. Original blueprint/roadmap/R&D sources were not found in repository search; no claim is made that they were reviewed.

## Continuation verification — 2026-11-06

| Requirement | Evidence | Status |
|---|---|---|
| Released assignment score import | Focused source-sync test verifies authorization, immutable published source, idempotency, amendment revision history, score scaling, and refusal to overwrite a manual edit. | IMPLEMENTED / VERIFIED for assignment source only |
| Quiz and examination source import | Adapters implemented; only assignment synchronization has a successful end-to-end import test in this run. | PARTIAL / NOT VERIFIED |
| Grade correction after publication | Override tests verify stale approval rejection, immutable revision and a new publication snapshot for approved correction. | VERIFIED for tested workflow |
| Full regression | SQLite 335 passed / 10 skipped / 7,269 assertions; focused gradebook 4 passed / 136 assertions. | PASS |
| Phase 14 MySQL lifecycle and concurrent workflow tests | Dedicated environment and guarded runner are absent. No database commands were run. | NOT RUN / BLOCKED |
| Authenticated Phase 14 browser acceptance | No Phase 14 journey coverage executed. Full shared Chromium suite had 40 pass, 2 ClamAV-dependent failures and 1 skip. | NOT COMPLETE |
| Phase 14 complete acceptance | Official record/GPA/standing, reports/certificates, end-to-end approvals/notifications, complete source coverage and browser/theme gates remain incomplete or unverified. | BLOCKED |

## Continuation evidence — 2026-11-10

| Requirement | Fresh evidence | Status |
|---|---|---|
| Quiz source import | Approved/released quiz result maps to its section enrollment; idempotency and amendment history asserted in `Phase14GradebookFoundationTest`. | IMPLEMENTED / VERIFIED in focused SQLite test |
| Examination source import | Approved/released exam result maps only to the matching course/period and enrollment. | IMPLEMENTED / VERIFIED in focused SQLite test |
| Guarded MySQL test lifecycle | Dedicated private env absent; preflight refused before DB contact. Runner also blocks execution until concurrency harness exists. | BLOCKED / NOT RUN |
| Eight independent MySQL concurrency probes | No harness; no probes executed. | NOT IMPLEMENTED |
| Shared authenticated Chromium regression | 43/43 pass with scanner enabled, including a six-theme examination matrix. No Phase 14-specific gradebook journey exists. | PASS for shared suite only / Phase 14 UI NOT COMPLETE |
| Full SQLite regression and scanner | 345 pass, 1 fail, 4 skipped / 7,424 assertions. The scanner learner-response EICAR test passed in isolated rerun, not enough to close the suite discrepancy. | FAIL / INVESTIGATION REQUIRED |
| Official GPA, reports, transcripts, certificates | No complete persisted workflows or acceptance evidence. | NOT IMPLEMENTED |

Full dated evidence and commands: [PHASE_14_CONTINUATION_2026-11-10.md](PHASE_14_CONTINUATION_2026-11-10.md).

## Fresh grading-policy slice — 2026-11-10

| Requirement | Implementation and evidence | Status |
|---|---|---|
| Numeric / percentage / letter / pass-fail policy scale | `GradebookWorkflow`, `GradeScaleResolver`, controller and gradebook policy UI. Resolver tests exercise decimal-safe numeric/percentage output, configurable letter thresholds, pass/fail boundaries, missing zero floor, duplicate thresholds, and missing outcomes. | IMPLEMENTED / VERIFIED in SQLite |
| Versioned institution grade bands | Additive migration creates tenant-scoped `academic_grade_scale_bands`; new policies use the next version for the institution/period/key. Feature test creates and publishes a letter-grade policy then creates v2 and asserts v1 band preservation. | IMPLEMENTED / VERIFIED in SQLite; MySQL not run |
| Official publication outcome | Publication and approved correction snapshots now persist `scale_result`, optional `grade_points`, and optional `passed`; student workspace renders released outcome only. Feature test verifies letter label and grade points. | IMPLEMENTED / VERIFIED for letter result |
| Pass/fail persisted publication workflow | `Phase14GradebookFoundationTest::test_pass_fail_policy_is_persisted_and_published_as_an_official_result` creates the institution policy through HTTP, activates it, publishes a gradebook, and verifies the failed official result and student-visible released outcome. | IMPLEMENTED / VERIFIED in SQLite |
| Institutional numeric scale range/configuration | Numeric currently means decimal result on the 0–100 scale; configurable alternate numeric ranges are not implemented. | PARTIAL |
| GPA/CGPA, reports, transcripts, certificates, full operational screens | No implementation or acceptance evidence in this slice. | NOT IMPLEMENTED |

Full regression result: **353 passed, 4 skipped, 7,460 assertions**. MySQL evidence remains owner-gated and unavailable. See [dated continuation evidence](PHASE_14_CONTINUATION_2026-11-10.md).

## Finisher traceability update - 2026-11-10

| Requirement | Current implementation/evidence | State |
|---|---|---|
| GPA/CGPA and standing | Versioned policies, repeat rules, published-grade/confirmed-credit inputs, term/cumulative immutable snapshots, standing bands and correction supersession. Full SQLite pass; 113-assertion record/GPA journey. | Implemented, SQLite verified; MySQL not run |
| Report cards/transcripts/certificates | Versioned templates and snapshot approval/issue/revoke workflows; transcript authenticated journey passed; feature tests cover report-card staleness and certificate issuance/revocation/privacy-safe verifier. | Partial; report-card/certificate E2E, template layout and QR image absent |
| Campus/tenant confidentiality | Staff download now checks campus ownership; real foreign-campus reviewer denial regression passes. Student and verified guardian route scope is enforced in code. | Focused SQLite verified; complete authorization matrix absent |
| Source integrations | Assignment, released quiz and approved/released examination imports have focused integration evidence in the gradebook suite. | Verified for tested adapters; source-race MySQL probes not run |
| Notifications and reports | No durable Phase 14 academic outbox/notification delivery or full registrar reporting workspace. | Not implemented |
| Role workspaces | Grade manager/owner, academic reviewer and learner pages exercised; registrar and guardian record workspaces not accepted end to end. | Partial |
| Browser/themes | 1 authenticated Phase 14 Chromium journey passed; 30 screenshots and axe across six themes for exercised screens. | Partial matrix |
| MySQL ownership/integration | Guarded preflight exit 1 because ignored `.env.phase14.testing` is absent; runner made no DB contact. | Owner action required; not run |
| Eight independent MySQL probes | Runner requires `tests/Support/phase14-grade-concurrency.php`, which is absent. | Not implemented/not run |
| Security dependencies | Production npm audit clean; 10 moderate development ESLint/TypeScript-ESLint advisories remain. | Owner disposition pending |
| Full SQLite | 355 passed, 4 MySQL-gated skips, 7,598 assertions. | Pass |

Phase14 remains partial and engineering-blocked. Phase 15 was not started.


Latest functional completion evidence (2026-11-10): see PHASE_14_FINAL_FUNCTIONAL_COMPLETION_2026-11-10.md. This addendum supersedes prior status statements only where it records newer evidence; earlier historical results are retained.


## Fresh closure evidence ? 2026-11-10

See [PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md](PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md) for this pass's detailed evidence. Key disposition: focused guardian published-grade workflow passed (9 tests / 447 assertions); focused guardian browser test passed after asset rebuild; TypeScript, ESLint, Prettier, Vitest (24), Composer audit, production npm audit, and Vite production build passed. The earlier full Chromium run remains 42 passed / 2 failed and was not rerun as a complete suite. Phase 14 MySQL preflight exited 2 because private `.env.phase14.testing` is absent; MySQL tests and all eight concurrency probes were not run. Phase 14 remains BLOCKED; no Phase 15 work started.
