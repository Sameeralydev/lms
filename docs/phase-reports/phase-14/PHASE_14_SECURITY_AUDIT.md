# Phase 14 security audit — partial foundation

## Controls present in the implemented slice

- Grade operations require explicit tenant permissions and active tenant membership.
- Institution and campus scope are enforced for policies, sections, and instructor assignment checks.
- Student profile and grade queries are scoped to the active institution/tenant; a cross-institution regression test denies a user who has only another institution’s student record.
- Entry updates use optimistic lock versions and append revision history.
- Submission and approval require distinct actors; draft editing stops after submission, and published student access reads immutable publication snapshots.
- Browser output excludes unreleased results; student page only returns published gradebooks.
- Non-manual assessment-source items are rejected until source authority is verified.

## Findings and limitations

- **High, release blocker:** no MySQL Phase 14 isolation/ownership lifecycle or integration evidence; Phase 14 migrations have not been run against an owner-authorized isolated MySQL schema.
- **High, functional/privacy verification gap:** no authenticated Phase 14 browser authorization matrix, MySQL tenant/campus adversarial tests, or secure generated-document endpoints exist yet.
- **Medium:** official grade correction/appeal, notification/outbox, export/download audit, and history workflows are not implemented.
- **Dependency governance:** `npm audit` reports 10 moderate advisories in the development ESLint/TypeScript-ESLint minimatch chain. `npm audit --omit=dev` reports zero vulnerabilities. No breaking update or owner risk acceptance was performed.

No certification or complete Phase 14 security assurance is claimed. Continue deny-by-default controls and require guarded MySQL and browser verification before acceptance.

## Fresh continuation review — 2026-11-06

The source synchronization service enforces the gradebook management permission and tenant/institution/campus scope, accepts only draft gradebooks, requires released/published source material, rejects stale/manual score changes, and records safe audit metadata and revision history. The focused assignment integration test passed. Quiz and examination import success was not demonstrated; no broad security certification is claimed. Full Chromium surfaced two scanner-dependent failures where the files remained unavailable, confirming the clean-only fail-closed access path did not falsely approve them. Phase 14 MySQL authorization/ownership and Phase 14-specific concurrency were not verified because the guarded environment/runner are absent. The 10 moderate npm findings remain confined to development tooling per the audit split; explicit owner risk disposition is still required.

## Security continuation — 2026-11-10

- The Phase 14 MySQL runner refuses before database contact when its private config is missing and refuses `--run` until the concurrency harness exists. Owner approval is exact and separate from credentials; the example contains neither a password nor disposable authorization.
- Private Phase 14 environment ignore policy was checked with `git check-ignore`; the private file is absent. No credentials were emitted or used.
- Production npm audit passed with zero vulnerabilities; full npm audit did not finish this run. The prior 10 moderate development-tool advisories remain undisposed; this is not owner risk approval.
- ClamAV remains fail-closed. A fresh isolated quiz-file EICAR response test passed, but the full SQLite run failed the same learner-response infection assertion once. Scanner integration remains unresolved until full-suite behavior is stable.
- No live Phase 14 grants, database identity or schema ownership were inspected. No MySQL database was contacted.
- This is application security evidence, not a security certification.

## Scanner and grading update — 2026-11-10

The prior full SQLite run recorded one missing infected learner-response artifact, while its isolated rerun passed. A subsequent full regression after this continuation's grade-scale migration completed **353/353 passed with 4 MySQL-specific skips**; the same real ClamAV quiz EICAR response case passed (42.11s). The discrepancy did not recur, but the original failure's exact cause was not established. Keep scanner integration fail-closed and retain both run records. No test-order change, queue state change, or scan mock was used to force the pass.

The new scale-band workflow persists versioned policy outcomes with tenant-scoped bands and displays results only from published records. This does not complete institutional GPA policies, official document workflows, or security acceptance. MySQL account/grants/ownership remain unverified because the owner-controlled environment is absent. The 10 moderate development-tooling advisories remain pending owner disposition; production npm audit remains clean.

## 2026-11-10 implementation finisher security update

A staff record download now checks the learner home-campus scope through `AcademicAccess` in addition to issued status, tenant and institution. A campus-scoped reviewer from another campus is denied at the actual PDF route; the regression passed. Student and guardian-only record permissions are explicitly excluded from broad staff academic permission expectations; the permission upgrade test now verifies these grants on their intended roles and absence on owner roles.

Private PDF output is no-store and nosniff, and the public certificate verifier reveals only validity, type, issue date and institution. Verification remains UUID-code based; no QR graphic was implemented. The built-in PDF layout is minimal and not a validated production template renderer. Durable notification/outbox delivery is absent. These are residual functional limitations, not security certification claims.

MySQL schema, grants and ownership remain unverified: the guarded preflight stopped before DB contact because the private config is missing. No migration or database mutation occurred. Production npm audit found zero vulnerabilities; full audit has 10 moderate dev-tool-chain findings awaiting owner disposition. No risk approval was inferred.

The previous full-suite permission assertion failure was a stale test expectation, not missing production authorization. It expected `academic.records.view-own` and `.guardian.view` among broad staff academic permissions. The test now excludes those user-specific grants from the staff set and independently asserts student/guardian-only role grants. Focused and full SQLite reruns passed; no privilege was broadened to satisfy the old expectation.


Latest functional completion evidence (2026-11-10): see PHASE_14_FINAL_FUNCTIONAL_COMPLETION_2026-11-10.md. This addendum supersedes prior status statements only where it records newer evidence; earlier historical results are retained.


## Fresh closure evidence ? 2026-11-10

See [PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md](PHASE_14_FINAL_CLOSURE_ADDENDUM_2026-11-10.md) for this pass's detailed evidence. Key disposition: focused guardian published-grade workflow passed (9 tests / 447 assertions); focused guardian browser test passed after asset rebuild; TypeScript, ESLint, Prettier, Vitest (24), Composer audit, production npm audit, and Vite production build passed. The earlier full Chromium run remains 42 passed / 2 failed and was not rerun as a complete suite. Phase 14 MySQL preflight exited 2 because private `.env.phase14.testing` is absent; MySQL tests and all eight concurrency probes were not run. Phase 14 remains BLOCKED; no Phase 15 work started.
