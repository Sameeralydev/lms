# Phase 13 Security Audit

## Fresh route authorization follow-up — 2026-11-05

- The live tenant route matrix test exercised all 73 examination/appeal workspace routes through Laravel's HTTP kernel against guest, unprivileged active member, foreign-tenant member, and revoked membership identities. It passed 297 assertions and verified unchanged exam-cycle, candidate, and audit-event row counts.
- The sweep identified missing explicit gates on student appeal-evidence upload and reviewer appeal-evidence upload. `routes/web.php` now requires `exams.recheck.request` for student submissions and `exams.recheck.review` for reviewer submissions. Both permissions exist in the permission catalog and the guarded MySQL feature suite passed after the fix.
- The generalized route sweep does not prove per-record ownership, campus-specific boundaries, or successful authorized actions for every route; those remain separate acceptance evidence.
- npm audit remains 10 moderate development-tooling findings, with 0 high/critical and 0 production findings in `npm audit --omit=dev`. No force remediation or owner risk approval was made.
- The final guarded MySQL run and full scanner-enabled Chromium suite passed after the route permission fix. No critical/high issue was identified in this review. Full route positive-role, campus/resource authorization coverage remains incomplete, so the review does not assert that all Phase 13 authorization combinations have been verified.

## Fresh final security review — 2026-11-05

- Appeal evidence is stored privately in tenant/appeal-scoped quarantine. Upload checks authenticated candidate/reviewer authority, campus/tenant scope, appeal state, file type and size; records a content hash; and dispatches scanning. Only clean, hash-matching evidence is available to an authorized reviewer. Infected, unavailable, stale-signature or otherwise failed scans remain blocked. Scanner-enabled browser evidence exercised a clean document and harmless EICAR signature through the appeal route.
- Schedule-change/cancellation notifications validate the current published revision, registered candidate, active student membership and tenant-scoped session identifiers. Delivery content avoids confidential schedule details. Assignment/accept/decline notices target active authorized memberships and use idempotent outbox events.
- Formal online-exam submission/expiry events had previously been queued but rejected as unsupported. The fixed processor now validates binding, session delivery and quiz match, and selects active assigned instructors within tenant/campus scope. Feature and browser tests confirm delivery and idempotency. No sensitive answer or candidate payload is included in notification copy.
- Operations CSV is permission-gated, tenant/campus scoped, private/no-store, formula-neutralized, filtered by a verified session when requested, and audited. The expanded dashboard derives counts and released campus/subject aggregates from real records.
- No critical/high issue was identified in these reviewed changes. This is a scoped application review, not ASVS certification or a complete security assessment.
- `npm audit` reports 10 moderate development-tooling advisories (one GHSA affecting minimatch through ESLint/TypeScript-ESLint), 0 high, 0 critical. `npm audit --omit=dev` reports 0. A compatible remediation was not established; a forced fix would make a breaking lint-toolchain change. Owner decision remains pending and has not been self-approved.
- No manual assistive-technology test, hosted CI run, production deployment review, or original roadmap/R&D source review is claimed.

## Current appeal and notification security re-review — 2026-11-05

- Appeal status changes are restricted to the tenant/campus scoped review permission; transitions lock and revalidate the appeal/result, reject invalid states, enforce the reopen window, and append to `exam_appeal_history` rather than rewriting prior events. Current feature evidence verifies unauthorized transition denial and the expected append-only lifecycle sequence.
- Appeal remedies and decisions verify the result version recorded at request time before applying an approved versioned change. Appeals do not directly overwrite finalized results.
- Formal online exam finalization updates its bound session receipt and enqueues an idempotent submitted/expired event within the transaction. Payload is limited to session and attempt identifiers; authorization-aware notification recipient selection is handled by the outbox processor.
- Invigilator accept/decline transitions now append stable assignment-keyed outbox events in the same transaction as state/audit changes. The dispatcher checks the current accepted/declined state and sends to active, campus-visible members with `exams.invigilators.manage`; focused tests confirm the invigilator does not receive the manager notice.
- Security limitation: appeal evidence uploads and controlled evidence retrieval are not yet implemented. Do not accept confidential supporting evidence through an unscanned or public path.
- No secrets were added to reports. Guarded MySQL postflight preserved its reviewed ownership policy. Current MySQL result: 25/644; SQLite: 327/4863/10 skipped.
- npm audit has 10 moderate development-toolchain advisories. A compatible remediation was not established; no breaking force update or self-approved risk acceptance occurred.


