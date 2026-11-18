# Deferred Product QA Backlog

Last updated: 2026-11-18

This backlog separates implementation work from verification that needs external infrastructure or a broader acceptance campaign. Each listed item uses the requested exact status.

## QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 15 guarded MySQL | Fresh guarded preflight, migrations/ownership postflight, focused Phase 15 MySQL suite, and analytics scheduling concurrency checks | The pinned MySQL endpoint was unavailable in the most recent recorded preflight. When the owner-provisioned Phase 15 endpoint is available, use `php tests/run-phase15-mysql.php --preflight` then its guarded `--run`; do not substitute a direct PHPUnit database run. |
| ClamAV/EICAR | Intact standard EICAR signature detection through each quarantine-integrated upload workflow | Windows endpoint protection intercepts or modifies the test signature before ClamAV receives it. Preserve 48-hour signature freshness and fail-closed quarantine; provide an owner-approved isolated scanner test runtime. |
| Broad browser acceptance | Full authenticated Phase 15 cross-role, screen, and six-theme action matrix after implementation integration | Existing dashboard and examination matrices are scoped evidence, not a complete Phase 01–15 screen/action matrix. Run the complete approved matrix in the browser environment after the implementation branches are integrated. |
| Whole-product regression | Full SQLite suite and full scanner-enabled Chromium suite | Historical full-suite failures include six intercepted EICAR cases, and the last broad browser runs were not a fresh all-green comprehensive campaign. This continuation intentionally ran only focused Phase 15 checks. |
| Hosted CI | Hosted workflow execution | No completed hosted run is evidenced in this continuation. Run after the repository integration checkpoint is prepared. |
| Manual assistive technology | Screen-reader and assistive-technology review | Automated accessibility checks are not a substitute for human assistive-technology testing. |
| External email delivery | Email delivery of scheduled reports | Scheduled reports currently notify authorized members through Laravel database notifications and private report downloads. Configure and verify an institution-approved mail provider before claiming email delivery. |

## Not deferred as implementation

The Phase 15 report-source catalog, drill-down, PDF generation, schedule management, queued generation, internal notifications, and role-aware dashboard foundations are implemented as recorded in the Phase 15 continuation addendum. Missing data owned by later finance or other absent modules is not represented as an operational source; integration points remain dependent on those modules.

## Phase 17 billing verification — QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 17 MySQL | Verify dedicated Phase 17 target identity/ownership and execute guarded migration plus MySQL integration tests | No Phase 17 guarded MySQL preflight or migration was executed in the billing implementation continuation. Use the established guarded runner only after its target is configured and verified; do not run direct database-mutating tests. |
| Phase 17 financial database behavior | Validate MySQL decimal aggregation, unique invoice constraints, transactions and concurrent duplicate issue | SQLite focused feature tests passed, but MySQL-specific execution and concurrency probes were not run. |
| Phase 17 browser workflows | Authenticated billing staff, learner and guardian workflows, responsive screens, PDF display and accessibility | Focused backend tests and frontend compilation do not replace browser verification. Run when the integrated product browser environment is available. |
| Phase 17 product regression | Full SQLite, complete Playwright, cross-phase regression and broad authorization matrix | This continuation ran the focused Phase 17 SQLite filter only; comprehensive product regression remains a later QA campaign. |

These QA entries do not defer missing implementation work. Phase 17 concessions, installments, ledger, bulk/recurring billing, adjustments, notifications, and broader reporting remain implementation gaps in `docs/phase-reports/phase-17/PHASE_17_REQUIREMENT_TRACEABILITY.md`.

## Phase 18 payments and collections — QA PENDING — EXTERNAL ENVIRONMENT

| Area | Deferred verification | Reason / next action |
|---|---|---|
| Phase 18 guarded MySQL | Configure/authorize a dedicated Phase 18 disposable target and ownership guard, then run migration preflight, MySQL integration, postflight and concurrency checks | No Phase 18 MySQL target/ownership runner was verified in this continuation. SQLite focused tests are not MySQL evidence. Never reuse development or prior-phase test schemas. |
| Payment concurrency | Duplicate collection, concurrent invoice allocation, bank verification retry, over-refund reservation and simultaneous refund execution | SQLite focused suite verifies transactional outcomes sequentially; independent MySQL sessions and real locks were not tested. |
| External gateway | Sandbox initiation, signed callbacks, replay/out-of-order events, currency/amount validation and provider refunds | No provider is approved or configured. Owner must select provider and approve its contract before adapter or sandbox verification is claimed. |
| External settlement/reconciliation | Bank statement import, matching, unmatched/duplicate exceptions, approval, close/reopen and audit history | Current page reconciles internal records by date/campus and exports CSV. It does not ingest or verify an external statement or settlement. Approved source format and financial policies are required. |
| Refund payout | Validate execution through an approved payout rail/process and reconcile payout reference | Current workflow records a manual payout reference after an independently authorized operator acts; no bank/provider disbursement is performed. |
| Browser and accessibility | Authenticated cashier/finance/learner/guardian journeys; responsive, keyboard, accessibility and six-theme matrix | Only focused backend SQLite verification was run after the latest changes. |
| Multi-currency/MySQL finance | Validate decimal precision, currency segregation and aggregated totals under MySQL at realistic data volumes | Integer minor-unit workflow calculations are implemented; no MySQL-specific aggregation/concurrency evidence exists. |
| Full regression and external notifications | Full product SQLite/Chromium regression, email/SMS delivery, performance and security campaign | Development-first policy; no external credentials or broad campaign were used in this continuation. |

Focused Phase 18 SQLite result on 2026-11-18: 6 tests / 134 assertions passed. This does not close the above pending verification or implementation items.

## Phase 17 continuation — latest evidence and remaining QA

**QA PENDING — EXTERNAL ENVIRONMENT**

- Guarded Phase 17 MySQL identity/ownership preflight, migrations, postflight and integration execution were not run in this continuation. Do not apply the new additive migrations to a persistent database outside the reviewed guarded runner.
- Full authenticated billing browser and responsive/accessibility journeys were not run. The Vite build and focused lint/type checks are not browser evidence.
- ClamAV/EICAR was not run. Do not disable quarantine or infer Phase 17 scan coverage from other phases.
- Full product regression was intentionally not run; only the focused Phase 17, enrollment, and Phase 18 compatibility suites ran.
- External email/SMS delivery remains unverified; implemented notifications use Laravel's database channel only.

Fresh test evidence: Phase 17 SQLite 9/266, course enrollment 9/190, and Phase 18 SQLite compatibility 6/140 all passed. Focused PHPStan, Pint, PHP syntax, TypeScript, ESLint, Prettier, production build, billing route registration and `git diff --check` passed. These results do not close the remaining functional and environmental work in the Phase 17 traceability report.
