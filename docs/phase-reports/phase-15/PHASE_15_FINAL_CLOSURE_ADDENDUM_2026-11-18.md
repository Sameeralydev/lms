# Phase 15 final closure addendum — 2026-11-18

This addendum records the latest Phase 15 continuation evidence. It supersedes older statements where current commands provide newer evidence. Historical runs remain unchanged. Phase 16 was not started.

## A. Report builder — implementation and remaining scope

Delivered a curated, aggregate-only report builder in `app/Domain/Analytics/AcademicReportBuilder.php` and `resources/js/pages/workspace/academics/analytics/index.tsx`.

- Supported, tenant/institution-scoped sources: course enrollments, admissions applications, finalized attendance, and examination sessions.
- Configurable allowlisted dimensions, campus/date/status filters, grouped-value search, safe sorting, bounded pagination and capped CSV export.
- Saved report configurations remain private to the creating membership and preserve the report-builder selection.
- CSV output uses the same query/scope as preview, sets private/no-store and nosniff headers, and neutralizes spreadsheet formulas.
- Export audit events now appear in an authenticated “Your recent report exports” table. The history is scoped to the current actor and tenant and contains dataset, row count, truncation status and time; it does not store exported data.
- Print output is available through the browser print interface. A server-generated PDF, record-level drill-downs, arbitrary selectable fields/aggregations, export scheduling, and a full catalog spanning all Phase 01–14 domains are not implemented.

Evidence: `php tests/run.php --phase=15 --profile=sqlite --no-report` **exit 0, 16 passed / 352 assertions**; guarded MySQL Phase 15 `--run` **exit 0, 16 passed / 352 assertions**; focused authenticated analytics Chromium **2 passed**, including export-history persistence, authorization denial, and six-theme checks. The builder is a verified vertical slice, not complete Phase 15 report-builder acceptance.

## B. Role dashboards and module coverage

The current supported-role dashboard matrix contains 12 roles: platform super administrator, institution owner/administrator, campus administrator, academic leadership, instructor, examination controller, examiner, invigilator, student, guardian, registrar, and admissions officer. The authenticated Phase 15 dashboard/theme test passed **72/72 cells** (one dashboard per role × six themes), with live role metrics, navigation/action route checks, persistence, keyboard/focus, mobile reflow and axe checks. A separate examination matrix passed **78/78** (five roles × 13 applicable examination screens × six themes).

These matrices do not verify every Phase 01–14 screen, every dashboard action, every filter or every role/module workflow. Complete role-specific dashboard/module coverage remains **PARTIAL**. No future-phase module was added.

## C. ClamAV and EICAR

- Private `.env.testing` scanner configuration resolves to `C:/Users/lenovo/AppData/Local/ClamAV/database`; the PHP scanner adapter confirmed that exact directory and returned `clean` for a harmless `artisan` file.
- ClamAV reports version **1.5.4**, daily database **28185**, build time **2026-11-16 06:24 UTC**. The configured maximum signature age remains 48 hours.
- `sigtool --info` verified `main.cvd` and `daily.cld`. A direct real clean scan succeeds.
- Full SQLite: six real clean/EICAR integration cases failed because Windows Defender intercepts or changes the EICAR fixture in PHP upload/quarantine storage before ClamAV receives the expected bytes. They are Admissions, Assignments, Attendance, Course Delivery, Quiz, and SIS. The scanner remains fail-closed; no Defender exclusion or freshness/quarantine relaxation was applied.
- In the scanner-enabled Chromium practical-script journey, `EICAR_UPLOAD=HOST_INTERCEPTED_FAIL_CLOSED`. This is safe rejection evidence, not ClamAV EICAR detection.
- Genuine EICAR detection still requires an owner-approved isolated scanner test environment that can deliver the intact standard test signature to ClamAV without changing host endpoint-protection policy.

## D. Verification results