## Final gate closure security update - 2026-11-05

- The new operations CSV is behind the existing `exams.analytics.view` permission. Its query is scoped to the tenant and active membership campus visibility, response is `private, no-store`, text fields beginning with spreadsheet formula characters are prefixed safely, and export creates an audit event. Feature tests verify authorized retrieval, denied permission, content and audit record.
- Date-sheet publication queues idempotent per-candidate events only for registered, non-withdrawn candidates. Delivery revalidates the published/current revision, session ids against the immutable schedule snapshot, active student and active tenant membership. Notification content omits candidate/schedule details.
- Scanner fail-closed control was exercised in the latest browser run. Because the signed daily database exceeded the configured 48-hour freshness limit, the scanner returned `signature_database_stale`; evidence, scripts, learning media and file responses remained unavailable. No scan bypass or freshness-policy relaxation was made. FreshClam's installed version reported the database up to date even though its signed build timestamp was older than policy.
- Current scanner-bound E2E evidence is incomplete: 4 scan-dependent cases failed and 14 later cases did not run after Playwright stopped the dependent sequence. Previous real clean/EICAR results are historical and are not presented as a fresh pass.
- npm audit still reports 10 moderate advisories in the development ESLint/TypeScript-ESLint chain, GHSA-g3hv-75wg-m249. The production direct dependency inventory contains Inertia, React and ReactDOM; no high/critical advisories were reported at the explicit high threshold. A breaking forced ESLint update was not applied. Risk disposition remains proposed for owner review, not approved.

No critical/high application-security finding was found in the reviewed reporting/notification changes. Residual concerns are stale local scanner signatures, incomplete role/screen verification, moderate development-toolchain exposure and absent original R&D source material. This review is not a security certification.

## Fresh security re-review - 2026-11-05

- Eligibility exceptions require a supported failed rule, independent reviewer, bounded expiry, and explicit revocation. Admit-document issue checks published cycle/session and current eligibility; document codes are encrypted at rest for the candidate view and stored as a one-way hash for public verification. Revoked, expired, withdrawn, or unpublished documents fail verification.
- Accommodation details are encrypted; review is independent of the student requester. Active accommodations are revalidated when seating plans publish and online exam attempt duration is calculated. The admit snapshot reveals approved accommodation types/time only; confidential request narratives are not included.
- Incident evidence is stored on the private local disk in a tenant/session/case-specific quarantine path. Upload validates MIME/extension and size, records SHA-256, dispatches scan after transaction commit, and fail-closes for unsupported path/hash/scanner outcomes. Download requires active tenant membership, campus scope, reporter or incident-review permission, clean scan state and matching content hash; response is attachment/octet-stream with `nosniff`.
- Incident report/review outbox events use unique event keys. Review notifications resolve recipients from active, non-revoked memberships with the review permission at the incident campus; decision notices go only to the active original reporter. Feature assertions verify the decider is not incorrectly treated as the reporter recipient.
- Existing immutable result-version and result-approval workflows remain the only route for score changes. An appeal/recheck does not write a finalized result directly; an approved adjustment creates a new result version and requires controlled release.
- Fresh guard run preserved the dedicated schema ownership fingerprint. No development, prior-phase or unrelated database was modified.
- No formal ASVS certification or regulatory compliance claim is made.

### Open security/release findings

| Severity | Finding | Disposition |
|---|---|---|
| High | No unresolved high-severity security defect was identified in the scoped re-review. Broader examination functionality remains incomplete as an acceptance gap, not a finding that authorizes release. | No scoped high finding open; Phase 13 gate remains blocked on the functional and owner-action items below. |
| Moderate | `npm audit` reports 10 moderate advisories in the ESLint/TypeScript-ESLint transitive `minimatch` developer-toolchain graph. No compatible non-breaking npm fix is currently offered. | Owner approval required; proposal not approved. |
| Moderate | Six-theme automated axe checks cover selected examination screens and roles, not every role-by-screen combination. Manual assistive-technology review was not performed. | Open acceptance evidence gap; exhaustive role matrix and separate human AT review remain outstanding. |

## Final continuation review — 2026-11-04

The final guarded MySQL run verified the dedicated Phase 13 identity and ownership policy before work, then applied only the reviewed pending migration through the guarded lifecycle. Postflight remained at 243 schema objects / 63 migrations with the same ownership fingerprint. No development or earlier-phase schema was targeted.

