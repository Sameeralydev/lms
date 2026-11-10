# Phase 13 Requirement Traceability

**Current-state matrix — 2026-11-05.** Historical run results and earlier failures remain in `PHASE_13_TEST_REPORT.md`; statuses here describe the current implementation and latest verification only.

| Requirement | Status | Evidence / remaining action |
|---|---|---|
| Secure appeal evidence | IMPLEMENTED / VERIFIED for scoped workflow | Private tenant/appeal-scoped quarantine, type/size validation, content hash, ClamAV scan, clean authorized download, EICAR rejection, revoked/wrong-candidate/cross-tenant denial, closed-appeal enforcement and audit. Full scanner-enabled Chromium: 42/42. |
| Examination operations reports | IMPLEMENTED / VERIFIED for current report scope | Real scoped schedule, registration, attendance, marking/moderation, result, appeal/recheck, incident, seating/capacity, invigilator, exception, accommodation, admit-document and retake/makeup measures; session filter and subject/campus released-performance table. CSV permission, scope, no-store, formula-safe and audit behavior covered by `ExaminationOperationsTest`. Separate downloadable report per category is not claimed. |
| Durable notifications | IMPLEMENTED / VERIFIED for listed flows | Candidate-specific date-sheet publication/revision/reschedule/cancellation; invigilator assignment/accept/decline; appeal/incident/recheck/result; formal online attempt submit/expiry. Recipient validation and idempotency covered by feature tests and the full browser suite. |
| MySQL concurrency and ownership | PASS | Guarded suite: 31 passed / 1 scanner-gated skip / 1,043 assertions; eight independent-session probes / 16 workers passed. Schema 253 objects / 69 migrations / zero pending; ownership fingerprint unchanged. |
| SQLite/backend regression | PASS | 327 passed / 4,949 assertions / 10 skipped. |
| Browser regression | PASS for selected journeys | Scanner-enabled Chromium: 42 passed / 0 failed / 0 skipped. Includes selected authenticated controller, examiner, invigilator, student, admin, appeal evidence and results journeys. |
| Role/action/campus authorization matrix | PARTIAL — mandatory evidence gap | `ROLE_SCREEN_THEME_MATRIX.json` has 73 live registered routes and selected role/screen/theme evidence. Every applicable route/action authorization and campus-denial cell has not been exercised. |
| Six-theme accessibility | PARTIAL | Selected browser journeys run axe, keyboard, mobile and theme checks; an exhaustive role-by-screen-by-theme matrix and human assistive-technology review remain outstanding. |
| npm dependency risk | OWNER DECISION PENDING | 10 moderate development-toolchain advisories; production dependency audit is clean. No compatible nonbreaking fix was established and no force fix was applied. Owner must select A or B in `NPM_ADVISORY_OWNER_DECISION.md`. |
| Source provenance / external QA | NOT VERIFIED | Original roadmap/R&D source documents were not found. Hosted CI, production deployment verification and manual assistive-technology testing were not performed. |

**Phase 13 status: ENGINEERING INCOMPLETE.** The incomplete role/action/campus matrix prevents acceptance. Do not start Phase 14.
# Fresh route-matrix evidence — 2026-11-05

| Requirement | Current evidence | Status |
|---|---|---|
| Route-by-route negative authorization | `EXAMINATION_AUTHORIZATION_MATRIX.json`; 73 live tenant examination/appeal routes × guest, unprivileged tenant member, foreign-tenant member, and revoked member. `Phase13ExaminationRouteAuthorizationMatrixTest`: 1 test / 297 assertions. | PARTIAL: negative route layer passes; positive role actions, wrong-campus and foreign-resource cases are not exhaustive. |
| Appeal evidence upload authorization | Both student and reviewer upload endpoints now have explicit least-privilege middleware; sweep verified denials for users without roles. | VERIFIED for middleware denial; workflow-specific cases remain in feature/browser suites. |
| Six-theme role-by-screen coverage | Existing authenticated Chromium journeys cover selected controller, reviewer, invigilator, student and administrator screens; theme switching/axe/mobile coverage is selected, not exhaustive for every role-screen pair. | PARTIAL. |
| npm advisory disposition | Ten moderate development-tooling findings; production omit-dev audit clean; compatible non-breaking resolution not identified; owner decision form remains unsigned. | OWNER DECISION PENDING. |