| Command / check | Result |
|---|---|
| `php tests/run.php --phase=all --profile=sqlite --no-report` | **exit 1** — 382 passed, 6 failed, 4 skipped, 8,156 assertions. The six scanner failures above remain release blockers. Four skips are explicitly MySQL-only metadata/locking/capacity checks. |
| `php tests/run.php --phase=15 --profile=sqlite --no-report` | **exit 0** — 16 passed, 352 assertions. |
| `php tests/run-phase15-mysql.php --preflight` before the guarded run | **exit 0** — dedicated database `techmentors_lms_phase15_test`, account `techmentors_phase15_test@127.0.0.1`, MySQL 8.4.11, 272 objects, 84 migrations, zero pending reviewed migrations. |
| `php tests/run-phase15-mysql.php --run` | **exit 0** — 16 passed, 352 assertions; all eight probes passed using 16 independent sessions and eight two-open-transaction barriers. Runner postflight reported 272 objects / 84 migrations and unchanged fingerprint `2d835263eb01ab344f53348fb77b9f84a0808ae3f0c2c97fd88e46e8353f8f0b`; owned fixture cleanup passed. |
| Separate `php tests/run-phase15-mysql.php --preflight` after runner exit | **exit 1** — target port refused a new connection. The runner's own postflight had already verified the fingerprint; no database change was attempted by this additional read-only check. |
| Full Chromium, scanner variables omitted | **exit 1** — 44 passed, 2 failed, 1 skipped. Not valid scanner-enabled acceptance evidence. |
| Full Chromium with explicit scanner variables | **exit 1** — 43 passed, 4 failed, 0 skipped. Failures were bounded-wait timeouts in timetable/examination theme persistence and two login redirects. |
| Focused Chromium rerun after increasing only affected E2E wait bounds to 30 seconds | **exit 0** — 7 passed, including all four failed journeys. |
| Focused Phase 15 analytics Chromium after export-history addition | **exit 0** — 2 passed. |
| `npm.cmd run test:unit` | **exit 0** — 24 passed. |
| `composer analyse`, `vendor/bin/pint --test`, TypeScript, ESLint, Prettier, production build | **exit 0**. Build transformed 697 modules. |
| Composer validation/audit; `npm audit`; `npm audit --omit=dev` | **exit 0**; current audit reports zero advisories/vulnerabilities. The previously recorded ten moderate development-tool advisories are not present in the current dependency audit. |
| PHP syntax validation | **exit 0** — 640 files passed. |
| `git diff --check` | **exit 0**; Git emitted existing CRLF-normalization warnings for Phase 14 report files. |

## E. Security and risk disposition

No tenant/campus scope or scanner fail-closed guard was relaxed. The report builder accepts only registered source/dimension keys and uses bound filter values; CSV formula neutralization and per-actor export-history scoping are covered by feature tests. Current dependency audits are clean; no npm advisory owner disposition is pending based on the current lockfile audit. No formal security certification is claimed.

## F. Remaining blockers and final gate

1. **Application/security verification blocker:** six MySQL-independent real scanner integration cases cannot prove EICAR detection on this host because endpoint protection intercepts the test signature. Required owner action: authorize/provide an isolated scanner test runtime; do not weaken host protections.
2. **Product acceptance blocker:** report-builder contract remains partial (no record-level drill-down, complete cross-domain catalog, selectable detail fields/multiple aggregations, export scheduling, or generated PDF). Dashboard/module verification remains one dashboard per role plus focused module journeys, not exhaustive Phase 01–14 role/action coverage.
3. **Verification limitation:** latest scanner-enabled full Chromium invocation exited 1; each of its four failed journeys passed in a subsequent focused rerun, but an all-green single full-suite rerun has not been executed. A separate post-run MySQL preflight could not reconnect after the guarded runner exited; its embedded postflight passed.

**PHASE15_IMPLEMENTATION=PARTIAL**
**PHASE15_ENGINEERING=BLOCKED**
**PHASE16=NOT_STARTED**

No owner approval is inferred for an isolated scanner environment. Existing Phase 01–14 data and Phase 15 ownership protections were preserved.

## Requirement count basis

The attached Phase 15 closure directive names three blocker groups (ClamAV/EICAR integration, report-builder completeness, and role-dashboard/module completeness). **Fully closed groups: 0/3.** Each group has verified partial work, but each still has at least one mandatory unmet criterion. This count is the three named blocker groups, not an invented count of the many subcriteria within the original Phase 15 specification.

## Report-builder implementation continuation — 2026-11-18

Added a selectable measure to the curated report builder. Enrollment reports can compare enrollment records with distinct learners; finalized-attendance reports can compare marks with distinct learners. Other datasets expose only their semantically valid record count. Expressions are fixed server-side allowlist entries; user input cannot supply SQL or select an unsupported measure. The selected measure persists in membership-owned saved reports, applies identically to preview and CSV export, is included in export audit metadata, and labels the result column. Grouped results also render a responsive bar visualization backed by the current page; the accessible results table remains the text equivalent. Requested pages clamp to the last available page.

Fresh evidence: `php tests/run.php --phase=15 --profile=sqlite --no-report` exited 0 with 16 passed / 382 assertions. Coverage verifies that distinct learner count differs from record count, invalid dataset/measure combinations return 422, saved measures reapply, CSV uses the matching aggregation and formula neutralization, and the attendance aggregation query is valid. `composer analyse`, focused Pint, TypeScript, ESLint, Prettier, Vitest (24 passed), and production build (697 modules) passed. Authenticated Phase 15 analytics Chromium passed 2/2 after rebuilding assets; the report control, empty state, export history, saved configuration, denial case and six-theme persistence/axe/mobile checks ran. Six screenshots are in `test-results/phase15-analytics-theme-evidence/`.

Remaining report scope: only four curated source datasets are available; record-level drill-down, generated PDF, scheduled reports and a full Phase 01–14 catalog remain incomplete. A new read-only guarded MySQL preflight exited 1 because the previously owner-pinned server refused connection; no MySQL operation ran. Prior MySQL postflight and 8/8 concurrency results remain historical. The last full SQLite suite remains 382 passed / 6 failed / 4 skipped, with six ClamAV/EICAR cases still failing due host interception; the full cross-phase Chromium suite was not rerun after this focused change. Phase 15 remains PARTIAL / BLOCKED; Phase 16 was not started.