The eight independent-session probes call the corresponding examination domain workflows under fixture tenant membership context. They use two verified sessions, a bounded barrier, unique run identifiers and persisted-state assertions. The result-release probe now runs the production `QuizOutboxProcessor`; it asserts exactly one logical event, one release audit, a delivered event, and one notification to the eligible candidate with no controller notification. Probe fixture creation includes direct SQL inserts for disposable setup records; the raced operations themselves are service based.

The retake/makeup workflow validates source examination identity and released outcome eligibility, duplicate registration, audit, and candidate-specific outbox delivery. Date-sheet revisions require independent review before publication and retain immutable published snapshots. Invigilator duty response is restricted to the assigned user and is audited. These controls have feature coverage; they do not constitute complete operational examination authorization.

Current automated checks found no critical or high-severity defect in the reviewed Phase 13 slices. This is not a certification or complete release security assessment. `npm audit` exits 1 with 12 moderate `minimatch`-related advisories in the development lint toolchain; the available forced npm remediation is incompatible with the current `typescript-eslint` dependency and was not applied.

Open release gaps remain: full exam center/seating administration, broader eligibility and accommodation enforcement, full appeal/incident operations, remaining examiner/invigilator/administrator journeys, complete role-by-screen six-theme coverage, and manual assistive-technology verification. The original 24-phase roadmap/R&D artifacts were not found, so no claims are based on those missing sources.

Updated 2026-11-03. This is a scoped engineering review, not an OWASP ASVS assessment or certification.

## Controls evidenced in the current code/tests

- Examination routes use authenticated tenant workspace middleware and explicit `exams.*` permissions.
- Cycle/session/candidate/seat/paper/staff records are tenant scoped; controller operations check institution and campus boundaries.
- Paper blueprint/question snapshots, instructions, reviewer notes, and accommodation details use encrypted model casts. Paper preview verifies a stored SHA-256 integrity hash, applies no-store response headers, and audits access.
- Paper question selection rejects inactive or unpublished questions and banks outside the institution/sharing scope.
- Review, approval, publication and authoring are separated; author/reviewer/publisher independence is enforced in the paper workflow.
- Session scheduling, registration, seating, attendance revisions, accommodation supersession and audit writes are transactional with locks or unique constraints where implemented.
- Seat allocation validates a registered, non-withdrawn candidate against confirmed enrollment in the session course and campus; enrollment effective withdrawals are checked against the scheduled exam start while the candidate/session rows are locked.
- Audit metadata excludes confidential accommodation details and question answer keys.
- Focused SQLite tests pass for confidentiality, cross-institution question denial and lifecycle authorization. PHPStan reports no errors.
- Practical/oral marking routes require tenant exam permissions plus academic campus/feature middleware; examiner draft/submit permissions do not require broad academic-administrator access. Candidate enrollment, examiner assignment and moderator assignment are checked server-side.
- Submitted rubric totals are computed from server-validated criteria. Private examiner notes use encrypted casts and are excluded from workspace serialization. The examiner history is scoped to the actor, tenant and campus. Independent moderation queue rows and comparison marks require an explicit active moderator assignment to that exact component.
- Private answer scripts use the non-public local disk, quarantine state, strict MIME/size allowlist, SHA-256 integrity check, fail-closed scanner result handling, campus and tenant checks, assigned examiner/controller authorization, audit events and private no-store download headers. SQLite tests exercise injected clean, rejected and unavailable scanner outcomes.

## Open findings

| Severity | Finding                                                                                                                                                                                                                                          | Status                                                |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------- |
| High     | Online delivery and practical/oral marking are implemented as slices, but complete results/release/appeals, incident response and remaining examination authorization flows are incomplete.                                                      | Open; release blocker                                 |
| High     | Dedicated MySQL identity and grant policy passed read-only preflight, but migrations, foreign-key behavior, transaction isolation and concurrency remain unverified because `--run` refuses until the eight-probe harness exists.                | Open; implement harness before guarded run            |
| High     | No eight-probe independent-connection concurrency harness exists. Authenticated online and practical/oral browser journeys now exist, but the full examination acceptance matrix remains incomplete.                                             | Open; implement probes and complete workflow coverage |
| Moderate | Candidate eligibility now includes active SIS and confirmed course/campus enrollment for seating, but subject/curriculum prerequisites, retake rules, approved exceptions and scalable eligibility decisions remain.                             | Partially addressed                                   |
| Moderate | Accommodation data is recorded confidentially, but enforcement by exam delivery does not yet exist.                                                                                                                                              | Open                                                  |
| Moderate | Real ClamAV clean/EICAR upload and assigned-role download passed in the scanner-enabled browser journey; scanner timeout, stale-signature and unavailable states are covered with injected outcomes rather than induced against the live engine. | Failure-state integration evidence remains            |
| Moderate | The examiner and moderator flow has SQLite and browser evidence, but MySQL foreign-key, row-lock, transaction and concurrent marking invariants remain unverified.                                                                               | Open; guarded MySQL harness required                  |