The complete Chromium suite subsequently passed 42/42 with scanner enabled. An examiner-specific post-run browser test passed 1/1 and captured six screenshots (one per registered theme). This is fresh representative role/theme evidence, not proof that every permission/action/campus/resource combination or every role-screen-theme Cartesian cell passes. Manual assistive-technology acceptance was not performed.

## One-shot closure evidence — 2026-11-05

Latest route-matrix execution verifies five denial identities across 73 live routes (365 requests; 1 test / 1,542 assertions): guest, active tenant member without roles, active parent.guardian without exam permissions, foreign-tenant member, and revoked membership. Selected exam/audit mutation sentinels remained unchanged. The live route definitions and permission middleware match the reviewed JSON inventory.

This closes only the tested negative-route layer. The matrix still lacks successful authorized execution for each applicable route/action and concrete wrong-campus and foreign-resource checks for each applicable scoped route. The six-theme role-screen matrix is representative rather than exhaustive. Guarded MySQL (32 passed / 1 skip / 2,585 assertions; eight probes), SQLite (328 / 6,491 / 10 skipped), and scanner-enabled Chromium (42/42) passed. Consequently Phase 13 remains blocked; see the final gate. Do not start Phase 14.

# 2026-11-06 acceptance evidence update

The final rerun is recorded in [FINAL_ACCEPTANCE_EXECUTOR_2026-11-06.md](FINAL_ACCEPTANCE_EXECUTOR_2026-11-06.md). Verified: guarded MySQL 35 passed / 1 scanner-gated skip / 3,117 assertions; all eight concurrent probes; SQLite 337 / 7,142 / 4 database-specific skips; Chromium 43/43; six-theme role-screen matrix 78/78 with screenshots.

The requirement for positive authorized execution of every live examination/appeal route remains **NOT COMPLETE (0/73 manifest-bound)**. The generic negative sweep is PASS (365 requests). Campus/resource applicability is not fully assessed: campus has one explicit N/A and 72 pending assessments, with one wrong-campus denial verified; resource ownership has 11 explicit collection N/As and 62 applicable rows, with one foreign-question paper-store denial verified and 61 pending. Do not infer route-level PASS from controller-level workflow tests.

The ten moderate development-tooling npm advisories remain subject to owner disposition; production dependencies audit clean. Hosted CI and human assistive-technology testing remain unrun.

## Fresh verification addendum — 2026-11-06
See [PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md](PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md) for fresh guarded MySQL, SQLite, Chromium, scanner, static-check and authorization evidence. The current route authorization gate remains blocked: 73 workflow references are linked, but systematic positive route probes are 0/73; 70 campus-applicability rows and 61 applicable foreign-resource rows remain unverified. Do not infer completion from linked workflow evidence.

## Fresh continuation verification — 2026-11-06

The negative route-matrix test passed again (73 route inventory, 2 tests / 2,138 assertions). This verifies only the existing negative denial matrix; systematic positive role/action execution and applicable wrong-campus/foreign-resource cells remain incomplete. The full SQLite suite passed 335 / 10 skipped / 7,269 assertions. The shared Chromium run was 40 passed / 2 ClamAV-dependent failures / 1 skipped, and therefore is not a full browser acceptance pass. Phase 14 changes did not access Phase 13 MySQL. Existing Phase 13 gate remains BLOCKED pending its documented positive authorization and scoped resource evidence, plus owner disposition of the development npm advisories.