No claim is made that the examination module is production-ready or formally certified. No Phase 13 MySQL database was contacted or modified in this run.

## Batch 2 independent review (2026-11-04)

Review found that the moderation queue initially selected every tenant moderator with the permission, while the write workflow already required an explicit component assignment. The queue now joins the actor's active moderator assignment, and a feature test verifies an unassigned moderator receives an empty queue while an assigned moderator sees both marks. The same review found the general examiner route inherited broad `academic.view`; queue, draft/submit and private script download now use narrow exam permissions while retaining academic campus/feature middleware. This keeps teachers out of administrator dashboards and maintains server-side scope checks.

The scanner-enabled Batch 2 browser journey now verifies real ClamAV clean-file approval and EICAR rejection through the examination upload route. Residual release blockers remain the guarded MySQL/concurrency gate and other unimplemented Phase 13 requirements. Automated checks do not establish formal ASVS compliance or manual assistive-technology conformance.

## Batch 3 security review (2026-11-04)

Reviewed the new result and recheck paths for tenant/campus scoping, deny-by-default permission checks, released-only student access, separation of compilation/approval/release, and independent approval on configured amendments. Student result payloads allowlist safe component labels and marks and omit source identifiers and internal notes. CSV output neutralizes formula-leading candidate identifiers. Outbox messages avoid embedding marks and resolve recipients through active SIS or membership state and scoped review permissions. New browser checks include six-theme axe scans, mobile width and keyboard interaction; these are automated checks, not a certification or manual assistive-technology audit.

No critical or high finding was identified in this code slice during review. This is a scoped code review, not a full Phase 13 security acceptance. The full Phase 13 MySQL and concurrency gates remain unverified, and npm audit still reports 12 moderate development-toolchain advisories at the configured high threshold. No formal ASVS compliance claim is made.


Batch 4 evidence: see [PHASE_13_BATCH_4_EVIDENCE.md](PHASE_13_BATCH_4_EVIDENCE.md) for the superseding verification results and remaining gate.
## Fresh continuation security review - 2026-11-04

Seating derives tenant and campus scope from server membership context, validates room ownership and candidate eligibility at revision creation and publication, rejects changes after an attempt or attendance exists, and requires separate author/reviewer/publisher actors. Snapshots are retained and transitions audited. The invigilator portal only queries the authenticated actor's assignments; the locked response workflow confirms assignment ownership. Client-supplied IDs cannot widen access.

No migration ran during this continuation. Guarded pre/postflight retained 245 objects, 64 migrations and the unchanged ownership fingerprint. No development or unrelated schema was targeted.

npm audit remains OPEN: 12 moderate advisories in ESLint/TypeScript-ESLint development dependencies, including minimatch GHSA-g3hv-75wg-m249. The suggested forced remediation is breaking. The proposal is not self-approved. No forced dependency change was made.

This is not a security certification. Eligibility exceptions, independent accommodation request/approval, secure admit documents, evidence-controlled appeal/incident remediation, and complete role-by-screen authorization remain.
## Fresh incident lifecycle and scanner review - 2026-11-05

The previously open high-severity finding for missing incident escalation/closure history is remediated for the implemented lifecycle. `ExaminationResultService::transitionIncident` locks and validates the incident within tenant/campus scope, checks active reviewer authority for assignment, persists the state and append-only history row transactionally, records an audit event, and enqueues idempotent notifications. Tests reject self-review, non-reviewer assignment, invalid transitions and evidence uploads after resolution/closure. Incident actions do not mutate `exam_results`; score remedies remain on the approved versioned recheck path.

The guarded Phase 13 migration added incident-history storage under the reviewed cutoff. Postflight verified the dedicated test schema, current 67-migration inventory and ownership fingerprint. No development or unrelated schema was accessed.

The scanner-enabled browser evidence now includes the real harmless EICAR signature embedded in incident PDF evidence, with rejection/quarantine and no authorized download, plus clean-file scan and controlled download. This establishes the incident route's real scanner path for the tested fixture, not general malware-detection guarantees. The full 42-test browser run completed after this EICAR flow was added.

Open security/release findings remain:

- **Moderate / owner action:** 10 npm audit moderate findings (GHSA-g3hv-75wg-m249) remain in the ESLint/TypeScript-ESLint development dependency tree. `npm audit` reports that the forced remediation changes to ESLint 9.39.5, a breaking major transition. No forced change was made. The risk disposition proposal is not approved.
- **Acceptance evidence gap:** The six-theme axe checks cover selected authenticated examination journeys only; the exhaustive role-by-screen matrix is incomplete. Manual assistive-technology testing was not performed.
- **Functional breadth:** Full examination operational reporting and notification event coverage remain partial.

No critical unresolved finding was identified in this scoped incident lifecycle change. No certification or formal compliance claim is made.

## 2026-11-05 urgent recovery evidence

See [URGENT_RECOVERY_ADDENDUM_2026-11-05.md](URGENT_RECOVERY_ADDENDUM_2026-11-05.md) for the fresh ClamAV signature verification, full scanner-enabled Chromium result (42 passed), repaired E2E timeout/theme synchronization, current quality checks, and remaining Phase 13 gate classification. Historical results above remain historical where superseded; this addendum is the current source for the urgent recovery outcome.



## Current final gap-closure evidence — 2026-11-05
See [FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md](FINAL_GAP_CLOSURE_ADDENDUM_2026-11-05.md) for the fresh scanner, guarded MySQL, SQLite, browser, reporting, quality-check evidence and exact remaining acceptance gaps. This addendum is authoritative for the current local verdict; historical results above are retained as historical evidence.

# Fresh authorization review — 2026-11-05

- The live 73-route tenant examination/appeal denial sweep passed 297 assertions and observed no changes in the exam-cycle, candidate, or audit-event mutation sentinels.
- It found two appeal-evidence upload routes without explicit permission middleware. Added `exams.recheck.request` to student evidence submission and `exams.recheck.review` to reviewer evidence submission. The route-driven denial test passes after the change.
- The route matrix does not establish every resource ownership, campus denial, private-file authorization, or positive workflow role combination. These remain explicit verification gaps.
- Dependency status remains 10 moderate findings (0 high/critical) in development-only ESLint/TypeScript-ESLint dependencies. `npm audit --omit=dev` reports 0. npm's suggested remediation requires major toolchain changes; no force fix or risk self-approval was made. Owner disposition is pending.

## Final security verification addendum � 2026-11-05

The current route denial test passed for all 73 tenant examination/appeal routes across 365 requests and 1,542 assertions, including an active guardian-role identity without exam permissions; exam cycle, candidate and audit sentinels remained unchanged. This remains partial authorization assurance because route-by-route positive role actions, campus/resource ownership cases, and exhaustive role-screen-theme coverage have not been established.

Full npm audit remains exit 1 for 10 moderate development-only ESLint/TypeScript-ESLint/minimatch findings; npm audit --omit=dev passes with zero findings. No forced upgrade was used and no risk was self-approved. Owner disposition is pending. No critical/high issue was found by these scoped checks; this is not a security certification. Hosted CI and manual assistive-technology testing were not run.

# 2026-11-06 verification addendum

The guarded Phase 13 MySQL preflight/run passed with its reviewed ownership fingerprint unchanged; the eight independent-session concurrency probes passed. The live route denial sweep passed 365 guest, unauthorized-role, foreign-tenant, and revoked-membership requests with mutation sentinels unchanged. These checks do not establish complete positive route authorization or all campus/resource ownership cases; those matrices remain incomplete and are recorded as engineering gaps.

Real ClamAV 1.5.4 was enabled in the complete Chromium and SQLite runs. The verified daily signature database was version 28174, integrity verification passed, and the build timestamp was within the configured 48-hour freshness limit at inspection. Clean and EICAR workflows passed. PHPStan, Composer audit, and production npm audit were clean. Full npm audit reports ten moderate advisories including GHSA-g3hv-75wg-m249 in the development-only ESLint/TypeScript-ESLint minimatch dependency chain. No forced dependency change or risk acceptance occurred; owner disposition remains pending. See [final acceptance evidence](FINAL_ACCEPTANCE_EXECUTOR_2026-11-06.md).
